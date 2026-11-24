<?php

namespace App\Console\Commands;

use App\Support\AgencyTime;
use App\Support\Live;
use App\Support\ReportCardQuarter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * QUARTER-END REPORT CARD REMINDER (2026-11-04). Report cards are done each quarter (Anthony). In the last
 * --days of a quarter (agency time), each educator gets ONE bell + push for that quarter listing the children in
 * their rooms who have no card for it yet (a draft counts as started). Logged in report_card_reminders, so a
 * daily run says it once. Only agencies whose plan includes report cards (ai_tools). --dry prints, writes nothing.
 */
class ReportCardRemindersCommand extends Command
{
    protected $signature = 'kiddietrac:report-card-reminders {--dry : list who would be reminded, send nothing}
        {--agency= : one agency only} {--days=14 : remind in the last N days of the quarter}';

    protected $description = 'Remind educators near quarter end about children with no report card for the quarter';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $days = max(1, (int) $this->option('days'));
        $agencies = DB::table('agencies')->whereNull('deleted_at')
            ->when($this->option('agency'), fn ($q) => $q->where('id', (int) $this->option('agency')))->pluck('id');
        $sent = 0;
        foreach ($agencies as $agencyId) {
            $agencyId = (int) $agencyId;
            if (! \App\Http\Middleware\EnforcePlanFeatures::allows($agencyId, 'ai_tools')) continue;
            $centres = Live::centreIds($agencyId);
            if (! $centres) continue;
            $quarter = ReportCardQuarter::current($agencyId);
            $today = AgencyTime::todayDate($agencyId);
            $left = (int) $today->diffInDays(\Illuminate\Support\Carbon::parse($quarter['end'], $today->getTimezone()), false);
            if ($left < 0 || $left >= $days) continue;

            $educators = DB::table('educator_rooms as er')
                ->join('rooms as r', 'r.id', '=', 'er.room_id')
                ->join('role_assignments as ra', 'ra.user_id', '=', 'er.user_id')
                ->whereIn('r.centre_id', $centres)->where('r.active', 1)
                ->where('ra.role', 'educator')->where('ra.active', 1)->where('ra.agency_id', $agencyId)
                ->select('er.user_id', 'er.room_id')->distinct()->get()->groupBy('user_id');

            foreach ($educators as $uid => $rooms) {
                $uid = (int) $uid;
                if (DB::table('report_card_reminders')->where(['user_id' => $uid, 'agency_id' => $agencyId, 'quarter_key' => $quarter['key']])->exists()) continue;
                $q = DB::table('children')->whereIn('primary_room_id', $rooms->pluck('room_id')->all())
                    ->orderBy('first_name')->select('id', 'first_name', 'preferred_name');
                Live::children($q, 'children');
                $kids = $q->get();
                if ($kids->isEmpty()) continue;
                $terms = DB::table('report_cards')->whereIn('child_id', $kids->pluck('id')->all())
                    ->get(['child_id', 'term'])->groupBy('child_id');
                $missing = $kids->filter(fn ($k) => ! collect($terms[$k->id] ?? [])->contains(fn ($c) => ReportCardQuarter::matches($c->term, $quarter)))
                    ->map(fn ($k) => $k->preferred_name ?: $k->first_name)->values();
                if ($missing->isEmpty()) continue;

                $names = $missing->take(5)->implode(', ') . ($missing->count() > 5 ? ' and ' . ($missing->count() - 5) . ' more' : '');
                $endsOn = \Illuminate\Support\Carbon::parse($quarter['end'])->format('M j');
                $n = $missing->count();
                $title = $n === 1 ? '1 report card due this quarter' : "{$n} report cards due this quarter";
                $body = "{$quarter['label']} ends {$endsOn}. No card yet for: {$names}.";
                $this->line(($dry ? '[dry] ' : '') . "agency {$agencyId} user {$uid}: {$title} - {$body}");
                if ($dry) continue;
                \App\Support\Notify::write([
                    'agency_id' => $agencyId,
                    'user_id' => $uid, 'type' => 'report_card_reminder', 'title' => $title, 'body' => $body,
                    'data' => json_encode(['link' => '#report-cards']), 'created_at' => now(),
                ]);
                DB::table('report_card_reminders')->insert([
                    'user_id' => $uid, 'agency_id' => $agencyId, 'quarter_key' => $quarter['key'], 'children' => $n, 'sent_at' => now(),
                ]);
                $sent++;
            }
        }
        $this->info(($dry ? 'dry run - ' : '') . "{$sent} reminder(s) sent");
        return self::SUCCESS;
    }
}
