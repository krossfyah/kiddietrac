<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p49 — Closes four competitor-parity gaps in one controller because
 * they're all simple CRUD against small new tables.
 *
 * Endpoints:
 *   POST   /care/logs                    Educator records a diaper / nap / meal / etc.
 *   GET    /care/logs/today              Today's logs for current user's child OR a centre's children (staff)
 *   GET    /care/logs/child/{child}      Recent logs for one child (parent + staff)
 *
 *   GET    /care/milestones/catalog      Static catalog of milestones by age band
 *   GET    /care/milestones/child/{child}  Recorded milestones for one child
 *   POST   /care/milestones              Mark a milestone status
 *
 *   POST   /care/portfolio/{child}       Returns the chronological observation timeline
 *
 *   POST   /staff/punch                  Clock in (or out if last punch open)
 *   GET    /staff/punches/me             Current user's punch history
 *   GET    /staff/punches/centre         Director view of all staff punches at the centre
 *
 *   POST   /public/tours                 Public tour booking (NO auth required)
 *   GET    /admin/tours                  List tour bookings for the agency
 *   PATCH  /admin/tours/{id}             Update status (confirm / complete / no-show / cancel)
 */
final class CareController extends Controller
{
    use ResolvesCentreContext;

    // ── Daily care logs ─────────────────────────────────────────────────

