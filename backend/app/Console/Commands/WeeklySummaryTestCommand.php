<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\AnthropicService;
use App\Services\EmailTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Weekly parent summary — a per-child wrap-up of the week (eating, sleep, diaper
 * changes, activities) with email-safe CSS bar charts and an AI-written note,
 * branded with the agency logo and signed by the centre's team.
 *
 *   php artisan kiddietrac:weekly-summary-test you@example.com
 *   php artisan kiddietrac:weekly-summary-test you@example.com --child=42 --week=2026-07-13
 *
 * The bars are plain <div>s with inline height/background (no SVG, no external
 * images) so they render in Gmail/Outlook/Apple Mail.
 */
class WeeklySummaryTestCommand extends Command
{
    protected $signature = 'kiddietrac:weekly-summary-test
        {email : where to send the test}
        {--child= : child id (default: the Test Agency child with the most recent activity)}
        {--week= : Monday of the week, YYYY-MM-DD (default: this week)}
        {--brand= : force branding for the test — kt | agency (default: per-agency)}
        {--preview : write the HTML to a public file for visual QA instead of sending}';

    protected $description = 'Send a test weekly parent-summary email (charts + AI) to an address.';

    private array $dayLabel = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $childId = $this->option('child') ? (int) $this->option('child') : $this->pickDemoChild();
        if (!$childId) {
            $this->error('Could not find a child with activity to summarise. Pass --child=<id>.');
            return 1;
        }

        $child = DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->join('agencies as a', 'a.id', '=', 'ce.agency_id')
            ->leftJoin('rooms as r', 'r.id', '=', 'c.primary_room_id')
            ->where('c.id', $childId)
            ->first([
                'c.id', 'c.first_name', 'c.preferred_name', 'c.last_name', 'c.primary_room_id',
                'ce.id as centre_id', 'ce.name as centre_name', 'ce.settings as centre_settings',
                'a.id as agency_id', 'a.name as agency_name', 'a.timezone as agency_tz',
                'r.name as room_name',
            ]);
        if (!$child) {
            $this->error("Child #{$childId} not found.");
            return 1;
        }

        $tz = $child->agency_tz ?: 'America/Toronto';
        if ($this->option('week')) {
            $weekStart = Carbon::parse($this->option('week'), $tz)->startOfWeek(Carbon::MONDAY);
        } else {
            // Default to the child's most recent active week so the charts have data.
            $latest = DB::table('daily_events')->where('child_id', $childId)->whereNull('deleted_at')
                ->orderBy('occurred_at', 'desc')->value('occurred_at');
            $ref = $latest ? Carbon::parse($latest, 'UTC')->setTimezone($tz) : Carbon::now($tz);
            $weekStart = $ref->copy()->startOfWeek(Carbon::MONDAY);
        }
        $weekStartYmd = $weekStart->format('Y-m-d');
        $weekEnd = $weekStart->copy()->addDays(6);
        $weekLabel = $weekStart->format('M j') . ' – ' . $weekEnd->format('M j, Y');

        $openDays = $this->openDays($child->centre_settings);
        $days = $this->collectWeek((int) $child->id, $weekStart, $tz);
        $prevDays = $this->collectWeek((int) $child->id, $weekStart->copy()->subDays(7), $tz);   // week-over-week comparison
        $menu = $this->collectMenu((int) $child->centre_id, $weekStartYmd);
        $staff = $this->staff((int) $child->centre_id);
        $childName = $child->preferred_name ?: $child->first_name;

        // AI note (graceful, VARIED fallback if the key isn't set or the call fails).
        $aiText = $this->aiNote($childName, $weekLabel, $days, $openDays, $menu, (int) $weekStart->weekOfYear);

        $body = $this->renderBody($childName, $child, $weekLabel, $days, $openDays, $staff, $aiText, $menu, $prevDays);
        $force = $this->option('brand');
        $force = in_array($force, ['kt', 'agency'], true) ? $force : null;
        $html = EmailTemplate::wrap((int) $child->agency_id, $body, [
            'eyebrow'  => 'WEEKLY SUMMARY',
            'title'    => $childName . "'s week",
            'subtitle' => $weekLabel . ' · ' . $child->centre_name,
            'preheader' => "How {$childName}'s week went — eating, sleep, activities and this week's menu.",
            'force_brand' => $force,
        ]);

