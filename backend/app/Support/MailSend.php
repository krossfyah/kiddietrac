<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * SENDING FROM A MICROSOFT 365 MAILBOX IN THE EMAIL CLIENT (2026-11-02, Anthony: "reply and reply to all in the mail
 * client must have cc and bcc and preview with signature and ability to add attachments etc").
 *
 * Every message is built as a DRAFT in the mailbox first, then sent:
 *   1. the draft - createReply / createReplyAll / createForward on the original (Outlook writes the quoted original
 *      and keeps the conversation; a forward keeps the original's attachments), or a new message;
 *   2. our part is put ABOVE the quote, with the To / Cc / Bcc and subject exactly as the person left them;
 *   3. attachments: small ones in one request, anything over 3 MB through an upload session;
 *   4. send.
 *
 * WHY TWO TOKENS. Steps 1-3 use the mailbox's own Microsoft sign-in (delegated Mail.ReadWrite / Mail.ReadWrite.Shared,
 * already consented). That sign-in has no Mail.Send, so step 4 uses the platform mail app (application Mail.Send, the
 * same channel proposals and booking confirmations use) on the same mailbox. Asking every mailbox to re-consent for
 * delegated Mail.Send would have broken the refresh tokens already saved. The app can only send for mailboxes in
 * KiddieTrac's own Microsoft 365 organisation, so sending is offered from Sales -> Email only.
 *
 * If anything fails after the draft exists, the draft is deleted, so a failed send never leaves half a message in
 * Drafts. Nothing here is sent unless step 4 is reached.
 */
final class MailSend
{
    public const MAX_TOTAL = 25 * 1024 * 1024;   // Exchange Online accepts ~35 MB after base64; 25 MB of files fits.
    public const MAX_FILES = 10;
    private const SMALL = 3 * 1024 * 1024;

    /**
     * @param object      $from    the mailbox it goes out from
     * @param string      $mode    new | reply | reply_all | forward
     * @param object|null $source  the mailbox holding the original (reply/forward); may differ from $from
     * @param array       $files   [['name','type','path','size']]
     */
    /**
     * data:image/...;base64 pictures in our part of the message -> cid: references plus the inline attachments to add.
     * The same picture used twice (a logo in the body and the signature) is attached once.
     * @return array{0: string, 1: array<string, array{name: string, type: string, b64: string}>}
     */
    public static function inlineImages(string $html): array
    {
        $inline = [];
        $html = (string) preg_replace_callback('#(["\'])data:(image/(?:png|jpe?g|gif|webp));base64,([A-Za-z0-9+/=\s]+)\1#i', function ($m) use (&$inline) {
            $b64 = preg_replace('/\s+/', '', $m[3]);
            $raw = base64_decode($b64, true);
            if ($raw === false || $raw === '' || strlen($raw) > self::SMALL) return $m[0];
            $cid = 'kt' . substr(sha1($raw), 0, 16) . '@kiddietrac';
            if (! isset($inline[$cid])) {
                $type = strtolower($m[2]) === 'image/jpg' ? 'image/jpeg' : strtolower($m[2]);
                $inline[$cid] = ['name' => 'image' . (count($inline) + 1) . '.' . explode('/', $type)[1], 'type' => $type, 'b64' => base64_encode($raw)];
            }
            return $m[1] . 'cid:' . $cid . $m[1];
        }, $html);
        return [$html, $inline];
    }

    public static function send(object $from, string $mode, ?object $source, ?string $mid, array $to, array $cc, array $bcc,
                                string $subject, string $html, array $files): void
    {
        /* Step 4's token (2026-11-09): the mailbox's own sign-in when it was given Mail.Send (any mailbox, any
           organisation - MicrosoftMailbox::SEND_SCOPE); otherwise the platform app, which only works for KiddieTrac's
           own sales mailboxes. Decided before a draft exists, so a mailbox that cannot send leaves nothing behind. */
        $delegated = MicrosoftMailbox::canSend($from);
        $app = null;
        if (! $delegated) {
            if (($from->shared_scope ?? null) !== SalesMailbox::SCOPE) {
                throw new \RuntimeException($from->email_address . ' was connected before sending was added. Open Manage accounts, choose Reconnect and sign in with Microsoft once to allow sending.');
            }
            $app = BookingTeams::appToken();
            if (! $app) throw new \RuntimeException('Sending is not set up (the Microsoft mail app is not configured in Platform → Email settings).');
        }

        // Pictures pasted into the message or signature (a logo from Outlook/Word) arrive as data: images, which Gmail
        // and many other mail apps do not show. They go out as inline attachments (cid:), as Outlook sends them.
        [$html, $inline] = self::inlineImages($html);
        $ours = '<div style="font-family:Segoe UI,Calibri,Arial,sans-serif;font-size:14px;line-height:1.5;color:#1F2937;">' . $html . '</div>';
        $draft = null;
        try {
            if ($mode !== 'new' && $mid && $source && (int) $source->id === (int) $from->id) {
                $verb = ['reply' => 'createReply', 'reply_all' => 'createReplyAll', 'forward' => 'createForward'][$mode];
                $d = MicrosoftMailbox::call($from, 'POST', '/messages/' . rawurlencode($mid) . '/' . $verb, [], new \stdClass())->json();
                $draft = (string) ($d['id'] ?? '');
                if ($draft === '') throw new \RuntimeException('Microsoft did not start the reply. Please try again.');
                $body = self::above((string) ($d['body']['content'] ?? ''), $ours);
            } elseif ($mode !== 'new' && $mid && $source) {
                // Answering from a different mailbox than the one it arrived in: a new message carrying the quote.
                [$quote, $orig] = self::quoteOf($source, $mid, $mode === 'forward');
                $body = $ours . $quote;
                $d = MicrosoftMailbox::call($from, 'POST', '/messages', [], ['subject' => $subject, 'body' => ['contentType' => 'HTML', 'content' => $body]])->json();
                $draft = (string) ($d['id'] ?? '');
                if ($draft === '') throw new \RuntimeException('Microsoft did not create the message. Please try again.');
                if ($mode === 'forward') self::copyAttachments($source, $mid, $from, $draft);
            } else {
                $body = $ours;
                $d = MicrosoftMailbox::call($from, 'POST', '/messages', [], ['subject' => $subject, 'body' => ['contentType' => 'HTML', 'content' => $body]])->json();
                $draft = (string) ($d['id'] ?? '');
                if ($draft === '') throw new \RuntimeException('Microsoft did not create the message. Please try again.');
            }

            $patch = ['body' => ['contentType' => 'HTML', 'content' => $body]];
            if ($subject !== '') $patch['subject'] = $subject;
            // No recipients given on a reply = keep the ones Outlook put on the draft (the sender, or everyone).
            if ($to || $cc || $bcc || ! in_array($mode, ['reply', 'reply_all'], true)) {
                $patch += ['toRecipients' => self::people($to), 'ccRecipients' => self::people($cc), 'bccRecipients' => self::people($bcc)];
            }
            MicrosoftMailbox::call($from, 'PATCH', '/messages/' . rawurlencode($draft), [], $patch);

            foreach ($inline as $cid => $img) {
                MicrosoftMailbox::call($from, 'POST', '/messages/' . rawurlencode($draft) . '/attachments', [], [
                    '@odata.type' => '#microsoft.graph.fileAttachment', 'name' => $img['name'], 'contentType' => $img['type'],
                    'contentBytes' => $img['b64'], 'isInline' => true, 'contentId' => $cid,
                ]);
            }

            foreach ($files as $f) {
                if ($f['size'] <= self::SMALL) {
                    MicrosoftMailbox::call($from, 'POST', '/messages/' . rawurlencode($draft) . '/attachments', [], [
                        '@odata.type' => '#microsoft.graph.fileAttachment',
                        'name' => $f['name'], 'contentType' => $f['type'] ?: 'application/octet-stream',
                        'contentBytes' => base64_encode((string) file_get_contents($f['path'])),
                    ]);
                } else {
                    MicrosoftMailbox::uploadLarge($from, $draft, $f['name'], $f['type'], $f['path']);
                }
            }

            if ($delegated) {
                MicrosoftMailbox::sendDraft($from, $draft);
            } else {
                $r = Http::withToken($app)->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->timeout(60)
                    ->withBody('', 'application/json')
                    ->post('https://graph.microsoft.com/v1.0/users/' . rawurlencode((string) $from->email_address) . '/messages/' . rawurlencode($draft) . '/send');
                if (! $r->successful()) {
                    $msg = (string) ($r->json('error.message') ?: ('HTTP ' . $r->status()));
                    throw new \RuntimeException('Microsoft would not send it: ' . $msg);
                }
            }
            $draft = null;   // sent: it is in Sent Items now, not a draft to clean up
        } finally {
            if ($draft) {
                try { MicrosoftMailbox::call($from, 'DELETE', '/messages/' . rawurlencode($draft)); } catch (\Throwable $e) {}
            }
        }
    }

    /** Our part goes straight after <body>, above Outlook's quoted original. */
    private static function above(string $draftHtml, string $ours): string
    {
        if ($draftHtml === '') return $ours;
        if (preg_match('/<body\b[^>]*>/i', $draftHtml, $m, PREG_OFFSET_CAPTURE)) {
            $at = $m[0][1] + strlen($m[0][0]);
            return substr($draftHtml, 0, $at) . $ours . substr($draftHtml, $at);
        }
        return $ours . $draftHtml;
    }

    /** The Outlook-style "From / Sent / To / Subject" block plus the original body, for a reply from another mailbox. */
    private static function quoteOf(object $source, string $mid, bool $forward): array
    {
        $m = MicrosoftMailbox::call($source, 'GET', '/messages/' . rawurlencode($mid), ['$select' => 'subject,from,toRecipients,ccRecipients,sentDateTime,body'])->json();
        $who = fn ($r) => e(trim(((string) ($r['emailAddress']['name'] ?? '')) . ' <' . ((string) ($r['emailAddress']['address'] ?? '')) . '>'));
        $list = fn ($rs) => implode('; ', array_map($who, (array) $rs));
        $tz = AgencyTime::tz(null);
        $sent = ! empty($m['sentDateTime']) ? \Carbon\Carbon::parse($m['sentDateTime'])->setTimezone($tz)->format('l, F j, Y g:i A') : '';
        $orig = (string) ($m['body']['content'] ?? '');
        if (strtolower((string) ($m['body']['contentType'] ?? '')) !== 'html') $orig = nl2br(e($orig));
        elseif (preg_match('#<body\b[^>]*>(.*)</body>#is', $orig, $b)) $orig = $b[1];
        $head = '<hr style="border:none;border-top:1px solid #E1E1E1;margin:16px 0 8px"><div style="font-family:Segoe UI,Calibri,Arial,sans-serif;font-size:13px;color:#1F2937">'
            . '<b>From:</b> ' . $who($m['from'] ?? []) . '<br><b>Sent:</b> ' . e($sent) . '<br><b>To:</b> ' . $list($m['toRecipients'] ?? [])
            . (! empty($m['ccRecipients']) ? '<br><b>Cc:</b> ' . $list($m['ccRecipients']) : '')
            . '<br><b>Subject:</b> ' . e((string) ($m['subject'] ?? '')) . '</div><br>';
        return [$head . $orig, $m];
    }

    /** A forward from another mailbox keeps the original's files (inline pictures stay in the quoted body). */
    private static function copyAttachments(object $source, string $mid, object $to, string $draft): void
    {
        $list = MicrosoftMailbox::call($source, 'GET', '/messages/' . rawurlencode($mid) . '/attachments', ['$select' => 'id,name,size,contentType,isInline'])->json('value') ?? [];
        foreach ($list as $att) {
            if (! empty($att['isInline']) || ($att['@odata.type'] ?? '#microsoft.graph.fileAttachment') !== '#microsoft.graph.fileAttachment') continue;
            $raw = MicrosoftMailbox::call($source, 'GET', '/messages/' . rawurlencode($mid) . '/attachments/' . rawurlencode((string) $att['id']) . '/$value')->body();
            if (strlen($raw) <= self::SMALL) {
                MicrosoftMailbox::call($to, 'POST', '/messages/' . rawurlencode($draft) . '/attachments', [], [
                    '@odata.type' => '#microsoft.graph.fileAttachment', 'name' => (string) $att['name'],
                    'contentType' => (string) ($att['contentType'] ?? 'application/octet-stream'), 'contentBytes' => base64_encode($raw)]);
            } else {
                $tmp = tempnam(sys_get_temp_dir(), 'ktfw');
                file_put_contents($tmp, $raw);
                try { MicrosoftMailbox::uploadLarge($to, $draft, (string) $att['name'], (string) ($att['contentType'] ?? ''), $tmp); }
                finally { @unlink($tmp); }
            }
        }
    }

    private static function people(array $list): array
    {
        $out = []; $seen = [];
        foreach ($list as $e) {
            $e = trim((string) $e);
            $k = mb_strtolower($e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && ! isset($seen[$k])) { $seen[$k] = 1; $out[] = ['emailAddress' => ['address' => $e]]; }
        }
        return $out;
    }
}
