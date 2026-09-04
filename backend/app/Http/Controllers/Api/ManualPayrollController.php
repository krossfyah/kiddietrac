<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Manual payroll — pay several people for one period, in one pass.
 *
 * Payroll here has only ever arrived from outside: iLearn pushes payroll_documents in
 * through the integration. There was no way to pay somebody the platform itself knows
 * about — a casual shift, a one-off bonus, an agency that does not run iLearn at all.
 *
 * TWO STEPS, because the hours are the part nobody should type. prepare() reads the
 * clock and answers what each person actually worked in the period; the screen then
 * shows that beside a rate, and only the money is entered by hand. commit() writes the
 * payslips from what was reviewed.
 *
 * THE HOURS ARE EVIDENCE, NOT A SUGGESTION. Every punch that produced the total is
 * stored on the payslip with it. A figure that cannot be traced back to the shifts
 * behind it is one somebody has to take on trust, and payroll is the last place for
 * that.
 */
final class ManualPayrollController extends Controller
{
    use ResolvesCentreContext;

    /**
     * GET /admin/payroll/manual/prepare?period_start=&period_end=&centre_id=
     *
     * Who can be paid, and what the clock says each of them worked.
     */
    public function prepare(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId, 403);

        $data = $request->validate([
            'period_start' => 'required|date_format:Y-m-d',
            'period_end' => 'required|date_format:Y-m-d',
            'centre_id' => 'nullable|integer',
        ]);
        [$from, $to] = $this->window($data['period_start'], $data['period_end']);

        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id');
        if (! empty($data['centre_id'])) {
            abort_unless($centreIds->contains((int) $data['centre_id']), 422, 'That centre is not in this agency.');
            $centreIds = collect([(int) $data['centre_id']]);
        }

        /* Everyone who holds a STAFF role in this agency — the people who can be paid.
           Guardians are excluded by the role list, not by hoping none appear. */
        $staffRoles = ['educator', 'centre_director', 'agency_admin', 'home_visitor', 'auditor'];
        $roles = DB::table('role_assignments')
            ->where('agency_id', $agencyId)->where('active', 1)
            ->whereIn('role', $staffRoles)
            ->get(['user_id', 'role', 'centre_id']);

        $userIds = $roles->pluck('user_id')->unique()->values();
        if ($userIds->isEmpty()) {
            return response()->json(['period' => ['from' => $from, 'to' => $to], 'staff' => []]);
        }

        /* WHAT THE CLOCK SAYS. Only CLOSED punches count: a shift still open has no
           duration yet, and inventing one would pay for time that has not happened.
           They are reported separately so nobody wonders why a total looks short. */
        $punches = DB::table('time_punches')
            ->whereIn('user_id', $userIds)
            ->whereIn('centre_id', $centreIds->isEmpty() ? [0] : $centreIds)
            ->whereDate('punched_in_at', '>=', $from)
            ->whereDate('punched_in_at', '<=', $to)
            ->orderBy('punched_in_at')
            ->get(['user_id', 'centre_id', 'punched_in_at', 'punched_out_at']);

        $worked = [];
        $detail = [];
        $open = [];
        foreach ($punches as $p) {
            $uid = (int) $p->user_id;
            if (! $p->punched_out_at) {
                $open[$uid] = ($open[$uid] ?? 0) + 1;
                continue;
            }
            $in = Carbon::parse($p->punched_in_at);
            $out = Carbon::parse($p->punched_out_at);
            $mins = max(0, $in->diffInMinutes($out));
            $worked[$uid] = ($worked[$uid] ?? 0) + $mins;
            $detail[$uid][] = [
                'in' => $p->punched_in_at,
                'out' => $p->punched_out_at,
                'minutes' => $mins,
            ];
        }

