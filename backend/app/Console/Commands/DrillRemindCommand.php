<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use App\Support\AgencyTime;
use App\Support\DrillRules;
use App\Support\Live;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * drills:remind — OFF unless an agency switches it on (Drill log screen). Weekdays at 9:00
 * agency time, each centre's directors get one email listing the drills still owed this
 * period and any missed last period. Nothing is owed → nothing is sent. Directors only
 * (never families); a centre with no director goes to the agency admins.
 */
final class DrillRemindCommand extends Command
{
    protected $signature = 'drills:remind {--agency=} {--dry}';
    protected $description = 'Remind directors about drills still owed under their jurisdiction (agencies that switched it on)';

    public function handle(): int
    {
        $q = DB::table('agencies')->whereNull('deleted_at');
        if ($this->option('agency')) $q->where('id', (int) $this->option('agency'));
        foreach ($q->get(['id', 'settings']) as $a) {
            $s = $a->settings ? (json_decode((string) $a->settings, true) ?: []) : [];
            if (empty($s['drill_reminders']['enabled'])) continue;
            $now = Carbon::now(AgencyTime::tz((int) $a->id));
            if (! $this->option('dry') && ($now->isWeekend() || $now->hour !== 9 || $now->minute >= 15)) continue;
            $lastKey = 'drill_reminders_last';
            if (! $this->option('dry') && (($s[$lastKey] ?? null) === $now->toDateString())) continue;

            foreach (Live::centreIds((int) $a->id) as $cid) {
                $owed = array_values(array_filter(DrillRules::status((int) $a->id, (int) $cid), fn ($r) => ! $r['optional']
                    && (($r['current']['owed'] && ! $r['current']['done_at']) || $r['previous']['missed'])));
                if (! $owed) continue;
                $to = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')->where('ra.centre_id', $cid)
                    ->where('ra.role', 'centre_director')->where('ra.active', true)->whereNull('u.deleted_at')->pluck('u.email')->filter()->unique()->values()->all();
                if (! $to) {
                    $to = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')->where('ra.agency_id', $a->id)
                        ->where('ra.role', 'agency_admin')->where('ra.active', true)->whereNull('u.deleted_at')->pluck('u.email')->filter()->unique()->values()->all();
                }
                $to = array_values(array_filter($to, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && ! \App\Support\ChatAudience::isServiceEmail($e)));
                $centre = (string) DB::table('centres')->where('id', $cid)->value('name');
                if ($this->option('dry')) { $this->line("agency {$a->id} {$centre}: " . count($owed) . ' owed → ' . count($to) . ' recipient(s)'); continue; }
                if (! $to) continue;
                $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
                $li = '';
                foreach ($owed as $r) {
                    $li .= '<li style="margin:4px 0">' . $e($r['label']) . ': '
                        . ($r['previous']['missed'] ? '<b style="color:#B91C1C">missed in ' . $e($r['previous']['period']) . '</b>' . ($r['current']['owed'] && ! $r['current']['done_at'] ? ', and ' : '') : '')
                        . ($r['current']['owed'] && ! $r['current']['done_at'] ? 'not yet done for ' . $e($r['current']['period']) : '') . '</li>';
                }
                $html = EmailTemplate::wrap((int) $a->id, '<p style="margin:0 0 10px;font-size:15px;color:#334155">Drills still owed at <b>' . $e($centre) . '</b>:</p>'
                    . '<ul style="margin:0;padding-left:18px;font-size:14.5px;color:#0F172A">' . $li . '</ul>'
                    . '<p style="margin:14px 0 0;font-size:14px;color:#334155">Record a drill in KiddieTrac → Drill log once it is done.</p>'
                    . '<p style="margin:14px 0 0;font-size:12px;color:#94A3B8">Your agency switched these reminders on in the Drill log.</p>',
                    ['eyebrow' => 'SAFETY', 'title' => 'Drills to do', 'subtitle' => $centre]);
                foreach ($to as $addr) {
                    try { AgencyMailer::forAgency((int) $a->id)->html($html, fn ($m) => $m->to($addr)->subject('Drills still owed at ' . $centre)); }
                    catch (\Throwable $ex) { $this->error('mail failed: ' . $ex->getMessage()); }
                }
            }
            if (! $this->option('dry')) {
                DB::transaction(function () use ($a, $lastKey, $now) {   // re-read under a lock: only this key changes
                    $raw = DB::table('agencies')->where('id', $a->id)->lockForUpdate()->value('settings');
                    $cur = $raw ? (json_decode((string) $raw, true) ?: []) : [];
                    $cur[$lastKey] = $now->toDateString();
                    DB::table('agencies')->where('id', $a->id)->update(['settings' => json_encode($cur)]);
                });
            }
        }

        return self::SUCCESS;
    }
}
