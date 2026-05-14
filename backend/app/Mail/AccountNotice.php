<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
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
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
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
