<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v22p59 — Multi-day attendance pattern per child.
 * Used by ratio compliance + tuition calc + parent-side preview.
 */
final class AttendancePatternController extends Controller
{
    use ResolvesCentreContext;

    public function get(Request $request, int $childId): JsonResponse
    {
        $this->assertChildAccess($request, $childId);
        $row = DB::table('attendance_patterns')->where('child_id', $childId)
            ->where(function ($q) {
                $q->whereNull('effective_until')->orWhere('effective_until', '>=', now());
            })
            ->orderByDesc('effective_from')->first();
        return response()->json(['data' => $row]);
    }

    public function set(Request $request, int $childId): JsonResponse
    {
        $this->assertChildAccess($request, $childId);
        // A day is a ROTATION now, not a yes/no: full day, mornings, afternoons,
        // before school, after school, or before and after. Booleans are still
        // accepted so an older client (and the APK, which updates on its own
        // schedule) keeps working — true becomes a full day.
        $rot = 'nullable|in:' . implode(',', self::ROTATIONS);
        $data = $request->validate([
            'monday' => $rot, 'tuesday' => $rot, 'wednesday' => $rot,
            'thursday' => $rot, 'friday' => $rot, 'saturday' => $rot, 'sunday' => $rot,
            'room_id' => 'nullable|integer',
            'active' => 'nullable|boolean',
            'effective_from' => 'required|date',
            'notes' => 'nullable|string|max:500',
        ]);
        foreach (self::DAYS as $d) {
            $data[$d] = $this->normaliseRotation($request->input($d));
        }
        // Close the prior open pattern
        DB::table('attendance_patterns')->where('child_id', $childId)
            ->whereNull('effective_until')
            ->update(['effective_until' => $data['effective_from'], 'updated_at' => now()]);
        $row = [
            'child_id' => $childId,
            'effective_from' => $data['effective_from'],
            'notes' => $data['notes'] ?? null,
            'updated_at' => now(),
        ];
        foreach (self::DAYS as $d) $row[$d] = $data[$d];
        if (Schema::hasColumn('attendance_patterns', 'room_id')) $row['room_id'] = $data['room_id'] ?? null;
        if (Schema::hasColumn('attendance_patterns', 'active')) $row['active'] = $request->boolean('active', true);
        if (Schema::hasColumn('attendance_patterns', 'updated_by_id')) $row['updated_by_id'] = $request->user()->id ?? null;
        if (Schema::hasColumn('attendance_patterns', 'created_at')) $row['created_at'] = now();
        $id = DB::table('attendance_patterns')->insertGetId($row);

        /* A PARENT CHANGING THE DAYS IS NEWS TO THE OFFICE. These days drive ratios,
           staffing and the check-in reminders, so a quiet change is the kind that bites
           later. Staff edits are not mailed: they are already in the room where this is
           decided, and a director's bulk "Mon-Fri" across a roster would fire dozens. */
        if ($this->actorIsGuardianOnly($request)) {
            $this->notifyPatternChange($request, $childId, $data);
        }

        return response()->json(['id' => $id, 'status' => 'saved']);
    }

    /** Is the caller a parent rather than staff? Roles on the account, not a preview. */
    private function actorIsGuardianOnly(Request $request): bool
    {
        $roles = DB::table('role_assignments')->where('user_id', $request->user()->id ?? 0)
            ->where('active', true)->pluck('role')->all();

        return in_array('guardian', $roles, true)
            && ! array_intersect($roles, ['educator', 'centre_director', 'agency_admin', 'platform_admin']);
    }

