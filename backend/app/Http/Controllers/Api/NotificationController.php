<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p45 — Real implementation of the notifications endpoints (was a 501
 * stub since the routes were declared). Pulls from the existing
 * notifications table — user_id, type, title, body, data, read_at,
 * created_at.
 *
 * Routes that hit this controller:
 *   GET   /api/v1/parent/notifications                  -> mine()
 *   PATCH /api/v1/parent/notifications/{id}/read        -> markRead()
 *
 * Even though the route group is named 'parent', the implementation does
 * not insist on a guardian role — it just filters by user_id so any role
 * with these routes wired in (we may add staff later) gets their own
 * inbox without changes to the controller.
 */
final class NotificationController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $rows = DB::table('notifications')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get([
                'id', 'type', 'title', 'body', 'data', 'read_at', 'created_at',
            ]);

        $unread = $rows->whereNull('read_at')->count();

        /* STAFF SUBMISSIONS IN THE PHONE INBOX (2026-11-26): only when the Inbox screen asks (with_submissions=1), so
           every other reader of this list (sounds, toasts, badges) is unchanged. Merged by time, read-time only - see
           App\Support\StaffSubmissionsFeed. Empty for anyone who is not an admin or director. unread_count stays the
           notifications' own. */
        if ($request->boolean('with_submissions')) {
            try {
                $extra = \App\Support\StaffSubmissionsFeed::inboxRows($request);
                if ($extra) {
                    $rows = $rows->concat($extra)->sortByDesc(fn ($r) => (string) $r->created_at)->take(200)->values();
                }
            } catch (\Throwable $e) {
                report($e);   // the person's own notifications still answer
            }
        }

        return response()->json([
            'notifications' => $rows,
            'unread_count' => $unread,
        ]);
    }

    public function markRead(Request $request, int $notification): JsonResponse
    {
        $userId = $request->user()->id;
        $owns = DB::table('notifications')->where('id', $notification)->where('user_id', $userId)->exists();
        if (!$owns) return response()->json(['message' => 'Not found'], 404);
        DB::table('notifications')->where('id', $notification)->update([
            'read_at' => now(),
        ]);
        return response()->json(['message' => 'Marked read']);
    }
}
