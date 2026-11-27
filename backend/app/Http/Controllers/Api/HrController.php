<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Services\XlsxExportService;
use App\Support\AgencyRole;
use App\Support\AgencyTime;
use App\Support\Audit;
use App\Support\CentreScope;
use App\Support\Features;
use App\Support\HrAttendance;
use App\Support\HrLeave;
use App\Support\Live;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * KIDDIETRAC HR, PHASE 1 (2026-11-27): leave types and balances, the employee file, the absence report and
 * "who's off". Sold as the HR add-on (feature `hr`, EnforcePlanFeatures::MAP owns hr/ and exports/hr-absence).
 *
 * WHO SEES WHAT (fails closed, always inside the ACTIVE agency):
 *  - agency admin / platform admin (in the agency they chose): every staff member of the agency.
 *  - centre director: the staff placed at their own centres (CentreScope -> HrLeave::staffIds). Anyone else = 404.
 *  - everyone on staff: their own balances and ledger (/hr/me), only for types shown to staff.
 *  - SIN: agency admins and platform admins only, never in a list, export or search; each reveal is audited.
 *  - Pay rate: only for the callers the payroll routes admit (admins + directors for their own centres, payroll on).
 */
final class HrController extends Controller
{
    use ResolvesCentreContext;

    private const ADMIN = ['agency_admin', 'platform_admin'];

    // ── access ────────────────────────────────────────────────────────────────────────

    private function agency(Request $request): int
    {
        $aid = (int) ($this->resolveAgencyId($request) ?: 0);
        abort_unless($aid > 0, 400, 'Select an agency first.');
        abort_unless(HrLeave::on($aid), 403, 'HR is not included in your agency\'s plan.');

        return $aid;
    }

    private function isAdmin(Request $request): bool
    {
        return (bool) array_intersect(AgencyRole::names($request), self::ADMIN);
    }

    private function isManager(Request $request): bool
    {
        return $this->isAdmin($request) || in_array('centre_director', AgencyRole::names($request), true);
    }

    private function needAdmin(Request $request): void
    {
        abort_unless($this->isAdmin($request), 403, 'Only an agency admin can change HR settings.');
    }

    /** The staff this caller manages. @return int[] */
    private function people(Request $request, int $aid): array
    {
        abort_unless($this->isManager($request), 403, 'Directors and admins only.');
        $scope = CentreScope::ids($request, $aid);

        return HrLeave::staffIds($aid, $scope);
    }

    private function needPerson(Request $request, int $aid, int $userId): void
    {
        abort_unless(in_array($userId, $this->people($request, $aid), true), 404, 'That person was not found.');
    }

