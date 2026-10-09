<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\RsvpGmail;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Sends one message through the Gmail API as a connected Gmail sender (2026-10-06).
 *
 * This host blocks outbound SMTP entirely, so a connected Gmail account is reached over
 * HTTPS: POST gmail/v1/users/me/messages/send with the RFC 822 message base64url-encoded.
 * The message is built by Symfony Mime exactly as for SMTP — From name, Reply-To,
 * List-Unsubscribe, the HTML part — so Gmail receives the same thing an SMTP server would.
 *
 * It is a TRANSPORT, used by a Mailer built the same way as every other (RsvpSender), so
 * nothing upstream changes: SuppressAgencyMail runs on MessageSending and cancels before
 * this is ever called (no Google request for a suppressed agency), the MessageSent logger
 * records a delivered message, and FailureAuditingTransport (which wraps this) records a
 * failure with Google's own error text.
 */
final class GmailApiTransport extends AbstractTransport
{
    public function __construct(private object $sender)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if (empty($this->sender->google_refresh_enc)) {
            throw new TransportException('This Gmail sender is not connected yet. Press “Connect Gmail” on the Sender accounts tab.');
        }
        $raw = rtrim(strtr(base64_encode(self::withoutInternalHeaders($message->toString())), '+/', '-_'), '=');
        try {
            $resp = $this->post(RsvpGmail::accessToken($this->sender), $raw);
            if ($resp->status() === 401) {
                // An access token Google no longer accepts: refresh once and try again.
                $resp = $this->post(RsvpGmail::accessToken($this->sender, true), $raw);
            }
        } catch (TransportException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
        if (! $resp->successful()) {
            throw new TransportException('Gmail API refused the message: ' . ($resp->json('error.message') ?: ('HTTP ' . $resp->status())));
        }
    }

    /**
     * KiddieTrac's own X-KT-* headers (agency stamp, open-tracking token, ...) are for
     * the listeners and the email log on THIS server. Through a person's own Gmail account
     * they would leave as headers no ordinary Gmail message carries — a fingerprint that
     * marks the mail as machine-sent (2026-10-09, RSVP invitations in junk). Removed from
     * the copy handed to Gmail only: the message object, which the MessageSent logger reads
     * for the tracking token, is untouched. Header block only; folded lines go too.
     */
    public static function withoutInternalHeaders(string $raw): string
    {
        $split = strpos($raw, "\r\n\r\n");
        if ($split === false) {
            return $raw;
        }
        $head = substr($raw, 0, $split);
        $head = preg_replace('/^X-KT-[A-Za-z0-9-]*:[^\r\n]*(?:\r\n[ \t][^\r\n]*)*(?:\r\n|$)/mi', '', $head);

        return rtrim($head, "\r\n") . substr($raw, $split);
    }

    private function post(string $token, string $raw)
    {
        return Http::withToken($token)->acceptJson()->timeout(30)
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', ['raw' => $raw]);
    }

    public function __toString(): string
    {
        return 'gmail-api://' . ($this->sender->google_email ?? 'not-connected');
    }
}
