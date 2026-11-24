<?php

namespace App\Console\Commands;

use App\Support\PlatformSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nightly reset of the public website demo (2026-10-31; App\Support\PublicDemo). Removes exactly the rows website
 * visitors inserted (recorded by PublicDemoGuard in web_demo_rows) - never the seeded demo data, never ids or
 * passwords (unlike kt:seed-brightwood-demo --rebuild) - and revokes expired "web-demo" tokens.
 */
class ResetWebDemo extends Command
{
    protected $signature = 'kt:reset-web-demo {--dry-run : show what would be removed, change nothing}';
    protected $description = 'Remove what public website demo visitors added (rows recorded by PublicDemoGuard) and expired demo logins';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        if (! Schema::hasTable('web_demo_rows')) { $this->warn('web_demo_rows does not exist yet.'); return self::SUCCESS; }
        $since = PlatformSettings::get('webdemo.last_reset');
        $maxId = (int) DB::table('web_demo_rows')->max('id');
        $rows = DB::table('web_demo_rows')->where('id', '<=', $maxId)->orderByDesc('id')->get(['id', 'table_name', 'row_id']);
        $byTable = [];
        foreach ($rows as $r) $byTable[$r->table_name][] = (int) $r->row_id;
        $this->line('Last reset: ' . ($since ?: 'never') . ' · recorded visitor rows: ' . $rows->count());
        // Simulated invoice payments (App\Support\WebDemoPayments): invoices back to unpaid before their payment rows go.
        $this->line('  demo payments undone: ' . \App\Support\WebDemoPayments::restore($dry));
        $total = 0;
        // Newest tables first (a message before its conversation), each table's rows in one statement.
        foreach ($byTable as $table => $ids) {
            if (! preg_match('/^[a-z0-9_]+$/', $table) || ! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
                $this->line(sprintf('  %-34s %5d  (skipped: no such table/id)', $table, count($ids)));
                continue;
            }
            $ids = array_values(array_unique($ids));
            $exists = DB::table($table)->whereIn('id', $ids)->count();
            $this->line(sprintf('  %-34s %5d recorded · %5d still there', $table, count($ids), $exists));
            if (! $dry && $exists) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    try { $total += DB::table($table)->whereIn('id', $chunk)->delete(); }
                    catch (\Throwable $e) { $this->warn("  {$table}: " . mb_substr($e->getMessage(), 0, 160)); }
                }
            }
        }
        // The demo phone (2026-10-30, App\Support\WebDemoPhone): its calls, texts and events, whoever started them.
        if (Schema::hasTable('web_demo_events')) {
            $ev = DB::table('web_demo_events')->count();
            $vc = DB::table('voice_calls')->where('provider', \App\Support\WebDemoPhone::PROVIDER)->count();
            $sm = DB::table('sms_messages')->where('provider', \App\Support\WebDemoPhone::PROVIDER)->count();
            $this->line(sprintf('  demo phone: %d events · %d calls · %d texts', $ev, $vc, $sm));
            if (! $dry) {
                DB::table('web_demo_events')->delete();
                DB::table('voice_calls')->where('provider', \App\Support\WebDemoPhone::PROVIDER)->delete();
                DB::table('sms_messages')->where('provider', \App\Support\WebDemoPhone::PROVIDER)->delete();
            }
        }
        // Every demo parent hears about sign-in / sign-out in the app, by email and by text (2026-10-31).
        $this->line('  demo parents with every channel on: ' . \App\Support\PublicDemo::demoPrefs($dry));
        // The demo phone's Mail app (2026-10-31): every captured message and its attachments.
        if (Schema::hasTable('web_demo_mail')) $this->line('  demo mail: ' . \App\Support\WebDemoMail::purge($dry) . ' messages');
        // The demo logins back as sales hands them out, every session signed out (2026-10-31; new logins 2026-10-30).
        foreach (\App\Support\PublicDemo::restoreAccounts($dry) as $line) $this->line('  ' . $line);
        $this->line('Fictional shifts left open: ' . \App\Support\PublicDemo::endShifts(true) . ($dry ? '' : ' (closed)'));
        if (! $dry) \App\Support\PublicDemo::endShifts();
        // The lived-in baseline (photos, videos, menus, lesson plans, invoices ...) written again, dates rolled forward.
        if (! $dry) {
            foreach (\App\Support\WebDemoContent::run() as $ag => $r) $this->line("  demo content, agency {$ag}: " . json_encode($r));
        }
        $expired = DB::table('personal_access_tokens')->where('name', \App\Support\PublicDemo::TOKEN)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '<', now()))->count();
        $this->line("Expired web-demo logins: {$expired}");
        if (! $dry) {
            DB::table('personal_access_tokens')->where('name', \App\Support\PublicDemo::TOKEN)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '<', now()))->delete();
            DB::table('web_demo_rows')->where('id', '<=', $maxId)->delete();
            PlatformSettings::set('webdemo.last_reset', now()->toIso8601String());
            $this->info("Removed {$total} visitor rows.");
        } else {
            $this->info('Dry run: nothing changed.');
        }
        return self::SUCCESS;
    }
}
