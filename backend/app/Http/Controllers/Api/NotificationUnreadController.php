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
        /* `type=*` means EVERY kind, which is what a bell has to count.

           The default of 'announcement' is right for the callers that ask "does this
           one tab have something new". It was wrong as a general answer, and there was
           no way to ask for the general answer at all — so the top-bar bell could not
           use this endpoint and did not count a person's alerts at all. An agency admin
           had 338 unread notifications and a silent bell. (2026-09-10) */
        $type = (string) $request->input('type', 'announcement');
        $all = $type === '*' || $request->boolean('all');

        $q = DB::table('notifications')
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at');
        if (! $all) {
            $q->where('type', $type);
        }

        return response()->json([
            'type' => $all ? '*' : $type,
            'unread' => $q->count(),
        ]);
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
    /**
     * Put one notification back to unread.
     *
     * Read was a one-way door: tapping a row marked it read on the way to wherever it
     * pointed, and nothing could undo that — so an item opened by accident dropped out
     * of the unread filter and the bell count for good.
     *
     * Existence is checked separately from the update because an already-unread row
     * updates zero rows, and treating that as "not found" would 404 on a notification
     * that is right there. Scoped to the caller's own rows, like everything else here.
     */
    public function markUnread(Request $request, int $id): JsonResponse
    {
        $q = DB::table('notifications')
            ->where('user_id', $request->user()->id)
            ->where('id', $id);

        if (! $q->exists()) return response()->json(['message' => 'Not found'], 404);

        $q->update(['read_at' => null]);

        return response()->json(['ok' => true]);
    }

    /**
     * Delete one notification — IDEMPOTENTLY.
     *
     * This used to 404 whenever the row was already gone, which is the normal outcome of
     * deleting the same notification twice: the bell dropdown and the notifications screen
     * keep separate caches, so binning it in one leaves it listed in the other. The client
     * treats any error as a failure — it restores the row it had optimistically removed and
     * shows "Could not delete". So a delete that had in fact WORKED reported failure, put
     * the notification back on screen, and invited another click that failed the same way.
     *
     * Seen live 2026-08-25: Cassandra Schnarr (user 146) deleted notification 2232 at
     * 17:00:31 — a clean 200, the row genuinely gone — then five further attempts over the
     * next 22 seconds, every one a 404, every one telling her it had failed.
     *
     * Deleting something that is already deleted is a success: the caller asked for a state,
     * and that state holds. Only a notification belonging to SOMEONE ELSE is a real 404 —
     * and it must stay a 404, so this cannot be used to probe which ids exist.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = DB::table('notifications')
            ->where('user_id', $request->user()->id)
            ->where('id', $id)
            ->delete();

        if ($deleted) {
            return response()->json(['deleted' => $deleted]);
        }

        // Nothing deleted. Does the row exist at all, or was it already gone?
        if (DB::table('notifications')->where('id', $id)->exists()) {
            return response()->json(['message' => 'Not found'], 404);   // someone else's
        }

        return response()->json(['deleted' => 0, 'already_deleted' => true]);
    }
}
