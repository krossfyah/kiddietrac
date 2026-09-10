<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Models\AiDailyDigest;
use App\Models\Child;
use App\Services\AiDigestService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DigestStatusController v20 — director-facing UI for AI digest visibility.
 *
 * Two endpoints:
 *  GET  /director/digest-status?date=YYYY-MM-DD&centre_id=N
 *       Returns per-child status for the given date.
 *  POST /director/digest-status/regenerate
 *       Body: { child_id, date }
 *       Forces a fresh AI digest for one child. Returns the generated digest.
 *
 * Both endpoints require centre_director or agency_admin role (middleware
 * applied at route level).
 */
class DigestStatusController extends Controller
{
    /* THE SAME TENANT HELPERS THE REST OF THE PORTAL USES.

       Found 2026-09-10 by probing every read endpoint with two agencies' tokens: this
       was the ONLY one of 325 that returned another tenant's records, and it did it in
       both directions — an iLearn admin listing Test Agency's children and a Test
       Agency admin listing iLearn's. See index() and regenerate() for the two holes. */
    use ResolvesCentreContext;

    public function __construct(protected AiDigestService $ai)
    {
    }

    /**
     * List per-child digest status for a date.
     */
    public function index(Request $request): JsonResponse
    {
        $date     = $request->query('date', today()->toDateString());
        $centreId = $request->query('centre_id');

        // Validate date format
        try {
            Carbon::parse($date);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Invalid date format. Expected YYYY-MM-DD.'], 422);
        }

        /* SCOPED TO THE CALLER'S OWN CENTRES.

           This read `Child::query()` with no scope at all when centre_id was absent —
           EVERY child on the platform, every agency — under a comment claiming the
           children were "visible to this user (delegated to model scopes)". There is no
           such scope on the model. A comment asserting a guard that does not exist is
           worse than no comment: it is why this survived the tenant-isolation audit that
           hardened ~33 other controllers.

           And when centre_id WAS supplied it was trusted verbatim, so passing another
           agency's centre id read that centre's children out directly.

           Both answered by the portal's own helper, which fails closed: no resolvable
           agency means no centres, and `?: [0]` means no centres matches nobody rather
           than everybody. */
        $visibleCentreIds = $this->visibleCentreIds($request);

        if ($centreId) {
            if (! in_array((int) $centreId, array_map('intval', $visibleCentreIds), true)) {
                // Not "forbidden" — this agency has no business knowing the centre exists.
                return response()->json(['error' => 'Centre not found'], 404);
            }
            $scopeCentreIds = [(int) $centreId];
        } else {
            $scopeCentreIds = array_map('intval', $visibleCentreIds);
        }

        $childrenQuery = Child::query()
            ->select(['children.id', 'children.first_name', 'children.last_name'])
            ->orderBy('children.last_name')
            ->whereHas('currentEnrollment.room', function ($q) use ($scopeCentreIds) {
                $q->whereIn('centre_id', $scopeCentreIds ?: [0]);
            });

        $children = $childrenQuery->get();
        if ($children->isEmpty()) {
            return response()->json([
                'date'       => $date,
                'centre_id'  => $centreId,
                'children'   => [],
                'summary'    => $this->emptySummary(),
            ]);
        }

        // Bulk lookups for performance
        $childIds = $children->pluck('id')->all();

        $digests = DB::table('ai_daily_digests')
            ->whereIn('child_id', $childIds)
            ->where('digest_date', $date)
            ->get()
            ->keyBy('child_id');

        // Agency-day instants. $childIds share a centre here, so one range serves all.
        [$dayFrom, $dayTo] = \App\Support\AgencyTime::dayRangeForChild((int) ($childIds[0] ?? 0), $date);
        $eventCounts = DB::table('daily_events')
            ->whereIn('child_id', $childIds)
            ->where('occurred_at', '>=', $dayFrom)->where('occurred_at', '<', $dayTo)
            ->select('child_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('child_id')
            ->pluck('cnt', 'child_id');

        $rows = $children->map(function ($child) use ($digests, $eventCounts) {
            $digest    = $digests->get($child->id);
            $hasEvents = ($eventCounts->get($child->id) ?? 0) > 0;

            $status = 'pending';
            if ($digest && !empty($digest->body)) {
                $status = 'generated';
            } elseif (!$hasEvents) {
                $status = 'no_events';
            }

            return [
                'child_id'        => $child->id,
                'child_name'      => trim($child->first_name . ' ' . $child->last_name),
                'event_count'     => (int) ($eventCounts->get($child->id) ?? 0),
                'status'          => $status,
                'has_digest'      => $digest !== null && !empty($digest->body),
                'generated_at'    => $digest->generated_at ?? null,
                'model_used'      => $digest->model_used ?? null,
                'tokens_used'     => $digest->tokens_used ?? null,
                'body_preview'    => $digest && !empty($digest->body)
                    ? mb_substr($digest->body, 0, 120) . (mb_strlen($digest->body) > 120 ? '...' : '')
                    : null,
            ];
        });

        // Summary tallies
        $summary = [
            'total'        => $rows->count(),
            'generated'    => $rows->where('status', 'generated')->count(),
            'pending'      => $rows->where('status', 'pending')->count(),
            'no_events'    => $rows->where('status', 'no_events')->count(),
            'ai_enabled'   => $this->ai->isConfigured(),
        ];

        return response()->json([
            'date'      => $date,
            'centre_id' => $centreId,
            'children'  => $rows->values(),
            'summary'   => $summary,
        ]);
    }

    /**
     * Manually regenerate a single child's digest.
     */
    public function regenerate(Request $request): JsonResponse
    {
        $request->validate([
            'child_id' => 'required|integer|exists:children,id',
            'date'     => 'nullable|date_format:Y-m-d',
        ]);

        $childId = (int) $request->input('child_id');
        $date    = $request->input('date') ?: today()->toDateString();

        $child = Child::find($childId);
        if (! $child) {
            return response()->json(['error' => 'Child not found'], 404);
        }

        /* THE WRITE PATH BESIDE THE READ — and it was the worse of the two.

           `exists:children,id` proves a row exists SOMEWHERE. This then generated an AI
           daily digest for it and returned the narrative, so any director could ask for
           any child on the platform by id and be handed a written account of that
           child's day. Validation is not authorisation.

           404, not 403: a director in another agency should not learn that this child
           exists. Same answer as a genuinely missing id, which is the point. */
        if (! $this->canAccessChildScoped($request, $childId)) {
            return response()->json(['error' => 'Child not found'], 404);
        }

        if (! $this->ai->isConfigured()) {
            return response()->json([
                'error'   => 'AI service not configured',
                'detail'  => 'Anthropic API key missing. Falls back to templated digest.',
            ], 503);
        }

        try {
            // Force regeneration: delete existing first so service does not update-or-create.
            // Actually the service uses updateOrCreate already — so this just overwrites.
            $digest = $this->ai->generate($child, $date);

            if (! $digest) {
                return response()->json([
                    'error'   => 'Digest generation returned null',
                    'detail'  => 'Check storage/logs for the AI API error. Most common: Anthropic credit balance too low, or no events on this date.',
                ], 502);
            }

            return response()->json([
                'success'      => true,
                'child_id'     => $childId,
                'date'         => $date,
                'body'         => $digest->body,
                'generated_at' => $digest->generated_at,
                'model_used'   => $digest->model_used,
                'tokens_used'  => $digest->tokens_used,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error'  => 'Regeneration exception',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }

    private function emptySummary(): array
    {
        return [
            'total'      => 0,
            'generated'  => 0,
            'pending'    => 0,
            'no_events'  => 0,
            'ai_enabled' => $this->ai->isConfigured(),
        ];
    }
}
