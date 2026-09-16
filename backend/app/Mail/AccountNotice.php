<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Generic transactional notice email — replaces the plain-text Mail::raw()
 * calls in AdminController so password resets, welcome resends and similar
 * notices all get the branded layout (logo + footer with privacy / terms /
 * contact).
 */
final class AccountNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $subjectLine,
        public readonly string $bodyText,
        public readonly ?string $ctaLabel = null,
        public readonly ?string $ctaUrl = null,
        /* WHICH AGENCY IS SENDING.

           This is a plain int and not a withSymfonyMessage() closure on purpose:
           these notices are QUEUED, a queued mailable is serialized, and
           "Serialization of 'Closure' is not allowed" fails the send outright —
           which is exactly how two sign-in emails were lost before this was moved
           here. A scalar serializes; a closure does not. */
        public readonly ?int $agencyId = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    // Account/welcome/reset emails are how a user gets INTO their account, so they
    // must reach even a not-yet-onboarded user. This header exempts them from the
    // not-onboarded suppression gate.
    public function headers(): Headers
    {
        $text = ['X-KT-Invite' => '1'];

        /* Name the sending tenant so the suppression gate applies THAT agency's
           switches, rather than judging the address against every account carrying
           it — one address can hold accounts in several agencies, and any one of
           them switched off would otherwise cancel this. See App\Support\MailScope. */
        if ($this->agencyId) {
            $text['X-KT-Agency-Id'] = (string) $this->agencyId;
        }

        return new Headers(text: $text);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account-notice',
            with: [
                'recipientName' => $this->recipientName,
                'subjectLine'   => $this->subjectLine,
                'bodyText'      => $this->bodyText,
                'ctaLabel'      => $this->ctaLabel,
                'ctaUrl'        => $this->ctaUrl,
                'appUrl'        => config('app.url', 'https://app.kiddietrac.com'),
            ],
        );
    }
}
