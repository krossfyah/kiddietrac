<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AutoTimeFix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PUBLIC: "enter the real time" from the automatic sign-out / clock-out emails (2026-10-22).
 * See App\Support\AutoTimeFix. Signed out by design: the link IS the credential (a random
 * 40-character token, stored hashed, single use, 7 days), bound to one record and one
 * recipient. No cookies or sessions are involved, so there is nothing for a cross-site
 * request to ride on — a forged POST without the token is simply refused.
 */
final class AutoTimeFixController extends Controller
{
    /** GET /api/v1/public/time-fix/{token} */
    public function show(string $token): JsonResponse
    {
        [$link, $state] = AutoTimeFix::find($token);
        if ($state !== 'ok') {
            return response()->json(['state' => $state, 'message' => AutoTimeFix::stateMessage($state),
                'brand' => $link ? (AutoTimeFix::describe($link)['brand'] ?? null) : null, 'locale' => $link ? self::locale($link) : null], 200);
        }

        return response()->json(['state' => 'ok', 'locale' => self::locale($link)] + AutoTimeFix::describe($link));
    }

    /** The recipient's own language, so the page reads like their app does (2026-10-22). */
    private static function locale(?object $link): string
    {
        $l = ($link && ! empty($link->recipient_user_id))
            ? (string) DB::table('users')->where('id', $link->recipient_user_id)->value('locale') : '';

        return in_array($l, ['en', 'fr', 'es', 'hi'], true) ? $l : 'en';
    }

    /** POST /api/v1/public/time-fix/{token}  {time: "HH:MM", note?: string} */
    public function submit(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'note' => ['nullable', 'string', 'max:280'],
            'website' => ['nullable', 'string', 'max:0'],   // honeypot: bots fill every field
        ]);
        [$ok, $message] = AutoTimeFix::apply($token, $data['time'], $data['note'] ?? null, $request->ip());
        if (! $ok) {
            // A refused attempt is worth a line too: an old or forwarded link being tried
            // again is exactly what a director asks about. Filed under the link's agency.
            [$link, $state] = AutoTimeFix::find($token);
            \App\Support\Audit::write([
                'user_id' => $link->recipient_user_id ?? null,
                'agency_id' => $link->agency_id ?? null,
                'action' => 'attendance.time_fix_refused',
                'entity_type' => $link ? ($link->kind === 'child' ? 'check_event' : 'time_punch') : null,
                'entity_id' => $link->record_id ?? null,
                'payload' => json_encode(['summary' => 'A time entered from an automatic sign-out email was refused: ' . $message,
                    'state' => $state, 'time' => $data['time']], JSON_UNESCAPED_UNICODE),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);

            return response()->json(['ok' => false, 'message' => $message], 422);
        }

        return response()->json(['ok' => true, 'message' => $message]);
    }
}
