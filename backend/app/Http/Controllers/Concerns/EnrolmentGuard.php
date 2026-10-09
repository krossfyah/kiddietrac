<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * NO ENROLMENT FOR SOMEBODY WHO HAS LEFT, OR IN A PLACE THAT HAS CLOSED (2026-10-09).
 *
 * An enrolment row written on 2026-09-10 for Sullivan Black — withdrawn on 31 August —
 * put her back on the immunization reminder, the director lists and the room's
 * emergency cards a month later. Readers now check the child too (App\Support\Live),
 * but the row should never have been written. Every path that creates or reopens an
 * enrolment asks this first.
 */
final class EnrolmentGuard
{
    /** Why this child cannot be placed in this room, or null if they can. */
    public static function refusal(int $childId, ?int $roomId): ?string
    {
        $child = DB::table('children')->where('id', $childId)->first(['id', 'first_name', 'last_name', 'enrollment_status', 'deleted_at']);
        if (! $child) {
            return 'Child not found.';
        }
        $name = trim(($child->first_name ?? '') . ' ' . ($child->last_name ?? ''));
        if ($child->deleted_at !== null) {
            return $name . ' has been archived, so cannot be placed in a room.';
        }
        if (in_array($child->enrollment_status, ['withdrawn', 'graduated'], true)) {
            return $name . ' is ' . $child->enrollment_status . '. Re-enrol them first, then place them in a room.';
        }
        if ($roomId) {
            $centreId = DB::table('rooms')->where('id', $roomId)->value('centre_id');
            if (! $centreId || ! \App\Support\Live::centreOpen((int) $centreId)) {
                return 'That room belongs to a centre that is closed.';
            }
        }

        return null;
    }

    /** Abort the request (422) when the placement is refused — rolls back an open transaction. */
    public static function assert(int $childId, ?int $roomId): void
    {
        if ($why = self::refusal($childId, $roomId)) {
            abort(response()->json(['message' => $why], 422));
        }
    }
}
