<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * How far this person has read — the bell, the what's-new dot, the sales-chat badge.
 *
 * Each of these lived in localStorage, which made them per-browser: read on the laptop,
 * still lit on the phone; and a new device, a private window or a cleared cache started
 * by claiming everything was unread.
 *
 * Scoped to the caller and nobody else. There is no route to read or write another
 * person's markers — it is their own read position, and there is no reason for anybody
 * else to see it, let alone move it.
 */
final class UiMarkerController extends Controller
{
    /**
     * The markers this feature owns.
     *
     * An allowlist rather than a free-for-all: this is a key/value store attached to a
     * user, and without one it becomes a place to stash anything, including things that
     * should never live in a column somebody can write to from a browser.
     */
    private const KEYS = [
        'kt_notif_seen',      // newest notification event the bell has shown
        'kt_whatsnew_seen',   // newest what's-new entry read
        'kt_sales_chat_seen', // highest sales-chat message id read
    ];

    /** GET /me/markers */
    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('user_ui_markers')
            ->where('user_id', (int) $request->user()->id)
            ->whereIn('marker_key', self::KEYS)
            ->pluck('marker_value', 'marker_key');

        return response()->json(['markers' => (object) $rows->all()]);
    }

    /**
     * PUT /me/markers — move one marker forward.
     *
     * MONOTONIC, on the server. Both clients already refuse to move their local marker
     * backwards, for a good reason: two tabs, or a poll racing the panel, could otherwise
     * write an older position and resurrect a badge that had just been cleared. Two
     * DEVICES make that ordinary rather than rare, so the rule belongs here as well —
     * a phone that has been asleep must not undo what the laptop just read.
     *
     * Answers with the value that is now stored, which may be the one already there. The
     * client takes that as the truth rather than assuming its own write won.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'   => ['required', 'string', 'in:' . implode(',', self::KEYS)],
            'value' => ['required', 'string', 'max:190'],
        ]);

        $userId = (int) $request->user()->id;
        $value = trim($data['value']);
        if ($value === '') {
            return response()->json(['message' => 'A marker needs a value.'], 422);
        }

        $stored = DB::table('user_ui_markers')
            ->where('user_id', $userId)->where('marker_key', $data['key'])
            ->value('marker_value');

        if ($stored !== null && ! self::isForward((string) $stored, $value)) {
            // Not an error: an older position arriving is expected, and the answer is
            // simply what remains stored.
            return response()->json(['key' => $data['key'], 'value' => $stored, 'moved' => false]);
        }

        DB::table('user_ui_markers')->updateOrInsert(
            ['user_id' => $userId, 'marker_key' => $data['key']],
            ['marker_value' => $value, 'updated_at' => now()]
        );

        return response()->json(['key' => $data['key'], 'value' => $value, 'moved' => true]);
    }

    /**
     * Is $next further on than $current?
     *
     * The three markers do not share a type: two are dates/timestamps, one is a numeric
     * message id. Comparing "10" against "9" as strings puts 10 first, which would let a
     * badge come back; comparing an ISO date numerically gives NAN. So: numeric when both
     * sides genuinely are numbers, lexical otherwise — which is correct for ISO dates and
     * timestamps, because they sort that way by construction.
     */
    private static function isForward(string $current, string $next): bool
    {
        if (is_numeric($current) && is_numeric($next)) {
            return (float) $next > (float) $current;
        }

        return strcmp($next, $current) > 0;
    }
}
