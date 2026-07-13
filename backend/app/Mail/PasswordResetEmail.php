<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class PasswordResetEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $resetUrl,
        public readonly string $expiresInMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your Kiddietrac password');
    }

    public function content(): Content
    {
        // Use the shared branded header/footer (EmailTemplate::wrap) — the same
        // KiddieTrac banner as invites/announcements — instead of the old plain
        // Blade view, so this email matches the rest.
        $first   = htmlspecialchars($this->recipientName !== '' ? $this->recipientName : 'there');
        $safeUrl = htmlspecialchars($this->resetUrl);

        $body = '<p style="margin:0 0 14px;">Hi ' . $first . ',</p>'
            . '<p style="margin:0 0 16px;">We received a request to reset your Kiddietrac password. '
            . 'Click the button below to choose a new one.</p>'
            . \App\Services\EmailTemplate::button('Reset my password →', $this->resetUrl)
            . '<p style="margin:16px 0 0;font-size:12px;color:#64748B;">Or paste this link into your browser:<br>'
            . '<a href="' . $safeUrl . '" style="color:#1F6080;">' . $safeUrl . '</a></p>'
            . '<p style="margin:14px 0 0;font-size:12px;color:#94A3B8;">This link expires in '
            . htmlspecialchars($this->expiresInMinutes) . ' minutes. If you didn\'t request a reset, '
            . 'you can safely ignore this email — your password won\'t change.</p>';

        $html = \App\Services\EmailTemplate::wrap(null, $body, [
            'eyebrow'   => 'PASSWORD RESET',
            'title'     => 'Reset your password',
            'subtitle'  => 'Choose a new password for your account',
            'preheader' => 'Reset your Kiddietrac password — this link expires in ' . $this->expiresInMinutes . ' minutes.',
        ]);

        return new Content(htmlString: $html);
    }
}
