<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Warm end-of-day summary email to EDUCATORS (2026-08-07).
 *
 * Celebrates what each educator did today, compares it with yesterday and with
 * roughly a month ago, offers a couple of gentle, encouraging suggestions, and
 * thanks them warmly. The whole tone is appreciative — educators do hard, loving
 * work and this note is meant to make them feel valued.
 *
 * "The day" is the agency's day (tz-aware). Everything is bucketed in the
 * agency's timezone so an 8pm-Toronto entry (stamped tomorrow in UTC) still counts.
 *
 * Usage:
 *   php artisan kiddietrac:educator-summary
 *   php artisan kiddietrac:educator-summary --user=139 --to=me@example.com
 *   php artisan kiddietrac:educator-summary --date=2026-08-06 --dry-run
 */
class EducatorDailySummaryCommand extends Command
{
    protected $signature = 'kiddietrac:educator-summary
        {--user= : Only this educator user id}
        {--date= : YYYY-MM-DD in the agency timezone (default: today)}
        {--to= : Send to this address instead of the educator (for testing)}
        {--dry-run : Build it, print what would happen, send nothing}';

    protected $description = 'Email each educator a warm end-of-day summary of their day, with wins and gentle suggestions';

    public function handle(): int
    {
        $onlyUser = $this->option('user') ? (int) $this->option('user') : null;
        $override = $this->option('to');
        $dry = (bool) $this->option('dry-run');

        $educators = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->join('centres as ce', 'ce.id', '=', 'ra.centre_id')
            ->join('agencies as a', 'a.id', '=', 'ce.agency_id')
            ->where('ra.role', 'educator')->where('ra.active', 1)
            ->whereNotNull('u.email')->whereNull('u.deleted_at')
            ->when($onlyUser, fn ($q) => $q->where('u.id', $onlyUser))
            ->select('u.id', 'u.first_name', 'u.email', 'u.sex', 'ce.agency_id', 'a.timezone as tz')
            ->distinct()->get();

        if ($educators->isEmpty()) {
            $this->warn('No educators matched.');
            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($educators as $ed) {
            if (! $override && \App\Support\Suppression::isAgency((int) $ed->agency_id)) {
                continue;
            }
            $tz = $ed->tz ?: 'America/Toronto';
            $date = $this->option('date') ? Carbon::parse((string) $this->option('date'), $tz) : Carbon::now($tz);

            $today = $this->statsFor((int) $ed->id, $tz, $date);
            if (! $override && $today['moments'] === 0 && $today['minutes'] === 0) {
                $this->line("· {$ed->first_name}: nothing logged, skipping");
                continue;
            }
            $yesterday = $this->statsFor((int) $ed->id, $tz, $date->copy()->subDay());
            $monthAgo  = $this->statsFor((int) $ed->id, $tz, $date->copy()->subDays(28));

            $html = $this->buildHtml($ed, $today, $yesterday, $monthAgo, $date, $tz);
            [$sPlace] = $this->placeFor((int) $ed->id, (int) $ed->agency_id);
            // Name the place: an agency admin CC'd on several of these needs to tell
            // them apart at a glance, and a provider's day is about their own home.
            // NOTE double quotes - \u{...} is not an escape inside single quotes.
            $subject = "Your day at a glance \u{2014} " . ($sPlace ? $sPlace . " \u{2014} " : '')
                . $date->format('D j M Y') . " \u{1F49B}";
            $to = $override ?: $ed->email;

            if ($dry) {
                $this->info("[dry-run] would send to {$to} — {$today['moments']} moments, "
                    . round($today['minutes'] / 60, 1) . 'h, ' . $today['children'] . ' children');
                continue;
            }
            if ($override) {
                // Immediate test send that bypasses suppression + the queue.
                try {
                    \App\Support\PlatformSettings::applyMail();
                    \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($to, $subject) {
                        $m->to($to)->subject('[Test] ' . $subject);
                        $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                    });
                    $sent++;
                    $this->info("✓ [test] {$subject} → {$to}");
                } catch (\Throwable $e) {
                    $this->error('send failed: ' . $e->getMessage());
                }
                continue;
            }
            $this->send((int) $ed->agency_id, (string) $to, (string) $ed->first_name, $subject, $html);
            $sent++;
            $this->info("✓ {$subject} → {$to}");
        }

        $this->info("Done. {$sent} email(s) queued.");
        return self::SUCCESS;
    }

