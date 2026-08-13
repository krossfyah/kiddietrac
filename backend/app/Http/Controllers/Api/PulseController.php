<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The pulse — one small request that answers "has anything I care about changed?"
 *
 * The portal reaches realtime today by polling: three separate badge pollers in the
 * top bar every 15s, message toasts every 12s, announcements every 30s, the clock
 * every 60s, and more. Twenty to thirty requests a minute per open browser, each one
 * a full controller doing real work — and despite all of it, the SCREEN still never
 * updated when another user changed something, because nothing polled the data the
 * screen was actually showing.
 *
 * This returns cheap CHANGE MARKERS instead: the highest id in each table that
 * matters, scoped to what this user can see, plus the unread counts the badges need.
 * The client keeps the last set it saw; anything that moved tells it precisely what
 * to refresh. One request replaces the question every poller was asking separately,
 * and it answers a question none of them could: "did someone ELSE change this?"
 *
 * Deliberately MAX(id) rather than COUNT(*) or a timestamp comparison — an indexed
 * max over a primary key is about as cheap as a query gets, it needs no clock
 * agreement between server and client, and it cannot miss a change the way a
 * timestamp with second precision can when two writes land in the same second.
 */
class PulseController extends Controller
{
    use ResolvesCentreContext;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) return response()->json(['message' => 'Unauthenticated'], 401);

        $agencyId = $this->resolveAgencyId($request);
        $centreIds = $this->visibleCentreIds($user, $agencyId);

        $marks = [];

        // Attendance — the most time-critical thing on the platform: a child arriving
        // or leaving should be visible to everyone watching the room, now.
        $marks['check_events'] = $centreIds
            ? (int) DB::table('check_events as ce')
                ->join('children as ch', 'ch.id', '=', 'ce.child_id')
                ->join('families as f', 'f.id', '=', 'ch.family_id')
                ->whereIn('f.centre_id', $centreIds)
                ->max('ce.id')
            : 0;

        // Care logged for children, from BOTH tables the portal writes to. Scoped
        // the same way as attendance: an UNSCOPED max would move whenever any other
        // agency logged anything, refreshing this user's screen for a change they
        // cannot see. No data leaks either way — these are only row ids — but a
        // tenant should not be able to make another tenant's screens work.
        $childScoped = function (string $table) use ($centreIds) {
            $q = DB::table($table . ' as t');
            if ($centreIds) {
                $q->join('children as c', 'c.id', '=', 't.child_id')
                  ->join('families as fam', 'fam.id', '=', 'c.family_id')
                  ->whereIn('fam.centre_id', $centreIds);
            }
            return (int) $q->max('t.id');
        };
        $marks['daily_events'] = $childScoped('daily_events');
        $marks['care_logs'] = $childScoped('daily_care_logs');

        // Photos and video shared with families.
        $marks['media'] = $centreIds
            ? (int) DB::table('photos')->whereIn('centre_id', $centreIds)->max('id')
            : (int) DB::table('photos')->max('id');

        // Staff on the clock — drives the ratio indicator. time_punches carries a
        // centre_id of its own, so this one scopes directly.
        $marks['time_punches'] = $centreIds
            ? (int) DB::table('time_punches')->whereIn('centre_id', $centreIds)->max('id')
            : (int) DB::table('time_punches')->max('id');

        // Agency-level records people edit while others are looking at them.
        if ($agencyId) {
            $marks['centres'] = (int) DB::table('centres')->where('agency_id', $agencyId)->max('updated_at')
                ? (int) strtotime((string) DB::table('centres')->where('agency_id', $agencyId)->max('updated_at'))
                : 0;
        }

        // The counts the badges poll for separately today. Returning them here is
        // what lets those pollers be retired onto this one request.
        $counts = [
            'notifications' => (int) DB::table('notifications')
                ->where('user_id', $user->id)->whereNull('read_at')->count(),
        ];

        return response()->json([
            'marks' => $marks,
            'counts' => $counts,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Every centre this user can see. A platform admin or agency admin sees the
     * agency's; everyone else sees the one they are attached to. Returning [] means
     * "unscoped" for the tables that are not centre-bound, never "everything".
     */
    private function visibleCentreIds($user, ?int $agencyId): array
    {
        if ($agencyId) {
            return DB::table('centres')->where('agency_id', $agencyId)
                ->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all();
        }
        $centreId = $this->resolveCentreId($user);
        return $centreId ? [(int) $centreId] : [];
    }
}
