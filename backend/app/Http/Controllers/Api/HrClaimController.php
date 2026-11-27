<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\HrClaims;
use App\Support\HrLeave;
use App\Support\Live;
use App\Support\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * KiddieTrac HR Phase 3, item 9: expense and mileage claims (rules in App\Support\HrClaims).
 * Staff: own claims (submit, see, withdraw while waiting). Directors: their own centres' people. Admins: the agency.
 * Approved claims are paid through the existing manual payroll run (ManualPayrollController), never here.
 */
class HrClaimController extends Controller
{
    use HrAccess;

    /** Claims this manager may see and decide: admin = agency; director = own centres' people / centres. */
    private function managedQuery(Request $request, int $aid)
    {
        $q = DB::table('hr_claims')->where('agency_id', $aid);
        if (! $this->hrIsAdmin($request)) {
            $scope = CentreScope::ids($request, $aid) ?? [];
            $people = $this->hrPeople($request, $aid);
            $q->where(fn ($w) => $w->whereIn('centre_id', $scope ?: [0])->orWhereIn('user_id', $people ?: [0]));
        }

        return $q;
    }

    private function canManage(Request $request, int $aid, object $c): bool
    {
        if (! $this->hrIsManager($request) || (int) $c->user_id === (int) $request->user()->id) return false;
        if ($this->hrIsAdmin($request)) return true;
        $scope = CentreScope::ids($request, $aid) ?? [];

        return ($c->centre_id && in_array((int) $c->centre_id, $scope, true)) || in_array((int) $c->user_id, $this->hrPeople($request, $aid), true);
    }

    private function claim(Request $request, int $aid, int $id): object
    {
        $c = DB::table('hr_claims')->where('id', $id)->where('agency_id', $aid)->first(HrClaims::COLS);
        abort_unless($c && ((int) $c->user_id === (int) $request->user()->id || $this->canManage($request, $aid, $c)), 404, 'That claim was not found.');

        return $c;
    }

    private function shapeAll(int $aid, $rows): array
    {
        $rows = collect($rows);
        $names = $this->hrNames($rows->pluck('user_id')->merge($rows->pluck('decided_by_id'))->filter()->map(fn ($v) => (int) $v)->unique()->values()->all());
        $centres = DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id')->all();
        $pay = DB::table('payroll_documents')->whereIn('id', $rows->pluck('payroll_document_id')->filter()->all() ?: [0])->pluck('reference', 'id')->all();

        return $rows->map(function ($c) use ($names, $centres, $pay) {
            $s = HrClaims::shape($c, $names, $centres);
            $s['payslip'] = $c->payroll_document_id ? ($pay[(int) $c->payroll_document_id] ?? null) : null;

            return $s;
        })->values()->all();
    }

    /** GET /hr/me/claims — the person's own claims and what they need to file one. */
    public function mine(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = (int) $request->user()->id;
        abort_unless(in_array($me, HrLeave::staffIds($aid), true), 404, 'Your HR record was not found.');
        $rows = DB::table('hr_claims')->where('agency_id', $aid)->where('user_id', $me)->orderByDesc('claim_date')->orderByDesc('id')->limit(300)->get(HrClaims::COLS);

        return response()->json(['claims' => $this->shapeAll($aid, $rows), 'settings' => HrClaims::settings($aid), 'today' => AgencyTime::today($aid),
            'statuses' => HrClaims::STATUSES]);
    }

    /** GET /hr/claims — a director's or admin's list (?status=submitted|approved|paid|declined|all). */
    public function index(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $st = (string) $request->query('status', 'submitted');
        $q = $this->managedQuery($request, $aid);
        if ($st !== 'all' && isset(HrClaims::STATUSES[$st])) $q->where('status', $st);
        $rows = $q->orderByRaw("status = 'submitted' DESC")->orderByDesc('claim_date')->orderByDesc('id')->limit(500)->get(HrClaims::COLS);
        $counts = $this->managedQuery($request, $aid)->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status')->map(fn ($v) => (int) $v)->all();

        return response()->json(['claims' => $this->shapeAll($aid, $rows), 'counts' => $counts, 'settings' => HrClaims::settings($aid),
            'is_admin' => $this->hrIsAdmin($request), 'me' => (int) $request->user()->id, 'statuses' => HrClaims::STATUSES, 'today' => AgencyTime::today($aid)]);
    }

