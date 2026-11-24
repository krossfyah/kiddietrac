<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AgencyTime;
use App\Support\PaymentAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Yesterday's money, emailed to each agency's admins and directors at 7 AM agency time (App\Support\PaymentAlerts,
 * 2026-11-05). Runs hourly; each agency is sent once per day, and only when something happened.
 *
 *   billing:payment-summary                      the scheduled pass
 *   billing:payment-summary --agency=6 --dry     list what would go, send nothing
 *   billing:payment-summary --agency=6 --date=2026-11-05 --to=me@example.com
 *                                                one agency-day, every email to that one address ("[TEST]")
 */
final class PaymentSummaryCommand extends Command
{
    protected $signature = 'billing:payment-summary {--agency= : one agency id} {--date= : the agency-local day (default yesterday)}
        {--to= : send every email to this one address instead} {--dry : build, list, send nothing}';

    protected $description = "Email each agency's admins and directors yesterday's payments (received, failed, refunded)";

    private const HOUR = 7;

    public function handle(): int
    {
        $one = $this->option('agency') ? (int) $this->option('agency') : null;
        $to = trim((string) $this->option('to'));
        $dry = (bool) $this->option('dry');
        if ($to !== '') {
            if (! $one || ! filter_var($to, FILTER_VALIDATE_EMAIL)) { $this->error('--to needs --agency and a valid address.'); return 1; }
            PaymentAlerts::$redirect = ['user_id' => 0, 'email' => $to];
        }
        $ids = $one ? [$one] : DB::table('agencies')->pluck('id')->map(fn ($v) => (int) $v)->all();
        foreach ($ids as $aid) {
            $now = AgencyTime::now($aid);
            $date = $this->option('date') ?: $now->copy()->subDay()->format('Y-m-d');
            // The scheduled pass: 7 AM in the agency's own zone, once per agency-day.
            if (! $one && ! $this->option('date')) {
                if ((int) $now->format('G') !== self::HOUR) continue;
                if (! $dry && ! Cache::add('kt-paysum:' . $aid . ':' . $date, 1, now()->addDays(3))) continue;
            }
            foreach (PaymentAlerts::summary($aid, $date, $dry) as $s) {
                $this->line(($dry ? '[dry] ' : '') . 'agency ' . $aid . ' ' . $date . ' -> ' . $s['to'] . ' | ' . $s['subject']
                    . ' (' . $s['received'] . ' in, ' . $s['failed'] . ' failed, ' . $s['refunds'] . ' refunds)');
            }
        }

        return 0;
    }
}
