<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Audit;
use App\Support\CareersMail;
use App\Support\Features;
use App\Support\HrLeave;
use App\Support\HrTalent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * DECLINED APPLICANTS ARE DELETED AFTER N DAYS (2026-11-28, Anthony). Per agency, HR > Hiring > Settings
 * "Delete declined applicants after" (HrTalent declined_days, default 90, null = Never). Nightly, after
 * kt:careers-retention, and the same removal: the resume and cover letter (careers/<id>), the status history and
 * comments, the letters we sent them (email_logs rows to their address from this agency carrying one of the
 * hiring letter subjects), the interview's guest row (the meeting stays on staff calendars, renamed) and the
 * application. Each purge is one audit row (hr.declined_applicants_purged: ids and count, never names).
 *
 * AGENCY ROWS ONLY: platform careers (agency_id NULL) keep kt:careers-retention's rules, untouched.
 * Test agencies are NOT skipped: this deletes, it sends nothing. Agencies without the HR add-on are skipped
 * (Features::skips): their setting is not in force. --dry-run lists, deletes nothing.
 */
final class HrDeclinedPurgeCommand extends Command
{
    protected $signature = 'hr:declined-purge {--agency= : one agency id only} {--dry-run : list what would be deleted, delete nothing}';

    protected $description = 'KiddieTrac HR: delete declined job applicants after the agency\'s chosen number of days';

    public function handle(): int
    {
        $only = $this->option('agency') ? (int) $this->option('agency') : null;
        $dry = (bool) $this->option('dry-run');
        $ids = DB::table('career_applications')->whereNotNull('agency_id')->where('status', 'declined')
            ->when($only, fn ($q) => $q->where('agency_id', $only))->distinct()->pluck('agency_id')->map(fn ($v) => (int) $v)->all();
        foreach ($ids as $aid) {
            if (Features::skips($aid, HrLeave::FEATURE, 'HR declined applicants purge')) { $this->line("agency $aid: HR add-on off, skipped"); continue; }
            $days = HrTalent::settings($aid)['declined_days'];
            if ($days === null) { $this->line("agency $aid: set to Never"); continue; }
            $cut = now()->subDays($days);
            $rows = DB::table('career_applications')->where('agency_id', $aid)->where('status', 'declined')
                ->whereNotNull('declined_at')->where('declined_at', '<', $cut)->get();
            if ($dry) { $this->line("agency $aid: would delete " . $rows->count() . ' declined applicant(s) (after ' . $days . ' days)' . ($rows->count() ? ': #' . $rows->pluck('id')->implode(', #') : '')); continue; }
            $done = [];
            foreach ($rows as $a) {
                try {
                    $this->purge($aid, $a);
                    $done[] = (int) $a->id;
                } catch (\Throwable $e) {
                    report($e);
                    $this->error("agency $aid: #{$a->id} " . $e->getMessage());
                }
            }
            if ($done) {
                try {
                    Audit::write(['user_id' => null, 'agency_id' => $aid, 'action' => 'hr.declined_applicants_purged', 'entity_type' => 'agency', 'entity_id' => $aid,
                        'payload' => json_encode(['count' => count($done), 'application_ids' => $done, 'after_days' => $days])]);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
            $this->line("agency $aid: deleted " . count($done) . ' declined applicant(s)');
        }

        return self::SUCCESS;
    }

    private function purge(int $aid, object $a): void
    {
        // The letters' subjects, built the way they were sent (the agency's name in place of KiddieTrac's).
        $org = \App\Http\Controllers\Api\HrHiringController::org($aid);
        $subjects = [];
        foreach (['received', 'interview', 'on_file', 'decline'] as $k) {
            try {
                $o = ['org' => $org, 'keep_until' => \App\Support\AgencyTime::today($aid), 'note' => ''];
                if ($k === 'interview') $o['meeting'] = ['when' => '', 'how' => '', 'where' => '', 'minutes' => 30, 'join_url' => null, 'video' => 'none', 'rsvp' => null];
                $subj = CareersMail::build($k, $a, $o)['subject'];
                $subjects[] = $subj;
                $subjects[] = '[TEST] ' . $subj;
            } catch (\Throwable $e) {
            }
        }
        DB::transaction(function () use ($aid, $a, $subjects) {
            if ($subjects && $a->email) {
                DB::table('email_logs')->where('agency_id', $aid)->where('to_email', $a->email)->whereIn('subject', $subjects)->delete();
            }
            if ($a->meeting_id) {
                DB::table('meeting_attendees')->where('meeting_id', $a->meeting_id)->whereNull('user_id')->where('email', $a->email)->delete();
                DB::table('meetings')->where('id', $a->meeting_id)->where('agency_id', $aid)->update(['title' => 'Interview (applicant deleted)', 'description' => null, 'updated_at' => now()]);
            }
            DB::table('career_application_events')->where('application_id', $a->id)->delete();
            DB::table('career_applications')->where('id', $a->id)->where('agency_id', $aid)->delete();
        });
        Storage::disk('local')->deleteDirectory('careers/' . (int) $a->id);
    }
}
