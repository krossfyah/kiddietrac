<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * Decorates the real mail transport so EVERY send failure is audited (2026-07-09).
 *
 * On success it is a pass-through (the MessageSent event already logs sends).
 * On failure it records an `email_logs` row (status=failed, error) and an
 * `audit_logs` row (action email.failed), then re-throws so the caller's own
 * error handling is unchanged. Wraps Laravel's already-built transport — it does
 * NOT construct a new one, so it can never mis-configure and break sending.
 */
final class FailureAuditingTransport implements TransportInterface
{
    public function __construct(private TransportInterface $inner)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        try {
            return $this->inner->send($message, $envelope);
        } catch (Throwable $e) {
            $this->audit($message, $e);
            throw $e;
        }
    }

    private function audit(RawMessage $message, Throwable $e): void
    {
        try {
            $to = null;
            $cc = null;
            $bcc = null;
            $subject = null;
            if ($message instanceof Email) {
                $to = collect($message->getTo())->map(fn ($a) => $a->getAddress())->filter()->implode(', ');
                $cc = collect($message->getCc())->map(fn ($a) => $a->getAddress())->filter()->implode(', ') ?: null;
                $bcc = collect($message->getBcc())->map(fn ($a) => $a->getAddress())->filter()->implode(', ') ?: null;
                $subject = $message->getSubject();
                try { $h = $message->getHtmlBody(); if (! is_string($h)) $h = $message->getTextBody(); $bodyHtml = (is_string($h) && $h !== '') ? mb_substr($h, 0, 500000) : null; } catch (\Throwable $eb) { $bodyHtml = null; }
            }
            $err = substr($e->getMessage(), 0, 1000);

            if (Schema::hasTable('email_logs')) {
                $failAgency = null;
                try { if (Schema::hasColumn('email_logs', 'agency_id') && $to) { $ft = trim(explode(',', (string) $to)[0]); if ($ft !== '') $failAgency = \App\Support\AgencyMail::agencyOfEmail($ft); } } catch (\Throwable $e) {}
                DB::table('email_logs')->insert([
                    'agency_id'  => $failAgency,
                    'to_email'   => $to ?: null,
                    // A failed send that was copied to the directors still needs to show
                    // who WOULD have been copied, or the failure looks smaller than it is.
                    'cc'         => Schema::hasColumn('email_logs', 'cc') ? $cc : null,
                    'bcc'        => Schema::hasColumn('email_logs', 'bcc') ? $bcc : null,
                    'from_email' => 'noreply@kiddietrac.com',
                    'subject'    => $subject,
                    'mailer'     => config('mail.default'),
                    'status'     => 'failed',
                    'error'      => $err,
                    'body_html'  => $bodyHtml ?? null,
                    'created_at' => now(),
                ]);
            }
            if (Schema::hasTable('audit_logs')) {
                DB::table('audit_logs')->insert([
                    'user_id'     => optional(auth()->user())->id,
                    'action'      => 'email.failed',
                    'entity_type' => 'email',
                    'entity_id'   => null,
                    'payload'     => json_encode(['to' => $to, 'subject' => $subject, 'error' => substr($err, 0, 400)]),
                    'ip_address'  => request() ? substr((string) request()->ip(), 0, 45) : null,
                    'user_agent'  => null,
                    'created_at'  => now(),
                ]);
            }
        } catch (Throwable $ignore) {
            // auditing must never mask the real mail error
        }
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }
}
