<?php

namespace App\Support;

use App\Console\Commands\SendCampaignEmailsCommand as Campaigns;
use App\Services\AgencyMailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RESEND A CAMPAIGN TO PEOPLE IT NEVER REACHED (2026-11-27, Anthony: "resend a campaign to people whose email bounced
 * once their address has been corrected"). ONE RULE for both ways in:
 *   - the portal: Marketing > Campaigns > Recipients & stats > "Resend to bounced" (MarketingController), which only
 *     ever offers a bounced person's CURRENT account address, and only when it is a corrected one (candidates());
 *   - the console: `kiddietrac:campaigns-mail --id=N --resend-to=a,b` (SendCampaignEmailsCommand).
 *
 * send() fails closed: an already-emailed campaign only; every address must be in the campaign's audience today
 * (a typo, an outsider or a family that has left stops the whole run); at most MAX addresses; nobody the campaign
 * already reached is sent to again. Same builder, same sender, same per-person record and open tracking as the first
 * send, so the mail gate applies unchanged (MailScope::campaign for family audiences, test agencies send nothing,
 * AgencyOutsiders, do-not-contact). What each person's email did is read back from the email log, never assumed.
 * One audit row names every address it tried and what happened.
 */
class CampaignResend
{
    public const MAX = 80;
    /** A paced (Gmail) sender waits GAP_SECONDS between messages; a portal click sends at most this many. */
    public const WEB_PACED_MAX = 15;

    public const LABELS = [
        'ready' => 'Address corrected — ready to resend',
        'same' => 'Same address — fix it first',
        'new_bounced' => 'The new address bounced too — fix it first',
        'no_email' => 'No email on file',
        'deactivated' => 'Account deactivated',
        'already' => 'Already received it at the new address',
        'outside' => 'No longer in this campaign\'s audience',
    ];

    /** The campaign can be resent: emailed already, not deleted. */
    public static function resendable(object $c): bool
    {
        return empty($c->deleted_at) && in_array($c->channel, ['email', 'both'], true) && ! empty($c->email_sent_at);
    }

    /**
     * The bounced people still on this campaign's list, and what can be done for each (state + label). A bounced row
     * is no longer listed once a resend to that person's new address went out (it is folded into the resend row on
     * Recipients & stats). Pure read.
     *
     * @return array<int, array{send_id:int, user_id:?int, name:?string, bounced_email:string, reason:string, bounced_at:?string, current_email:?string, state:string, label:string}>
     */
    public static function candidates(object $c): array
    {
        if (! CampaignSends::ready()) return [];
        $sends = DB::table('marketing_campaign_sends')->where('campaign_id', $c->id)->get();
        if ($sends->isEmpty()) return [];
        $logIds = $sends->pluck('email_log_id')->filter()->all();
        $logs = $logIds ? DB::table('email_logs')->whereIn('id', $logIds)->get(['id', 'status', 'error', 'bounced_at', 'opened_at', 'created_at'])->keyBy('id') : collect();

        $resentTo = self::resentTo($sends, $logs);
        $bounced = [];
        foreach ($sends as $s) {
            $log = $s->email_log_id ? $logs->get($s->email_log_id) : null;
            if (CampaignSends::status($s, $log)[0] !== 'bounced') continue;
            if (isset($resentTo[$s->id])) continue;     // already resent to their new address
            $bounced[] = [$s, $log];
        }
        if (! $bounced) return [];

        $uids = array_values(array_unique(array_filter(array_map(fn ($b) => (int) $b[0]->user_id, $bounced))));
        $users = $uids ? DB::table('users')->whereIn('id', $uids)->get(['id', 'email', 'first_name', 'last_name', 'status', 'deleted_at'])->keyBy('id') : collect();
        $byAddr = self::audienceByAddress($c);
        $sentSet = self::sentSet($c);
        ['subject' => $subject] = self::built($c);

        $out = [];
        foreach ($bounced as [$s, $log]) {
            $u = $s->user_id ? $users->get((int) $s->user_id) : null;
            $cur = $u ? mb_strtolower(trim((string) $u->email)) : '';
            if (! $u || ! empty($u->deleted_at) || in_array((string) $u->status, ['suspended', 'deactivated'], true)) {
                $state = 'deactivated';
            } elseif ($cur === '' || ! filter_var($cur, FILTER_VALIDATE_EMAIL)) {
                $state = 'no_email';
            } elseif ($cur === mb_strtolower(trim((string) $s->email))) {
                $state = 'same';
            } elseif (self::hasBounced($cur)) {
                $state = 'new_bounced';
            } elseif (self::alreadyReceived($c, $cur, $u->first_name ?? null, $subject, $sentSet)) {
                $state = 'already';
            } elseif (! isset($byAddr[$cur]) || self::outsider((int) $byAddr[$cur]->id, (int) $c->agency_id)) {
                $state = 'outside';
            } else {
                $state = 'ready';
            }
            $name = trim((string) (($u->first_name ?? $s->first_name ?? '') . ' ' . ($u->last_name ?? $s->last_name ?? '')));
            $out[] = [
                'send_id' => (int) $s->id,
                'user_id' => $s->user_id ? (int) $s->user_id : null,
                'name' => $name !== '' ? $name : null,
                'bounced_email' => (string) $s->email,
                'reason' => CampaignSends::plain($log->error ?? null, 'bounced'),
                'bounced_at' => $log->bounced_at ?? null,
                'current_email' => $cur !== '' ? $cur : null,
                'state' => $state,
                'label' => self::LABELS[$state],
            ];
        }
        usort($out, fn ($a, $b) => [$a['state'] !== 'ready', (string) $a['name']] <=> [$b['state'] !== 'ready', (string) $b['name']]);

        return $out;
    }

    /**
     * Bounced rows already followed by a resend that went out to the same person at a different address:
     * [bounced send id => the resend row]. Recipients & stats folds each pair into one line.
     */
    public static function resentTo($sends, $logs): array
    {
        $resend = [];
        foreach ($sends as $s) {
            if ($s->source === 'resend' && $s->outcome === 'sent' && $s->user_id) $resend[(int) $s->user_id][] = $s;
        }
        if (! $resend) return [];
        $out = [];
        foreach ($sends as $s) {
            if (! $s->user_id || empty($resend[(int) $s->user_id])) continue;
            $log = $s->email_log_id ? $logs->get($s->email_log_id) : null;
            if (CampaignSends::status($s, $log)[0] !== 'bounced') continue;
            foreach ($resend[(int) $s->user_id] as $r) {
                if ($r->id !== $s->id && mb_strtolower((string) $r->email) !== mb_strtolower((string) $s->email)) { $out[(int) $s->id] = $r; break; }
            }
        }

        return $out;
    }

    /**
     * SEND. $emails are the addresses to send to (the portal passes each bounced person's current address; the
     * console passes what was typed). Options: dry (list only), say (a line printer), web (a portal click: no
     * politeness pause for the agency mailer, a paced sender sends WEB_PACED_MAX at most), actor (user id for the
     * audit row), replaces ([new address => the address that bounced], for the audit row), via ('portal'|'command').
     *
     * @return array{ok:bool, error?:string, todo?:array, skipped?:array, results?:array, tally?:array, left?:array, sent_total?:int}
     */
    public static function send(object $c, array $emails, array $opt = []): array
    {
        $say = $opt['say'] ?? null;
        $line = function (string $l) use ($say) { if ($say) $say($l); };
        if (! self::resendable($c)) return ['ok' => false, 'error' => 'Only a campaign that has already been emailed can be resent.'];
        $agency = DB::table('agencies')->where('id', $c->agency_id)->first();
        if (! $agency) return ['ok' => false, 'error' => 'The campaign\'s agency is missing.'];

        $want = [];
        foreach ($emails as $e) {
            $e = mb_strtolower(trim((string) $e));
            if ($e === '') continue;
            if (! filter_var($e, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => "Not an email address: $e"];
            $want[$e] = true;
        }
        $want = array_keys($want);
        if (! $want) return ['ok' => false, 'error' => 'No addresses given.'];
        if (count($want) > self::MAX) return ['ok' => false, 'error' => 'More than ' . self::MAX . ' addresses at once: refused. Send them in smaller groups.'];

        $byAddr = self::audienceByAddress($c);
        $outside = array_values(array_filter($want, fn ($e) => ! isset($byAddr[$e]) || self::outsider((int) $byAddr[$e]->id, (int) $c->agency_id)));
        if ($outside) return ['ok' => false, 'error' => 'Not in this campaign\'s audience, so nothing was sent: ' . implode(', ', $outside)];

        ['subject' => $subject, 'html' => $bodyHtml] = self::built($c, $agency);
        $sentSet = self::sentSet($c);
        $todo = [];
        $skipped = [];
        foreach ($want as $e) {
            $r = $byAddr[$e];
            if (self::alreadyReceived($c, $e, $r->first_name ?? null, $subject, $sentSet)) {
                $line("  skip $e: already received this campaign");
                $skipped[] = ['email' => $e, 'why' => 'Already received this campaign'];
                continue;
            }
            $todo[] = $r;
        }

        $sender = CampaignSender::forCampaign($c, $agency);
        $paced = $sender && RsvpSender::isGmail($sender);
        $left = [];
        if (! empty($opt['web']) && $paced && count($todo) > self::WEB_PACED_MAX) {
            $left = array_map(fn ($r) => mb_strtolower(trim((string) $r->email)), array_slice($todo, self::WEB_PACED_MAX));
            $todo = array_slice($todo, 0, self::WEB_PACED_MAX);
        }
        $line("Campaign #{$c->id}: " . count($todo) . ' to send (dry=' . (! empty($opt['dry']) ? 'yes' : 'no') . ')');
        if (! empty($opt['dry']) || ! $todo) {
            foreach ($todo as $r) $line('  would send to ' . $r->email . ' (user ' . $r->id . ')');

            return ['ok' => true, 'todo' => array_map(fn ($r) => $r->email, $todo), 'skipped' => $skipped, 'results' => [],
                'tally' => ['sent' => 0, 'suppressed' => 0, 'failed' => 0, 'unknown' => 0], 'left' => $left, 'dry' => ! empty($opt['dry'])];
        }

        $mailer = $sender ? null : AgencyMailer::forAgency((int) $agency->id);
        $tally = ['sent' => 0, 'suppressed' => 0, 'failed' => 0, 'unknown' => 0];
        $results = [];
        $reached = [];
        $replaces = $opt['replaces'] ?? [];
        foreach ($todo as $i => $r) {
            if ($i > 0) {
                if ($paced) sleep(CampaignSender::GAP_SECONDS);
                elseif (empty($opt['web'])) sleep(1);
            }
            $out = Campaigns::deliverOne($c, $agency, $r, $sender, $mailer, $bodyHtml, $subject, 'resend', $say);
            $tally[$out] = ($tally[$out] ?? 0) + 1;
            $line("  {$r->email}: $out");
            $e = mb_strtolower(trim((string) $r->email));
            $results[] = ['email' => $e, 'user_id' => (int) $r->id, 'outcome' => $out, 'replaces' => $replaces[$e] ?? null];
            if ($out === 'sent') $reached[] = $r;
        }

        // In-app notice only for people who have none for this campaign (the first run wrote one for everyone it listed).
        $hasBell = DB::table('notifications')->where('type', 'marketing')
            ->whereRaw("JSON_EXTRACT(data, '$.campaign_id') = ?", [(int) $c->id])->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $needBell = array_values(array_filter($reached, fn ($r) => ! in_array((int) $r->id, $hasBell, true)));
        if ($needBell) Campaigns::writeBell($c, $needBell, now());

        /* The counts, from what really happened: every person this campaign reached by email, from the per-person
           records (they cover the first run and every resend). Failures add on. */
        $sentTotal = CampaignSends::ready()
            ? DB::table('marketing_campaign_sends')->where('campaign_id', $c->id)->where('outcome', 'sent')->count()
            : (int) $c->email_delivery_count + $tally['sent'];
        $upd = [
            'email_delivery_count' => $sentTotal,
            'email_failure_count' => (int) ($c->email_failure_count ?? 0) + $tally['failed'],
            'updated_at' => now(),
        ];
        if ($c->channel === 'email') $upd['delivery_count'] = $sentTotal;   // 'both': delivery_count is the News count
        DB::table('marketing_campaigns')->where('id', $c->id)->update($upd);

        // One row, naming every address tried and what happened to it (and, from the portal, the address that bounced).
        Audit::write([
            'user_id' => $opt['actor'] ?? null,
            'agency_id' => (int) ($c->agency_id ?? 0) ?: null,
            'action' => 'campaign.email_resent',
            'entity_type' => 'marketing_campaign',
            'entity_id' => (int) $c->id,
            'payload' => json_encode([
                'via' => $opt['via'] ?? 'command',
                'asked' => count($want), 'tried' => count($todo),
                'addresses' => $results,
                'skipped' => $skipped,
                'left_for_next_click' => $left,
            ] + $tally),
            'created_at' => now(),
        ]);
        $line("Campaign #{$c->id} resend: " . json_encode($tally) . "; campaign now counts $sentTotal emailed");

        return ['ok' => true, 'todo' => array_map(fn ($r) => $r->email, $todo), 'skipped' => $skipped, 'results' => $results,
            'tally' => $tally, 'left' => $left, 'sent_total' => $sentTotal];
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** The campaign's audience today, one person per address (lower-cased address => person). */
    private static function audienceByAddress(object $c): array
    {
        $everyone = Campaigns::audience($c);
        usort($everyone, fn ($x, $y) => ((int) $x->id) <=> ((int) $y->id));
        $byAddr = [];
        foreach (Campaigns::mailable($everyone) as $r) $byAddr[mb_strtolower(trim((string) $r->email))] = $r;

        return $byAddr;
    }

    /** A platform admin or other outsider who is not a parent here never gets the agency's campaign. */
    private static function outsider(int $userId, int $agencyId): bool
    {
        return AgencyOutsiders::isOutsider($userId) && ! AgencyOutsiders::parentIn($userId, $agencyId);
    }

    private static function built(object $c, ?object $agency = null): array
    {
        $agency ??= DB::table('agencies')->where('id', $c->agency_id)->first();

        return Campaigns::build($c, $agency);
    }

    /** Addresses this campaign's per-person records say it reached. */
    private static function sentSet(object $c): array
    {
        if (! CampaignSends::ready()) return [];

        return array_fill_keys(DB::table('marketing_campaign_sends')->where('campaign_id', $c->id)->where('outcome', 'sent')->pluck('email')
            ->map(fn ($e) => mb_strtolower(trim((string) $e)))->all(), true);
    }

    /** Never send to an address that already got the campaign: a 'sent' record, or a sent/bounced log of its subject. */
    private static function alreadyReceived(object $c, string $email, ?string $firstName, string $subject, array $sentSet): bool
    {
        if (isset($sentSet[$email])) return true;

        return DB::table('email_logs')->whereRaw('LOWER(TRIM(to_email)) = ?', [$email])
            ->where('created_at', '>=', Carbon::parse($c->sent_at ?: $c->email_sent_at)->subMinutes(10))
            ->whereIn('status', ['sent', 'bounced'])
            ->where('subject', CampaignMerge::text($subject, $firstName))->exists();
    }

    /** Has mail to this address ever bounced? Then it is not a corrected address yet. */
    private static function hasBounced(string $email): bool
    {
        return DB::table('email_logs')->where('to_email', $email)
            ->where(fn ($w) => $w->where('status', 'bounced')->orWhereNotNull('bounced_at'))->exists();
    }
}
