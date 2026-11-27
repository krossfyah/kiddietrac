<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class WelcomeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $recipientEmail,
        public readonly string $tempPassword,
        public readonly string $centreName,
        public readonly string $role, // 'parent', 'educator', 'director'
        public readonly ?string $childNames = null,
        // The house frame for a parent (2026-11-27): the agency (branding) and centre (how families name it).
        public readonly ?int $agencyId = null,
        public readonly ?int $centreId = null,
    ) {}

    /** @var array{subject:string, html:string}|null */
    private ?array $framed = null;

    /* A PARENT'S WELCOME IN THE HOUSE FRAME (2026-11-27): App\Support\FamilyNoticeMail::welcome, so a white-label
       agency's branding applies. Staff and director welcomes keep the Blade layout. Same details, same link. */
    private function framed(): ?array
    {
        if ($this->role !== 'parent' || ! $this->agencyId) {
            return null;
        }

        return $this->framed ??= \App\Support\FamilyNoticeMail::welcome($this->agencyId, $this->centreId,
            $this->recipientName, $this->recipientEmail, $this->tempPassword, $this->childNames,
            (string) config('app.url', 'https://app.kiddietrac.com'));
    }

    public function envelope(): Envelope
    {
        if ($f = $this->framed()) {
            return new Envelope(subject: $f['subject']);
        }
        $subject = match ($this->role) {
            'parent' => "You've been invited to {$this->centreName} on Kiddietrac",
            'educator', 'director' => "Welcome to the {$this->centreName} team on Kiddietrac",
            default => "Welcome to Kiddietrac",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        if ($f = $this->framed()) {
            return new Content(htmlString: $f['html']);
        }
        return new Content(
            view: 'emails.welcome',
            with: [
                'recipientName' => $this->recipientName,
                'recipientEmail' => $this->recipientEmail,
                'tempPassword' => $this->tempPassword,
                'centreName' => $this->centreName,
                'role' => $this->role,
                'childNames' => $this->childNames,
                'appUrl' => config('app.url', 'https://app.kiddietrac.com'),
            ],
        );
    }
}
