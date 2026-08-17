<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every timestamp leaving this API carries its timezone (2026-08-17).
 *
 * THE CLASS OF BUG THIS ENDS. MySQL returns "2026-08-17 14:45:00" with no zone marker, and
 * most of this codebase queries with DB::table() and hands those strings straight out. A
 * client cannot tell whether that is UTC or the centre's wall clock, so it guesses.
 * JavaScript guesses "device local" for a zone-less date-TIME, which is wrong by the whole
 * UTC offset. Fixing that on the client (kt-tz-global) then broke the two endpoints that
 * had gone the other way and were sending local times — 10:45 rendered as 06:45.
 *
 * Both of those are the same underlying fault: the wire format could not say what it meant.
 * Patching readers one at a time cannot fix it, because the next endpoint, the next screen
 * and the next client all start the argument again.
 *
 * So it is fixed here, once, for every response: a bare "Y-m-d H:i:s" is what this database
 * stores, which is UTC, and it goes out as ISO-8601 Zulu — "2026-08-17T14:45:00Z". One
 * instant, no interpretation required, and JavaScript's own Date parser gets it right with
 * no shim at all.
 *
 * UTC on the wire rather than the agency's offset, deliberately: a single format keeps
 * lexicographic ordering meaningful, and several screens sort by comparing these strings
 * directly. Mixed offsets would sort wrongly while looking correct. Human-facing times
 * travel separately as time_display, already rendered in the centre's zone.
 *
 * WHAT IT LEAVES ALONE:
 *   • anything already carrying Z or ±HH:MM — nothing ambiguous about those, and
 *     re-stamping would shift them twice;
 *   • date-only "2026-08-17" — no time, nothing to misread, and appending a zone would
 *     let it move across a day boundary;
 *   • every other string. The pattern is anchored and demands the exact MySQL datetime
 *     shape, so "1 x 2026-08-17 14:45:00" in a note is not touched.
 *
 * KT_TZ_NORMALIZE=false switches it off without a deploy, because middleware that rewrites
 * every response should have a way to stop.
 */
final class NormalizeJsonTimestamps
{
    /** The MySQL datetime shape, and nothing else. Seconds optional; no zone. */
    private const MYSQL_DATETIME = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/';

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (! filter_var(env('KT_TZ_NORMALIZE', true), FILTER_VALIDATE_BOOLEAN)) {
            return $response;
        }
        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $data = $response->getData(true);
        if (! is_array($data)) {
            return $response;
        }

        $response->setData($this->walk($data));

        return $response;
    }

    /**
     * Rewrite in place, depth-limited.
     *
     * The limit is a safety rail rather than a real constraint: a runaway structure would
     * otherwise recurse until the request died, and no response here is 20 levels deep.
     */
    private function walk(array $node, int $depth = 0): array
    {
        if ($depth > 20) {
            return $node;
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->walk($value, $depth + 1);
                continue;
            }
            if (is_string($value) && $value !== '' && preg_match(self::MYSQL_DATETIME, $value)) {
                // Stored UTC — every writer in this codebase inserts now() under
                // app.timezone=UTC — so it is stated as UTC rather than assumed.
                $node[$key] = str_replace(' ', 'T', $value)
                    . (strlen($value) === 16 ? ':00' : '') . 'Z';
            }
        }

        return $node;
    }
}
