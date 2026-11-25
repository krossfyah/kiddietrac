<?php

namespace App\Console\Commands;

use App\Support\MailUnread;
use App\Support\MicrosoftMailbox;
use App\Support\Notify;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * NEW MAIL -> THE BELL, AND STAYING SIGNED IN (2026-11-02, Anthony: "any new mail that comes in alert me via the bell
 * with a soft chime ... keep the connection alive without me signing in all the time").
 *
 * Every minute, for each mailbox connected with Microsoft:
 *   - inbox messages received since the account's watermark (mail_seen_until), unread, and not from one of our own
 *     connected addresses, become a bell row (type 'email' -> the "Email" sound, a soft up-chime by default) for the
 *     people who use that mailbox (MicrosoftMailbox::recipients: the sales team for a Sales -> Email mailbox, the owner
 *     for a personal one). Four or more at once are ONE row ("5 new emails in sales@"), never a burst.
 *   - FRESH ROWS ONLY. A mailbox that has never been watched (watermark NULL - just connected, or the first run after
 *     this shipped) only gets its watermark set: the mail already in it never rings. The look-back is capped at 30
 *     minutes, so a long outage does not replay a morning of mail either.
 *   - Never twice: email_mail_alerts holds every message already announced (2-minute overlap between passes for
 *     Microsoft's indexing delay).
 *   - A mailbox whose sign-in Microsoft refused (auth_failed_at) is skipped until it is reconnected; it was told once.
 *
 * --keepalive (daily): use each mailbox's refresh token once, which keeps it - and saves the new one Microsoft hands
 * back - so a mailbox nobody opens never reaches Microsoft's 90-day inactivity limit.
 * --dry-run: report what would ring, write nothing.
 *
 * Unread counts (2026-11-04): each pass also stores every Microsoft mailbox's inbox unread count, and every fifth minute
 * each IMAP (password) mailbox's, for the top-bar ✉️ / 📮 badges (App\Support\MailUnread).
 *
 * Lead history (2026-11-05): in a Sales -> Email mailbox, every message from a live lead - read or not - is also filed on
 * that lead (App\Support\LeadHistory::fileEmail; once per email, whichever mailbox sees it first).
 *
 * People (2026-11-07): in an agency's own mailbox with "link emails to people" on, mail from a parent, staff member or
 * contact of that agency - and, if the agency chose it, what the mailbox sent them (Sent Items) - is linked to their
 * record (App\Support\PersonMail::autoLink).
 */
class MailWatchCommand extends Command
{
    protected $signature = 'kiddietrac:mail-watch {--keepalive} {--dry-run} {--account=}';
    protected $description = 'Bell the people who use a connected mailbox when new mail arrives; --keepalive refreshes the Microsoft sign-ins';

    private const LOOKBACK_MIN = 30;
    private const OVERLAP_MIN = 2;
    private const SUMMARY_FROM = 4;

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $q = DB::table('email_accounts')->whereNull('deleted_at')->where('auth_type', 'microsoft')->whereNotNull('oauth_token')->whereNull('auth_failed_at');
        if ($this->option('account')) $q->where('id', (int) $this->option('account'));
        $accounts = $q->orderBy('id')->get();

        if ($this->option('keepalive')) {
            foreach ($accounts as $a) {
                try {
                    if (! $dry) MicrosoftMailbox::keepAlive($a);
                    $this->line('kept alive ' . $a->id . ' ' . $a->email_address . ($dry ? ' (dry run)' : ''));
                } catch (\Throwable $e) {
                    $this->warn('keep-alive ' . $a->id . ' ' . $a->email_address . ': ' . $e->getMessage());
                }
            }
            return self::SUCCESS;
        }

        // Our own addresses: mail from any of them (a copy to ourselves, one mailbox writing to another) never rings.
        $own = [];
        foreach (DB::table('email_accounts')->whereNull('deleted_at')->get(['email_address', 'oauth_identity']) as $r) {
            foreach ([$r->email_address, $r->oauth_identity] as $x) if ($x) $own[mb_strtolower(trim($x))] = true;
        }

        foreach ($accounts as $a) {
            if (($a->shared_scope ?? null) === 'calendar') continue;   // a calendar-only sign-in has no mail (kept alive above)
            try {
                $this->watch($a, $own, $dry);
            } catch (\Throwable $e) {
                $this->warn('watch ' . $a->id . ' ' . $a->email_address . ': ' . $e->getMessage());
            }
        }
        if (! $this->option('account') && (int) now()->format('i') % 5 === 0) $this->countImap($dry);
        // A lead made (or re-addressed) after the mail came in: its earlier mail, once (2026-11-24, WeeWatch Barrie).
        if (! $this->option('account')) {
            try {
                foreach (\App\Support\LeadHistory::catchUpRecent($dry) as [$lid, $n, $errs]) {
                    $this->line('lead ' . $lid . ' caught up: ' . $n . ($dry ? ' would be filed' : ' filed') . ($errs ? ' (' . implode('; ', $errs) . ')' : ''));
                }
            } catch (\Throwable $e) {
                $this->warn('lead catch-up: ' . $e->getMessage());
            }
        }
        if (! $dry && random_int(1, 240) === 1) {
            DB::table('email_mail_alerts')->where('created_at', '<', now()->subDays(14))->delete();
        }
        return self::SUCCESS;
    }

    /** IMAP mailboxes' inbox unread counts (one short connection each). A mailbox already failing to sign in is left alone. */
    private function countImap(bool $dry): void
    {
        $rows = DB::table('email_accounts')->whereNull('deleted_at')->where('auth_type', 'password')->whereNotNull('imap_host')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 'error'))->orderBy('id')->get();
        if (! $rows->count()) return;
        $ctl = app(\App\Http\Controllers\Api\EmailMailController::class);
        foreach ($rows as $a) {
            try {
                $n = $ctl->inboxUnread($a);
                $this->line($a->id . ' ' . $a->email_address . ': ' . ($n === null ? 'could not count' : $n . ' unread') . ($dry ? ' (dry run)' : ''));
                if ($n !== null && ! $dry) MailUnread::store((int) $a->id, $n);
            } catch (\Throwable $e) {
                $this->warn('count ' . $a->id . ' ' . $a->email_address . ': ' . $e->getMessage());
            }
        }
    }

    /** Inbox mail from - and Sent Items mail to - live people of the mailbox's agency, onto their records. */
    private function linkPeople(object $a, array $inbox, array $own, Carbon $since, bool $dry): void
    {
        $n = 0;
        foreach ($inbox as $m) {
            $n += count(\App\Support\PersonMail::autoLink($a, \App\Support\PersonMail::graphMeta($m, 'in'), $own, $dry));
        }
        $s = \App\Support\PersonMail::settings((int) $a->agency_id);
        if ($s['auto'] && $s['sent']) {
            try {
                $sent = MicrosoftMailbox::call($a, 'GET', '/mailFolders/sentitems/messages', [
                    '$filter' => 'sentDateTime ge ' . $since->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                    '$orderby' => 'sentDateTime desc',
                    '$top' => 30,
                    '$select' => 'id,internetMessageId,from,toRecipients,ccRecipients,bccRecipients,subject,sentDateTime,bodyPreview,hasAttachments',
                ])->json('value') ?? [];
                foreach ($sent as $m) {
                    $n += count(\App\Support\PersonMail::autoLink($a, \App\Support\PersonMail::graphMeta($m, 'out'), $own, $dry));
                }
            } catch (\Throwable $e) {
                $this->warn('  sent items ' . $a->id . ': ' . $e->getMessage());
            }
        }
        if ($n) $this->line('  people: ' . $n . ($dry ? ' links would be made' : ' links made'));
    }

    private function watch(object $a, array $own, bool $dry): void
    {
        $now = now();
        if (! $dry) { MailUnread::refresh($a); MailUnread::settleBell($a); }   // mail read anywhere takes its bell row with it
        if (empty($a->mail_seen_until)) {
            $this->line($a->id . ' ' . $a->email_address . ': first look - watermark set, nothing rings' . ($dry ? ' (dry run)' : ''));
            if (! $dry) DB::table('email_accounts')->where('id', $a->id)->whereNull('mail_seen_until')->update(['mail_seen_until' => $now]);
            return;
        }
        $since = Carbon::parse($a->mail_seen_until, 'UTC');
        $floor = $now->copy()->subMinutes(self::LOOKBACK_MIN);
        if ($since->lt($floor)) $since = $floor;
        $since = $since->copy()->subMinutes(self::OVERLAP_MIN);

        $rows = MicrosoftMailbox::call($a, 'GET', '/mailFolders/inbox/messages', [
            '$filter' => 'receivedDateTime ge ' . $since->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            '$orderby' => 'receivedDateTime desc',
            '$top' => 30,
            '$select' => 'id,internetMessageId,from,subject,receivedDateTime,isRead,hasAttachments,bodyPreview',
        ])->json('value') ?? [];

        // The mailbox answered: a leftover non-sign-in error (a network blip on Test) is cleared.
        if (! $dry && ($a->status ?? '') !== 'connected') {
            DB::table('email_accounts')->where('id', $a->id)->whereNull('auth_failed_at')
                ->update(['status' => 'connected', 'status_detail' => 'Signed in with Microsoft as ' . ($a->oauth_identity ?: $a->email_address), 'updated_at' => $now]);
        }

        // From a live lead into a Sales mailbox: on the lead's history (read on Outlook already or not).
        if (\App\Support\LeadHistory::salesMailbox($a)) {
            foreach ($rows as $m) {
                $from = mb_strtolower(trim((string) ($m['from']['emailAddress']['address'] ?? '')));
                if ($from === '' || isset($own[$from])) continue;
                $lid = \App\Support\LeadHistory::fileEmail($a, $m, $dry);
                if ($lid) $this->line('  lead ' . $lid . ($dry ? ': would file' : ': filed') . ' an email received ' . ($m['receivedDateTime'] ?? ''));
            }
            // And what we sent them (2026-11-24): replies out of sales@ were never on the lead's history.
            try {
                $sent = MicrosoftMailbox::call($a, 'GET', '/mailFolders/sentitems/messages', [
                    '$filter' => 'sentDateTime ge ' . $since->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                    '$orderby' => 'sentDateTime desc',
                    '$top' => 30,
                    '$select' => 'id,internetMessageId,from,toRecipients,ccRecipients,subject,sentDateTime,bodyPreview,hasAttachments,isDraft',
                ])->json('value') ?? [];
                foreach ($sent as $m) {
                    foreach (\App\Support\LeadHistory::fileSent($a, $m, $dry) as $lid) $this->line('  lead ' . $lid . ($dry ? ': would file' : ': filed') . ' an email sent ' . ($m['sentDateTime'] ?? ''));
                }
            } catch (\Throwable $e) {
                $this->warn('  sent items ' . $a->id . ': ' . $e->getMessage());
            }
        }

        if (\App\Support\PersonMail::linkable($a)) $this->linkPeople($a, $rows, $own, $since, $dry);

        $fresh = [];
        foreach ($rows as $m) {
            if (! empty($m['isRead'])) continue;
            $from = mb_strtolower(trim((string) ($m['from']['emailAddress']['address'] ?? '')));
            if ($from === '' || isset($own[$from])) continue;
            $key = hash('sha256', (string) (($m['internetMessageId'] ?? '') ?: ($m['id'] ?? '')));
            if ($dry) {
                if (DB::table('email_mail_alerts')->where('account_id', $a->id)->where('message_key', $key)->exists()) continue;
            } else {
                // Claimed first: an overlapping pass cannot announce the same message.
                if (! DB::table('email_mail_alerts')->insertOrIgnore(['account_id' => $a->id, 'message_key' => $key, 'created_at' => $now])) continue;
            }
            $fresh[] = $m;
        }

        $this->line($a->id . ' ' . $a->email_address . ': ' . count($rows) . ' since ' . $since->toIso8601String() . ', ' . count($fresh) . ' new' . ($dry ? ' (dry run)' : ''));
        if (! $dry) DB::table('email_accounts')->where('id', $a->id)->update(['mail_seen_until' => $now]);
        if (! $fresh) return;

        $box = (string) ($a->display_name ?: $a->email_address);
        $link = ($a->shared_scope ?? null) === \App\Support\SalesMailbox::SCOPE ? '#sales-email' : '#email';
        $notes = [];
        if (count($fresh) >= self::SUMMARY_FROM) {
            $names = array_values(array_unique(array_map(fn ($m) => (string) (($m['from']['emailAddress']['name'] ?? '') ?: ($m['from']['emailAddress']['address'] ?? '')), $fresh)));
            $notes[] = ['title' => '✉️ ' . count($fresh) . ' new emails in ' . $a->email_address,
                'body' => 'From ' . implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' and ' . (count($names) - 3) . ' more' : '') . '.',
                'data' => ['link' => $link . '?acct=' . $a->id, 'mids' => array_values(array_map(fn ($m) => (string) ($m['id'] ?? ''), $fresh))]];
        } else {
            foreach (array_reverse($fresh) as $m) {
                $who = (string) (($m['from']['emailAddress']['name'] ?? '') ?: ($m['from']['emailAddress']['address'] ?? ''));
                $notes[] = ['title' => '✉️ ' . mb_substr($who, 0, 80) . ' · ' . $box,
                    'body' => mb_substr((string) (($m['subject'] ?? '') ?: '(no subject)'), 0, 180) . (! empty($m['hasAttachments']) ? ' 📎' : ''),
                    'data' => ['link' => $link . '?acct=' . $a->id, 'mids' => [(string) ($m['id'] ?? '')]]];
            }
        }
        /* EACH PERSON'S OWN CHOICE FOR THIS MAILBOX (2026-11-05, email_account_alerts): alerts off = no bell row, no push,
           no sound for that person; a tone of their own for this inbox rides on the row as kt_tone (the phone push and
           the open portal both play it instead of the Email tone). */
        $prefs = DB::table('email_account_alerts')->where('account_id', $a->id)->get(['user_id', 'alerts_on', 'tone'])->keyBy('user_id');
        $users = array_values(array_filter(MicrosoftMailbox::recipients($a), fn ($uid) => ! isset($prefs[$uid]) || $prefs[$uid]->alerts_on));
        $muted = array_values(array_diff(MicrosoftMailbox::recipients($a), $users));
        foreach ($notes as $n) $this->line('  ring ' . implode(',', $users) . ($muted ? ' (alerts off: ' . implode(',', $muted) . ')' : '') . ': ' . $n['title'] . ' - ' . $n['body']);
        if ($dry || ! $users) return;
        $out = [];
        foreach ($users as $uid) {
            $tone = isset($prefs[$uid]) && $prefs[$uid]->tone && \App\Support\NotificationSounds::isTone((string) $prefs[$uid]->tone) ? (string) $prefs[$uid]->tone : null;
            foreach ($notes as $n) {
                $out[] = ['user_id' => $uid, 'type' => 'email', 'title' => $n['title'], 'body' => $n['body'],
                    'data' => json_encode($n['data'] + ['account_id' => (int) $a->id, 'kt_category' => 'email', 'severity' => 'info'] + ($tone ? ['kt_tone' => $tone] : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $now];
            }
        }
        Notify::write($out);
    }
}