    public function logCare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'log_type' => ['required', 'in:diaper,bathroom,nap,meal,snack,bottle,sunscreen,mood'],
            'occurred_at' => ['nullable', 'date'],
            'ended_at' => ['nullable', 'date'],
            'details' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'amount_ml' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'amount_oz' => ['nullable', 'numeric', 'min:0', 'max:60'],
        ]);

        $id = DB::table('daily_care_logs')->insertGetId([
            'child_id' => (int) $data['child_id'],
            'recorded_by_id' => $request->user()->id,
            'log_type' => $data['log_type'],
            'occurred_at' => $data['occurred_at'] ?? now(),
            'ended_at' => $data['ended_at'] ?? null,
            'details' => $data['details'] ?? null,
            'notes' => $data['notes'] ?? null,
            'amount_ml' => $data['amount_ml'] ?? null,
            'amount_oz' => $data['amount_oz'] ?? null,
            'created_at' => now(),
        ]);
        return response()->json(['id' => $id, 'message' => 'Logged'], 201);
    }

    public function logsForChild(Request $request, int $child): JsonResponse
    {
        // Parent-or-staff access check — guardian on the child's family, or
        // staff at the child's centre.
        if (!$this->canSeeChild($request, $child)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $since = $request->query('since')
            ? \Carbon\Carbon::parse($request->query('since'))
            : now()->subDays(7);

        $rows = DB::table('daily_care_logs as l')
            ->leftJoin('users as u', 'u.id', '=', 'l.recorded_by_id')
            ->where('l.child_id', $child)
            ->where('l.occurred_at', '>=', $since)
            ->orderByDesc('l.occurred_at')
            ->limit(300)
            ->get([
                'l.id', 'l.log_type', 'l.occurred_at', 'l.ended_at',
                'l.details', 'l.notes', 'l.amount_ml', 'l.amount_oz',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), 'staff') as logged_by"),
            ])
            ->map(function ($r) { $r->source = 'care_log'; return $r; });

        // A care moment can be recorded from TWO places, into two different
        // tables: "Log a moment" writes daily_care_logs, while the room roster's
        // quick-log writes daily_events. Reading only the first meant a nappy
        // logged from the roster never appeared in Today's log — the entry looked
        // lost. Merge both so every reader sees the child's full day.
        $eventRows = DB::table('daily_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.recorded_by_id')
            ->where('e.child_id', $child)
            ->whereNull('e.deleted_at')
            ->where('e.occurred_at', '>=', $since)
            ->whereIn('e.event_type', ['diaper', 'bathroom', 'nap', 'meal', 'snack', 'bottle', 'sunscreen', 'mood'])
            ->orderByDesc('e.occurred_at')
            ->limit(300)
            ->get([
                'e.id', 'e.event_type', 'e.occurred_at', 'e.payload', 'e.notes',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), 'staff') as logged_by"),
            ])
            ->map(function ($e) {
                // payload is a small JSON blob ({"type":"wet"}) — flatten its values
                // into the same "details" string daily_care_logs uses ("wet").
                $details = null;
                if (!empty($e->payload)) {
                    $p = json_decode($e->payload, true);
                    if (is_array($p)) {
                        $parts = [];
                        foreach ($p as $v) {
                            if (is_scalar($v) && trim((string) $v) !== '') {
                                $parts[] = (string) $v;
                            }
                        }
                        $details = $parts ? implode(', ', $parts) : null;
                    } elseif (is_string($p) && trim($p) !== '') {
                        $details = $p;
                    }
                }
                return (object) [
                    'id' => $e->id,
                    'log_type' => $e->event_type,
                    'occurred_at' => $e->occurred_at,
                    'ended_at' => null,
                    'details' => $details,
                    'notes' => $e->notes,
                    'amount_ml' => null,
                    'amount_oz' => null,
                    'logged_by' => $e->logged_by,
                    'source' => 'event',
                ];
            });

        $rows = $rows->concat($eventRows)
            ->sortByDesc('occurred_at')
            ->values()
            ->take(300);

        // "Today" means today at the CENTRE, not today in UTC. An 8pm Toronto
        // nappy is stamped 00:07 the next day in UTC, so a UTC-midnight cutoff
        // dropped every late-afternoon and evening entry from the day's summary.
        $tz = $this->centreTimezoneForChild($child);
        $today = \Carbon\Carbon::now($tz)->startOfDay()->timezone(config('app.timezone', 'UTC'));

        $summary = [];
        foreach (['diaper','bathroom','nap','meal','snack','bottle','sunscreen','mood'] as $t) {
            $summary[$t] = $rows
                ->filter(fn ($r) => $r->log_type === $t && \Carbon\Carbon::parse($r->occurred_at)->gte($today))
                ->count();
        }

        // Hand the client times already in the centre's zone, so its own
        // "is this today?" check agrees with ours.
        $rows = $rows->map(function ($r) use ($tz) {
            $r->occurred_at = \Carbon\Carbon::parse($r->occurred_at)->timezone($tz)->format('Y-m-d H:i:s');
            if (!empty($r->ended_at)) {
                $r->ended_at = \Carbon\Carbon::parse($r->ended_at)->timezone($tz)->format('Y-m-d H:i:s');
            }
            return $r;
        });

        return response()->json(['logs' => $rows->values(), 'today_summary' => $summary]);
    }

    /**
     * The timezone the child's centre actually operates in.
     *
     * There is no `timezone` column on centres — the value /provider/bootstrap
     * reports is a constant — so we look in the centre's settings JSON and fall
     * back to the same default the rest of the app assumes. This matters because
     * app.timezone is UTC: without it, an early-evening entry in Toronto is
     * stamped tomorrow and disappears from "today".
     */
    private const DEFAULT_TZ = 'America/Toronto';

    private function centreTimezoneForChild(int $child): string
    {
        $settings = DB::table('children as c')
            ->leftJoin('families as f', 'f.id', '=', 'c.family_id')
            ->leftJoin('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->where('c.id', $child)
            ->value('ce.settings');

        if ($settings) {
            $decoded = json_decode((string) $settings, true);
            if (is_array($decoded) && !empty($decoded['timezone'])) {
                return (string) $decoded['timezone'];
            }
        }

        return self::DEFAULT_TZ;
    }

    // ── Milestones ──────────────────────────────────────────────────────

    /**
     * Static HDLH-style milestone catalog. Returned to the UI so the
     * checklist can render without a separate seed table. Grouped by
     * domain × age band so a 2-year-old shows toddler milestones, etc.
     */
    public function milestoneCatalog(): JsonResponse
    {
        return response()->json(['catalog' => $this->catalog()]);
    }

    public function milestonesForChild(Request $request, int $child): JsonResponse
    {
        if (!$this->canSeeChild($request, $child)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $rows = DB::table('milestone_records')
            ->where('child_id', $child)
            ->orderByDesc('observed_at')
            ->get();
        $byKey = $rows->keyBy('milestone_key');
        return response()->json(['records' => $byKey, 'list' => $rows]);
    }

    public function recordMilestone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'framework' => ['required', 'in:HDLH,ELECT,ELOF,custom'],
            'domain' => ['required', 'string', 'max:80'],
            'milestone_key' => ['required', 'string', 'max:120'],
            'milestone_label' => ['required', 'string', 'max:200'],
            'status' => ['required', 'in:emerging,in_progress,achieved'],
            'observed_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (!$this->canSeeChild($request, (int) $data['child_id'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        // Upsert — one row per (child, milestone_key)
        DB::table('milestone_records')->updateOrInsert(
            ['child_id' => (int) $data['child_id'], 'milestone_key' => $data['milestone_key']],
            [
                'recorded_by_id' => $request->user()->id,
                'framework' => $data['framework'],
                'domain' => $data['domain'],
                'milestone_label' => $data['milestone_label'],
                'status' => $data['status'],
                'observed_at' => $data['observed_at'],
                'notes' => $data['notes'] ?? null,
                'updated_at' => now(),
            ]
        );
        return response()->json(['message' => 'Saved']);
    }

    // ── Child portfolio ─────────────────────────────────────────────────

    public function portfolio(Request $request, int $child): JsonResponse
    {
        if (!$this->canSeeChild($request, $child)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $childRow = DB::table('children')->where('id', $child)->whereNull('deleted_at')->first();
        if (!$childRow) return response()->json(['message' => 'Not found'], 404);

        $observations = DB::table('observations as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.recorded_by_id')
            ->where('o.child_id', $child)
            ->orderByDesc('o.observed_at')
            ->limit(500)
            ->get([
                'o.id', 'o.framework', 'o.domain', 'o.title', 'o.body',
                'o.observed_at', 'o.media_ids', 'o.shared_with_family',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), 'staff') as recorded_by"),
            ]);

        $milestones = DB::table('milestone_records')
            ->where('child_id', $child)
            ->orderByDesc('observed_at')
            ->get();

        $stats = [
            'observations' => $observations->count(),
            'milestones_achieved' => $milestones->where('status', 'achieved')->count(),
            'milestones_in_progress' => $milestones->where('status', 'in_progress')->count(),
            'milestones_emerging' => $milestones->where('status', 'emerging')->count(),
        ];

        return response()->json([
            'child' => $childRow,
            'observations' => $observations,
            'milestones' => $milestones,
            'stats' => $stats,
        ]);
    }

    // ── Staff time clock ────────────────────────────────────────────────

    public function punch(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        // v22p97: resolve the centre WITHIN the active agency (header-aware) so a
        // multi-agency user clocks in at the agency they've switched into, and a
        // super-admin testing Test Agency resolves to its centre instead of the
        // bogus "no centre assigned" (which fired because their only role rows are
        // agency/platform-level with no centre_id).
        $centreId = $this->resolveCentreId($request->user());
        if (!$centreId) return response()->json(['message' => 'No centre assigned'], 422);

        $open = DB::table('time_punches')
            ->where('user_id', $userId)
            ->whereNull('punched_out_at')
            ->orderByDesc('punched_in_at')
            ->first();

        if ($open) {
            DB::table('time_punches')->where('id', $open->id)->update([
                'punched_out_at' => now(),
                'notes' => $request->input('notes') ?: $open->notes,
            ]);
            return response()->json([
                'action' => 'out',
                'minutes_worked' => now()->diffInMinutes(\Carbon\Carbon::parse($open->punched_in_at)),
                'message' => 'Clocked out',
            ]);
        }

        $id = DB::table('time_punches')->insertGetId([
            'user_id' => $userId,
            'centre_id' => (int) $centreId,
            'punched_in_at' => now(),
            'notes' => $request->input('notes') ?: null,
            'source' => $request->input('source') ?: 'web',
            'created_at' => now(),
        ]);
        return response()->json(['action' => 'in', 'id' => $id, 'message' => 'Clocked in']);
    }

    public function myPunches(Request $request): JsonResponse
    {
        $rows = DB::table('time_punches')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('punched_in_at')
            ->limit(60)
            ->get();
        return response()->json(['punches' => $rows]);
    }

    public function centrePunches(Request $request): JsonResponse
    {
        // v22p98: resolve the centre WITHIN the active agency (header-aware) so an
        // agency_admin / platform_admin (who have no direct centre_id role) still
        // see the centre's punches — the old direct-centre lookup returned [].
        $centreId = $this->resolveCentreId($request->user());
        if (!$centreId) return response()->json(['punches' => []]);
        $rows = DB::table('time_punches as t')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->where('t.centre_id', $centreId)
            ->orderByDesc('t.punched_in_at')
            ->limit(200)
            ->get([
                't.id', 't.user_id', 't.punched_in_at', 't.punched_out_at', 't.notes',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.email) as staff_name"),
            ]);
        return response()->json(['punches' => $rows]);
    }

    // ── Tour bookings (public) ──────────────────────────────────────────

    public function publicTourBook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agency_slug' => ['required', 'string', 'max:80'],
            'centre_id' => ['required', 'integer'],
            'parent_name' => ['required', 'string', 'max:160'],
            'parent_email' => ['required', 'email', 'max:180'],
            'parent_phone' => ['nullable', 'string', 'max:40'],
            'child_age_months' => ['nullable', 'integer', 'min:0', 'max:144'],
            'preferred_start_date' => ['nullable', 'date'],
            'tour_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $agency = DB::table('agencies')
            ->where('slug', $data['agency_slug'])
            ->whereNull('deleted_at')->first();
        if (!$agency) return response()->json(['message' => 'Agency not found'], 404);

        $centre = DB::table('centres')->where('id', $data['centre_id'])
            ->where('agency_id', $agency->id)->whereNull('deleted_at')->first();
        if (!$centre) return response()->json(['message' => 'Centre not in this agency'], 422);

        $id = DB::table('tour_bookings')->insertGetId([
            'agency_id' => $agency->id,
            'centre_id' => (int) $data['centre_id'],
            'parent_name' => $data['parent_name'],
            'parent_email' => $data['parent_email'],
            'parent_phone' => $data['parent_phone'] ?? null,
            'child_age_months' => $data['child_age_months'] ?? null,
            'preferred_start_date' => $data['preferred_start_date'] ?? null,
            'tour_at' => $data['tour_at'],
            'notes' => $data['notes'] ?? null,
            'status' => 'requested',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Notify centre director + agency admins that a new tour was requested
        $staffIds = DB::table('role_assignments')->where('active', true)
            ->where(function ($q) use ($agency, $centre) {
                $q->where(function ($x) use ($agency) {
                    $x->where('role', 'agency_admin')->where('agency_id', $agency->id);
                })->orWhere(function ($x) use ($centre) {
                    $x->where('role', 'centre_director')->where('centre_id', $centre->id);
                });
            })->pluck('user_id')->all();
        if (!empty($staffIds)) {
            $rows = [];
            foreach ($staffIds as $sid) {
                $rows[] = [
                    'user_id' => $sid,
                    'type' => 'tour',
                    'title' => 'New tour booking — ' . $data['parent_name'],
                    'body' => 'Requested tour of ' . $centre->name . ' on ' . \Carbon\Carbon::parse($data['tour_at'])->format('M j, g:i A'),
                    'data' => json_encode(['url' => '/dashboard.html#tours', 'tour_id' => $id]),
                    'created_at' => now(),
                ];
            }
            DB::table('notifications')->insert($rows);
        }

        return response()->json(['id' => $id, 'message' => 'Tour requested. We will be in touch shortly.'], 201);
    }

    public function listTours(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $isAdmin = DB::table('role_assignments')->where('user_id', $userId)
            ->whereIn('role', ['agency_admin', 'platform_admin'])->where('active', true)->exists();
        if (!$isAdmin) {
            return response()->json(['tours' => []]);
        }
        $agencyId = (int) $request->header('X-Active-Agency-Id') ?:
            (int) DB::table('role_assignments')->where('user_id', $userId)
                ->where('role', 'agency_admin')->where('active', true)->value('agency_id');
        $rows = DB::table('tour_bookings as tb')
            ->leftJoin('centres as c', 'c.id', '=', 'tb.centre_id')
            ->where('tb.agency_id', $agencyId)
            ->orderByDesc('tb.tour_at')
            ->limit(200)
            ->get(['tb.*', 'c.name as centre_name']);
        return response()->json(['tours' => $rows]);
    }

    public function updateTour(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:requested,confirmed,completed,no_show,cancelled'],
            'tour_at' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);
        $data['updated_at'] = now();
        DB::table('tour_bookings')->where('id', $id)->update($data);
        return response()->json(['message' => 'Tour updated']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function canSeeChild(Request $request, int $childId): bool
    {
        $userId = $request->user()->id;
        // v22p96: a platform_admin's access is SCOPED to the agency they've
        // switched into (X-Active-Agency-Id) — not every child on the platform.
        // The prior unconditional `return true` leaked care logs / milestones /
        // portfolios across all tenants for a switched super-admin.
        $isPlatform = DB::table('role_assignments')
            ->where('user_id', $userId)->where('role', 'platform_admin')
            ->where('active', true)->exists();
        if ($isPlatform) {
            $activeId = (int) $request->header('X-Active-Agency-Id');
            if (!$activeId) return false;
            return DB::table('children as c')
                ->join('families as f', 'f.id', '=', 'c.family_id')
                ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
                ->where('c.id', $childId)
                ->where('ce.agency_id', $activeId)
                ->exists();
        }

        // Direct: a guardian on the child's family
        $isGuardian = DB::table('guardians as g')
            ->join('children as c', 'c.family_id', '=', 'g.family_id')
            ->where('g.user_id', $userId)
            ->where('c.id', $childId)
            ->exists();
        if ($isGuardian) return true;

        // Staff: anyone with an active role at the child's centre
        $isCentreStaff = DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('role_assignments as ra', function ($j) {
                $j->on('ra.centre_id', '=', 'f.centre_id')->where('ra.active', '=', true);
            })
            ->where('c.id', $childId)
            ->where('ra.user_id', $userId)
            ->exists();
        if ($isCentreStaff) return true;

        // v22p77: agency_admin whose agency owns the child's centre (admins have
        // an agency-level role_assignment, not a centre-level one).
        return DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->join('role_assignments as ra', function ($j) {
                $j->on('ra.agency_id', '=', 'ce.agency_id')->where('ra.active', '=', true);
            })
            ->where('c.id', $childId)
            ->where('ra.user_id', $userId)
            ->whereIn('ra.role', ['agency_admin'])
            ->exists();
    }

    /**
     * Minimal HDLH-flavoured milestone catalog. 4 domains × 4 age bands × a
     * handful of milestones each. Curated for MVP — operators can pad later
     * once we collect feedback.
     */
    private function catalog(): array
    {
        return [
            'infant' => [   // 0–18 mo
                'Belonging' => [
                    ['key' => 'inf_b_smile_caregivers', 'label' => 'Smiles at familiar caregivers'],
                    ['key' => 'inf_b_seeks_comfort', 'label' => 'Seeks comfort when distressed'],
                ],
                'Wellbeing' => [
                    ['key' => 'inf_w_self_feed', 'label' => 'Brings food to mouth independently'],
                    ['key' => 'inf_w_rolls', 'label' => 'Rolls from back to tummy'],
                    ['key' => 'inf_w_sits', 'label' => 'Sits unsupported'],
                ],
                'Engagement' => [
                    ['key' => 'inf_e_object_perm', 'label' => 'Looks for a partially hidden object'],
                    ['key' => 'inf_e_imitates', 'label' => 'Imitates simple gestures'],
                ],
                'Expression' => [
                    ['key' => 'inf_x_babbles', 'label' => 'Babbles consonant-vowel sounds'],
                    ['key' => 'inf_x_first_word', 'label' => 'Says first word'],
                ],
            ],
            'toddler' => [  // 18 mo – 3 yr
                'Belonging' => [
                    ['key' => 'tod_b_says_hi', 'label' => 'Greets familiar adults by name or wave'],
                    ['key' => 'tod_b_parallel_play', 'label' => 'Engages in parallel play'],
                ],
                'Wellbeing' => [
                    ['key' => 'tod_w_walks_steady', 'label' => 'Walks steadily without support'],
                    ['key' => 'tod_w_uses_cup', 'label' => 'Drinks from an open cup'],
                    ['key' => 'tod_w_hand_wash', 'label' => 'Attempts hand-washing routine'],
                ],
                'Engagement' => [
                    ['key' => 'tod_e_sorts_shapes', 'label' => 'Sorts simple shapes'],
                    ['key' => 'tod_e_pretend_play', 'label' => 'Begins simple pretend play'],
                ],
                'Expression' => [
                    ['key' => 'tod_x_2_words', 'label' => 'Combines two words to make a phrase'],
                    ['key' => 'tod_x_names_objects', 'label' => 'Names 5+ familiar objects'],
                ],
            ],
            'preschool' => [ // 3–4
                'Belonging' => [
                    ['key' => 'pre_b_cooperative_play', 'label' => 'Plays cooperatively with one peer'],
                    ['key' => 'pre_b_takes_turns', 'label' => 'Takes turns with adult prompting'],
                ],
                'Wellbeing' => [
                    ['key' => 'pre_w_uses_utensils', 'label' => 'Uses fork and spoon competently'],
                    ['key' => 'pre_w_dresses_self', 'label' => 'Dresses self with simple clothing'],
                    ['key' => 'pre_w_toilet_independent', 'label' => 'Uses the toilet independently'],
                ],
                'Engagement' => [
                    ['key' => 'pre_e_count_to_10', 'label' => 'Counts to 10'],
                    ['key' => 'pre_e_completes_puzzle', 'label' => 'Completes a 6–9 piece puzzle'],
                    ['key' => 'pre_e_sustained_attention', 'label' => 'Maintains focus on an activity for 10+ minutes'],
                ],
                'Expression' => [
                    ['key' => 'pre_x_simple_sentence', 'label' => 'Speaks in 4–5 word sentences'],
                    ['key' => 'pre_x_tells_story', 'label' => 'Retells a simple story'],
                ],
            ],
            'kindergarten' => [ // 4–6
                'Belonging' => [
                    ['key' => 'kin_b_group_play', 'label' => 'Sustains group play and follows shared rules'],
                    ['key' => 'kin_b_resolves_conflict', 'label' => 'Uses words to resolve a peer conflict'],
                ],
                'Wellbeing' => [
                    ['key' => 'kin_w_balance', 'label' => 'Balances on one foot for 10+ seconds'],
                    ['key' => 'kin_w_runs_skips', 'label' => 'Runs, skips, and jumps with control'],
                    ['key' => 'kin_w_self_care', 'label' => 'Manages self-care routine independently'],
                ],
                'Engagement' => [
                    ['key' => 'kin_e_recognize_letters', 'label' => 'Recognises most upper and lower case letters'],
                    ['key' => 'kin_e_count_20', 'label' => 'Counts objects accurately to 20'],
                    ['key' => 'kin_e_writes_name', 'label' => 'Writes own first name'],
                ],
                'Expression' => [
                    ['key' => 'kin_x_full_sentences', 'label' => 'Speaks in complete grammatical sentences'],
                    ['key' => 'kin_x_describes_events', 'label' => 'Describes a past event with detail'],
                ],
            ],
        ];
    }
}
