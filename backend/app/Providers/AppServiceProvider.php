<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // ── Outbound mail routing (superadmin-managed, DB-configured) ──────────
        // Register the Microsoft Graph transport and apply platform_settings, so a
        // superadmin can switch KiddieTrac between sendmail and Microsoft Graph from
        // the portal (no redeploy). When Graph is active, mail routes through a
        // FAILOVER transport (graph → sendmail): if the Graph secret ever expires or
        // Graph has an outage, sending falls back to sendmail automatically and email
        // never goes down. Wrapped so a bad/absent settings row can't break booting.
        try {
            \Illuminate\Support\Facades\Mail::extend('graph', function (array $config) {
                return new \App\Mail\GraphTransport($config);
            });
            // Per-agency (white-label) router: an agency can send from their OWN
            // Microsoft 365 / Google; everyone else falls through to the platform
            // Graph → sendmail failover. Selected as the default in applyMail().
            \Illuminate\Support\Facades\Mail::extend('agency_router', function (array $config) {
                return new \App\Mail\AgencyRouterTransport();
            });
            \App\Support\PlatformSettings::applyMail();
        } catch (\Throwable $e) {
            // keep whatever .env configured; never block boot or sending
        }

        // Login throttle keyed by EMAIL+IP (not IP alone). The old plain per-IP
        // throttle:10,1 locked out account B just because someone on the same IP
        // (multi-account testing, a shared office/home NAT) had tried account A
        // 10× in a minute — "too many attempts" on an account you never touched.
        // Now each account gets its own bucket; a looser per-IP cap is the backstop.
        RateLimiter::for('login', function ($request) {
            $email = mb_strtolower(trim((string) $request->input('email')));
            return [
                Limit::perMinute(10)->by('login:' . $email . '|' . $request->ip()),
                Limit::perMinute(40)->by('loginip:' . $request->ip()),
            ];
        });

        // Microsoft Socialite driver (community provider) — active once installed.
        if (class_exists(\SocialiteProviders\Manager\SocialiteWasCalled::class)) {
            Event::listen(\SocialiteProviders\Manager\SocialiteWasCalled::class, function ($event) {
                $event->extendSocialite('microsoft', \SocialiteProviders\Microsoft\Provider::class);
            });
        }
        // v22p92 — log every outbound email so platform admins can audit what
        // actually left the system (delivery itself still depends on DNS/SPF,
        // but the send is recorded). Best-effort; never breaks a real send.
        // KILL-SWITCH: no email may reach the live agency while we test against the
        // Test Agency. Registered on MessageSending (returning false cancels the
        // send), so EVERY path is covered — controllers, queued closures, scheduled
        // commands — and no future call site has to remember to check.
        // Configured by MAIL_SUPPRESS_AGENCIES in .env.
        Event::listen(
            \Illuminate\Mail\Events\MessageSending::class,
            [\App\Listeners\SuppressAgencyMail::class, 'handle']
        );

        Event::listen(MessageSent::class, function (MessageSent $event) {
            try {
                $msg = $event->message; // Symfony\Component\Mime\Email
                $addr = function ($list) {
                    return collect($list ?: [])->map(fn ($a) => method_exists($a, 'getAddress') ? $a->getAddress() : (string) $a)->filter()->implode(', ');
                };
                $name = function ($list) {
                    return collect($list ?: [])->map(fn ($a) => method_exists($a, 'getName') ? $a->getName() : '')->filter()->implode(', ');
                };

                // Portal-wide audit trail: record EVERY outbound email (including the
                // self-logged / tracked ones) so email activity shows in the audit log.
                if (Schema::hasTable('audit_logs')) {
                    // Tag the sending agency so this email shows only in the right
                    // agency's audit log. Prefer the recipient's agency (the mail
                    // is "about" them); fall back to the acting user's agency.
                    $emailAgency = null;
                    try {
                        $firstTo = collect($msg->getTo() ?: [])->map(fn ($a) => method_exists($a, 'getAddress') ? $a->getAddress() : null)->filter()->first();
                        if ($firstTo) {
                            $emailAgency = \App\Support\AgencyMail::agencyOfEmail($firstTo);
                        }
                        if (! $emailAgency && auth()->id()) {
                            $emailAgency = \App\Support\AuditScope::resolve((int) auth()->id());
                        }
                    } catch (\Throwable $e) {}
                    DB::table('audit_logs')->insert([
                        'user_id'     => optional(auth()->user())->id,
                        'agency_id'   => $emailAgency,
                        'action'      => 'email.sent',
                        'entity_type' => 'email',
                        'entity_id'   => null,
                        'payload'     => json_encode(['to' => $addr($msg->getTo()), 'subject' => $msg->getSubject(), 'mailer' => config('mail.default')]),
                        'ip_address'  => request() ? substr((string) request()->ip(), 0, 45) : null,
                        'user_agent'  => request() ? substr((string) request()->userAgent(), 0, 500) : null,
                        'created_at'  => now(),
                    ]);
                }

                // email_logs: skip messages that log themselves, to avoid a duplicate row.
                //
                // CONTRACT for X-KT-Logged — only set it if BOTH are true:
                //   1. the sender embeds an open-tracking pixel in the HTML, so its
                //      email_logs row (which owns the token) must exist before sending; and
                //   2. the sender actually performs that insert.
                //
                // Only AdminController + PlatformController (invite / resend) qualify.
                // Setting the header without doing the insert makes the email vanish from
                // the log entirely — that is how the educator day-brief and absence notices
                // were delivered for weeks with no trace. Doing the insert WITHOUT a pixel
                // is also wrong: it writes status='sent' before the suppression listener
                // has had its say, so a suppressed message was logged as both 'sent' and
                // 'suppressed'. Senders with nothing to track simply omit the header and
                // let this listener log them — it only ever runs on a real delivery.
                //
                // `mail:reconcile-logs` compares the email.sent audit rows above against
                // email_logs and reports/backfills anything a sender fails to record.
                if (! Schema::hasTable('email_logs')) return;
                if ($msg->getHeaders() && $msg->getHeaders()->has('X-KT-Logged')) return;
                // Stamp the OWNING agency so the email log is strictly per-tenant (same
                // rule as audit_logs). Prefer the recipient's agency; fall back to the
                // acting user's. Self-contained so it never depends on the audit block.
                $logAgency = null;
                if (Schema::hasColumn('email_logs', 'agency_id')) {
                    try {
                        $ft = collect($msg->getTo() ?: [])->map(fn ($a) => method_exists($a, 'getAddress') ? $a->getAddress() : null)->filter()->first();
                        if ($ft) $logAgency = \App\Support\AgencyMail::agencyOfEmail($ft);
                        if (! $logAgency && auth()->id()) $logAgency = \App\Support\AuditScope::resolve((int) auth()->id());
                    } catch (\Throwable $e) {}
                }
                DB::table('email_logs')->insert([
                    'to_email'   => $addr($msg->getTo()) ?: null,
                    'to_name'    => $name($msg->getTo()) ?: null,
                    // Copied recipients were never recorded, so "was the director
                    // copied?" — the whole reason these notices carry a BCC — could not
                    // be answered from the log. Guarded on the column so an older
                    // database keeps working if this deploys ahead of the migration.
                    'cc'         => Schema::hasColumn('email_logs', 'cc') ? ($addr($msg->getCc()) ?: null) : null,
                    'bcc'        => Schema::hasColumn('email_logs', 'bcc') ? ($addr($msg->getBcc()) ?: null) : null,
                    'from_email' => $addr($msg->getFrom()) ?: null,
                    'subject'    => $msg->getSubject(),
                    'mailer'     => config('mail.default'),
                    'status'     => 'sent',
                    'agency_id'  => $logAgency,
                    'body_html'  => (function () use ($msg) { try { $h = $msg->getHtmlBody(); if (! is_string($h)) $h = $msg->getTextBody(); return (is_string($h) && $h !== '') ? mb_substr($h, 0, 500000) : null; } catch (\Throwable $e) { return null; } })(),
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // swallow — logging must never interfere with sending
            }
        });

        // Wrap the mail transport so send FAILURES are audited (email_logs 'failed'
        // + audit_logs 'email.failed'). We wrap the transport Laravel already built
        // rather than constructing a new one, so sending can never break.
        $this->app->resolving('mailer', function ($mailer) {
            try {
                $mailer->setSymfonyTransport(new \App\Mail\FailureAuditingTransport($mailer->getSymfonyTransport()));
            } catch (\Throwable $e) {
                // leave the default transport intact on any incompatibility
            }
        });
    }
}
