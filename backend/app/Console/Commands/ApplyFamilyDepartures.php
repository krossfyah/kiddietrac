<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finish the de-enrolments that were booked for a date now past.
 *
 * When an admin gives a family a future last day, nothing happens at the time beyond
 * recording it and telling the family — they keep the portal, the daily updates and the
 * invoices right up to the day they leave. This is what actually closes them, the morning
 * after.
 *
 * Runs the real endpoint rather than repeating what it does. De-enrolment withdraws the
 * children, closes the guardian logins, writes the audit trail and sends the goodbye
 * letter with the itemised balance; a second copy of that here would drift from it within
 * a month, and the copy that drifts is the one that silently stops emailing people.
 */
class ApplyFamilyDepartures extends Command
{
    protected $signature = 'families:apply-departures {--dry : List what is due without closing anything}';

    protected $description = 'Complete family de-enrolments whose agreed last day has passed';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');

        /* Strictly BEFORE today: a family's last day is a day they still attend, so a
           departure dated today is completed tomorrow morning, not this morning.

           "Today" is the AGENCY's date, not UTC. This command is scheduled at 01:15
           Toronto (05:15 UTC), where the two happen to agree — so the old UTC comparison
           was right by accident of the schedule, and moving the cron into the evening
           would have started closing families a day early with nothing to show for it.
           All current agencies are Ontario; a non-Ontario agency would need this resolved
           per family rather than once. */
        $due = DB::table('families')
            ->whereNotNull('departure_date')
            ->whereNull('departure_applied_at')
            ->whereNull('deleted_at')
            ->whereDate('departure_date', '<', \App\Support\AgencyTime::today())
            ->get(['id', 'family_name', 'departure_date', 'departure_by_id']);

        if ($due->isEmpty()) {
            $this->info('No family departures are due.');

            return self::SUCCESS;
        }

        $done = 0;
        foreach ($due as $f) {
            $this->line(sprintf('  %-28s last day %s', $f->family_name, $f->departure_date));
            if ($dry) {
                continue;
            }

            try {
                /* As the person who scheduled it, so the audit trail names a human rather
                   than "system" — they made this decision, the clock only carried it out.
                   Falls back to the agency's first admin if that account has since gone. */
                $actorId = $f->departure_by_id ?: DB::table('role_assignments as ra')
                    ->join('families as fam', 'fam.id', '=', DB::raw((int) $f->id))
                    ->join('centres as c', 'c.id', '=', 'fam.centre_id')
                    ->whereColumn('ra.agency_id', 'c.agency_id')
                    ->where('ra.role', 'agency_admin')->where('ra.active', 1)
                    ->value('ra.user_id');

                $actor = $actorId ? \App\Models\User::find($actorId) : null;
                if (! $actor) {
                    $this->error('  no actor could be resolved — skipped');
                    continue;
                }

                $req = \Illuminate\Http\Request::create('/api/v1/admin/families/'.$f->id, 'DELETE', [
                    // The balance was acknowledged when the departure was booked; refusing
                    // now would strand the family half-closed with nobody watching.
                    'acknowledged_balance' => 1,
                    'last_day' => $f->departure_date,
                ]);
                $req->setUserResolver(fn () => $actor);
                $agencyId = DB::table('families as fam')->join('centres as c', 'c.id', '=', 'fam.centre_id')
                    ->where('fam.id', $f->id)->value('c.agency_id');
                if ($agencyId) {
                    $req->headers->set('X-Active-Agency-Id', (string) $agencyId);
                }

                app(\App\Http\Controllers\Api\AdminController::class)->destroyFamily($req, (int) $f->id);
                $done++;
            } catch (\Throwable $e) {
                Log::warning('Family departure failed', ['family' => $f->id, 'error' => $e->getMessage()]);
                $this->error('  '.$f->family_name.': '.$e->getMessage());
            }
        }

        $this->info(($dry ? 'Would complete ' : 'Completed ').($dry ? $due->count() : $done).' departure(s).');

        return self::SUCCESS;
    }
}
