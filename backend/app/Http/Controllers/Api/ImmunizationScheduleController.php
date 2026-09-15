<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p58 — Immunization "Due At Age" scheduling.
 * Each agency has a per-vaccine + dose schedule expressed in months.
 * Per-child due-status is computed on demand from DOB.
 */
final class ImmunizationScheduleController extends Controller
{
    use ResolvesCentreContext;

    // Ontario / Canada NACI default schedule (months)
    private const DEFAULTS = [
        ['DTaP-IPV-Hib', '1st dose', 2],
        ['DTaP-IPV-Hib', '2nd dose', 4],
        ['DTaP-IPV-Hib', '3rd dose', 6],
        ['Pneumococcal', '1st dose', 2],
        ['Pneumococcal', '2nd dose', 4],
        ['Pneumococcal', '3rd dose', 12],
        ['Rotavirus', '1st dose', 2],
        ['Rotavirus', '2nd dose', 4],
        ['Meningococcal C', '1 dose', 12],
        ['MMR', '1st dose', 12],
        ['Varicella', '1st dose', 15],
        ['DTaP-IPV-Hib', '4th dose (booster)', 18],
        ['MMRV', '2nd dose', 48],
        ['DTaP-IPV', '5th dose (booster)', 60],
    ];

    public function listSchedule(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('immunization_schedule')->where('agency_id', $agencyId)
            ->where('active', 1)
            ->orderBy('due_at_age_months')->orderBy('vaccine')->orderBy('dose_label')->get();
        if ($rows->isEmpty()) {
            $this->seedDefaults($agencyId);
            $rows = DB::table('immunization_schedule')->where('agency_id', $agencyId)->where('active', 1)
                ->orderBy('due_at_age_months')->orderBy('vaccine')->orderBy('dose_label')->get();
        }
        return response()->json(['data' => $rows]);
    }

