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
                    DB::table('audit_logs')->insert([
                        'user_id'     => optional(auth()->user())->id,
                        'action'      => 'email.sent',
                        'entity_type' => 'email',
                        'entity_id'   => null,
                        'payload'     => json_encode(['to' => $addr($msg->getTo()), 'subject' => $msg->getSubject(), 'mailer' => config('mail.default')]),
                        'ip_address'  => request() ? substr((string) request()->ip(), 0, 45) : null,
                        'user_agent'  => request() ? substr((string) request()->userAgent(), 0, 500) : null,
                        'created_at'  => now(),
                    ]);
                }

                // email_logs: skip messages that log themselves (open-tracking) to avoid a dup row.
                if (! Schema::hasTable('email_logs')) return;
                if ($msg->getHeaders() && $msg->getHeaders()->has('X-KT-Logged')) return;
                DB::table('email_logs')->insert([
                    'to_email'   => $addr($msg->getTo()) ?: null,
                    'to_name'    => $name($msg->getTo()) ?: null,
                    'from_email' => $addr($msg->getFrom()) ?: null,
                    'subject'    => $msg->getSubject(),
                    'mailer'     => config('mail.default'),
                    'status'     => 'sent',
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
