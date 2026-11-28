<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * TUITION INCREASES THAT DO THEIR JOB (2026-11-28, Anthony chose "properly build" the screen).
 *
 * An increase is Scheduled -> Notified -> Applied:
 *   - Notified: on (effective date - notice days), in the agency's timezone, every affected family's guardians get
 *     a bell and a house-frame email naming their own children's old and new tuition (BillingNoticeMail).
 *   - Applied: on the effective date the new amount is written to each affected open enrolment's monthly_fee (what
 *     invoices bill) and to the centre's tuition plans (fee_plans).
 * Both steps are recorded per family/child/plan in tuition_increase_items (phase notice / applied), which is also
 * what makes them safe to run again: a family already told is not told twice, a fee already raised is not raised
 * twice (the increase row is locked and applied_at set in the same transaction).
 *
 * WHAT IS AFFECTED: open enrolments (no end date, or ending on/after the effective date) with a fee above zero, of
 * enrolled children, in live families, at open centres - the increase's centre, or every centre of the agency.
 * A PERCENT increase raises every fee and every active tuition plan in scope. A FLAT increase adds the amount to each
 * child's MONTHLY tuition and to MONTHLY plans only: a weekly or one-time plan is left alone and the preview says so.
 */
final class TuitionIncreases
{
    public static function newAmount(float $old, string $type, float $amount): float
    {
        return $type === 'flat' ? round($old + $amount, 2) : round($old * (1 + $amount / 100), 2);
    }

    /**
     * NEVER QUIETLY LATE. An increase whose date passed more than this many days ago without being applied (it was
     * scheduled before this ran, or its centre was closed) is not applied by the scheduler: it shows as Overdue and
     * an admin chooses Apply now or Delete.
     */
    public const LATE_DAYS = 14;

    public static function status(object $t, ?string $today = null): string
    {
        if ($t->applied_at) return 'applied';
        if ($today && Carbon::parse(substr((string) $t->effective_date, 0, 10))->addDays(self::LATE_DAYS)->toDateString() < $today) return 'overdue';

        return $t->notification_sent_at ? 'notified' : 'scheduled';
    }

    public static function type(?string $t): string
    {
        return $t === 'flat' ? 'flat' : 'percent';   // seeded rows say 'percentage'
    }

    /** The date families are told: effective date minus the notice days (a calendar day). */
    public static function noticeDate(string $effective, int $days): string
    {
        return Carbon::parse(substr($effective, 0, 10))->subDays(max(0, $days))->toDateString();
    }

    /**
     * Who and what an increase touches, worked out from today's fees.
     *
     * @param  int[]|null $scope  CentreScope ids of the caller (null = agency-wide); null from the scheduler
     * @return array{families: array, plans: array, skipped_plans: array, children: int, old_total: float, new_total: float}
     */
    public static function affected(int $agencyId, ?int $centreId, string $type, float $amount, string $effective, ?array $scope = null): array
    {
        $q = DB::table('enrollments as e')
            ->join('children as ch', 'ch.id', '=', 'e.child_id')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
            ->where('e.monthly_fee', '>', 0)
            ->where(fn ($w) => $w->whereNull('e.end_date')->orWhere('e.end_date', '>=', substr($effective, 0, 10)));
        Live::enrolledChildren($q, 'ch');
        Live::families($q, 'f');
        Live::centres($q, 'c');
        if ($centreId) $q->where('f.centre_id', $centreId);
        if ($scope !== null) $q->whereIn('f.centre_id', $scope ?: [0]);
        $rows = $q->orderBy('f.family_name')->orderBy('ch.first_name')
            ->get(['e.id as enrollment_id', 'e.monthly_fee', 'ch.id as child_id', 'ch.first_name', 'ch.preferred_name',
                'f.id as family_id', 'f.family_name', 'c.id as centre_id', 'c.name as centre_name']);

        $families = [];
        $oldTotal = 0.0;
        $newTotal = 0.0;
        foreach ($rows as $r) {
            $old = round((float) $r->monthly_fee, 2);
            $new = self::newAmount($old, $type, $amount);
            $fid = (int) $r->family_id;
            $families[$fid] ??= ['family_id' => $fid, 'family_name' => (string) $r->family_name, 'centre_id' => (int) $r->centre_id,
                'centre_name' => (string) $r->centre_name, 'children' => [], 'old_total' => 0.0, 'new_total' => 0.0];
            $families[$fid]['children'][] = ['enrollment_id' => (int) $r->enrollment_id, 'child_id' => (int) $r->child_id,
                'name' => trim((string) ($r->preferred_name ?: $r->first_name)) ?: 'Child', 'old' => $old, 'new' => $new];
            $families[$fid]['old_total'] = round($families[$fid]['old_total'] + $old, 2);
            $families[$fid]['new_total'] = round($families[$fid]['new_total'] + $new, 2);
            $oldTotal += $old;
            $newTotal += $new;
        }

        $plans = [];
        $skipped = [];
        $pq = DB::table('fee_plans')->where('agency_id', $agencyId)->whereNull('deleted_at')->where('active', 1);
        if ($centreId) {
            $pq->where('centre_id', $centreId);
        } elseif ($scope !== null) {
            $pq->whereIn('centre_id', $scope ?: [0]);
        }
        foreach ($pq->orderBy('name')->get(['id', 'name', 'amount', 'frequency', 'centre_id']) as $p) {
            if ($type === 'flat' && $p->frequency !== 'monthly') {
                $skipped[] = ['fee_plan_id' => (int) $p->id, 'name' => (string) $p->name, 'frequency' => (string) $p->frequency];
                continue;
            }
            $old = round((float) $p->amount, 2);
            $plans[] = ['fee_plan_id' => (int) $p->id, 'name' => (string) $p->name, 'frequency' => (string) $p->frequency,
                'old' => $old, 'new' => self::newAmount($old, $type, $amount)];
        }

        return ['families' => array_values($families), 'plans' => $plans, 'skipped_plans' => $skipped,
            'children' => $rows->count(), 'old_total' => round($oldTotal, 2), 'new_total' => round($newTotal, 2)];
    }

