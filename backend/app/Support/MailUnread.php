<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * UNREAD MAIL ON THE TOP BAR (2026-11-04, Anthony: "if tehre is new mail add the unread notifications as well to the top
 * bar for the email clients").
 *
 * Each mailbox's inbox unread count is kept on its row (email_accounts.inbox_unread), so the badge poll (GET /badges,
 * every few seconds for every signed-in person) reads one column and never waits on Microsoft or an IMAP server:
 *   - kiddietrac:mail-watch refreshes every Microsoft mailbox each minute and IMAP mailboxes every five;
 *   - opening the folder list stores what it just counted;
 *   - reading, marking, deleting or moving mail in KiddieTrac re-counts that mailbox once the answer has gone back.
 * Mail read in Outlook itself shows on the next watcher pass.
 *
 * forUser(): 'email' = the person's own mailboxes (the ✉️ Email client), 'sales_email' = the Sales -> Email mailboxes
 * for whoever may open them (the same people MicrosoftMailbox::recipients() rings: an active sales_rep or
 * platform_admin role anywhere). NULL means "no such mailbox for you" - the icon shows no badge.
 */
class MailUnread
{
    public static function store(int $accountId, int $n): void
    {
        DB::table('email_accounts')->where('id', $accountId)->update(['inbox_unread' => max(0, $n), 'inbox_unread_at' => now()]);
    }

    /** A Microsoft mailbox's inbox count, asked of Graph and stored. Never throws. */
    public static function refresh(object $a): ?int
    {
        try {
            $n = MicrosoftMailbox::call($a, 'GET', '/mailFolders/inbox', ['$select' => 'unreadItemCount'])->json('unreadItemCount');
            if ($n === null) return null;
            self::store((int) $a->id, (int) $n);
            return (int) $n;
        } catch (\Throwable $e) {
            Log::info('mail unread: refresh ' . $a->id . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * THE BELL FOLLOWS THE MAILBOX (2026-11-04, Anthony: "mail notifications at top bar doesnt go away after reading the
     * mail - full sweep"). The new-mail bell rows (type 'email', kiddietrac:mail-watch) were never marked read: the
     * mailbox count went down, the bell kept ringing for mail long since read. A row is now marked read - for everyone it
     * went to - once none of the messages it announced is still unread in the inbox, wherever it was read (KiddieTrac,
     * Outlook, a phone). Rows carry the Graph ids they announce (data.mids); rows from before carry none and are matched
     * on the subject (a "N new emails" row: settled once the inbox has no unread mail). Microsoft mailboxes only - IMAP
     * mail never rings. Never throws; returns how many rows it settled.
     */
    public static function settleBell(object $a): int
    {
        try {
            if (! MicrosoftMailbox::uses($a)) return 0;
            $rows = DB::table('notifications')->where('type', 'email')->whereNull('read_at')
                ->where('data', 'like', '%"account_id":' . (int) $a->id . ',%')->get(['id', 'title', 'body', 'data']);
            if (! $rows->count()) return 0;
            $list = MicrosoftMailbox::call($a, 'GET', '/mailFolders/inbox/messages', [
                '$filter' => 'isRead eq false', '$top' => 200, '$select' => 'id,subject',
            ])->json('value');
            if (! is_array($list) || count($list) >= 200) return 0;   // no answer, or too much unread to tell: leave the bell
            $ids = []; $subjects = [];
            foreach ($list as $m) {
                $ids[(string) ($m['id'] ?? '')] = true;
                $subjects[self::subjectKey((string) (($m['subject'] ?? '') ?: '(no subject)'))] = true;
            }
            $done = [];
            foreach ($rows as $r) {
                $d = json_decode((string) $r->data, true) ?: [];
                if (! empty($d['mids']) && is_array($d['mids'])) {
                    $open = false;
                    foreach ($d['mids'] as $mid) { if (isset($ids[(string) $mid])) { $open = true; break; } }
                } elseif (preg_match('/^\S+\s+\d+ new emails in /u', (string) $r->title)) {
                    $open = count($list) > 0;
                } else {
                    $open = isset($subjects[self::subjectKey((string) $r->body)]);
                }
                if (! $open) $done[] = (int) $r->id;
            }
            if ($done) DB::table('notifications')->whereIn('id', $done)->whereNull('read_at')->update(['read_at' => now()]);
            return count($done);
        } catch (\Throwable $e) {
            Log::info('mail unread: settle bell ' . $a->id . ': ' . $e->getMessage());
            return 0;
        }
    }

    /** A subject as the bell row's body holds it: cut at 180, without the paperclip. */
    private static function subjectKey(string $s): string
    {
        $s = trim(preg_replace('/\s*\x{1F4CE}\s*$/u', '', $s));
        return mb_strtolower(trim(mb_substr($s, 0, 180)));
    }

    public static function salesAllowed(int $userId): bool
    {
        return DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)
            ->whereIn('role', ['sales_rep', 'platform_admin'])->exists();
    }

    public static function forUser(int $userId): array
    {
        $own = DB::table('email_accounts')->whereNull('deleted_at')->where('user_id', $userId)->whereNull('shared_scope')
            ->selectRaw('count(*) as n, coalesce(sum(inbox_unread), 0) as u')->first();
        $out = ['email' => ($own && $own->n) ? (int) $own->u : null, 'sales_email' => null, 'sales_email_access' => false];
        if (self::salesAllowed($userId)) {
            $out['sales_email_access'] = true;
            $s = DB::table('email_accounts')->whereNull('deleted_at')->where('shared_scope', SalesMailbox::SCOPE)
                ->selectRaw('count(*) as n, coalesce(sum(inbox_unread), 0) as u')->first();
            $out['sales_email'] = ($s && $s->n) ? (int) $s->u : null;
        }
        return $out;
    }
}
