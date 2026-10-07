<?php

declare(strict_types=1);

namespace App\Support;

use App\Listeners\EmailSubjectOverride;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * A preview of EVERY email, built by the code that really sends it (2026-10-06).
 *
 * Anthony: "i don't see all email templates being able to edit and preview only some?"
 *
 * Most emails are composed inline by the thing that sends them, so the only honest
 * preview is to run that code. This does, with made-up records (EmailSampleData), and
 * makes sure nothing it does can leave the building:
 *
 *   - every MessageSending listener is set aside for the run and replaced by one that
 *     records the message and returns false — Laravel's mailer then does not hand it to
 *     any transport, the agency's own SMTP included;
 *   - the default mailer is the in-memory `array` transport as a second wall, and the
 *     agency's own SMTP host is blanked inside the transaction as a third;
 *   - queued work (Bus and Queue) is faked, so a sender that queues its email never runs
 *     the job — the HTML and subject are read out of the queued closure instead;
 *   - push (FcmService, WebPushService) and SMS (SmsController) are swapped for stubs,
 *     and the HTTP client is faked, because several senders also push or text;
 *   - the cache is the in-memory array store, so a "sent today" dedupe key written by a
 *     preview cannot stop tomorrow's real email;
 *   - everything happens inside a database transaction that is always rolled back.
 *
 * Everything is put back in `finally`, so a sender that throws leaves nothing behind.
 */
final class EmailPreviews
{
    /** Where a preview's message is addressed. Never delivered: every send is cancelled. */
    public const SAMPLE_TO = 'sample.recipient@example.com';

    /** The last run's raw capture, for the default-equality check (email:previews --check). */
    public static ?array $lastCapture = null;

    /** Keys with more than one version, and the versions a preview can show. */
    public const VARIANTS = [
        'closure-reminder' => ['parent' => 'Parent — ordinary closure', 'parent-holiday' => 'Parent — statutory holiday',
            'staff' => 'Staff — ordinary closure', 'staff-holiday' => 'Staff — statutory holiday'],
        'checkin-reminder' => ['morning' => 'Morning — not signed in', 'evening' => 'Evening — not signed out'],
        'check-event' => ['in' => 'Signed in', 'out' => 'Signed out'],
        'portal-access' => ['paused' => 'Access paused', 'restored' => 'Access restored'],
        'timeoff-decision' => ['approved' => 'Approved', 'declined' => 'Declined'],
        'password-expiry' => ['soon' => 'Expires in a few days', 'expired' => 'Already expired'],
        'files-requested' => ['first' => 'First request', 'reminder' => 'Reminder'],
        'late-pickup-alert' => ['decide' => 'Needs a decision', 'charged' => 'Fee charged'],
        'walk-notice' => ['out' => 'Out on a walk', 'back' => 'Back from the walk'],
        'birthday' => ['parent' => 'To a parent', 'educator' => 'To an educator', 'person' => 'To the birthday person'],
    ];

    public static function has(string $key): bool
    {
        return isset(self::samplers()[$key]);
    }

    /**
     * Build one email. Returns ok, subject, html, attachments (names), how ('sent' |
     * 'queued' | 'built'), and error when the sender could not be run.
     */
    public static function render(int $agencyId, string $key, ?array $draft = null, ?string $variant = null): array
    {
        $fn = self::samplers()[$key] ?? null;
        if (! $fn) {
            return ['ok' => false, 'error' => 'No preview for this email.'];
        }
        $variant = $variant && isset(self::VARIANTS[$key][$variant]) ? $variant : (array_key_first(self::VARIANTS[$key] ?? []) ?? '');

        $run = function () use ($agencyId, $key, $fn, $variant) {
            return self::capture($agencyId, function (EmailSampleData $fx) use ($fn, $variant) {
                return $fn($fx, $variant);
            });
        };
        // Some senders render a PDF attachment as they go; give them room.
        $mem = ini_get('memory_limit');
        if ($mem !== '-1' && (int) $mem < 512) {
            @ini_set('memory_limit', '512M');
        }
        $cap = EmailOverrides::withDraft($key, $draft, $run);
        self::$lastCapture = $cap;

        return self::pick($key, $cap);
    }

    // ── the harness ─────────────────────────────────────────────────────────

