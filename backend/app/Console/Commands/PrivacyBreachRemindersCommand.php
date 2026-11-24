<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AgencyTime;
use App\Support\PrivacyBreachAlerts;
use App\Support\PrivacyRequestAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Overdue privacy breaches (and privacy requests coming due - App\Support\PrivacyRequestAlerts), emailed to each agency's admins and the breach centres' directors at the hour each agency picks (8 AM by default) in agency time
 * (App\Support\PrivacyBreachAlerts, 2026-11-06). Runs hourly; each agency once per day, and only when something is
 * overdue: a real-risk decision 7+ days after discovery, or a "Yes" not yet reported to the Commissioner or notified.
 *
 *   privacy:breach-reminders                          the scheduled pass
 *   privacy:breach-reminders --agency=6 --dry         list what would go, send nothing
 *   privacy:breach-reminders --agency=6 --to=me@example.com
 *                                                     that agency's digest, sent to that one address only ("[TEST]")
 */
final class PrivacyBreachRemindersCommand extends Command
{
    protected $signature = 'privacy:breach-reminders {--agency= : one agency id} {--to= : send to this one address instead} {--dry : list, send nothing}';

    protected $description = "Email each agency's admins and directors the privacy breaches that still need action";

    public function handle(): int
    {
        $one = $this->option('agency') ? (int) $this->option('agency') : null;
        $to = trim((string) $this->option('to'));
        $dry = (bool) $this->option('dry');
        if ($to !== '') {
            if (! $one || ! filter_var($to, FILTER_VALIDATE_EMAIL)) { $this->error('--to needs --agency and a valid address.'); return 1; }
            PrivacyBreachAlerts::$redirect = ['user_id' => 0, 'email' => $to];
        }
        // Agencies with breaches OR privacy requests (App\Support\PrivacyRequestAlerts rides the same hour, 2026-11-10).
        $ids = $one ? [$one] : DB::table('privacy_breaches')->distinct()->pluck('agency_id')
            ->merge(DB::table('privacy_requests')->whereIn('status', ['open', 'in_progress'])->distinct()->pluck('agency_id'))
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
        foreach ($ids as $aid) {
            $now = AgencyTime::now($aid);
            if (! $one) {
                if ((int) $now->format('G') !== PrivacyBreachAlerts::settings($aid)['reminder_hour']) continue;
                if (! $dry && ! Cache::add('kt-pbremind:' . $aid . ':' . $now->format('Y-m-d'), 1, now()->addDays(3))) continue;
            }
            $sent = PrivacyBreachAlerts::digest($aid, $dry);
            foreach ($sent as $s) {
                $this->line(($dry ? '[dry] ' : '') . 'agency ' . $aid . ' -> ' . $s['to'] . ' | ' . $s['subject'] . ' (' . $s['count'] . ')');
            }
            if (! $sent && $one) $this->line('agency ' . $aid . ': nothing overdue.');
            // Requests due within 7 days or overdue: a bell row to each admin. Bell only, so a --to test lists them instead.
            foreach (PrivacyRequestAlerts::digest($aid, $dry || $to !== '') as $s) {
                $this->line(($dry || $to !== '' ? '[dry] ' : '') . 'agency ' . $aid . ' -> user ' . $s['to'] . ' (bell) | ' . $s['title']);
            }
        }

        return 0;
    }
}