        $roleLabel = [];
        $roleCentre = [];
        foreach ($roles as $r) {
            $uid = (int) $r->user_id;
            $roleLabel[$uid] = $roleLabel[$uid] ?? ucfirst(str_replace('_', ' ', $r->role));
            $roleCentre[$uid] = $roleCentre[$uid] ?? $r->centre_id;
        }

        /* What they were last paid, so a rate does not have to be remembered. Read
           only — it seeds the form, and the person entering payroll confirms it. */
        $lastRate = DB::table('payroll_documents')
            ->whereIn('user_id', $userIds)->where('agency_id', $agencyId)
            ->whereNotNull('rate')->where('rate', '>', 0)
            ->orderByDesc('period_end')
            ->get(['user_id', 'rate'])->groupBy('user_id')
            ->map(fn ($g) => (float) $g->first()->rate);

        $staff = DB::table('users')->whereIn('id', $userIds)->whereNull('deleted_at')
            ->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'email'])
            ->map(function ($u) use ($worked, $detail, $open, $roleLabel, $roleCentre, $lastRate) {
                $mins = $worked[(int) $u->id] ?? 0;

                return [
                    'user_id' => (int) $u->id,
                    'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: '(no name)',
                    'email' => $u->email,
                    'role_label' => $roleLabel[(int) $u->id] ?? 'Staff',
                    'centre_id' => $roleCentre[(int) $u->id] ?? null,
                    'hours' => round($mins / 60, 2),
                    'shifts' => count($detail[(int) $u->id] ?? []),
                    'open_punches' => $open[(int) $u->id] ?? 0,
                    'hours_detail' => $detail[(int) $u->id] ?? [],
                    'last_rate' => $lastRate[(int) $u->id] ?? null,
                ];
            })->values();

        return response()->json([
            'period' => ['from' => $from, 'to' => $to],
            'staff' => $staff,
            'centres' => DB::table('centres')->where('agency_id', $agencyId)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * POST /admin/payroll/manual — write the payslips that were reviewed.
     *
     * Only the rows actually sent are paid. The screen lists everybody who COULD be
     * paid; leaving somebody out of the payload is how they are left out of the run,
     * and there is deliberately no "pay everyone" flag to press by accident.
     */
    public function commit(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId, 403);

        $data = $request->validate([
            'period_start' => 'required|date_format:Y-m-d',
            'period_end' => 'required|date_format:Y-m-d',
            'pay_frequency' => 'nullable|string|max:32',
            'rows' => 'required|array|min:1|max:200',
            'rows.*.user_id' => 'required|integer',
            'rows.*.hours' => 'nullable|numeric|min:0|max:1000',
            'rows.*.rate' => 'nullable|numeric|min:0',
            'rows.*.gross' => 'required|numeric|min:0.01',
            'rows.*.net' => 'nullable|numeric|min:0',
            'rows.*.notes' => 'nullable|string|max:2000',
            'rows.*.lines' => 'nullable|array|max:30',
            'rows.*.lines.*.label' => 'required_with:rows.*.lines|string|max:120',
            'rows.*.lines.*.amount' => 'required_with:rows.*.lines|numeric',
            'rows.*.hours_detail' => 'nullable|array|max:200',
        ]);
        [$from, $to] = $this->window($data['period_start'], $data['period_end']);

        /* Every person paid must hold a staff role in THIS agency. Checked against the
           database rather than trusted from the payload — a user id is just a number a
           client can send, and this one moves money. */
        $allowed = DB::table('role_assignments')->where('agency_id', $agencyId)->where('active', 1)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'home_visitor', 'auditor'])
            ->pluck('user_id')->unique()->flip();

        $people = DB::table('users')->whereIn('id', collect($data['rows'])->pluck('user_id'))
            ->get(['id', 'first_name', 'last_name', 'email'])->keyBy('id');

        $created = [];
        $skipped = [];

        DB::transaction(function () use ($data, $agencyId, $from, $to, $allowed, $people, $request, &$created, &$skipped) {
            foreach ($data['rows'] as $row) {
                $uid = (int) $row['user_id'];
                if (! $allowed->has($uid) || ! $people->has($uid)) {
                    $skipped[] = ['user_id' => $uid, 'why' => 'not staff in this agency'];
                    continue;
                }
                $u = $people[$uid];

                /* One payslip per person per period. Pressing the button twice must not
                   pay somebody twice — the run is re-runnable, not duplicating. */
                $dupe = DB::table('payroll_documents')->where('agency_id', $agencyId)
                    ->where('user_id', $uid)->where('period_start', $from)->where('period_end', $to)
                    ->where('source', 'manual')->exists();
                if ($dupe) {
                    $skipped[] = ['user_id' => $uid, 'why' => 'already has a manual payslip for this period'];
                    continue;
                }

                $gross = round((float) $row['gross'], 2);
                // Net defaults to gross: this run records what is PAID, and an agency
                // that does its own deductions enters the net it actually paid.
                $net = isset($row['net']) && $row['net'] !== null ? round((float) $row['net'], 2) : $gross;
                $hours = isset($row['hours']) ? round((float) $row['hours'], 2) : null;

                $id = DB::table('payroll_documents')->insertGetId([
                    'agency_id' => $agencyId,
                    'centre_id' => $row['centre_id'] ?? null,
                    'user_id' => $uid,
                    'payee_name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                    'payee_email' => $u->email,
                    'kind' => 'payslip',
                    'reference' => 'MP-' . Carbon::parse($from)->format('Ym') . '-' . str_pad((string) $uid, 4, '0', STR_PAD_LEFT),
                    'period_start' => $from,
                    'period_end' => $to,
                    'units' => $hours,
                    'unit_label' => $hours !== null ? 'hours' : 'flat',
                    'rate' => isset($row['rate']) ? round((float) $row['rate'], 2) : null,
                    'pay_frequency' => $data['pay_frequency'] ?? 'Manual run',
                    'gross' => $gross,
                    'net' => $net,
                    'currency' => 'CAD',
                    'status' => 'issued',
                    'source' => 'manual',
                    'lines' => ! empty($row['lines']) ? json_encode(array_values($row['lines'])) : null,
                    // The shifts the hours came from, so the figure can be traced.
                    'hours_detail' => ! empty($row['hours_detail']) ? json_encode(array_values($row['hours_detail'])) : null,
                    'notes' => $row['notes'] ?? null,
                    'issued_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $created[] = ['id' => $id, 'user_id' => $uid, 'gross' => $gross, 'net' => $net];
            }

            try {
                Audit::write([
                    'agency_id' => $agencyId,
                    'user_id' => $request->user()->id,
                    'action' => 'payroll.manual_run',
                    'entity_type' => 'payroll_run',
                    'entity_id' => null,
                    'payload' => json_encode([
                        'period' => $from . ' to ' . $to,
                        'payslips' => count($created),
                        'skipped' => count($skipped),
                        'total_gross' => round(array_sum(array_column($created, 'gross')), 2),
                        'total_net' => round(array_sum(array_column($created, 'net')), 2),
                        /* Named, not counted — an audit row that says "12 payslips" cannot
                           answer "was Amna in that run". */
                        'paid' => array_column($created, 'user_id'),
                        'summary' => 'Manual payroll for ' . $from . ' to ' . $to . ' — '
                            . count($created) . ' payslip(s), $'
                            . number_format(array_sum(array_column($created, 'net')), 2) . ' net.',
                    ]),
                    'created_at' => now(),
                ]);
            } catch (Throwable $e) { /* never fail a run over its own audit row */ }
        });

        return response()->json([
            'created' => count($created),
            'skipped' => $skipped,
            'total_net' => round(array_sum(array_column($created, 'net')), 2),
        ], 201);
    }

    /** Dates only, and swapped rather than refused if they arrive backwards. */
    private function window(string $a, string $b): array
    {
        return $a <= $b ? [$a, $b] : [$b, $a];
    }
}
