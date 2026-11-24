<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\CalendarFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Calendar sync (2026-11-03): the private subscription feed and the Settings card that manages it.
 * See App\Support\CalendarFeed for the scope rules.
 *
 *   GET  /cal/{token}.ics            public, no sign-in (that is how calendar apps work)
 *   GET  /me/calendar-feed?as=…      the person's link + choices (made on first use)
 *   PATCH /me/calendar-feed          save the choices
 *   POST /me/calendar-feed/reset     new link; the old one stops at once
 */
final class CalendarFeedController extends Controller
{
    /** GET /cal/{token}.ics */
    public function feed(Request $request, string $token)
    {
        $ip = (string) $request->ip();
        // Guessing is hopeless (256 bits), but a scanner still should not get free tries.
        if (RateLimiter::tooManyAttempts('calfeed-miss:' . $ip, 30)) {
            return $this->plain('Too many requests. Try again later.', 429, ['Retry-After' => '600']);
        }
        $feed = strlen($token) >= 40 ? DB::table('calendar_feeds')->where('token_hash', CalendarFeed::hashOf($token))->first() : null;
        if (! $feed) {
            RateLimiter::hit('calfeed-miss:' . $ip, 600);

            return $this->plain("This calendar link is no longer active.\nOpen KiddieTrac, go to Settings → Calendar and add your current link.", 404);
        }
        // Per link: calendar apps poll every few minutes at most; 60 in 10 minutes is a runaway.
        $key = 'calfeed:' . $feed->id;
        if (RateLimiter::tooManyAttempts($key, 60)) {
            return $this->plain('Too many requests for this calendar. Try again later.', 429, ['Retry-After' => '600']);
        }
        RateLimiter::hit($key, 600);

        // Built at most every 10 minutes per link (and rebuilt at once after a change of choices or a reset).
        $ck = 'calfeed-body:' . $feed->id . ':' . sha1((string) $feed->token_hash . '|' . (string) $feed->options . '|' . (string) $feed->updated_at);
        [$body, $n] = Cache::remember($ck, 600, fn () => CalendarFeed::build($feed));

        // Who is polling, at most one write per 15 minutes.
        if (! $feed->last_polled_at || strtotime((string) $feed->last_polled_at . ' UTC') < time() - 900) {
            DB::table('calendar_feeds')->where('id', $feed->id)->update([
                'last_polled_at' => now(), 'poll_count' => DB::raw('poll_count + 1'),
                'last_agent' => mb_substr((string) $request->userAgent(), 0, 120),
            ]);
        }

        $etag = '"' . sha1($body) . '"';
        $headers = [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="kiddietrac.ics"',
            // Private to the link holder; a calendar app re-checks every 15 minutes at most.
            'Cache-Control' => 'private, max-age=900, must-revalidate',
            'ETag' => $etag,
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'X-KT-Events' => (string) $n,
        ];
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, $headers);
        }
        if ($request->isMethod('HEAD')) {
            return response('', 200, $headers);
        }

        return response($body, 200, $headers);
    }

    private function plain(string $msg, int $status, array $extra = [])
    {
        return response($msg, $status, $extra + ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    /** Which kind the caller asked for, if they really can have it. */
    private function kind(Request $request): ?string
    {
        $uid = (int) $request->user()->id;
        $kinds = CalendarFeed::kindsFor($uid);
        $want = (string) $request->input('as', '');
        if (in_array($want, $kinds, true)) {
            return $want;
        }

        return $want === '' && count($kinds) === 1 ? $kinds[0] : null;
    }

    private function shape(object $feed, array $kinds): array
    {
        $agencies = $feed->kind === 'staff' ? CalendarFeed::staffScope((int) $feed->user_id)['agencies'] : CalendarFeed::parentScope((int) $feed->user_id)['agencies'];

        return [
            'kind' => $feed->kind,
            'kinds' => $kinds,
            'urls' => CalendarFeed::urls(CalendarFeed::tokenFor((string) $feed->salt)),
            'options' => CalendarFeed::options($feed),
            'created_at' => $feed->created_at,
            'reset_at' => $feed->reset_at,
            'last_polled_at' => $feed->last_polled_at,
            'agencies' => $agencies ? DB::table('agencies')->whereIn('id', $agencies)->pluck('name')->all() : [],
            'window' => ['back_days' => CalendarFeed::BACK_DAYS, 'ahead_months' => CalendarFeed::AHEAD_MONTHS],
        ];
    }

    private function unavailable(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        // Say why: either nothing to sync, or every agency switched it off.
        $why = 'none';
        $hasAny = DB::table('guardians')->where('user_id', $uid)->exists()
            || DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)->whereIn('role', ['educator', 'centre_director', 'home_visitor', 'agency_admin'])->exists();
        if ($hasAny) {
            $why = 'off';
        }

        return response()->json(['available' => false, 'reason' => $why]);
    }

    /** GET /me/calendar-feed */
    public function show(Request $request): JsonResponse
    {
        $kinds = CalendarFeed::kindsFor((int) $request->user()->id);
        $kind = $this->kind($request);
        if (! $kind) {
            return $this->unavailable($request);
        }
        $feed = CalendarFeed::ensure((int) $request->user()->id, $kind);

        return response()->json(['available' => true] + $this->shape($feed, $kinds));
    }

    /** PATCH /me/calendar-feed {as, options:{closures,events,absences,schedule|shifts,timeoff}} */
    public function update(Request $request): JsonResponse
    {
        $kind = $this->kind($request);
        abort_unless($kind, 403, 'Calendar sync is not available for this account.');
        $data = $request->validate(['options' => ['required', 'array']]);
        $feed = CalendarFeed::ensure((int) $request->user()->id, $kind);
        $cur = CalendarFeed::options($feed);
        foreach (array_keys(CalendarFeed::OPTIONS[$kind]) as $k) {
            if (array_key_exists($k, $data['options'])) {
                $cur[$k] = filter_var($data['options'][$k], FILTER_VALIDATE_BOOLEAN);
            }
        }
        DB::table('calendar_feeds')->where('id', $feed->id)->update(['options' => json_encode($cur), 'updated_at' => now()]);
        Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $this->agencyOf($request, $kind),
            'action' => 'calendar_feed.options_changed', 'entity_type' => 'calendar_feed', 'entity_id' => (int) $feed->id,
            'payload' => json_encode(['summary' => 'Changed what their calendar link shows', 'kind' => $kind, 'options' => $cur])]);
        $feed = DB::table('calendar_feeds')->where('id', $feed->id)->first();

        return response()->json(['ok' => true, 'available' => true] + $this->shape($feed, CalendarFeed::kindsFor((int) $request->user()->id)));
    }

    /** POST /me/calendar-feed/reset {as} */
    public function reset(Request $request): JsonResponse
    {
        $kind = $this->kind($request);
        abort_unless($kind, 403, 'Calendar sync is not available for this account.');
        $feed = CalendarFeed::reset(CalendarFeed::ensure((int) $request->user()->id, $kind));
        Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $this->agencyOf($request, $kind),
            'action' => 'calendar_feed.reset', 'entity_type' => 'calendar_feed', 'entity_id' => (int) $feed->id,
            'payload' => json_encode(['summary' => 'Reset their calendar link (the old link stopped working)', 'kind' => $kind])]);

        return response()->json(['ok' => true, 'available' => true] + $this->shape($feed, CalendarFeed::kindsFor((int) $request->user()->id)));
    }

    private function agencyOf(Request $request, string $kind): ?int
    {
        $uid = (int) $request->user()->id;
        $agencies = $kind === 'staff' ? CalendarFeed::staffScope($uid)['agencies'] : CalendarFeed::parentScope($uid)['agencies'];
        $h = (int) $request->header('X-Active-Agency-Id');

        return $h && in_array($h, $agencies, true) ? $h : ($agencies[0] ?? null);
    }
}
