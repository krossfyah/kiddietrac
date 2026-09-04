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

        /* NO RATE IS CARRIED FORWARD. The data cannot support it.

           This field feeds a multiplication that proposes somebody's pay, so it must be
           an hourly figure. Filtering on unit_label = 'hours' was not enough: all 13
           rate-bearing documents here claim 'hours' while carrying an average "rate" of
           $677.54 against 13.81 units. They are period amounts in a column labelled
           hourly. Amna's is 1185 — against her 186.50 hours that proposes $221,102.50.

           Nothing here separates a real hourly rate from a mislabelled period amount,
           and a wrong guess is a six-figure payslip. So the box starts empty and a
           person types it: a worse form, a much better outcome. The number that decides
           what somebody is paid is always one a human chose for this run. */

        /* What they were last paid in total, whatever shape it took. Useful context for
           deciding what to pay now — and never multiplied by anything, which is why it
           is a separate field with a name that cannot be mistaken for a rate. */
        $lastPaid = DB::table('payroll_documents')
            ->whereIn('user_id', $userIds)->where('agency_id', $agencyId)
            ->orderByDesc('period_end')
            ->get(['user_id', 'net', 'period_end'])->groupBy('user_id')
            ->map(fn ($g) => ['net' => (float) $g->first()->net, 'period_end' => $g->first()->period_end]);

        $staff = DB::table('users')->whereIn('id', $userIds)->whereNull('deleted_at')
            ->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'email'])
            ->map(function ($u) use ($worked, $detail, $open, $roleLabel, $roleCentre, $lastPaid) {
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
                    // Deliberately absent: see the note above the rate lookup.
                    'last_rate' => null,
                    'last_paid' => $lastPaid[(int) $u->id] ?? null,
                ];
            })->values();

        /* PEOPLE PAID BY NAME. The bookkeeper, the accountant, the trades — nobody
           with a login, and nobody with a clock to read, so they carry an amount and no
           hours. Drawn from what this agency has actually paid before, which is also
           what stops the name field being used to invent a payee. */
        $contractors = DB::table('payroll_documents')
            ->where('agency_id', $agencyId)->whereNull('user_id')
            ->whereNotNull('payee_name')->where('payee_name', '!=', '')
            ->select('payee_name', 'payee_email', DB::raw('MAX(period_end) as last_paid'),
                     DB::raw('COUNT(*) as documents'), DB::raw('MAX(net) as last_net'))
            ->groupBy('payee_name', 'payee_email')
            ->orderBy('payee_name')
            ->get()
            ->map(fn ($r) => [
                'payee_name' => $r->payee_name,
                'payee_email' => $r->payee_email,
                'last_paid' => $r->last_paid,
                'documents' => (int) $r->documents,
                'last_net' => (float) $r->last_net,
            ]);

        return response()->json([
            'period' => ['from' => $from, 'to' => $to],
            'staff' => $staff,
            'contractors' => $contractors,
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
            /* Either an account or a name — a contractor has no user_id, and demanding
               one is what kept them out of the run. */
            'rows.*.user_id' => 'required_without:rows.*.payee_name|nullable|integer',
            'rows.*.payee_name' => 'required_without:rows.*.user_id|nullable|string|max:120',
            'rows.*.hours' => 'nullable|numeric|min:0|max:1000',
            'rows.*.rate' => 'nullable|numeric|min:0',
            'rows.*.gross' => 'required|numeric|min:0.01',
            'rows.*.net' => 'nullable|numeric|min:0',
            'rows.*.notes' => 'nullable|string|max:2000',
            'rows.*.lines' => 'nullable|array|max:30',
            'rows.*.lines.*.label' => 'required_with:rows.*.lines|string|max:120',
            'rows.*.lines.*.amount' => 'required_with:rows.*.lines|numeric',
            'rows.*.hours_detail' => 'nullable|array|max:200',
            'rows.*.staff_group' => 'nullable|string|max:16',
            'rows.*.centre_id' => 'nullable|integer',
        ]);
        [$from, $to] = $this->window($data['period_start'], $data['period_end']);

        /* Every person paid must hold a staff role in THIS agency. Checked against the
           database rather than trusted from the payload — a user id is just a number a
           client can send, and this one moves money. */
        $allowed = DB::table('role_assignments')->where('agency_id', $agencyId)->where('active', 1)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'home_visitor', 'auditor'])
            ->pluck('user_id')->unique()->flip();

        $people = DB::table('users')->whereIn('id', collect($data['rows'])->pluck('user_id')->filter())
            ->get(['id', 'first_name', 'last_name', 'email'])->keyBy('id');

        /* The names this agency has paid before. A contractor row is checked against
           this rather than trusted, so the field cannot introduce a payee nobody has
           ever approved — paying somebody new stays a deliberate act elsewhere. */
        $knownPayees = DB::table('payroll_documents')->where('agency_id', $agencyId)
            ->whereNull('user_id')->whereNotNull('payee_name')
            ->pluck('payee_email', 'payee_name');

        $created = [];
        $skipped = [];

        DB::transaction(function () use ($data, $agencyId, $from, $to, $allowed, $people, $request, &$created, &$skipped) {
            foreach ($data['rows'] as $row) {
                $uid = isset($row['user_id']) ? (int) $row['user_id'] : 0;
                $payeeName = trim((string) ($row['payee_name'] ?? ''));
                $isContractor = ! $uid && $payeeName !== '';

                if ($isContractor) {
                    if (! $knownPayees->has($payeeName)) {
                        $skipped[] = ['payee_name' => $payeeName, 'why' => 'not a payee this agency has paid before'];
                        continue;
                    }
                    $u = (object) [
                        'id' => null,
                        'first_name' => $payeeName, 'last_name' => '',
                        'email' => $knownPayees[$payeeName],
                    ];
                } else {
                    if (! $allowed->has($uid) || ! $people->has($uid)) {
                        $skipped[] = ['user_id' => $uid, 'why' => 'not staff in this agency'];
                        continue;
                    }
                    $u = $people[$uid];
                }

                /* One payslip per person per period. Pressing the button twice must not
                   pay somebody twice — the run is re-runnable, not duplicating. */
                $dupeQ = DB::table('payroll_documents')->where('agency_id', $agencyId)
                    ->where('period_start', $from)->where('period_end', $to)->where('source', 'manual');
                $isContractor
                    ? $dupeQ->whereNull('user_id')->where('payee_name', $payeeName)
                    : $dupeQ->where('user_id', $uid);
                if ($dupeQ->exists()) {
                    $skipped[] = [
                        ($isContractor ? 'payee_name' : 'user_id') => $isContractor ? $payeeName : $uid,
                        'why' => 'already has a manual payslip for this period',
                    ];
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
                    'user_id' => $isContractor ? null : $uid,
                    'staff_group' => $isContractor ? 'contractors' : ($row['staff_group'] ?? 'other'),
                    'payee_name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                    'payee_email' => $u->email,
                    'kind' => $isContractor ? 'invoice' : 'payslip',
                    'reference' => 'MP-' . Carbon::parse($from)->format('Ym') . '-'
                        . ($isContractor ? strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $payeeName), 0, 6))
                                         : str_pad((string) $uid, 4, '0', STR_PAD_LEFT)),
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

                $created[] = [
                    'id' => $id,
                    'user_id' => $isContractor ? null : $uid,
                    'payee' => $isContractor ? $payeeName : ($people[$uid]->first_name ?? ''),
                    'gross' => $gross, 'net' => $net,
                ];
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
                        'paid' => array_column($created, 'payee'),
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
