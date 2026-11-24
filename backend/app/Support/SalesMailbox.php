<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * THE SHARED SALES MAILBOX (2026-10-26, Anthony: "take a copy of email client and move to sales and connect the
 * sales account").
 *
 * The email client normally shows a person's OWN mailboxes (email_accounts.user_id). Sales -> Email runs the same
 * client against ONE shared mailbox (email_accounts.shared_scope = 'sales', normally sales@kiddietrac.com) that every
 * sales rep and superadmin opens. Which of the two a request means is decided by the ROUTE (the /sales/email/* routes
 * carry the default kt_mailbox=sales and sit behind role:sales_rep,platform_admin), never by anything the browser sends.
 *
 * Personal mode never sees the shared mailbox, and sales mode never sees anyone's personal one.
 *
 * Sending: the delegated Microsoft sign-in only READS the mailbox. Replies and new messages go out through the platform
 * mail app (application Mail.Send, the same channel proposals and booking confirmations use), as the mailbox itself.
 * Since 2026-11-02 the Email client sends through App\Support\MailSend (draft + attachments + send); reply() / forward()
 * / compose() below are kept for callers that send without the client.
 *
 * Several mailboxes (2026-11-02): every email_accounts row with shared_scope 'sales' is a Sales -> Email mailbox
 * (sales@, info@, ...). account() is still THE sales@ one, for reply detection.
 */
final class SalesMailbox
{
    public const SCOPE = 'sales';

    /** Is this request for the shared sales mailbox? */
    public static function on(Request $request): bool
    {
        $r = $request->route();
        return $r && ($r->defaults['kt_mailbox'] ?? null) === self::SCOPE;
    }

    /** where() constraint: the shared sales mailbox in sales mode, the caller's own mailboxes otherwise. */
    public static function owner(Request $request): \Closure
    {
        $uid = (int) optional($request->user())->id;
        $sales = self::on($request);
        return function ($q) use ($uid, $sales) {
            if ($sales) $q->where('shared_scope', self::SCOPE);
            else $q->where('user_id', $uid)->whereNull('shared_scope');
        };
    }

    /**
     * THE sales mailbox (sales@kiddietrac.com), for campaign and cold-reach reply detection. Since 2026-11-02 Sales ->
     * Email can hold several mailboxes (info@ and others), so "the newest one" would have quietly moved reply
     * detection to whichever was added last: sales@ first, then the oldest.
     */
    public static function account(): ?object
    {
        return DB::table('email_accounts')->where('shared_scope', self::SCOPE)->whereNull('deleted_at')
            ->orderByRaw("CASE WHEN LOWER(email_address) = 'sales@kiddietrac.com' THEN 0 ELSE 1 END")->orderBy('id')->first();
    }

    /** Graph POST as the mailbox, with the platform mail app. Throws RuntimeException with a readable message. */
    private static function post(string $mailbox, string $path, array $body): void
    {
        $t = BookingTeams::appToken();
        if (! $t) throw new \RuntimeException('Sending from the sales mailbox is not set up (the Microsoft mail app is not configured).');
        $r = Http::withToken($t)->timeout(30)->post('https://graph.microsoft.com/v1.0/users/' . rawurlencode($mailbox) . $path, $body);
        if (! $r->successful()) {
            $msg = (string) ($r->json('error.message') ?: ('HTTP ' . $r->status()));
            throw new \RuntimeException('Microsoft would not send it: ' . $msg);
        }
    }

    private static function people(array $list): array
    {
        $out = [];
        foreach ($list as $e) {
            $e = trim((string) $e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = ['emailAddress' => ['address' => $e]];
        }
        return $out;
    }

    /** A reply (or reply-all) to a message in the mailbox: Outlook keeps it in the same conversation. */
    public static function reply(object $account, string $messageId, string $html, bool $all = false): void
    {
        self::post((string) $account->email_address, '/messages/' . rawurlencode($messageId) . ($all ? '/replyAll' : '/reply'), ['comment' => $html]);
    }

    /** A forward of a message in the mailbox. */
    public static function forward(object $account, string $messageId, array $to, string $html): void
    {
        self::post((string) $account->email_address, '/messages/' . rawurlencode($messageId) . '/forward', ['comment' => $html, 'toRecipients' => self::people($to)]);
    }

    /** A new message from the mailbox, saved to its Sent Items. */
    public static function compose(object $account, array $to, array $cc, string $subject, string $html): void
    {
        self::post((string) $account->email_address, '/sendMail', ['message' => [
            'subject' => $subject,
            'body' => ['contentType' => 'HTML', 'content' => $html],
            'toRecipients' => self::people($to),
            'ccRecipients' => self::people($cc),
        ], 'saveToSentItems' => true]);
    }
}