    /** One scheduler pass for one agency: tell families whose notice day has come, apply increases whose day has come. */
    public static function runAgency(int $agencyId, bool $dry = false): array
    {
        $today = AgencyTime::today($agencyId);
        $out = ['notified' => 0, 'applied' => 0, 'increases' => []];
        $rows = DB::table('tuition_increases')->where('agency_id', $agencyId)->whereNull('applied_at')
            ->orderBy('effective_date')->get();
        foreach ($rows as $t) {
            if ($t->centre_id && ! Live::centreOpen((int) $t->centre_id)) continue;
            if (self::status($t, $today) === 'overdue') continue;
            $eff = substr((string) $t->effective_date, 0, 10);
            $noticeOn = self::noticeDate($eff, (int) $t->notify_families_days_before);
            $doNotice = ! $t->notification_sent_at && $today >= $noticeOn;
            $doApply = $today >= $eff;
            if (! $doNotice && ! $doApply) continue;
            $out['increases'][] = ['id' => (int) $t->id, 'notice' => $doNotice || ($doApply && ! $t->notification_sent_at), 'apply' => $doApply];
            if ($dry) continue;
            // Never applied without the families having been told first, even when the notice day was missed.
            if (! $t->notification_sent_at) $out['notified'] += self::notify((int) $t->id);
            if ($doApply) $out['applied'] += self::apply((int) $t->id);
        }

        return $out;
    }

    /** Tell every affected family (once each). Returns how many families were told this time. */
    public static function notify(int $increaseId): int
    {
        $t = DB::table('tuition_increases')->where('id', $increaseId)->first();
        if (! $t || $t->applied_at) return 0;
        $agencyId = (int) $t->agency_id;
        $eff = substr((string) $t->effective_date, 0, 10);
        $aff = self::affected($agencyId, $t->centre_id ? (int) $t->centre_id : null, self::type($t->increase_type), (float) $t->amount, $eff);
        $done = DB::table('tuition_increase_items')->where('tuition_increase_id', $increaseId)->where('phase', 'notice')
            ->pluck('family_id')->map(fn ($v) => (int) $v)->flip()->all();
        $told = 0;
        $team = FamilyFacingName::agency($agencyId) ?: 'KiddieTrac';
        foreach ($aff['families'] as $fam) {
            if (isset($done[$fam['family_id']])) continue;
            // Recorded first: a crash part-way never tells the same family twice.
            $now = now();
            DB::table('tuition_increase_items')->insert(array_map(fn ($c) => [
                'tuition_increase_id' => $increaseId, 'phase' => 'notice', 'family_id' => $fam['family_id'], 'child_id' => $c['child_id'],
                'enrollment_id' => $c['enrollment_id'], 'fee_plan_id' => null, 'old_amount' => $c['old'], 'new_amount' => $c['new'], 'created_at' => $now,
            ], $fam['children']));
            $lines = array_map(fn ($c) => ['name' => $c['name'], 'old' => $c['old'], 'new' => $c['new']], $fam['children']);
            $bellBody = implode('; ', array_map(fn ($c) => $c['name'] . ': ' . BillingRules::money($c['old']) . ' → ' . BillingRules::money($c['new']) . ' a month', $lines))
                . '. From ' . BillingRules::day($eff) . '.';
            foreach (BillingRules::guardians($fam['family_id']) as $uid => $g) {
                Notify::write([
                    'user_id' => $uid, 'agency_id' => $agencyId, 'type' => 'tuition_increase',
                    'title' => 'Tuition change from ' . BillingRules::day($eff),
                    'body' => $bellBody,
                    'data' => json_encode(['link' => '#billing', 'tuition_increase_id' => $increaseId]),
                    'created_at' => now(),
                ]);
                if ($g['email']) {
                    $m = BillingNoticeMail::tuitionIncrease($agencyId, $g['first'], $lines, $eff, (string) ($t->notes ?? ''));
                    BillingRules::mailFamily($agencyId, $g['email'], $m['subject'], $m['html']);
                }
            }
            $told++;
        }
        $families = (int) DB::table('tuition_increase_items')->where('tuition_increase_id', $increaseId)->where('phase', 'notice')->distinct()->count('family_id');
        DB::table('tuition_increases')->where('id', $increaseId)->update([
            'notification_sent_at' => $t->notification_sent_at ?: now(), 'notified_families' => $families, 'updated_at' => now(),
        ]);
        Audit::write(['user_id' => null, 'agency_id' => $agencyId, 'action' => 'tuition_increase.notified',
            'entity_type' => 'tuition_increase', 'entity_id' => $increaseId,
            'payload' => json_encode(['families' => $told, 'effective_date' => $eff, 'team' => $team]), 'created_at' => now()]);

        return $told;
    }

