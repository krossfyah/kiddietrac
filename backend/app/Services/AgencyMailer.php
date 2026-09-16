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

    /**
     * The agency the most recent send was made AS.
     *
     * The email log used to derive its agency from the RECIPIENT, which is wrong whenever
     * a person belongs to a different agency than the thing being emailed about — a Test
     * Agency child's daily summary sent to an address that belongs to an iLearn user was
     * filed under iLearn, putting one agency's children in another's log. The sender
     * knows the answer; it just never said so.
     */
    public static ?int $lastAgencyId = null;

    public function __construct(?object $agency)
    {
        $this->agency = $agency;
    }

    public static function forAgency(?int $agencyId): self
    {
        // Recorded even when the agency has no mail override of its own: attribution and
        // delivery config are different questions, and the log needs the former regardless.
        self::$lastAgencyId = $agencyId ?: null;
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
        /* A white-label agency sends through its own M365/Google, on a transport built
           here rather than by the MailManager — so it needs the failure auditing applied
           explicitly, or a bounce on an agency's own mailbox would be the one kind of
           failure still invisible. */
        $transport = \App\Mail\FailureAuditingTransport::wrap(Transport::fromDsn($dsn));

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
     * Send pre-built HTML AS THIS AGENCY — and say so on the message.
     *
     * WHY THIS EXISTS RATHER THAN `->html(...)`.
     *
     * SuppressAgencyMail decides whose mail a message is by reading X-KT-Agency-Id.
     * Without that header it falls back to judging the send by EVERY account that
     * shares a recipient's address — so a message to somebody who holds a role in a
     * switched-off agency is cancelled no matter which agency actually sent it.
     *
     * That is not hypothetical. mr.anthonyhosein@gmail.com is an agency_admin at iLearn
     * and also holds a role at Test Agency, and he is BCC'd on iLearn's oversight
     * notices. Test Agency's master switch is off by design. Result, measured
     * 2026-09-16: 50 real iLearn emails cancelled — de-enrolment confirmations to
     * families, absence alerts, leaving notices, account deactivations — including
     * every notice for the Chearstine Fitzpatrick provider closure. Nothing failed
     * loudly; they are all sitting in email_logs as 'suppressed' against the WRONG
     * agency.
     *
     * 31 of the 38 send sites had simply forgotten the header. Asking each one to
     * remember is how it got to 31, so the stamp moves here, where the agency is not in
     * doubt — this object was built from it. A caller's own closure still runs first and
     * can override the header if it genuinely needs to.
     *
     * (Anthony, 2026-09-16: "emails should have went out to directors/admins and the
     * educator themselves" — they were composed correctly and then cancelled.)
     */
    public function html(string $body, ?\Closure $build = null)
    {
        $agencyId = $this->agency->id ?? self::$lastAgencyId;

        return $this->mailer()->html($body, function ($m) use ($build, $agencyId) {
            if ($build) {
                $build($m);
            }
            try {
                if ($agencyId && ! $m->getHeaders()->has('X-KT-Agency-Id')) {
                    $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId);
                }
            } catch (\Throwable $e) {
                // A header must never be the reason a notice fails to send.
            }
        });
    }

    /** Plain text, same stamp. One sender still uses raw() and it must not be the gap. */
    public function raw(string $text, ?\Closure $build = null)
    {
        $agencyId = $this->agency->id ?? self::$lastAgencyId;

        return $this->mailer()->raw($text, function ($m) use ($build, $agencyId) {
            if ($build) { $build($m); }
            try {
                if ($agencyId && ! $m->getHeaders()->has('X-KT-Agency-Id')) {
                    $m->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId);
                }
            } catch (\Throwable $e) {}
        });
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