    /** This educator's activity for the given agency-day. */
    private function statsFor(int $uid, string $tz, Carbon $date): array
    {
        $start = Carbon::parse($date->toDateString() . ' 00:00:00', $tz)->utc();
        $end   = Carbon::parse($date->toDateString() . ' 23:59:59', $tz)->utc();

        $events = DB::table('daily_events')->where('recorded_by_id', $uid)
            ->whereBetween('occurred_at', [$start, $end])->get(['event_type', 'child_id']);
        $byType = [];
        foreach ($events as $e) { $byType[$e->event_type] = ($byType[$e->event_type] ?? 0) + 1; }

        $children = DB::table('check_events')->where('recorded_by_id', $uid)
            ->whereBetween('occurred_at', [$start, $end])->distinct('child_id')->count('child_id');
        if (! $children) { $children = $events->pluck('child_id')->unique()->count(); }

        $obs = Schema::hasTable('observations')
            ? DB::table('observations')->where('recorded_by_id', $uid)->whereBetween('created_at', [$start, $end])->count()
            : 0;

        $mins = 0;
        foreach (DB::table('time_punches')->where('user_id', $uid)
            ->whereBetween('punched_in_at', [$start, $end])->whereNotNull('punched_out_at')
            ->get(['punched_in_at', 'punched_out_at']) as $p) {
            try { $mins += (int) Carbon::parse($p->punched_in_at)->diffInMinutes(Carbon::parse($p->punched_out_at)); } catch (\Throwable $e) {}
        }

        return [
            'moments'      => $events->count(),
            'children'     => (int) $children,
            'observations' => (int) $obs,
            'minutes'      => $mins,
            'meals'        => ($byType['meal'] ?? 0) + ($byType['snack'] ?? 0),
            'naps'         => ($byType['nap_start'] ?? 0) + ($byType['nap_end'] ?? 0) + ($byType['nap'] ?? 0),
            'activities'   => $byType['activity'] ?? 0,
            'diapers'      => $byType['diaper'] ?? 0,
            'notes'        => $byType['note'] ?? 0,
        ];
    }

    /**
     * A single headline number for the day, 0-100.
     *
     * Six stat tiles tell an educator what they did but not how the day WENT; a score
     * gives them one thing to feel, and something to beat tomorrow. Each component is
     * capped so a big day in one area cannot mask a missing one, and the weights follow
     * what the role is actually judged on: moments logged for the families, children
     * covered, observations written, and time on the floor.
     *
     * @return array{score:int, label:string, colour:string, blurb:string}
     */
    private function dayScore(array $t): array
    {
        $moments = min(1.0, $t['moments'] / 12);        // ~12 logged moments = full marks
        $kids    = min(1.0, $t['children'] / 6);        // ~6 children cared for
        $obs     = min(1.0, $t['observations'] / 2);    // 2 observations is a strong day
        $hours   = min(1.0, ($t['minutes'] / 60) / 7);  // ~7h on the floor

        $score = (int) round(100 * (0.40 * $moments + 0.25 * $kids + 0.20 * $obs + 0.15 * $hours));
        $score = max(0, min(100, $score));

        if ($score >= 85)      return ['score' => $score, 'label' => 'Outstanding day', 'colour' => '#16A34A', 'blurb' => 'Everything logged, everyone cared for. Brilliant.'];
        if ($score >= 70)      return ['score' => $score, 'label' => 'Great day',       'colour' => '#0EA5E9', 'blurb' => 'A strong, steady day with the children.'];
        if ($score >= 50)      return ['score' => $score, 'label' => 'Solid day',       'colour' => '#7C3AED', 'blurb' => 'Good work today — a moment or two more tomorrow.'];
        if ($score >= 25)      return ['score' => $score, 'label' => 'Quieter day',     'colour' => '#F59E0B', 'blurb' => 'A lighter day. Logging a little more helps families feel close.'];
        return ['score' => $score, 'label' => 'Light day', 'colour' => '#94A3B8', 'blurb' => 'Not much logged today — tomorrow is a fresh page.'];
    }

