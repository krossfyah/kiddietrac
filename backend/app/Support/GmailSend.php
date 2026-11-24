<?php

namespace App\Support;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * SENDING FROM A GMAIL MAILBOX IN THE EMAIL CLIENT (2026-11-09, Anthony: "why can't we reply to emails from the agency
 * email client?" -> Microsoft + Gmail). The gmail.modify permission every Google mailbox already signed in with covers
 * sending, so no mailbox has to reconnect.
 *
 * The message is built here (Symfony Mime: our part, the quoted original, inline pictures as cid:, attachments) and
 * handed to Gmail's messages.send as raw MIME. A reply carries In-Reply-To / References and the original's threadId,
 * so Gmail (and the other person's mail app) keeps it in the conversation; it lands in the mailbox's Sent folder.
 * A forward keeps the original's attachments. Nothing is sent unless the last call is reached.
 */
final class GmailSend
{
    /**
     * @param string      $quote  the original, already made safe for display (EmailMailController::gmailMessage html)
     * @param array       $files  [['name','type','path','size']]
     */
    public static function send(object $from, string $mode, ?string $mid, string $quote, array $to, array $cc, array $bcc,
                                string $subject, string $html, array $files): void
    {
        $orig = null; $thread = null;
        if ($mode !== 'new' && $mid) {
            $orig = GoogleMailbox::call($from, 'GET', '/messages/' . rawurlencode($mid), ['format' => 'full'])->json();
            $thread = (string) ($orig['threadId'] ?? '') ?: null;
        }
        $h = fn (string $n) => self::header($orig, $n);

        // Recipients left empty on a reply: the sender (Reply-To first), and for reply all everyone else too.
        if ($orig && ! $to && ! $cc && in_array($mode, ['reply', 'reply_all'], true)) {
            $own = mb_strtolower((string) $from->email_address);
            $to = self::addresses($h('Reply-To') ?: $h('From'));
            if ($mode === 'reply_all') $cc = array_merge(self::addresses($h('To')), self::addresses($h('Cc')));
            $not = fn ($e) => mb_strtolower($e) !== $own;
            $to = array_values(array_filter($to, $not));
            $cc = array_values(array_diff(array_filter($cc, $not), $to));
        }
        if (! $to && ! $cc && ! $bcc) throw new \RuntimeException('Add at least one recipient.');
        if ($subject === '' && $orig) {
            $s = $h('Subject');
            $subject = $mode === 'forward' ? (preg_match('/^(fw|fwd):/i', $s) ? $s : 'Fw: ' . $s) : (preg_match('/^re:/i', $s) ? $s : 'Re: ' . $s);
        }

        [$html, $inline] = MailSend::inlineImages($html);
        $body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#1F2937;">' . $html . '</div>';
        if ($orig) {
            $when = ! empty($orig['internalDate']) ? \Carbon\Carbon::createFromTimestamp(intdiv((int) $orig['internalDate'], 1000), AgencyTime::tz($from->agency_id ? (int) $from->agency_id : null))->format('D, M j, Y \a\t g:i A') : '';
            $head = $mode === 'forward'
                ? '---------- Forwarded message ---------<br>From: ' . e($h('From')) . '<br>Date: ' . e($when) . '<br>Subject: ' . e($h('Subject')) . '<br>To: ' . e($h('To')) . ($h('Cc') !== '' ? '<br>Cc: ' . e($h('Cc')) : '')
                : 'On ' . e($when) . ', ' . e($h('From')) . ' wrote:';
            $body .= '<br><div class="gmail_quote"><div style="font-size:13px;color:#475569">' . $head . '</div><br>'
                . ($mode === 'forward' ? $quote : '<blockquote style="margin:0 0 0 .8ex;border-left:1px solid #ccc;padding-left:1ex">' . $quote . '</blockquote>') . '</div>';
        }

        $m = (new Email())->from(new Address((string) $from->email_address, (string) ($from->display_name ?? '')))->subject($subject)
            ->html($body)->text(MailHtml::text($body));
        foreach ($to as $x) $m->addTo($x);
        foreach ($cc as $x) $m->addCc($x);
        foreach ($bcc as $x) $m->addBcc($x);
        if ($orig && $mode !== 'forward' && ($msgId = $h('Message-ID') ?: $h('Message-Id')) !== '') {
            $m->getHeaders()->addIdHeader('In-Reply-To', trim($msgId, '<> '));
            $refs = preg_split('/\s+/', trim($h('References') . ' ' . $msgId)) ?: [];
            $m->getHeaders()->addIdHeader('References', array_values(array_unique(array_filter(array_map(fn ($r) => trim($r, '<> '), $refs)))));
        }
        foreach ($inline as $cid => $img) $m->embed(base64_decode($img['b64']), $cid, $img['type']);
        $total = 0;
        foreach ($files as $f) { $total += (int) $f['size']; $m->attachFromPath($f['path'], $f['name'], $f['type'] ?: 'application/octet-stream'); }
        if ($orig && $mode === 'forward') {
            foreach (self::parts((array) ($orig['payload'] ?? [])) as $p) {
                $total += (int) ($p['body']['size'] ?? 0);
                if ($total > MailSend::MAX_TOTAL) throw new \RuntimeException('The original\'s attachments are too big to forward together (the limit is 25 MB per email).');
                $data = ! empty($p['body']['attachmentId'])
                    ? (string) (GoogleMailbox::call($from, 'GET', '/messages/' . rawurlencode($mid) . '/attachments/' . rawurlencode((string) $p['body']['attachmentId']))->json('data') ?? '')
                    : (string) ($p['body']['data'] ?? '');
                $m->attach((string) base64_decode(strtr($data, '-_', '+/')), (string) $p['filename'], (string) ($p['mimeType'] ?? 'application/octet-stream'));
            }
        }

        /* Symfony leaves Bcc out of the written message (a mailer reads it from the envelope). Gmail delivers to the
           Bcc header in the raw message and strips it before it goes out, so it is written back in here. */
        $mime = $m->toString();
        $bccList = array_values(array_filter(array_map('trim', $bcc), fn ($x) => filter_var($x, FILTER_VALIDATE_EMAIL)));
        if ($bccList) $mime = 'Bcc: ' . implode(', ', $bccList) . "\r\n" . $mime;
        $raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
        GoogleMailbox::call($from, 'POST', '/messages/send', [], ['raw' => $raw] + ($thread && $mode !== 'forward' ? ['threadId' => $thread] : []));
    }

    private static function header(?array $m, string $name): string
    {
        foreach ((array) ($m['payload']['headers'] ?? []) as $x) {
            if (strcasecmp((string) ($x['name'] ?? ''), $name) === 0) return trim((string) ($x['value'] ?? ''));
        }
        return '';
    }

    /** The addresses in a header list ("Ann <a@x.com>, b@y.com"). */
    private static function addresses(string $list): array
    {
        preg_match_all('/[A-Z0-9._%+\'-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $list, $mm);
        return array_values(array_unique($mm[0]));
    }

    /** The original's file attachments (not pictures drawn in the body). */
    private static function parts(array $p): array
    {
        if (! empty($p['parts'])) return array_merge(...array_map([self::class, 'parts'], $p['parts']));
        if ((string) ($p['filename'] ?? '') === '') return [];
        foreach ((array) ($p['headers'] ?? []) as $x) {
            if (strcasecmp((string) ($x['name'] ?? ''), 'Content-Disposition') === 0 && stripos((string) $x['value'], 'inline') === 0) return [];
        }
        return [$p];
    }
}