    private static function capture(int $agencyId, callable $fn): array
    {
        $out = ['messages' => [], 'queued' => [], 'built' => [], 'returned' => null, 'error' => null];

        $events = app('events');
        $raw = method_exists($events, 'getRawListeners') ? $events->getRawListeners() : [];
        $keep = [
            MessageSending::class => $raw[MessageSending::class] ?? [],
            MessageSent::class => $raw[MessageSent::class] ?? [],
        ];
        $events->forget(MessageSending::class);
        $events->forget(MessageSent::class);
        $events->listen(MessageSending::class, function (MessageSending $e) use (&$out) {
            (new EmailSubjectOverride())->handle($e);
            $m = $e->message;
            $html = $m->getHtmlBody();
            $atts = [];
            foreach ($m->getAttachments() as $a) {
                $atts[] = (string) ($a->getFilename() ?? 'attachment');
            }
            $out['messages'][] = [
                'subject' => (string) $m->getSubject(),
                'html' => is_string($html) ? $html : null,
                'text' => is_string($m->getTextBody()) ? $m->getTextBody() : null,
                'to' => array_map(fn ($x) => $x->getAddress(), $m->getTo()),
                'from' => array_map(fn ($x) => ['name' => $x->getName(), 'address' => $x->getAddress()], $m->getFrom()),
                'attachments' => $atts,
            ];

            return false;   // never handed to a transport
        });

        $mailDefault = config('mail.default');

        $origBus = app(BusDispatcher::class);
        $origQueue = app('queue');
        $origHttp = Http::getFacadeRoot();
        $cache = app('cache');
        $cacheDefault = $cache->getDefaultDriver();
        $logDefault = app('log')->getDefaultDriver();
        $stubbed = [\App\Services\FcmService::class, \App\Services\WebPushService::class,
            \App\Http\Controllers\Api\SmsController::class];

        $level = DB::transactionLevel();
        try {
            Bus::fake();
            Queue::fake();
            config(['mail.default' => 'array']);
            self::forgetMailers();
            $http = new \Illuminate\Http\Client\Factory(app('events'));
            $http->fake();
            Http::swap($http);
            $cache->setDefaultDriver('array');
            $cache->store('array')->flush();
            if (! getenv('KT_PREVIEW_LOG')) {
                app('log')->setDefaultDriver('null');
            }
            app()->instance(\App\Services\FcmService::class, new class () extends \App\Services\FcmService {
                public function __construct()
                {
                }

                public function configured(): bool
                {
                    return false;
                }

                public function sendToTokens(array $tokens, string $title, string $body, array $data = [], bool $urgent = false): array
                {
                    return [];
                }

                public function sendToUser(int $userId, string $title, string $body, string $link = '', bool $urgent = false, bool $forceUrgent = false, bool $webFallback = true): array
                {
                    return [];
                }
            });
            app()->instance(\App\Services\WebPushService::class, new class () {
                public function __call($n, $a)
                {
                    return str_starts_with($n, 'send') ? 0 : false;
                }
            });
            app()->instance(\App\Http\Controllers\Api\SmsController::class, new class () {
                public function __call($n, $a)
                {
                    return false;
                }
            });

            EmailOverrides::$seen = [];
            DB::beginTransaction();
            $ag = DB::table('agencies')->where('id', $agencyId)->first();
            $set = $ag && $ag->settings ? (json_decode((string) $ag->settings, true) ?: []) : [];
            $set['notifications_enabled'] = true;
            $upd = ['settings' => json_encode($set)];
            if (Schema::hasColumn('agencies', 'email_smtp_host')) {
                $upd['email_smtp_host'] = null;
            }
            // An agency that has switched its email off still gets to see what it would send.
            DB::table('agencies')->where('id', $agencyId)->update($upd);
            $out['returned'] = $fn(new EmailSampleData($agencyId));
        } catch (\Throwable $e) {
            $out['error'] = class_basename($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        } finally {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
            // What the sender queued instead of sending: read, never run.
            try {
                $bus = app(BusDispatcher::class);
                if ($bus instanceof \Illuminate\Support\Testing\Fakes\BusFake) {
                    foreach ([$bus->dispatched(CallQueuedClosure::class), $bus->dispatchedSync(CallQueuedClosure::class)] as $jobs) {
                        foreach ($jobs as $job) {
                            $vars = self::closureVars($job);
                            if (isset($vars['html']) && is_string($vars['html'])) {
                                $out['queued'][] = $vars;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
            }
            try {
                $q = app('queue');
                if ($q instanceof \Illuminate\Support\Testing\Fakes\QueueFake) {
                    foreach ($q->pushedJobs() as $cls => $pushed) {
                        if ($cls !== \Illuminate\Mail\SendQueuedMailable::class) {
                            continue;
                        }
                        foreach ($pushed as $p) {
                            $mailable = $p['job']->mailable ?? null;
                            if ($mailable instanceof \Illuminate\Mail\Mailable) {
                                $html = $mailable->render();
                                $out['queued'][] = ['html' => (string) $html, 'subject' => (string) ($mailable->subject ?? '')];
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
            }
            $out['built'] = EmailOverrides::$seen ?? [];
            EmailOverrides::$seen = null;

            Bus::swap($origBus);
            Queue::swap($origQueue);
            Http::swap($origHttp);
            try {
                $cache->store('array')->flush();
            } catch (\Throwable $e) {
            }
            $cache->setDefaultDriver($cacheDefault);
            app('log')->setDefaultDriver($logDefault);
            foreach ($stubbed as $cls) {
                app()->forgetInstance($cls);
            }
            $events->forget(MessageSending::class);
            $events->forget(MessageSent::class);
            foreach ($keep as $evt => $listeners) {
                foreach ($listeners as $l) {
                    $events->listen($evt, $l);
                }
            }
            config(['mail.default' => $mailDefault]);
            self::forgetMailers();
        }

        return $out;
    }

    private static function forgetMailers(): void
    {
        try {
            $mm = app('mail.manager');
            if (method_exists($mm, 'forgetMailers')) {
                $mm->forgetMailers();
            }
        } catch (\Throwable $e) {
        }
    }

    /** The variables a queued closure captured (`use (...)`), without running it. */
    private static function closureVars(CallQueuedClosure $job): array
    {
        $c = $job->closure;
        if (is_object($c) && method_exists($c, 'getClosure')) {
            $c = $c->getClosure();
        }
        if (! $c instanceof \Closure) {
            return [];
        }
        $vars = (new \ReflectionFunction($c))->getStaticVariables();
        $out = [];
        foreach ($vars as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /** Choose the one message the preview is about, and shape the answer. */
    private static function pick(string $key, array $cap): array
    {
        $r = $cap['returned'];
        $needle = is_array($r) && isset($r['contains']) ? (string) $r['contains'] : null;
        if ($needle !== null) {
            $has = fn ($h) => is_string($h) && str_contains($h, $needle);
            $cap['built'] = array_values(array_filter($cap['built'], fn ($b) => $has($b['html'])));
            $cap['messages'] = array_values(array_filter($cap['messages'], fn ($m) => $has($m['html'])));
            $cap['queued'] = array_values(array_filter($cap['queued'], fn ($q) => $has($q['html'] ?? null)));
        }
        if (is_array($r) && isset($r['html'])) {
            $sub = (string) ($r['subject'] ?? '');
            $res = EmailOverrides::resolveSubject((string) $r['html'], $sub);

            return ['ok' => true, 'how' => 'built', 'subject' => $res['subject'] ?? $sub,
                'html' => $res['html'] ?? (string) $r['html'], 'attachments' => $r['attachments'] ?? [], 'error' => null];
        }

        // The wrap() output that belongs to this email, if the run produced one.
        $mine = array_values(array_filter($cap['built'], fn ($b) => $b['key'] === $key));
        $sig = $mine ? self::sig(end($mine)['html']) : null;

        $match = null;
        foreach ($cap['messages'] as $m) {
            if ($m['html'] !== null && $sig !== null && self::sig($m['html']) === $sig) {
                $match = $m;
            }
        }
        if (! $match && $sig === null && $cap['messages']) {
            $match = $cap['messages'][0];
        }
        if ($match) {
            return ['ok' => true, 'how' => 'sent', 'subject' => $match['subject'], 'html' => (string) $match['html'],
                'attachments' => $match['attachments'], 'error' => $cap['error']];
        }

        foreach ($cap['queued'] as $q) {
            if ($sig === null || self::sig((string) $q['html']) === $sig) {
                $sub = (string) ($q['subject'] ?? $q['title'] ?? '');
                $res = EmailOverrides::resolveSubject((string) $q['html'], $sub);

                return ['ok' => true, 'how' => 'queued', 'subject' => $res['subject'] ?? $sub,
                    'html' => $res['html'] ?? (string) $q['html'], 'attachments' => [], 'error' => $cap['error']];
            }
        }

        if ($mine) {
            $html = (string) end($mine)['html'];
            $res = EmailOverrides::resolveSubject($html, '');

            return ['ok' => true, 'how' => 'built', 'subject' => $res['subject'] ?? '', 'html' => $res['html'] ?? $html,
                'attachments' => [], 'error' => $cap['error']];
        }

        return ['ok' => false, 'error' => $cap['error'] ?: ('The sender ran but produced no email for this sample ('
            . count($cap['messages']) . ' sent, ' . count($cap['queued']) . ' queued, ' . count($cap['built']) . ' built).')];
    }

    /** Compare two renders of one email, ignoring the subject marker. */
    private static function sig(string $html): string
    {
        return md5((string) preg_replace('/<!--kt-email-subject:[a-z0-9-]+:\d+-->/', '', $html));
    }

    // ── helpers for the samplers ────────────────────────────────────────────

    /** Call a method whatever its visibility. */
    private static function call($target, string $method, array $args)
    {
        $rm = new \ReflectionMethod($target, $method);
        $rm->setAccessible(true);

        return $rm->invokeArgs(is_object($target) ? $target : null, $args);
    }

    /** A console command ready to have its methods called outside Artisan. */
    private static function cmd(string $cls, array $opts = [])
    {
        $c = app($cls);
        $c->setLaravel(app());
        $in = new \Symfony\Component\Console\Input\ArrayInput($opts, $c->getDefinition());
        $c->setInput($in);
        $c->setOutput(new \Illuminate\Console\OutputStyle($in, new \Symfony\Component\Console\Output\BufferedOutput()));

        return $c;
    }

    /** The request a controller sees: the viewer's own, with sample input. */
    private static function req(array $input = []): Request
    {
        $base = request();
        $r = Request::create('/api/v1/email-preview', 'POST', $input);
        foreach (['X-Active-Agency-Id', 'Authorization', 'User-Agent', 'Accept'] as $h) {
            if ($base->headers->has($h)) {
                $r->headers->set($h, $base->headers->get($h));
            }
        }
        $r->setUserResolver($base->getUserResolver());

        return $r;
    }

    /** Merge keys into the agency's settings — inside the preview's transaction only. */
    private static function setting(int $agencyId, array $patch): void
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode(array_replace_recursive($s, $patch))]);
    }

    private static function person(string $first = 'Alex', string $last = 'Sample', string $email = self::SAMPLE_TO, int $id = 0): object
    {
        return (object) ['id' => $id, 'first_name' => $first, 'last_name' => $last, 'email' => $email, 'user_id' => $id];
    }

    // ── one sampler per email ───────────────────────────────────────────────

    /** @return array<string, callable(EmailSampleData, string): ?array> */
    private static function samplers(): array
    {
        $T = self::SAMPLE_TO;

        return [
            'password-reset' => function (EmailSampleData $fx) {
                $m = new \App\Mail\PasswordResetEmail('Alex', 'https://app.kiddietrac.com/reset-password?token=SAMPLE', '60');

                return ['subject' => $m->envelope()->subject, 'html' => $m->render()];
            },

            'closure-reminder' => function (EmailSampleData $fx, string $v) use ($T) {
                $holiday = str_ends_with($v, 'holiday');
                $parent = str_starts_with($v, 'parent');
                $d1 = now()->addDays($holiday ? 3 : 2);
                self::cmd(\App\Console\Commands\ClosureReminderCommand::class)->send(
                    $fx->agencyId, self::person(), $parent ? 'parent' : 'educator', $fx->centre()->name,
                    $holiday ? $d1->format('D j M') : $d1->format('D j M') . ' – ' . $d1->copy()->addDay()->format('D j M'),
                    $holiday ? 'Thanksgiving' : 'Professional development days', $holiday ? 3 : 2, true, [], false,
                    $holiday ? 'A day to gather with the people we love and give thanks for the harvest and the year.' : '',
                    $parent ? 'Jordan Lee' : '', $holiday ? $d1->toDateString() : '');

                return null;
            },

            'weekly-digest' => function (EmailSampleData $fx) {
                $start = Carbon::now()->startOfWeek()->subWeek();
                $end = $start->copy()->addWeek();
                $s = ['new_enrolments' => 3, 'withdrawn' => 1, 'billed' => 18450.0, 'paid' => 15210.0, 'outstanding' => 3240.0,
                    'check_events' => 42, 'observations' => 128, 'incidents' => 2,
                    'centre_movement' => [['name' => $fx->centre()->name, 'added' => 3, 'withdrew' => 1, 'net' => 2]],
                    'top_recorders' => [(object) ['first_name' => 'Jordan', 'last_name' => 'Lee', 'cnt' => 41],
                        (object) ['first_name' => 'Priya', 'last_name' => 'Shah', 'cnt' => 33]]];
                $html = self::call(self::cmd(\App\Console\Commands\WeeklyDigestCommand::class), 'renderHtml', [$fx->agency(), $s, $start, $end]);

                return ['subject' => '[' . $fx->agencyName() . '] Weekly summary · week of ' . $start->format('M j'), 'html' => $html];
            },

            'daily-digest' => function (EmailSampleData $fx) {
                $today = Carbon::now()->startOfDay();
                $s = ['checked_in' => 38, 'enrolled' => 44, 'checked_pct' => 86, 'open_balance' => 2310.5, 'open_invoices' => 9,
                    'meds_today' => 3, 'observations_yesterday' => 27, 'staff_clocked_today' => 9, 'staff_on_floor' => 7,
                    'waitlist_waiting' => 5, 'incidents_yesterday' => 1, 'serious_yesterday' => 0,
                    'centre_ratios' => [['name' => $fx->centre()->name, 'enrolled' => 44, 'capacity' => 50, 'pct' => 88]],
                    'mfa_not_enrolled' => 1, 'expiring_certs' => [(object) ['first_name' => 'Priya', 'last_name' => 'Shah',
                        'cert_type' => 'first_aid', 'expires_at' => now()->addDays(12)->toDateString()]],
                    'upcoming_invoices' => [(object) ['invoice_number' => 'INV-1042', 'due_at' => now()->addDays(3)->toDateString(), 'balance_due' => 142.5]]];
                $html = self::call(self::cmd(\App\Console\Commands\DailyDigestCommand::class), 'renderHtml', [$fx->agency(), $s, $today]);

                return ['subject' => '[' . $fx->agencyName() . '] Daily summary · ' . $today->format('D, M j'), 'html' => $html];
            },

            'drip' => function (EmailSampleData $fx) use ($T) {
                $cid = EmailSampleData::insert('marketing_campaigns', ['agency_id' => $fx->agencyId, 'created_by_user_id' => $fx->actor()->id, 'title' => 'Welcome series — day 3',
                    'subject' => 'Three things families love about us', 'body_html' => '<p>This is where the campaign\'s own words go. Each drip email is written on its campaign in Marketing.</p>',
                    'audience' => 'all_families', 'channel' => 'email', 'status' => 'draft', 'trigger_event' => 'sample_preview', 'trigger_delay_days' => 0]);
                $cmd = self::cmd(\App\Console\Commands\DripDispatchCommand::class);
                // Only the sample campaign is considered: its trigger source is this one address.
                $c = DB::table('marketing_campaigns')->where('id', $cid)->first();
                $html = \App\Services\EmailTemplate::wrap((int) $c->agency_id, $c->body_html, ['eyebrow' => 'A NOTE FROM US', 'title' => $c->subject, 'email' => 'drip']);

                return ['subject' => $c->subject, 'html' => $html];
            },

            'immunization-reminder-parent' => function (EmailSampleData $fx) use ($T) {
                $c = $fx->child();
                $rows = [['child_id' => $c->id, 'family_id' => $fx->family()->id, 'child_name' => 'Ava Sample', 'ask' => 'doses',
                    'overdue' => [['vaccine' => 'MMR', 'dose_label' => 'dose 2', 'due_date' => now()->subDays(10)->toDateString()]],
                    'due_soon' => [['vaccine' => 'DTaP-IPV', 'dose_label' => 'dose 5', 'due_date' => now()->addDays(25)->toDateString()]]]];
                $body = self::call(self::cmd(\App\Console\Commands\ImmunizationRemindersCommand::class), 'parentBody', [$rows, $fx->agencyName(), []]);
                $subject = 'Immunization record for Ava Sample';
                $html = \App\Services\EmailTemplate::wrap($fx->agencyId, $body, ['eyebrow' => 'Immunization', 'title' => $subject,
                    'preheader' => 'An update is needed on your child\'s immunization record.', 'email' => 'immunization-reminder-parent']);

                return ['subject' => $subject, 'html' => $html];
            },

            'immunization-reminders' => function (EmailSampleData $fx) use ($T) {
                self::call(self::cmd(\App\Console\Commands\ImmunizationRemindersCommand::class), 'sendOne', [$fx->agencyId, $fx->agencyName(), [$T],
                    [['name' => 'Ava Sample', 'centre_name' => $fx->centre()->name, 'room_name' => $fx->room()->name, 'reason' => 'No immunization record on file'],
                        ['name' => 'Leo Sample', 'centre_name' => $fx->centre()->name, 'room_name' => $fx->room()->name, 'reason' => 'MMR dose 2 overdue']], []]);

                return null;
            },

            'platform-invoice' => function (EmailSampleData $fx) use ($T) {
                $num = 'KT-SAMPLE-' . now()->format('ym');
                EmailSampleData::insert('platform_invoices', ['agency_id' => $fx->agencyId, 'number' => $num,
                    'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
                    'amount_cents' => 24900, 'subtotal_cents' => 24900, 'tax_cents' => 0, 'amount_paid_cents' => 0, 'currency' => 'CAD',
                    'status' => 'issued', 'issued_at' => now(), 'due_at' => now()->addDays(14)->toDateString()]);
                $c = self::cmd(\App\Console\Commands\SendPlatformInvoice::class, ['invoice' => $num, '--to' => $T]);
                $c->handle();

                return null;
            },

            'billing-reminder' => function (EmailSampleData $fx) use ($T) {
                $num = 'KT-SAMPLE-OVERDUE';
                // Only the sample is overdue for this run (inside the rolled-back transaction).
                DB::table('platform_invoices')->where('status', 'issued')->update(['status' => 'preview-hidden']);
                EmailSampleData::insert('platform_invoices', ['agency_id' => $fx->agencyId, 'number' => $num,
                    'period_start' => now()->subMonth()->startOfMonth()->toDateString(), 'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
                    'amount_cents' => 24900, 'subtotal_cents' => 24900, 'tax_cents' => 0, 'amount_paid_cents' => 0, 'currency' => 'CAD',
                    'status' => 'issued', 'issued_at' => now()->subDays(40), 'due_at' => now()->subDays(14)->toDateString()]);
                self::cmd(\App\Console\Commands\PlatformInvoiceReminders::class, ['--to' => $T, '--commit' => true, '--force' => true])->handle();

                return null;
            },

            'chat-unread' => function (EmailSampleData $fx) {
                $cmd = self::cmd(\App\Console\Commands\EmailMissedMessagesCommand::class);
                $sender = (object) ['id' => 9001, 'first_name' => 'Jordan', 'last_name' => 'Lee'];
                $msgs = [(object) ['sender_id' => 9001, 'created_at' => now()->subMinutes(40), 'body' => 'Ava had a great morning — she painted a rainbow for you! 🌈', 'attachments' => null],
                    (object) ['sender_id' => 9001, 'created_at' => now()->subMinutes(35), 'body' => '', 'attachments' => json_encode([['url' => 'x']])]];
                $senders = collect([9001 => $sender]);
                $html = self::call($cmd, 'renderEmail', [self::person(), (object) ['id' => 1], $msgs, $senders, $fx->agency()]);
                $subject = self::call($cmd, 'subjectFor', [$msgs, $senders, $fx->agency()]);

                return ['subject' => $subject, 'html' => $html];
            },

            'team-chat-unread' => function (EmailSampleData $fx) {
                $me = $fx->educator();
                $other = $fx->director();
                $tid = EmailSampleData::insert('staff_threads', ['agency_id' => $fx->agencyId, 'title' => 'Sunflowers room', 'created_by_id' => $other->id]);
                EmailSampleData::insert('staff_thread_participants', ['thread_id' => $tid, 'user_id' => $me->id, 'last_read_at' => now()->subHours(3)]);
                EmailSampleData::insert('staff_thread_participants', ['thread_id' => $tid, 'user_id' => $other->id, 'last_read_at' => now()]);
                EmailSampleData::insert('staff_messages', ['thread_id' => $tid, 'sender_id' => $other->id, 'body' => 'Could you cover the 3:30 pickup line today? Thank you!', 'created_at' => now()->subHour()]);
                // Only the sample message is unsent for this run.
                DB::table('staff_messages')->whereNull('email_notified_at')->where('thread_id', '!=', $tid)->update(['email_notified_at' => now()]);
                self::call(self::cmd(\App\Console\Commands\EmailMissedMessagesCommand::class), 'sweepTeamChat', [Carbon::now(), 5, false]);

                return null;
            },

            'manual-checkin-tip' => function (EmailSampleData $fx) use ($T) {
                self::call(self::cmd(\App\Console\Commands\ManualCheckinReminderCommand::class), 'send', [$T, 'Alex', ['Ava'], 4, $fx->agencyName(), $fx->agencyId, null, true]);

                return null;
            },

            'weekly-summary' => function (EmailSampleData $fx) use ($T) {
                $c = $fx->child();
                self::cmd(\App\Console\Commands\WeeklySummaryTestCommand::class, ['email' => $T, '--child' => (string) $c->id])->handle();

                return null;
            },

            'security-alerts' => function (EmailSampleData $fx) {
                self::call(self::cmd(\App\Console\Commands\SecurityAlerts::class), 'notify', [[
                    ['severity' => 'high', 'details' => '14 failed sign-ins for alex.sample@example.com from 203.0.113.7 in 10 minutes'],
                ]]);

                return null;
            },

            'admin-digest' => function (EmailSampleData $fx) use ($T) {
                self::cmd(\App\Console\Commands\AdminDigestCommand::class, ['--agency' => (string) $fx->agencyId, '--test' => $T, '--force' => true])->handle();

                return null;
            },

            'legal-notice' => function (EmailSampleData $fx) {
                $cmd = self::cmd(\App\Console\Commands\LegalNoticeCommand::class);
                $doc = ['anchor' => '#terms', 'effective' => now()->addDays(30)->toDateString(), 'headline' => 'We have updated our terms',
                    'summary' => 'We have made our privacy policy clearer about how photos of children are stored and who can see them.',
                    'points' => ['Photos are kept for as long as your child is enrolled, then deleted after 12 months.', 'You can download every photo of your child at any time.']];
                try {
                    $docs = \App\Support\LegalDocuments::current();
                    if (is_array($docs) && isset($docs['points'])) {
                        $doc = $docs;
                    }
                } catch (\Throwable $e) {
                }
                $html = self::call($cmd, 'body', [$doc, '2026.10', $fx->agencyId, 'Alex', false]);

                return ['subject' => 'Our legal terms have changed', 'html' => $html];
            },

            'year-end-portfolio' => function (EmailSampleData $fx) {
                // The real body, without rendering a year of observations into a PDF.
                $y = (int) now()->year;
                $html = \App\Services\EmailTemplate::wrap($fx->agencyId, '
                    <p>Happy holidays from ' . htmlspecialchars($fx->centre()->name) . '!</p>
                    <p>Attached is Ava\'s year-end portfolio for ' . $y . ' — every observation, milestone, and 214 care moment we shared this year.</p>
                    <p>Thank you for trusting us with your family. We look forward to ' . ($y + 1) . '!</p>
                ', ['eyebrow' => 'YEAR-END PORTFOLIO', 'title' => 'Ava\'s ' . $y . ' portfolio', 'subtitle' => $fx->centre()->name, 'email' => 'year-end-portfolio']);

                return ['subject' => "[$y Portfolio] Ava's year-end memories", 'html' => $html, 'attachments' => ["Ava-$y-portfolio.pdf"]];
            },

            'clock-reminder' => function (EmailSampleData $fx) use ($T) {
                $cmd = self::cmd(\App\Console\Commands\ClockReminderCommand::class);
                self::call($cmd, 'sendReminder', [$fx->educator()->id, $fx->centre()->id, $T, 'Jordan', 'You have not clocked in yet today',
                    'Your shift started at 8:00 AM and there is no clock-in recorded.', '8:00 AM – 4:00 PM']);

                return null;
            },

            'birthday' => function (EmailSampleData $fx, string $v) use ($T) {
                $when = \App\Support\BirthdayGreetings::whenPhrase(1, Carbon::tomorrow());
                [$subject, $message, $heading] = match ($v) {
                    'educator' => ["🎂 Ava's birthday is tomorrow", \App\Support\BirthdayGreetings::forEducator(2, 'Ava', 4, $when), "Ava's 4th birthday"],
                    'person' => ['🎉 Happy birthday, Jordan!', \App\Support\BirthdayGreetings::forPerson(1, 'Jordan', $when), 'Happy birthday'],
                    default => ['🎂 Ava turns 4 tomorrow', \App\Support\BirthdayGreetings::forGuardian(3, 'Ava', 4, $when), "Ava's 4th birthday"],
                };
                $p = $fx->parent();
                self::call(self::cmd(\App\Console\Commands\BirthdayEmailCommand::class), 'deliver', [$fx->agency(),
                    (object) ['id' => $p->id, 'email' => $T, 'first_name' => 'Alex', 'last_name' => 'Sample'], 'sample-' . $v, $fx->child()->id,
                    (int) now()->year, $subject, $message, $heading]);

                return null;
            },

            'checkin-reminder' => function (EmailSampleData $fx, string $v) use ($T) {
                $morning = $v !== 'evening';
                self::call(self::cmd(\App\Console\Commands\CheckinReminderCommand::class), 'email', [$fx->agencyId, $T, 'Alex Sample',
                    $morning ? '🕘 Has Ava arrived?' : '🕕 Please sign Ava out',
                    $morning ? "Ava hasn't been signed in at " . $fx->centre()->name . " today. Please sign in when you arrive — or let us know if they're not coming in."
                        : "Ava was signed in at 8:32 AM and hasn't been signed out. Please sign out so the day's attendance record is complete.",
                    $morning ? 'morning' : 'evening',
                    $morning ? ['child_name' => 'Ava', 'centre_name' => $fx->centre()->name] : ['child_name' => 'Ava', 'centre_name' => $fx->centre()->name, 'signed_in_at' => '8:32 AM']]);

                return null;
            },

            'whats-new-digest' => function (EmailSampleData $fx) use ($T) {
                self::call(self::cmd(\App\Console\Commands\WhatsNewDigestCommand::class), 'send', [$fx->agencyId, $fx->agencyName(), $T, 'Jordan Lee', ['agency_admin'],
                    [['icon' => '📝', 'title' => 'Email templates you can word yourself', 'body' => 'Every email the portal sends can now be previewed and worded in Settings → Email templates.'],
                        ['icon' => '🗓️', 'title' => 'Time-off reminders', 'body' => 'Approvers get a reminder while a request waits.']], now()->subDays(7)->toDateString()]);

                return null;
            },

            'safe-arrival' => function (EmailSampleData $fx) use ($T) {
                self::call(self::cmd(\App\Console\Commands\SafeArrivalCommand::class), 'email', [$fx->agencyId, $T, 'Alex Sample',
                    'Is Ava coming in today?', 'Ava was expected at ' . $fx->centre()->name . ' by 9:30 AM and has not been signed in, and no absence was reported.']);

                return null;
            },

            'safe-arrival-staff' => function (EmailSampleData $fx) use ($T) {
                self::call(self::cmd(\App\Console\Commands\SafeArrivalCommand::class), 'staffEmail', [$fx->agencyId, $T, 'Jordan Lee',
                    'Ava has not arrived', 'Ava was expected by 9:30 AM and has not been signed in. The family has been asked; no answer yet.']);

                return null;
            },

            'onboarding-reminder' => function (EmailSampleData $fx) use ($T) {
                self::call(self::cmd(\App\Console\Commands\OnboardingReminderCommand::class), 'send', [$T, 'Alex', $fx->agencyName(), $fx->agencyId,
                    'https://app.kiddietrac.com/set-password?token=SAMPLE', true]);

                return null;
            },

            'educator-daily-summary' => function (EmailSampleData $fx) use ($T) {
                $ed = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                    ->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
                    ->where('ra.active', 1)->where('ra.role', 'educator')->whereNull('u.deleted_at')
                    ->where(fn ($q) => $q->where('ra.agency_id', $fx->agencyId)->orWhere('c.agency_id', $fx->agencyId))
                    ->orderByDesc('u.last_seen_at')->value('u.id') ?: $fx->educator()->id;
                // The real path (no --to): it queues the send, which a preview reads and never runs.
                self::cmd(\App\Console\Commands\EducatorDailySummaryCommand::class, ['--user' => (string) $ed, '--limit' => '1'])
                    ->handle();

                return null;
            },

            'incident-reminders' => function (EmailSampleData $fx) {
                $fx->ownCentre();
                $c = $fx->child();
                EmailSampleData::insert('incidents', ['child_id' => $c->id, 'room_id' => $fx->room()->id, 'incident_type' => 'injury',
                    'severity' => 'low', 'occurred_at' => now()->subDays(2)->format('Y-m-d 10:15:00'), 'location' => 'Playground',
                    'description' => 'Tripped on the climbing frame step.', 'action_taken' => 'Ice pack applied.', 'status' => 'submitted',
                    'recorded_by_id' => $fx->educator()->id, 'created_at' => now()->subDays(2)]);
                $fx->director();
                self::cmd(\App\Console\Commands\IncidentRemindersCommand::class, ['--agency' => (string) $fx->agencyId])->handle();

                return ['contains' => 'Sunny Days (sample)'];
            },

            'scheduled-report' => function (EmailSampleData $fx) {
                $r = (object) ['id' => 0, 'agency_id' => $fx->agencyId, 'recipient_email' => self::SAMPLE_TO, 'recipient_user_id' => null,
                    'range_kind' => 'last_7d', 'report_type' => 'attendance', 'centre_id' => $fx->centre()->id, 'format' => 'csv'];
                \App\Console\Commands\SendScheduledReports::sendOne($r, true);

                return null;
            },

            'check-event' => function (EmailSampleData $fx, string $v) use ($T) {
                $in = $v !== 'out';
                self::call(app(\App\Services\CheckEventNotifier::class), 'email', [$fx->agencyId, $T, 'Alex Sample',
                    $in ? 'Ava was signed in' : 'Ava was signed out', 'Ava', $in ? 'signed in' : 'signed out', $in ? '8:32 AM' : '4:10 PM',
                    now()->format('l, j F Y'), 'Jordan Lee', $fx->centre()->name]);

                return null;
            },

            'payment-failed-office' => function (EmailSampleData $fx) use ($T) {
                $p = $fx->payment();
                DB::table('payments')->where('id', $p->id)->update(['status' => 'failed']);
                try {
                    \App\Services\PaymentNotifier::failed((int) $p->id, 'Your card was declined.');
                } catch (\Error $e) {
                    self::call(\App\Services\PaymentNotifier::class, 'send', [$fx->agencyId, $T, 'A payment did not go through — Sample family',
                        '<p>A card payment of <strong>$142.50</strong> from the Sample family did not go through. Nothing was taken.</p>',
                        ['eyebrow' => 'PAYMENT FAILED', 'title' => 'A payment did not go through']]);
                }

                return null;
            },

            'payment-receipt-pdf' => function (EmailSampleData $fx) {
                $p = $fx->payment();
                $b = \App\Services\PaymentReceipt::build((int) $p->id);

                return $b ? ['subject' => $b['subject'], 'html' => $b['html'], 'attachments' => [$b['filename']]] : null;
            },

            'password-expiry' => function (EmailSampleData $fx, string $v) {
                $u = $fx->educator();
                \App\Services\PasswordExpiryMail::send((int) $u->id, $v === 'expired' ? -2 : 5);

                return null;
            },

            'sms-consent-receipt' => function (EmailSampleData $fx) {
                // The "off" record: the "on" one also files a document and texts a phone.
                \App\Services\SmsConsentReceipt::send((int) $fx->parent()->id, false, 'app', null, $fx->agencyId);

                return null;
            },

            'new-family-notice' => function (EmailSampleData $fx) {
                $fx->director();
                \App\Services\NewFamilyNotice::forSyncedFamily((int) $fx->family()->id, $fx->agencyId, true, null);

                return null;
            },

            'invoice-copy' => function (EmailSampleData $fx) use ($T) {
                $inv = $fx->invoice();
                app(\App\Http\Controllers\Api\AccountLedgerController::class)->emailInvoice(self::req(['to' => $T, 'message' => '']), 'native', (int) $inv->id);

                return null;
            },

            'account-statement' => function (EmailSampleData $fx) use ($T) {
                $fx->invoice();
                app(\App\Http\Controllers\Api\AccountLedgerController::class)->emailStatement(self::req(['to' => $T]), (int) $fx->parent()->id);

                return null;
            },

            'provider-closed' => function (EmailSampleData $fx) {
                $fx->director();
                self::call(app(\App\Http\Controllers\Api\CentreOffboardController::class), 'notifyClosure', [$fx->centre(), now()->addDays(14)->format('j F Y'),
                    ['transferred' => [1, 2, 3], 'withdrawn' => [4], 'staff_closed' => [5], 'errors' => []], ['archive' => true]]);

                return null;
            },

            'forms-to-sign' => function (EmailSampleData $fx) {
                $u = $fx->parent();
                $u->email = self::SAMPLE_TO;
                $forms = collect([(object) ['id' => 1, 'title' => 'Medication authorization', 'description' => 'For Ava\'s inhaler', 'fillable' => 1],
                    (object) ['id' => 2, 'title' => 'Field trip consent', 'description' => 'Pumpkin patch, Friday', 'fillable' => 0]]);
                self::call(app(\App\Http\Controllers\Api\ManagedFormController::class), 'emailFormPackage', [$fx->agencyId, $u, $forms, 'Please sign these by Friday. Thank you!']);

                return null;
            },

            'form-correction' => function (EmailSampleData $fx) {
                self::call(app(\App\Http\Controllers\Api\ManagedFormController::class), 'mailCorrectionRequest', [$fx->agencyId, self::SAMPLE_TO,
                    'Alex Sample', 'Medication authorization', 'The dosage box is empty — please add the dose and sign again.', 'Sam Rivera', $fx->agencyName()]);

                return null;
            },

            'form-completed' => function (EmailSampleData $fx) {
                $form = (object) ['id' => 0, 'agency_id' => $fx->agencyId, 'title' => 'Medication authorization', 'description' => 'For Ava\'s inhaler',
                    'notify_email' => self::SAMPLE_TO];
                self::call(app(\App\Http\Controllers\Api\ManagedFormController::class), 'emailCompletedForm', [$form, 'Alex Sample', null, EmailSampleData::PARENT_EMAIL, (int) $fx->parent()->id, 0]);

                return null;
            },

            'form-countersigned' => function (EmailSampleData $fx) {
                $row = (object) ['id' => 0, 'agency_id' => $fx->agencyId, 'user_id' => $fx->parent()->id, 'title' => 'Medication authorization',
                    'description' => 'For Ava\'s inhaler', 'notify_email' => '', 'signer_email' => self::SAMPLE_TO, 'signer_name' => 'Alex Sample',
                    'filled_file_url' => null, 'signed_at' => now()->subDay()];
                self::call(app(\App\Http\Controllers\Api\ManagedFormController::class), 'returnCountersigned', [$row, null, 'Sam Rivera', 'All in order — thank you.', $fx->agencyName()]);

                return null;
            },

            'family-left-educator' => function (EmailSampleData $fx) {
                $fx->child();
                self::call(app(\App\Http\Controllers\Api\AdminController::class), 'notifyProviderOfDeparture', [$fx->family(), [$fx->room()->id], self::req(),
                    now()->addDays(10)->toDateString(), null]);

                return null;
            },

            'departure-oversight' => function (EmailSampleData $fx) {
                $fx->child();
                self::call(app(\App\Http\Controllers\Api\AdminController::class), 'notifyAdminsOfDeparture', [$fx->family(), self::req(),
                    now()->addDays(10)->toDateString(), 'Moving out of the area', ['children_withdrawn' => 1, 'accounts_closed' => 1],
                    ['total' => 142.5, 'count' => 1, 'invoices' => []], null]);

                return null;
            },

            'family-departure' => function (EmailSampleData $fx) {
                $fx->child();
                self::call(app(\App\Http\Controllers\Api\AdminController::class), 'notifyFamilyDeparture', [$fx->family(), [(int) $fx->parent()->id], self::req(),
                    ['total' => 142.5, 'count' => 1, 'invoices' => [['number' => 'INV-1042', 'total' => 142.5, 'balance_due' => 142.5,
                        'due_at' => now()->addDays(7)->toDateString()]]],
                    null, now()->addDays(10)->toDateString()]);

                return null;
            },

            'portal-access' => function (EmailSampleData $fx, string $v) {
                $fx->child();
                self::call(app(\App\Http\Controllers\Api\AdminController::class), 'notifyFamilyAccess', [$fx->family(), [(int) $fx->parent()->id], self::req(), $v === 'restored']);

                return null;
            },

            'immunization-record-filed' => function (EmailSampleData $fx) {
                $c = $fx->child();
                $fx->director();
                self::call(app(\App\Http\Controllers\Api\ParentImmunizationRecordController::class), 'emailOffice', [(int) $c->id, 'Ava Sample',
                    $fx->parent(), 0, 'Immunization record — Ava Sample', ['MMR — dose 2 — ' . now()->subDays(3)->format('j M Y'),
                        'Varicella — dose 2 — ' . now()->subDays(3)->format('j M Y')], ['Hep B — date unreadable'], 'Uploaded from the clinic printout.']);

                return null;
            },

            'account-deletion' => function (EmailSampleData $fx) {
                $fx->director();
                self::call(app(\App\Http\Controllers\Api\AccountController::class), 'notifyAdmins', [$fx->parent(), $fx->agencyId, 'We have moved and no longer need the account.']);

                return null;
            },

            'sms-consent-invite' => function (EmailSampleData $fx) {
                $p = $fx->parent();
                self::call(app(\App\Http\Controllers\Api\SmsConsentInviteController::class), 'email', [$fx->agencyId,
                    (object) ['id' => $p->id, 'first_name' => 'Alex', 'last_name' => 'Sample', 'email' => self::SAMPLE_TO]]);

                return null;
            },

            'withdrawal-decision' => function (EmailSampleData $fx) {
                self::call(app(\App\Http\Controllers\Api\WithdrawalController::class), 'mailUser', [$fx->agencyId, self::SAMPLE_TO, 'Alex Sample',
                    'Your withdrawal request was approved', '<p>Hello Alex,</p><p>Your request to withdraw Ava from ' . e($fx->centre()->name) . ' has been approved. Her last day will be ' . now()->addDays(14)->format('l j F') . '.</p>']);

                return null;
            },

            'invoice-voided-staff' => function (EmailSampleData $fx) {
                $inv = $fx->invoice();
                $fx->director();
                $letters = self::call(app(\App\Http\Controllers\Api\InvoiceController::class), 'voidLetters', [$inv, 'Duplicate of INV-1041', 'Sam Rivera']);

                return is_array($letters) && is_string($letters['staff'] ?? null)
                    ? ['subject' => (string) ($letters['subject_staff'] ?? ''), 'html' => $letters['staff']] : null;
            },

            'incident-review' => function (EmailSampleData $fx) {
                return self::incident($fx, true);
            },

            'incident-report-share' => function (EmailSampleData $fx) {
                return self::incident($fx, false);
            },

            'absence-reported' => function (EmailSampleData $fx) {
                $fx->parent();
                self::call(app(\App\Http\Controllers\Api\AbsenceController::class), 'tellTheFamily', [$fx->childWithPlace(), now()->addDay()->toDateString(),
                    'sick', 'A bit of a cold — keeping her home.', 'Alex Sample', 0, AgencyTime::tz($fx->agencyId)]);

                return null;
            },

            'absence-reported-office' => function (EmailSampleData $fx) {
                $fx->educator();
                $fx->director();
                self::call(app(\App\Http\Controllers\Api\AbsenceController::class), 'tellTheCentre', [$fx->childWithPlace(), now()->addDay()->toDateString(),
                    'sick', 'A bit of a cold — keeping her home.', 'Alex Sample', AgencyTime::tz($fx->agencyId)]);

                return null;
            },

            'payslip' => function (EmailSampleData $fx) {
                $u = $fx->educator();
                $id = EmailSampleData::insert('payroll_documents', ['agency_id' => $fx->agencyId, 'centre_id' => $fx->centre()->id, 'user_id' => $u->id,
                    'kind' => 'payslip', 'payee_name' => 'Jordan Lee', 'payee_email' => self::SAMPLE_TO, 'reference' => 'PS-1042',
                    'period_start' => now()->subDays(14)->toDateString(), 'period_end' => now()->subDay()->toDateString(), 'units' => 72,
                    'unit_label' => 'hours', 'rate' => 22.5, 'gross' => 1620.0, 'net' => 1288.4, 'currency' => 'CAD', 'status' => 'issued',
                    'external_key' => 'preview-' . bin2hex(random_bytes(6)), 'issued_at' => now()]);
                app(\App\Http\Controllers\Api\PayrollDocumentController::class)->email(self::req(['to' => self::SAMPLE_TO, 'email' => self::SAMPLE_TO]), $id);

                return null;
            },

            'website-support' => function (EmailSampleData $fx) {
                return self::websiteSupport(false);
            },

            'website-support-ack' => function (EmailSampleData $fx) {
                return self::websiteSupport(true);
            },

            'website-voicemail' => function (EmailSampleData $fx) {
                // A token that exists only inside this rolled-back run.
                $tok = 'preview-' . bin2hex(random_bytes(6));
                DB::table('platform_settings')->updateOrInsert(['key' => 'voice.voicemail_token'], ['value' => $tok, 'updated_at' => now()]);
                app(\App\Http\Controllers\Api\WebsiteSupportController::class)->voicemail(self::req(['RecordingSid' => 'RE-SAMPLE-' . bin2hex(random_bytes(4)),
                    'RecordingUrl' => 'https://recordings.example.invalid/sample', 'From' => '+14165550123', 'RecordingDuration' => 42]), $tok);

                return null;
            },

            'support-ticket' => function (EmailSampleData $fx) {
                $fx->director();
                app(\App\Http\Controllers\Api\SupportTicketController::class)->create(self::req(['category' => 'enrollment', 'priority' => 'normal',
                    'subject' => 'How do I add a second guardian?', 'body' => 'A parent asked to add her partner so he can pick up. Where is that?']));

                return null;
            },

            'support-ticket-reply' => function (EmailSampleData $fx) {
                $p = $fx->parent();
                $t = (object) ['id' => 1042, 'agency_id' => $fx->agencyId, 'subject' => 'How do I add a second guardian?', 'raised_by_user_id' => $p->id];
                self::call(app(\App\Http\Controllers\Api\SupportTicketController::class), 'sendReplyNotice', [$t, $fx->director(),
                    'Open the family, then Guardians → Add guardian. He will get his own invite.', true, (int) $p->id]);

                return null;
            },

            'support-ticket-resolved' => function (EmailSampleData $fx) {
                $p = $fx->parent();
                $t = (object) ['id' => 1042, 'agency_id' => $fx->agencyId, 'subject' => 'How do I add a second guardian?', 'raised_by_user_id' => $p->id,
                    'body' => 'A parent asked to add her partner.', 'created_at' => now()->subDays(2), 'resolution' => 'Explained the Guardians tab.'];
                self::call(app(\App\Http\Controllers\Api\SupportTicketController::class), 'notifyReporterResolved', [$t, 'resolved', $fx->director()]);

                return null;
            },

            'platform-welcome' => function (EmailSampleData $fx) {
                self::call(app(\App\Http\Controllers\Api\PlatformController::class), 'sendAdminInvite', [[
                    'name' => 'Sunny Days Childcare', 'admin_first_name' => 'Alex', 'admin_last_name' => 'Sample', 'admin_email' => self::SAMPLE_TO,
                ], 'https://app.kiddietrac.com/set-password?token=SAMPLE']);

                return null;
            },

            'biometric-alert' => function (EmailSampleData $fx) {
                \App\Http\Controllers\Api\BiometricController::alert($fx->educator(), 'iPhone 15 (Face ID)', '203.0.113.7', Carbon::now(), false, self::SAMPLE_TO);

                return null;
            },

            'change-of-provider' => function (EmailSampleData $fx) {
                app(\App\Http\Controllers\Api\ChildController::class)->mailTransfer($fx->agencyId, [self::SAMPLE_TO], 'Ava', $fx->centre()->name,
                    'Maple Grove Home Childcare', now()->addDays(7)->format('l j F'), 'A place closer to home opened up.');

                return null;
            },

            'walk-notice' => function (EmailSampleData $fx, string $v) {
                $back = $v === 'back';
                self::call(app(\App\Http\Controllers\Api\WalkController::class), 'mailWalk', [$fx->agencyId, [['to' => self::SAMPLE_TO, 'child' => 'Ava']], $back,
                    'High Park', 'Jordan Lee', now()->format('g:i A'), $back ? 2.4 : null]);

                return null;
            },

            'attendance-days-updated' => function (EmailSampleData $fx) {
                $c = $fx->child();
                $fx->director();
                self::call(app(\App\Http\Controllers\Api\AttendancePatternController::class), 'notifyPatternChange', [self::req(), (int) $c->id,
                    ['mon' => true, 'tue' => true, 'wed' => false, 'thu' => true, 'fri' => true, 'effective_from' => now()->addDays(7)->toDateString(),
                        'notes' => 'Wednesdays with grandma from next week.']]);

                return null;
            },

            'invoice' => function (EmailSampleData $fx) {
                $id = EmailSampleData::insert('payee_invoices', ['agency_id' => $fx->agencyId, 'centre_id' => $fx->centre()->id, 'kind' => 'subsidy',
                    'payee_name' => 'Regional Childcare Subsidy Office', 'reference' => 'PI-1042', 'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
                    'period_end' => now()->subMonth()->endOfMonth()->toDateString(), 'basis' => 'fixed', 'amount' => 1250.0, 'base_amount' => 1250.0,
                    'subtotal' => 1250.0, 'tax_amount' => 0, 'status' => 'draft']);
                app(\App\Http\Controllers\Api\PayeeInvoiceController::class)->send(self::req(['email' => self::SAMPLE_TO]), $id);

                return null;
            },

            'home-visit-report' => function (EmailSampleData $fx) {
                $fx->director();
                self::call(app(\App\Http\Controllers\Api\HomeVisitReportController::class), 'notifyReviewers', [$fx->agencyId, 0,
                    ['centre_id' => $fx->centre()->id, 'visit_date' => now()->toDateString(), 'family_name' => 'Sample', 'child_name' => 'Ava Sample',
                        'visit_type' => 'routine', 'summary' => 'Settled well; reading corner set up as discussed.'], $fx->educator()]);

                return null;
            },

            'timeoff-request' => function (EmailSampleData $fx) {
                self::call(app(\App\Http\Controllers\Api\TimeOffController::class), 'mailApprover', [$fx->agencyId,
                    (object) ['id' => $fx->director()->id, 'email' => self::SAMPLE_TO, 'first_name' => 'Sam', 'last_name' => 'Rivera'],
                    'Jordan Lee', 'vacation', 'Mon 19 Oct – Fri 23 Oct', 'Family wedding', null]);

                return null;
            },

            'timeoff-decision' => function (EmailSampleData $fx, string $v) {
                $row = self::timeoffRow($fx);
                self::call(app(\App\Http\Controllers\Api\TimeOffController::class), 'mailDecision', [$row, $v === 'declined' ? 'declined' : 'approved',
                    'Mon 19 Oct – Fri 23 Oct', $v === 'declined' ? 'We are short that week — could you move it by a week?' : null, $fx->director()]);

                return null;
            },

            'rota-change' => function (EmailSampleData $fx) {
                return self::timeoffApproval($fx);
            },

            'timeoff-approved-office' => function (EmailSampleData $fx) {
                return self::timeoffApproval($fx);
            },

            'timeoff-closure-parents' => function (EmailSampleData $fx) {
                return self::timeoffApproval($fx, true);
            },

            'timeoff-withdrawn' => function (EmailSampleData $fx) {
                $row = self::timeoffRow($fx);
                $fx->director();
                self::call(app(\App\Http\Controllers\Api\TimeOffController::class), 'announceWithdrawal', [$row, $fx->educator(), null, false]);

                return null;
            },

            'staff-offboarded' => function (EmailSampleData $fx) {
                $fx->director();
                self::call(app(\App\Http\Controllers\Api\StaffOffboardController::class), 'notifyOffice', [$fx->educator(), $fx->agencyId,
                    now()->addDays(7)->toDateString(), [['room_id' => $fx->room()->id, 'name' => $fx->room()->name, 'centre_name' => $fx->centre()->name,
                        'other_educators' => 0]], ['reassigned' => 1, 'shifts_cancelled' => 6, 'punches_closed' => 0, 'tasks_moved' => 2, 'errors' => []],
                    ['hours' => 12.5]]);

                return null;
            },

            'staff-offboard' => function (EmailSampleData $fx) {
                $u = $fx->educator();
                $u->email = self::SAMPLE_TO;
                self::call(app(\App\Http\Controllers\Api\StaffOffboardController::class), 'sendExitNotice', [$u, $fx->agencyId, now()->addDays(7)->toDateString(), ['hours' => 12.5]]);

                return null;
            },

            'agreement-declined' => function (EmailSampleData $fx) {
                $fx->director();
                self::call(app(\App\Http\Controllers\Api\AgreementController::class), 'emailDeclineAlert', [$fx->parent(), $fx->agencyId,
                    now()->format('l j F Y, g:i A'), 'I would like to discuss the photo clause first.']);

                return null;
            },

            'attendance-correction' => function (EmailSampleData $fx) {
                $c = $fx->child();
                $fx->director();
                self::call(app(\App\Http\Controllers\Api\AttendanceCorrectionController::class), 'notifyOversight', [self::req(), (int) $c->id, 'corrected',
                    ['date' => now()->subDay()->toDateString(), 'before' => '8:45 AM – 4:10 PM', 'after' => '8:15 AM – 4:10 PM', 'reason' => 'Parent signed in at the door; tablet was offline.']]);

                return null;
            },

            'timeoff-reminders' => function (EmailSampleData $fx) {
                $row = self::timeoffRow($fx);
                $row->staff_name = 'Jordan Lee';
                $approver = (object) ['user_id' => $fx->director()->id, 'id' => $fx->director()->id, 'email' => self::SAMPLE_TO, 'first_name' => 'Sam', 'last_name' => 'Rivera', 'name' => 'Sam Rivera'];
                [$subject, $html] = \App\Support\TimeOffReminders::digest($fx->agencyId, $approver, [$row], false);

                return ['subject' => (string) $subject, 'html' => (string) $html];
            },

            'training-assignment' => function (EmailSampleData $fx) {
                \App\Support\TrainingMail::send($fx->agencyId, (int) $fx->educator()->id, 'assigned',
                    [['title' => 'Safe sleep for infants', 'due_on' => now()->addDays(14)->toDateString(), 'minutes' => 12]], 'Please finish before your next shift.', 'Sam Rivera');

                return null;
            },

            'sign-in-alert' => function (EmailSampleData $fx) {
                $html = \App\Support\SignInAlert::renderEmail($fx->agencyId, $fx->parent(), 'Chrome on Windows', 'Toronto, Ontario', '203.0.113.7', 'signed in with a password');

                return ['subject' => 'New sign-in to your KiddieTrac account', 'html' => $html];
            },

            'rsvp-emails' => function (EmailSampleData $fx) {
                $ev = (object) ['id' => 0, 'agency_id' => $fx->agencyId, 'template_id' => null, 'preset_key' => null, 'title' => 'Fall family picnic',
                    'description' => 'Bring a blanket — we will bring the apple cider.', 'email_subject' => 'You\'re invited: {{event}}',
                    'email_body' => "Hi {{name}},\n\nJoin us for our fall family picnic! Bring a blanket — we will bring the apple cider.",
                    'starts_at' => now()->addDays(10)->setTime(11, 0)->toDateTimeString(), 'ends_at' => now()->addDays(10)->setTime(14, 0)->toDateTimeString(),
                    'location' => 'High Park, Toronto', 'image_url' => null, 'fields' => null, 'confirmation_message' => null, 'notify_email' => null,
                    'sender_id' => null, 'public_token' => 'SAMPLE', 'status' => 'open', 'capacity' => null, 'created_by_id' => null,
                    'branding' => 'agency', 'show_map' => 0, 'map_pin' => null, 'attend_tips' => null];
                $inv = (object) ['id' => 0, 'name' => 'Alex Sample', 'email' => self::SAMPLE_TO, 'token' => 'SAMPLE'];
                [$subject, $html] = \App\Support\RsvpMail::invite($ev, $inv);

                return ['subject' => $subject, 'html' => $html];
            },

            'attendance-reminder' => function (EmailSampleData $fx) {
                $c = $fx->child();
                $ed = $fx->educator();
                self::setting($fx->agencyId, ['attendance_reminders' => true]);
                foreach (['nap', 'snack'] as $t) {
                    EmailSampleData::insert('daily_care_logs', ['child_id' => $c->id, 'recorded_by_id' => $ed->id, 'log_type' => $t,
                        'occurred_at' => now()->subMinutes(20), 'created_at' => now()]);
                }
                \App\Support\AttendanceReminder::maybeSend((int) $ed->id, [(int) $c->id], null, 0);

                return null;
            },

            'form-received' => function (EmailSampleData $fx) {
                $s = (object) ['title' => 'Medication authorization', 'description' => 'For Ava\'s inhaler'];
                $status = ['done' => [['title' => 'Medication authorization', 'at' => now()->toDateTimeString()]], 'open' => ['Field trip consent']];
                self::call(\App\Support\FormSubmissionNotice::class, 'toSigner', [self::person(), 'Alex Sample', $fx->agencyId, $fx->agencyName(), 'Sample', $s,
                    now()->format('l j F Y, g:i A'), $status]);

                return null;
            },

            'form-submitted-office' => function (EmailSampleData $fx) {
                $s = (object) ['title' => 'Medication authorization', 'description' => 'For Ava\'s inhaler', 'notify_email' => self::SAMPLE_TO];
                $status = ['done' => [['title' => 'Medication authorization', 'at' => now()->toDateTimeString()]], 'open' => ['Field trip consent']];
                $fx->director();
                self::call(\App\Support\FormSubmissionNotice::class, 'toOffice', [$fx->agencyId, $fx->agencyName(), 'Alex Sample', self::person(), 'Sample', $s,
                    now()->format('l j F Y, g:i A'), now()->format('j M'), $status]);

                return null;
            },

            'files-requested' => function (EmailSampleData $fx, string $v) {
                $id = self::fileRequest($fx);
                \App\Support\FileRequestNotice::ask($id, $v === 'reminder');

                return null;
            },

            'files-received-office' => function (EmailSampleData $fx) {
                $id = self::fileRequest($fx, true);
                \App\Support\FileRequestNotice::completed($id);

                return null;
            },

            'files-received-family' => function (EmailSampleData $fx) {
                $id = self::fileRequest($fx, true);
                \App\Support\FileRequestNotice::completed($id);

                return null;
            },

            'late-pickup-alert' => function (EmailSampleData $fx, string $v) {
                $c = $fx->child();
                $fx->director();
                $ev = (object) ['id' => 0, 'centre_id' => $fx->centre()->id, 'child_id' => $c->id, 'kind' => 'pickup', 'pickup_at' => now()->setTime(18, 22),
                    'occurred_on' => now()->toDateString(), 'close_time' => '18:00:00', 'minutes' => 22, 'note' => 'Traffic on the 401, called ahead.'];
                $charged = $v === 'charged';
                self::call(app(\App\Support\LatePickups::class), 'send', [$ev, $fx->educator(), $charged, $charged ? 25.0 : 0.0, $charged ? $fx->invoice() : null]);

                return null;
            },

            'donation-receipt' => function (EmailSampleData $fx) {
                $did = EmailSampleData::insert('donations', ['agency_id' => $fx->agencyId, 'donor_first_name' => 'Alex', 'donor_last_name' => 'Sample',
                    'donor_email' => self::SAMPLE_TO, 'amount' => 100.0, 'advantage_amount' => 0, 'currency' => 'CAD', 'method' => 'etransfer',
                    'status' => 'received', 'received_on' => now()->subDays(2)->toDateString(), 'source' => 'manual']);
                $id = EmailSampleData::insert('donation_receipts', ['agency_id' => $fx->agencyId, 'donation_id' => $did, 'serial' => 'DR-1042', 'kind' => 'official',
                    'charity' => json_encode(['name' => $fx->agencyName(), 'legal_name' => $fx->agencyName(), 'charity_number' => '123456789RR0001',
                        'receipt_address' => '1 Sample Street, Toronto, ON', 'receipt_location' => 'Toronto, Ontario', 'signatory_name' => 'Sam Rivera',
                        'signatory_title' => 'Director', 'signature' => '']), 'issued_at_location' => 'Toronto, Ontario',
                    'donor_address' => '1 Sample Street, Toronto, ON',
                    'donor_name' => 'Alex Sample', 'donor_email' => self::SAMPLE_TO, 'amount' => 100.0, 'advantage_amount' => 0, 'eligible_amount' => 100.0,
                    'currency' => 'CAD', 'status' => 'issued', 'issued_on' => now()->toDateString(), 'received_on' => now()->subDays(2)->toDateString()]);
                app(\App\Http\Controllers\Api\DonationController::class)->emailReceipt(self::req(), $id);

                return null;
            },

            'donation-pledge' => function (EmailSampleData $fx) {
                $slug = 'sample-' . bin2hex(random_bytes(3));
                self::setting($fx->agencyId, ['donations' => ['enabled' => true]]);
                EmailSampleData::insert('donation_campaigns', ['agency_id' => $fx->agencyId, 'slug' => $slug, 'title' => 'New playground fund',
                    'description' => 'Help us build a new playground.', 'goal_amount' => 10000, 'status' => 'active', 'created_by_id' => $fx->actor()->id]);
                app(\App\Http\Controllers\Api\DonationController::class)->publicPledge(self::req(['first_name' => 'Alex', 'last_name' => 'Sample',
                    'email' => self::SAMPLE_TO, 'amount' => 50, 'method' => 'etransfer']), $slug);

                return null;
            },

            'waitlist' => function (EmailSampleData $fx) {
                $c = $fx->child();
                DB::table('children')->where('id', $c->id)->update(['enrollment_status' => 'waitlist']);
                app(\App\Http\Controllers\Api\WaitlistController::class)->remind(self::req(['note' => 'A space may open in January — we will be in touch.']), (int) $c->id);

                return null;
            },

            'website-booking-confirmation' => function (EmailSampleData $fx) {
                $b = (object) ['id' => 0, 'name' => 'Alex Sample', 'email' => self::SAMPLE_TO, 'phone' => '+1 416 555 0123', 'meet_via' => 'video',
                    'duration_min' => 30, 'starts_at' => now()->addDays(3)->setTime(15, 0)->utc(), 'company' => 'Sunny Days Childcare'];
                $b->ends_at = $b->starts_at->copy()->addMinutes(30);
                self::call(app(\App\Http\Controllers\Api\MarketingBookingController::class), 'mailVisitor', [$b, 'Product demo']);

                return null;
            },

            'website-booking-sales' => function (EmailSampleData $fx) {
                $b = (object) ['id' => 0, 'name' => 'Alex Sample', 'email' => self::SAMPLE_TO, 'phone' => '+1 416 555 0123', 'meet_via' => 'video',
                    'duration_min' => 30, 'starts_at' => now()->addDays(3)->setTime(15, 0)->utc(), 'company' => 'Sunny Days Childcare', 'notes' => 'Two centres, 80 children.',
                    'agency' => 'Sunny Days Childcare', 'children' => 80, 'visitor_tz' => 'America/Toronto',
                    'local_date' => now()->addDays(3)->toDateString()];
                $b->ends_at = $b->starts_at->copy()->addMinutes(30);
                self::call(app(\App\Http\Controllers\Api\MarketingBookingController::class), 'mailSales', [$b, 'Product demo']);

                return null;
            },

            'rsvp-sender-test' => function (EmailSampleData $fx) {
                // .invalid never resolves, and every send in a preview is cancelled before a transport anyway.
                $id = EmailSampleData::insert('rsvp_senders', ['agency_id' => $fx->agencyId, 'type' => 'smtp', 'label' => 'Events mailbox',
                    'from_name' => $fx->agencyName(), 'from_address' => 'events@example.com', 'smtp_host' => 'smtp.example.invalid', 'smtp_port' => 587,
                    'smtp_encryption' => 'tls', 'smtp_user' => 'events@example.com', 'created_by_id' => $fx->actor()->id]);
                app(\App\Http\Controllers\Api\RsvpController::class)->testSender(self::req(['to' => self::SAMPLE_TO]), $id);

                return null;
            },

            'inspection-form' => function (EmailSampleData $fx) {
                $fx->director();
                $type = \App\Support\HccFormSchemas::keys()[1] ?? 'quarterly_checklist';
                $data = ['centre_id' => $fx->centre()->id, 'form_type' => $type, 'visit_date' => now()->toDateString(), 'visit_time_in' => '10:00',
                    'visit_time_out' => '11:15', 'provider_name' => 'Maple Grove Home Childcare', 'quarter' => 4];
                $id = EmailSampleData::insert('hcc_inspection_forms', ['agency_id' => $fx->agencyId, 'centre_id' => $fx->centre()->id, 'form_type' => $type,
                    'home_visitor_id' => $fx->educator()->id, 'visit_date' => now()->toDateString(), 'visit_time_in' => '10:00', 'visit_time_out' => '11:15',
                    'quarter' => 4, 'provider_name' => 'Maple Grove Home Childcare', 'answers' => json_encode([]), 'status' => 'submitted']);
                self::call(app(\App\Http\Controllers\Api\HccFormController::class), 'notifyReviewers', [$fx->agencyId, $id, $data, 'Jordan Lee']);

                return null;
            },

            'agreement-signed' => function (EmailSampleData $fx) {
                $path = storage_path('app/email-preview-agreement.pdf');
                app(\App\Http\Controllers\Api\AgreementController::class)->emailSignedCopy(self::SAMPLE_TO, 'Alex Sample', 'Alex Sample',
                    now()->format('l j F Y, g:i A'), $path);

                return null;
            },
        ];
    }

    // ── shared sample builders ──────────────────────────────────────────────

    private static function incident(EmailSampleData $fx, bool $staff): ?array
    {
        $c = $fx->child();
        $fx->director();
        $id = EmailSampleData::insert('incidents', ['child_id' => $c->id, 'room_id' => $fx->room()->id, 'incident_type' => 'injury', 'severity' => 'low',
            'occurred_at' => now()->format('Y-m-d 10:15:00'), 'location' => 'Playground', 'description' => 'Ava tripped on the bottom step of the climbing frame and grazed her knee.',
            'action_taken' => 'Cleaned the graze and applied a plaster and an ice pack. She was back playing within ten minutes.', 'first_aid_administered' => 1,
            'recorded_by_id' => $fx->educator()->id, 'status' => 'submitted', 'submitted_at' => now()]);
        $ctl = (new \ReflectionClass(\App\Http\Controllers\Api\IncidentController::class))->newInstanceWithoutConstructor();
        $ctl->emailReport(self::req(['to' => [$staff ? 'director' : 'parent']]), $id);

        return null;
    }

    private static function websiteSupport(bool $ack): ?array
    {
        app(\App\Http\Controllers\Api\WebsiteSupportController::class)->submitTicket(self::req(['name' => 'Alex Sample', 'email' => self::SAMPLE_TO,
            'organization' => 'Sunny Days Childcare', 'subject' => 'Cannot see the billing tab', 'description' => 'Since this morning the billing tab is missing for our director.',
            'priority' => 'normal', 'category' => 'technical', 'phone' => '+1 416 555 0123']));

        return null;
    }

    private static function timeoffRow(EmailSampleData $fx): object
    {
        $u = $fx->educator();
        $id = EmailSampleData::insert('time_off_requests', ['agency_id' => $fx->agencyId, 'user_id' => $u->id, 'centre_id' => $fx->centre()->id,
            'request_type' => 'vacation', 'start_at' => now()->addDays(13)->setTime(0, 0), 'end_at' => now()->addDays(17)->setTime(23, 59), 'all_day' => 1,
            'reason' => 'Family wedding', 'status' => 'pending']);

        return DB::table('time_off_requests')->where('id', $id)->first();
    }

    private static function timeoffApproval(EmailSampleData $fx, bool $closed = false): ?array
    {
        $row = self::timeoffRow($fx);
        $fx->director();
        $fx->child();
        $other = (object) ['id' => 0];
        self::call(app(\App\Http\Controllers\Api\TimeOffController::class), 'announceApproval', [$row, $closed ? (int) $fx->centre()->id : null, $fx->actor()]);

        return null;
    }

    private static function fileRequest(EmailSampleData $fx, bool $complete = false): int
    {
        $p = $fx->parent();
        $id = EmailSampleData::insert('file_requests', ['agency_id' => $fx->agencyId, 'requested_by_id' => $fx->actor()->id, 'requested_by_name' => 'Sam Rivera',
            'user_id' => $p->id, 'email' => self::SAMPLE_TO, 'priority' => 'normal', 'due_on' => now()->addDays(7)->toDateString(),
            'note' => 'Please send these before Ava\'s start date.', 'status' => $complete ? 'complete' : 'open', 'completed_at' => $complete ? now() : null]);
        if (Schema::hasTable('file_request_items')) {
            foreach (['Immunization record', 'Birth certificate'] as $i => $label) {
                EmailSampleData::insert('file_request_items', ['file_request_id' => $id, 'description' => $label, 'kind' => 'document',
                    'quantity' => 1, 'display_order' => $i]);
            }
        }

        return $id;
    }
}
