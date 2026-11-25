<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AttendanceReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PUBLIC: "Mark absent / Running late" from the missed-child emails (2026-11-25). See App\Support\AttendanceReply.
 * Signed out by design: the link IS the credential (random 40 characters, stored hashed, one child + one guardian +
 * one day). GET only reads (email scanners open links); the page's POST is the only thing that acts.
 */
final class AttendanceReplyController extends Controller
{
    /** GET /api/v1/public/attendance-reply/{token} */
    public function show(string $token): JsonResponse
    {
        [$link, $state] = AttendanceReply::find($token);
        if ($state !== 'ok') {
            return response()->json(['state' => $state, 'message' => AttendanceReply::stateMessage($state),
                'brand' => $link ? AttendanceReply::brand((int) $link->agency_id) : null, 'locale' => self::locale($link)]);
        }

        return response()->json(['state' => 'ok', 'locale' => self::locale($link)] + AttendanceReply::describe($link));
    }

    /** POST /api/v1/public/attendance-reply/{token}  {action: absent|late, reason?, note?, by?: "HH:MM"} */
    public function submit(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:absent,late'],
            'reason' => ['nullable', 'string', 'in:' . implode(',', AttendanceReply::REASONS)],
            'note' => ['nullable', 'string', 'max:280'],
            'by' => ['nullable', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'website' => ['nullable', 'string', 'max:0'],   // honeypot: bots fill every field
        ]);
        [$ok, $message] = AttendanceReply::apply($token, $data['action'], $data['reason'] ?? null, $data['note'] ?? null, $data['by'] ?? null);
        [$link, $state] = AttendanceReply::find($token);
        if (! $ok) {
            // A refused answer is worth a line too (an old or forwarded link). Recorded answers are audited in
            // AttendanceReply::apply (attendance_reply.absent / .late).
            try {
                \App\Support\Audit::write(['user_id' => $link->guardian_user_id ?? null, 'agency_id' => $link->agency_id ?? null,
                    'action' => 'attendance_reply.refused', 'entity_type' => 'child', 'entity_id' => $link->child_id ?? null,
                    'payload' => json_encode(['summary' => 'Email link answer not recorded (' . $data['action'] . '): ' . $message,
                        'ip' => $request->ip()], JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
            } catch (\Throwable $e) {
            }

            return response()->json(['message' => $message, 'current' => ($link && $state === 'ok') ? AttendanceReply::current($link) : null], 422);
        }

        return response()->json(['ok' => true, 'message' => $message, 'current' => $link ? AttendanceReply::current($link) : null]);
    }

    /** The recipient's own language, so the page reads like their app does. */
    private static function locale(?object $link): string
    {
        $l = ($link && ! empty($link->guardian_user_id))
            ? (string) DB::table('users')->where('id', $link->guardian_user_id)->value('locale') : '';

        return in_array($l, ['en', 'fr', 'es', 'hi'], true) ? $l : 'en';
    }
}