    /** Write the new rates. Returns how many fees (enrolments + plans) changed. Runs once per increase. */
    public static function apply(int $increaseId): int
    {
        $changed = 0;
        $agencyId = 0;
        $centreId = null;
        $eff = null;
        DB::transaction(function () use ($increaseId, &$changed, &$agencyId, &$centreId, &$eff) {
            $t = DB::table('tuition_increases')->where('id', $increaseId)->lockForUpdate()->first();
            if (! $t || $t->applied_at) return;
            $agencyId = (int) $t->agency_id;
            $centreId = $t->centre_id ? (int) $t->centre_id : null;
            $eff = substr((string) $t->effective_date, 0, 10);
            $aff = self::affected($agencyId, $centreId, self::type($t->increase_type), (float) $t->amount, $eff);
            $now = now();
            $items = [];
            foreach ($aff['families'] as $fam) {
                foreach ($fam['children'] as $c) {
                    DB::table('enrollments')->where('id', $c['enrollment_id'])->update(['monthly_fee' => $c['new']]);
                    $items[] = ['tuition_increase_id' => $increaseId, 'phase' => 'applied', 'family_id' => $fam['family_id'], 'child_id' => $c['child_id'],
                        'enrollment_id' => $c['enrollment_id'], 'fee_plan_id' => null, 'old_amount' => $c['old'], 'new_amount' => $c['new'], 'created_at' => $now];
                    $changed++;
                }
            }
            foreach ($aff['plans'] as $p) {
                DB::table('fee_plans')->where('id', $p['fee_plan_id'])->update(['amount' => $p['new'], 'updated_at' => $now]);
                $items[] = ['tuition_increase_id' => $increaseId, 'phase' => 'applied', 'family_id' => null, 'child_id' => null,
                    'enrollment_id' => null, 'fee_plan_id' => $p['fee_plan_id'], 'old_amount' => $p['old'], 'new_amount' => $p['new'], 'created_at' => $now];
                $changed++;
            }
            foreach (array_chunk($items, 200) as $chunk) DB::table('tuition_increase_items')->insert($chunk);
            DB::table('tuition_increases')->where('id', $increaseId)->update(['applied_at' => $now, 'applied_count' => $changed, 'updated_at' => $now]);
            Audit::write(['user_id' => null, 'agency_id' => $agencyId, 'action' => 'tuition_increase.applied',
                'entity_type' => 'tuition_increase', 'entity_id' => $increaseId,
                'payload' => json_encode(['fees_changed' => $changed, 'effective_date' => $eff, 'plans' => count($aff['plans'])]), 'created_at' => $now]);
        });
        if ($agencyId && $eff) {
            // The office hears it happened (admins + the centre's directors; never an outsider).
            $ids = CentreScope::managers($agencyId, $centreId ? [$centreId] : [])->pluck('u.id')->unique()->all();
            foreach ($ids as $uid) {
                Notify::write(['user_id' => (int) $uid, 'agency_id' => $agencyId, 'type' => 'tuition_increase_applied',
                    'title' => 'Tuition increase applied',
                    'body' => $changed . ' ' . ($changed === 1 ? 'fee was' : 'fees were') . ' updated from ' . BillingRules::day($eff) . '.',
                    'data' => json_encode(['link' => '#tuition-increases', 'tuition_increase_id' => $increaseId]), 'created_at' => now()]);
            }
        }

        return $changed;
    }
}