    /** POST /hr/claims — a staff member files their own claim (multipart: receipt). */
    public function store(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = (int) $request->user()->id;
        abort_unless(in_array($me, HrLeave::staffIds($aid), true), 403, 'Only the agency\'s staff can file a claim here.');
        $set = HrClaims::settings($aid);
        $d = $request->validate([
            'kind' => 'required|in:expense,mileage',
            'claim_date' => 'required|date|before_or_equal:' . AgencyTime::today($aid),
            'category' => 'nullable|string|max:60',
            'description' => 'nullable|string|max:500',
            'amount' => 'required_if:kind,expense|nullable|numeric|min:0|max:100000',
            'tax' => 'nullable|numeric|min:0|max:100000',
            'km' => 'required_if:kind,mileage|nullable|numeric|min:0.1|max:20000',
            'from_place' => 'required_if:kind,mileage|nullable|string|max:200',
            'to_place' => 'required_if:kind,mileage|nullable|string|max:200',
            'receipt' => 'nullable|' . HrClaims::RECEIPT_RULE,
        ], ['receipt.mimes' => 'Upload a photo or a PDF of the receipt.', 'receipt.max' => 'The receipt is larger than 12 MB.',
            'claim_date.before_or_equal' => 'The date cannot be in the future.']);
        $row = ['kind' => $d['kind'], 'claim_date' => $d['claim_date'], 'description' => $d['description'] ?? null];
        if ($d['kind'] === 'mileage') {
            abort_unless($set['km_rate'], 422, 'Your agency has not set a mileage rate yet. Ask your agency admin.');
            $amount = round((float) $d['km'] * $set['km_rate'], 2);
            $row += ['km' => round((float) $d['km'], 1), 'km_rate' => $set['km_rate'], 'from_place' => $d['from_place'], 'to_place' => $d['to_place'],
                'category' => 'Mileage', 'amount' => $amount, 'tax' => 0, 'total' => $amount];
        } else {
            abort_unless(isset($d['category']) && in_array($d['category'], $set['categories'], true), 422, 'Choose a category.');
            abort_if($set['receipt_required'] && ! $request->hasFile('receipt'), 422, 'Add a photo of the receipt.');
            $amount = round((float) $d['amount'], 2);
            $tax = round((float) ($d['tax'] ?? 0), 2);
            abort_unless($amount + $tax > 0, 422, 'Enter the amount.');
            $row += ['category' => $d['category'], 'amount' => $amount, 'tax' => $tax, 'total' => round($amount + $tax, 2)];
        }
        $open = Live::centreIds($aid);
        $centre = (int) DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $me)->value('primary_centre_id') ?: null;
        if (! $centre || ! in_array($centre, $open, true)) {
            $centre = DB::table('role_assignments')->where('user_id', $me)->where('active', 1)->whereIn('role', HrLeave::STAFF_ROLES)
                ->whereIn('centre_id', $open ?: [0])->value('centre_id');
            $centre = $centre ? (int) $centre : null;
        }
        $id = 0;
        DB::transaction(function () use (&$id, $row, $aid, $centre, $me, $request) {
            $id = (int) DB::table('hr_claims')->insertGetId($row + ['agency_id' => $aid, 'centre_id' => $centre, 'user_id' => $me, 'status' => 'submitted',
                'created_at' => now(), 'updated_at' => now()]);
            if ($request->hasFile('receipt')) {
                $f = $request->file('receipt');
                $ext = strtolower($f->getClientOriginalExtension() ?: 'jpg');
                $name = mb_substr(preg_replace('/[^A-Za-z0-9 ._-]+/', '', (string) $f->getClientOriginalName()) ?: ('receipt.' . $ext), 0, 150);
                $path = Storage::disk('local')->putFileAs('hr-claims/' . $aid . '/' . $id, $f, 'receipt-' . bin2hex(random_bytes(6)) . '.' . $ext);
                DB::table('hr_claims')->where('id', $id)->update(['receipt_path' => $path, 'receipt_name' => $name, 'receipt_mime' => (string) $f->getMimeType()]);
            }
        });
        $this->hrNote($aid, 'hr.claim_submitted', 'hr_claim', $id, ['kind' => $row['kind'], 'total' => $row['total']]);

