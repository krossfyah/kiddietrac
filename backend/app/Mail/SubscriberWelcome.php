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
 * Confirmation email sent to a new marketing-site subscriber. Uses the shared
 * branded layout (logo + footer) and explicitly includes an unsubscribe link
 * plus Privacy Policy and Terms of Use links in the body (CASL / good-practice).
 * A List-Unsubscribe header enables one-click unsubscribe in Gmail/Outlook.
 */
final class SubscriberWelcome extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $unsubscribeUrl,
        public readonly string $privacyUrl,
        public readonly string $termsUrl,
        // Which form they used (site_subscribers.source). A webinar sign-up is told about
        // the webinar, not welcomed to a newsletter it never asked for (2026-10-22).
        public readonly string $source = '',
    ) {}

    public function isWebinar(): bool
    {
        return str_starts_with(strtolower($this->source), 'webinar');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->isWebinar()
            ? "You're on the KiddieTrac webinar list"
            : "You're subscribed to KiddieTrac updates");
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            // Sent only because this person just asked to subscribe: an agency's mail switch
            // (their address may also belong to a family or staff account) must not cancel it.
            'X-KT-Bypass-Suppression' => '1',
            'X-KT-Platform' => '1',   // KiddieTrac's own website mail: Platform in the email log
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscriber-welcome',
            with: [
                'recipientName'  => $this->recipientName,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'privacyUrl'     => $this->privacyUrl,
                'termsUrl'       => $this->termsUrl,
                'webinar'        => $this->isWebinar(),
                'appUrl'         => config('app.url', 'https://app.kiddietrac.com'),
            ],
        );
    }
}
