<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EDUCATOR AWARD HISTORY (2026-11-02, Anthony: "educator awards, where is the subtab to show who got awarded for the
 * month(s) - keep a history").
 *
 * Every award is already its own educator_rewards row (one per award, with its month, the points as they stood and the
 * amount), so the history was never overwritten. What the row did NOT keep was the context a history needs once people
 * move on: the earner's name and role, the centres they worked at, who approved it, and whether it was automatic.
 * Points are computed live (EducatorPoints) and staff change centres or leave, so those are snapshotted on the row when
 * the award is made (snapshot()), and the reader falls back to the person's role assignments (active or not) only for
 * a row that somehow has none.
 *
 *   source        manual | auto      (auto = sent from a ThankYouAuto draft)
 *   centre_ids    JSON [int]         the earner's centres in this agency when it was awarded
 *   earner_name   the earner's full name then (staff screens only; never in an email)
 *   earner_role   educator | home_visitor | centre_director
 *   awarded_by_name  who approved it (null + source auto = sent by the schedule with no review)
 */
final class EducatorAwardHistory
{
    public const SOURCES = ['manual', 'auto'];

    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try { $ok = Schema::hasColumn('educator_rewards', 'earner_name'); } catch (\Throwable $e) { $ok = false; }
        }
        return $ok;
    }

    /** The columns to merge into an educator_rewards insert. Empty until the migration has run. */
    public static function snapshot(int $agencyId, int $userId, ?int $byUserId, string $source = 'manual'): array
    {
        if (! self::ready()) {
            return [];
        }
        $u = DB::table('users')->where('id', $userId)->first(['first_name', 'last_name']);
        $by = $byUserId ? DB::table('users')->where('id', $byUserId)->first(['first_name', 'last_name']) : null;
        [$role, $centres] = self::assignment($agencyId, $userId, true);
        if (! $centres && ! $role) {
            [$role, $centres] = self::assignment($agencyId, $userId, false);
        }
        return [
            'source' => in_array($source, self::SOURCES, true) ? $source : 'manual',
            'centre_ids' => json_encode(array_values($centres)),
            'earner_name' => $u ? mb_substr(trim($u->first_name . ' ' . $u->last_name), 0, 160) : null,
            'earner_role' => $role,
            'awarded_by_name' => $by ? mb_substr(trim($by->first_name . ' ' . $by->last_name), 0, 160) : null,
        ];
    }

    /** [senior earner role, centre ids] from the person's role assignments in the agency. */
    public static function assignment(int $agencyId, int $userId, bool $activeOnly): array
    {
        $rows = DB::table('role_assignments')->where('agency_id', $agencyId)->where('user_id', $userId)
            ->whereIn('role', EducatorPoints::EARNER_ROLES)
            ->when($activeOnly, fn ($q) => $q->where('active', 1))
            ->get(['role', 'centre_id']);
        $order = array_flip(EducatorPoints::EARNER_ROLES);
        $role = null;
        $centres = [];
        foreach ($rows as $r) {
            if ($role === null || ($order[$r->role] ?? 9) > ($order[$role] ?? 9)) {
                $role = $r->role;
            }
            if ($r->centre_id) {
                $centres[(int) $r->centre_id] = (int) $r->centre_id;
            }
        }
        return [$role, array_values($centres)];
    }

    /** Centre ids on a row: the snapshot, else the person's assignments (any state). */
    public static function centresOf(object $a): array
    {
        $ids = json_decode((string) ($a->centre_ids ?? ''), true);
        if (is_array($ids)) {
            return array_values(array_map('intval', $ids));
        }
        return self::assignment((int) $a->agency_id, (int) $a->user_id, false)[1];
    }

    /**
     * The history for one agency, newest month first. $scope: null = whole agency, else the director's centre ids
     * (a row is visible when one of its centres is in scope). Filters: from/to (YYYY-MM), centre_id, user_id,
     * include_cancelled.
     */
    public static function rows(int $agencyId, ?array $scope, array $f, array $methods): array
    {
        $tz = AgencyTime::tz($agencyId);
        $q = DB::table('educator_rewards')->where('agency_id', $agencyId);
        if (! empty($f['from'])) $q->where('month', '>=', $f['from']);
        if (! empty($f['to'])) $q->where('month', '<=', $f['to']);
        if (! empty($f['user_id'])) $q->where('user_id', (int) $f['user_id']);
        if (empty($f['include_cancelled'])) $q->where('status', '!=', 'cancelled');
        $all = $q->orderByDesc('month')->orderByDesc('created_at')->orderByDesc('id')->limit(5000)->get();

        $centreNames = DB::table('centres')->where('agency_id', $agencyId)->get(['id', 'name', 'deleted_at'])
            ->mapWithKeys(fn ($c) => [(int) $c->id => trim((string) $c->name) . ($c->deleted_at ? ' (closed)' : '')])->all();
        $live = array_keys(EducatorPoints::earners($agencyId));
        $live = array_flip($live);
        $autoIds = Schema::hasTable('thank_you_auto_drafts')
            ? array_flip(DB::table('thank_you_auto_drafts')->where('agency_id', $agencyId)->whereNotNull('reward_id')->pluck('reward_id')->map(fn ($v) => (int) $v)->all())
            : [];
        $names = [];
        $nameOf = function (?int $id) use (&$names) {
            if (! $id) return null;
            if (! array_key_exists($id, $names)) {
                $u = DB::table('users')->where('id', $id)->first(['first_name', 'last_name']);
                $names[$id] = $u ? trim($u->first_name . ' ' . $u->last_name) : null;
            }
            return $names[$id];
        };
        $ready = self::ready();

        $out = [];
        $people = [];
        foreach ($all as $a) {
            $centres = self::centresOf($a);
            if ($scope !== null && ! array_intersect($centres, $scope)) continue;
            if (! empty($f['centre_id']) && ! in_array((int) $f['centre_id'], $centres, true)) continue;
            $source = ($ready && ! empty($a->source)) ? $a->source
                : ((isset($autoIds[(int) $a->id]) || str_starts_with((string) $a->reference, 'Automatic')) ? 'auto' : 'manual');
            $name = ($ready ? ($a->earner_name ?? null) : null) ?: ($nameOf((int) $a->user_id) ?: 'Former staff member');
            $by = ($ready ? ($a->awarded_by_name ?? null) : null) ?: $nameOf($a->awarded_by_id ? (int) $a->awarded_by_id : null);
            $role = ($ready ? ($a->earner_role ?? null) : null) ?: self::assignment($agencyId, (int) $a->user_id, false)[0];
            $created = $a->created_at ? Carbon::parse($a->created_at, 'UTC')->setTimezone($tz) : null;
            $people[(int) $a->user_id] = $name;
            $out[] = [
                'id' => (int) $a->id,
                'month' => $a->month,
                'month_label' => Carbon::parse($a->month . '-01')->format('F Y'),
                'user_id' => (int) $a->user_id,
                'name' => $name,
                'role' => $role,
                'left' => ! isset($live[(int) $a->user_id]),
                'centres' => array_values(array_filter(array_map(fn ($c) => $centreNames[$c] ?? null, $centres))),
                'method' => $a->method,
                'method_label' => $methods[$a->method] ?? $a->method,
                'kind' => self::kind($a->method),
                'brand' => $a->brand,
                'amount' => (float) $a->amount,
                'currency' => $a->currency,
                'points' => (int) $a->points,
                'status' => $a->status,
                'source' => $source,
                'approved_by' => $by ?: null,
                'note' => $a->note,
                'reference' => $a->reference,
                'date' => $created ? $created->format('M j, Y') : null,
                'date_full' => $created ? $created->format('Y-m-d g:i A') : null,
            ];
        }
        asort($people);
        return ['rows' => $out, 'people' => array_map(fn ($id, $n) => ['user_id' => $id, 'name' => $n], array_keys($people), array_values($people)), 'tz' => $tz];
    }

    /** payroll | gift card | payout | thank-you, for the Method column. */
    public static function kind(string $method): string
    {
        return [
            'payroll_bonus' => 'Payroll',
            'payout' => 'Payout',
            'gift_card_manual' => 'Gift card',
            'gift_card_provider' => 'Gift card',
            'thank_you' => 'Thank-you certificate',
        ][$method] ?? 'Other';
    }

    /**
     * Fill the snapshot on rows that have none (the migration's backfill). Uses only what is on record: the user's
     * name, their role assignments in that agency (active first, else any), the awarder's name, and the
     * thank_you_auto_drafts link for "automatic". Returns counts.
     */
    public static function backfill(): array
    {
        $done = 0; $auto = 0; $noCentre = 0;
        $autoIds = Schema::hasTable('thank_you_auto_drafts')
            ? array_flip(DB::table('thank_you_auto_drafts')->whereNotNull('reward_id')->pluck('reward_id')->map(fn ($v) => (int) $v)->all())
            : [];
        foreach (DB::table('educator_rewards')->whereNull('earner_name')->orderBy('id')->get() as $a) {
            $isAuto = isset($autoIds[(int) $a->id]) || str_starts_with((string) $a->reference, 'Automatic');
            $snap = self::snapshot((int) $a->agency_id, (int) $a->user_id, $a->awarded_by_id ? (int) $a->awarded_by_id : null, $isAuto ? 'auto' : 'manual');
            if (! $snap) continue;
            DB::table('educator_rewards')->where('id', $a->id)->update($snap);
            $done++;
            if ($isAuto) $auto++;
            if ($snap['centre_ids'] === '[]') $noCentre++;
        }
        return ['filled' => $done, 'automatic' => $auto, 'no_centre' => $noCentre];
    }
}
