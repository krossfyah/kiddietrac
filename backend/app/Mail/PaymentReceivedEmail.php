<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class PaymentReceivedEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $centreName,
        public readonly string $invoiceNumber,
        public readonly float $amount,
        public readonly string $method,
        public readonly float $remainingBalance,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Payment received — {$this->invoiceNumber}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment',
            with: [
                'recipientName' => $this->recipientName,
                'centreName' => $this->centreName,
                'invoiceNumber' => $this->invoiceNumber,
                'amount' => $this->amount,
                'method' => $this->method,
                'remainingBalance' => $this->remainingBalance,
                'fullyPaid' => $this->remainingBalance <= 0.01,
            ],
        );
    }
}
