<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * v22p36 — Resolves which SMTP credentials to use for a given agency.
 *
 * Each customer agency can configure their own outbound mail (email_smtp_*
 * columns on agencies). When set, all transactional + marketing email for
 * that agency goes through those credentials so messages arrive from the
 * agency's own domain. When unset, falls back to the platform-level config
 * from .env (MAIL_*).
 *
 * Usage:
 *   AgencyMailer::forAgency($agencyId)->send($mailable);
 *   AgencyMailer::forAgency($agencyId)->raw('subject', 'body', 'to@example');
 */
final class AgencyMailer
{
    private ?object $agency;

    public function __construct(?object $agency)
    {
        $this->agency = $agency;
    }

    public static function forAgency(?int $agencyId): self
    {
        $agency = $agencyId ? DB::table('agencies')->where('id', $agencyId)->first() : null;
        return new self($agency);
    }

    /**
     * Build a Symfony transport for this agency, or return the default
     * Laravel mailer when no per-agency config exists.
     */
    public function mailer(): Mailer
    {
        if (!$this->hasOverride()) {
            return Mail::mailer(); // default driver from .env
        }

        $dsn = $this->dsn();
        $transport = Transport::fromDsn($dsn);

        $mailer = new Mailer(
            'kt-agency-' . ($this->agency->id ?? 'na'),
            view(),
            $transport,
            app('events')
        );
        $mailer->alwaysFrom($this->fromAddress(), $this->fromName());

        return $mailer;
    }

    /**
     * Send a Laravel Mailable using this agency's mailer.
     */
    public function send($mailable, ?string $to = null): void
    {
        try {
            $m = $this->mailer();
            if ($to) {
                $m->to($to)->send($mailable);
            } else {
                $m->send($mailable);
            }
        } catch (\Throwable $e) {
            Log::warning('AgencyMailer send failed', [
                'agency_id' => $this->agency->id ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function fromAddress(): string
    {
        return $this->agency->email_from_address
            ?? Config::get('mail.from.address', 'noreply@kiddietrac.com');
    }

    public function fromName(): string
    {
        return $this->agency->email_from_name
            ?? ($this->agency->name ?? Config::get('mail.from.name', 'Kiddietrac'));
    }

    private function hasOverride(): bool
    {
        return $this->agency && !empty($this->agency->email_smtp_host);
    }

    /**
     * Symfony Mailer DSN string. Example outputs:
     *   smtp://user:pass@host:587?encryption=tls
     *   smtps://user:pass@host:465
     */
    private function dsn(): string
    {
        $host = $this->agency->email_smtp_host;
        $port = (int) ($this->agency->email_smtp_port ?? 587);
        $user = rawurlencode((string) ($this->agency->email_smtp_user ?? ''));
        $pass = rawurlencode((string) ($this->agency->email_smtp_pass ?? ''));
        $enc  = $this->agency->email_smtp_encryption ?? 'tls';

        $scheme = $enc === 'ssl' ? 'smtps' : 'smtp';
        $auth = ($user || $pass) ? "$user:$pass@" : '';
        $dsn  = "$scheme://$auth$host:$port";
        return $dsn;
    }
}