    public function upsertSchedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => 'nullable|integer',
            'vaccine' => 'required|string|max:80',
            'dose_label' => 'required|string|max:40',
            'due_at_age_months' => 'required|integer|min:0|max:300',
            'is_required' => 'nullable|boolean',
            'display_order' => 'nullable|integer',
            'notes' => 'nullable|string|max:1000',
        ]);
        $agencyId = $this->resolveAgencyId($request);
        $payload = [
            'agency_id' => $agencyId,
            'vaccine' => $data['vaccine'],
            'dose_label' => $data['dose_label'],
            'due_at_age_months' => $data['due_at_age_months'],
            'is_required' => $data['is_required'] ?? 1,
            'display_order' => $data['display_order'] ?? 0,
            'notes' => $data['notes'] ?? null,
            'active' => 1,
            'updated_at' => now(),
        ];
        if (!empty($data['id'])) {
            /* THE ROW MUST BE THIS AGENCY'S.

               removeSchedule has checked this since v22p95; the update beside it never
               did, so a posted id was written straight through -- one agency could edit
               another's vaccine schedule, and the payload even carried its own agency_id,
               which would have MOVED the row. Nothing in the UI could do it, but "the UI
               does not offer it" is not an access control, and the editor is about to
               become a real screen with real buttons.

               Same shape as the delete: platform admins may touch any row, everybody else
               only their own. (Anthony, 2026-09-10) */
            $existing = DB::table('immunization_schedule')->where('id', $data['id'])->first();
            abort_unless($existing, 404);
            abort_unless(
                $this->isPlatformAdminUser($request->user()) || (int) $existing->agency_id === (int) $agencyId,
                403
            );
            /* Never re-home a row on update. agency_id belongs in the INSERT only. */
            unset($payload['agency_id']);
            DB::table('immunization_schedule')->where('id', $data['id'])->update($payload);
            return response()->json(['id' => (int) $data['id'], 'status' => 'updated']);
        }
        $payload['created_at'] = now();
        $id = DB::table('immunization_schedule')->insertGetId($payload);
        return response()->json(['id' => $id], 201);
    }

    public function removeSchedule(Request $request, int $id): JsonResponse
    {
        // SECURITY (v22p95): a schedule row belongs to an agency — only delete
        // rows of the caller's own agency (platform_admin may delete any).
        $row = DB::table('immunization_schedule')->where('id', $id)->first();
        abort_unless($row, 404);
        $agencyId = $this->resolveAgencyId($request);
        abort_unless($this->isPlatformAdminUser($request->user()) || (int) $row->agency_id === (int) $agencyId, 403);
        DB::table('immunization_schedule')->where('id', $id)->update(['active' => 0, 'updated_at' => now()]);
        return response()->json(['status' => 'removed']);
    }

    /**
     * For a child: combine the agency's schedule with their immunization
     * records (or check existing 'immunizations' for matching vaccine/dose)
     * to compute due / overdue / done status.
     */
    public function childStatus(Request $request, int $childId): JsonResponse
    {
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        abort_unless($child, 404);
        // SECURITY (v22p94): only the child's guardians/centre staff (health data).
        abort_unless($this->canAccessChildId($request->user(), $childId), 403);
        $family = DB::table('families')->where('id', $child->family_id)->first();
        $agencyId = (int) DB::table('centres')->where('id', $family->centre_id)->value('agency_id');

        $schedule = DB::table('immunization_schedule')->where('agency_id', $agencyId)
            ->where('active', 1)->orderBy('due_at_age_months')->get();
        if ($schedule->isEmpty()) {
            $this->seedDefaults($agencyId);
            $schedule = DB::table('immunization_schedule')->where('agency_id', $agencyId)->where('active', 1)->get();
        }
        $records = DB::table('immunizations')->where('child_id', $childId)
            ->select('vaccine', 'dose_label', 'administered_on', 'exempt')
            ->get();
        $recordKey = fn ($v, $d) => strtolower(trim($v . '|' . $d));
        $byKey = $records->keyBy(fn ($r) => $recordKey($r->vaccine, $r->dose_label));

        $dob = Carbon::parse($child->date_of_birth);
        $ageMonths = (int) $dob->diffInMonths(Carbon::now());

        $items = $schedule->map(function ($s) use ($byKey, $recordKey, $dob, $ageMonths) {
            $key = $recordKey($s->vaccine, $s->dose_label);
            $rec = $byKey->get($key);
            $dueAt = $dob->copy()->addMonths((int) $s->due_at_age_months);
            $today = Carbon::now()->startOfDay();
            $monthsUntil = (int) $today->diffInMonths($dueAt, false);
            $status = 'pending';
            if ($rec) {
                $status = $rec->exempt ? 'exempt' : 'done';
            } elseif ($monthsUntil < 0) {
                $status = 'overdue';
            } elseif ($monthsUntil < 2) {
                $status = 'due_soon';
            }
            return [
                'vaccine' => $s->vaccine,
                'dose_label' => $s->dose_label,
                'due_at_age_months' => (int) $s->due_at_age_months,
                'due_date' => $dueAt->toDateString(),
                'months_until_due' => $monthsUntil,
                'status' => $status,
                'administered_on' => $rec->administered_on ?? null,
                'is_required' => (bool) $s->is_required,
            ];
        });

        $overdue = $items->where('status', 'overdue')->count();
        $dueSoon = $items->where('status', 'due_soon')->count();
        $done = $items->where('status', 'done')->count();

        /* A RECORD ON FILE IS NOT THE SAME AS A DOSE RECORDED — and the screen has to be
           able to tell them apart.

           This endpoint only ever read the `immunizations` table, so a child whose card
           had been photographed and filed that morning was indistinguishable from one
           whose family had sent nothing in years. The panel headline said, in the staff
           wording, "Nothing has been recorded against these. Upload the card or printout
           if the family has sent one in." — asking for the exact document sitting on the
           Records-on-file list three inches below it, and telling whoever had just spent
           ten minutes uploading it that nothing had happened.

           Anthony, 2026-09-15: "Safia uploaded an immunization record for a child and I
           don't see this updated in the immunization section." She had; it was there; the
           screen led with a red block saying it was not.

           The count of overdue doses deliberately does NOT move. A PDF nobody has read is
           not a recorded dose, and quietly clearing a compliance flag because a file
           arrived would be worse than the bug. What changes is the ASK: the work still to
           be done is transcribing the record, not chasing the family for it again.

           Read through recordsFor() rather than a second query, so "a record is on file"
           has one definition here, in the reminder job, and on the records list itself. */
        $filed = \App\Http\Controllers\Api\ParentImmunizationRecordController::recordsFor([$childId]);
        $latestFiled = $filed[0] ?? null;   // recordsFor() orders newest first

        return response()->json([
            'child_id' => $childId,
            'child_name' => $child->first_name . ' ' . $child->last_name,
            'age_months' => $ageMonths,
            'items' => $items,
            'summary' => [
                'overdue' => $overdue,
                'due_soon' => $dueSoon,
                'done' => $done,
                'total_applicable' => $items->where('due_at_age_months', '<=', $ageMonths + 6)->count(),
            ],
            'record_on_file' => $latestFiled ? [
                'id' => $latestFiled['id'],
                'title' => $latestFiled['title'],
                'uploaded_at' => $latestFiled['uploaded_at'],
                'uploaded_by' => $latestFiled['uploaded_by'],
                'uploaded_by_parent' => $latestFiled['uploaded_by_parent'],
                'count' => count($filed),
            ] : null,
        ]);
    }

    public function agencyDueReport(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $children = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
            ->whereNull('ch.deleted_at')
            ->where('ch.enrollment_status', 'enrolled')
            ->select('ch.id', 'ch.first_name', 'ch.last_name', 'ch.date_of_birth',
                'f.family_name', 'c.name as centre_name')
            ->get();
        $rows = $children->map(function ($ch) use ($request) {
            $resp = $this->childStatus($request, (int) $ch->id);
            $payload = json_decode($resp->getContent(), true);
            return [
                'child_id' => $ch->id,
                'child_name' => $ch->first_name . ' ' . $ch->last_name,
                'family_name' => $ch->family_name,
                'centre_name' => $ch->centre_name,
                'overdue' => $payload['summary']['overdue'],
                'due_soon' => $payload['summary']['due_soon'],
                /* So the agency list can tell "nothing has ever arrived" from "it arrived
                   and still needs typing up" — two different jobs for two different
                   people, and they looked identical here. */
                'record_on_file' => ! empty($payload['record_on_file']),
                'record_filed_at' => $payload['record_on_file']['uploaded_at'] ?? null,
            ];
        })->sortByDesc('overdue')->values();
        return response()->json(['data' => $rows]);
    }

    private function seedDefaults(int $agencyId): void
    {
        $rows = [];
        foreach (self::DEFAULTS as $i => [$v, $d, $m]) {
            $rows[] = [
                'agency_id' => $agencyId,
                'vaccine' => $v, 'dose_label' => $d,
                'due_at_age_months' => $m,
                'is_required' => 1, 'display_order' => $i + 1, 'active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('immunization_schedule')->insertOrIgnore($rows);
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        // SECURITY (v22p94): only honour the header if the user is platform_admin
        // or holds an active role for that exact agency (else fall back below).
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->where(function ($w) use ($activeId) { $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin'); })->exists()) return $activeId;
        // SECURITY (v22p98): a platform_admin with no valid SELECTED agency must NOT
        // fall through to their first role's agency (iLearn) — require an explicit
        // choice, else agency-scoped data leaked to a super-admin on a header-less call.
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }
}
