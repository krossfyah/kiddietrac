<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One answer to "what is this person paid, and for what".
 *
 * profile() and units() were private to PayController; they live here so the stored
 * payroll ledger and the live payslip screen cannot drift into quoting different
 * numbers for the same week. PayController delegates to these.
 */
class Payroll
{
    public const TZ = 'America/Toronto';

    /** Educators and home visitors are one payroll; everybody else is the other. */
    public const RANK = [
        'agency_admin' => 0, 'platform_admin' => 0, 'centre_director' => 1,
        'home_visitor' => 2, 'educator' => 3, 'auditor' => 4, 'guardian' => 5,
    ];

    public const LABEL = [
        'agency_admin' => 'Admin', 'platform_admin' => 'Admin', 'centre_director' => 'Director',
        'educator' => 'Educator', 'home_visitor' => 'Home visitor', 'auditor' => 'Auditor',
        'guardian' => 'Parent',
    ];

    /** The senior-most active role, so an educator who is also a director reads as a director. */
    public static function primaryRole(int $userId): ?string
    {
        $best = null;
        foreach (DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)->pluck('role') as $r) {
            if ($best === null || (self::RANK[$r] ?? 9) < (self::RANK[$best] ?? 9)) {
                $best = $r;
            }
        }

        return $best;
    }

    public static function groupFor(?string $role): string
    {
        return in_array($role, ['educator', 'home_visitor'], true) ? 'educators' : 'other';
    }

    /** Rate + pay type. Identical to what the payslip screen has always used. */
    public static function profile(int $userId): array
    {
        $u = DB::table('users')->where('id', $userId)
            ->first(['first_name', 'last_name', 'email', 'phone', 'pay_rate', 'pay_type', 'profile_extras']);
        $roles = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)->pluck('role')->all();
        $isVisitor = in_array('home_visitor', $roles, true)
            && ! array_intersect($roles, ['educator', 'centre_director', 'agency_admin']);
        $type = $u && $u->pay_type ? $u->pay_type : ($isVisitor ? 'per_visit' : 'hourly');
        $extras = [];
        if ($u && $u->profile_extras) {
            $extras = json_decode($u->profile_extras, true) ?: [];
        }

        return [
            'user' => $u,
            'rate' => (float) ($u->pay_rate ?? 0),
            'type' => $type,
            'unit_label' => $type === 'per_visit' ? 'visits' : ($type === 'salary' ? 'period' : 'hours'),
            'extras' => $extras,
        ];
    }

    /** Hours worked, visits logged, or one flat period. */
    public static function units(int $userId, string $type, string $start, string $end): float
    {
        if ($type === 'salary') {
            return 1.0;
        }
        if ($type === 'per_visit') {
            return (float) DB::table('home_visit_reports')
                ->where('home_visitor_id', $userId)
                ->whereNull('deleted_at')
                ->whereBetween('visit_date', [$start, $end])
                ->count();
        }
        $startUtc = Carbon::parse($start . ' 00:00:00', self::TZ)->utc();
        $endUtc = Carbon::parse($end . ' 23:59:59', self::TZ)->utc();
        $mins = (int) DB::table('time_punches')
            ->where('user_id', $userId)
            ->whereNotNull('punched_out_at')
            ->whereBetween('punched_in_at', [$startUtc, $endUtc])
            ->sum(DB::raw('TIMESTAMPDIFF(MINUTE, punched_in_at, punched_out_at)'));

        return round($mins / 60, 2);
    }

    /**
     * Write the payroll history KiddieTrac can already prove, from its own punch and
     * visit records. Idempotent on external_key, so re-running corrects rather than
     * duplicates — which it needs to, every time more history arrives.
     *
     * @return array{created:int,updated:int,skipped:int,weeks:int}
     */
    public static function backfillFromWork(?int $agencyId = null, int $weeksBack = 104): array
    {
        $out = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'weeks' => 0];
        $monday = Carbon::now(self::TZ)->startOfWeek(Carbon::MONDAY);

        // Punches carry the centre and the agency comes through it, which is also what
        // keeps this scoped to one agency at a time.
        $centres = DB::table('centres')
            ->when($agencyId, function ($q) use ($agencyId) { return $q->where('agency_id', $agencyId); })
            ->pluck('agency_id', 'id')->all();
        if (! $centres) {
            return $out;
        }
        $centreIds = array_keys($centres);

        $staff = DB::table('time_punches')->whereIn('centre_id', $centreIds)
            ->whereNotNull('punched_out_at')->distinct()->pluck('user_id')->all();
        if (Schema::hasTable('home_visit_reports')) {
            $visitors = DB::table('home_visit_reports as h')
                ->join('role_assignments as ra', 'ra.user_id', '=', 'h.home_visitor_id')
                ->when($agencyId, function ($q) use ($agencyId) { return $q->where('ra.agency_id', $agencyId); })
                ->whereNull('h.deleted_at')->distinct()->pluck('h.home_visitor_id')->all();
            $staff = array_unique(array_merge($staff, $visitors));
        }

        foreach ($staff as $uid) {
            $uid = (int) $uid;
            $p = self::profile($uid);
            if (! $p['user']) {
                $out['skipped']++;
                continue;
            }
            $role = self::primaryRole($uid);
            $name = trim(($p['user']->first_name ?? '') . ' ' . ($p['user']->last_name ?? ''));

            // The centre they punch at most, so the document files under the right one.
            $centreId = DB::table('time_punches')->where('user_id', $uid)->whereIn('centre_id', $centreIds)
                ->select('centre_id', DB::raw('COUNT(*) c'))->groupBy('centre_id')
                ->orderByDesc('c')->value('centre_id');
            $agId = $centreId ? ($centres[$centreId] ?? $agencyId) : $agencyId;
            if (! $agId) {
                $out['skipped']++;
                continue;
            }

            for ($i = 0; $i < $weeksBack; $i++) {
                $s = $monday->copy()->subWeeks($i);
                $start = $s->toDateString();
                $end = $s->copy()->addDays(6)->toDateString();
                $units = self::units($uid, $p['type'], $start, $end);
                if ($units <= 0) {
                    continue;
                }
                $out['weeks']++;
                $key = 'kt:payslip:u' . $uid . ':' . $start;
                $row = [
                    'agency_id' => (int) $agId,
                    'centre_id' => $centreId ? (int) $centreId : null,
                    'user_id' => $uid,
                    'staff_group' => self::groupFor($role),
                    'role_label' => self::LABEL[$role] ?? 'Staff',
                    'payee_name' => $name !== '' ? $name : ($p['user']->email ?? 'Staff'),
                    'kind' => 'payslip',
                    'reference' => 'PS-' . $s->isoFormat('GGGG-[W]WW') . '-' . $uid,
                    'period_start' => $start,
                    'period_end' => $end,
                    'units' => $units,
                    'unit_label' => $p['unit_label'],
                    'rate' => $p['rate'],
                    'gross' => round($units * $p['rate'], 2),
                    'status' => 'issued',
                    'source' => 'kiddietrac',
                    'external_key' => $key,
                    'issued_at' => $s->copy()->addDays(7)->setTime(9, 0)->utc()->toDateTimeString(),
                    'updated_at' => now(),
                ];
                $existing = DB::table('payroll_documents')->where('external_key', $key)->first(['id', 'status']);
                if ($existing) {
                    // A paid document is a historical fact and is left alone.
                    if ($existing->status === 'paid') {
                        continue;
                    }
                    DB::table('payroll_documents')->where('id', $existing->id)->update($row);
                    $out['updated']++;
                } else {
                    $row['created_at'] = now();
                    DB::table('payroll_documents')->insert($row);
                    $out['created']++;
                }
            }
        }

        return $out;
    }

    /** Payroll invoices raised through the bulk run land in the same ledger. */
    public static function syncPayeeInvoices(?int $agencyId = null): array
    {
        $out = ['created' => 0, 'updated' => 0];
        if (! Schema::hasTable('payee_invoices')) {
            return $out;
        }
        $rows = DB::table('payee_invoices')
            ->whereIn('kind', ['educator', 'contractor'])
            ->when($agencyId, function ($q) use ($agencyId) { return $q->where('agency_id', $agencyId); })
            ->get();
        foreach ($rows as $r) {
            if (! $r->payee_user_id) {
                continue;
            }
            $role = self::primaryRole((int) $r->payee_user_id);
            $key = 'kt:invoice:' . $r->id;
            $row = [
                'agency_id' => (int) $r->agency_id,
                'centre_id' => $r->centre_id ? (int) $r->centre_id : null,
                'user_id' => (int) $r->payee_user_id,
                'staff_group' => self::groupFor($role),
                'role_label' => self::LABEL[$role] ?? ucfirst((string) $r->kind),
                'payee_name' => $r->payee_name,
                'kind' => 'invoice',
                'reference' => $r->reference,
                'period_start' => $r->period_start,
                'period_end' => $r->period_end,
                'units' => (float) ($r->hours ?? 0),
                'unit_label' => $r->basis === 'hours' ? 'hours' : 'flat',
                'rate' => (float) ($r->rate ?? 0),
                'gross' => (float) $r->amount,
                'status' => $r->status ?: 'issued',
                'source' => 'kiddietrac',
                'external_id' => (string) $r->id,
                'external_key' => $key,
                'issued_at' => $r->created_at,
                'paid_at' => $r->paid_at,
                'updated_at' => now(),
            ];
            $id = DB::table('payroll_documents')->where('external_key', $key)->value('id');
            if ($id) {
                DB::table('payroll_documents')->where('id', $id)->update($row);
                $out['updated']++;
            } else {
                $row['created_at'] = now();
                DB::table('payroll_documents')->insert($row);
                $out['created']++;
            }
        }

        return $out;
    }
}
