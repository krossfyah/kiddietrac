<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONTARIO ECE WORKFORCE FUNDING — WEG + CWELCC Workforce Compensation (2026-10-13).
 *
 * The rules, from the 2026 guidelines (Toronto Children's Services "2026 WEG/HCCEG &
 * Workforce Compensation Requirements", March 2026; Region of Peel "2026 CWELCC funding
 * guideline" s.5.3 order of operations):
 *
 *   1. Base wage (employer-paid, as of Dec 31 of the prior year)
 *   2. + 2024 GOF hourly enhancement the employer kept paying (Peel; 0 elsewhere)
 *   3. + WEG: up to $2.00/h, only while base + GOF is under the WEG eligibility ceiling
 *        ($33.81 in 2026) and never past it (full WEG at <= $31.81, partial above)
 *   4. + WCF annual wage increase (RECE only): up to $4.00/h (=$1/yr compounded 2023-26),
 *        only up to the WCF eligibility ceiling — $28 program staff, $31 supervisors and
 *        home child care visitors in 2026
 *   5. + WCF wage floor top-up (RECE only): whatever is still needed to reach $25.86
 *        (program staff) / $26.86 (supervisors and home visitors)
 *   (6. wage stability, Peel only — typed, never computed)
 *
 * WCF does not apply to non-RECE staff (director-approved staff, non-RECE supervisors);
 * they can still get WEG and are reported separately. Enhancements are paid for every
 * hour worked including overtime, at the same hourly amount (Peel s.5.9), on every pay
 * cheque, never as a lump sum, within 32 days of receiving funding.
 *
 * Every computed amount can be overridden per person; overrides are checked against the
 * ceilings and the result carries warnings rather than refusing — the agency decides, the
 * screen says plainly when a number is outside the guideline.
 */
final class WorkforceFunding
{
    public const CATEGORIES = [
        'rece_program' => 'RECE program staff',
        'rece_supervisor' => 'RECE supervisor',
        'hcc_visitor' => 'RECE home child care visitor',
        'non_rece' => 'Non-RECE (director-approved staff / supervisor)',
        'not_eligible' => 'Not eligible',
    ];

    public const RECE = ['rece_program', 'rece_supervisor', 'hcc_visitor'];

    public const COMPONENTS = [
        'base' => 'Base wage',
        'gof' => 'GOF wage enhancement (2024, maintained)',
        'weg' => 'Wage Enhancement Grant (WEG)',
        'wcf_increase' => 'Workforce compensation (CWELCC) — annual wage increase',
        'wcf_floor' => 'Workforce compensation (CWELCC) — wage floor top-up',
        'stability' => 'Workforce compensation (CWELCC) — wage stability',
    ];

    /** Components that are provincial funding (what the 32-day rule and reconciliation track). */
    public const FUNDED = ['weg', 'wcf_increase', 'wcf_floor', 'stability'];

    /** The 2026 parameters, verified in both guideline texts. Agencies may edit per year. */
    public const DEFAULT_YEARS = [
        '2026' => [
            'floor_program' => 25.86, 'floor_supervisor' => 26.86,
            'wcf_ceiling_program' => 28.00, 'wcf_ceiling_supervisor' => 31.00,
            'wcf_max_increase' => 4.00, 'weg_max' => 2.00, 'weg_ceiling' => 33.81,
        ],
    ];

    public const PARAM_KEYS = ['floor_program', 'floor_supervisor', 'wcf_ceiling_program', 'wcf_ceiling_supervisor',
        'wcf_max_increase', 'weg_max', 'weg_ceiling'];

    public const STAFF_ROLES = ['educator', 'centre_director', 'home_visitor', 'agency_admin'];

    public const POLICY_KEY = 'workforce_funding_distribution';

    // ───────────────────────────── settings ─────────────────────────────

    public static function ontario(int $agencyId): bool
    {
        return Jurisdictions::codeFor($agencyId) === 'CA-ON';
    }

    public static function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $all = $raw ? (json_decode($raw, true) ?: []) : [];
        $s = (isset($all['workforce_funding']) && is_array($all['workforce_funding'])) ? $all['workforce_funding'] : [];
        $years = self::DEFAULT_YEARS;
        foreach ((array) ($s['years'] ?? []) as $y => $p) {
            if (! preg_match('/^\d{4}$/', (string) $y) || ! is_array($p)) continue;
            $row = $years[(string) $y] ?? [];
            foreach (self::PARAM_KEYS as $k) {
                if (isset($p[$k]) && is_numeric($p[$k])) $row[$k] = round((float) $p[$k], 2);
            }
            $years[(string) $y] = $row;
        }
        ksort($years);

        return [
            'enabled' => (bool) ($s['enabled'] ?? false),
            'fte_hours' => (float) ($s['fte_hours'] ?? 1950),
            'years' => $years,
            'policy_text' => (string) ($s['policy_text'] ?? ''),
            'policy_approved_by' => (string) ($s['policy_approved_by'] ?? ''),
            'policy_approved_on' => $s['policy_approved_on'] ?? null,
            'policy_id' => isset($s['policy_id']) ? (int) $s['policy_id'] : null,
        ];
    }

    public static function saveSettings(int $agencyId, array $s): void
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $all = $raw ? (json_decode($raw, true) ?: []) : [];
        $all['workforce_funding'] = $s;
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all), 'updated_at' => now()]);
    }

    /** On = Ontario agency AND switched on in Settings → Payroll. */
    public static function on(int $agencyId): bool
    {
        return self::ontario($agencyId) && self::settings($agencyId)['enabled'];
    }

    /**
     * The parameters for a calendar year. A year with no values of its own uses the
     * latest earlier year's, and says so ('inherited_from') so the screen can ask the
     * agency to confirm the new year's floor and ceilings.
     */
    public static function params(array $settings, int $year): array
    {
        $years = $settings['years'];
        if (isset($years[(string) $year]) && count($years[(string) $year]) === count(self::PARAM_KEYS)) {
            return $years[(string) $year] + ['year' => $year, 'inherited_from' => null];
        }
        $best = null;
        foreach ($years as $y => $p) {
            if ((int) $y <= $year && count($p) === count(self::PARAM_KEYS)) $best = (int) $y;
        }
        if ($best === null) {
            $best = (int) array_key_first(self::DEFAULT_YEARS);
            $p = self::DEFAULT_YEARS[(string) $best];
        } else {
            $p = $years[(string) $best];
        }

        return $p + ['year' => $year, 'inherited_from' => $best];
    }

    // ─────────────────────────── eligibility ───────────────────────────

    /** Does this person hold an active RECE registration on $date? */
    public static function hasRece(int $userId, string $date): bool
    {
        if (! Schema::hasTable('staff_certifications')) return false;

        return DB::table('staff_certifications')->where('user_id', $userId)->where('cert_type', 'RECE')
            ->where('active', 1)
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', $date))
            ->exists();
    }

    /** The most senior payroll-relevant staff role this person holds IN this agency. */
    public static function roleIn(int $agencyId, int $userId): ?string
    {
        $rank = ['centre_director' => 0, 'home_visitor' => 1, 'educator' => 2, 'agency_admin' => 3];
        $best = null;
        foreach (DB::table('role_assignments')->where('user_id', $userId)->where('agency_id', $agencyId)
            ->where('active', 1)->whereIn('role', self::STAFF_ROLES)->pluck('role') as $r) {
            if ($best === null || $rank[$r] < $rank[$best]) $best = $r;
        }

        return $best;
    }

    public static function derive(?string $role, bool $rece): string
    {
        return match ($role) {
            'centre_director' => $rece ? 'rece_supervisor' : 'non_rece',
            'home_visitor' => $rece ? 'hcc_visitor' : 'non_rece',
            'educator' => $rece ? 'rece_program' : 'non_rece',
            default => 'not_eligible',
        };
    }

    /** The component row in force for a period (latest effective_from that overlaps it). */
    public static function componentFor(int $agencyId, int $userId, string $from, string $to): ?object
    {
        return DB::table('workforce_wage_components')->where('agency_id', $agencyId)->where('user_id', $userId)
            ->where('effective_from', '<=', $to)
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    /**
     * The hourly components for one person, per the order of operations, with warnings.
     *
     * @return array{category:string,derived_category:string,rece:bool,base:?float,gof:float,weg:float,wcf_increase:float,wcf_floor:float,stability:float,total:?float,auto:array,warnings:array,params:array,component_id:?int}
     */
    public static function compute(int $agencyId, int $userId, string $from, string $to, ?array $settings = null, ?object $row = null, ?string $role = null): array
    {
        $settings = $settings ?: self::settings($agencyId);
        $year = (int) substr($to, 0, 4);
        $p = self::params($settings, $year);
        $row = $row ?: self::componentFor($agencyId, $userId, $from, $to);
        $role = $role ?? self::roleIn($agencyId, $userId);
        $rece = self::hasRece($userId, $to);
        $derived = self::derive($role, $rece);
        $cat = ($row && $row->eligibility_override && isset(self::CATEGORIES[$row->eligibility_override]))
            ? $row->eligibility_override : $derived;

        $warn = [];
        $base = ($row && $row->base_wage !== null) ? (float) $row->base_wage : null;
        $gof = $row ? (float) $row->gof : 0.0;
        $out = ['category' => $cat, 'category_label' => self::CATEGORIES[$cat], 'derived_category' => $derived,
            'rece' => $rece, 'role' => $role, 'base' => $base, 'gof' => round($gof, 2),
            'weg' => 0.0, 'wcf_increase' => 0.0, 'wcf_floor' => 0.0, 'stability' => 0.0, 'total' => $base,
            'auto' => ['weg' => 0.0, 'wcf_increase' => 0.0, 'wcf_floor' => 0.0],
            'overridden' => [], 'warnings' => [], 'params' => $p,
            'component_id' => $row ? (int) $row->id : null,
            'effective_from' => $row->effective_from ?? null];

        if ($p['inherited_from'] !== null && $p['inherited_from'] !== $year) {
            $warn[] = ['level' => 'info', 'text' => 'No ' . $year . ' floor/ceiling values set — using ' . $p['inherited_from'] . '\'s. Confirm them in Settings → Payroll.'];
        }
        if ($row && $row->eligibility_override && $row->eligibility_override !== $derived) {
            if (in_array($row->eligibility_override, self::RECE, true) && ! $rece) {
                $warn[] = ['level' => 'warn', 'text' => 'Set as ' . self::CATEGORIES[$cat] . ', but no active RECE registration is on file (Staff → certifications).'];
            } else {
                $warn[] = ['level' => 'info', 'text' => 'Eligibility set by hand (would otherwise be: ' . self::CATEGORIES[$derived] . ').'];
            }
        }
        if ($base === null) {
            $warn[] = ['level' => 'warn', 'text' => 'No base wage recorded — funding cannot be worked out.'];
            $out['warnings'] = $warn;

            return $out;
        }
        if ($cat === 'not_eligible') {
            if ($row && ($row->weg_override !== null || $row->wcf_increase_override !== null || $row->floor_override !== null)) {
                $warn[] = ['level' => 'warn', 'text' => 'Not in an eligible position — the funding overrides are ignored.'];
            }
            $out['warnings'] = $warn;

            return $out;
        }

        $t0 = $base + $gof;
        // 3. WEG
        $wegAuto = round(max(0.0, min($p['weg_max'], $p['weg_ceiling'] - $t0)), 2);
        $weg = ($row && $row->weg_override !== null) ? round((float) $row->weg_override, 2) : $wegAuto;
        if ($row && $row->weg_override !== null) $out['overridden'][] = 'weg';
        if ($t0 >= $p['weg_ceiling']) {
            $warn[] = ['level' => 'info', 'text' => 'Base wage is at or above the WEG eligibility ceiling ($' . number_format($p['weg_ceiling'], 2) . ') — no WEG, and no WCF.'];
        }
        if ($weg > $p['weg_max'] + 0.001) {
            $warn[] = ['level' => 'warn', 'text' => 'WEG of $' . number_format($weg, 2) . '/h is more than the $' . number_format($p['weg_max'], 2) . ' maximum.'];
        }
        if ($weg > 0 && $t0 + $weg > $p['weg_ceiling'] + 0.001) {
            $warn[] = ['level' => 'warn', 'text' => 'Base + WEG ($' . number_format($t0 + $weg, 2) . ') exceeds the WEG eligibility ceiling of $' . number_format($p['weg_ceiling'], 2) . '.'];
        }

        $inc = 0.0; $incAuto = 0.0; $fl = 0.0; $flAuto = 0.0;
        $isRece = in_array($cat, self::RECE, true);
        $ceiling = $cat === 'rece_program' ? $p['wcf_ceiling_program'] : $p['wcf_ceiling_supervisor'];
        $floor = $cat === 'rece_program' ? $p['floor_program'] : $p['floor_supervisor'];
        $t1 = $t0 + $weg;
        if ($isRece) {
            // 4. WCF annual increase — staff must qualify for WEG first.
            $incAuto = ($wegAuto > 0 || $weg > 0) ? round(max(0.0, min($p['wcf_max_increase'], $ceiling - $t1)), 2) : 0.0;
            $inc = ($row && $row->wcf_increase_override !== null) ? round((float) $row->wcf_increase_override, 2) : $incAuto;
            if ($row && $row->wcf_increase_override !== null) $out['overridden'][] = 'wcf_increase';
            if ($inc > $p['wcf_max_increase'] + 0.001) {
                $warn[] = ['level' => 'warn', 'text' => 'WCF increase of $' . number_format($inc, 2) . '/h is more than the $' . number_format($p['wcf_max_increase'], 2) . ' maximum.'];
            }
            if ($inc > 0 && $t1 + $inc > $ceiling + 0.001) {
                $warn[] = ['level' => 'warn', 'text' => 'Base + WEG + WCF increase ($' . number_format($t1 + $inc, 2) . ') exceeds the $' . number_format($ceiling, 2) . ' WCF eligibility ceiling.'];
            }
            if ($t1 >= $ceiling && $inc <= 0) {
                $warn[] = ['level' => 'info', 'text' => 'At or above the $' . number_format($ceiling, 2) . ' WCF ceiling — no annual increase.'];
            }
            // 5. wage floor
            $t2 = $t1 + $inc;
            $flAuto = round(max(0.0, $floor - $t2), 2);
            $fl = ($row && $row->floor_override !== null) ? round((float) $row->floor_override, 2) : $flAuto;
            if ($row && $row->floor_override !== null) $out['overridden'][] = 'wcf_floor';
            if ($fl > $flAuto + 0.001) {
                $warn[] = ['level' => 'warn', 'text' => 'Wage floor top-up of $' . number_format($fl, 2) . '/h is more than needed to reach the $' . number_format($floor, 2) . ' floor ($' . number_format($flAuto, 2) . ').'];
            }
        }
        $stab = $row ? round((float) $row->stability, 2) : 0.0;
        $total = round($t0 + $weg + $inc + $fl + $stab, 2);
        if ($isRece && $total + 0.001 < $floor) {
            $warn[] = ['level' => 'warn', 'text' => 'Total hourly wage $' . number_format($total, 2) . ' is BELOW the $' . number_format($floor, 2) . ' wage floor.'];
        }
        if (! $isRece && $cat === 'non_rece' && $row && ($row->wcf_increase_override !== null || $row->floor_override !== null)) {
            $warn[] = ['level' => 'warn', 'text' => 'Workforce compensation (WCF) does not apply to non-RECE staff — WCF overrides are ignored.'];
        }

        $out['weg'] = $weg; $out['wcf_increase'] = $inc; $out['wcf_floor'] = $fl; $out['stability'] = $stab;
        $out['total'] = $total;
        $out['auto'] = ['weg' => $wegAuto, 'wcf_increase' => $incAuto, 'wcf_floor' => $flAuto];
        $out['floor'] = $isRece ? $floor : null;
        $out['ceiling'] = $isRece ? $ceiling : null;
        $out['warnings'] = $warn;

        return $out;
    }

    /** Hours × rate lines for one person (base first). Rates of 0 are left out. */
    public static function lines(array $c, float $hours): array
    {
        $out = [];
        foreach (['base', 'gof', 'weg', 'wcf_increase', 'wcf_floor', 'stability'] as $k) {
            $rate = (float) ($c[$k] ?? 0);
            if ($rate <= 0) continue;
            $out[] = ['component' => $k, 'label' => self::COMPONENTS[$k], 'hours' => round($hours, 2),
                'rate' => round($rate, 2), 'amount' => round($hours * $rate, 2)];
        }

        return $out;
    }

    // ───────────────────────────── staff / hours ─────────────────────────────

    /**
     * Staff who can be paid in this agency: an active staff role here, at an open centre
     * (or an agency-level role), not deleted.
     *
     * @return array<int, array{id:int,name:string,role:string,centre_id:?int}>
     */
    public static function staff(int $agencyId): array
    {
        $open = array_flip(Live::centreIds($agencyId));
        $roles = DB::table('role_assignments')->where('agency_id', $agencyId)->where('active', 1)
            ->whereIn('role', self::STAFF_ROLES)->get(['user_id', 'role', 'centre_id']);
        $keep = [];
        foreach ($roles as $r) {
            if ($r->centre_id && ! isset($open[(int) $r->centre_id])) continue;
            $keep[(int) $r->user_id][] = $r;
        }
        if (! $keep) return [];
        $users = DB::table('users')->whereIn('id', array_keys($keep))->whereNull('deleted_at')
            ->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name']);
        $rank = ['centre_director' => 0, 'home_visitor' => 1, 'educator' => 2, 'agency_admin' => 3];
        $out = [];
        foreach ($users as $u) {
            $best = null; $centre = null;
            foreach ($keep[(int) $u->id] as $r) {
                if ($best === null || $rank[$r->role] < $rank[$best]) { $best = $r->role; $centre = $r->centre_id; }
            }
            $out[(int) $u->id] = ['id' => (int) $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name) ?: '(no name)',
                'role' => $best, 'centre_id' => $centre ? (int) $centre : null];
        }

        return $out;
    }

    /** Closed-punch hours per person in an agency-local date range. */
    public static function hours(int $agencyId, array $userIds, string $from, string $to): array
    {
        if (! $userIds) return [];
        [$a, $b] = AgencyTime::spanRange($agencyId, $from, $to);
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $out = [];
        foreach (DB::table('time_punches')->whereIn('user_id', $userIds)->whereIn('centre_id', $centres ?: [0])
            ->where('punched_in_at', '>=', $a)->where('punched_in_at', '<', $b)->whereNotNull('punched_out_at')
            ->get(['user_id', 'punched_in_at', 'punched_out_at']) as $p) {
            $m = max(0, Carbon::parse($p->punched_in_at)->diffInMinutes(Carbon::parse($p->punched_out_at)));
            $out[(int) $p->user_id] = ($out[(int) $p->user_id] ?? 0) + $m;
        }

        return array_map(fn ($m) => round($m / 60, 2), $out);
    }

    /** For the manual payroll run: per user, the components and the lines to add. */
    public static function forRun(int $agencyId, array $hoursByUser, string $from, string $to): array
    {
        if (! self::on($agencyId)) return [];
        $settings = self::settings($agencyId);
        $out = [];
        foreach ($hoursByUser as $uid => $hours) {
            $c = self::compute($agencyId, (int) $uid, $from, $to, $settings);
            if ($c['base'] === null && $c['category'] === 'not_eligible') continue;
            $funded = array_values(array_filter(self::lines($c, (float) $hours), fn ($l) => $l['component'] !== 'base'));
            $out[(int) $uid] = [
                'category' => $c['category'], 'category_label' => $c['category_label'],
                'base' => $c['base'], 'total' => $c['total'],
                'rates' => ['gof' => $c['gof'], 'weg' => $c['weg'], 'wcf_increase' => $c['wcf_increase'], 'wcf_floor' => $c['wcf_floor'], 'stability' => $c['stability']],
                'lines' => $funded,
                'warnings' => array_values(array_filter($c['warnings'], fn ($w) => $w['level'] === 'warn')),
            ];
        }

        return $out;
    }

    /**
     * Record the component lines of a payslip that was just written. Lines come from the
     * payslip as approved (amounts as paid); the category is the server's own answer.
     */
    public static function recordLines(int $agencyId, int $docId, int $userId, ?int $centreId, string $from, string $to, ?float $hours, ?float $rate, array $lines, ?string $paidOn = null): int
    {
        if (! Schema::hasTable('workforce_pay_lines')) return 0;
        $funded = array_values(array_filter($lines, fn ($l) => isset($l['component']) && in_array($l['component'], ['gof', 'weg', 'wcf_increase', 'wcf_floor', 'stability'], true)));
        if (! $funded) return 0;
        $c = self::compute($agencyId, $userId, $from, $to);
        $paidOn = $paidOn ?: AgencyTime::today($agencyId);
        $rows = [];
        $now = now();
        if ($hours !== null && $rate !== null && $rate > 0) {
            $rows[] = ['component' => 'base', 'hours' => $hours, 'rate' => $rate, 'amount' => round($hours * $rate, 2)];
        }
        foreach ($funded as $l) {
            $rows[] = ['component' => $l['component'], 'hours' => isset($l['hours']) ? (float) $l['hours'] : $hours,
                'rate' => isset($l['rate']) ? (float) $l['rate'] : null, 'amount' => round((float) $l['amount'], 2)];
        }
        foreach ($rows as &$r) {
            $r += ['agency_id' => $agencyId, 'payroll_document_id' => $docId, 'user_id' => $userId, 'centre_id' => $centreId,
                'period_start' => $from, 'period_end' => $to, 'paid_on' => $paidOn, 'category' => $c['category'],
                'created_at' => $now, 'updated_at' => $now];
        }
        unset($r);
        DB::table('workforce_pay_lines')->insert($rows);

        return count($rows);
    }

    // ───────────────────────────── 32-day check ─────────────────────────────

    /** Each receipt with its 32-day verdict. */
    public static function receiptChecks(int $agencyId, ?int $year = null): array
    {
        $q = DB::table('workforce_funding_receipts')->where('agency_id', $agencyId);
        if ($year) $q->whereYear('received_on', $year);
        $today = AgencyTime::today($agencyId);
        $out = [];
        foreach ($q->orderByDesc('received_on')->orderByDesc('id')->get() as $r) {
            $deadline = Carbon::parse($r->received_on)->addDays(32)->toDateString();
            $comps = $r->stream === 'weg' ? ['weg'] : ($r->stream === 'wcf' ? ['wcf_increase', 'wcf_floor', 'stability'] : self::FUNDED);
            $first = DB::table('workforce_pay_lines as l')
                ->join('payroll_documents as d', 'd.id', '=', 'l.payroll_document_id')
                ->where('l.agency_id', $agencyId)->where('d.agency_id', $agencyId)
                ->where(fn ($w) => $w->whereNull('d.status')->orWhere('d.status', '!=', 'void'))
                ->whereIn('l.component', $comps)
                ->where('l.paid_on', '>=', $r->received_on)->where('l.paid_on', '<=', $deadline)
                ->orderBy('l.paid_on')->first(['l.paid_on', 'd.period_start', 'd.period_end']);
            if ($r->voided_at) $state = 'void';
            elseif ($first) $state = 'met';
            elseif ($deadline < $today) $state = 'missed';
            else $state = 'pending';
            $daysLeft = (int) Carbon::parse($today)->diffInDays(Carbon::parse($deadline), false);
            $out[] = [
                'id' => (int) $r->id, 'received_on' => $r->received_on, 'amount' => (float) $r->amount, 'stream' => $r->stream,
                'period_from' => $r->period_from, 'period_to' => $r->period_to, 'reference' => $r->reference, 'notes' => $r->notes,
                'centre_id' => $r->centre_id ? (int) $r->centre_id : null,
                'voided_at' => $r->voided_at, 'void_reason' => $r->void_reason,
                'deadline' => $deadline, 'state' => $state, 'days_left' => $daysLeft,
                'first_paid_on' => $first->paid_on ?? null,
                'first_period' => $first ? ($first->period_start . ' to ' . $first->period_end) : null,
            ];
        }

        return $out;
    }

    // ───────────────────────────── year-end ─────────────────────────────

    public static function yearEnd(int $agencyId, int $year): array
    {
        $settings = self::settings($agencyId);
        $fte = max(1.0, (float) $settings['fte_hours']);
        $lines = DB::table('workforce_pay_lines as l')
            ->join('payroll_documents as d', 'd.id', '=', 'l.payroll_document_id')
            ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->where('l.agency_id', $agencyId)->where('d.agency_id', $agencyId)
            ->where(fn ($w) => $w->whereNull('d.status')->orWhere('d.status', '!=', 'void'))
            ->whereYear('l.period_end', $year)
            ->orderBy('l.period_end')
            ->get(['l.*', 'u.first_name', 'u.last_name', 'd.vacation_pay']);

        $people = [];
        $docsSeen = [];
        foreach ($lines as $l) {
            $uid = (int) $l->user_id;
            $p = $people[$uid] ?? ['user_id' => $uid, 'name' => trim(($l->first_name ?? '') . ' ' . ($l->last_name ?? '')) ?: ('User ' . $uid),
                'category' => $l->category, 'hours' => 0.0, 'base' => 0.0, 'gof' => 0.0, 'weg' => 0.0, 'wcf_increase' => 0.0,
                'wcf_floor' => 0.0, 'stability' => 0.0, 'vacation_pay' => 0.0, 'payslips' => 0, '_fhours' => []];
            $p['category'] = $l->category ?: $p['category'];   // latest period wins (ordered by period_end)
            $comp = (string) $l->component;
            if (isset($p[$comp])) $p[$comp] += (float) $l->amount;
            $did = (int) $l->payroll_document_id;
            if ($comp === 'base') {
                $p['hours'] += (float) $l->hours;
            } else {
                $p['_fhours'][$did] = max($p['_fhours'][$did] ?? 0, (float) $l->hours);
            }
            if (! isset($docsSeen[$uid][$did])) {
                $docsSeen[$uid][$did] = true;
                $p['payslips']++;
                $p['vacation_pay'] += (float) ($l->vacation_pay ?? 0);
            }
            $people[$uid] = $p;
        }
        // Hours from the base line; a payslip with funded lines and no base line still counts its hours once.
        foreach ($lines->where('component', 'base')->groupBy('user_id') as $uid => $g) {
            foreach ($g as $l) unset($people[(int) $uid]['_fhours'][(int) $l->payroll_document_id]);
        }
        foreach ($people as $uid => &$p) {
            $p['hours'] += array_sum($p['_fhours']);
            unset($p['_fhours']);
            foreach (['hours', 'base', 'gof', 'weg', 'wcf_increase', 'wcf_floor', 'stability', 'vacation_pay'] as $k) $p[$k] = round($p[$k], 2);
            $p['wcf'] = round($p['wcf_increase'] + $p['wcf_floor'] + $p['stability'], 2);
            $p['funding'] = round($p['weg'] + $p['wcf'], 2);
            $p['benefit_allocation'] = round($p['funding'] * 0.175, 2);
            $p['fte'] = round($p['hours'] / $fte, 3);
            $p['category_label'] = self::CATEGORIES[$p['category']] ?? (string) $p['category'];
        }
        unset($p);
        uasort($people, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $rece = array_values(array_filter($people, fn ($p) => in_array($p['category'], self::RECE, true)));
        $nonRece = array_values(array_filter($people, fn ($p) => $p['category'] === 'non_rece'));
        $other = array_values(array_filter($people, fn ($p) => ! in_array($p['category'], self::RECE, true) && $p['category'] !== 'non_rece'));

        $positions = [];
        foreach ($people as $p) {
            $k = $p['category'] ?: 'not_eligible';
            $x = $positions[$k] ?? ['category' => $k, 'label' => self::CATEGORIES[$k] ?? $k, 'staff' => 0, 'hours' => 0.0, 'fte' => 0.0,
                'base' => 0.0, 'gof' => 0.0, 'weg' => 0.0, 'wcf_increase' => 0.0, 'wcf_floor' => 0.0, 'stability' => 0.0, 'funding' => 0.0,
                'benefit_allocation' => 0.0, 'vacation_pay' => 0.0, 'staff_weg' => 0, 'staff_wcf_increase' => 0, 'staff_wcf_floor' => 0];
            $x['staff']++;
            foreach (['hours', 'fte', 'base', 'gof', 'weg', 'wcf_increase', 'wcf_floor', 'stability', 'funding', 'benefit_allocation', 'vacation_pay'] as $f) $x[$f] += $p[$f];
            if ($p['weg'] > 0) $x['staff_weg']++;
            if ($p['wcf_increase'] > 0) $x['staff_wcf_increase']++;
            if ($p['wcf_floor'] > 0) $x['staff_wcf_floor']++;
            $positions[$k] = $x;
        }
        $order = array_keys(self::CATEGORIES);
        uksort($positions, fn ($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));
        foreach ($positions as &$x) foreach (['hours', 'fte', 'base', 'gof', 'weg', 'wcf_increase', 'wcf_floor', 'stability', 'funding', 'benefit_allocation', 'vacation_pay'] as $f) $x[$f] = round($x[$f], $f === 'fte' ? 3 : 2);
        unset($x);

        $tot = fn (array $list) => array_reduce($list, function ($a, $p) {
            foreach (['hours', 'fte', 'base', 'gof', 'weg', 'wcf_increase', 'wcf_floor', 'stability', 'wcf', 'funding', 'benefit_allocation', 'vacation_pay'] as $f) $a[$f] = round(($a[$f] ?? 0) + $p[$f], $f === 'fte' ? 3 : 2);
            $a['staff'] = ($a['staff'] ?? 0) + 1;

            return $a;
        }, []);

        // Funding received vs paid out.
        $rec = DB::table('workforce_funding_receipts')->where('agency_id', $agencyId)->whereNull('voided_at')
            ->where(fn ($w) => $w->whereYear('period_from', $year)->orWhere(fn ($x) => $x->whereNull('period_from')->whereYear('received_on', $year)))
            ->get(['stream', 'amount']);
        $received = ['weg' => 0.0, 'wcf' => 0.0, 'both' => 0.0];
        foreach ($rec as $r) $received[$r->stream] = ($received[$r->stream] ?? 0) + (float) $r->amount;
        $all = $tot(array_values($people));
        $paidWeg = (float) ($all['weg'] ?? 0); $paidWcf = (float) ($all['wcf'] ?? 0);
        $recTotal = round(array_sum($received), 2);
        $paidTotal = round($paidWeg + $paidWcf, 2);

        // Payslips this year for eligible staff that carry NO component breakdown (e.g. synced from another payroll system).
        $withLines = DB::table('workforce_pay_lines')->where('agency_id', $agencyId)->distinct()->pluck('payroll_document_id')->all();
        $unsplit = DB::table('payroll_documents')->where('agency_id', $agencyId)->whereNotNull('user_id')
            ->where('kind', 'payslip')->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'void'))
            ->whereYear('period_end', $year)
            ->when($withLines, fn ($q) => $q->whereNotIn('id', $withLines))
            ->count();

        return [
            'year' => $year, 'fte_hours' => $fte, 'params' => self::params($settings, $year),
            'rece' => $rece, 'non_rece' => $nonRece, 'other' => $other,
            'positions' => array_values($positions),
            'totals' => ['rece' => $tot($rece), 'non_rece' => $tot($nonRece), 'all' => $all],
            'funding' => [
                'received' => ['weg' => round($received['weg'], 2), 'wcf' => round($received['wcf'], 2), 'combined' => round($received['both'], 2), 'total' => $recTotal],
                'paid' => ['weg' => round($paidWeg, 2), 'wcf' => round($paidWcf, 2), 'total' => $paidTotal],
                // Funding carries up to 17.5% for employer benefits on the enhancement amounts
                // (CPP, EI, WSIB, EHT, vacation/holiday pay). Shown as the allocation, not as
                // the employer's actual benefit cost, which payroll here does not record.
                'benefit_allocation' => round($paidTotal * 0.175, 2),
                'difference' => round($recTotal - $paidTotal - round($paidTotal * 0.175, 2), 2),
            ],
            'unsplit_payslips' => $unsplit,
        ];
    }
}
