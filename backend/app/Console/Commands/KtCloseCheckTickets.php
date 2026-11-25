<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * ONE-OFF (2026-11-25, Anthony): close support tickets #266, #267 and #268, which the nightly
 * checks filed on 2026-11-25 and commit c83c6a3 fixed, but only once the checks PASS. He said to
 * close them "only after tomorrow's checks pass", each with its resolution note, and to leave any
 * that did not pass open.
 *
 * A ticket counts as passed when BOTH hold:
 *   1. the scheduled run this morning did not fail: a failing scheduled command appends
 *      "happened again" to the same open ticket (ProblemReport de-duplicates by subject), which
 *      moves updated_at, so the ticket must be untouched since 05:30 Toronto today; and
 *   2. the check, re-run now with its mail/alert switched off, exits 0.
 *
 * Scheduled once, for 06:15 Toronto on 2026-11-26 (routes/console.php); on any other day the
 * schedule skips it. It sends nothing: auto-filed tickets have no reporter, and the resolve
 * email is never sent for them anyway (SupportTicketController::notifyReporterResolved).
 *
 *   php artisan kt:close-check-tickets --dry   (reports what it would do, changes nothing)
 */
class KtCloseCheckTickets extends Command
{
    protected $signature = 'kt:close-check-tickets {--dry : report only}';
    protected $description = 'One-off: resolve tickets #266-#268 if their nightly checks now pass.';

    /** ticket id => [check command (mail/alert off), resolution note] */
    public const TICKETS = [
        266 => ['kt:leak-sweep --no-mail',
            'Fixed 2026-11-25 (c83c6a3). kt:leak-sweep failed because families held on the agency waitlist after their provider closed (#251) name the closed centre on purpose: as their previous centre, in the closure waitlist entry\'s notes, and in the "need a new home" notice to admins. The sweep now skips exactly those; everything else is still checked. The nightly run and a re-run both passed.'],
        267 => ['kt:search-coverage --no-mail',
            'Fixed 2026-11-25 (c83c6a3). kt:search-coverage failed because seven tables added 2026-11-24 were never classified for global search (temporary pickups, parent phone messages, Play Spark). All are now classified in SearchRegistry. The nightly run and a re-run both passed.'],
        268 => ['kt:notify-audience-check --no-alert',
            'Fixed 2026-11-25 (c83c6a3). kt:notify-audience-check failed because the new Safe Arrival "Notify me about" setting kept its audience inline instead of in NotifyAudience::PREF_EVENTS (now mapped there as staff). The "clock: 3 to 1 account" finding was the iLearn integration account, written before it moved to the silent integration role; nothing has reached it since and those rows have aged out. The nightly run and a re-run both passed.'],
    ];

    public function handle(): int
    {
        $since = Carbon::today('America/Toronto')->setTime(5, 30)->utc();
        $dry = (bool) $this->option('dry');
        $closer = (int) DB::table('users')->where('email', 'mr.anthonyhosein@gmail.com')->value('id') ?: null;

        foreach (self::TICKETS as $id => [$check, $note]) {
            $t = DB::table('support_tickets')->where('id', $id)->first();
            if (! $t || (string) $t->status !== 'open') {
                $this->line("#$id: not open (" . ($t->status ?? 'missing') . '), left alone');
                continue;
            }
            $touched = Carbon::parse($t->updated_at, 'UTC')->gte($since);
            $p = new Process(array_merge([PHP_BINARY, base_path('artisan')], preg_split('/\s+/', $check)), base_path(), null, null, 900);
            $p->run();
            $code = $p->getExitCode();
            $ok = ! $touched && $code === 0;
            $why = $touched ? 'the scheduled run failed again this morning (ticket updated ' . $t->updated_at . ')' : "re-run exit $code";
            $this->line("#$id $check: " . ($ok ? 'PASSED' : 'NOT PASSED') . " ($why)");
            Log::info('[kt:close-check-tickets] #' . $id . ' ' . ($ok ? 'passed' : 'not passed') . ': ' . $why);
            if (! $ok || $dry) {
                continue;
            }
            DB::table('support_tickets')->where('id', $id)->where('status', 'open')->update([
                'status' => 'resolved', 'resolved_at' => now(), 'resolved_by_user_id' => $closer,
                'resolution' => $note, 'updated_at' => now(),
            ]);
            DB::table('support_ticket_events')->insert([
                'ticket_id' => $id, 'type' => 'status', 'from_value' => 'open', 'to_value' => 'resolved',
                'note' => $note, 'user_id' => $closer, 'created_at' => now(),
            ]);
            $this->info("#$id resolved");
        }

        return self::SUCCESS;
    }
}