        $subject = $childName . "'s week — " . $weekLabel;

        // --preview: write the rendered HTML to a public file for visual QA (no send).
        if ($this->option('preview')) {
            $fn = 'dl/_wkpreview-' . ($force ?: 'default') . '.html';
            $path = base_path('../parent-portal/' . $fn);
            @file_put_contents($path, $html);
            $this->info('Preview: https://app.kiddietrac.com/' . $fn);
            return 0;
        }

        try {
            $mailer = AgencyMailer::forAgency((int) $child->agency_id);
            $fromA = $mailer->fromAddress();
            $fromN = $mailer->fromName();
            $mailer->mailer()->html($html, function ($m) use ($email, $subject, $fromA, $fromN) {
                $m->to($email)->from($fromA, $fromN)->subject($subject);
                // Explicit test send — exempt from the live-agency kill-switch.
                try { $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1'); } catch (\Throwable $e) {}
            });
            $this->info("✓ Sent \"{$subject}\" to {$email} (child #{$child->id}, {$child->centre_name}).");
            return 0;
        } catch (\Throwable $e) {
            $this->error('Send failed: ' . $e->getMessage());
            return 1;
        }
    }

    private function pickDemoChild(): int
    {
        return (int) (DB::table('daily_events as de')
            ->join('children as c', 'c.id', '=', 'de.child_id')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->join('agencies as a', 'a.id', '=', 'ce.agency_id')
            ->where('a.name', 'like', '%Test%')
            ->whereNull('c.deleted_at')
            ->where('de.occurred_at', '>=', now()->subDays(21))
            ->groupBy('de.child_id')
            ->orderByRaw('COUNT(*) desc')
            ->limit(1)
            ->value('de.child_id') ?? 0);
    }

    private function openDays(?string $settings): array
    {
        $arr = $settings ? json_decode($settings, true) : null;
        if (is_array($arr) && !empty($arr['open_days']) && is_array($arr['open_days'])) {
            $d = array_values(array_unique(array_filter(array_map('intval', $arr['open_days']), fn ($x) => $x >= 1 && $x <= 7)));
            sort($d);
            if ($d) return $d;
        }
        return [1, 2, 3, 4, 5];
    }

    /** Per-day aggregation for the week, keyed by ISO day-of-week (1=Mon..7=Sun). */
    private function collectWeek(int $childId, Carbon $weekStart, string $tz): array
    {
        $startUtc = $weekStart->copy()->utc();
        $endUtc = $weekStart->copy()->addDays(7)->utc();

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $weekStart->copy()->addDays($i);
            $days[$i + 1] = [
                'dow' => $i + 1, 'label' => $this->dayLabel[$i + 1], 'date' => $d->format('M j'),
                'meals' => 0, 'snacks' => 0, 'diapers' => 0, 'activities' => 0, 'sleep_min' => 0, 'moods' => [],
            ];
        }

        $rows = DB::table('daily_events')
            ->where('child_id', $childId)
            ->whereNull('deleted_at')
            ->whereBetween('occurred_at', [$startUtc, $endUtc])
            ->orderBy('occurred_at')
            ->get(['event_type', 'occurred_at', 'payload', 'notes']);

