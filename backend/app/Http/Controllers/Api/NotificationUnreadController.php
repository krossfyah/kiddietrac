<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v14: Unread count for the announcement nav badge.
 *
 * Reads notifications where type='announcement' and read_at IS NULL
 * for the current user. Also exposes mark-as-read so the badge clears
 * when the parent opens the inbox.
 */
final class NotificationUnreadController extends Controller
{
    /**
     * GET /api/v1/notifications/unread-count?type=announcement
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $type = $request->input('type', 'announcement');
        $count = DB::table('notifications')
            ->where('user_id', $request->user()->id)
            ->where('type', $type)
            ->whereNull('read_at')
            ->count();

        return response()->json(['type' => $type, 'unread' => $count]);
    }

    /**
     * POST /api/v1/notifications/mark-read
     * Mark notifications read for the current user — by exact `type`, or by a
     * `category` (billing / photos / messages) that matches the same keywords the
     * bottom-nav badge uses, so opening Billing/Photos clears its counter.
     */
    public function markRead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'in:billing,photos,messages'],
        ]);
        $q = DB::table('notifications')
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at');
        if (!empty($data['type'])) $q->where('type', $data['type']);
        if (!empty($data['category'])) {
            $kw = $data['category'] === 'billing' ? ['invoice', 'billing', 'payment', 'receipt']
                : ($data['category'] === 'photos' ? ['photo', 'image', 'gallery', 'picture'] : ['message', 'chat', 'nudge']);
            $q->where(function ($w) use ($kw) {
                foreach ($kw as $k) {
                    $w->orWhere('type', 'like', '%' . $k . '%')
                      ->orWhere('title', 'like', '%' . $k . '%')
                      ->orWhere('body', 'like', '%' . $k . '%');
                }
            });
        }
        $updated = $q->update(['read_at' => now()]);

        return response()->json(['marked_read' => $updated]);
    }

    /**
     * Delete notifications from the caller's own inbox.
     *
     * Body: {ids: [1,2,3]}  — delete those, or
     *       {all: true}     — clear the whole inbox, or
     *       {read: true}    — clear just the ones already read.
     *
     * Every query is scoped by user_id, so a caller can only ever delete their
     * own rows even if they pass someone else's id.
     */
    public function destroyMany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => 'array',
            'ids.*' => 'integer',
            'all'   => 'boolean',
            'read'  => 'boolean',
        ]);

        $userId = $request->user()->id;
        $q = DB::table('notifications')->where('user_id', $userId);

        if (!empty($data['all'])) {
            // whole inbox
        } elseif (!empty($data['read'])) {
            $q->whereNotNull('read_at');
        } elseif (!empty($data['ids'])) {
            $q->whereIn('id', $data['ids']);
        } else {
            return response()->json(['message' => 'Nothing to delete.'], 422);
        }

        return response()->json(['deleted' => $q->delete()]);
    }

    /** Delete a single notification from the caller's own inbox. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = DB::table('notifications')
            ->where('user_id', $request->user()->id)
            ->where('id', $id)
            ->delete();

        if (!$deleted) return response()->json(['message' => 'Not found'], 404);
        return response()->json(['deleted' => $deleted]);
    }
}
