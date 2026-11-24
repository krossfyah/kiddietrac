<?php

namespace App\Console\Commands;

use App\Support\CareersMail;
use Illuminate\Console\Command;

/**
 * The four applicant letters, sent to a reviewer with [TEST] in the subject (2026-11-24). A made-up applicant; nothing
 * is written to the database, no meeting is made, no real applicant is emailed. `to` is required: never a default.
 *   php8.3 artisan kt:careers-test-emails someone@example.com [--only=received,interview,on_file,decline] [--html=dir]
 */
class CareersTestEmails extends Command
{
    protected $signature = 'kt:careers-test-emails {to} {--only=} {--html= : also write each letter to this folder}';
    protected $description = 'Send sample job-applicant letters to a reviewer.';

    public function handle(): int
    {
        $to = (string) $this->argument('to');
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) { $this->error('Give a valid address.'); return 1; }
        $only = array_filter(explode(',', (string) $this->option('only')));
        $a = (object) ['id' => 0, 'first_name' => 'Jordan', 'last_name' => 'Sample', 'email' => $to, 'role' => 'Customer Success Specialist'];

        $tz = \App\Support\BookingMail::TZ;
        $start = now($tz)->addDays(3)->setTime(14, 0);
        $m = (object) ['id' => 0, 'scope' => 'platform', 'agency_id' => null, 'timezone' => $tz, 'all_day' => 0, 'sequence' => 0,
            'starts_at' => $start->copy()->utc()->format('Y-m-d H:i:s'), 'ends_at' => $start->copy()->addMinutes(30)->utc()->format('Y-m-d H:i:s'),
            'title' => '[TEST] Interview: Customer Success Specialist with KiddieTrac', 'description' => 'A sample invitation for review.',
            'video' => 'teams', 'join_url' => 'https://teams.microsoft.com/', 'location' => null];
        $careers = CareersMail::CAREERS_URL;
        $note = 'We loved how you described helping families settle into a new routine. That is exactly the care we try to bring to every centre we work with.';
        $sets = [
            'received' => [[], null],
            'interview' => [['note' => $note, 'meeting' => ['when' => \App\Support\Meetings::whenText($m), 'how' => 'Microsoft Teams video call (link below)',
                'where' => '', 'minutes' => 30, 'join_url' => $m->join_url, 'video' => 'teams', 'rsvp' => [$careers, $careers, $careers]]],
                \App\Support\Meetings::ics($m, 'REQUEST', [(object) ['name' => 'Jordan Sample', 'email' => $to, 'optional' => 0, 'response' => 'pending']], null)],
            'on_file' => [['keep_until' => now($tz)->addMonths(CareersMail::KEEP_MONTHS)->toDateString()], null],
            'decline' => [[], null],
        ];
        foreach ($sets as $kind => [$o, $ics]) {
            if ($only && ! in_array($kind, $only, true)) continue;
            if ($dir = $this->option('html')) {
                @mkdir($dir, 0775, true);
                file_put_contents(rtrim($dir, '/') . '/' . $kind . '.html', CareersMail::build($kind, $a, $o)['html']);
            }
            $ok = CareersMail::send($kind, $a, $o, $to, $ics);
            $this->line(($ok ? 'sent  ' : 'FAILED ') . $kind . '  ' . CareersMail::build($kind, $a, $o)['subject']);
        }
        return 0;
    }
}
