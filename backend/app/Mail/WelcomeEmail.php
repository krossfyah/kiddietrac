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
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->role) {
            'parent' => "You've been invited to {$this->centreName} on Kiddietrac",
            'educator', 'director' => "Welcome to the {$this->centreName} team on Kiddietrac",
            default => "Welcome to Kiddietrac",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
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
