<?php

declare(strict_types=1);

namespace App\Support\Payroll;

use App\Support\AgencyTime;
use App\Support\HrLeave;
use App\Support\TestAgency;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 2 (2026-11-27): the Starlight (agency 26) demo payroll year, written by
 * WebDemoShowcase::payrollStory (nightly, after kt:reset-web-demo; every id it writes goes in the showcase ledger and is
 * removed before the next run). Fictional demo staff only (HrLeave::staffIds already drops platform admins / outsiders).
 *
 * Starlight's centres are in California; payroll is Canada-only until the US phase, so every demo employee's tax setup says
 * "province of employment: Ontario" (an explicit employer setting, not the centre's address).
 *
 *  - the payroll switch on, the accountant notice accepted by Olivia (the owner, who is not on payroll), a demo CRA number;
 *  - tax setups (ON, every two weeks, 4% vacation pay for hourly educators), pay rates where the demo had none, birth dates;
 *  - TD1s recorded on paper for everyone but Lucas (his line shows "basic amounts used"); Sofia claims more, Ava asks for
 *    $25 extra tax a pay;
 *  - a year of weekday clock-ins for the hourly staff before the demo's own recent ones (hours always come from HrTime);
 *  - 26 biweekly pay dates in the year: every period that has ended is run through the real engine (draft -> review ->
 *    approved by Olivia -> finalized), the current one is left as a draft; Marcus joined this payroll in April with an
 *    opening year-to-date balance for what the previous payroll paid him.
 * Test agency: finalize records each payslip notice as skipped, nothing is sent.
 */
final class PayrollDemo
{
    private const RATES = ['Maya' => [24.50, 'hourly'], 'Grace' => [21.00, 'hourly'], 'Marcus' => [21.50, 'hourly'], 'Elena' => [64000, 'salary'], 'Harper' => [72000, 'salary']];

    private const DOB = ['Harper' => '1979-03-14', 'Elena' => '1984-09-02', 'Maya' => '1996-05-21', 'Lucas' => '2001-11-08', 'Sofia' => '1990-02-17',
        'Noah' => '1998-07-30', 'Ethan' => '1993-12-05', 'Ava' => '1988-04-26', 'Grace' => '1975-08-11', 'Marcus' => '1982-01-19', 'Nina' => '2007-06-03'];

    /** @return array<string, int[]> table => ids written */
    public static function story(int $agencyId, array $byName, int $olivia): array
    {
        abort_unless(TestAgency::isAgency($agencyId), 500, 'The payroll demo only runs in a test agency.');
        $made = [];
        $add = function (string $t, array $row) use (&$made) {
            $id = (int) DB::table($t)->insertGetId($row);
            $made[$t][] = $id;

            return $id;
        };
        $now = now();
        $year = (int) AgencyTime::now($agencyId)->format('Y');
        while ($year > 2026 && ! TaxTables::hasYear('CA', $year)) {
            $year--;
        }
        if (! TaxTables::hasYear('CA', $year)) {
            return $made;
        }
        $emp = PayrollEmployer::settings($agencyId);
        PayrollEmployer::save($agencyId, ['enabled' => true, 'note' => 'Starlight demo payroll year', 'remitter_type' => 'regular', 'rp_number' => '123456789RP0001']
            + ($emp['acknowledged_at'] ? [] : ['acknowledged_at' => $now->toIso8601String(), 'acknowledged_by' => $olivia ?: null]));

        $staff = HrLeave::staffIds($agencyId);
        $names = DB::table('users')->whereIn('id', $staff ?: [0])->get(['id', 'first_name', 'pay_rate', 'pay_type', 'date_of_birth'])->keyBy('id');
        $who = [];
        foreach ($names as $u) {
            $who[(string) $u->first_name] = (int) $u->id;
            $first = (string) $u->first_name;
            $upd = [];
            if (isset(self::RATES[$first]) && $u->pay_rate === null) {
                $upd += ['pay_rate' => self::RATES[$first][0], 'pay_type' => self::RATES[$first][1]];
            }
            if (isset(self::DOB[$first]) && ! $u->date_of_birth) {
                $upd['date_of_birth'] = self::DOB[$first];
            }
            if ($upd) {
                DB::table('users')->where('id', $u->id)->update($upd);
            }
        }
        $pay = DB::table('users')->whereIn('id', $staff ?: [0])->get(['id', 'first_name', 'pay_rate', 'pay_type'])->keyBy('id');

        // Tax setups + TD1s.
        $basics = PayrollTax::basics('ON', $year, $year . '-01-01');
        foreach ($pay as $u) {
            if ($u->pay_rate === null || (int) $u->id === $olivia) {
                continue;
            }
            DB::table('payroll_tax_profiles')->where('agency_id', $agencyId)->where('user_id', $u->id)->delete();
            $add('payroll_tax_profiles', ['agency_id' => $agencyId, 'user_id' => $u->id, 'country' => 'CA', 'jurisdiction' => 'ON', 'pay_frequency' => 26,
                'ei_exempt' => 0, 'qpip_exempt' => 0, 'vacation_pay_pct' => $u->pay_type === 'salary' ? null : 4.00,
                'notes' => 'Demo: Ontario payroll (the demo centres are in California; US payroll comes later)', 'updated_by_id' => $olivia ?: null,
                'created_at' => $now, 'updated_at' => $now]);
            if ($u->first_name === 'Lucas') {
                continue;   // no TD1: his line shows the "basic amounts used" warning
            }
            $fed = (float) $basics['federal_basic'];
            $prov = (float) $basics['provincial_basic'];
            $extra = 0.0;
            if ($u->first_name === 'Sofia') {
                $fed += 9500;   // a demo spouse amount
                $prov += 5000;
            }
            if ($u->first_name === 'Ava') {
                $extra = 25.0;
            }
            DB::table('payroll_tax_forms')->where('agency_id', $agencyId)->where('user_id', $u->id)->where('tax_year', $year)->where('source', 'paper')->delete();
            $add('payroll_tax_forms', ['agency_id' => $agencyId, 'user_id' => $u->id, 'country' => 'CA', 'form' => 'TD1', 'tax_year' => $year, 'jurisdiction' => 'ON',
                'claims' => json_encode(['federal' => ['total_claim' => $fed, 'additional_tax' => $extra, 'zone_deduction' => 0, 'more_than_one_employer' => false, 'exempt' => false],
                    'provincial' => ['total_claim' => $prov]]),
                'source' => 'paper', 'status' => 'signed', 'submitted_by_id' => $olivia ?: null, 'signed_at' => $year . '-01-05 17:00:00', 'created_at' => $now, 'updated_at' => $now]);
        }

        // Pay dates: every second Friday from the first in January; period = the two weeks ending the Saturday before.
        $first = Carbon::create($year, 1, 1)->next(Carbon::FRIDAY);
        if ($first->day > 7) {
            $first->subDays(7);
        }
        $first->addWeek();   // Jan 9 in 2026
        $today = AgencyTime::today($agencyId);
        $tz = AgencyTime::tz($agencyId) ?: 'America/Los_Angeles';
        $start = $first->copy()->subDays(6 + 13)->toDateString();

        // Weekday clock-ins for the hourly staff, before the demo's own recent ones.
        $centreOf = DB::table('role_assignments')->whereIn('user_id', $staff ?: [0])->where('active', 1)->whereNotNull('centre_id')->pluck('centre_id', 'user_id');
        foreach ($pay as $u) {
            if ($u->pay_type !== 'hourly' || $u->pay_rate === null || ! isset($centreOf[$u->id])) {
                continue;
            }
            $firstOwn = DB::table('time_punches')->where('user_id', $u->id)->where('punched_in_at', '>=', $start)->where(fn ($w) => $w->whereNull('notes')->orWhere('notes', '!=', 'Demo payroll year'))->min('punched_in_at');
            $until = $firstOwn ? Carbon::parse($firstOwn, 'UTC')->setTimezone($tz)->toDateString() : $today;
            $part = in_array($u->first_name, ['Nina', 'Noah'], true);
            for ($d = Carbon::parse($start, $tz); $d->toDateString() < $until; $d->addDay()) {
                if ($d->isWeekend() || (crc32($u->id . $d->toDateString()) % 17 === 0) || ($d->month === 12 && $d->day >= 24 && $d->day <= 26) || ($d->month === 1 && $d->day === 1)) {
                    continue;
                }
                $in = $d->copy()->setTime($part ? 9 : 7, $part ? 0 : 30 + (crc32('i' . $u->id . $d) % 3) * 5);
                $out = $in->copy()->addMinutes(($part ? ($d->isFriday() ? 240 : 330) : 480) + (crc32('o' . $u->id . $d) % 4) * 5);
                $add('time_punches', ['user_id' => $u->id, 'centre_id' => (int) $centreOf[$u->id], 'punched_in_at' => $in->copy()->utc()->toDateTimeString(),
                    'punched_out_at' => $out->copy()->utc()->toDateTimeString(), 'notes' => 'Demo payroll year', 'source' => 'web', 'created_at' => $now]);
            }
        }

        // The runs.
        $harper = $who['Harper'] ?? $olivia;
        $marcus = $who['Marcus'] ?? 0;
        $openingDone = false;
        PayRuns::$skipExtras = true;
        try {
            for ($k = 0; $k < 26; $k++) {
                $payDate = $first->copy()->addDays(14 * $k);
                $pEnd = $payDate->copy()->subDays(6);
                $pStart = $pEnd->copy()->subDays(13);
                if ($pStart->toDateString() > $today) {
                    break;
                }
                $done = $pEnd->toDateString() < $today;
                PayRuns::$skipExtras = $done;
                if ($marcus && ! $openingDone && $payDate->toDateString() >= $year . '-04-01') {
                    // What the previous payroll paid Marcus up to March 31: the figures his left-out lines worked out.
                    $s = DB::table('payroll_run_lines')->whereIn('run_id', $made['payroll_runs'] ?? [0])->where('user_id', $marcus)->where('included', 0)
                        ->selectRaw('SUM(gross) g, SUM(cpp) c, SUM(cpp2) c2, SUM(ei) e, SUM(income_tax) t')->first();
                    DB::table('payroll_ytd_openings')->where('agency_id', $agencyId)->where('user_id', $marcus)->where('tax_year', $year)->delete();
                    $add('payroll_ytd_openings', ['agency_id' => $agencyId, 'user_id' => $marcus, 'tax_year' => $year, 'as_of' => $year . '-03-31',
                        'gross' => round((float) $s->g, 2), 'pensionable' => round((float) $s->g, 2), 'insurable' => round((float) $s->g, 2), 'cpp' => round((float) $s->c, 2),
                        'cpp2' => round((float) $s->c2, 2), 'ei' => round((float) $s->e, 2), 'qpip' => 0, 'income_tax' => round((float) $s->t, 2), 'bonus' => 0,
                        'note' => 'Demo: paid by the previous payroll provider January to March', 'entered_by_id' => $olivia ?: null, 'created_at' => $now, 'updated_at' => $now]);
                    $openingDone = true;
                }
                $id = $add('payroll_runs', ['agency_id' => $agencyId, 'centre_id' => null, 'country' => 'CA', 'reference' => 'PR-' . $year . '-' . str_pad((string) ($k + 1), 2, '0', STR_PAD_LEFT),
                    'kind' => 'regular', 'period_start' => $pStart->toDateString(), 'period_end' => $pEnd->toDateString(), 'pay_date' => $payDate->toDateString(), 'periods' => 26,
                    'status' => 'draft', 'notes' => $done ? null : 'This period is still running: recalculate when it ends.', 'created_by_id' => $harper, 'created_at' => $now, 'updated_at' => $now]);
                $run = DB::table('payroll_runs')->where('id', $id)->first();
                PayRuns::build($run);
                $made['payroll_run_lines'] = array_merge($made['payroll_run_lines'] ?? [], DB::table('payroll_run_lines')->where('run_id', $id)->pluck('id')->all());
                if ($marcus && $payDate->toDateString() < $year . '-04-01') {
                    DB::table('payroll_run_lines')->where('run_id', $id)->where('user_id', $marcus)->update(['included' => 0, 'note' => 'Paid by the previous payroll provider until March']);
                }
                if ($k === 22) {
                    // A bonus, so the bonus method shows on a payslip.
                    $l = DB::table('payroll_run_lines')->where('run_id', $id)->where('user_id', $who['Sofia'] ?? 0)->first();
                    if ($l) {
                        DB::table('payroll_run_lines')->where('id', $l->id)->update(['bonus' => 500, 'note' => 'Demo: lead educator bonus']);
                        PayRuns::calcLine((int) $l->id, $run);
                    }
                }
                if (! $done) {
                    DB::table('payroll_runs')->where('id', $id)->update(['totals' => json_encode(PayRuns::totals($id))]);
                    break;
                }
                foreach (DB::table('payroll_run_lines')->where('run_id', $id)->where('included', 1)->where('state', 'error')->pluck('id') as $bad) {
                    DB::table('payroll_run_lines')->where('id', $bad)->update(['included' => 0]);
                }
                $at = $pEnd->copy()->addDays(2)->setTime(10, 0)->toDateTimeString();
                DB::table('payroll_runs')->where('id', $id)->update(['status' => 'approved', 'totals' => json_encode(PayRuns::totals($id)), 'submitted_by_id' => $harper, 'submitted_at' => $at,
                    'approved_by_id' => $olivia ?: $harper, 'approved_at' => $pEnd->copy()->addDays(3)->setTime(9, 0)->toDateTimeString()]);
                PayRuns::finalize($id, $olivia ?: $harper);
                DB::table('payroll_runs')->where('id', $id)->update(['finalized_at' => $pEnd->copy()->addDays(3)->setTime(9, 30)->toDateTimeString()]);
                $docs = DB::table('payroll_documents')->where('payroll_run_id', $id)->pluck('id')->all();
                $made['payroll_documents'] = array_merge($made['payroll_documents'] ?? [], $docs);
                $made['payslip_notices'] = array_merge($made['payslip_notices'] ?? [], DB::table('payslip_notices')->whereIn('payroll_document_id', $docs ?: [0])->pluck('id')->all());
                DB::table('payroll_documents')->whereIn('id', $docs ?: [0])->update(['issued_at' => $pEnd->copy()->addDays(3)->setTime(9, 30)->toDateTimeString(),
                    'status' => 'paid', 'paid_at' => $payDate->copy()->setTime(9, 0)->toDateTimeString()]);
            }
        } finally {
            PayRuns::$skipExtras = false;
        }

        return $made;
    }
}
