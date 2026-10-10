<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * STAFF COMPLIANCE, from the jurisdiction profile (2026-10-10).
 *
 * What a staff member owes, worked out from App\Support\Jurisdictions:
 *  - certifications with `within_days_of_hire` (Ohio: first aid/CPR 90, child abuse 60,
 *    orientation 30, staff health records 30) → done / due by / overdue / expired, counted
 *    from users.hire_date (unknown hire date → "set a hire date", never a guess);
 *  - background checks listed in `checks_required` (Ohio: BCI, FBI, both sex offender
 *    registries, SACWIS — before day one, then every `renew_years`) → current / expired /
 *    missing;
 *  - professional development hours (`pd_hours`: hours per training year and the month the
 *    year starts) → approved hours this year against the requirement;
 *  - the staff registry number when the profile names a registry (Ohio OPIN).
 * A jurisdiction with none of these (Ontario today) is `enabled: false`: nothing changes.
 */
final class StaffCompliance
{
    public const STAFF_ROLES = ['educator', 'home_visitor', 'centre_director'];

    public static function rules(int $agencyId): array
    {
        $p = Jurisdictions::forAgency($agencyId);
        $certs = array_values(array_filter((array) ($p['certifications'] ?? []), fn ($c) => ! empty($c['within_days_of_hire'])));
        $checkCodes = (array) ($p['checks_required'] ?? []);
        $checks = array_values(array_filter((array) ($p['background_checks'] ?? []), fn ($c) => in_array($c['code'], $checkCodes, true)));
        $pd = is_array($p['pd_hours'] ?? null) ? $p['pd_hours'] : null;
        $registry = $p['terms']['registry'] ?? null;

        return ['code' => $p['code'], 'label' => $p['label'], 'certs' => $certs, 'checks' => $checks, 'pd' => $pd, 'registry' => $registry,
            'enabled' => (bool) ($certs || $checks || $pd || $registry)];
    }

    /** The training year containing $today: [start, end] as dates. */
    public static function trainingYear(?array $pd, string $today): array
    {
        $startMonth = max(1, min(12, (int) ($pd['year_starts_month'] ?? 1)));
        $d = Carbon::parse($today);
        $start = Carbon::create($d->year, $startMonth, 1);
        if ($d->lt($start)) $start->subYear();

        return [$start->toDateString(), $start->copy()->addYear()->subDay()->toDateString()];
    }

    public static function forUser(int $agencyId, int $userId, ?array $rules = null): array
    {
        $rules = $rules ?? self::rules($agencyId);
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $today = Carbon::now($tz)->toDateString();
        $u = DB::table('users')->where('id', $userId)->first(['id', 'first_name', 'last_name', 'hire_date', 'registry_number']);
        $hire = $u->hire_date ?? null;
        $items = [];

        foreach ($rules['certs'] as $c) {
            $rec = DB::table('staff_certifications')->where('user_id', $userId)->where('cert_type', $c['code'])->where('active', 1)
                ->orderByDesc('issued_at')->orderByDesc('id')->first(['issued_at', 'expires_at']);
            $deadline = $hire ? Carbon::parse($hire)->addDays((int) $c['within_days_of_hire'])->toDateString() : null;
            if ($rec) {
                $status = ($rec->expires_at && $rec->expires_at < $today) ? 'expired' : 'done';
                $items[] = ['kind' => 'certification', 'code' => $c['code'], 'label' => $c['label'], 'status' => $status,
                    'date' => $rec->issued_at, 'expires' => $rec->expires_at, 'deadline' => $deadline];
            } else {
                $items[] = ['kind' => 'certification', 'code' => $c['code'], 'label' => $c['label'],
                    'status' => ! $hire ? 'needs_hire_date' : ($deadline < $today ? 'overdue' : 'due'), 'deadline' => $deadline,
                    'rule' => 'Within ' . (int) $c['within_days_of_hire'] . ' days of hire'];
            }
        }
        foreach ($rules['checks'] as $c) {
            $rec = DB::table('background_checks')->where('user_id', $userId)->where('agency_id', $agencyId)->where('check_type', $c['code'])
                ->orderByDesc('expires_at')->first(['issued_at', 'expires_at']);
            $status = $rec ? (($rec->expires_at && $rec->expires_at < $today) ? 'expired' : 'done') : 'missing';
            $items[] = ['kind' => 'check', 'code' => $c['code'], 'label' => $c['label'], 'status' => $status,
                'date' => $rec->issued_at ?? null, 'expires' => $rec->expires_at ?? null,
                'rule' => 'Before day one' . (! empty($c['renew_years']) ? ', then every ' . (int) $c['renew_years'] . ' years' : '')];
        }
        if ($rules['registry']) {
            $items[] = ['kind' => 'registry', 'code' => 'registry', 'label' => $rules['registry'], 'status' => $u->registry_number ? 'done' : 'missing',
                'value' => $u->registry_number];
        }

        $pd = null;
        if ($rules['pd']) {
            [$from, $to] = self::trainingYear($rules['pd'], $today);
            $q = DB::table('pd_hours')->where('user_id', $userId)->where('agency_id', $agencyId)->whereNull('deleted_at')->whereBetween('taken_on', [$from, $to]);
            $approved = (float) (clone $q)->where('status', 'approved')->sum('hours');
            $pending = (float) (clone $q)->where('status', 'pending')->sum('hours');
            $need = (float) ($rules['pd']['hours'] ?? 0);
            $pd = ['required' => $need, 'approved' => round($approved, 2), 'pending' => round($pending, 2), 'from' => $from, 'to' => $to,
                'status' => $approved >= $need ? 'done' : ($to < $today ? 'overdue' : 'due')];
        }
        $counts = ['overdue' => 0, 'due' => 0, 'expired' => 0, 'missing' => 0, 'done' => 0, 'needs_hire_date' => 0];
        foreach ($items as $i) $counts[$i['status']] = ($counts[$i['status']] ?? 0) + 1;

        return ['user_id' => (int) $userId, 'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')), 'hire_date' => $hire,
            'registry_number' => $u->registry_number, 'items' => $items, 'pd' => $pd, 'counts' => $counts, 'today' => $today];
    }

    /** Active staff of the agency (optionally only those at $centreIds). */
    public static function staffIds(int $agencyId, ?array $centreIds = null): array
    {
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $q = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', true)->whereNull('u.deleted_at')->whereIn('ra.role', self::STAFF_ROLES)
            ->where(function ($w) use ($agencyId, $centres) { $w->where('ra.agency_id', $agencyId)->orWhereIn('ra.centre_id', $centres ?: [0]); });
        if ($centreIds !== null) $q->whereIn('ra.centre_id', $centreIds ?: [0]);

        return $q->get(['u.id', 'u.email'])->reject(fn ($r) => ChatAudience::isServiceEmail($r->email))->pluck('id')->unique()->map(fn ($i) => (int) $i)->values()->all();
    }
}
