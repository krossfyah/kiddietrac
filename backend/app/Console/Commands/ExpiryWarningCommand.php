<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p51 — Daily expiry-warning scan for certifications + background checks.
 * Notifies the holder + their agency_admin if anything expires within 60 days.
 * De-duped via notification.body containing the cert id (we don't want to
 * spam every day for the same expiring cert — fired once per (cert, calendar week)).
 */
final class ExpiryWarningCommand extends Command
{
    protected $signature = 'expiry:warn {--days=60 : Warn ahead this many days}';
    protected $description = 'Daily warning emails for expiring background checks and certifications';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = Carbon::now()->addDays($days)->endOfDay();
        $now = Carbon::now();
        $weekKey = Carbon::now()->format('o-W');

        // Only people still with us: a closed account is not chased for paperwork.
        $rows = DB::table('background_checks')
            ->whereIn('user_id', fn ($q) => \App\Support\Audience::excludeOff($q->from('users as u')->whereNull('u.deleted_at'), 'u')->select('u.id'))
            ->where('expires_at', '<=', $cutoff)
            ->where('expires_at', '>=', $now->subDays(30))
            ->get();

        $sent = 0;
        foreach ($rows as $bgc) {
            $dedupe = "bgc:{$bgc->id}:{$weekKey}";
            $already = DB::table('notifications')
                ->where('user_id', $bgc->user_id)
                ->where('body', 'like', "%[{$dedupe}]%")
                ->exists();
            if ($already) continue;

            $exp = Carbon::parse($bgc->expires_at);
            $daysOut = max(0, (int) $now->diffInDays($exp, false));
            $title = $exp->isPast()
                ? "Background check expired ({$bgc->check_type})"
                : "Background check expires in {$daysOut} day(s) ({$bgc->check_type})";

            \App\Support\Notify::write([
                'user_id'    => $bgc->user_id,
                'type'       => 'compliance',
                'title'      => $title,
                'body'       => "Reference: {$bgc->reference}. Expires: " . $exp->format('M j, Y') . " [{$dedupe}]",
                /* Was 'link_url' => '#background-checks' — notifications has no such
                   column, so every insert here threw and not one expiry warning has
                   ever been delivered. The destination belongs in the data blob under
                   'hash', which is what the bell actually reads. */
                'data'       => json_encode(['hash' => 'background-checks']),
                'created_at' => now(),
            ]);
            $sent++;
        }
        $this->info("Sent {$sent} expiry warnings");

        /* DEADLINES FROM HIRE DATE, AND TRAINING HOURS (2026-10-10). Where the agency's
           jurisdiction sets them (App\Support\StaffCompliance — Ohio today; Ontario sets none,
           so nothing here runs for it): a bell to the staff member for anything due within 14
           days or overdue, a required check not on file, and training hours still short in the
           last 30 days of the training year. Same weekly de-duplication as above; no email. */
        $deadlineBells = 0;
        foreach (DB::table('agencies')->whereNull('deleted_at')->pluck('id') as $agencyId) {
            $rules = \App\Support\StaffCompliance::rules((int) $agencyId);
            if (! $rules['enabled']) continue;
            foreach (\App\Support\StaffCompliance::staffIds((int) $agencyId) as $uid) {
                $c = \App\Support\StaffCompliance::forUser((int) $agencyId, $uid, $rules);
                $soon = Carbon::parse($c['today'])->addDays(14)->toDateString();
                $lines = [];
                foreach ($c['items'] as $it) {
                    if ($it['status'] === 'overdue') $lines[] = $it['label'] . ' — overdue (was due ' . $it['deadline'] . ')';
                    elseif ($it['status'] === 'due' && ! empty($it['deadline']) && $it['deadline'] <= $soon) $lines[] = $it['label'] . ' — due by ' . $it['deadline'];
                    elseif ($it['status'] === 'missing' && $it['kind'] === 'check') $lines[] = $it['label'] . ' — not on file';
                }
                if ($c['pd'] && $c['pd']['status'] !== 'done' && $c['pd']['to'] <= Carbon::parse($c['today'])->addDays(30)->toDateString()) {
                    $lines[] = 'Training hours: ' . $c['pd']['approved'] . ' of ' . $c['pd']['required'] . ' approved — the year ends ' . $c['pd']['to'];
                }
                if (! $lines) continue;
                $dedupe = "staffdl:{$uid}:{$weekKey}";
                if (DB::table('notifications')->where('user_id', $uid)->where('body', 'like', "%[{$dedupe}]%")->exists()) continue;
                \App\Support\Notify::write([
                    'user_id' => $uid, 'type' => 'compliance',
                    'title' => count($lines) === 1 ? 'Training / checks: 1 item needs attention' : 'Training / checks: ' . count($lines) . ' items need attention',
                    'body' => implode(' · ', array_slice($lines, 0, 4)) . " [{$dedupe}]",
                    'data' => json_encode(['hash' => 'my-training']), 'created_at' => now(),
                ]);
                $deadlineBells++;
            }
        }
        $this->info("Sent {$deadlineBells} staff deadline reminders");

        return 0;
    }
}
