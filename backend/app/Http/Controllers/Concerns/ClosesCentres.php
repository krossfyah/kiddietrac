<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * WHAT CLOSING A CENTRE MUST ALSO CLOSE (2026-10-09).
 *
 * Archiving a provider set centres.status/deleted_at and nothing else. Staff postings
 * stayed active (Safia Ali was still "an educator at" both closed providers), open
 * enrolment rows stayed open, and a family whose only child had been withdrawn stayed
 * live on the deleted centre (family 87 — that parent got the closed provider's
 * Thanksgiving notice). Every path that closes a centre calls this, in the same
 * transaction as the centre update, so the closure is whole or not at all.
 *
 * Enrolled children are NOT handled here: every caller refuses to close while any
 * remain (they must be transferred or withdrawn, which has its own notices).
 * Families left open are REPORTED, not departed — departing a family closes logins
 * and is a decision for a person.
 *
 * Future shifts (2026-11-12, kt:integrity-check ticket #249): closing Bruni Meeser's home left
 * 40 shifts booked there for her and Rachel Blane through December, on accounts the closure had
 * itself closed. Shifts still 'scheduled' from the day after the last day (never before today)
 * are removed here; a shift that was worked, started or cancelled is a record and stays.
 */
trait ClosesCentres
{
    /** @return array{roles_closed:int, enrolments_ended:int, shifts_removed:int, families_still_open:array<int,array{id:int,family_name:?string}>} */
    protected function closeCentreLinks(int $centreId, ?string $endDate = null): array
    {
        $endDate = $endDate ?: \App\Support\AgencyTime::today();

        $rolesClosed = DB::table('role_assignments')
            ->where('centre_id', $centreId)->where('active', 1)
            ->update(['active' => 0]);

        $roomIds = DB::table('rooms')->where('centre_id', $centreId)->pluck('id')->all();
        $enrolmentsEnded = $roomIds
            ? DB::table('enrollments')->whereIn('room_id', $roomIds)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>', $endDate))
                ->update(['end_date' => $endDate])
            : 0;

        $shiftsFrom = max(\Illuminate\Support\Carbon::parse($endDate)->addDay()->toDateString(),
            \App\Support\AgencyTime::today());
        $shiftsRemoved = $roomIds
            ? DB::table('shifts')->whereIn('room_id', $roomIds)->where('status', 'scheduled')
                ->where('starts_at', '>=', $shiftsFrom . ' 00:00:00')->delete()
            : 0;

        $familiesOpen = DB::table('families')->where('centre_id', $centreId)
            ->whereNull('deleted_at')->whereNull('departure_applied_at')
            /* A family waiting on the AGENCY waitlist is accounted for, not stranded (#251). */
            ->whereNotExists(fn ($q) => $q->from('external_waitlist as w')->whereColumn('w.family_id', 'families.id')
                ->whereNull('w.deleted_at')->whereIn('w.status', \App\Support\AgencyWaitlist::OPEN_STATUSES))
            ->orderBy('family_name')->get(['id', 'family_name'])
            ->map(fn ($f) => ['id' => (int) $f->id, 'family_name' => $f->family_name])->all();

        return [
            'roles_closed' => $rolesClosed,
            'enrolments_ended' => $enrolmentsEnded,
            'shifts_removed' => $shiftsRemoved,
            'families_still_open' => $familiesOpen,
        ];
    }

    /** The sentence an admin reads about families left on a closed centre, or null. */
    protected function familiesStillOpenNote(array $links): ?string
    {
        $n = count($links['families_still_open'] ?? []);
        if (! $n) {
            return null;
        }
        $names = implode(', ', array_map(fn ($f) => $f['family_name'] ?: ('Family #' . $f['id']), $links['families_still_open']));

        return $n . ' ' . ($n === 1 ? 'family is' : 'families are') . ' still open on this centre with no child enrolled ('
            . $names . '). Record their departure (or move them to another centre) so they stop receiving this centre\'s emails.';
    }
}
