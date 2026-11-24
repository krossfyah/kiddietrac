<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
        $totalMore = 0;

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

            $totalMore += $this->more($a, $c, $mode, $dry);

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

        $totalMore += $this->system($dry);

        $msg = 'retention:purge ' . ($dry ? '(dry-run) ' : '')
            . "complete — {$totalMsg} messages, {$totalAnn} announcements, {$totalFam} suspended families, {$totalMore} recordings/GPS/log rows.";
        $this->info($msg);
        Log::info($msg);
        return self::SUCCESS;
    }

    /**
     * PIPEDA review (2026-11-10): recordings, GPS trails and the text/email logs. Each period defaults to 0 = never,
     * and like the rest only runs for an agency with auto_enforce on. Audio and location cannot be "anonymised", so
     * those are removed in either mode; the logs follow enforce_mode. Returns the rows acted on.
     */
    private function more(object $a, array $c, string $mode, bool $dry): int
    {
        $n = 0;
        $say = function (string $what, int $count, int $months, string $how) use ($a, $dry) {
            if ($count > 0) $this->line("Agency {$a->id} ({$a->name}): {$count} {$what} > {$months}mo" . ($dry ? ' [dry-run]' : " [{$how}]"));
        };

        // Call recordings + transcripts: the audio file and the text go; the call row (who, when, how long) stays.
        $m = (int) ($c['recording_months'] ?? 0);
        if ($m > 0) {
            $rows = DB::table('outbound_calls')->where('agency_id', $a->id)->where('created_at', '<', Carbon::now()->subMonths($m))
                ->where(fn ($w) => $w->whereNotNull('recording_path')->orWhereNotNull('transcript'))->get(['id', 'recording_path']);
            if (! $dry) {
                foreach ($rows as $r) {
                    if ($r->recording_path && Storage::disk('local')->exists($r->recording_path)) Storage::disk('local')->delete($r->recording_path);
                    DB::table('outbound_calls')->where('id', $r->id)->update(['recording_path' => null, 'recording_sid' => null,
                        'transcript' => null, 'updated_at' => now()]);
                }
            }
            $say('call recordings/transcripts', $rows->count(), $m, 'audio and transcript removed');
            $n += $rows->count();
        }

        // Walk / field trip GPS points: the trip row stays, its trail goes.
        $m = (int) ($c['walk_gps_months'] ?? 0);
        if ($m > 0) {
            $trips = DB::table('field_trips')->where('agency_id', $a->id)->where('trip_date', '<', \App\Support\AgencyTime::todayDate((int) $a->id)->subMonths($m)->toDateString())->pluck('id');
            $count = $trips->isEmpty() ? 0 : (int) DB::table('field_trip_pings')->whereIn('field_trip_id', $trips)->count();
            if ($count > 0 && ! $dry) DB::table('field_trip_pings')->whereIn('field_trip_id', $trips)->delete();
            $say('GPS points', $count, $m, 'removed');
            $n += $count;
        }

        // The text log.
        $m = (int) ($c['sms_log_months'] ?? 0);
        if ($m > 0) {
            $base = DB::table('sms_messages')->where('agency_id', $a->id)->where('created_at', '<', Carbon::now()->subMonths($m));
            if ($mode === 'anonymize') $base->where(fn ($w) => $w->where('body', '!=', '[Removed per data-retention policy]')->orWhere('to_phone', '!=', ''));
            $count = (clone $base)->count();
            if ($count > 0 && ! $dry) {
                $mode === 'delete' ? $base->delete() : $base->update(['body' => '[Removed per data-retention policy]', 'to_phone' => '']);   // NOT NULL column
            }
            $say('logged texts', $count, $m, $mode);
            $n += $count;
        }

        // The email log. Anonymised rows keep date/status for delivery stats; who and what are removed.
        $m = (int) ($c['email_log_months'] ?? 0);
        if ($m > 0) {
            $base = DB::table('email_logs')->where('agency_id', $a->id)->where('created_at', '<', Carbon::now()->subMonths($m));
            if ($mode === 'anonymize') $base->where(fn ($w) => $w->whereNotNull('to_email')->orWhereNotNull('body_html'));
            $count = (clone $base)->count();
            if ($count > 0 && ! $dry) {
                $mode === 'delete' ? $base->delete() : $base->update(['to_email' => null, 'to_name' => null, 'cc' => null, 'bcc' => null,
                    'subject' => '[Removed]', 'body_html' => null, 'tracking_token' => null]);
            }
            $say('logged emails', $count, $m, $mode);
            $n += $count;
        }

        return $n;
    }

    /**
     * Platform leftovers that belong to no agency: used or expired password-reset codes, failed queue jobs, old
     * "new sign-in place" markers, and the sales line's call recordings. OFF unless the platform setting
     * retention.system_enabled is true (a dry run always reports them). retention.platform_recording_months: 0 = keep.
     */
    private function system(bool $dry): int
    {
        $on = (bool) \App\Support\PlatformSettings::get('retention.system_enabled', false);
        $act = $on && ! $dry;
        if (! $on && ! $dry) return 0;
        $tag = $dry ? ($on ? ' [dry-run]' : ' [dry-run; OFF - retention.system_enabled]') : ' [removed]';
        $n = 0;

        $q = DB::table('password_resets')->where('created_at', '<', Carbon::now()->subDays(30))
            ->where(fn ($w) => $w->whereNotNull('used_at')->orWhere('expires_at', '<', now()));
        $c = (clone $q)->count(); if ($c && $act) $q->delete();
        if ($c) $this->line("Platform: {$c} used/expired password-reset codes > 30 days{$tag}");
        $n += $c;

        $q = DB::table('failed_jobs')->where('failed_at', '<', Carbon::now()->subDays(30));
        $c = (clone $q)->count(); if ($c && $act) $q->delete();
        if ($c) $this->line("Platform: {$c} failed queue jobs > 30 days{$tag}");
        $n += $c;

        $q = DB::table('user_signin_places')->where('last_seen_at', '<', Carbon::now()->subMonths(13));
        $c = (clone $q)->count(); if ($c && $act) $q->delete();
        if ($c) $this->line("Platform: {$c} sign-in places not seen in 13 months{$tag}");
        $n += $c;

        $m = (int) \App\Support\PlatformSettings::get('retention.platform_recording_months', 0);
        if ($m > 0) {
            $rows = DB::table('inbound_calls')->where('created_at', '<', Carbon::now()->subMonths($m))
                ->where(fn ($w) => $w->whereNotNull('recording_path')->orWhereNotNull('transcript'))->get(['id', 'recording_path']);
            if ($act) {
                foreach ($rows as $r) {
                    if ($r->recording_path && Storage::disk('local')->exists($r->recording_path)) Storage::disk('local')->delete($r->recording_path);
                    DB::table('inbound_calls')->where('id', $r->id)->update(['recording_path' => null, 'recording_sid' => null, 'transcript' => null, 'updated_at' => now()]);
                }
            }
            if ($rows->count()) $this->line("Platform: {$rows->count()} sales-line recordings > {$m}mo{$tag}");
            $n += $rows->count();
        }
        if ($act && $n) Log::info('retention:purge removed platform leftovers', ['rows' => $n]);

        return $n;
    }
}
