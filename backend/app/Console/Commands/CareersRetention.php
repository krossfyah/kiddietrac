<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * KEPT ON FILE MEANS 24 MONTHS, THEN GONE (2026-11-24). The "on file" letter tells the applicant we keep their resume
 * for CareersMail::KEEP_MONTHS months, until a stated date. This keeps that promise: an on_file application past its
 * keep_until date loses its files and its record (and its status history). Nightly.
 * An applicant who asks to be removed sooner: change the status (or delete) by hand; this is only the backstop.
 * AGENCIES TOO (2026-11-28, KiddieTrac HR hiring): an agency's applicants live in the same table (agency_id set). Their
 * keep_until is written from the agency's own setting (HR > Hiring > Settings, HrTalent keep_months) when they are kept
 * on file, so this one pass keeps every promise. It runs even when the HR add-on is switched off: a privacy promise.
 */
class CareersRetention extends Command
{
    protected $signature = 'kt:careers-retention {--dry : list what would be removed}';
    protected $description = 'Remove kept-on-file job applications whose 24 months have passed.';

    public function handle(): int
    {
        $today = now(\App\Support\BookingMail::TZ)->toDateString();
        $rows = DB::table('career_applications')->where('status', 'on_file')->whereNotNull('keep_until')
            ->where('keep_until', '<', $today)->get(['id', 'keep_until']);
        $n = 0;
        foreach ($rows as $r) {
            if ($this->option('dry')) { $this->line('would remove #' . $r->id . ' (kept until ' . $r->keep_until . ')'); continue; }
            try {
                Storage::disk('local')->deleteDirectory('careers/' . (int) $r->id);
                DB::table('career_application_events')->where('application_id', $r->id)->delete();
                DB::table('career_applications')->where('id', $r->id)->delete();
                $n++;
            } catch (\Throwable $e) {
                report($e);
            }
        }
        if (! $this->option('dry')) $this->info('Removed ' . $n . ' application(s) past their keep-on-file date.');
        return 0;
    }
}