    /**
     * Tell the centre's directors and admins that a parent changed the expected days, and
     * send the parent the same message so they have a record of what they submitted.
     */
    private function notifyPatternChange(Request $request, int $childId, array $data): void
    {
        try {
            $child = DB::table('children as ch')
                ->join('families as f', 'f.id', '=', 'ch.family_id')
                ->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('ch.id', $childId)
                ->first(['ch.first_name', 'ch.preferred_name', 'ch.last_name',
                         'c.id as centre_id', 'c.name as centre_name', 'c.agency_id']);
            if (! $child) { return; }

            $childName = trim(($child->preferred_name ?: $child->first_name) . ' ' . ($child->last_name ?? ''));
            $actor = $request->user();
            $agencyId = (int) $child->agency_id;

            // The week, in the order a person reads it, with the rotation spelled out.
            /* ROTATION_LABELS is a LIST of ['key','label','short'], not a map — look the
               rotation up by its key or the email prints raw values like "bna". */
            $rotLabel = function ($v) {
                foreach (self::ROTATION_LABELS as $r) {
                    if (($r['key'] ?? null) === $v) { return $r['label'] ?? $v; }
                }
                return $v;
            };
            $rows = collect(self::DAYS)->map(function ($d) use ($data, $rotLabel) {
                $v = $data[$d] ?? null;
                $text = $v ? $rotLabel($v) : 'Not attending';
                $muted = $v ? '#0F172A' : '#94A3B8';

                return '<tr><td style="padding:7px 0;border-bottom:1px solid #F1F5F9;color:#64748B;'
                     . 'font-size:13px;">' . e(ucfirst($d)) . '</td>'
                     . '<td style="padding:7px 0;border-bottom:1px solid #F1F5F9;text-align:right;'
                     . 'font-size:14px;font-weight:600;color:' . $muted . ';">' . e($text) . '</td></tr>';
            })->implode('');
            $labels = '<table style="width:100%;border-collapse:collapse;margin:14px 0;">' . $rows . '</table>';

            $body = '<p>' . e($actor->first_name ?? 'A parent') . ' updated the days '
                  . e($childName) . ' is expected at ' . e($child->centre_name) . '.</p>'
                  . '<p style="color:#64748B;font-size:13px;">Effective from '
                  . e($data['effective_from']) . '.</p>'
                  . $labels
                  . ($data['notes'] ?? null ? '<p><b>Note from the parent:</b> ' . e($data['notes']) . '</p>' : '')
                  . '<p style="color:#64748B;font-size:13px;">If this is not right, the centre can '
                  . 'adjust it from Attendance in the portal.</p>';

            $html = \App\Services\EmailTemplate::wrap($agencyId, $body, [
                'title' => 'Attendance days updated',
                'preheader' => $childName . ' — expected days changed',
            ]);
            $subject = 'Attendance days updated — ' . $childName;

            /* Directors and agency admins who own this centre. Agency-wide roles carry no
               centre_id but still cover it, which is why the second branch exists. */
            $staff = DB::table('role_assignments as ra')
                ->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.active', true)
                ->whereIn('ra.role', ['centre_director', 'agency_admin'])
                ->where(function ($q) use ($child, $agencyId) {
                    $q->where('ra.centre_id', $child->centre_id)
                      ->orWhere(function ($q2) use ($agencyId) {
                          $q2->whereNull('ra.centre_id')->where('ra.agency_id', $agencyId);
                      });
                })
                ->whereNotNull('u.email')->whereNull('u.deleted_at')
                ->pluck('u.email')->unique();

            // The parent's own copy — same body, so the two can never say different things.
            $recipients = $staff->push($actor->email ?? null)->filter()->unique()->values()->all();
            if (! $recipients) { return; }

            foreach ($recipients as $to) {
                dispatch(function () use ($agencyId, $to, $html, $subject) {
                    \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $subject) {
                            $m->to($to)->from('noreply@kiddietrac.com', 'KiddieTrac')->subject($subject);
                        });
                })->onQueue('mail');
            }
        } catch (\Throwable $e) {
            // Saving the pattern must succeed even if telling people fails.
            \Illuminate\Support\Facades\Log::error('Attendance pattern notice failed', [
                'child' => $childId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** Get an overview of every enrolled child's expected days this week. */
    /**
     * GET /parent/attendance/weekly-overview — the caller's OWN children only.
     *
     * The parent nav has always offered this ("Your child's attendance history"), but the
     * screen called the staff route, which is agency-wide and role-gated — so every
     * guardian who tapped it got a 403 and a broken panel (seven in the audit log; all of
     * them guardians). The guard was right; the client was pointed at the wrong door.
     *
     * Same response shape and same rotation vocabulary as the staff view, so the screen
     * renders identically — only the child selection differs.
     */
    public function parentWeeklyOverview(Request $request): JsonResponse
    {
        $famId = DB::table('guardians')->where('user_id', $request->user()->id)->value('family_id');
        // Fail CLOSED: no guardian record must never mean "no filter".
        $childIds = $famId
            ? DB::table('children')->where('family_id', $famId)->whereNull('deleted_at')->pluck('id')->all()
            : [];
        if (! $childIds) { $childIds = [0]; }

        return $this->overviewResponse($this->rowsFor(null, $childIds));
    }

    public function weeklyOverview(Request $request): JsonResponse
    {
        // SECURITY (v22p96): resolve the active agency securely — the prior raw
        // header let an agency_admin of A forge X-Active-Agency-Id=B and read
        // agency B's full child roster + attendance patterns.
        $agencyId = (int) $this->resolveAgencyId($request);

        return $this->overviewResponse($this->rowsFor($agencyId, null));
    }

    /**
     * The rows behind both overviews. ONE query, so the staff and parent views can never
     * drift: either an agency (staff) or an explicit child list (a parent's own), never
     * neither — passing neither would select the whole table.
     */
    private function rowsFor(?int $agencyId, ?array $childIds)
    {
        if ($agencyId === null && empty($childIds)) {
            throw new \InvalidArgumentException('rowsFor needs an agency or a child list');
        }

        $children = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->leftJoin('attendance_patterns as ap', function ($j) {
                $j->on('ap.child_id', '=', 'ch.id')
                  ->whereNull('ap.effective_until');
            })
            ->leftJoin('rooms as rm', 'rm.id', '=', 'ap.room_id')
            ->when($agencyId !== null, fn ($q) => $q->where('c.agency_id', $agencyId))
            ->when($childIds !== null, fn ($q) => $q->whereIn('ch.id', $childIds))
            ->whereNull('ch.deleted_at')
            // EVERY child in the agency, not only those whose status is exactly
            // 'enrolled'. Dropping the others is why this screen "did not pick up
            // all the kids": a child starting next month, on hold or waitlisted
            // still has a pattern to plan around. The status travels with each row
            // so the reader can filter — the screen decides what to show, not a
            // WHERE clause nobody could see.
            ->select('ch.id', 'ch.first_name', 'ch.last_name', 'ch.enrollment_status',
                'c.id as centre_id', 'c.name as centre_name',
                'rm.id as room_id', 'rm.name as room_name',
                'ap.id as pattern_id', 'ap.effective_from', 'ap.notes',
                'ap.monday', 'ap.tuesday', 'ap.wednesday', 'ap.thursday', 'ap.friday',
                'ap.saturday', 'ap.sunday')
            ->orderBy('c.name')->orderBy('ch.last_name')->get();

        // Normalise on the way OUT as well as in: rows written before rotations
        // existed hold '1', and a client should never have to know that.
        $children->transform(function ($r) {
            foreach (self::DAYS as $d) $r->$d = $this->normaliseRotation($r->$d);
            $r->has_pattern = !empty($r->pattern_id);
            $r->active = !empty($r->pattern_id);
            return $r;
        });

        return $children;
    }

    /** The envelope both overviews return, so the client sees one shape. */
    private function overviewResponse($children): JsonResponse
    {
        return response()->json([
            'data' => $children,
            // The vocabulary, sent with the data so the UI never hard-codes a list
            // that then drifts from what the API accepts.
            'rotations' => self::ROTATION_LABELS,
            'statuses' => $children->pluck('enrollment_status')->filter()->unique()->values(),
        ]);
    }

    /** The rotations a day can hold. Extend here and the API, UI and validation all follow. */
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    private const ROTATIONS = ['full', 'am', 'pm', 'before', 'after', 'bna'];
    private const ROTATION_LABELS = [
        ['key' => 'full',   'label' => 'Full day',            'short' => 'Full'],
        ['key' => 'am',     'label' => 'Morning only',        'short' => 'AM'],
        ['key' => 'pm',     'label' => 'Afternoon only',      'short' => 'PM'],
        ['key' => 'before', 'label' => 'Before school',       'short' => 'Before'],
        ['key' => 'after',  'label' => 'After school',        'short' => 'After'],
        ['key' => 'bna',    'label' => 'Before and after school', 'short' => 'B&A'],
    ];

    /**
     * One value in, one meaning out. Accepts the legacy booleans ('1'/1/true =
     * a full day) alongside the rotation codes, so older clients and rows written
     * before this existed both keep working.
     */
    private function normaliseRotation($v): ?string
    {
        if ($v === null || $v === '' || $v === false || $v === 0 || $v === '0') return null;
        if ($v === true || $v === 1 || $v === '1') return 'full';
        $v = strtolower(trim((string) $v));
        return in_array($v, self::ROTATIONS, true) ? $v : null;
    }

    private function assertChildAccess(Request $request, int $childId): void
    {
        // SECURITY (v22p96): the prior blanket `$isStaff` accepted any active staff
        // role anywhere, so any agency's staff — or a switched platform_admin —
        // could read/write any child's attendance pattern. Now guardian of the
        // child, staff of its centre, or a platform_admin scoped to its agency.
        abort_unless($this->canAccessChildScoped($request, $childId), 403);
    }
}
