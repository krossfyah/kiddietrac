<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\Api\ReportsController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails due report schedules (PDF and/or CSV). Runs hourly; a schedule fires
 * once per day at/after its send-time hour on the matching day.
 */
class SendScheduledReports extends Command
{
    protected $signature = 'kiddietrac:send-scheduled-reports {--force : send every active schedule now, ignoring day/time}';
    protected $description = 'Email due scheduled reports to their recipients';

    public function handle(): int
    {
        $now = now();
        $today = $now->toDateString();
        $dow = (int) $now->dayOfWeek;   // 0=Sun..6=Sat
        $dom = (int) $now->day;
        $hour = (int) $now->hour;
        $force = (bool) $this->option('force');

        $rows = DB::table('report_schedules')
            ->where('active', 1)
            ->when(! $force, fn ($q) => $q->where(function ($qq) use ($today) {
                $qq->whereNull('last_sent_on')->orWhere('last_sent_on', '<', $today);
            }))
            ->get();

        $sent = 0;
        foreach ($rows as $r) {
            if (! $force) {
                if ($r->frequency === 'weekly' && (int) ($r->day_of_week ?? 1) !== $dow) {
                    continue;
                }
                if ($r->frequency === 'monthly' && (int) ($r->day_of_month ?: 1) !== min($dom, 28)) {
                    continue;
                }
                $sendHour = (int) substr((string) $r->send_time, 0, 2);
                if ($hour < $sendHour) {
                    continue;   // not time yet today
                }
            }

            if (self::sendOne($r)) {
                DB::table('report_schedules')->where('id', $r->id)->update(['last_sent_on' => $today, 'updated_at' => now()]);
                $sent++;
            }
        }

        $this->info("Scheduled reports sent: {$sent}");

        return self::SUCCESS;
    }

    /** Date range [from, to] (Y-m-d or null) for a schedule's range_kind. */
    public static function range(string $kind): array
    {
        $today = now();
        switch ($kind) {
            case 'last_7d':    return [$today->copy()->subDays(7)->toDateString(), $today->toDateString()];
            case 'last_30d':   return [$today->copy()->subDays(30)->toDateString(), $today->toDateString()];
            case 'this_month': return [$today->copy()->startOfMonth()->toDateString(), $today->toDateString()];
            case 'last_month': return [$today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()];
            case 'all':
            default:           return [null, null];
        }
    }

    /** Build + email a single schedule. Returns true if a mail was dispatched. */
    public static function sendOne($r, bool $manual = false): bool
    {
        $to = $r->recipient_email;
        if (! $to && $r->recipient_user_id) {
            $to = DB::table('users')->where('id', $r->recipient_user_id)->value('email');
        }
        if (! $to) {
            return false;
        }

        [$from, $toDate] = self::range((string) $r->range_kind);

        $rep = app(ReportsController::class)->buildScheduledReport(
            (int) $r->agency_id,
            (string) $r->report_type,
            $r->centre_id ? (int) $r->centre_id : null,
            $from,
            $toDate,
            'Scheduled report'
        );
        if (! ($rep['ok'] ?? false)) {
            return false;
        }

        $atts = [];
        if (in_array($r->format, ['pdf', 'both'], true) && $rep['pdf'] !== null) {
            $atts[] = ['data' => $rep['pdf'], 'name' => $rep['filename_base'] . '.pdf', 'mime' => 'application/pdf'];
        }
        if (in_array($r->format, ['csv', 'both'], true) && $rep['csv'] !== null) {
            $atts[] = ['data' => $rep['csv'], 'name' => $rep['filename_base'] . '.csv', 'mime' => 'text/csv'];
        }
        if (! $atts) {
            return false;
        }

        $agencyName = DB::table('agencies')->where('id', $r->agency_id)->value('name') ?: 'KiddieTrac';
        $subject = $rep['title'] . ' — ' . $agencyName;
        $rangeTxt = $from ? ($from . ' to ' . $toDate) : 'all records';
        $body = '<p>Hello,</p>'
            . '<p>Your scheduled <strong>' . e($rep['title']) . '</strong> report for <strong>' . e($agencyName)
            . '</strong> is attached (' . e($rangeTxt) . ', ' . (int) $rep['count'] . ' row' . ($rep['count'] === 1 ? '' : 's') . ').</p>'
            . '<p style="color:#64748B;font-size:12px;">You are receiving this because a report schedule was set up in KiddieTrac.'
            . ($manual ? ' (Sent manually as a test.)' : '') . '</p>';

        try {
            Mail::html($body, function ($m) use ($to, $subject, $atts) {
                $m->to($to)->subject($subject);
                foreach ($atts as $a) {
                    $m->attachData($a['data'], $a['name'], ['mime' => $a['mime']]);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Scheduled report send failed: ' . $e->getMessage(), ['schedule_id' => $r->id ?? null]);

            return false;
        }

        return true;
    }
}
