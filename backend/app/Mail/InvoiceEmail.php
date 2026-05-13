<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class InvoiceEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $centreName,
        public readonly string $invoiceNumber,
        public readonly float $totalDue,
        public readonly string $dueDate,
        public readonly array $lineItems,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Invoice from {$this->centreName} — {$this->invoiceNumber}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice',
            with: [
                'recipientName' => $this->recipientName,
                'centreName' => $this->centreName,
                'invoiceNumber' => $this->invoiceNumber,
                'totalDue' => $this->totalDue,
                'dueDate' => $this->dueDate,
                'lineItems' => $this->lineItems,
                'appUrl' => config('app.url', 'https://app.kiddietrac.com'),
            ],
        );
    }
}
