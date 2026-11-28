<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ring the 🔔 for the office when a family joins.
 *
 * Anthony, 2026-08-27: "when a family enrols ... a notification this occurred."
 *
 * Isolation note: `notifications` has no agency column — a row is addressed to a USER.
 * So the guard here is entirely in the recipient query, which is why it reads
 * role_assignments scoped to the family's own agency (resolved through its centre) rather
 * than trusting any agency id handed in by a caller. Get that wrong and one agency's
 * admins are told about another's family, which is the leak the standing rule is about.
 */
final class FamilyJoinedNotice
{
    /**
     * @return int how many people were told
     */
    public static function fire(int $familyId, ?int $actorUserId = null): int
    {
        try {
            $fam = DB::table('families as f')
                ->leftJoin('centres as ce', 'ce.id', '=', 'f.centre_id')
                ->where('f.id', $familyId)
                ->first(['f.id', 'f.family_name', 'f.centre_id', 'ce.name as centre_name', 'ce.agency_id']);

            if (! $fam || ! $fam->agency_id) {
                return 0;
            }
            /* ONCE PER FAMILY (2026-11-03): claimed before anybody is told, so the creating screen and the
               kiddietrac:roster-alerts sweep (families created any other way) never both ring. */
            if (\Illuminate\Support\Facades\Schema::hasColumn('families', 'joined_alerted_at')
                && ! DB::table('families')->where('id', $familyId)->whereNull('joined_alerted_at')->update(['joined_alerted_at' => now()])) {
                return 0;
            }

            $kids = DB::table('children')->where('family_id', $familyId)
                ->whereNull('deleted_at')->count();

            /* The agency's own admins and the directors of THIS family's centre, and nobody else (2026-11-27: a
               director of another centre was told every family's name - CentreScope::managers, the leak guard's
               centre-scope check). */
            /* And the family's own educator(s) / provider (2026-11-03, Anthony: "new family should also be alerted
               the same way by the bell ... to its educator/provider and not to all") - App\Support\RosterAlerts. */
            $famRooms = DB::table('enrollments as e')->join('children as c', 'c.id', '=', 'e.child_id')
                ->where('c.family_id', $familyId)->whereNull('e.end_date')->pluck('e.room_id')->all();
            $userIds = \App\Support\RosterAlerts::recipients((int) $fam->agency_id, (int) $fam->centre_id, $famRooms);

            // The person who just created them is looking at the result already.
            if ($actorUserId) {
                $userIds = array_values(array_filter($userIds, fn ($id) => (int) $id !== (int) $actorUserId));
            }
            if (! $userIds) {
                return 0;
            }

            $actorName = null;
            if ($actorUserId) {
                $a = DB::table('users')->where('id', $actorUserId)->first(['first_name', 'last_name']);
                if ($a) {
                    $actorName = trim(($a->first_name ?? '') . ' ' . ($a->last_name ?? '')) ?: null;
                }
            }

            $name = (string) ($fam->family_name ?: 'A family');
            $body = $name . ' joined'
                . ($fam->centre_name ? ' at ' . $fam->centre_name : '')
                . ' with ' . ($kids === 1 ? '1 child' : $kids . ' children') . '.'
                . ($actorName ? ' Added by ' . $actorName . '.' : '')
                . ' Invite their guardians, place the children in a room, and check your forms.';

            $now = now();
            $rows = [];
            foreach ($userIds as $uid) {
                $rows[] = [
                    'user_id' => (int) $uid,
                    'type' => 'family_joined',
                    'title' => '👪 New family: ' . $name,
                    'body' => $body,
                    'data' => json_encode([
                        'family_id' => (int) $fam->id,
                        'centre_id' => $fam->centre_id ? (int) $fam->centre_id : null,
                        'children' => $kids,
                        // Where the bell should take them (link: the phone push opens it too).
                        'hash' => 'admin-families',
                        'link' => '#admin-families',
                    ]),
                    'created_at' => $now,
                ];
            }
            \App\Support\Notify::write($rows);

            return count($rows);
        } catch (\Throwable $e) {
            // A family that was created must never fail because a bell could not be rung.
            Log::warning('FamilyJoinedNotice failed', ['family' => $familyId, 'e' => $e->getMessage()]);

            return 0;
        }
    }
}
