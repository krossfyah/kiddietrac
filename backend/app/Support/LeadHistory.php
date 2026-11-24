<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EVERYTHING A LEAD SENDS US GOES ON THEIR HISTORY (2026-11-05, Anthony: "when text messages, calls, emails come in
 * from a lead - attach these to the lead history (only in lead status)" / "backfill anything with leads that wasn't
 * updated").
 *
 * Measured first: texts on the 1-855 line already filed (LineTexts::fileOnLead), and recorded calls and voicemails
 * (CallRecords) - but no email from a lead ever reached the history (all 27 email entries were proposals we sent and
 * "opened" events), and two texts from before fileOnLead covered every path were missing.
 *
 *   - Email: kiddietrac:mail-watch files every message that arrives in a Sales -> Email mailbox (shared_scope sales)
 *     from a lead's address (sales_leads.email or owner_email): "✉️ Email from …", the subject and Outlook's preview.
 *     Personal mailboxes are never read for this - what lands in somebody's own inbox is theirs.
 *   - Sent: mail from a Sales mailbox TO a lead is filed too ("📤 Email to …", 2026-11-24), unless another path
 *     already logged that email. A new lead (or a changed address) gets its last 90 days caught up, once.
 *   - Only a live lead: not deleted, status open (CallRecords::leadFor's rule for texts and calls). A won or lost
 *     lead's history is closed.
 *   - Never twice: sales_lead_filed holds one row per email (sha256 of its internetMessageId, so the same email in
 *     sales@ and info@ is filed once) and per backfilled text.
 *   - The entry is dated when the email arrived, so the history reads in order.
 *
 * Backfill: kt:lead-history-backfill (--dry-run) - the texts on the line that never reached a live lead, and the last
 * 90 days of email from leads in the Sales mailboxes.
 */
class LeadHistory
{
    /** The live (open, not deleted) lead with this email address, or null. */
    public static function leadForEmail(?string $addr): ?object
    {
        $a = mb_strtolower(trim((string) $addr));
        if ($a === '' || ! str_contains($a, '@')) return null;

        return DB::table('sales_leads')->whereNull('deleted_at')->where('status', 'open')
            ->where(fn ($q) => $q->whereRaw('LOWER(TRIM(email)) = ?', [$a])->orWhereRaw('LOWER(TRIM(owner_email)) = ?', [$a]))
            ->orderByDesc('last_activity_at')->first(['id', 'name', 'company', 'email', 'last_activity_at']) ?: null;
    }

    /** Is this a Sales -> Email mailbox (the only ones filed from)? */
    public static function salesMailbox(object $acct): bool
    {
        return ($acct->shared_scope ?? null) === SalesMailbox::SCOPE;
    }

    public static function emailKey(array $m): string
    {
        return hash('sha256', (string) (($m['internetMessageId'] ?? '') ?: ($m['id'] ?? '')));
    }

    /**
     * File one Graph message (id, internetMessageId, from, subject, receivedDateTime, bodyPreview, hasAttachments) on its
     * lead. Returns the lead id it was (or, dry, would be) filed on; null when it is not from a live lead or already filed.
     */
    public static function fileEmail(object $acct, array $m, bool $dry = false): ?int
    {
        if (! self::salesMailbox($acct)) return null;
        $from = (string) ($m['from']['emailAddress']['address'] ?? '');
        $lead = self::leadForEmail($from);
        if (! $lead) return null;
        $key = self::emailKey($m);
        if (DB::table('sales_lead_filed')->where('source', 'email')->where('ref', $key)->exists()) return null;
        if ($dry) return (int) $lead->id;

        $at = ! empty($m['receivedDateTime']) ? Carbon::parse($m['receivedDateTime'])->utc() : now();
        $who = trim((string) ($m['from']['emailAddress']['name'] ?? '')) ?: ($lead->name ?: $from);
        $subject = trim((string) ($m['subject'] ?? '')) ?: '(no subject)';
        $preview = trim(preg_replace('/\s+/u', ' ', (string) ($m['bodyPreview'] ?? '')));
        $body = '✉️ Email from ' . $who . ' to ' . $acct->email_address . (! empty($m['hasAttachments']) ? ' · 📎 attachment' : '') . "\n"
            . 'Subject: ' . mb_substr($subject, 0, 250)
            . ($preview !== '' ? "\n\n" . mb_substr($preview, 0, 600) : '');

        return self::write('email', $key, $lead, $body, $at, 'email');
    }

    /** One claimed entry: the sales_lead_filed row and the activity together, or neither. */
    private static function write(string $source, string $ref, object $lead, string $body, Carbon $at, string $type, ?int $uid = null): ?int
    {
        try {
            return DB::transaction(function () use ($source, $ref, $lead, $body, $at, $type, $uid) {
                if (! DB::table('sales_lead_filed')->insertOrIgnore(['source' => $source, 'ref' => $ref, 'lead_id' => $lead->id, 'created_at' => now()])) return null;
                $id = DB::table('sales_activities')->insertGetId(['lead_id' => $lead->id, 'user_id' => $uid, 'type' => $type, 'body' => $body,
                    'done' => 1, 'created_at' => $at, 'updated_at' => $at]);
                DB::table('sales_lead_filed')->where('source', $source)->where('ref', $ref)->update(['activity_id' => $id]);
                DB::table('sales_leads')->where('id', $lead->id)
                    ->where(fn ($q) => $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<', $at))->update(['last_activity_at' => $at]);

                return (int) $lead->id;
            });
        } catch (Throwable $e) {
            Log::warning('LeadHistory: could not file', ['source' => $source, 'lead' => $lead->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Backfill texts: every text on the line to or from a live lead's number with no history entry. An entry
     * fileOnLead already wrote (same lead, within a minute, holding the text) counts as filed.
     * Returns [[sms_id, lead_id, 'in'|'out'], ...] filed (or, dry, to file).
     */
    public static function backfillTexts(bool $dry): array
    {
        $done = [];
        $leads = DB::table('sales_leads')->whereNull('deleted_at')->where('status', 'open')->whereNotNull('phone')->get(['id', 'name', 'company', 'phone']);
        foreach ($leads as $lead) {
            $d = substr(preg_replace('/\D/', '', (string) $lead->phone), -10);   // CallRecords::digits
            if (strlen($d) !== 10 || CallRecords::leadFor($d)?->id !== $lead->id) continue;   // a number two leads share files on the newest, as live
            $rows = DB::table('sms_messages')->where('agency_id', LineTexts::AGENCY)
                ->whereRaw("RIGHT(REGEXP_REPLACE(to_phone, '[^0-9]', ''), 10) = ?", [$d])
                ->where(fn ($q) => $q->where(fn ($w) => $w->where('direction', 'in')->where(fn ($c) => $c->whereNull('category')->orWhere('category', 'inbound')))
                    ->orWhere(fn ($w) => $w->where('category', 'line_text')->whereIn('status', ['sent', 'delivered'])))
                ->orderBy('id')->get(['id', 'direction', 'body', 'created_at']);
            foreach ($rows as $s) {
                if (DB::table('sales_lead_filed')->where('source', 'sms')->where('ref', (string) $s->id)->exists()) continue;
                $at = Carbon::parse($s->created_at);
                $snip = mb_substr(trim((string) $s->body), 0, 20);
                $have = DB::table('sales_activities')->where('lead_id', $lead->id)->where('type', 'text')
                    ->whereBetween('created_at', [$at->copy()->subSeconds(10), $at->copy()->addMinute()])->pluck('body')
                    ->contains(fn ($b) => $snip === '' || str_contains((string) $b, $snip));
                if ($have) continue;
                $in = $s->direction === 'in';
                $done[] = [(int) $s->id, (int) $lead->id, $in ? 'in' : 'out'];
                if ($dry) continue;
                $who = $in ? 'Text from ' . ($lead->name ?: Dialer::pretty($d)) : 'Text sent to ' . Dialer::pretty($d);
                self::write('sms', (string) $s->id, $lead, $who . ":\n" . (string) $s->body, $at, 'text');
            }
        }

        return $done;
    }

    /**
     * Backfill email: the last $days days of mail from each live lead's address in every Sales mailbox.
     * Returns [[account_id, lead_id, received], ...] filed (or, dry, to file), and the mailboxes that could not be read.
     */
    public static function backfillEmail(bool $dry, int $days = 90): array
    {
        $done = []; $errors = []; $seen = [];
        $addrs = [];
        foreach (DB::table('sales_leads')->whereNull('deleted_at')->where('status', 'open')->get(['email', 'owner_email']) as $l) {
            foreach ([$l->email, $l->owner_email] as $x) { $x = mb_strtolower(trim((string) $x)); if ($x !== '' && str_contains($x, '@')) $addrs[$x] = true; }
        }
        $accts = DB::table('email_accounts')->whereNull('deleted_at')->where('shared_scope', SalesMailbox::SCOPE)->where('auth_type', 'microsoft')
            ->whereNotNull('oauth_token')->whereNull('auth_failed_at')->orderBy('id')->get();
        $since = now()->subDays($days)->utc()->format('Y-m-d\TH:i:s\Z');
        foreach ($accts as $a) {
            foreach (array_keys($addrs) as $addr) {
                try {
                    $rows = MicrosoftMailbox::call($a, 'GET', '/messages', [
                        '$filter' => "from/emailAddress/address eq '" . str_replace("'", "''", $addr) . "' and receivedDateTime ge " . $since,
                        '$top' => 100,
                        '$select' => 'id,internetMessageId,from,subject,receivedDateTime,bodyPreview,hasAttachments,isDraft',
                    ])->json('value') ?? [];
                } catch (Throwable $e) {
                    $errors[] = $a->id . ': ' . mb_substr($e->getMessage(), 0, 160);
                    continue 2;
                }
                usort($rows, fn ($x, $y) => strcmp((string) ($x['receivedDateTime'] ?? ''), (string) ($y['receivedDateTime'] ?? '')));
                foreach ($rows as $m) {
                    if (! empty($m['isDraft'])) continue;
                    $k = self::emailKey($m);
                    if (isset($seen[$k])) continue;   // the same email in two mailboxes (a dry run claims nothing)
                    $seen[$k] = true;
                    $lid = self::fileEmail($a, $m, $dry);
                    if ($lid) $done[] = [(int) $a->id, $lid, (string) ($m['receivedDateTime'] ?? '')];
                }
            }
        }

        return [$done, $errors];
    }

    /* ── SENT MAIL AND LATE LEADS (2026-11-24, Anthony: "i created weewatch barrie and emails where being received and
          sent but nothing is logging under the lead activity"). Two holes: only mail FROM a lead was ever filed - our
          replies out of sales@ never were - and mail-watch only files what arrives after the lead exists, so Cathy's
          quote request (Nov 22) and our reply (2 minutes before the lead was made) were never looked at again. ── */

    /** Every live lead whose address is among these recipients. */
    public static function leadsForRecipients(array $m): array
    {
        $out = [];
        foreach (array_merge($m['toRecipients'] ?? [], $m['ccRecipients'] ?? []) as $r) {
            $lead = self::leadForEmail((string) ($r['emailAddress']['address'] ?? ''));
            if ($lead) $out[$lead->id] = [$lead, (string) ($r['emailAddress']['name'] ?? '')];
        }

        return array_values($out);
    }

    /**
     * File one message SENT from a Sales mailbox on each live lead it went to: "📤 Email to …". An email another path
     * already put on the history (proposal follow-ups, meeting reminders, campaigns: an email entry within 3 minutes)
     * is not filed again. Returns the lead ids filed (or, dry, to file).
     */
    public static function fileSent(object $acct, array $m, bool $dry = false): array
    {
        if (! self::salesMailbox($acct) || ! empty($m['isDraft'])) return [];
        $done = [];
        $at = ! empty($m['sentDateTime']) ? Carbon::parse($m['sentDateTime'])->utc() : now();
        foreach (self::leadsForRecipients($m) as [$lead, $name]) {
            $key = hash('sha256', 'out|' . self::emailKey($m) . '|' . $lead->id);
            if (DB::table('sales_lead_filed')->where('source', 'email_out')->where('ref', $key)->exists()) continue;
            $twin = DB::table('sales_activities')->where('lead_id', $lead->id)->where('type', 'email')
                ->whereBetween('created_at', [$at->copy()->subMinutes(3), $at->copy()->addMinutes(3)])->exists();
            if ($twin) continue;
            if ($dry) { $done[] = (int) $lead->id; continue; }
            $who = trim($name) ?: ($lead->name ?: (string) $lead->email);
            $subject = trim((string) ($m['subject'] ?? '')) ?: '(no subject)';
            $preview = trim(preg_replace('/\s+/u', ' ', (string) ($m['bodyPreview'] ?? '')));
            $body = '📤 Email to ' . $who . ' from ' . $acct->email_address . (! empty($m['hasAttachments']) ? ' · 📎 attachment' : '') . "\n"
                . 'Subject: ' . mb_substr($subject, 0, 250)
                . ($preview !== '' ? "\n\n" . mb_substr($preview, 0, 600) : '');
            $uid = (int) ($acct->user_id ?? 0) ?: null;
            if (self::write('email_out', $key, $lead, $body, $at, 'email', $uid)) $done[] = (int) $lead->id;
        }

        return $done;
    }

    /**
     * One lead's last $days days of mail in every Sales mailbox: what they sent us and what we sent them. Run when a
     * lead is new or its address changed (mail-watch), and by the backfill. Returns [filed count, errors].
     */
    public static function catchUpLead(object $lead, bool $dry = false, int $days = 90): array
    {
        $addrs = [];
        foreach ([$lead->email ?? null, $lead->owner_email ?? null] as $x) {
            $x = mb_strtolower(trim((string) $x));
            if ($x !== '' && str_contains($x, '@')) $addrs[$x] = true;
        }
        $n = 0; $errors = [];
        if (! $addrs) return [0, []];
        $since = now()->subDays($days)->utc()->format('Y-m-d\TH:i:s\Z');
        foreach (self::salesAccounts() as $a) {
            foreach (array_keys($addrs) as $addr) {
                try {
                    $in = MicrosoftMailbox::call($a, 'GET', '/messages', [
                        '$filter' => "from/emailAddress/address eq '" . str_replace("'", "''", $addr) . "' and receivedDateTime ge " . $since,
                        '$top' => 100,
                        '$select' => 'id,internetMessageId,from,subject,receivedDateTime,bodyPreview,hasAttachments,isDraft',
                    ])->json('value') ?? [];
                    // Graph cannot $filter on recipients; $search finds them, the recipient check below keeps it exact.
                    $out = MicrosoftMailbox::call($a, 'GET', '/mailFolders/sentitems/messages', [
                        '$search' => '"to:' . $addr . '"', '$top' => 100,
                        '$select' => 'id,internetMessageId,from,toRecipients,ccRecipients,subject,sentDateTime,bodyPreview,hasAttachments,isDraft',
                    ])->json('value') ?? [];
                } catch (Throwable $e) {
                    $errors[] = $a->id . ': ' . mb_substr($e->getMessage(), 0, 160);
                    continue;
                }
                foreach ($in as $m) {
                    if (empty($m['isDraft']) && self::fileEmail($a, $m, $dry)) $n++;
                }
                foreach ($out as $m) {
                    if (($m['sentDateTime'] ?? '') < $since) continue;
                    $n += count(self::fileSent($a, $m, $dry));
                }
            }
        }

        return [$n, $errors];
    }

    /** New leads, and leads whose address changed, since the last pass: their earlier mail, once per address. */
    public static function catchUpRecent(bool $dry = false, int $minutes = 40): array
    {
        $done = [];
        $leads = DB::table('sales_leads')->whereNull('deleted_at')->where('status', 'open')
            ->where('updated_at', '>=', now()->subMinutes($minutes))->get(['id', 'name', 'company', 'email', 'owner_email']);
        foreach ($leads as $lead) {
            $ref = hash('sha256', 'catchup|' . $lead->id . '|' . mb_strtolower(trim((string) $lead->email)) . '|' . mb_strtolower(trim((string) $lead->owner_email)));
            if (DB::table('sales_lead_filed')->where('source', 'catchup')->where('ref', $ref)->exists()) continue;
            [$n, $errors] = self::catchUpLead($lead, $dry);
            if (! $dry && ! $errors) {
                DB::table('sales_lead_filed')->insertOrIgnore(['source' => 'catchup', 'ref' => $ref, 'lead_id' => $lead->id, 'created_at' => now()]);
            }
            $done[] = [(int) $lead->id, $n, $errors];
        }

        return $done;
    }

    private static function salesAccounts()
    {
        return DB::table('email_accounts')->whereNull('deleted_at')->where('shared_scope', SalesMailbox::SCOPE)->where('auth_type', 'microsoft')
            ->whereNotNull('oauth_token')->whereNull('auth_failed_at')->orderBy('id')->get();
    }
}