        return response()->json(['id' => $id], 201);
    }

    /** POST /hr/claims/{id}/withdraw — the claimant takes back a claim still waiting. */
    public function withdraw(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $c = $this->claim($request, $aid, $id);
        abort_unless((int) $c->user_id === (int) $request->user()->id, 403, 'Only the person who filed it can withdraw it.');
        abort_unless($c->status === 'submitted', 422, 'Only a claim still waiting for approval can be withdrawn.');
        DB::table('hr_claims')->where('id', $id)->where('status', 'submitted')->update(['status' => 'withdrawn', 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.claim_withdrawn', 'hr_claim', $id);

        return response()->json(['ok' => true]);
    }

    /** POST /hr/claims/{id}/decide — approve or decline, with a comment (required to decline). */
    public function decide(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $c = $this->claim($request, $aid, $id);
        abort_unless($this->canManage($request, $aid, $c), 403, 'A director or admin decides this claim (never the person who filed it).');
        $d = $request->validate(['decision' => 'required|in:approve,decline', 'comment' => 'required_if:decision,decline|nullable|string|max:1000'],
            ['comment.required_if' => 'Say why the claim is declined.']);
        abort_unless($c->status === 'submitted' || ($c->status === 'declined' && $d['decision'] === 'approve')
            || ($c->status === 'approved' && $d['decision'] === 'decline' && ! $c->payroll_document_id), 422, 'This claim has already been decided.');
        $st = $d['decision'] === 'approve' ? 'approved' : 'declined';
        DB::table('hr_claims')->where('id', $id)->whereNull('payroll_document_id')->update(['status' => $st, 'decided_by_id' => $request->user()->id,
            'decided_at' => now(), 'decision_comment' => $d['comment'] ?? null, 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.claim_' . $st, 'hr_claim', $id, ['total' => (float) $c->total]);
        try {
            $money = '$' . number_format((float) $c->total, 2);
            Notify::write(['user_id' => (int) $c->user_id, 'agency_id' => $aid, 'type' => 'hr',
                'title' => $st === 'approved' ? 'Claim approved: ' . $money : 'Claim declined: ' . $money,
                'body' => $st === 'approved' ? 'It will be added to your next pay.' : (string) ($d['comment'] ?? ''),
                'data' => json_encode(['link' => '#my-hr', 'kind' => 'own']), 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true, 'status' => $st]);
    }

    /** GET /hr/claims/{id}/receipt — the private receipt, for the claimant or a manager of them. */
    public function receipt(Request $request, int $id)
    {
        $aid = $this->hrAgency($request);
        $c = $this->claim($request, $aid, $id);
        abort_unless($c->receipt_path && Storage::disk('local')->exists($c->receipt_path), 404, 'No receipt was added.');

        return \App\Support\FileResponse::download($c->receipt_path, $c->receipt_name ?: basename($c->receipt_path), ['Cache-Control' => 'private, no-store'], 'local', (bool) $request->boolean('inline'));
    }

    /** GET /hr/claims/report?from=&to= — approved and paid claims by person, category and month (manager scope). */
    public function report(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $d = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $today = AgencyTime::today($aid);
        $from = $d['from'] ?? substr($today, 0, 4) . '-01-01';
        $to = $d['to'] ?? $today;
        $rows = $this->managedQuery($request, $aid)->whereIn('status', ['approved', 'paid'])->whereBetween('claim_date', [$from, $to])
            ->get(['user_id', 'kind', 'category', 'claim_date', 'total', 'km', 'status']);
        $names = $this->hrNames($rows->pluck('user_id')->unique()->map(fn ($v) => (int) $v)->all());
        $sum = fn ($g) => ['count' => $g->count(), 'total' => round((float) $g->sum('total'), 2), 'paid' => round((float) $g->where('status', 'paid')->sum('total'), 2),
            'km' => round((float) $g->sum('km'), 1)];
        $person = $rows->groupBy('user_id')->map(fn ($g, $u) => ['name' => $names[(int) $u] ?? ''] + $sum($g))->sortBy('name')->values()->all();
        $category = $rows->groupBy(fn ($r) => $r->category ?: ($r->kind === 'mileage' ? 'Mileage' : 'Other'))->map(fn ($g, $k) => ['name' => $k] + $sum($g))->sortByDesc('total')->values()->all();
        $month = $rows->groupBy(fn ($r) => substr((string) $r->claim_date, 0, 7))->map(fn ($g, $k) => ['month' => $k] + $sum($g))->sortBy('month')->values()->all();

        return response()->json(['from' => $from, 'to' => $to, 'by_person' => $person, 'by_category' => $category, 'by_month' => $month,
            'total' => $sum($rows)]);
    }

    /** PUT /hr/claims/settings — an agency admin sets the km rate, the categories and whether a receipt is required. */
    public function saveSettings(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $d = $request->validate(['km_rate' => 'nullable|numeric|min:0|max:10', 'categories' => 'required|array|min:1|max:30',
            'categories.*' => 'required|string|max:60', 'receipt_required' => 'nullable|boolean']);
        $d['km_rate'] = isset($d['km_rate']) && (float) $d['km_rate'] > 0 ? round((float) $d['km_rate'], 3) : null;
        $d['categories'] = array_values(array_unique(array_filter(array_map(fn ($c) => trim((string) $c), $d['categories']))));
        $s = HrClaims::saveSettings($aid, $d);
        $this->hrNote($aid, 'hr.claim_settings_saved', 'agency', $aid, ['km_rate' => $s['km_rate'], 'categories' => count($s['categories']), 'receipt_required' => $s['receipt_required']]);

        return response()->json(['settings' => $s]);
    }
}