    /**
     * The educator's place, and the agency's own word for it.
     *
     * settings.centre_term is 'centre', 'room' or 'provider' — and a home-provider
     * agency's centre record IS the provider, so it is one lookup with three labels.
     *
     * @return array{0: ?string, 1: string}
     */
    private function placeFor(int $userId, int $agencyId): array
    {
        $word = 'centre';
        try {
            $settings = DB::table('agencies')->where('id', $agencyId)->value('settings');
            $arr = $settings ? (json_decode($settings, true) ?: []) : [];
            $t = $arr['centre_term'] ?? 'centre';
            if (in_array($t, ['centre', 'room', 'provider'], true)) $word = $t;
        } catch (\Throwable $e) {
        }
        try {
            $centreId = DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)
                ->whereNotNull('centre_id')->value('centre_id');
            if (! $centreId && Schema::hasTable('educator_rooms')) {
                $centreId = DB::table('educator_rooms as er')->join('rooms as r', 'r.id', '=', 'er.room_id')
                    ->where('er.user_id', $userId)->value('r.centre_id');
            }
            if (! $centreId) return [null, $word];
            return [DB::table('centres')->where('id', $centreId)->value('name') ?: null, $word];
        } catch (\Throwable $e) {
            return [null, $word];
        }
    }

    /** The hero: one big score, the place it belongs to, and a progress arc. */
    private function heroCard(array $sc, ?string $place, string $placeWord, Carbon $date): string
    {
        $pct = max(0, min(100, $sc['score']));
        // Email clients will not render a conic gradient or SVG arc reliably, so the
        // meter is a plain two-cell table: a coloured bar over a track. Works in Outlook.
        $bar = '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" style="border-collapse:collapse;">'
             . '<tr><td width="' . $pct . '%" style="background:' . $sc['colour'] . ';height:10px;border-radius:6px 0 0 6px;font-size:0;line-height:0;">&nbsp;</td>'
             . '<td style="background:#E6EDF5;height:10px;border-radius:0 6px 6px 0;font-size:0;line-height:0;">&nbsp;</td></tr></table>';

        return '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" '
             . 'style="border-collapse:separate;background:linear-gradient(135deg,#0B2545 0%,#12315C 60%,#0E7C90 140%);'
             . 'border-radius:18px;margin:6px 0 22px;"><tr><td style="padding:22px 24px;">'
             . ($place
                 ? '<div style="font-size:10.5px;font-weight:800;letter-spacing:1.4px;color:#8FB3D9;text-transform:uppercase;margin-bottom:10px;">'
                   . htmlspecialchars(strtoupper($placeWord)) . ' &middot; ' . htmlspecialchars($place) . '</div>'
                 : '')
             . '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation"><tr>'
             . '<td valign="middle" style="width:96px;">'
             .   '<div style="width:88px;height:88px;border-radius:50%;background:rgba(255,255,255,.10);'
             .   'border:3px solid ' . $sc['colour'] . ';text-align:center;">'
             .   '<div style="font-size:30px;font-weight:800;color:#fff;line-height:1.05;padding-top:19px;">' . $pct . '</div>'
             .   '<div style="font-size:9px;font-weight:800;letter-spacing:1px;color:#9FC4E8;text-transform:uppercase;">/ 100</div>'
             .   '</div></td>'
             . '<td valign="middle" style="padding-left:18px;">'
             .   '<div style="font-size:19px;font-weight:800;color:#fff;line-height:1.25;">' . htmlspecialchars($sc['label']) . '</div>'
             .   '<div style="font-size:13px;color:#BBD3EC;line-height:1.5;margin-top:4px;">' . htmlspecialchars($sc['blurb']) . '</div>'
             .   '<div style="margin-top:12px;">' . $bar . '</div>'
             . '</td></tr></table>'
             . '<div style="font-size:11.5px;color:#8FB3D9;margin-top:14px;">' . $date->format('l, j F Y') . '</div>'
             . '</td></tr></table>';
    }

    /** A bright, iconed stat card cell for the "day in numbers" grid. */
    private function card(string $icon, string $label, string $value, string $accent): string
    {
        return '<td valign="top" width="33%" style="padding:5px;">'
            . '<div style="background:#FFFFFF;border:1px solid #EAEEF3;border-top:3px solid ' . $accent . ';border-radius:12px;padding:14px 8px;text-align:center;box-shadow:0 1px 3px rgba(15,23,42,.05);">'
            . '<div style="font-size:22px;line-height:1;">' . $icon . '</div>'
            . '<div style="font-size:24px;font-weight:800;color:' . $accent . ';margin-top:6px;">' . htmlspecialchars($value) . '</div>'
            . '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;color:#64748B;margin-top:3px;line-height:1.3;">' . htmlspecialchars($label) . '</div>'
            . '</div></td>';
    }

    private function delta(int $now, int $prev): string
    {
        if ($prev === 0) { return $now > 0 ? '<span style="color:#16A34A;">▲ new</span>' : '—'; }
        $d = $now - $prev;
        if ($d === 0) { return '<span style="color:#94A3B8;">▬ same</span>'; }
        return $d > 0
            ? '<span style="color:#16A34A;">▲ ' . $d . '</span>'
            : '<span style="color:#B45309;">▼ ' . abs($d) . '</span>';
    }

    private function buildHtml($ed, array $t, array $y, array $m, Carbon $date, string $tz): string
    {
        $name = $ed->first_name ?: 'there';
        $hoursTxt = $t['minutes'] > 0 ? round($t['minutes'] / 60, 1) . 'h' : '—';

        // Deterministic daily variety — no two days read the same for the same person.
        $seed = crc32($date->toDateString() . '#' . $ed->id);
        $pick = function (array $arr) use (&$seed) { $seed = ($seed * 1103515245 + 12345) & 0x7fffffff; return $arr[$seed % count($arr)]; };

        $greet = $pick([
            'Hi ' . e($name) . ', what a day! 🌟',
            'Hey ' . e($name) . ' — here\'s your day at a glance. ✨',
            'Hello ' . e($name) . ', let\'s look back on today. 🌸',
            e($name) . ', you did wonderful work today. 💛',
            'Good evening ' . e($name) . '! Here\'s how today unfolded. 🌇',
            'Well done today, ' . e($name) . '. 🌿',
        ]);
        $opener = $pick([
            'Thank you for the warmth, patience and love you brought to your room today — it truly makes a difference.',
            'Every gentle moment you gave the children today mattered more than you know. Here\'s a little snapshot.',
            'The little ones in your care learned, laughed and grew today — because of you. Here\'s the recap.',
            'Behind every happy child today was your steady, caring presence. Here\'s what that looked like.',
            'You poured so much heart into today. Take a moment to see everything you accomplished.',
            'Another day of shaping little lives — here\'s a look at all the good you did.',
        ]);
        // Score + place lead the email: the first thing you see is how the day went and
        // whose room it was, not a wall of six equal tiles.
        $sc = $this->dayScore($t);
        [$placeName, $placeWord] = $this->placeFor((int) $ed->id, (int) $ed->agency_id);

        $body = '<p style="font-size:16px;font-weight:700;color:#0F172A;margin:0 0 4px;">' . $greet . '</p>'
            . $this->heroCard($sc, $placeName, $placeWord, $date)
            . '<p style="margin:0 0 4px;">' . $opener . '</p>';

        // A fresh inspirational quote each day (same for everyone that day).
        $body .= EmailTemplate::dailyQuote(crc32($date->toDateString()));

        // Stat cards — bright, iconed, three-up.
        $cards = [
            ['🌟', 'Caring moments', (string) $t['moments'], '#7C3AED'],
            ['👶', 'Children cared for', (string) $t['children'], '#1F6080'],
            ['🍽️', 'Meals & snacks', (string) $t['meals'], '#0EA5E9'],
            ['😴', 'Naps', (string) $t['naps'], '#0F9D6B'],
            ['🎨', 'Activities', (string) $t['activities'], '#DB2777'],
            ['⏱️', 'Hours', $hoursTxt, '#F59E0B'],
        ];
        $body .= '<h2 style="font-size:16px;margin:24px 0 10px;">📋 Your day in numbers</h2>'
            . '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation"><tr>';
        foreach ($cards as $i => $c) {
            $body .= $this->card($c[0], $c[1], $c[2], $c[3]);
            if ($i === 2) { $body .= '</tr><tr>'; }
        }
        $body .= '</tr></table>';

        // Comparison table (today vs yesterday vs ~a month ago).
        $rows = [
            ['Caring moments', $t['moments'], $y['moments'], $m['moments']],
            ['Children', $t['children'], $y['children'], $m['children']],
            ['Activities', $t['activities'], $y['activities'], $m['activities']],
            ['Observations', $t['observations'], $y['observations'], $m['observations']],
        ];
        $cmp = '<h2 style="font-size:16px;margin:26px 0 8px;">📈 How today compares</h2>'
            . '<table style="width:100%;border-collapse:collapse;font-size:14px;">'
            . '<tr style="color:#94A3B8;font-size:11px;text-transform:uppercase;letter-spacing:.5px;text-align:right;">'
            . '<td style="text-align:left;padding:6px 0;">&nbsp;</td><td style="padding:6px 8px;">Today</td>'
            . '<td style="padding:6px 8px;">vs yesterday</td><td style="padding:6px 8px;">vs a month ago</td></tr>';
        foreach ($rows as $r) {
            $cmp .= '<tr style="border-top:1px solid #EEF2F6;text-align:right;">'
                . '<td style="text-align:left;padding:8px 0;font-weight:700;color:#0F172A;">' . e($r[0]) . '</td>'
                . '<td style="padding:8px 8px;font-weight:800;color:#0F172A;">' . (int) $r[1] . '</td>'
                . '<td style="padding:8px 8px;">' . $this->delta((int) $r[1], (int) $r[2]) . '</td>'
                . '<td style="padding:8px 8px;">' . $this->delta((int) $r[1], (int) $r[3]) . '</td></tr>';
        }
        $cmp .= '</table>';
        $body .= $cmp;

        // Wins — always find something warm to celebrate.
        $wins = [];
        if ($t['moments'] > 0) { $wins[] = 'You logged <strong>' . $t['moments'] . '</strong> caring moment' . ($t['moments'] === 1 ? '' : 's') . ' — every one keeps a family connected to their child\'s day. 💛'; }
        if ($t['moments'] > $y['moments'] && $y['moments'] > 0) { $wins[] = 'That\'s more than yesterday — lovely momentum!'; }
        if ($t['activities'] >= 2) { $wins[] = 'A great variety of <strong>' . $t['activities'] . '</strong> learning activities — the children are lucky to have you.'; }
        if ($t['observations'] > 0) { $wins[] = 'You captured <strong>' . $t['observations'] . '</strong> learning observation' . ($t['observations'] === 1 ? '' : 's') . ' — parents love these windows into growth.'; }
        if ($t['naps'] > 0 && $t['meals'] > 0) { $wins[] = 'Meals and naps beautifully tracked — those steady routines help little ones feel safe.'; }
        if (empty($wins)) { $wins[] = 'You showed up and cared for your room today — and that\'s what matters most. 💛'; }
        $body .= '<h2 style="font-size:16px;margin:26px 0 8px;">' . $pick(['🎉 Today\'s wins', '⭐ Moments to be proud of', '💫 What went brilliantly', '🌟 Today\'s bright spots', '👏 Worth celebrating']) . '</h2>';
        $body .= '<ul style="margin:0 0 8px;padding-left:20px;font-size:14.5px;line-height:1.7;color:#2A3D5F;">';
        foreach ($wins as $w) { $body .= '<li>' . $w . '</li>'; }
        $body .= '</ul>';

        // Gentle, encouraging suggestions based on gaps.
        $tips = [];
        if ($t['observations'] === 0) { $tips[] = 'A quick learning observation tomorrow — even one line — gives parents a treasured glimpse of their child\'s progress.'; }
        if ($t['activities'] === 0) { $tips[] = 'Logging an activity or two helps families see all the wonderful learning happening in your room.'; }
        if ($t['meals'] === 0 && $t['minutes'] > 0) { $tips[] = 'If meals happened today, a quick meal log reassures parents their little one ate well.'; }
        if ($t['moments'] > 0 && $t['moments'] < 4) { $tips[] = 'A few more quick taps through the day (a photo, a mood, a nap) build a richer story for parents — no extra effort, just in-the-moment.'; }
        if (! empty($tips)) {
            $body .= EmailTemplate::calloutBox(
                '<strong>A gentle idea for tomorrow:</strong><br>' . implode('<br>• ', array_merge([''], $tips)),
                'info'
            );
        }

        // Warm close — varied each day.
        $close = $pick([
            'You\'re doing a wonderful job, ' . e($name) . '. The children are growing, laughing and thriving because of the care you give every single day. Rest well tonight — you\'ve earned it. 💛',
            'Thank you for everything today, ' . e($name) . '. The little ones are lucky to have someone so kind and dedicated. Put your feet up tonight — you deserve it. 🌷',
            'What you do matters, ' . e($name) . ' — more than any number can show. The warmth you give these children stays with them for life. Have a lovely, restful evening. ✨',
            'Every child in your room felt safe and cared for today, ' . e($name) . ', and that is everything. Be proud, and rest easy tonight. 💛',
            'Days like today are quietly building brighter futures, ' . e($name) . '. Thank you for your patience and heart. Enjoy a well-earned evening. 🌙',
        ]);
        $signoff = $pick(['With gratitude,', 'With appreciation,', 'Warmly,', 'Cheering you on,', 'Thank you,']);
        $body .= '<p style="margin-top:20px;font-size:15px;">' . $close . '</p>'
            . '<p style="font-weight:800;color:#0F172A;">' . $signoff . '<br>Your KiddieTrac team</p>';

        return EmailTemplate::wrap((int) $ed->agency_id, $body, [
            'title'     => 'Your day at a glance',
            'preheader' => 'A warm look back at everything you did today — thank you!',
        ]);
    }

    private function send(int $agencyId, string $email, string $name, string $subject, string $html): void
    {
        dispatch(function () use ($agencyId, $email, $name, $subject, $html) {
            AgencyMailer::forAgency($agencyId)->mailer()->html($html, function ($m) use ($email, $name, $subject) {
                $m->to($email, $name)
                  ->from('noreply@kiddietrac.com', 'KiddieTrac')
                  ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
                  ->subject($subject);
            });
        })->onQueue('mail');
    }
}
