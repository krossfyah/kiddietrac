<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmation that somebody has been removed from the marketing list.
 *
 * This is a TRANSACTIONAL message, not marketing — it is the receipt for an action the
 * person just took, and it is the last thing they will be sent. So it carries no product
 * pitch and no unsubscribe link: there is nothing left to unsubscribe from, and offering
 * one would suggest the request had not been honoured.
 *
 * It does say how to come back, because the commonest reason for writing in afterwards is
 * having unsubscribed by mistake.
 */
final class SubscriberUnsubscribed extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $recipientName = '',
        /** Whether they did it themselves, or an administrator did it on request. */
        public readonly string $actor = 'self',
        public readonly string $resubscribeUrl = 'https://www.kiddietrac.com',
        public readonly string $privacyUrl = 'https://www.kiddietrac.com/privacy',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You've been unsubscribed from KiddieTrac");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscriber-unsubscribed',
            with: [
                'recipientEmail' => $this->recipientEmail,
                'recipientName' => $this->recipientName,
                'actor' => $this->actor,
                'resubscribeUrl' => $this->resubscribeUrl,
                'privacyUrl' => $this->privacyUrl,
                'appUrl' => config('app.url', 'https://app.kiddietrac.com'),
            ],
        );
    }
}
