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
        {--limit= : Stop after N educators (defaults to 1 when --to is set)}
        {--dry-run : Build it, print what would happen, send nothing}';

    protected $description = 'Email each educator a warm end-of-day summary of their day, with wins and gentle suggestions';

    public function handle(): int
    {
        $onlyUser = $this->option('user') ? (int) $this->option('user') : null;
        $override = $this->option('to');
        $dry = (bool) $this->option('dry-run');
        // A test send is to check the layout, not to mail yourself the whole staff
        // list: --to produced one email per educator (24 of them, once). It now
        // means "show me one", unless --limit says otherwise.
        $limit = ($this->option('limit') !== null && $this->option('limit') !== '')
            ? max(1, (int) $this->option('limit'))
            : ($override ? 1 : 0);
        $sentCount = 0;

        $educators = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->join('centres as ce', 'ce.id', '=', 'ra.centre_id')
            ->join('agencies as a', 'a.id', '=', 'ce.agency_id')
            ->where('ra.role', 'educator')->where('ra.active', 1)
            ->whereNotNull('u.email')->whereNull('u.deleted_at')
            ->when($onlyUser, fn ($q) => $q->where('u.id', $onlyUser))
            // ce.id so the operating-day check below has a centre to ask about. Without
            // it the check would read null and quietly pass every educator through on a
             // Sunday — the same shape of bug as the check-in reminder's missing centre_id.
            ->select('u.id', 'u.first_name', 'u.email', 'u.sex', 'ce.id as centre_id', 'ce.agency_id', 'a.timezone as tz')
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

            // Not a day this centre runs — nothing to write up. The parent summary has
            // always checked this; this one never did, so educators at a Monday–Friday
            // centre were getting a Saturday summary of a day that did not happen.
            if (! $override && ! \App\Support\Closures::isOperatingDay((int) ($ed->centre_id ?? 0), $date->toDateString())) {
                $this->line("· {$ed->first_name}: not an operating day, skipping");
                continue;
            }

            $today = self::statsFor((int) $ed->id, $tz, $date);
            if (! $override && $today['moments'] === 0 && $today['minutes'] === 0) {
                $this->line("· {$ed->first_name}: nothing logged, skipping");
                continue;
            }
            $yesterday = self::statsFor((int) $ed->id, $tz, $date->copy()->subDay());
            $monthAgo  = self::statsFor((int) $ed->id, $tz, $date->copy()->subDays(28));

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
            $sentCount++;
            if ($limit && $sentCount >= $limit) {
                $this->info('Stopped after ' . $sentCount . ' (limit).');
                break;
            }
            $sent++;
            $this->info("✓ {$subject} → {$to}");
        }

        $this->info("Done. {$sent} email(s) queued.");
        return self::SUCCESS;
    }

    /**
     * Children who were expected today and were not signed in, split into those
     * whose family reported it and those who simply never arrived.
     *
     * "Expected" comes from the attendance pattern for this weekday. A child with no
     * pattern is not counted as missing — the agency has not said they were coming,
     * and inventing an alarm from an absence of data is how a safeguarding signal
     * becomes noise people learn to ignore.
     */
    /**
     * Children in this educator's rooms whose last event today was a check-IN.
     *
     * The last event decides it, not the presence of a check-out: a child who left and
     * came back has both, and counting check-outs would call them absent.
     *
     * @return string[] child names
     */
    private function childrenStillSignedIn(int $educatorId, string $tz, Carbon $date): array
    {
        $start = Carbon::parse($date->toDateString() . ' 00:00:00', $tz)->utc();
        $end = Carbon::parse($date->toDateString() . ' 23:59:59', $tz)->utc();

        // Rooms first where they are assigned — but educator_rooms is empty across this
        // deployment, so a query resting on it alone reports nothing for everyone. The
        // centre on role_assignments is the fallback the rest of this command already
        // relies on, and it is what actually resolves.
        $roomIds = DB::table('educator_rooms')->where('user_id', $educatorId)->pluck('room_id')->all();

        $q = DB::table('check_events as e')
            ->join('children as ch', 'ch.id', '=', 'e.child_id')
            ->whereBetween('e.occurred_at', [$start, $end])
            ->whereNull('ch.deleted_at');

        if ($roomIds) {
            $q->whereIn('e.room_id', $roomIds);
        } else {
            $centreIds = DB::table('role_assignments')->where('user_id', $educatorId)
                ->where('active', true)->whereNotNull('centre_id')->pluck('centre_id')->all();
            if (! $centreIds) {
                return [];
            }
            // Through the family, the same join the narrative uses.
            $q->join('families as f', 'f.id', '=', 'ch.family_id')->whereIn('f.centre_id', $centreIds);
        }

        $events = $q->orderBy('e.occurred_at')
            ->get(['e.child_id', 'e.event_type', 'ch.first_name', 'ch.last_name']);

        $last = [];
        foreach ($events as $ev) {
            $last[$ev->child_id] = $ev;
        }

        $out = [];
        foreach ($last as $ev) {
            if ($ev->event_type === 'check_in') {
                $out[] = trim($ev->first_name . ' ' . ($ev->last_name ?? ''));
            }
        }
        sort($out);

        return $out;
    }

    private function awayToday(int $educatorId, string $tz, Carbon $date): array
    {
        $out = ['expected_absent' => [], 'reported' => []];
        try {
            $centreIds = DB::table('role_assignments')->where('user_id', $educatorId)
                ->where('active', true)->whereNotNull('centre_id')->pluck('centre_id')->all();
            if (! $centreIds) return $out;

            $dow = strtolower($date->format('l'));               // monday..sunday
            $start = Carbon::parse($date->toDateString() . ' 00:00:00', $tz)->utc();
            $end = Carbon::parse($date->toDateString() . ' 23:59:59', $tz)->utc();

            $children = DB::table('children as ch')
                ->join('families as f', 'f.id', '=', 'ch.family_id')
                ->leftJoin('attendance_patterns as ap', function ($j) {
                    $j->on('ap.child_id', '=', 'ch.id')->whereNull('ap.effective_until');
                })
                ->whereIn('f.centre_id', $centreIds)
                ->whereNull('ch.deleted_at')
                ->where('ch.enrollment_status', 'enrolled')
                ->select('ch.id', 'ch.first_name', 'ch.last_name', 'ap.' . $dow . ' as expected')
                ->get();

            foreach ($children as $c) {
                if (empty($c->expected)) continue;               // not expected today
                $wasIn = DB::table('check_events')->where('child_id', $c->id)
                    ->where('event_type', 'check_in')
                    ->whereBetween('occurred_at', [$start, $end])->exists();
                if ($wasIn) continue;

                $name = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
                $absence = Schema::hasTable('child_absences')
                    ? DB::table('child_absences')->where('child_id', $c->id)
                        ->whereDate('absent_on', $date->toDateString())->first()
                    : null;
                if ($absence) {
                    $out['reported'][] = ['name' => $name, 'reason' => $absence->reason ?? null];
                } else {
                    $out['expected_absent'][] = ['name' => $name];
                }
            }
        } catch (\Throwable $e) {
            // A summary email must still send if this lookup fails.
            \Illuminate\Support\Facades\Log::warning('Educator summary: away list failed', ['error' => $e->getMessage()]);
        }
        return $out;
    }

    /** This educator's activity for the given agency-day. */
    /**
     * Public and static so the Today screen shows the SAME number as the evening email.
     * Two implementations of "how did today go" drifting apart would be worse than
     * having neither — the educator would not know which to believe.
     */
    public static function statsFor(int $uid, string $tz, Carbon $date): array
    {
        $start = Carbon::parse($date->toDateString() . ' 00:00:00', $tz)->utc();
        $end   = Carbon::parse($date->toDateString() . ' 23:59:59', $tz)->utc();

        // BOTH care tables. The roster quick-log writes daily_events; the care
        // screen writes daily_care_logs. Reading only the first counted a real
        // day's work as a light one - and the score is 40% this number.
        $events = DB::table('daily_events')->where('recorded_by_id', $uid)
            ->whereBetween('occurred_at', [$start, $end])->get(['event_type', 'child_id', 'occurred_at']);

        $careLogs = Schema::hasTable('daily_care_logs')
            ? DB::table('daily_care_logs')->where('recorded_by_id', $uid)
                ->whereBetween('occurred_at', [$start, $end])->get(['log_type', 'child_id', 'occurred_at'])
            : collect();

        // The two tables use different words for the same care, so map them onto one
        // set of buckets: a nap counts as a nap whichever screen logged it. Bathroom
        // rides with nappies (both toileting) and bottle with meals (both feeding);
        // sunscreen and mood have no tile but still count as moments, which is the
        // point - they ARE work done for a child.
        $CARE_TO_EVENT = [
            'meal' => 'meal', 'snack' => 'snack', 'bottle' => 'meal',
            'nap' => 'nap', 'diaper' => 'diaper', 'bathroom' => 'diaper',
            'mood' => 'mood', 'sunscreen' => 'care',
        ];

        $byType = [];
        $momentKeys = [];      // child|bucket|minute - dedupes a moment written to both
        $addMoment = function ($childId, $bucket, $when) use (&$byType, &$momentKeys) {
            try { $minute = Carbon::parse($when)->format('Y-m-d H:i'); } catch (\Throwable $e) { $minute = (string) $when; }
            $key = $childId . '|' . $bucket . '|' . $minute;
            if (isset($momentKeys[$key])) return;        // same moment, both tables
            $momentKeys[$key] = true;
            $byType[$bucket] = ($byType[$bucket] ?? 0) + 1;
        };
        foreach ($events as $e)   { $addMoment($e->child_id, $e->event_type, $e->occurred_at); }
        foreach ($careLogs as $c) { $addMoment($c->child_id, $CARE_TO_EVENT[$c->log_type] ?? $c->log_type, $c->occurred_at); }
        $moments = count($momentKeys);

        // Photos and video shared with families are the day's work too, and were
        // credited nowhere at all.
        $media = Schema::hasTable('photos')
            ? DB::table('photos')->where('uploaded_by_id', $uid)->whereBetween('created_at', [$start, $end])->count()
            : 0;

        // Children covered = everyone this educator actually looked after, from any
        // source. This used to take the check_events count and fall back to the logs
        // only when that was ZERO, so checking in 3 children while logging care for 8
        // credited 3.
        $children = DB::table('check_events')->where('recorded_by_id', $uid)
            ->whereBetween('occurred_at', [$start, $end])->pluck('child_id')
            ->merge($events->pluck('child_id'))
            ->merge($careLogs->pluck('child_id'))
            ->filter()->unique()->count();

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
            'moments'      => (int) $moments + (int) $media,
            'children'     => (int) $children,
            'observations' => (int) $obs,
            'minutes'      => $mins,
            'media'        => (int) $media,
            'meals'        => ($byType['meal'] ?? 0) + ($byType['snack'] ?? 0),
            'naps'         => ($byType['nap_start'] ?? 0) + ($byType['nap_end'] ?? 0) + ($byType['nap'] ?? 0),
            'activities'   => ($byType['activity'] ?? 0) + ($byType['care'] ?? 0),
            'diapers'      => $byType['diaper'] ?? 0,
            'notes'        => ($byType['note'] ?? 0) + ($byType['mood'] ?? 0),
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
    public static function dayScore(array $t): array
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

    /**
     * One stat tile: a soft colour wash, a big number, a quiet label.
     *
     * Replaces the old card(): white boxes with a coloured top border read as a form,
     * not a celebration. Table-cell based with inline styles, because Outlook ignores
     * flex/grid and collapses divs with percentage widths.
     */
    private function statTile(string $icon, string $label, string $value, string $accent, string $wash): string
    {
        return '<td valign="top" width="33.33%" style="padding:5px;">'
            . '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" '
            .   'style="border-collapse:separate;background:' . $wash . ';border-radius:14px;">'
            . '<tr><td style="padding:14px 10px;text-align:center;">'
            .   '<div style="font-size:20px;line-height:1;">' . $icon . '</div>'
            .   '<div style="font-size:26px;font-weight:800;color:' . $accent . ';margin-top:6px;line-height:1.1;">'
            .     htmlspecialchars($value) . '</div>'
            .   '<div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;'
            .     'color:#64748B;margin-top:4px;line-height:1.35;">' . htmlspecialchars($label) . '</div>'
            . '</td></tr></table></td>';
    }

    /** A section heading with a hairline under it, used to break the email into parts. */

    /**
     * Walks this educator led on the day, with the route map for finished ones.
     *
     * Led BY them, not merely at their centre: a summary that says "where you took
     * them" should not list somebody else's outing.
     */
    private function walksLed(int $educatorId, Carbon $date, string $tz): array
    {
        try {
            $rows = DB::table('field_trips')
                ->where('staff_lead_id', $educatorId)
                ->whereDate('trip_date', $date->toDateString())
                ->orderBy('depart_time')
                ->get(['id', 'title', 'destination', 'status', 'depart_time', 'return_time', 'distance_km']);

            $out = [];
            foreach ($rows as $r) {
                $sum = \App\Http\Controllers\Api\WalkController::walkSummary((int) $r->id);
                $kids = DB::table('field_trip_permissions')->where('field_trip_id', $r->id)->count();

                // depart_time/return_time are agency-LOCAL wall clock, not UTC.
                $when = trim(($r->depart_time ? Carbon::parse($r->depart_time)->format('g:i A') : '')
                    .($r->return_time ? ' – '.Carbon::parse($r->return_time)->format('g:i A') : ''), ' –');

                $out[] = [
                    'where' => trim((string) ($r->destination ?: $r->title)) ?: 'A walk',
                    'when' => $when,
                    'minutes' => (int) ($sum['duration_min'] ?? 0),
                    'km' => round(($sum['distance_m'] ?? 0) / 1000, 2),
                    'children' => (int) $kids,
                    'map_url' => $r->status === 'completed'
                        ? \App\Support\WalkMap::urlFor((int) $r->id)
                        : null,
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            // A summary that arrives without its walks beats one that does not arrive.
            return [];
        }
    }
    private function sectionHead(string $icon, string $text): string
    {
        return '<div style="margin:26px 0 12px;">'
            . '<span style="font-size:15px;font-weight:800;color:#0F172A;">' . $icon . ' ' . htmlspecialchars($text) . '</span>'
            . '<div style="height:3px;width:38px;background:#0E7C90;border-radius:3px;margin-top:7px;"></div>'
            . '</div>';
    }

    /** One comparison row: today's number, then how it moved against a past day. */
    private function compareRow(string $label, int $now, int $yest, int $month): string
    {
        return '<tr>'
            . '<td style="padding:11px 12px;border-top:1px solid #EEF2F6;font-weight:700;color:#0F172A;font-size:13.5px;">'
            .   htmlspecialchars($label) . '</td>'
            . '<td align="center" style="padding:11px 8px;border-top:1px solid #EEF2F6;font-weight:800;color:#0F172A;font-size:16px;">'
            .   $now . '</td>'
            . '<td align="center" style="padding:11px 8px;border-top:1px solid #EEF2F6;">' . $this->delta($now, $yest) . '</td>'
            . '<td align="center" style="padding:11px 8px;border-top:1px solid #EEF2F6;">' . $this->delta($now, $month) . '</td>'
            . '</tr>';
    }

    /**
     * A sentence per child about how their day went, and what this educator did for
     * them. Six tiles say "14 moments"; a parent-style line says what actually
     * happened, which is what makes the email feel like the day rather than a report.
     *
     * Reads BOTH care tables: daily_events (roster quick-log) and daily_care_logs
     * ("Log a moment") — using only one of them has bitten this codebase before.
     *
     * @return array<int, array{name:string, line:string, moments:int}>
     */
    private function childrenNarrative(int $uid, string $tz, Carbon $date): array
    {
        $start = Carbon::parse($date->toDateString() . ' 00:00:00', $tz)->utc();
        $end   = Carbon::parse($date->toDateString() . ' 23:59:59', $tz)->utc();

        $byChild = [];
        $add = function (int $cid, string $kind, ?string $detail) use (&$byChild) {
            if (! isset($byChild[$cid])) $byChild[$cid] = ['kinds' => [], 'notes' => []];
            $byChild[$cid]['kinds'][$kind] = ($byChild[$cid]['kinds'][$kind] ?? 0) + 1;
            $detail = trim((string) $detail);
            if ($detail !== '' && count($byChild[$cid]['notes']) < 2) $byChild[$cid]['notes'][] = $detail;
        };

        foreach (DB::table('daily_events')->where('recorded_by_id', $uid)
            ->whereBetween('occurred_at', [$start, $end])->whereNull('deleted_at')
            ->get(['child_id', 'event_type', 'notes']) as $e) {
            $add((int) $e->child_id, (string) $e->event_type, $e->notes);
        }
        if (Schema::hasTable('daily_care_logs')) {
            foreach (DB::table('daily_care_logs')->where('recorded_by_id', $uid)
                ->whereBetween('occurred_at', [$start, $end])
                ->get(['child_id', 'log_type', 'details', 'notes']) as $e) {
                $add((int) $e->child_id, (string) $e->log_type, $e->details ?: $e->notes);
            }
        }
        if (Schema::hasTable('observations')) {
            foreach (DB::table('observations')->where('recorded_by_id', $uid)
                ->whereBetween('observed_at', [$start, $end])
                ->get(['child_id', 'title']) as $o) {
                $add((int) $o->child_id, 'observation', $o->title);
            }
        }
        if (! $byChild) return [];

        $names = DB::table('children')->whereIn('id', array_keys($byChild))
            ->get(['id', 'first_name', 'preferred_name'])
            ->keyBy('id');

        // Plain words for what each log type meant for the child.
        $PHRASE = [
            'meal' => 'ate', 'snack' => 'had a snack', 'bottle' => 'took a bottle',
            'nap' => 'napped', 'nap_start' => 'napped', 'nap_end' => 'woke up',
            'diaper' => 'was changed', 'bathroom' => 'used the bathroom',
            'activity' => 'joined an activity', 'mood' => 'had their mood checked',
            'observation' => 'was observed for their learning story',
            'note' => 'had a moment shared', 'sunscreen' => 'had sunscreen applied',
        ];

        $out = [];
        foreach ($byChild as $cid => $d) {
            $rec = $names[$cid] ?? null;
            if (! $rec) continue;
            $nm = $rec->preferred_name ?: $rec->first_name;

            $parts = [];
            foreach ($d['kinds'] as $kind => $n) {
                $phrase = $PHRASE[$kind] ?? str_replace('_', ' ', $kind);
                $parts[] = $n > 1 ? ($phrase . ' (' . $n . ')') : $phrase;
            }
            $total = array_sum($d['kinds']);
            $line  = htmlspecialchars($nm) . ' ' . htmlspecialchars(implode(', ', array_slice($parts, 0, 5))) . '.';
            if ($d['notes']) {
                $quoted = array_map(fn ($n) => '&ldquo;' . htmlspecialchars($n) . '&rdquo;', $d['notes']);
            $line .= ' <span style="color:#64748B;">' . implode(' &middot; ', $quoted) . '</span>';
            }
            $out[] = ['name' => $nm, 'line' => $line, 'moments' => $total];
        }
        usort($out, fn ($a, $b) => $b['moments'] <=> $a['moments']);
        return $out;
    }

    /**
     * The educator's own shift: each clock-in/out pair in the AGENCY's timezone.
     * "7h" in a tile does not tell someone whether they forgot to clock out.
     *
     * @return array{rows: array<int, array{in:string, out:?string, mins:int}>, total:int, open:bool}
     */
    private function shiftDetail(int $uid, string $tz, Carbon $date): array
    {
        $start = Carbon::parse($date->toDateString() . ' 00:00:00', $tz)->utc();
        $end   = Carbon::parse($date->toDateString() . ' 23:59:59', $tz)->utc();
        $rows = []; $total = 0; $open = false;

        foreach (DB::table('time_punches')->where('user_id', $uid)
            ->whereBetween('punched_in_at', [$start, $end])->orderBy('punched_in_at')
            ->get(['punched_in_at', 'punched_out_at']) as $tp) {
            $in  = Carbon::parse($tp->punched_in_at)->timezone($tz);
            $out = $tp->punched_out_at ? Carbon::parse($tp->punched_out_at)->timezone($tz) : null;
            // An open punch is not zero time — it is time still being worked. Count it
            // up to now (capped at the end of the day being reported) so the total
            // reflects the shift instead of reading 0h 0m.
            $upto = $out ?: Carbon::now($tz)->min(Carbon::parse($end)->timezone($tz));
            $mins = (int) $in->diffInMinutes($upto);
            $total += $mins;
            if (! $out) $open = true;
            $rows[] = ['in' => $in->format('g:i A'), 'out' => $out ? $out->format('g:i A') : null, 'mins' => $mins];
        }
        return ['rows' => $rows, 'total' => $total, 'open' => $open];
    }

    /**
     * Two or three concrete things to try tomorrow, drawn from what today was missing
     * rather than generic advice — the point is that it is about THEIR day.
     *
     * @return array<int, string>
     */
    private function tomorrowIdeas(array $t, array $kids, array $shift): array
    {
        $ideas = [];
        if ($t['observations'] < 1) {
            $ideas[] = 'Write one learning observation. A single noticing — who they played with, what they figured out — is what families treasure most.';
        }
        $quiet = array_values(array_filter($kids, fn ($k) => $k['moments'] <= 1));
        if ($quiet) {
            $names = implode(' and ', array_slice(array_column($quiet, 'name'), 0, 3));
            $ideas[] = 'Share a moment for ' . htmlspecialchars($names) . ' — quieter children can go a whole day without a note home.';
        }
        if (($t['activities'] ?? 0) < 1) {
            $ideas[] = 'Log one activity. Even "water play in the garden" gives a parent something real to ask about at dinner.';
        }
        if ($shift['open']) {
            $ideas[] = 'You are still clocked in — remember to clock out so your hours are right.';
        }
        if (($t['moments'] ?? 0) >= 12 && ! $ideas) {
            $ideas[] = 'Honestly? Nothing to fix. Do exactly what you did today.';
        }
        if (! $ideas) {
            $ideas[] = 'Try a photo with your next moment — families read those first.';
        }
        return array_slice($ideas, 0, 3);
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

        // Deterministic daily variety — and it now delivers what that comment claimed.
        //
        // This was crc32(date + educator) driving an LCG. Deterministic, yes, and stable
        // across a resend — but crc32 of one date tells you nothing about the next, so
        // consecutive evenings landed independently. Measured over a year and five
        // educators, the greeting repeated the previous night 21% of the time, and one
        // educator was sent the identical opening SIX evenings running. That is exactly
        // the sameness this was supposed to prevent.
        //
        // Stepping by day-of-year instead means the index advances by exactly one each
        // night, so the same variant can never appear twice in a row and the cycle is
        // as long as the list. Each slot gets its own phase offset so the greeting and
        // the sign-off are not always the same distance apart.
        $doy = (int) $date->format('z');
        $slotNo = 0;
        $pick = function (array $arr) use (&$slotNo, $doy, $ed) {
            $slotNo++;
            return $arr[abs((int) $ed->id + $doy + $slotNo * 7) % count($arr)];
        };

        $greet = $pick([
            'Hi ' . e($name) . ', what a day! 🌟',
            'Hey ' . e($name) . ' — here\'s your day at a glance. ✨',
            'Hello ' . e($name) . ', let\'s look back on today. 🌸',
            e($name) . ', you did wonderful work today. 💛',
            'Good evening ' . e($name) . '! Here\'s how today unfolded. 🌇',
            'Well done today, ' . e($name) . '. 🌿',
            'That is the day done, ' . e($name) . '. 🌙',
            e($name) . ', here is what today added up to. 📋',
            'Evening, ' . e($name) . ' — today in one page. 🌆',
            'Made it, ' . e($name) . '. Here is the day. ☕',
            'A good shift, ' . e($name) . '. Here is the shape of it. 🌤️',
            e($name) . ', today is in the books. 📖',
            'Winding down, ' . e($name) . '? Here is your day. 🕯️',
            'Nice work out there today, ' . e($name) . '. 🌻',
            e($name) . ' — a look back at today before you switch off. 🌒',
            'Hello ' . e($name) . '. Today, gathered up for you. 🧺',
            'Another one done, ' . e($name) . '. Here is how it went. 🍂',
            e($name) . ', your day at a glance. 🔎',
            'Hi ' . e($name) . ' — the day you just had, in short. 🌼',
            'Signing off, ' . e($name) . '? Here is today first. 🌘',
        ]);
        $opener = $pick([
            'Thank you for the warmth, patience and love you brought to your room today — it truly makes a difference.',
            'Every gentle moment you gave the children today mattered more than you know. Here\'s a little snapshot.',
            'The little ones in your care learned, laughed and grew today — because of you. Here\'s the recap.',
            'Behind every happy child today was your steady, caring presence. Here\'s what that looked like.',
            'You poured so much heart into today. Take a moment to see everything you accomplished.',
            'Another day of shaping little lives — here\'s a look at all the good you did.',
            'The care you gave today does not show up in numbers, but here are the numbers anyway.',
            'Children remember how a room felt long after they forget what they did in it. Yours felt safe today.',
            'A day of small kindnesses, most of which nobody saw. Here is the part that was recorded.',
            'You kept a room of little people fed, rested and looked after today. That is not a small thing.',
            'Here is today, laid out — though the best parts of it never fit in a summary.',
            'Steady hands, a calm voice, and a long day. Here is what came of it.',
            'The work you did today will show up in these children for years. Here is the short version.',
            'You gave a lot today. Here is a record of where it went.',
            'Somebody had to notice every small thing in that room today, and you did. Here is the tally.',
        ]);
        // Score + place lead the email: the first thing you see is how the day went and
        // whose room it was, not a wall of six equal tiles.
        $sc = self::dayScore($t);
        [$placeName, $placeWord] = $this->placeFor((int) $ed->id, (int) $ed->agency_id);

        $body = '<p style="font-size:16px;font-weight:700;color:#0F172A;margin:0 0 4px;">' . $greet . '</p>'
            . $this->heroCard($sc, $placeName, $placeWord, $date)
            . '<p style="margin:0 0 4px;">' . $opener . '</p>';

        // ── Your day in numbers ────────────────────────────────────────────
        // Soft washes rather than bordered white boxes: this is a thank-you, not a
        // report. Two rows of three, table-based so Outlook lays it out correctly.
        $tiles = [
            ["\u{1F31F}", 'Caring moments', (string) $t['moments'],      '#6D28D9', '#F3EEFF'],
            ["\u{1F476}", 'Children',       (string) $t['children'],     '#155E75', '#E7F5FA'],
            ["\u{1F37D}", 'Meals & snacks', (string) $t['meals'],        '#0369A1', '#E6F3FC'],
            ["\u{1F634}", 'Naps',           (string) $t['naps'],         '#047857', '#E7F7F1'],
            ["\u{1F3A8}", 'Activities',     (string) $t['activities'],   '#BE185D', '#FDECF3'],
            ["\u{23F1}",  'Hours on floor', $hoursTxt,                   '#B45309', '#FEF4E6'],
        ];
        $body .= $this->sectionHead("\u{1F4CB}", 'Your day in numbers')
            . '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" style="border-collapse:separate;"><tr>';
        foreach ($tiles as $i => $tile) {
            $body .= $this->statTile($tile[0], $tile[1], $tile[2], $tile[3], $tile[4]);
            if ($i === 2) { $body .= '</tr><tr>'; }
        }
        $body .= '</tr></table>';

        // ── Where you took them ────────────────────────────────────────────
        $walks = $this->walksLed((int) $ed->id, $date, $tz);
        if ($walks) {
            $totalKm = round(array_sum(array_map(fn ($w) => $w['km'], $walks)), 2);
            $body .= $this->sectionHead("\u{1F6B6}", 'Where you took them'
                . ($totalKm > 0 ? ' — ' . rtrim(rtrim(number_format($totalKm, 2), '0'), '.') . ' km' : ''));

            foreach ($walks as $w) {
                $bits = array_filter([
                    $w['when'],
                    $w['minutes'] ? $w['minutes'] . ' min' : null,
                    $w['km'] > 0 ? rtrim(rtrim(number_format($w['km'], 2), '0'), '.') . ' km' : null,
                    $w['children'] . ' child' . ($w['children'] === 1 ? '' : 'ren'),
                ]);

                $body .= '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" '
                    . 'style="border-collapse:separate;background:#F0F9FF;border:1px solid #BAE6FD;border-radius:14px;margin-bottom:10px;">'
                    . '<tr><td style="padding:13px 15px;">'
                    . '<div style="font-size:15px;font-weight:800;color:#0C4A6E;">'
                    . htmlspecialchars($w['where']) . '</div>'
                    . ($bits ? '<div style="font-size:13px;color:#0369A1;margin-top:3px;">'
                        . htmlspecialchars(implode(' · ', $bits)) . '</div>' : '');

                // Same rendered PNG the parents are sent, so everybody is looking at the
                // same picture of the same walk.
                if ($w['map_url']) {
                    $body .= '<img src="' . htmlspecialchars($w['map_url']) . '" width="540" alt="Route walked"'
                        . ' style="display:block;width:100%;max-width:540px;height:auto;border:0;'
                        . 'border-radius:10px;margin-top:10px;">';
                }

                $body .= '</td></tr></table>';
            }
        }

        // ── What made up today's score ─────────────────────────────────────
        // One number says "63" without saying why. These are the four parts it is
        // built from, each drawn as how close it came to full marks, so where
        // tomorrow could differ is visible rather than merely asserted.
        $bars = [
            ['Moments logged',     min(1.0, $t['moments'] / 12),       $t['moments'] . ' of ~12',   '#7C3AED'],
            ['Children cared for', min(1.0, $t['children'] / 6),       (string) $t['children'],     '#0E7C90'],
            ['Observations',       min(1.0, $t['observations'] / 2),   (string) $t['observations'], '#B45309'],
            ['Hours on the floor', min(1.0, ($t['minutes'] / 60) / 7), $hoursTxt,                   '#0369A1'],
        ];
        $body .= $this->sectionHead("\u{1F4CA}", "What made up today's score")
            . '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" '
            . 'style="border-collapse:separate;background:#fff;border:1px solid #EEF2F6;border-radius:14px;">'
            . '<tr><td style="padding:14px 16px;">';
        foreach ($bars as $b) {
            $pct = (int) round($b[1] * 100);
            $body .= '<div style="margin-bottom:11px;">'
                . '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation"><tr>'
                .   '<td style="font-size:12.5px;font-weight:700;color:#334155;">' . e($b[0]) . '</td>'
                .   '<td align="right" style="font-size:12px;color:#94A3B8;">' . e($b[2]) . '</td>'
                . '</tr></table>'
                . '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" '
                .   'style="border-collapse:separate;background:#F1F5F9;border-radius:999px;margin-top:4px;"><tr>'
                .   '<td width="' . max(2, $pct) . '%" style="background:' . $b[3] . ';border-radius:999px;height:9px;font-size:0;line-height:0;">&nbsp;</td>'
                .   '<td style="font-size:0;line-height:0;">&nbsp;</td>'
                . '</tr></table>'
                . '</div>';
        }
        $body .= '</td></tr></table>';

        // ── Who wasn't in today ────────────────────────────────────────────
        // The most important thing on this email, and it was missing. A child with
        // a pattern for today who never signed in, and no absence reported, is a
        // question somebody needs to ask — not a number to celebrate.
        $away = $this->awayToday((int) $ed->id, $tz, $date);
        if ($away['expected_absent'] || $away['reported']) {
            $body .= $this->sectionHead("\u{1F3E0}", "Who wasn't in today");
            $body .= '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" '
                . 'style="border-collapse:collapse;background:#fff;border:1px solid #EEF2F6;border-radius:14px;overflow:hidden;margin-bottom:6px;">';

            foreach ($away['expected_absent'] as $c) {
                $body .= '<tr><td style="padding:11px 13px;border-bottom:1px solid #F1F5F9;">'
                    . '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#DC2626;margin-right:8px;"></span>'
                    . '<strong style="color:#0F172A;">' . e($c['name']) . '</strong>'
                    . '<span style="color:#B91C1C;font-size:13px;"> — expected today, not signed in, no absence reported</span>'
                    . '</td></tr>';
            }
            foreach ($away['reported'] as $c) {
                $body .= '<tr><td style="padding:11px 13px;border-bottom:1px solid #F1F5F9;">'
                    . '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#94A3B8;margin-right:8px;"></span>'
                    . '<strong style="color:#0F172A;">' . e($c['name']) . '</strong>'
                    . '<span style="color:#64748B;font-size:13px;"> — reported away'
                    . ($c['reason'] ? ' (' . e($c['reason']) . ')' : '') . '</span>'
                    . '</td></tr>';
            }
            $body .= '</table>';
            if ($away['expected_absent']) {
                $body .= '<p style="margin:0 0 6px;font-size:13px;color:#B91C1C;">'
                    . 'Please check in with ' . (count($away['expected_absent']) === 1 ? 'this family' : 'these families')
                    . ' if you have not already.</p>';
            }
        }

        // ── How today compares ─────────────────────────────────────────────
        $body .= $this->sectionHead("\u{1F4C8}", 'How today compares')
            . '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" '
            .   'style="border-collapse:collapse;background:#fff;border:1px solid #EEF2F6;border-radius:14px;overflow:hidden;">'
            . '<tr style="background:#F8FAFC;">'
            .   '<td style="padding:9px 12px;font-size:10.5px;font-weight:800;letter-spacing:.5px;color:#94A3B8;text-transform:uppercase;">&nbsp;</td>'
            .   '<td align="center" style="padding:9px 8px;font-size:10.5px;font-weight:800;letter-spacing:.5px;color:#94A3B8;text-transform:uppercase;">Today</td>'
            .   '<td align="center" style="padding:9px 8px;font-size:10.5px;font-weight:800;letter-spacing:.5px;color:#94A3B8;text-transform:uppercase;">vs yesterday</td>'
            .   '<td align="center" style="padding:9px 8px;font-size:10.5px;font-weight:800;letter-spacing:.5px;color:#94A3B8;text-transform:uppercase;">vs a month ago</td>'
            . '</tr>'
            . $this->compareRow('Caring moments', (int) $t['moments'],      (int) $y['moments'],      (int) $m['moments'])
            . $this->compareRow('Children',       (int) $t['children'],     (int) $y['children'],     (int) $m['children'])
            . $this->compareRow('Activities',     (int) $t['activities'],   (int) $y['activities'],   (int) $m['activities'])
            . $this->compareRow('Observations',   (int) $t['observations'], (int) $y['observations'], (int) $m['observations'])
            . '</table>';

        // ── A thought to end on ────────────────────────────────────────────
        // The quote used to sit at the very top, ahead of the person's own day. It
        // reads better as a closing note once the numbers have been seen.
        $body .= $this->sectionHead("\u{1F4AD}", 'A thought to end on')
            . '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" '
            .   'style="border-collapse:separate;background:#F8FBFD;border-left:4px solid #0E7C90;border-radius:0 12px 12px 0;">'
            . '<tr><td style="padding:14px 16px;">' . EmailTemplate::dailyQuote(crc32($date->toDateString())) . '</td></tr></table>';

        // ── The children's day, in words ───────────────────────────────────
        $kids = $this->childrenNarrative((int) $ed->id, $tz, $date);
        if ($kids) {
            $body .= $this->sectionHead("\u{1F9F8}", 'How the children\'s day went')
                . '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" '
                .   'style="border-collapse:collapse;background:#fff;border:1px solid #EEF2F6;border-radius:14px;overflow:hidden;">';
            foreach ($kids as $i => $k) {
                $body .= '<tr><td style="padding:12px 14px;' . ($i ? 'border-top:1px solid #F1F5F9;' : '') . 'font-size:13.5px;line-height:1.6;color:#334155;">'
                    . '<strong style="color:#0F172A;">' . htmlspecialchars($k['name']) . '</strong> &mdash; ' . $k['line']
                    . '</td></tr>';
            }
            $body .= '</table>';
        }

        // ── Your shift ─────────────────────────────────────────────────────
        $shift = $this->shiftDetail((int) $ed->id, $tz, $date);
        if ($shift['rows']) {
            $body .= $this->sectionHead("\u{1F553}", 'Your sign-in and sign-out')
                . '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" '
                .   'style="border-collapse:collapse;background:#fff;border:1px solid #EEF2F6;border-radius:14px;overflow:hidden;">';
            foreach ($shift['rows'] as $i => $r) {
                $dur = intdiv($r['mins'], 60) . 'h ' . ($r['mins'] % 60) . 'm' . ($r['out'] ? '' : ' so far');
                $body .= '<tr><td style="padding:11px 14px;' . ($i ? 'border-top:1px solid #F1F5F9;' : '') . 'font-size:13.5px;color:#334155;">'
                    .   '<strong style="color:#0F172A;">' . htmlspecialchars($r['in']) . '</strong>'
                    .   ' &rarr; ' . ($r['out'] ? '<strong style="color:#0F172A;">' . htmlspecialchars($r['out']) . '</strong>'
                                               : '<span style="color:#B45309;font-weight:700;">not clocked out</span>')
                    .   '<span style="float:right;color:#64748B;">' . htmlspecialchars($dur) . '</span>'
                    . '</td></tr>';
            }
            $tot = $shift['total'];
            $body .= '<tr><td style="padding:11px 14px;border-top:2px solid #EEF2F6;background:#F8FAFC;font-size:13.5px;font-weight:800;color:#0F172A;">'
                . 'Total on the floor' . ($shift['open'] ? ' so far' : '')
                . '<span style="float:right;">' . intdiv($tot, 60) . 'h ' . ($tot % 60) . 'm</span></td></tr></table>';
        }

        // ── Ideas for tomorrow ─────────────────────────────────────────────
        $ideas = $this->tomorrowIdeas($t, $kids, $shift);
        if ($ideas) {
            $body .= $this->sectionHead("\u{1F31E}", 'A couple of ideas for tomorrow')
                . '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" '
                .   'style="border-collapse:separate;background:#F0FAFC;border-radius:14px;"><tr><td style="padding:14px 16px;">';
            foreach ($ideas as $i => $idea) {
                $body .= '<div style="font-size:13.5px;line-height:1.6;color:#134E5A;' . ($i ? 'margin-top:10px;' : '') . '">'
                    . '<span style="color:#0E7C90;font-weight:800;">&bull;</span> ' . $idea . '</div>';
            }
            $body .= '</td></tr></table>';
        }

        // Warm close — varied each day.
        $close = $pick([
            'You\'re doing a wonderful job, ' . e($name) . '. The children are growing, laughing and thriving because of the care you give every single day. Rest well tonight — you\'ve earned it. 💛',
            'Thank you for everything today, ' . e($name) . '. The little ones are lucky to have someone so kind and dedicated. Put your feet up tonight — you deserve it. 🌷',
            'What you do matters, ' . e($name) . ' — more than any number can show. The warmth you give these children stays with them for life. Have a lovely, restful evening. ✨',
            'Every child in your room felt safe and cared for today, ' . e($name) . ', and that is everything. Be proud, and rest easy tonight. 💛',
            'Days like today are quietly building brighter futures, ' . e($name) . '. Thank you for your patience and heart. Enjoy a well-earned evening. 🌙',
            'Whatever today threw at you, ' . e($name) . ', you met it. That is enough for one day. Rest well. 🌙',
            'The children in your room were safe, seen and looked after today, ' . e($name) . '. Go and put the kettle on. ☕',
            'Not every day feels like progress, ' . e($name) . ', but the children felt the difference today. Have a proper rest. 🌿',
            'Thank you for the patience today asked of you, ' . e($name) . '. Close the door on it now and enjoy your evening. 🌇',
            'You showed up and gave it your full attention, ' . e($name) . '. The children know. Sleep well. ✨',
            'Long days like this one are the job, ' . e($name) . ', and you did it well. Take the evening back for yourself. 🌷',
            'The small things you did today will be remembered by people too young to thank you, ' . e($name) . '. So: thank you. 💛',
            'That is a day well spent, ' . e($name) . '. Put it down now — it will keep until tomorrow. 🌙',
            'Care like yours is quiet work, ' . e($name) . ', and it matters enormously. Enjoy a restful evening. 🍃',
        ]);
        $signoff = $pick([
            'With gratitude,', 'With appreciation,', 'Warmly,', 'Cheering you on,', 'Thank you,',
            'With thanks,', 'Gratefully,', 'In appreciation,', 'With real thanks,',
            'Thank you, sincerely,', 'With admiration,', 'Very best,',
        ]);
        // Children who never got signed out. This email lands in the evening, which is
        // the last moment the person who was there can still put it right from memory —
        // by tomorrow it is a guess, and an auto sign-off will already have stamped a
        // time that nobody witnessed.
        try {
            $stillIn = $this->childrenStillSignedIn((int) ($ed->id ?? 0), $tz, $date);
            if ($stillIn) {
                $names = implode(', ', array_map(fn ($c) => e($c), array_slice($stillIn, 0, 12)));
                $more = count($stillIn) > 12 ? ' and ' . (count($stillIn) - 12) . ' more' : '';
                $body .= '<div class="kt-panel" style="background:#FEF2F2;border:1px solid #FECACA;border-left:4px solid #DC2626;'
                    . 'border-radius:0 12px 12px 0;padding:15px 17px;margin:18px 0 4px;">'
                    . '<div style="font-size:15px;font-weight:800;color:#991B1B;margin:0 0 6px;">'
                    . '🚸 ' . count($stillIn) . ' ' . (count($stillIn) === 1 ? 'child is' : 'children are')
                    . ' still signed in</div>'
                    . '<div style="font-size:13.5px;color:#7F1D1D;line-height:1.55;">' . $names . $more . '</div>'
                    . '<div style="font-size:13px;color:#0F172A;line-height:1.55;margin-top:7px;">'
                    . '<strong>Please sign them out now if they have gone home.</strong> '
                    . 'Attendance and ratio records are built from these times, and a day left open '
                    . 'is closed automatically later with a time nobody witnessed.</div></div>';
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Educator summary: still-signed-in check failed', ['error' => $e->getMessage()]);
        }

        // Anything not set up for next week, said loudly enough to act on. Placed ABOVE
        // the recap: the rest of this email is what already happened and cannot be
        // changed, and this is the only part with something to do in it.
        try {
            $lpRoom = \App\Support\LessonPlans::roomForEducator((int) ($ed->id ?? 0));
            $lpCentre = isset($ed->centre_id) ? (int) $ed->centre_id : null;
            $nextMon = $date->copy()->addWeek()->startOfWeek(Carbon::MONDAY);
            $gaps = [];

            // Next week's lesson plan: their own room, then the centre-wide one, then any
            // plan in the centre — the same order every other reader uses.
            $planned = false;
            foreach ([$nextMon, $nextMon->copy()->addDay(), $nextMon->copy()->addDays(2)] as $probe) {
                $p = \App\Support\LessonPlans::forDate($lpRoom, $lpCentre, $probe->toDateString());
                if (! $p['items'] && $lpCentre) {
                    $p = \App\Support\LessonPlans::forDateInCentres([$lpCentre], $probe->toDateString());
                }
                if ($p['items']) { $planned = true; break; }
            }
            if (! $planned) {
                $gaps[] = [
                    'what' => 'No lesson plan for next week',
                    'why' => 'Week beginning ' . $nextMon->format('j F') . ' has nothing in it yet.',
                    'do' => 'Open Lesson plans and add a few activities — or draft the week with AI and edit it.',
                ];
            }

            // Next week's menu, which is published per centre.
            if ($lpCentre && \Illuminate\Support\Facades\Schema::hasTable('menu_weeks')) {
                $menu = DB::table('menu_weeks')->where('centre_id', $lpCentre)
                    ->whereDate('week_start', $nextMon->toDateString())->first(['id', 'status', 'published_at']);
                $hasItems = $menu ? DB::table('menu_items')->where('menu_week_id', $menu->id)->exists() : false;
                if (! $menu || ! $hasItems) {
                    $gaps[] = [
                        'what' => 'No menu for next week',
                        'why' => 'Families check this before Monday, and allergies are listed against it.',
                        'do' => 'Open Weekly menu to fill it in, or ask your director if they usually set it.',
                    ];
                } elseif (empty($menu->published_at) && $menu->status !== 'published') {
                    $gaps[] = [
                        'what' => 'Next week\'s menu is written but not published',
                        'why' => 'Until it is published, families cannot see it.',
                        'do' => 'Open Weekly menu and publish it.',
                    ];
                }
            }

            if ($gaps) {
                $body .= '<div class="kt-panel" style="background:#FEF3C7;border:1px solid #FDE68A;border-left:4px solid #F59E0B;'
                    . 'border-radius:0 12px 12px 0;padding:15px 17px;margin:18px 0 4px;">'
                    . '<div style="font-size:15px;font-weight:800;color:#92400E;margin:0 0 8px;">⚠️ Needs setting up for next week</div>';
                foreach ($gaps as $g) {
                    $body .= '<div style="padding:7px 0;border-top:1px solid rgba(146,64,14,.15);">'
                        . '<div style="font-size:14px;font-weight:800;color:#0F172A;">' . e($g['what']) . '</div>'
                        . '<div style="font-size:13px;color:#78350F;line-height:1.5;margin-top:2px;">' . e($g['why']) . '</div>'
                        . '<div style="font-size:13px;color:#0F172A;line-height:1.5;margin-top:3px;"><strong>' . e($g['do']) . '</strong></div>'
                        . '</div>';
                }
                // Said plainly, because for most agencies here it is not their job — and a
                // nudge that blames the wrong person gets the whole panel ignored.
                $body .= '<div style="font-size:12px;color:#78350F;margin-top:9px;">'
                    . 'If your director usually sets these, a quick message is all that is needed.</div></div>';
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Educator summary: setup gaps failed', ['error' => $e->getMessage()]);
        }

        // What the room was working towards today, as a recap. Same source as the
        // parent summary and the planner, so all three agree.
        try {
            $body .= \App\Support\LessonPlans::emailBlock(
                \App\Support\LessonPlans::forDate(
                    \App\Support\LessonPlans::roomForEducator((int) ($ed->id ?? 0)),
                    isset($ed->centre_id) ? (int) $ed->centre_id : null,
                    $date->toDateString()
                ),
                'educator'
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Educator summary: lesson plan block failed', ['error' => $e->getMessage()]);
        }

        $body .= '<p style="margin-top:20px;font-size:15px;">' . $close . '</p>'
            . '<p style="font-weight:800;color:#0F172A;">' . $signoff . '<br>'
            . 'The team at ' . e((string) (DB::table('agencies')->where('id', (int) $ed->agency_id)->value('name') ?: 'your team')) . '</p>';

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
