<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Support\Audience;
use App\Support\Closures;
use App\Support\Live;
use App\Support\Notify;
use App\Support\RecordReminders;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Evening "daily records" reminder to educators, copied to their directors and the
 * agency admins (App\Support\RecordReminders). Runs every 15 minutes; an agency is
 * handled once its own send time has passed, and each educator once per day (the
 * record_reminders unique key).
 *
 *   php artisan records:remind --dry-run            what would go, sends nothing
 *   php artisan records:remind --force              ignore the send time (backfill today)
 *   php artisan records:remind --date=2026-10-15 --agency=2 --user=143
 */
final class RecordRemindCommand extends Command
{
    protected $signature = 'records:remind
        {--date= : YYYY-MM-DD in the agency timezone (default: today)}
        {--agency= : Only this agency}
        {--user= : Only this educator}
        {--force : Ignore the configured send time}
        {--dry-run : Print what would be sent; send and record nothing}';

    protected $description = 'Remind educators (copying directors/admins) about missing observations, logs, photos, meals, lesson plans and menus';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $agencies = DB::table('agencies')->whereNull('deleted_at')
            ->when($this->option('agency'), fn ($q) => $q->where('id', (int) $this->option('agency')))
            ->get(['id', 'name', 'timezone']);

        $seen = [];   // one email per person per run, even with roles in two agencies
        foreach ($agencies as $a) {
            $cfg = RecordReminders::settings((int) $a->id);
            if (! $cfg['enabled']) continue;
            if (\App\Support\Suppression::isAgency((int) $a->id)) continue;
            $tz = $a->timezone ?: 'America/Toronto';
            $date = $this->option('date') ? Carbon::parse((string) $this->option('date'), $tz) : Carbon::now($tz);
            if (! $this->option('force') && ! $this->option('date')) {
                if (Carbon::now($tz)->format('H:i') < $cfg['send_at']) continue;
            }

            $centreIds = Live::centreIds((int) $a->id);
            if (! $centreIds) continue;
            $eds = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->whereIn('ra.centre_id', $centreIds)->where('ra.role', 'educator')->where('ra.active', true)
                ->whereNull('u.deleted_at')->whereNotNull('u.email')
                ->when($this->option('user'), fn ($q) => $q->where('u.id', (int) $this->option('user')))
                ->orderBy('u.id')->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'ra.centre_id'])->unique('id');
            $eds = Audience::excludeOff(DB::table('users as u')->whereIn('u.id', $eds->pluck('id')->all() ?: [0]))->pluck('u.id')
                ->flip()->pipe(fn ($live) => $eds->filter(fn ($e) => isset($live[$e->id])));

            $weekCache = [];
            foreach ($eds as $ed) {
                $cid = (int) $ed->centre_id;
                if (isset($seen[(int) $ed->id])) continue;
                $seen[(int) $ed->id] = true;
                if (DB::table('record_reminders')->where('user_id', $ed->id)->where('for_date', $date->toDateString())->exists()) continue;
                if (! Closures::isOperatingDay($cid, $date->toDateString())) continue;
                $ev = RecordReminders::evaluate((int) $ed->id, $cid, $tz, $date, $cfg, $weekCache);
                if ($ev === null) continue;                      // did not work today
                $place = (string) DB::table('centres')->where('id', $cid)->value('name');

                if (! $ev['missing']) {
                    $this->line("  ok   {$ed->first_name} {$ed->last_name} ({$place})");
                    if (! $dry) $this->log($a->id, $cid, $ed->id, $date, [], 0, false, false);
                    continue;
                }
                $hist = RecordReminders::history((int) $ed->id, $cid, $tz, $date, $cfg, $weekCache);
                [$subject, $html, $escalated] = RecordReminders::email((int) $a->id, $ed, $place, $date, $ev, $hist, $cfg, (string) $a->name);

                $cc = [];
                if ($cfg['cc_managers']) {
                    $cc = Audience::excludeService(Audience::excludeOff(DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                        ->where('ra.active', true)->whereNull('u.deleted_at')
                        ->where(fn ($w) => $w->where(fn ($x) => $x->where('ra.centre_id', $cid)->where('ra.role', 'centre_director'))
                            ->orWhere(fn ($x) => $x->where('ra.agency_id', $a->id)->where('ra.role', 'agency_admin')))))
                        ->where('u.id', '!=', $ed->id)->pluck('u.email')
                        ->map(fn ($m) => mb_strtolower(trim((string) $m)))->filter(fn ($m) => filter_var($m, FILTER_VALIDATE_EMAIL))
                        ->reject(fn ($m) => $m === mb_strtolower(trim((string) $ed->email)))->unique()->values()->all();
                }
                $this->line(sprintf('  %s %s %s (%s): %s | %d day(s) in %d | cc %d', $escalated ? 'ESC ' : 'send', $ed->first_name, $ed->last_name, $place,
                    implode(', ', array_keys($ev['missing'])), $hist['days'], $cfg['window_days'], count($cc)));
                if ($dry) continue;

                $emailed = false;
                try {
                    AgencyMailer::forAgency((int) $a->id)->html($html, function ($m) use ($ed, $cc, $subject) {
                        $m->to((string) $ed->email)->subject($subject);
                        if ($cc) $m->cc($cc);
                    });
                    $emailed = true;
                } catch (\Throwable $e) {
                    Log::warning('Record reminder failed', ['user' => $ed->id, 'error' => $e->getMessage()]);
                }
                Notify::write(['user_id' => (int) $ed->id, 'type' => 'records_reminder',
                    'title' => ($escalated ? 'Escalated: ' : '') . 'Daily records for ' . $date->format('D j M'),
                    'body' => 'Not completed: ' . implode(', ', array_map(fn ($m) => $m['label'], $ev['missing'])) . '. ' . $hist['days'] . ' day(s) in the last ' . $cfg['window_days'] . '.',
                    'data' => json_encode(['link' => '#care-log'])]);
                $this->log($a->id, $cid, $ed->id, $date, array_keys($ev['missing']), $hist['days'], $escalated, $emailed);
            }
        }

        return self::SUCCESS;
    }

    private function log($agencyId, int $centreId, $userId, Carbon $date, array $missing, int $days, bool $escalated, bool $emailed): void
    {
        try {
            DB::table('record_reminders')->insertOrIgnore([
                'agency_id' => (int) $agencyId, 'centre_id' => $centreId, 'user_id' => (int) $userId, 'for_date' => $date->toDateString(),
                'missing' => json_encode($missing), 'days_in_window' => $days, 'escalated' => $escalated, 'emailed' => $emailed, 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Record reminder log failed', ['user' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
