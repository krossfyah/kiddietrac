<?php

namespace App\Support;

use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * AI USAGE, PER AGENCY (2026-10-20). Anthony: "add logic if SMS, TEXT or AI exceeds
 * threshold $ per agency to notify superadmin".
 *
 * Texts and calls were already logged per agency (sms_messages, voice_calls). AI was not
 * logged anywhere: twelve places call Anthropic directly. Rather than touch each one,
 * this listens to every reply the HTTP client receives, and for an Anthropic Messages
 * reply records the model and the token counts Anthropic itself reports (ai_usage).
 *
 * The agency is, in order: one a background job set for the work it is doing
 * (UsageMeter::$agencyId, e.g. the nightly digests per child), else the agency the
 * signed-in person is acting in (AuditScope's rules), else none — shown in the weekly
 * summary as "No agency" so it is never silently lost.
 *
 * Recording never breaks the caller: any failure here is swallowed.
 *
 * THE PERSON (2026-11-26, AI usage "By user"). A web call is the signed-in person's. A QUEUED job is the person who
 * started it: every job's payload carries who dispatched it (kt_meter: their id and agency, stamped by
 * Queue::createPayloadUsing), and while the worker runs that job the meter uses them and names the feature after the
 * job ("job:DrawPlaySparkPicture") instead of "cli:queue:work". Each job's context is put back when it ends, so one
 * job's agency or person never leaks into the next one the long-running worker picks up. Scheduled commands
 * (nightly digests) have no person: those calls show as "System".
 */
class UsageMeter
{
    /** Set by background work that knows which agency it is spending for. */
    public static ?int $agencyId = null;

    /** Set by background work that knows which person it is working for (a queued job they started). */
    public static ?int $userId = null;

    /** Set by background work to name what is asking (e.g. "job:DrawPlaySparkPicture"). */
    public static ?string $feature = null;

    /** Saved context while a queued job runs, put back when it ends. */
    private static array $stack = [];

    public static function forUser(?int $userId): void
    {
        self::$userId = $userId ?: null;
    }

    public static function forAgency(?int $agencyId): void
    {
        self::$agencyId = $agencyId ?: null;
    }

    /** The agency a child belongs to (children have no agency_id; their centre does). */
    public static function forChild($child): void
    {
        try {
            $centre = (int) ($child->centre_id ?? 0);
            self::$agencyId = $centre ? ((int) DB::table('centres')->where('id', $centre)->value('agency_id') ?: null) : null;
        } catch (\Throwable $e) {
            self::$agencyId = null;
        }
    }

    public static function register(): void
    {
        Event::listen(ResponseReceived::class, function (ResponseReceived $e) {
            try { self::record($e); } catch (\Throwable $ex) {}
        });

        // Who started a queued job travels with it.
        Queue::createPayloadUsing(function () {
            try {
                $user = null; $agency = self::$agencyId;
                if (! app()->runningInConsole()) {
                    $user = optional(request()->user())->id;
                    if ($agency === null && $user) $agency = AuditScope::resolve((int) $user, request());
                } else {
                    $user = self::$userId;
                }
                return ['kt_meter' => ['u' => $user ? (int) $user : null, 'a' => $agency ? (int) $agency : null]];
            } catch (\Throwable $e) {
                return [];
            }
        });
        Event::listen(JobProcessing::class, function (JobProcessing $e) {
            self::$stack[] = [self::$agencyId, self::$userId, self::$feature];
            try {
                $p = $e->job->payload();
                $m = is_array($p['kt_meter'] ?? null) ? $p['kt_meter'] : [];
                self::$userId = isset($m['u']) ? ((int) $m['u'] ?: null) : null;
                self::$agencyId = isset($m['a']) ? ((int) $m['a'] ?: null) : null;
                $name = (string) ($p['displayName'] ?? $e->job->resolveName());
                self::$feature = 'job:' . class_basename($name);
            } catch (\Throwable $ex) {
                self::$feature = 'job:unknown';
            }
        });
        $restore = function () {
            [$a, $u, $f] = array_pop(self::$stack) ?? [null, null, null];
            self::$agencyId = $a; self::$userId = $u; self::$feature = $f;
        };
        // A job ends in exactly one of these (an exception fires JobExceptionOccurred, never JobProcessed).
        Event::listen(JobProcessed::class, $restore);
        Event::listen(JobExceptionOccurred::class, $restore);
    }

    private static function record(ResponseReceived $e): void
    {
        $uri = $e->request->toPsrRequest()->getUri();
        if (strtolower($uri->getHost()) !== 'api.anthropic.com' || ! str_ends_with($uri->getPath(), '/messages')) {
            return;
        }
        // The account's live state for Platform → AI usage: last success, last error (e.g. out of credit).
        \App\Support\AiConfig::noteReply($e->response->successful(), $e->response->status(), (string) ($e->response->json('error.message') ?? ''));
        if (! $e->response->successful()) return;
        $j = $e->response->json();
        $u = is_array($j) ? ($j['usage'] ?? null) : null;
        if (! is_array($u)) return;

        $agency = self::$agencyId;
        $userId = self::$userId;
        if ($userId === null && ! app()->runningInConsole()) {
            try {
                $userId = optional(request()->user())->id;
                if ($agency === null && $userId) $agency = AuditScope::resolve((int) $userId, request());
            } catch (\Throwable $ex) {}
        }

        DB::table('ai_usage')->insert([
            'agency_id' => $agency,
            'user_id' => $userId,
            'feature' => mb_substr(self::feature(), 0, 120),
            'model' => mb_substr((string) ($j['model'] ?? ''), 0, 80),
            'input_tokens' => (int) ($u['input_tokens'] ?? 0)
                + (int) ($u['cache_creation_input_tokens'] ?? 0) + (int) ($u['cache_read_input_tokens'] ?? 0),
            'output_tokens' => (int) ($u['output_tokens'] ?? 0),
            'created_at' => now(),
        ]);
    }

    /** What asked: the API route, or the artisan command. */
    private static function feature(): string
    {
        if (self::$feature) return self::$feature;
        if (app()->runningInConsole()) {
            $argv = $_SERVER['argv'] ?? [];
            return 'cli:' . ($argv[1] ?? 'unknown');
        }
        try {
            $route = request()->route();
            return $route ? $route->uri() : request()->path();
        } catch (\Throwable $e) {
            return 'web';
        }
    }
}