        $openNap = [];
        foreach ($rows as $r) {
            $at = Carbon::parse($r->occurred_at, 'UTC')->setTimezone($tz);
            $dow = (int) $at->dayOfWeekIso;
            if (!isset($days[$dow])) continue;
            $p = json_decode((string) $r->payload, true) ?: [];
            switch ($r->event_type) {
                case 'meal': $days[$dow]['meals']++; break;
                case 'snack': $days[$dow]['snacks']++; break;
                case 'diaper': case 'bathroom': $days[$dow]['diapers']++; break;
                case 'activity': $days[$dow]['activities']++; break;
                case 'mood':
                    $mood = $p['mood'] ?? $p['value'] ?? ($r->notes ?: null);
                    if ($mood) $days[$dow]['moods'][] = (string) $mood;
                    break;
                case 'nap_start': $openNap[$dow] = $at; break;
                case 'nap_end':
                    if (!empty($openNap[$dow])) { $days[$dow]['sleep_min'] += $openNap[$dow]->diffInMinutes($at); $openNap[$dow] = null; }
                    break;
                case 'nap':
                    $mins = (int) ($p['duration_min'] ?? $p['minutes'] ?? $p['duration'] ?? 0);
                    $days[$dow]['sleep_min'] += $mins > 0 ? $mins : 60;
                    break;
            }
        }
        return $days;
    }

    private function staff(int $centreId): array
    {
        $rows = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.centre_id', $centreId)
            ->whereIn('ra.role', ['centre_director', 'educator'])
            ->where('ra.active', true)
            ->whereNull('u.deleted_at')
            ->orderByRaw("FIELD(ra.role,'centre_director','educator')")
            ->limit(8)
            ->get([DB::raw("NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),'') as name"), 'ra.role']);
        $out = [];
        foreach ($rows as $r) {
            if (!$r->name) continue;
            $label = $r->role === 'centre_director' ? 'Director' : 'Educator';
            $out[] = $r->name . ' (' . $label . ')';
        }
        return array_values(array_unique($out));
    }

    /** Published menu for the week: [dow => [meal_type => name]]. */
    private function collectMenu(int $centreId, string $weekStartYmd): array
    {
        $week = DB::table('menu_weeks')->where('centre_id', $centreId)
            ->where('week_start', $weekStartYmd)->where('status', 'published')->first();
        if (!$week) return [];
        $items = DB::table('menu_items')->where('menu_week_id', $week->id)
            ->orderBy('day_of_week')->orderBy('meal_type')->get(['day_of_week', 'meal_type', 'name']);
        $byDay = [];
        foreach ($items as $it) { $byDay[(int) $it->day_of_week][$it->meal_type] = $it->name; }
        return $byDay;
    }

    private function aiNote(string $childName, string $weekLabel, array $days, array $openDays, array $menu, int $weekOfYear): string
    {
        $facts = [];
        foreach ($days as $dow => $d) {
            if (!in_array($dow, $openDays, true)) continue;
            $facts[$d['label']] = [
                'meals' => $d['meals'], 'snacks' => $d['snacks'], 'diaper_changes' => $d['diapers'],
                'activities' => $d['activities'], 'sleep_hours' => round($d['sleep_min'] / 60, 1),
                'moods' => array_values(array_slice($d['moods'], 0, 4)),
                'menu' => isset($menu[$dow]) ? $menu[$dow] : null,
            ];
        }
        try {
            $ai = app(AnthropicService::class);
            if ($ai->isConfigured()) {
                return $ai->generateWeeklySummary([
                    'child_name' => $childName, 'week_label' => $weekLabel, 'facts' => $facts,
                    'week_of_year' => $weekOfYear,
                ]);
            }
        } catch (\Throwable $e) {
            $this->warn('AI note skipped: ' . $e->getMessage());
        }

        // Richer, VARIED fallback so week-after-week emails don't read identically.
        $totalMeals = array_sum(array_column($facts, 'meals'));
        $sleepVals = array_column($facts, 'sleep_hours');
        $avgSleep = $sleepVals ? round(array_sum($sleepVals) / count($sleepVals), 1) : 0;
        $totalAct = array_sum(array_column($facts, 'activities'));
        // Most common lunch across the week (a concrete, warm detail).
        $lunches = [];
        foreach ($menu as $meals) { if (!empty($meals['lunch'])) $lunches[] = $meals['lunch']; }
        $lunchLine = $lunches ? (' Highlights from the kitchen included ' . $this->humanList(array_slice(array_values(array_unique($lunches)), 0, 3)) . '.') : '';

        $openers = [
            "What a lovely week {$childName} had with us.",
            "{$childName} settled in beautifully this week.",
            "It's been a full and happy week for {$childName}.",
            "Here's a little window into {$childName}'s week with us.",
            "{$childName} kept us smiling all week.",
        ];
        $closers = [
            'Thank you for trusting us with your little one — see you next week.',
            "We're so glad to be part of {$childName}'s days. Have a wonderful weekend.",
            "As always, reach out any time — we love talking about {$childName}'s day.",
            "It's a privilege to watch {$childName} grow. Until next week!",
            'Wishing your family a restful weekend from all of us.',
        ];
        $sleepPhrase = $avgSleep >= 1.5 ? "rested well, averaging about {$avgSleep} hours of daytime sleep"
            : ($avgSleep > 0 ? "had shorter rests, averaging about {$avgSleep} hours a day" : 'was busy and on the go');
        $open = $openers[$weekOfYear % count($openers)];
        $close = $closers[$weekOfYear % count($closers)];

        return $open
            . " Over the week {$childName} enjoyed {$totalMeals} recorded meals and {$sleepPhrase}, and took part in {$totalAct} logged activities and experiences." . $lunchLine
            . " You'll find the day-by-day picture below, including how eating and sleep looked each day. Rest assured your child was cared for, kept safe, and encouraged to explore and play throughout the week — and we noted anything worth sharing."
            . ' ' . $close;
    }

    /** "a, b and c" */
    private function humanList(array $items): string
    {
        $items = array_values(array_filter($items));
        $n = count($items);
        if ($n === 0) return '';
        if ($n === 1) return $items[0];
        return implode(', ', array_slice($items, 0, $n - 1)) . ' and ' . $items[$n - 1];
    }

    /** Email-safe GROUPED bar chart: this-week (colour) vs last-week (grey) per day.
     *  Title and the sub-note sit on their own lines so they never crowd. */
    private function barChart(array $days, array $prevDays, array $openDays, string $key, string $title, string $emoji, string $light, string $dark, callable $fmt, string $note = ''): string
    {
        $barsH = 104;
        $vals = [];
        foreach ($days as $dow => $d) { if (in_array($dow, $openDays, true)) $vals[] = $d[$key]; }
        foreach ($prevDays as $dow => $d) { if (in_array($dow, $openDays, true)) $vals[] = $d[$key]; }
        $max = max(1, ...($vals ?: [0]));
        $groups = '';
        $labels = '';
        foreach ($days as $dow => $d) {
            if (!in_array($dow, $openDays, true)) continue;
            $cur = $d[$key];
            $prev = isset($prevDays[$dow]) ? $prevDays[$dow][$key] : 0;
            $hc = (int) round(($cur / $max) * $barsH); if ($cur > 0 && $hc < 6) $hc = 6;
            $hp = (int) round(($prev / $max) * $barsH); if ($prev > 0 && $hp < 6) $hp = 6;
            $groups .= '<td valign="bottom" align="center" style="vertical-align:bottom;padding:0 4px;">'
                . '<div style="font-size:10.5px;color:' . $dark . ';font-weight:800;margin-bottom:5px;white-space:nowrap;">' . htmlspecialchars((string) $fmt($cur)) . '</div>'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;"><tr>'
                . '<td valign="bottom" style="vertical-align:bottom;padding:0 1px;"><div style="width:15px;height:' . max(3, $hc) . 'px;background:' . $dark . ';background-image:linear-gradient(180deg,' . $light . ' 0%,' . $dark . ' 100%);border-radius:5px 5px 0 0;"></div></td>'
                . '<td valign="bottom" style="vertical-align:bottom;padding:0 1px;"><div style="width:15px;height:' . max(3, $hp) . 'px;background:#E2E8F0;border-radius:5px 5px 0 0;"></div></td>'
                . '</tr></table>'
                . '</td>';
            $labels .= '<td align="center" style="font-size:10.5px;color:#64748B;font-weight:600;padding-top:7px;">' . htmlspecialchars($d['label']) . '</td>';
        }
        $legend = '<span style="display:inline-block;width:9px;height:9px;background:' . $dark . ';border-radius:2px;"></span> <span style="color:#475569;">This week</span>'
            . ' &nbsp; <span style="display:inline-block;width:9px;height:9px;background:#E2E8F0;border-radius:2px;"></span> <span style="color:#94A3B8;">Last week</span>';
        return '<div style="margin:14px 0;padding:16px 14px 12px;border:1px solid #EAECEF;border-radius:14px;background:#ffffff;box-shadow:0 1px 2px rgba(15,23,42,.04);">'
            . '<div style="font-size:14px;font-weight:800;color:#0D1B2A;">' . $emoji . ' ' . htmlspecialchars($title) . '</div>'
            . ($note ? '<div style="font-size:11.5px;color:#94A3B8;font-weight:600;margin-top:4px;">' . htmlspecialchars($note) . '</div>' : '')
            . '<div style="font-size:10.5px;margin-top:8px;">' . $legend . '</div>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;height:' . ($barsH + 24) . 'px;border-bottom:2px solid #EEF1F4;margin-top:10px;"><tr>' . $groups . '</tr></table>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;"><tr>' . $labels . '</tr></table>'
            . '</div>';
    }

    private function menuSection(array $days, array $openDays, array $menu): string
    {
        if (!$menu) return '';
        $mealMeta = ['breakfast' => '🍳', 'morning_snack' => '🍎', 'lunch' => '🍽️', 'afternoon_snack' => '🧃', 'dinner' => '🍲'];
        $rows = '';
        foreach ($days as $dow => $d) {
            if (!in_array($dow, $openDays, true)) continue;
            $m = $menu[$dow] ?? [];
            if (!$m) continue;
            $bits = [];
            foreach (['breakfast', 'morning_snack', 'lunch', 'afternoon_snack', 'dinner'] as $mt) {
                if (!empty($m[$mt])) $bits[] = ($mealMeta[$mt] ?? '•') . ' ' . htmlspecialchars($m[$mt]);
            }
            if (!$bits) continue;
            $rows .= '<div style="display:flex;gap:10px;padding:9px 0;border-top:1px solid #F3F4F6;">'
                . '<div style="font-size:12px;font-weight:800;color:#0D1B2A;width:38px;flex-shrink:0;">' . htmlspecialchars($d['label']) . '</div>'
                . '<div style="flex:1;min-width:0;font-size:12.5px;color:#334155;line-height:1.6;">' . implode('&nbsp;&nbsp;·&nbsp;&nbsp;', $bits) . '</div>'
                . '</div>';
        }
        if (!$rows) return '';
        return '<div style="margin:18px 0;padding:16px 16px 8px;border:1px solid #FDE8C8;border-radius:14px;background:#FFFBF3;">'
            . '<div style="font-size:13.5px;font-weight:800;color:#9A5B00;margin-bottom:2px;">🍴 On the menu this week</div>'
            . '<div style="font-size:11.5px;color:#B98330;margin-bottom:4px;">The meals served to the children this week.</div>'
            . $rows . '</div>';
    }

    private function renderBody(string $childName, object $child, string $weekLabel, array $days, array $openDays, array $staff, string $aiText, array $menu = [], array $prevDays = []): string
    {
        // Totals for a quick stat strip + week-over-week comparison.
        $sum = fn ($k) => array_sum(array_map(fn ($d) => $d[$k], $days));
        $sumPrev = fn ($k) => $prevDays ? array_sum(array_map(fn ($d) => $d[$k], $prevDays)) : null;
        $openCount = count($openDays) ?: 5;
        $avgSleepH = round($sum('sleep_min') / 60 / max(1, $openCount), 1);
        $avgSleepPrevH = $prevDays ? round($sumPrev('sleep_min') / 60 / max(1, $openCount), 1) : null;
        // A small "▲ +N vs last week" delta chip (green up / amber down / grey flat).
        $delta = function ($cur, $prev, $suffix = '') {
            if ($prev === null) return '';
            $d = round($cur - $prev, 1);
            if (abs($d) < 0.05) return '<div style="font-size:9.5px;color:#94A3B8;margin-top:2px;">– same as last wk</div>';
            $up = $d > 0;
            $col = $up ? '#059862' : '#B45309';
            $arrow = $up ? '▲' : '▼';
            $mag = ($d == (int) $d) ? (string) abs((int) $d) : (string) abs($d);
            return '<div style="font-size:9.5px;color:' . $col . ';font-weight:700;margin-top:2px;">' . $arrow . ' ' . $mag . $suffix . ' vs last wk</div>';
        };
        $stat = function ($emoji, $val, $lbl, $deltaHtml = '') {
            return '<td align="center" style="padding:10px 6px;background:#F8FAFC;border-radius:12px;vertical-align:top;">'
                . '<div style="font-size:20px;">' . $emoji . '</div>'
                . '<div style="font-size:18px;font-weight:800;color:#0D1B2A;margin-top:2px;">' . htmlspecialchars((string) $val) . '</div>'
                . '<div style="font-size:10.5px;color:#64748B;margin-top:1px;">' . htmlspecialchars($lbl) . '</div>'
                . $deltaHtml . '</td>';
        };

        $aiHtml = '';
        foreach (preg_split('/\n{2,}/', trim($aiText)) as $para) {
            $para = trim($para); if ($para === '') continue;
            $aiHtml .= '<p style="margin:0 0 10px;font-size:14.5px;line-height:1.6;color:#243244;">' . nl2br(htmlspecialchars($para)) . '</p>';
        }

        $tot = function ($k) use ($sum, $sumPrev) { $p = $sumPrev($k); return $p === null ? '' : 'This week ' . $sum($k) . ' · last week ' . $p; };
        $charts = $this->barChart($days, $prevDays, $openDays, 'meals', 'Meals eaten', '🍽️', '#5FD0DE', '#0E8A9C', fn ($v) => (string) $v, $tot('meals'))
            . $this->barChart($days, $prevDays, $openDays, 'sleep_min', 'Daytime sleep', '😴', '#B79CF0', '#6D28D9', fn ($v) => $v >= 60 ? round($v / 60, 1) . 'h' : ($v > 0 ? $v . 'm' : '0'), 'This week avg ' . $avgSleepH . 'h/day' . ($avgSleepPrevH !== null ? ' · last week ' . $avgSleepPrevH . 'h' : ''))
            . $this->barChart($days, $prevDays, $openDays, 'diapers', 'Diaper changes', '🧷', '#FBCF73', '#D9880B', fn ($v) => (string) $v, $tot('diapers'))
            . $this->barChart($days, $prevDays, $openDays, 'activities', 'Activities & play', '🎨', '#6FE0A6', '#059862', fn ($v) => (string) $v, $tot('activities'));

        $staffLine = $staff
            ? '<div style="font-size:13px;color:#475569;"><strong style="color:#0D1B2A;">From your team:</strong> ' . htmlspecialchars(implode(', ', $staff)) . '</div>'
            : '';

        $room = $child->room_name ? ' · ' . htmlspecialchars($child->room_name) : '';

        return ''
            . '<div style="font-size:13px;color:#64748B;margin:0 0 4px;">' . htmlspecialchars($weekLabel) . '</div>'
            . '<div style="font-size:20px;font-weight:800;color:#0D1B2A;margin:0 0 2px;">' . htmlspecialchars($childName) . "'s week</div>"
            . '<div style="font-size:13px;color:#64748B;margin:0 0 16px;">' . htmlspecialchars($child->centre_name) . $room . '</div>'
            // AI note card
            . '<div style="background:linear-gradient(135deg,#EAF3FB,#F3F0FF);border:1px solid rgba(31,96,128,.12);border-radius:14px;padding:16px 18px;margin-bottom:18px;">'
            . '<div style="font-size:11px;font-weight:800;letter-spacing:.8px;color:#0FA3B1;margin-bottom:8px;">✨ THIS WEEK IN WORDS</div>'
            . $aiHtml . '</div>'
            // Quick stats
            . '<table role="presentation" cellpadding="0" cellspacing="6" border="0" style="width:100%;margin-bottom:6px;"><tr>'
            . $stat('🍽️', $sum('meals'), 'meals', $delta($sum('meals'), $sumPrev('meals')))
            . $stat('😴', $avgSleepH . 'h', 'avg sleep/day', $delta($avgSleepH, $avgSleepPrevH, 'h'))
            . $stat('🧷', $sum('diapers'), 'changes', $delta($sum('diapers'), $sumPrev('diapers')))
            . $stat('🎨', $sum('activities'), 'activities', $delta($sum('activities'), $sumPrev('activities')))
            . '</tr></table>'
            // Charts
            . $charts
            // This week's menu (foods served)
            . $this->menuSection($days, $openDays, $menu)
            // Reassurance + team
            . '<div style="margin-top:18px;padding:14px 16px;background:#F0FBFD;border:1px solid #CDEFF4;border-radius:12px;">'
            . '<div style="font-size:12.5px;color:#0E6070;line-height:1.6;">💙 Your child was cared for, kept safe, and encouraged to learn and play all week. If anything needed your attention, we would have reached out directly — otherwise, it was simply a good week.</div>'
            . $staffLine
            . '</div>';
    }
}
