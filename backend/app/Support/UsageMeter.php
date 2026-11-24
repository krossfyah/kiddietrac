<?php

namespace App\Support;

use Illuminate\Http\Client\Events\ResponseReceived;
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
 */
class UsageMeter
{
    /** Set by background work that knows which agency it is spending for. */
    public static ?int $agencyId = null;

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
        $userId = null;
        if (! app()->runningInConsole()) {
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
