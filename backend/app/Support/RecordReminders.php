<?php

declare(strict_types=1);

namespace App\Support;

use App\Console\Commands\EducatorDailySummaryCommand;
use App\Services\EmailTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DAILY RECORDS REMINDERS (2026-10-15).
 *
 * Anthony: "wire up reminder emails to educators and cc admin/director about not logging
 * information such as observations, daily logs, photos/images/video capturing the moments
 * of the children for their parents ... also to include meals/lessons not scheduled and
 * add call out how many times this has been missed and is being escalated as this
 * information is needed based on the agency policy and suggest how to improve by giving
 * tips ... (randomize the tips daily endlessly)".
 *
 * One evening check per educator who WORKED that day (a punch, a child covered or
 * anything logged), on an operating day. Daily items are measured with the very numbers
 * the 7 p.m. "Your day at a glance" email shows (EducatorDailySummaryCommand::statsFor),
 * so the two emails can never disagree. Weekly items (this week's lesson plan, this
 * week's published menu) are checked against the week the day falls in.
 *
 * Settings: agencies.settings.record_reminders, edited in Settings → Room Controls →
 * Daily records. Sent and evaluated days are logged in record_reminders.
 */
final class RecordReminders
{
    public const DEFAULTS = [
        'enabled' => true,
        'send_at' => '18:30',          // agency wall clock
        'observations_min' => 1,       // per educator per day; 0 = do not check
        'logs_min' => 3,               // daily-log moments (meals, naps, toileting, activities, notes)
        'media_min' => 1,              // photos / videos shared with families
        'check_meals' => true,         // at least one meal or snack logged when children were in
        'check_lessons' => true,       // a lesson plan exists for the week
        'check_menu' => true,          // the week's menu is written and published
        'cc_managers' => true,         // copy the centre's directors and the agency admins
        'escalate_after' => 3,         // days with gaps in the window before it is "escalated"
        'window_days' => 30,
    ];

    public const ITEMS = [
        'observations' => ['Observations', 'Observations document each child\'s learning and development. They guide your programme planning and are part of the record the agency keeps for every child.'],
        'logs' => ['Daily logs', 'Meals, naps, toileting and activities are how families learn how their child\'s day went, and they form the daily care record.'],
        'media' => ['Photos and videos', 'A photo or short video of their child\'s day is the moment families look forward to most. It builds trust and keeps them connected.'],
        'meals' => ['Meals and snacks', 'What, and how much, a child ate is one of the first questions families ask, and it matters for allergies and health.'],
        'lessons' => ['This week\'s lesson plan', 'A lesson plan shows families and inspectors a planned, intentional programme.'],
        'menu' => ['This week\'s menu', 'Families read the weekly menu, and allergies are checked against it. It must be written and published.'],
    ];

    public static function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];

        return array_merge(self::DEFAULTS, is_array($s['record_reminders'] ?? null) ? $s['record_reminders'] : []);
    }

    public static function save(int $agencyId, array $in): array
    {
        $clean = [];
        foreach (self::DEFAULTS as $k => $def) {
            if (! array_key_exists($k, $in)) continue;
            if (is_bool($def)) $clean[$k] = (bool) $in[$k];
            elseif (is_int($def)) $clean[$k] = max(0, min($k === 'window_days' ? 90 : 50, (int) $in[$k]));
            elseif ($k === 'send_at' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $in[$k])) $clean[$k] = (string) $in[$k];
        }
        DB::transaction(function () use ($agencyId, $clean) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s['record_reminders'] = array_merge(self::DEFAULTS, is_array($s['record_reminders'] ?? null) ? $s['record_reminders'] : [], $clean);
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });

        return self::settings($agencyId);
    }

    /** Monday of the week $date falls in (agency calendar). */
    private static function monday(Carbon $date): string
    {
        return $date->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    /**
     * What is missing for this educator on this day.
     *
     * @return array|null null = did not work that day (nothing to check); otherwise
     *                    ['missing' => [key => [label, got, want, why]], 'stats' => [...]]
     */
    public static function evaluate(int $uid, int $centreId, string $tz, Carbon $date, array $cfg, array &$weekCache): ?array
    {
        $t = EducatorDailySummaryCommand::statsFor($uid, $tz, $date);
        if ($t['moments'] === 0 && $t['minutes'] === 0 && $t['children'] === 0) {
            return null;
        }

        // Were there children to record for? An educator on the clock with an empty room
        // is not asked for observations of nobody.
        $start = Carbon::parse($date->toDateString() . ' 00:00:00', $tz)->utc();
        $end = Carbon::parse($date->toDateString() . ' 23:59:59', $tz)->utc();
        $kids = $t['children'];
        if ($kids === 0) {
            $rooms = DB::table('educator_rooms')->where('user_id', $uid)->pluck('room_id')->all();
            $q = DB::table('check_events as e')->where('e.event_type', 'check_in')->whereBetween('e.occurred_at', [$start, $end]);
            if ($rooms) $q->whereIn('e.room_id', $rooms);
            else $q->join('children as ch', 'ch.id', '=', 'e.child_id')->join('families as f', 'f.id', '=', 'ch.family_id')->where('f.centre_id', $centreId);
            $kids = (int) $q->distinct()->count('e.child_id');
        }

        $missing = [];
        $add = function (string $key, $got, $want) use (&$missing) {
            $missing[$key] = ['label' => self::ITEMS[$key][0], 'got' => $got, 'want' => $want, 'why' => self::ITEMS[$key][1]];
        };
        if ($kids > 0) {
            if ($cfg['observations_min'] > 0 && $t['observations'] < $cfg['observations_min']) $add('observations', $t['observations'], $cfg['observations_min']);
            $logs = $t['meals'] + $t['naps'] + $t['activities'] + $t['diapers'] + $t['notes'];
            if ($cfg['logs_min'] > 0 && $logs < $cfg['logs_min']) $add('logs', $logs, $cfg['logs_min']);
            if ($cfg['media_min'] > 0 && $t['media'] < $cfg['media_min']) $add('media', $t['media'], $cfg['media_min']);
            if ($cfg['check_meals'] && $t['meals'] < 1) $add('meals', 0, 1);
        }

        $mon = self::monday($date);
        if ($cfg['check_lessons']) {
            $k = 'lp|' . $uid . '|' . $centreId . '|' . $mon;
            if (! array_key_exists($k, $weekCache)) {
                $rooms = DB::table('educator_rooms')->where('user_id', $uid)->pluck('room_id')->all();
                $weekCache[$k] = Schema::hasTable('lesson_plans') && DB::table('lesson_plans')->whereDate('week_starting', $mon)
                    ->where(function ($w) use ($rooms, $centreId) {
                        $w->where(fn ($x) => $x->where('centre_id', $centreId)->whereNull('room_id'));
                        if ($rooms) $w->orWhereIn('room_id', $rooms);
                        else $w->orWhereIn('room_id', DB::table('rooms')->where('centre_id', $centreId)->pluck('id'));
                    })->exists();
            }
            if (! $weekCache[$k]) $add('lessons', 0, 1);
        }
        if ($cfg['check_menu'] && Schema::hasTable('menu_weeks')) {
            $k = 'menu|' . $centreId . '|' . $mon;
            if (! array_key_exists($k, $weekCache)) {
                $m = DB::table('menu_weeks')->where('centre_id', $centreId)->whereDate('week_start', $mon)->first(['id', 'status', 'published_at']);
                $weekCache[$k] = $m && DB::table('menu_items')->where('menu_week_id', $m->id)->exists()
                    && (! empty($m->published_at) || $m->status === 'published');
            }
            if (! $weekCache[$k]) $add('menu', 0, 1);
        }

        return ['missing' => $missing, 'stats' => $t, 'children' => $kids];
    }

    /** Days in the window (ending on $date) this educator worked with something missing. */
    public static function history(int $uid, int $centreId, string $tz, Carbon $date, array $cfg, array &$weekCache): array
    {
        $dates = [];
        $days = max(1, (int) $cfg['window_days']);
        for ($i = 0; $i < $days; $i++) {
            $d = $date->copy()->subDays($i);
            if (! Closures::isOperatingDay($centreId, $d->toDateString())) continue;
            $ev = self::evaluate($uid, $centreId, $tz, $d, $cfg, $weekCache);
            if ($ev && $ev['missing']) $dates[] = $d->toDateString();
        }

        return ['days' => count($dates), 'dates' => $dates];
    }

    /* ── Tips: a different handful for every educator, every day, without end ──────── */

    private const TIPS = [
        'observations' => [
            'Pick one child to watch closely during free play and jot three words about what they tried. That is the start of an observation.',
            'Keep a short list of "who I have not observed this week" so every child gets a turn.',
            'Snap a photo while you watch, and write the observation from it at nap time.',
            'Link the observation to a learning area, then add one idea to extend it tomorrow.',
            'Use a voice note on your phone in the moment, and type it up when the room is calm.',
            'Two good sentences are enough: what the child did, and what it shows about their learning.',
            'Observe during routines too. How a child pours, shares or settles is development worth recording.',
            'Set a quiet goal of one observation before lunch, so the afternoon is not a scramble.',
        ],
        'logs' => [
            'Log each meal right after it is cleared, while you still remember who ate what.',
            'Record naps when children wake, not at the end of the day.',
            'Use the quick-log buttons on the roster. One tap per child is faster than a long note.',
            'Log toileting as it happens. Families notice when the record is accurate.',
            'Batch similar entries: everyone\'s snack at once, everyone\'s outdoor play at once.',
            'Write the day\'s activity once for the room, then tick the children who took part.',
            'Before you hand a child to their family, glance at their day. Anything blank is a two-second fix.',
            'A short note about mood or a funny moment makes the log feel personal to families.',
        ],
        'media' => [
            'Aim for one photo per child per day. It can be the same activity, from each child\'s angle.',
            'Capture the process, not just the finished craft: hands busy, faces concentrating.',
            'A ten-second video of a song or a first attempt is often the families\' favourite.',
            'Take photos at the start of an activity, before the room gets busy.',
            'Check consent and avoid other children\'s faces where the family has asked you to.',
            'Share photos as you go. Waiting until the end of the day is how they get forgotten.',
            'Outdoor play is full of moments. Keep your phone in your pocket, not in the cubby.',
            'Add one line under the photo so families know what they are looking at.',
        ],
        'meals' => [
            'Record meals straight after clean-up while plates are still in mind.',
            'Note how much each child ate (all, some, none). It is what families ask first.',
            'Log snacks and bottles too. They count as meals for the record.',
            'If a child refused food, say so. It helps families plan the evening.',
            'Keep the allergy list at the meal table and confirm it before you log.',
        ],
        'lessons' => [
            'Draft next week\'s lesson plan on Thursday, so Monday starts ready.',
            'Use the AI draft in Lesson plans, then make it yours with the children\'s interests.',
            'Plan from this week\'s observations. What the children loved is next week\'s theme.',
            'A short plan for each day is enough: one focus activity and one outdoor idea.',
            'Ask your director for a shared template so the plan takes ten minutes, not an hour.',
        ],
        'menu' => [
            'Fill the menu for the next week by Thursday so families see it before the weekend.',
            'Copy last week\'s menu and change what you need. It is faster than starting fresh.',
            'Publish the menu once it is written. Until then families cannot see it.',
            'Note substitutions on the menu so allergy checks stay accurate.',
        ],
    ];

    private const BUSY_OPEN = [
        'On a busy day,', 'When the room is short-staffed,', 'If the afternoon gets away from you,', 'When everyone needs you at once,',
        'On days with lots of drop-offs,', 'If you are covering another room,', 'When the weather keeps everyone inside,', 'On a hectic morning,',
        'When a child needs extra attention,', 'If transitions run long,', 'On days with a special event,', 'When you are new to the room,',
    ];
    private const BUSY_DO = [
        'log in short bursts', 'take three photos before snack', 'write one observation during outdoor play', 'use the quick-log buttons instead of notes',
        'record meals before tidying up', 'ask your co-educator to log while you lead the activity', 'set a quiet reminder on your phone for 2:30 p.m.',
        'jot notes on paper and enter them at nap time', 'focus on two children for observations and rotate tomorrow',
        'capture one group photo and tag each child', 'log naps as children wake rather than at the end', 'use voice notes and type them up later',
        'pick the five-minute wind-down to finish the day\'s entries', 'divide the room: you log meals, your partner logs naps',
    ];
    private const BUSY_WHY = [
        'so nothing piles up for the end of the day.', 'so families still get a glimpse of their child\'s day.', 'and the record stays complete without staying late.',
        'so the evening hand-over is easy.', 'which keeps your records ready for any inspection.', 'and you will leave on time.',
        'so tomorrow starts with a clean slate.', 'because a little often beats a lot at once.', 'and families will notice the difference.',
        'so the moments are captured while they happen.',
    ];

    /** @return string[] three tips: two for what was missed, one for busy days. */
    public static function tips(int $uid, Carbon $date, array $missingKeys): array
    {
        mt_srand(crc32($date->toDateString() . '|' . $uid));
        $out = [];
        $keys = $missingKeys ?: ['logs'];
        shuffle($keys);
        foreach (array_slice($keys, 0, 2) as $k) {
            $pool = self::TIPS[$k] ?? self::TIPS['logs'];
            $out[] = $pool[mt_rand(0, count($pool) - 1)];
        }
        if (count($out) < 2) {
            $pool = self::TIPS[$keys[0]] ?? self::TIPS['logs'];
            $pick = $pool[mt_rand(0, count($pool) - 1)];
            if (! in_array($pick, $out, true)) $out[] = $pick;
        }
        $out[] = self::BUSY_OPEN[mt_rand(0, count(self::BUSY_OPEN) - 1)] . ' ' . self::BUSY_DO[mt_rand(0, count(self::BUSY_DO) - 1)] . ' ' . self::BUSY_WHY[mt_rand(0, count(self::BUSY_WHY) - 1)];
        mt_srand();

        return $out;
    }

    /** @return array{0:string,1:string} [subject, html] */
    public static function email(int $agencyId, object $ed, string $place, Carbon $date, array $ev, array $hist, array $cfg, string $agencyName): array
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $day = $date->format('l, F j');
        $n = count($ev['missing']);
        $escalated = $hist['days'] >= max(1, (int) $cfg['escalate_after']);
        $ord = function (int $x): string {
            $s = ['th', 'st', 'nd', 'rd'];
            $v = $x % 100;
            return $x . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
        };

        $rows = '';
        foreach ($ev['missing'] as $key => $m) {
            $have = in_array($key, ['lessons', 'menu'], true) ? 'Not done for the week of ' . $date->copy()->startOfWeek(Carbon::MONDAY)->format('F j')
                : $m['got'] . ' recorded (expected at least ' . $m['want'] . ')';
            $rows .= '<tr><td style="padding:10px 12px;border-bottom:1px solid #E2E8F0;vertical-align:top;width:34%">'
                . '<div style="font-weight:700;color:#0F172A;font-size:14px">' . $e($m['label']) . '</div>'
                . '<div style="font-size:12.5px;color:#B45309;margin-top:2px">' . $e($have) . '</div></td>'
                . '<td style="padding:10px 12px;border-bottom:1px solid #E2E8F0;font-size:13.5px;color:#475569;line-height:1.5">' . $e($m['why']) . '</td></tr>';
        }

        $count = '<strong>' . $ord($hist['days']) . ' working day in the last ' . (int) $cfg['window_days'] . ' days</strong> with incomplete records';
        $callout = $escalated
            ? EmailTemplate::calloutBox('This is the ' . $count . '. Because these records are required under ' . $e($agencyName)
                . '\'s documentation policy, this reminder has been <strong>escalated</strong> and shared with your director and administrator.', 'warning')
            : EmailTemplate::calloutBox('This is the ' . $count . '. After ' . (int) $cfg['escalate_after'] . ' such days, reminders are escalated to your director and administrator under '
                . $e($agencyName) . '\'s documentation policy.', 'info');

        $tips = '';
        foreach (self::tips((int) $ed->id, $date, array_keys($ev['missing'])) as $t) {
            $tips .= '<li style="margin:0 0 8px">' . $e($t) . '</li>';
        }

        $body = '<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6">Dear ' . $e($ed->first_name ?: 'Educator') . ',</p>'
            . '<p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.6">Thank you for your care of the children today' . ($place ? ' at <b>' . $e($place) . '</b>' : '')
            . '. When we reviewed the records for <b>' . $e($day) . '</b>, ' . ($n === 1 ? 'one item was' : $n . ' items were') . ' not completed. Families rely on these records to share in their child\'s day, and the agency relies on them for its required documentation.</p>'
            . '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;border:1px solid #E2E8F0;border-radius:10px;margin:0 0 16px">' . $rows . '</table>'
            . $callout
            . '<p style="margin:18px 0 6px;font-size:14px;font-weight:700;color:#0F172A">Ideas for tomorrow</p>'
            . '<ul style="margin:0 0 0 18px;padding:0;font-size:14px;color:#334155;line-height:1.55">' . $tips . '</ul>'
            . '<p style="margin:16px 0 0;font-size:14px;color:#334155;line-height:1.6">If you can still add today\'s entries, please do so this evening or first thing tomorrow. '
            . 'If something made recording difficult today, please let your director know; we are here to help.</p>'
            . '<div style="margin:18px 0 0">' . EmailTemplate::button('Open KiddieTrac', 'https://app.kiddietrac.com/dashboard.html#care-log') . '</div>'
            . '<p style="margin:18px 0 0;font-size:14px;color:#334155">With thanks,<br>' . $e($agencyName) . '</p>';

        $title = $escalated ? 'Daily records: escalated' : 'Daily records reminder';
        $html = EmailTemplate::wrap($agencyId, $body, ['eyebrow' => 'DAILY RECORDS', 'title' => $title, 'subtitle' => $day . ($place ? ' · ' . $place : ''),
            'preheader' => ($n === 1 ? 'One item' : $n . ' items') . ' from ' . $day . ' still need recording.']);
        $subject = ($escalated ? 'Escalated: ' : '') . 'Daily records for ' . $date->format('D j M') . ' — ' . ($n === 1 ? '1 item needs' : $n . ' items need') . ' attention'
            . ($escalated ? ' (' . $hist['days'] . ' days in ' . (int) $cfg['window_days'] . ')' : '');

        return [$subject, $html, $escalated];
    }
}
