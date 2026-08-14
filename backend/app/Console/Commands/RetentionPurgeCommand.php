<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Data-retention auto-purge (nightly). Enforces each agency's Data Retention &
 * Compliance policy (agencies.settings->compliance) — but ONLY for agencies that
 * explicitly turned on `auto_enforce`. Opt-in by design, so it can never touch an
 * agency that hasn't consented.
 *
 * Per policy:
 *   • message_months       → chat messages older than N months
 *   • announcement_months  → announcements/news older than N months
 *
 * enforce_mode:
 *   • 'delete'    → messages soft-deleted (deleted_at); announcements hard-deleted.
 *   • 'anonymize' → content blanked but the row is kept (audit trail intact).
 *
 * Run `php artisan retention:purge --dry-run` to preview without changing data.
 */
final class RetentionPurgeCommand extends Command
{
    protected $signature = 'retention:purge {--dry-run : report what would be purged without changing anything}';
    protected $description = 'Enforce each agency\'s data-retention policy (chat + announcements) for agencies with auto-enforce on.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $agencies = DB::table('agencies')->whereNull('deleted_at')->get(['id', 'name', 'settings']);
        $totalMsg = 0;
        $totalAnn = 0;
        $totalFam = 0;

        foreach ($agencies as $a) {
            $settings = $a->settings ? (json_decode($a->settings, true) ?: []) : [];
            $c = (isset($settings['compliance']) && is_array($settings['compliance'])) ? $settings['compliance'] : [];
            if (empty($c['auto_enforce'])) {
                continue; // opt-in only — never purge an agency that hasn't enabled it
            }
            $mode = ($c['enforce_mode'] ?? 'anonymize') === 'delete' ? 'delete' : 'anonymize';
            $msgMonths = (int) ($c['message_months'] ?? 24);
            $annMonths = (int) ($c['announcement_months'] ?? 24);
            // Defaults to 0 - do nothing. The two above default to 24 months, which is
            // reasonable for chat; a default here would start removing family records
            // at agencies that never asked for it, on the first night this ran.
            $famMonths = (int) ($c['suspended_family_months'] ?? 0);
            $centreIds = DB::table('centres')->where('agency_id', $a->id)->pluck('id')->all();

            // ── Chat messages ────────────────────────────────────────────────
            if ($msgMonths > 0 && $centreIds) {
                $cutoff = Carbon::now()->subMonths($msgMonths);
                $convIds = DB::table('conversations')->whereIn('centre_id', $centreIds)->pluck('id')->all();
                if ($convIds) {
                    $base = DB::table('messages')->whereIn('conversation_id', $convIds)
                        ->where('created_at', '<', $cutoff)->whereNull('deleted_at');
                    $count = (clone $base)->count();
                    if ($count > 0 && ! $dry) {
                        if ($mode === 'delete') {
                            $base->update(['deleted_at' => now()]);
                        } else {
                            $base->update([
                                'body' => '[Removed per data-retention policy]',
                                'translated_body' => null,
                                'attachments' => null,
                            ]);
                        }
                    }
                    $totalMsg += $count;
                    if ($count > 0) $this->line("Agency {$a->id} ({$a->name}): {$count} chat messages > {$msgMonths}mo" . ($dry ? ' [dry-run]' : " [{$mode}]"));
                }
            }

            // ── Announcements ────────────────────────────────────────────────
            if ($annMonths > 0) {
                $cutoff = Carbon::now()->subMonths($annMonths);
                $base = DB::table('announcements')->where('created_at', '<', $cutoff)
                    ->where(function ($w) use ($a, $centreIds) {
                        $w->where(function ($x) use ($a) { $x->where('scope_type', 'agency')->where('scope_id', $a->id); });
                        if ($centreIds) {
                            $w->orWhere(function ($x) use ($centreIds) { $x->where('scope_type', 'centre')->whereIn('scope_id', $centreIds); });
                        }
                    });
                $count = (clone $base)->count();
                if ($count > 0 && ! $dry) {
                    if ($mode === 'delete') {
                        $base->delete();
                    } else {
                        $base->update(['title' => '[Removed]', 'body' => '[Removed per data-retention policy]']);
                    }
                }
                $totalAnn += $count;
                if ($count > 0) $this->line("Agency {$a->id} ({$a->name}): {$count} announcements > {$annMonths}mo" . ($dry ? ' [dry-run]' : " [{$mode}]"));
            }

            // ── Suspended families ───────────────────────────────────────────
            // A family paused for longer than the agency's retention period is
            // removed from the portal. The FAMILY row is soft-deleted; the children
            // and their care records are deliberately left untouched.
            //
            // That is not squeamishness. The access-removal notice this platform
            // sends states in writing that enrolment, attendance, daily care notes,
            // medication and incident records are licensed child care records the
            // agency is required to keep and cannot delete on request. A nightly job
            // that quietly deleted them would make that letter a lie.
            if ($famMonths > 0 && $centreIds) {
                $cutoff = Carbon::now()->subMonths($famMonths);
                $base = DB::table('families')
                    ->whereIn('centre_id', $centreIds)
                    ->whereNotNull('suspended_at')
                    ->where('suspended_at', '<', $cutoff)
                    ->whereNull('deleted_at');
                $count = (clone $base)->count();
                if ($count > 0 && ! $dry) {
                    $base->update(['deleted_at' => now(), 'updated_at' => now()]);
                }
                $totalFam += $count;
                if ($count > 0) {
                    $line = "Agency {$a->id} ({$a->name}): {$count} families suspended > {$famMonths}mo"
                        . ($dry ? ' [dry-run]' : ' [removed from portal; child care records retained]');
                    $this->line($line);
                    // Removing a family is not like trimming old chat. Leave a record
                    // of it that does not depend on anyone reading console output.
                    if (! $dry) {
                        Log::info('retention:purge removed suspended families', [
                            'agency_id' => $a->id, 'families' => $count, 'months' => $famMonths,
                        ]);
                    }
                }
            }
        }

        $msg = 'retention:purge ' . ($dry ? '(dry-run) ' : '')
            . "complete — {$totalMsg} messages, {$totalAnn} announcements, {$totalFam} suspended families.";
        $this->info($msg);
        Log::info($msg);
        return self::SUCCESS;
    }
}
