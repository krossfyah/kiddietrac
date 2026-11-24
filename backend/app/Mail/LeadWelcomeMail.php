<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * The welcome a new lead gets (2026-10-22). Anthony: "with leads coming through chat or
 * through the website we need to formulate a welcome email to exploring kiddie trac and
 * add our video ... features and how we can work with daycare and home childcare centres
 * across North America - highlight our benefits, include the brochure ... book me a
 * meeting link". Sent once per address by App\Support\LeadWelcome, which also puts the
 * address on the subscriber list. Replies go to the sales inbox.
 */
final class LeadWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public const BROCHURE_URL = 'https://www.kiddietrac.com/downloads/kiddietrac-brochure.pdf';

    public function __construct(
        public readonly string $recipientName,
        public readonly string $unsubscribeUrl,
        public readonly string $source = '',
        // Only when the person themselves asked for mail (a sign-up form): an agency's
        // mail switch must not cancel what they just asked for. Never for a chat.
        public readonly bool $optedIn = false,
    ) {}

    public function envelope(): Envelope
    {
        $first = trim(explode(' ', trim($this->recipientName))[0] ?? '');
        $first = ($first !== '' && strtolower($first) !== 'there') ? $first . ', welcome' : 'Welcome';

        // From the sales team, not noreply (Anthony, 2026-10-22): a reply is the point.
        return new Envelope(
            from: new Address('sales@kiddietrac.com', 'KiddieTrac'),
            subject: $first . ' to KiddieTrac — your one-minute tour is inside',
            replyTo: [new Address('sales@kiddietrac.com', 'KiddieTrac')],
        );
    }

    public function headers(): Headers
    {
        $h = [
            // Sent through the sales@ mailbox (GraphTransport::SEND_AS), noreply if refused.
            'X-KT-Send-As' => 'sales@kiddietrac.com',
            'List-Unsubscribe' => '<' . $this->unsubscribeUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
        if ($this->optedIn) {
            $h['X-KT-Bypass-Suppression'] = '1';
        }

        return new Headers(text: $h);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.lead-welcome',
            with: [
                'recipientName' => $this->recipientName,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'webinar' => str_starts_with(strtolower($this->source), 'webinar'),
                'brochureUrl' => self::BROCHURE_URL,
                'appUrl' => config('app.url', 'https://app.kiddietrac.com'),
            ],
        );
    }

    /** The brochure itself, when the file is on this server; the button links to it either way. */
    public function attachments(): array
    {
        $file = self::brochureFile();

        return $file ? [Attachment::fromPath($file)->as('KiddieTrac-brochure.pdf')->withMime('application/pdf')] : [];
    }

    public static function brochureFile(): ?string
    {
        foreach ([base_path('../../public_html/downloads/kiddietrac-brochure.pdf'), base_path('../public_html/downloads/kiddietrac-brochure.pdf')] as $f) {
            if (is_file($f) && filesize($f) < 8 * 1024 * 1024) {
                return realpath($f) ?: $f;
            }
        }

        return null;
    }
}