    private function name(?object $u): string
    {
        return $u ? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) : '';
    }

    private function note(int $aid, string $action, string $type, int $id, array $payload = []): void
    {
        try {
            Audit::write(['user_id' => optional(request()->user())->id, 'agency_id' => $aid, 'action' => $action,
                'entity_type' => $type, 'entity_id' => $id, 'payload' => json_encode($payload)]);
        } catch (\Throwable $e) {
        }
    }

    // ── my own view ───────────────────────────────────────────────────────────────────

    /** GET /hr/me — my balances (types shown to staff), and my file's plain facts. */
    public function me(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $me = (int) $request->user()->id;
        $file = HrLeave::file($aid, $me);
        $s = HrLeave::settings($aid);

        return response()->json([
            'balances' => HrLeave::summary($aid, $me, true),
            'types' => HrLeave::types($aid)->filter(fn ($t) => $t->visible_to_staff)->map(fn ($t) => [
                'id' => (int) $t->id, 'name' => $t->name, 'colour' => $t->colour, 'unit' => $t->unit, 'paid' => (bool) $t->paid,
                'needs_approval' => (bool) $t->needs_approval, 'maps_request_type' => $t->maps_request_type])->values(),
            'allow_negative' => (bool) $s['allow_negative'],
            'hours_per_day' => (float) $s['hours_per_day'],
            // Holidays and closures on my centre calendar, so the request form shows the same amount the server books.
            'closed' => HrLeave::closedDays($aid, $me, null, Carbon::parse(AgencyTime::today($aid))->subDays(60)->toDateString(),
                Carbon::parse(AgencyTime::today($aid))->addDays(400)->toDateString()),
            'file' => $file ? ['job_title' => $file->job_title, 'employment_type' => $file->employment_type, 'status' => $file->status] : null,
        ]);
    }

    // ── setup: settings, leave types, rules ───────────────────────────────────────────

    /** GET /hr/setup */
    public function setup(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        abort_unless($this->isManager($request), 403, 'Directors and admins only.');
        $types = HrLeave::types($aid, true);
        $rules = DB::table('hr_leave_rules')->where('agency_id', $aid)->orderBy('min_years')->get()->groupBy('leave_type_id');
        $used = DB::table('hr_leave_ledger')->where('agency_id', $aid)->groupBy('leave_type_id')->selectRaw('leave_type_id, COUNT(DISTINCT user_id) n')->pluck('n', 'leave_type_id');

        return response()->json([
            'settings' => HrLeave::settings($aid),
            'types' => $types->map(fn ($t) => (array) $t + ['rules' => array_values(($rules[$t->id] ?? collect())->all()), 'people' => (int) ($used[$t->id] ?? 0)])->values(),
            'employment_types' => HrLeave::EMPLOYMENT,
            'request_types' => HrLeave::REQUEST_TYPES,
            'can_manage' => $this->isAdmin($request),
            'example_loaded' => $types->contains(fn ($t) => (bool) $t->is_example),
        ]);
    }

    /** PUT /hr/settings */
    public function saveSettings(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $this->needAdmin($request);
        $d = $request->validate([
            'leave_year' => 'required|in:fixed,anniversary',
            'leave_year_start' => ['required', 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/'],
            'allow_negative' => 'required|boolean',
            'hours_per_day' => 'required|numeric|min:1|max:24',
            'short_absence.max_days' => 'required|numeric|min:0.5|max:10',
            'short_absence.count' => 'required|integer|min:2|max:50',
            'short_absence.months' => 'required|integer|min:1|max:24',
        ]);
        $out = HrLeave::saveSettings($aid, [
            'leave_year' => $d['leave_year'], 'leave_year_start' => $d['leave_year_start'], 'allow_negative' => (bool) $d['allow_negative'],
            'hours_per_day' => (float) $d['hours_per_day'],
            'short_absence' => ['max_days' => (float) $d['short_absence']['max_days'], 'count' => (int) $d['short_absence']['count'], 'months' => (int) $d['short_absence']['months']],
        ]);
        $this->note($aid, 'hr.settings_updated', 'agency', $aid, ['settings' => $out]);

        return response()->json(['settings' => $out]);
    }

    private function typeRules(): array
    {
        return [
            'name' => 'required|string|max:80',
            'colour' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'paid' => 'required|boolean',
            'unit' => 'required|in:days,hours',
            'visible_to_staff' => 'required|boolean',
            'needs_approval' => 'required|boolean',
            'maps_request_type' => 'nullable|in:' . implode(',', array_keys(HrLeave::REQUEST_TYPES)),
            'accrual' => 'required|in:' . implode(',', HrLeave::ACCRUALS),
            'periods_per_year' => 'nullable|required_if:accrual,pay_period|in:12,24,26,52',
            'hours_per_unit' => 'nullable|required_if:accrual,hours_worked|numeric|min:1|max:2000',
            'carry_mode' => 'required|in:' . implode(',', HrLeave::CARRY),
            'carry_cap' => 'nullable|required_if:carry_mode,capped|numeric|min:0|max:1000',
            'carry_expiry_months' => 'nullable|integer|min:1|max:24',
            'sort' => 'nullable|integer|min:0|max:999',
            'payout_on_leaving' => 'sometimes|nullable|boolean',   // HR Phase 4 batch 5: null = default (vacation + overtime bank)
        ];
    }

    private function typeRow(array $d): array
    {
        return [
            'name' => trim($d['name']), 'colour' => strtoupper($d['colour']), 'paid' => (bool) $d['paid'], 'unit' => $d['unit'],
            'visible_to_staff' => (bool) $d['visible_to_staff'], 'needs_approval' => (bool) $d['needs_approval'],
            'maps_request_type' => $d['maps_request_type'] ?? null, 'accrual' => $d['accrual'],
            'periods_per_year' => $d['accrual'] === 'pay_period' ? (int) $d['periods_per_year'] : null,
            'hours_per_unit' => $d['accrual'] === 'hours_worked' ? (float) $d['hours_per_unit'] : null,
            'carry_mode' => $d['carry_mode'], 'carry_cap' => $d['carry_mode'] === 'capped' ? (float) $d['carry_cap'] : null,
            'carry_expiry_months' => $d['carry_mode'] === 'none' ? null : ($d['carry_expiry_months'] ?? null),
            'sort' => (int) ($d['sort'] ?? 0), 'updated_at' => now(),
        ] + (array_key_exists('payout_on_leaving', $d) ? ['payout_on_leaving' => $d['payout_on_leaving'] === null ? null : (bool) $d['payout_on_leaving']] : []);
    }

    /** POST /hr/leave-types */
    public function createType(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $this->needAdmin($request);
        $d = $request->validate($this->typeRules());
        abort_if(DB::table('hr_leave_types')->where('agency_id', $aid)->where('name', trim($d['name']))->exists(), 422, 'There is already a leave type with that name.');
        $id = (int) DB::table('hr_leave_types')->insertGetId($this->typeRow($d) + ['agency_id' => $aid, 'created_by_id' => $request->user()->id, 'created_at' => now()]);
        $this->note($aid, 'hr.leave_type_created', 'hr_leave_type', $id, ['name' => $d['name']]);

        return response()->json(['id' => $id], 201);
    }

    private function ownType(int $aid, int $id): object
    {
        $t = DB::table('hr_leave_types')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($t, 404, 'That leave type was not found.');

        return $t;
    }

    /** PATCH /hr/leave-types/{id} */
    public function updateType(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $this->needAdmin($request);
        $t = $this->ownType($aid, $id);
        $d = $request->validate($this->typeRules());
        abort_if(DB::table('hr_leave_types')->where('agency_id', $aid)->where('name', trim($d['name']))->where('id', '!=', $id)->exists(), 422, 'There is already a leave type with that name.');
        // A unit cannot change once balances exist: 3 "days" would silently become 3 hours.
        abort_if($t->unit !== $d['unit'] && DB::table('hr_leave_ledger')->where('leave_type_id', $id)->exists(), 422,
            'People already have balances in ' . $t->unit . '. Add a new leave type for ' . $d['unit'] . ' instead.');
        DB::table('hr_leave_types')->where('id', $id)->update($this->typeRow($d));
        $this->note($aid, 'hr.leave_type_updated', 'hr_leave_type', $id, ['name' => $d['name']]);

        return response()->json(['ok' => true]);
    }

    /** POST /hr/leave-types/{id}/archive and /restore */
    public function archiveType(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $this->needAdmin($request);
        $this->ownType($aid, $id);
        $restore = str_ends_with($request->path(), '/restore');
        DB::table('hr_leave_types')->where('id', $id)->update(['archived_at' => $restore ? null : now(), 'updated_at' => now()]);
        $this->note($aid, $restore ? 'hr.leave_type_restored' : 'hr.leave_type_archived', 'hr_leave_type', $id);

        return response()->json(['ok' => true]);
    }

    /** PUT /hr/leave-types/{id}/rules — replaces the type's entitlement rules (configuration, not balances). */
    public function saveRules(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $this->needAdmin($request);
        $this->ownType($aid, $id);
        $d = $request->validate([
            'rules' => 'present|array|max:30',
            'rules.*.employment_type' => 'nullable|in:' . implode(',', array_keys(HrLeave::EMPLOYMENT)),
            'rules.*.min_years' => 'required|numeric|min:0|max:60',
            'rules.*.amount' => 'required|numeric|min:0|max:2000',
        ]);
        DB::transaction(function () use ($aid, $id, $d) {
            DB::table('hr_leave_rules')->where('leave_type_id', $id)->delete();
            foreach ($d['rules'] as $r) {
                DB::table('hr_leave_rules')->insert(['agency_id' => $aid, 'leave_type_id' => $id, 'employment_type' => $r['employment_type'] ?? null,
                    'min_years' => (float) $r['min_years'], 'amount' => (float) $r['amount'], 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->note($aid, 'hr.leave_rules_saved', 'hr_leave_type', $id, ['rules' => count($d['rules'])]);

        return response()->json(['ok' => true, 'rules' => count($d['rules'])]);
    }

    /** POST /hr/leave-types/example — the labelled example set, never applied by itself. Skips names already used. */
    public function loadExample(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $this->needAdmin($request);
        $added = [];
        DB::transaction(function () use ($aid, $request, &$added) {
            $sort = (int) DB::table('hr_leave_types')->where('agency_id', $aid)->max('sort');
            foreach (HrLeave::EXAMPLE_SET as $e) {
                if (DB::table('hr_leave_types')->where('agency_id', $aid)->where('name', $e['name'])->exists()) {
                    continue;
                }
                $rules = $e['rules'];
                unset($e['rules']);
                $e += ['periods_per_year' => null, 'hours_per_unit' => null, 'carry_cap' => null, 'carry_expiry_months' => null];
                $id = (int) DB::table('hr_leave_types')->insertGetId($e + ['agency_id' => $aid, 'is_example' => 1, 'sort' => ++$sort,
                    'created_by_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                foreach ($rules as $r) {
                    DB::table('hr_leave_rules')->insert($r + ['agency_id' => $aid, 'leave_type_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
                }
                $added[] = $e['name'];
            }
        });
        $this->note($aid, 'hr.example_loaded', 'agency', $aid, ['added' => $added]);

        return response()->json(['added' => $added]);
    }

    /** POST /hr/accrue — run this agency's grants and accruals now (the nightly job does the same). */
    public function accrue(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $this->needAdmin($request);
        $r = HrLeave::accrueAgency($aid);
        $this->note($aid, 'hr.accruals_run', 'agency', $aid, $r);

        return response()->json($r);
    }

    /**
     * POST /hr/leave-types/{id}/count-approved — count this leave year's already-approved requests of the mapped type
     * against balances (requests from before the agency set HR up). Writes ledger rows only; requests are untouched.
     */
    public function countApproved(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $this->needAdmin($request);
        $t = $this->ownType($aid, $id);
        $s = HrLeave::settings($aid);
        $today = AgencyTime::today($aid);
        $n = 0;
        $q = DB::table('time_off_requests')->where('agency_id', $aid)->where('status', 'approved')
            ->where(fn ($w) => $w->where('leave_type_id', $id)->orWhere(fn ($o) => $o->whereNull('leave_type_id')->where('request_type', (string) $t->maps_request_type)))
            ->whereIn('user_id', HrLeave::staffIds($aid) ?: [0]);
        foreach ($q->get() as $r) {
            $ys = HrLeave::yearStart($aid, (int) $r->user_id, $today, $s);
            $mapped = HrLeave::typeForRequest($r);
            if (substr((string) $r->start_at, 0, 10) < $ys || ! $mapped || (int) $mapped->id !== $id) {
                continue;
            }
            $before = DB::table('hr_leave_ledger')->where('time_off_request_id', $r->id)->count();
            HrLeave::syncRequest($r, (int) $request->user()->id);
            $n += DB::table('hr_leave_ledger')->where('time_off_request_id', $r->id)->count() - $before;
        }
        $this->note($aid, 'hr.approved_counted', 'hr_leave_type', $id, ['rows' => $n]);

        return response()->json(['rows' => $n]);
    }

    // ── people and the employee file ─────────────────────────────────────────────────

    /** GET /hr/people — the staff this caller manages, with their file. Never a SIN. */
    public function peopleList(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $ids = $this->people($request, $aid);
        $files = DB::table('hr_employee_files')->where('agency_id', $aid)->whereIn('user_id', $ids ?: [0])->get()->keyBy('user_id');
        $centres = DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id');
        $users = DB::table('users')->whereIn('id', $ids ?: [0])->get(['id', 'first_name', 'last_name', 'email', 'photo_url', 'hire_date', 'sex'])->keyBy('id');
        $roles = DB::table('role_assignments')->where('active', 1)->whereIn('user_id', $ids ?: [0])->whereIn('role', HrLeave::STAFF_ROLES)
            ->where(fn ($w) => $w->where('agency_id', $aid)->orWhereIn('centre_id', Live::centreIds($aid) ?: [0]))
            ->get(['user_id', 'role', 'centre_id'])->groupBy('user_id');
        $open = array_flip(Live::centreIds($aid));
        $out = [];
        foreach ($ids as $id) {
            $u = $users[$id] ?? null;
            if (! $u) {
                continue;
            }
            $f = $files[$id] ?? null;
            $rs = $roles[$id] ?? collect();
            $cids = $rs->pluck('centre_id')->filter(fn ($c) => $c && isset($open[(int) $c]))->map(fn ($c) => (int) $c)->unique()->values()->all();
            $primary = $f && $f->primary_centre_id && isset($open[(int) $f->primary_centre_id]) ? (int) $f->primary_centre_id : ($cids[0] ?? null);
            $mgr = $f && $f->manager_user_id ? DB::table('users')->where('id', $f->manager_user_id)->first(['first_name', 'last_name']) : null;
            $out[] = ['id' => (int) $id, 'name' => $this->name($u), 'first_name' => $u->first_name, 'last_name' => $u->last_name,
                'photo_url' => $u->photo_url, 'sex' => $u->sex, 'email' => $u->email,
                'roles' => $rs->pluck('role')->unique()->values(), 'job_title' => $f->job_title ?? null, 'employment_type' => $f->employment_type ?? null,
                'status' => $f->status ?? 'active', 'start_date' => $u->hire_date ? substr((string) $u->hire_date, 0, 10) : null,
                'primary_centre_id' => $primary, 'centre_name' => $primary ? ($centres[$primary] ?? null) : null,
                'manager_name' => $mgr ? $this->name($mgr) : null];
        }
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return response()->json(['people' => $out, 'can_manage_setup' => $this->isAdmin($request),
            'centres' => Live::centres(DB::table('centres'), 'centres')->where('agency_id', $aid)
                ->when(($sc = CentreScope::ids($request, $aid)) !== null, fn ($q) => $q->whereIn('id', $sc ?: [0]))
                ->orderBy('name')->get(['id', 'name']),
            'employment_types' => HrLeave::EMPLOYMENT, 'statuses' => HrLeave::STATUSES]);
    }

    private function canSeePay(Request $request, int $aid): bool
    {
        // The callers /admin/payroll admits (role:centre_director,agency_admin,platform_admin + the payroll feature);
        // a director is already limited to their own centres' people by needPerson().
        return $this->isManager($request) && Features::on($aid, 'payroll');
    }

    /** GET /hr/people/{id} — the HR profile: what KiddieTrac already knows, gathered, with links to each screen. */
    public function profile(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $this->needPerson($request, $aid, $id);
        $u = DB::table('users')->where('id', $id)->first();
        $f = HrLeave::file($aid, $id);
        $open = Live::centreIds($aid);
        $centres = DB::table('centres')->whereIn('id', $open ?: [0])->pluck('name', 'id');
        $roleRows = DB::table('role_assignments')->where('user_id', $id)->where('active', 1)->whereIn('role', HrLeave::STAFF_ROLES)
            ->where(fn ($w) => $w->where('agency_id', $aid)->orWhereIn('centre_id', $open ?: [0]))->get(['role', 'centre_id']);
        $prof = DB::table('user_profiles')->where('user_id', $id)->first();
        $today = AgencyTime::today($aid);
        $soon = Carbon::parse($today)->addDays(60)->toDateString();
        $certs = DB::table('staff_certifications')->where('user_id', $id)->where('active', 1)
            ->where(fn ($w) => $w->where('agency_id', $aid)->orWhereNull('agency_id'))->orderBy('expires_at')
            ->get(['id', 'title', 'cert_type', 'issued_at', 'expires_at', 'verified_at'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'title' => $c->title ?: $c->cert_type, 'issued_at' => $c->issued_at ? substr((string) $c->issued_at, 0, 10) : null,
                'expires_at' => $c->expires_at ? substr((string) $c->expires_at, 0, 10) : null, 'verified' => (bool) $c->verified_at,
                'state' => ! $c->expires_at ? 'ok' : (substr((string) $c->expires_at, 0, 10) < $today ? 'expired' : (substr((string) $c->expires_at, 0, 10) <= $soon ? 'soon' : 'ok'))]);
        $acks = DB::table('policy_acknowledgements')->where('agency_id', $aid)->where('user_id', $id);
        $policyCount = (int) DB::table('policies')->where('agency_id', $aid)->when(\Illuminate\Support\Facades\Schema::hasColumn('policies', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))->count();
        $yStart = substr($today, 0, 4) . '-01-01';
        $pd = DB::table('pd_hours')->where('agency_id', $aid)->where('user_id', $id)->whereNull('deleted_at')->where('status', 'approved');
        $mgr = $f && $f->manager_user_id ? DB::table('users')->where('id', $f->manager_user_id)->first(['first_name', 'last_name']) : null;
        $sin = $this->isAdmin($request) ? DB::table('hr_staff_sin')->where('agency_id', $aid)->where('user_id', $id)->first(['sin_hint', 'updated_at']) : null;
        $reqs = DB::table('time_off_requests')->where('agency_id', $aid)->where('user_id', $id)->orderByDesc('start_at')->limit(15)->get();

        return response()->json([
            'person' => ['id' => (int) $u->id, 'name' => $this->name($u), 'first_name' => $u->first_name, 'email' => $u->email, 'phone' => $u->phone,
                'photo_url' => $u->photo_url, 'sex' => $u->sex, 'start_date' => $u->hire_date ? substr((string) $u->hire_date, 0, 10) : null,
                'years_of_service' => HrLeave::serviceYears($u->hire_date ? substr((string) $u->hire_date, 0, 10) : null, $today),
                'roles' => $roleRows->pluck('role')->unique()->values(),
                'centres' => $roleRows->pluck('centre_id')->filter(fn ($c) => $c && isset($centres[$c]))->unique()->map(fn ($c) => ['id' => (int) $c, 'name' => $centres[$c]])->values()],
            'file' => ['job_title' => $f->job_title ?? null, 'employment_type' => $f->employment_type ?? null, 'status' => $f->status ?? 'active',
                'hours_per_week' => isset($f->hours_per_week) ? (float) $f->hours_per_week : null, 'end_date' => $f->end_date ?? null, 'end_reason' => $f->end_reason ?? null, 'manager_user_id' => $f->manager_user_id ?? null,
                'manager_name' => $mgr ? $this->name($mgr) : null, 'primary_centre_id' => $f->primary_centre_id ?? null,
                'primary_centre_name' => ($f && $f->primary_centre_id) ? ($centres[$f->primary_centre_id] ?? null) : null,
                'updated_at' => $f->updated_at ?? null],
            'emergency' => $prof ? ['name' => $prof->emergency_contact_name, 'phone' => $prof->emergency_contact_phone, 'relation' => $prof->emergency_contact_relation] : null,
            'certifications' => $certs,
            'policies' => ['acknowledged' => (int) (clone $acks)->distinct()->count('policy_id'), 'total' => $policyCount, 'last' => (clone $acks)->max('acknowledged_at')],
            'pd_hours' => ['this_year' => round((float) (clone $pd)->where('taken_on', '>=', $yStart)->sum('hours'), 1), 'total' => round((float) (clone $pd)->sum('hours'), 1)],
            'leave' => HrLeave::summary($aid, $id),
            'requests' => $reqs->map(function ($r) {
                $t = HrLeave::typeForRequest($r);
                return ['id' => (int) $r->id, 'status' => $r->status, 'when' => TimeOffController::whenPhrase($r) . ', ' . substr((string) $r->start_at, 0, 4),
                    'type' => $t ? $t->name : (HrLeave::REQUEST_TYPES[$r->request_type] ?? $r->request_type), 'colour' => $t->colour ?? '#64748B',
                    'amount' => $t ? HrLeave::amountFor($r, $t) : null, 'unit' => $t->unit ?? null];
            }),
            'pay' => $this->canSeePay($request, $aid) ? ['pay_rate' => $u->pay_rate, 'pay_type' => $u->pay_type] : null,
            'sin' => $this->isAdmin($request) ? ['on_file' => (bool) $sin, 'hint' => $sin->sin_hint ?? null, 'label' => $this->sinLabel($aid)] : null,
            'can' => ['edit' => true, 'sin' => $this->isAdmin($request), 'adjust' => $id !== (int) $request->user()->id, 'pay' => $this->canSeePay($request, $aid)],
            'managers' => $this->managerChoices($request, $aid, $id),
            'centre_choices' => Live::centres(DB::table('centres'), 'centres')->where('agency_id', $aid)
                ->when(($sc = CentreScope::ids($request, $aid)) !== null, fn ($q) => $q->whereIn('id', $sc ?: [0]))->orderBy('name')->get(['id', 'name']),
            'employment_types' => HrLeave::EMPLOYMENT, 'statuses' => HrLeave::STATUSES,
        ]);
    }

    private function sinLabel(int $aid): string
    {
        $country = (string) (json_decode((string) DB::table('agencies')->where('id', $aid)->value('settings'), true)['country'] ?? 'CA');

        return strtoupper($country) === 'US' ? 'Social Security number' : 'Social insurance number (SIN)';
    }

    /** Admins and directors of the agency who can be someone's manager. */
    private function managerChoices(Request $request, int $aid, int $forUser): array
    {
        $ids = \App\Support\HrTalent::managerIds($aid);   // never a leaver (HR Phase 4) or an outsider
        return DB::table('users as u')->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->whereIn('u.id', $ids ?: [0])->where('u.id', '!=', $forUser)->where('ra.active', 1)->whereIn('ra.role', ['agency_admin', 'centre_director'])
            ->distinct()->orderBy('u.first_name')->get(['u.id', 'u.first_name', 'u.last_name'])
            ->map(fn ($m) => ['id' => (int) $m->id, 'name' => $this->name($m)])->all();
    }

    /** PUT /hr/people/{id} — the employee file. Start date writes users.hire_date. */
    public function saveFile(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $this->needPerson($request, $aid, $id);
        $d = $request->validate([
            'job_title' => 'nullable|string|max:120',
            'employment_type' => 'nullable|in:' . implode(',', array_keys(HrLeave::EMPLOYMENT)),
            'status' => 'required|in:' . implode(',', array_keys(HrLeave::STATUSES)),
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|required_if:status,terminated',
            'end_reason' => 'nullable|string|max:255',
            'manager_user_id' => 'nullable|integer',
            'primary_centre_id' => 'nullable|integer',
        ]);
        /* Authorise the DESTINATION (the data-leakage rule): the manager must be an admin or director of this agency,
           and the centre an open centre of it that this caller may write. */
        if (! empty($d['manager_user_id'])) {
            abort_unless(in_array((int) $d['manager_user_id'], array_column($this->managerChoices($request, $aid, $id), 'id'), true), 422, 'Choose a manager from this agency.');
        }
        if (! empty($d['primary_centre_id'])) {
            abort_unless(in_array((int) $d['primary_centre_id'], Live::centreIds($aid), true)
                && CentreScope::canWrite(CentreScope::ids($request, $aid), (int) $d['primary_centre_id']), 422, 'Choose one of your centres.');
        }
        if (! empty($d['end_date']) && ! empty($d['start_date'])) {
            abort_if($d['end_date'] < $d['start_date'], 422, 'The end date is before the start date.');
        }
        $row = ['job_title' => $d['job_title'] ?? null, 'employment_type' => $d['employment_type'] ?? null, 'status' => $d['status'],
            'end_date' => $d['status'] === 'terminated' ? ($d['end_date'] ?? null) : ($d['end_date'] ?? null),
            'end_reason' => $d['end_reason'] ?? null, 'manager_user_id' => $d['manager_user_id'] ?? null,
            'primary_centre_id' => $d['primary_centre_id'] ?? null, 'updated_by_id' => $request->user()->id, 'updated_at' => now()];
        $typeBefore = DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $id)->value('employment_type');
        $was = (array) (DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $id)->first(['job_title', 'employment_type']) ?? []);
        DB::transaction(function () use ($aid, $id, $row, $d, $typeBefore, $was, $request) {
            \App\Support\HrPay::recordEdit($aid, $id, $was, $row, (int) $request->user()->id);   // pay + role history (HR Phase 4)
            HrLeave::syncContractorFlag($id, $typeBefore, $row['employment_type']);   // the contractor box follows "contract"
            $exists = DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $id)->exists();
            if ($exists) {
                DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $id)->update($row);
            } else {
                DB::table('hr_employee_files')->insert($row + ['agency_id' => $aid, 'user_id' => $id, 'created_at' => now()]);
            }
            if (array_key_exists('start_date', $d)) {
                DB::table('users')->where('id', $id)->update(['hire_date' => $d['start_date'] ?: null]);
            }
        });
        $this->note($aid, 'hr.file_updated', 'user', $id, ['fields' => array_keys(array_filter($d, fn ($v) => $v !== null))]);

        return response()->json(['ok' => true]);
    }

    // ── SIN: encrypted, separate, admin only, every reveal audited ───────────────────

    /** PUT /hr/people/{id}/sin */
    public function saveSin(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $this->needAdmin($request);
        $this->needPerson($request, $aid, $id);
        $digits = preg_replace('/\D/', '', (string) $request->input('sin', ''));
        abort_unless(strlen($digits) === 9, 422, 'Enter the 9 digits.');
        $row = ['sin_enc' => Crypt::encryptString($digits), 'sin_hint' => '••• ••• ' . substr($digits, -3), 'updated_by_id' => $request->user()->id, 'updated_at' => now()];
        if (DB::table('hr_staff_sin')->where('agency_id', $aid)->where('user_id', $id)->exists()) {
            DB::table('hr_staff_sin')->where('agency_id', $aid)->where('user_id', $id)->update($row);
        } else {
            DB::table('hr_staff_sin')->insert($row + ['agency_id' => $aid, 'user_id' => $id, 'created_at' => now()]);
        }
        $this->note($aid, 'hr.sin_updated', 'user', $id);   // what changed, never to what

        return response()->json(['hint' => $row['sin_hint']]);
    }

    /** DELETE /hr/people/{id}/sin */
    public function clearSin(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $this->needAdmin($request);
        $this->needPerson($request, $aid, $id);
        DB::table('hr_staff_sin')->where('agency_id', $aid)->where('user_id', $id)->delete();
        $this->note($aid, 'hr.sin_cleared', 'user', $id);

        return response()->json(['ok' => true]);
    }

    /** POST /hr/people/{id}/sin/reveal — the one door out. POST, so no link, prefetch or scanner can open it. */
    public function revealSin(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $this->needAdmin($request);
        $this->needPerson($request, $aid, $id);
        $row = DB::table('hr_staff_sin')->where('agency_id', $aid)->where('user_id', $id)->first();
        abort_unless($row, 404, 'No number is on file.');
        $this->note($aid, 'hr.sin_revealed', 'user', $id);
        $v = rescue(fn () => Crypt::decryptString($row->sin_enc), null, false);
        abort_unless($v, 500, 'The number could not be read.');

        return response()->json(['sin' => substr($v, 0, 3) . ' ' . substr($v, 3, 3) . ' ' . substr($v, 6)]);
    }

    // ── balances, ledger, adjustments ────────────────────────────────────────────────

    /** GET /hr/balances — every managed person x every type. */
    public function balances(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $ids = $this->people($request, $aid);
        $types = HrLeave::types($aid);
        $sums = DB::table('hr_leave_ledger')->where('agency_id', $aid)->whereIn('user_id', $ids ?: [0])
            ->groupBy('user_id', 'leave_type_id')->selectRaw('user_id, leave_type_id, SUM(amount) s')->get()
            ->groupBy('user_id')->map(fn ($g) => $g->pluck('s', 'leave_type_id'));
        $users = DB::table('users')->whereIn('id', $ids ?: [0])->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name']);
        $pend = [];
        foreach (DB::table('time_off_requests')->where('agency_id', $aid)->where('status', 'pending')->whereIn('user_id', $ids ?: [0])->get() as $r) {
            $t = HrLeave::typeForRequest($r);
            if ($t) {
                $pend[$r->user_id][$t->id] = ($pend[$r->user_id][$t->id] ?? 0) + HrLeave::amountFor($r, $t);
            }
        }
        $rows = $users->map(fn ($u) => ['id' => (int) $u->id, 'name' => $this->name($u),
            'balances' => $types->mapWithKeys(fn ($t) => [$t->id => ['balance' => round((float) ($sums[$u->id][$t->id] ?? 0), 2), 'pending' => round((float) ($pend[$u->id][$t->id] ?? 0), 2)]])])->values();

        return response()->json(['types' => $types->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name, 'colour' => $t->colour, 'unit' => $t->unit])->values(), 'people' => $rows]);
    }

    /** GET /hr/ledger?user_id=&leave_type_id= — mine, or a managed person's. */
    public function ledger(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $uid = (int) $request->query('user_id', $request->user()->id);
        $self = $uid === (int) $request->user()->id;
        if (! $self) {
            $this->needPerson($request, $aid, $uid);
        }
        $tid = (int) $request->query('leave_type_id', 0);
        $t = $tid ? DB::table('hr_leave_types')->where('id', $tid)->where('agency_id', $aid)->first() : null;
        abort_if($tid && ! $t, 404, 'That leave type was not found.');
        abort_if($self && $t && ! $t->visible_to_staff && ! $this->isManager($request), 404, 'That leave type was not found.');
        $visible = $self && ! $this->isManager($request) ? HrLeave::types($aid)->filter(fn ($x) => $x->visible_to_staff)->pluck('id')->all() : null;
        $rows = DB::table('hr_leave_ledger as l')->leftJoin('users as c', 'c.id', '=', 'l.created_by_id')
            ->join('hr_leave_types as t', 't.id', '=', 'l.leave_type_id')
            ->where('l.agency_id', $aid)->where('l.user_id', $uid)
            ->when($tid, fn ($q) => $q->where('l.leave_type_id', $tid))
            ->when($visible !== null, fn ($q) => $q->whereIn('l.leave_type_id', $visible ?: [0]))
            ->orderByDesc('l.entry_date')->orderByDesc('l.id')->limit(300)
            ->get(['l.id', 'l.entry_date', 'l.amount', 'l.kind', 'l.reason', 'l.time_off_request_id', 't.name as type', 't.unit', 't.colour', 'c.first_name', 'c.last_name'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'date' => substr((string) $r->entry_date, 0, 10), 'amount' => (float) $r->amount, 'kind' => $r->kind,
                'reason' => $r->reason, 'type' => $r->type, 'unit' => $r->unit, 'colour' => $r->colour, 'request_id' => $r->time_off_request_id,
                'by' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: null]);

        return response()->json(['entries' => $rows]);
    }

    /** POST /hr/adjustments — a manual change with a reason, as a new ledger row. Nobody adjusts their own balance. */
    public function adjust(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $d = $request->validate([
            'user_id' => 'required|integer',
            'leave_type_id' => 'required|integer',
            'amount' => 'required|numeric|min:-1000|max:1000|not_in:0',
            'reason' => 'required|string|min:3|max:500',
        ]);
        $uid = (int) $d['user_id'];
        $this->needPerson($request, $aid, $uid);
        abort_if($uid === (int) $request->user()->id, 403, 'Someone else has to adjust your own balance.');
        $t = $this->ownType($aid, (int) $d['leave_type_id']);
        $id = (int) DB::table('hr_leave_ledger')->insertGetId([
            'agency_id' => $aid, 'user_id' => $uid, 'leave_type_id' => (int) $t->id, 'entry_date' => AgencyTime::today($aid),
            'amount' => round((float) $d['amount'], 2), 'kind' => 'adjustment', 'reason' => trim($d['reason']),
            'created_by_id' => $request->user()->id, 'created_at' => now(),
        ]);
        $this->note($aid, 'hr.balance_adjusted', 'user', $uid, ['leave_type' => $t->name, 'amount' => (float) $d['amount']]);
        $bal = HrLeave::balance($uid, (int) $t->id);
        if ($t->visible_to_staff) {
            $amt = rtrim(rtrim(number_format(abs((float) $d['amount']), 2), '0'), '.');
            \App\Support\Notify::write([
                'user_id' => $uid, 'agency_id' => $aid, 'type' => 'time_off',
                'title' => 'Your ' . $t->name . ' balance was ' . ((float) $d['amount'] > 0 ? 'increased' : 'reduced') . ' by ' . $amt . ' ' . $t->unit,
                'body' => trim($d['reason']), 'data' => json_encode(['link' => '#time-off']), 'created_at' => now(),
            ]);
        }

        return response()->json(['id' => $id, 'balance' => $bal], 201);
    }

    // ── absence report, who's off, export ────────────────────────────────────────────

    private function reportData(Request $request): array
    {
        $aid = $this->agency($request);
        $ids = $this->people($request, $aid);
        $today = AgencyTime::today($aid);
        $from = (string) $request->query('from', Carbon::parse($today)->subMonthsNoOverflow(2)->startOfMonth()->toDateString());
        $to = (string) $request->query('to', $today);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && $from <= $to, 422, 'Choose a valid date range.');
        abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 731, 422, 'Choose two years or less.');
        $group = in_array($request->query('group'), ['person', 'type', 'month', 'centre'], true) ? (string) $request->query('group') : 'person';
        $centreF = (int) $request->query('centre_id', 0);
        $typeF = (int) $request->query('leave_type_id', 0);
        $s = HrLeave::settings($aid);
        $rule = $s['short_absence'];

        $files = DB::table('hr_employee_files')->where('agency_id', $aid)->whereIn('user_id', $ids ?: [0])->pluck('primary_centre_id', 'user_id');
        $openNames = DB::table('centres')->whereIn('id', Live::centreIds($aid) ?: [0])->pluck('name', 'id');
        $homeCentre = [];
        foreach (DB::table('role_assignments')->where('active', 1)->whereIn('user_id', $ids ?: [0])->whereIn('role', HrLeave::STAFF_ROLES)
            ->whereIn('centre_id', array_keys($openNames->all()) ?: [0])->get(['user_id', 'centre_id']) as $r) {
            $homeCentre[$r->user_id][] = (int) $r->centre_id;
        }
        $centreOf = function (array $d) use ($files, $homeCentre, $openNames) {
            $c = $d['centre_id'] ?: ($files[$d['user_id']] ?? null) ?: (count(array_unique($homeCentre[$d['user_id']] ?? [])) === 1 ? $homeCentre[$d['user_id']][0] : null);
            return $c && isset($openNames[$c]) ? (int) $c : null;
        };
        $users = DB::table('users')->whereIn('id', $ids ?: [0])->get(['id', 'first_name', 'last_name'])->keyBy('id');

        $days = array_values(array_filter(HrLeave::absenceDays($aid, $from, $to, $ids), function ($d) use ($centreF, $typeF, $centreOf) {
            return (! $typeF || $d['leave_type_id'] === $typeF) && (! $centreF || $centreOf($d) === $centreF);
        }));

        // Repeated short absences: requests of max_days or less, in the rule's window ending on $to.
        $wFrom = Carbon::parse($to)->subMonthsNoOverflow((int) $rule['months'])->addDay()->toDateString();
        $short = [];
        foreach (HrLeave::absenceDays($aid, $wFrom, $to, $ids) as $d) {
            if ($d['request_days'] <= (float) $rule['max_days'] && (! $typeF || $d['leave_type_id'] === $typeF)) {
                $short[$d['user_id']][$d['request_id']] = true;
            }
        }
        $flagged = [];
        foreach ($short as $uid => $reqs) {
            if (count($reqs) >= (int) $rule['count']) {
                $flagged[$uid] = count($reqs);
            }
        }

        $rows = [];
        foreach ($days as $d) {
            $c = $centreOf($d);
            $key = match ($group) { 'type' => (string) ($d['leave_type_id'] ?? $d['type_name']), 'month' => substr($d['date'], 0, 7), 'centre' => (string) ($c ?? 0), default => (string) $d['user_id'] };
            if (! isset($rows[$key])) {
                $label = match ($group) {
                    'type' => $d['type_name'],
                    'month' => Carbon::parse($d['date'])->format('F Y'),
                    'centre' => $c ? $openNames[$c] : 'No centre',
                    default => $this->name($users[$d['user_id']] ?? null),
                };
                $rows[$key] = ['key' => $key, 'label' => $label, 'colour' => $group === 'type' ? $d['colour'] : null, 'days' => 0.0, 'hours' => 0.0,
                    'occurrences' => [], 'people' => [], 'user_id' => $group === 'person' ? $d['user_id'] : null,
                    'centre' => $group === 'person' && $c ? $openNames[$c] : null, 'by_type' => []];
            }
            $rows[$key]['days'] += $d['days'];
            $rows[$key]['hours'] += $d['hours'];
            $rows[$key]['occurrences'][$d['request_id']] = true;
            $rows[$key]['people'][$d['user_id']] = true;
            $rows[$key]['by_type'][$d['type_name']] = ($rows[$key]['by_type'][$d['type_name']] ?? 0) + $d['days'];
        }
        /* ABSENCE SCORE (HR Phase 4, App\Support\HrAttendance::scores): spells x spells x days over the agency's window
           ending on $to, for the leave types it counts. Beside the short-absence flag, per person. */
        $sc = HrAttendance::scores($aid, $to, $ids);
        $out = [];
        foreach ($rows as $r) {
            $uid = $r['user_id'];
            $score = $uid ? ($sc['people'][$uid] ?? ['spells' => 0, 'days' => 0, 'score' => 0]) : null;
            $out[] = ['key' => $r['key'], 'label' => $r['label'], 'colour' => $r['colour'], 'centre' => $r['centre'], 'user_id' => $uid,
                'days' => round($r['days'], 2), 'hours' => round($r['hours'], 2), 'occurrences' => count($r['occurrences']), 'people' => count($r['people']),
                'short_count' => $uid ? count($short[$uid] ?? []) : null, 'flagged' => $uid ? isset($flagged[$uid]) : null,
                'score' => $score ? $score['score'] : null, 'score_spells' => $score ? $score['spells'] : null, 'score_days' => $score ? $score['days'] : null,
                'score_flag' => $score ? $score['score'] >= $sc['threshold'] : null,
                'by_type' => collect($r['by_type'])->map(fn ($v, $k) => ['type' => $k, 'days' => round($v, 2)])->values()];
        }
        if ($group === 'person' && $request->boolean('short_only')) {
            $out = array_values(array_filter($out, fn ($r) => $r['flagged']));
        }
        if ($group === 'person' && $request->boolean('score_only')) {
            $out = array_values(array_filter($out, fn ($r) => $r['score_flag']));
        }
        $scoreFlagged = $group === 'person' ? count(array_filter($out, fn ($r) => $r['score_flag']))
            : count(array_filter($sc['people'], fn ($p) => $p['score'] >= $sc['threshold']));
        usort($out, fn ($a, $b) => $group === 'month' ? strcmp($a['key'], $b['key']) : ($b['days'] <=> $a['days'] ?: strcasecmp($a['label'], $b['label'])));

        return ['agency' => $aid, 'from' => $from, 'to' => $to, 'group' => $group, 'rows' => $out, 'rule' => $rule,
            'score_rule' => ['threshold' => $sc['threshold'], 'months' => $sc['months'], 'from' => $sc['window_from'], 'types' => $sc['types']],
            'totals' => ['days' => round(array_sum(array_column($out, 'days')), 2), 'hours' => round(array_sum(array_column($out, 'hours')), 2),
                'people' => count(array_unique(array_column($days, 'user_id'))), 'flagged' => count($flagged), 'score_flagged' => $scoreFlagged],
            'types' => HrLeave::types($aid)->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name])->values(),
            'centres' => Live::centres(DB::table('centres'), 'centres')->where('agency_id', $aid)
                ->when(($sc = CentreScope::ids($request, $aid)) !== null, fn ($q) => $q->whereIn('id', $sc ?: [0]))->orderBy('name')->get(['id', 'name'])];
    }

    /** GET /hr/absence */
    public function absence(Request $request): JsonResponse
    {
        $d = $this->reportData($request);
        unset($d['agency']);

        return response()->json($d);
    }

    /** GET /exports/hr-absence — the same report as an Excel file (the branded XLSX exports). */
    public function exportAbsence(Request $request, XlsxExportService $xlsx): Response
    {
        if ($request->query('view') === 'late') {   // the Lateness tab of the same report (HR Phase 4)
            $d = $this->latenessData($request);
            $cols = [['header' => 'Person', 'key' => 'name', 'width' => 28], ['header' => 'Centre', 'key' => 'centre', 'width' => 24],
                ['header' => 'Clock-ins compared', 'key' => 'compared', 'width' => 18], ['header' => 'Late', 'key' => 'late', 'width' => 8],
                ['header' => 'Minutes late in all', 'key' => 'minutes', 'width' => 18], ['header' => 'Average minutes late', 'key' => 'average', 'width' => 20],
                ['header' => 'Most minutes late', 'key' => 'worst', 'width' => 17], ['header' => 'On time %', 'key' => 'on_time_pct', 'width' => 11],
                ['header' => 'Pattern', 'key' => 'pattern', 'width' => 30]];

            return $xlsx->download($d['agency'], 'Lateness ' . $d['from'] . ' to ' . $d['to'], 'lateness-' . $d['from'] . '-to-' . $d['to'] . '.xlsx', $cols, $d['rows']);
        }
        $d = $this->reportData($request);
        $g = $d['group'];
        $first = ['person' => 'Person', 'type' => 'Leave type', 'month' => 'Month', 'centre' => 'Centre'][$g];
        $cols = [['header' => $first, 'key' => 'label', 'width' => 28]];
        if ($g === 'person') {
            $cols[] = ['header' => 'Centre', 'key' => 'centre', 'width' => 24];
        }
        $cols[] = ['header' => 'Days off', 'key' => 'days', 'width' => 11];
        $cols[] = ['header' => 'Hours off', 'key' => 'hours', 'width' => 11];
        $cols[] = ['header' => 'Absences', 'key' => 'occurrences', 'width' => 11];
        if ($g === 'person') {
            $cols[] = ['header' => 'Short absences', 'key' => 'short_count', 'width' => 15];
            $cols[] = ['header' => 'Repeated short absences', 'key' => 'flag', 'width' => 24];
            $cols[] = ['header' => 'Absence score', 'key' => 'score', 'width' => 14];
            $cols[] = ['header' => 'Above the absence score threshold', 'key' => 'score_mark', 'width' => 30];
        } else {
            $cols[] = ['header' => 'People', 'key' => 'people', 'width' => 10];
        }
        $cols[] = ['header' => 'By leave type (days)', 'key' => 'by', 'width' => 40];
        $rows = array_map(fn ($r) => $r + ['flag' => $r['flagged'] ? 'Yes' : '', 'score_mark' => $r['score_flag'] ? 'Yes' : '', 'by' => collect($r['by_type'])->map(fn ($b) => $b['type'] . ' ' . $b['days'])->implode(', ')], $d['rows']);

        return $xlsx->download($d['agency'], 'Absence report ' . $d['from'] . ' to ' . $d['to'], 'absence-report-' . $d['from'] . '-to-' . $d['to'] . '.xlsx', $cols, $rows);
    }

    // ── HR Phase 4, batch 1: lateness, attendance settings, leave clashes (App\Support\HrAttendance) ──────────

    /** The date range + centre filter the reports share (the absence report's defaults). */
    private function range(Request $request, int $aid): array
    {
        $today = AgencyTime::today($aid);
        $from = (string) $request->query('from', Carbon::parse($today)->subMonthsNoOverflow(2)->startOfMonth()->toDateString());
        $to = (string) $request->query('to', $today);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && $from <= $to, 422, 'Choose a valid date range.');
        abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 731, 422, 'Choose two years or less.');

        return [$from, $to];
    }

    private function latenessData(Request $request): array
    {
        $aid = $this->agency($request);
        $ids = $this->people($request, $aid);
        [$from, $to] = $this->range($request, $aid);
        $centreF = (int) $request->query('centre_id', 0);
        // A centre filter is only ever one of this caller's own open centres.
        if ($centreF && ! (in_array($centreF, Live::centreIds($aid), true) && CentreScope::canWrite(CentreScope::ids($request, $aid), $centreF))) {
            $centreF = 0;
        }
        $r = HrAttendance::lateness($aid, $from, $to, $ids, $centreF ?: null);
        $users = DB::table('users')->whereIn('id', array_column($r['rows'], 'user_id') ?: [0])->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $cn = DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id');
        $rows = array_map(fn ($x) => $x + ['name' => $this->name($users[$x['user_id']] ?? null),
            'centre' => implode(', ', array_filter(array_map(fn ($c) => $cn[$c] ?? null, $x['centre_ids'])))], $r['rows']);
        usort($rows, fn ($a, $b) => ($b['late'] <=> $a['late']) ?: ($b['minutes'] <=> $a['minutes']) ?: strcasecmp($a['name'], $b['name']));
        if ($request->boolean('late_only')) {
            $rows = array_values(array_filter($rows, fn ($x) => $x['late'] > 0));
        }

        return ['agency' => $aid, 'from' => $from, 'to' => $to, 'grace' => $r['grace'], 'rows' => $rows,
            'totals' => ['compared' => array_sum(array_column($rows, 'compared')), 'late' => array_sum(array_column($rows, 'late')),
                'minutes' => array_sum(array_column($rows, 'minutes')), 'people_late' => count(array_filter($rows, fn ($x) => $x['late'] > 0))],
            'centres' => Live::centres(DB::table('centres'), 'centres')->where('agency_id', $aid)
                ->when(($sc = CentreScope::ids($request, $aid)) !== null, fn ($q) => $q->whereIn('id', $sc ?: [0]))->orderBy('name')->get(['id', 'name'])];
    }

    /** GET /hr/lateness — clock-ins compared with the schedule, per person (the absence report's Lateness tab). */
    public function lateness(Request $request): JsonResponse
    {
        $d = $this->latenessData($request);
        unset($d['agency']);

        return response()->json($d);
    }

    /** GET /hr/lateness/{id} — one person's compared clock-ins. */
    public function latenessPerson(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $this->needPerson($request, $aid, $id);
        [$from, $to] = $this->range($request, $aid);

        return response()->json(['person' => $this->name(DB::table('users')->where('id', $id)->first(['first_name', 'last_name'])),
            'grace' => HrAttendance::settings($aid)['lateness']['grace_minutes'], 'from' => $from, 'to' => $to,
            'entries' => HrAttendance::latenessDetail($aid, $id, $from, $to)]);
    }

    /** GET /hr/attendance/settings — lateness, absence score and leave-clash rules, with each centre's minimum staff. */
    public function attendanceSettings(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        abort_unless($this->isManager($request), 403, 'Directors and admins only.');
        $mins = HrAttendance::minStaff($aid);
        $scope = CentreScope::ids($request, $aid);

        return response()->json(['settings' => HrAttendance::settings($aid), 'can_manage' => $this->isAdmin($request),
            'default_score_types' => DB::table('hr_leave_types')->where('agency_id', $aid)->where('maps_request_type', 'sick')->pluck('name'),
            'types' => HrLeave::types($aid)->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name, 'colour' => $t->colour])->values(),
            'centres' => Live::centres(DB::table('centres'), 'centres')->where('agency_id', $aid)
                ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope ?: [0]))->orderBy('name')->get(['id', 'name'])
                ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name, 'min_staff' => $mins[(int) $c->id] ?? null,
                    'staff' => count(HrLeave::staffIds($aid, [(int) $c->id]))])->values()]);
    }

    /** PUT /hr/attendance/settings — agency admins. */
    public function saveAttendanceSettings(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $this->needAdmin($request);
        $d = $request->validate([
            'lateness.grace_minutes' => 'required|integer|min:0|max:120',
            'lateness.notify_directors' => 'required|boolean',
            'lateness.skip_contractors' => 'required|boolean',
            'absence_score.threshold' => 'required|integer|min:1|max:100000',
            'absence_score.months' => 'required|integer|min:1|max:24',
            'absence_score.type_ids' => 'nullable|array|max:50',
            'absence_score.type_ids.*' => 'integer',
            'clash.warn' => 'required|boolean',
            'clash.min_mode' => 'required|in:' . implode(',', HrAttendance::MIN_MODES),
            'min_staff' => 'nullable|array|max:200',
            'min_staff.*.centre_id' => 'required|integer',
            'min_staff.*.min' => 'nullable|integer|min:0|max:500',
        ]);
        $own = HrLeave::types($aid, true)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $types = array_values(array_intersect(array_map('intval', $d['absence_score']['type_ids'] ?? []), $own));
        $out = HrAttendance::saveSettings($aid, [
            'lateness' => ['grace_minutes' => (int) $d['lateness']['grace_minutes'], 'notify_directors' => (bool) $d['lateness']['notify_directors'],
                'skip_contractors' => (bool) $d['lateness']['skip_contractors']],
            'absence_score' => ['threshold' => (int) $d['absence_score']['threshold'], 'months' => (int) $d['absence_score']['months'], 'type_ids' => $types ?: null],
            'clash' => ['warn' => (bool) $d['clash']['warn'], 'min_mode' => $d['clash']['min_mode']],
        ]);
        // Each centre's minimum: only this agency's open centres (the destination is authorised, never trusted).
        $open = Live::centreIds($aid);
        $mins = [];
        foreach ($d['min_staff'] ?? [] as $m) {
            $cid = (int) $m['centre_id'];
            if (! in_array($cid, $open, true)) continue;
            HrAttendance::saveMinStaff($cid, ! empty($m['min']) ? (int) $m['min'] : null);
            $mins[$cid] = ! empty($m['min']) ? (int) $m['min'] : null;
        }
        $this->note($aid, 'hr.attendance_settings_updated', 'agency', $aid, ['settings' => $out, 'min_staff' => $mins]);

        return response()->json(['settings' => $out, 'min_staff' => HrAttendance::minStaff($aid)]);
    }

    /** GET /hr/time-off/{id}/clash — for the approver: who else at the centre is off or has asked, and the minimum-staff check. */
    public function requestClash(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $r = DB::table('time_off_requests')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($r, 404, 'That request was not found.');
        $this->needPerson($request, $aid, (int) $r->user_id);

        return response()->json(HrAttendance::forRequest($r, true));
    }

    /** GET /hr/me/clash?start_at=&end_at=&all_day=&centre_id= — for the request form: counts only, never names. */
    public function myClash(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $d = $request->validate(['start_at' => 'required|date_format:Y-m-d', 'end_at' => 'required|date_format:Y-m-d|after_or_equal:start_at',
            'all_day' => 'nullable|boolean', 'centre_id' => 'nullable|integer']);
        abort_if(Carbon::parse($d['start_at'])->diffInDays(Carbon::parse($d['end_at'])) > 400, 422, 'Choose a shorter range.');
        $me = (int) $request->user()->id;
        $cid = ! empty($d['centre_id']) && DB::table('role_assignments')->where('user_id', $me)->where('active', 1)
            ->whereIn('role', HrLeave::STAFF_ROLES)->where('centre_id', (int) $d['centre_id'])->exists() ? (int) $d['centre_id'] : null;
        $c = HrAttendance::clash($aid, $me, $cid, $d['start_at'], $d['end_at'], ! array_key_exists('all_day', $d) || $d['all_day'] === null || (bool) $d['all_day'], null, false);
        if (! $c['warn']) {   // the agency turned the clash note off: only the minimum-staff part stays
            foreach ($c['centres'] as &$x) $x['others_count'] = 0;
            unset($x);
            $c['others'] = 0;
        }

        return response()->json($c);
    }

    /** GET /hr/off-now — who is off today and the rest of the week (approved leave). */
    public function offNow(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $ids = $this->people($request, $aid);
        $today = AgencyTime::today($aid);
        $end = Carbon::parse($today)->addDays(6)->toDateString();
        $users = DB::table('users')->whereIn('id', $ids ?: [0])->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $byReq = [];
        foreach (HrLeave::absenceDays($aid, $today, $end, $ids) as $d) {
            $byReq[$d['request_id']] = $byReq[$d['request_id']] ?? $d + ['first' => $d['date']];
        }
        $centres = DB::table('centres')->whereIn('id', Live::centreIds($aid) ?: [0])->pluck('name', 'id');
        $today_l = [];
        $week = [];
        foreach ($byReq as $rid => $d) {
            $r = DB::table('time_off_requests')->where('id', $rid)->first();
            $item = ['request_id' => $rid, 'user_id' => $d['user_id'], 'name' => $this->name($users[$d['user_id']] ?? null), 'type' => $d['type_name'],
                'colour' => $d['colour'], 'when' => TimeOffController::whenPhrase($r), 'centre' => $r->centre_id ? ($centres[$r->centre_id] ?? null) : null, 'from' => $d['first']];
            if ($d['first'] === $today || substr((string) $r->start_at, 0, 10) <= $today) {
                $today_l[] = $item;
            } else {
                $week[] = $item;
            }
        }
        usort($today_l, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($week, fn ($a, $b) => strcmp($a['from'], $b['from']) ?: strcasecmp($a['name'], $b['name']));

        return response()->json(['today' => $today_l, 'week' => $week, 'date' => $today]);
    }
}
