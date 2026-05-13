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
     * Mark all of a type as read (called when parent opens the inbox).
     */
    public function markRead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'string', 'max:80'],
        ]);
        $q = DB::table('notifications')
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at');
        if (!empty($data['type'])) $q->where('type', $data['type']);
        $updated = $q->update(['read_at' => now()]);

        return response()->json(['marked_read' => $updated]);
    }
}
