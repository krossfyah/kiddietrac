<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Features;
use App\Support\Notify;
use App\Support\Payroll\PayrollTax;
use App\Support\Payroll\TaxTables;
use App\Support\TestAgency;
use App\Support\TicketAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 1 (2026-11-27): next year's tax tables must be in before the first January pay.
 * CRA publishes T4127 (January edition) in November and Revenu Quebec publishes TP-1015.F in December; somebody has to
 * add resources/payroll/ca/<year>/<year>-01-01.json (from the official tables, never guessed) and re-run
 * payroll:calc-check against a fresh PDOC fixture.
 *
 * Runs daily in December (or any day with --force). When next year's Canadian tables, or their Quebec block, are
 * missing it tells: the platform admins (bell, once a week per gap) and, for agencies that have switched the payroll
 * engine on (and have the HR add-on, and are not test agencies), their agency admins (bell, once per gap). Nothing is
 * emailed. While no agency has the engine on, only platform admins hear about it.
 */
final class PayrollTablesCheckCommand extends Command
{
    protected $signature = 'payroll:tables-check {--force : run outside December} {--dry : print only, tell nobody}';

    protected $description = 'Warn when next year\'s CRA / Revenu Quebec payroll tables are not loaded yet';

    public function handle(): int
    {
        $now = now('America/Toronto');
        if ((int) $now->format('n') !== 12 && ! $this->option('force')) {
            $this->line('Not December: nothing to check (use --force).');

            return 0;
        }
        $next = (int) $now->format('Y') + 1;
        $gaps = [];
        if (! TaxTables::hasYear('CA', $next)) {
            $gaps[] = 'CRA ' . $next . ' payroll deductions tables (T4127, January ' . $next . ' edition)';
        } elseif (! TaxTables::hasQuebec($next)) {
            $gaps[] = 'Revenu Québec ' . $next . ' source deduction formulas (TP-1015.F)';
        }
        if (! $gaps) {
            $this->info($next . ' Canadian payroll tables are loaded.');

            return 0;
        }
        $what = implode(' and ', $gaps);
        $this->warn('Missing: ' . $what);
        if ($this->option('dry')) {
            return 0;
        }
        $key = 'payroll-tables-gap:' . $next . ':' . md5($what);
        $body = 'Add resources/payroll/ca/' . $next . '/' . $next . '-01-01.json from the official tables (canada.ca T4127 / revenuquebec.ca TP-1015.F), then run payroll:calc-check with a new PDOC fixture. Until then, pays dated in ' . $next . ' cannot be calculated.';
        if (Cache::add($key . ':platform:' . $now->format('W'), 1, now()->addDays(8))) {
            foreach (TicketAlerts::adminIds() as $uid) {
                Notify::write(['user_id' => $uid, 'type' => 'platform', 'title' => 'Payroll: ' . $next . ' tax tables not loaded yet',
                    'body' => mb_substr('Missing: ' . $what . '. ' . $body, 0, 900), 'data' => json_encode(['hash' => 'server-health', 'payroll_tables' => $next]), 'created_at' => now()]);
            }
        }
        $agencies = DB::table('agencies')->where('settings', 'like', '%payroll_engine%')->pluck('id');
        foreach ($agencies as $aid) {
            $aid = (int) $aid;
            if (! PayrollTax::engineOn($aid) || Features::skips($aid, 'hr', 'payroll tables check') || TestAgency::isAgency($aid)) {
                continue;
            }
            if (! Cache::add($key . ':agency:' . $aid, 1, now()->addDays(40))) {
                continue;
            }
            $admins = DB::table('role_assignments')->where('agency_id', $aid)->where('role', 'agency_admin')->where('active', 1)->distinct()->pluck('user_id');
            foreach ($admins as $uid) {
                if (TicketAlerts::allowsUser((int) $uid)) {
                    continue;   // platform admins are not agency staff
                }
                Notify::write(['agency_id' => $aid, 'user_id' => (int) $uid, 'type' => 'hr', 'title' => 'Payroll: ' . $next . ' tax tables are on their way',
                    'body' => 'The ' . $next . ' government tax tables are not in KiddieTrac yet. We add them before your first ' . $next . ' pay; pays dated in ' . $next . ' wait until then.',
                    'data' => json_encode(['hash' => 'hr', 'payroll_tables' => $next]), 'created_at' => now()]);
            }
        }

        return 0;
    }
}
