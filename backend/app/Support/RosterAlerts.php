<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * NEW FAMILIES AND WITHDRAWN CHILDREN RING THE BELL FOR THE WHOLE TEAM (2026-11-03, Anthony: "de-enrollement should be
 * alerted in the bell for all admin staff and educator" and "new family should also be alerted the same way").
 *
 * Who: the agency's admins and the directors of the family's centre (CentreScope::managers, 2026-11-27), and the family's OWN educator(s) / provider - not every educator ("should be
 * to its educator/provider and not to all", same day). Its educators = those assigned to the child's room(s)
 * (educator_rooms); with no room or nobody assigned, the centre's educators - in home child care the centre is the
 * provider's home, so that is the provider. Never another agency (role_assignments are read for the family's own
 * agency, resolved through its centre - the data-leakage rule).
 *
 * Once each: children.withdraw_alerted_at and families.joined_alerted_at are claimed atomically before anybody is
 * told. The places that know at once call childWithdrawn() / FamilyJoinedNotice::fire(); kiddietrac:roster-alerts
 * (every 5 minutes) sweeps up everything else - a child can be withdrawn from at least six places (withdrawal
 * requests, the child screen, archive, family departures, the waitlist, the iLearn sync) and a family created from as
 * many, most by direct table writes no model event sees. A child enrolled again is re-armed by the sweep.
 */
final class RosterAlerts
{
    /** [agency_id, centre_id, centre name] for a family, or null. */
    public static function familyPlace(int $familyId): ?array
    {
        $f = DB::table('families as f')->leftJoin('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->where('f.id', $familyId)->first(['f.centre_id', 'ce.agency_id', 'ce.name']);
        if (! $f || ! $f->agency_id) return null;
        return [(int) $f->agency_id, (int) $f->centre_id, (string) ($f->name ?? '')];
    }

    /**
     * Agency admins + the directors OF THIS CENTRE, and the educators of these rooms (else the centre's educators).
     * Active people only. CENTRE SCOPE (2026-11-27, leak guard): "agency_id = A AND role IN (agency_admin,
     * centre_director)" reached the directors of EVERY centre, because a director's role row carries agency_id too, so
     * a director was told the name of a family joining (or a child leaving) another centre. The managers now come
     * from CentreScope::managers, the one rule for notices about something at a centre (outsiders and HR leavers out).
     */
    public static function recipients(int $agencyId, int $centreId, array $roomIds = [], ?int $exceptUserId = null): array
    {
        $roomIds = array_values(array_filter(array_map('intval', $roomIds)));
        $own = $roomIds ? DB::table('educator_rooms as er')->join('rooms as r', 'r.id', '=', 'er.room_id')
            ->whereIn('er.room_id', $roomIds)->where('r.centre_id', $centreId)->pluck('er.user_id')->map(fn ($v) => (int) $v)->all() : [];
        $managers = CentreScope::managers($agencyId, $centreId ?: null)->where('u.status', 'active')
            ->distinct()->pluck('u.id')->map(fn ($v) => (int) $v)->all();
        $educators = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', true)->whereNull('u.deleted_at')->where('u.status', 'active')
            ->where('ra.centre_id', $centreId ?: -1)->where('ra.role', 'educator')
            ->when($own, fn ($w) => $w->whereIn('ra.user_id', $own))   // the room's own educators, still checked against this centre
            ->distinct()->pluck('u.id')->map(fn ($v) => (int) $v)->all();
        $ids = array_values(array_unique(array_merge($managers, $educators)));
        if ($exceptUserId) $ids = array_values(array_filter($ids, fn ($id) => $id !== (int) $exceptUserId));
        return $ids;
    }

    /** Bell everyone that a child has been withdrawn, once. Returns how many were told. */
    public static function childWithdrawn(int $childId, ?int $actorUserId = null): int
    {
        try {
            if (! Schema::hasColumn('children', 'withdraw_alerted_at')) return 0;
            // Claim first: whoever wins rings, nobody rings twice.
            $won = DB::table('children')->where('id', $childId)->where('enrollment_status', 'withdrawn')
                ->whereNull('withdraw_alerted_at')->update(['withdraw_alerted_at' => now()]);
            if (! $won) return 0;
            $c = DB::table('children')->where('id', $childId)->first(['id', 'first_name', 'preferred_name', 'last_name', 'family_id', 'withdrawn_at']);
            $place = $c ? self::familyPlace((int) $c->family_id) : null;
            if (! $place) return 0;
            [$agencyId, $centreId, $centreName] = $place;
            // The room(s) the child was in: open enrolments, else the most recently ended one.
            $rooms = DB::table('enrollments')->where('child_id', $c->id)->whereNull('end_date')->pluck('room_id')->all()
                ?: array_filter([DB::table('enrollments')->where('child_id', $c->id)->orderByDesc('end_date')->value('room_id')]);
            $users = self::recipients($agencyId, $centreId, $rooms, $actorUserId);
            if (! $users) return 0;
            $name = trim(($c->preferred_name ?: $c->first_name) . ' ' . mb_substr((string) $c->last_name, 0, 1) . ((string) $c->last_name !== '' ? '.' : ''));
            $when = $c->withdrawn_at ? \Illuminate\Support\Carbon::parse($c->withdrawn_at)->format('M j, Y') : null;
            $body = $name . ' has been withdrawn' . ($centreName !== '' ? ' from ' . $centreName : '')
                . ($when ? ', effective ' . $when : '') . '. They are off the roster and out of daily records.';
            $rows = [];
            foreach ($users as $u) {
                $rows[] = ['user_id' => $u, 'type' => 'withdrawal', 'title' => "\u{1F44B} Child withdrawn: " . $name, 'body' => $body,
                    'data' => json_encode(['child_id' => (int) $c->id, 'centre_id' => $centreId, 'link' => '#today']), 'created_at' => now()];
            }
            Notify::write($rows);
            return count($rows);
        } catch (\Throwable $e) {
            Log::warning('RosterAlerts::childWithdrawn failed', ['child' => $childId, 'e' => $e->getMessage()]);
            return 0;
        }
    }

    /** The sweep: withdrawals and new families nobody rang for yet; re-arm children enrolled again. */
    public static function sweep(): array
    {
        $out = ['withdrawn' => 0, 'families' => 0, 'rearmed' => 0];
        if (Schema::hasColumn('children', 'withdraw_alerted_at')) {
            $out['rearmed'] = DB::table('children')->where('enrollment_status', '!=', 'withdrawn')->whereNotNull('withdraw_alerted_at')
                ->update(['withdraw_alerted_at' => null]);
            foreach (DB::table('children')->where('enrollment_status', 'withdrawn')->whereNull('withdraw_alerted_at')->limit(200)->pluck('id') as $id) {
                if (self::childWithdrawn((int) $id) > 0) $out['withdrawn']++;
            }
        }
        if (Schema::hasColumn('families', 'joined_alerted_at')) {
            // Two minutes old, so the children are on it before the bell counts them.
            foreach (DB::table('families')->whereNull('joined_alerted_at')->whereNull('deleted_at')->where('created_at', '<=', now()->subMinutes(2))->limit(200)->pluck('id') as $id) {
                if (\App\Services\FamilyJoinedNotice::fire((int) $id, null) > 0) $out['families']++;
            }
        }
        return $out;
    }
}
