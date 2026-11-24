<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * THE RUN-UP TO A WEBSITE MEETING (2026-10-26, Anthony: "with the booking follow up emails make them each
 * different leading up to the meeting (add the new videos and some new images/wordings to each one").
 *
 * Before this, every day until the meeting the same "Before we meet" intake email went out (and nothing at
 * all once the intake form was in). Now each morning (kt:intake-reminders, 09:00 ET) a confirmed meeting
 * gets the NEXT email of a sequence, never the same one twice (marketing_bookings.run_up_sent):
 *
 *   2+ days before  one film a day, in order: a day with KiddieTrac → Safe Arrival → One-Tap → Walk Watch
 *                   (a film whose share page is not live yet is skipped until it is)
 *   films used up   nothing more until the day before — unless the intake form is still missing, then the
 *                   original "Before we meet" intake reminder carries on daily, as before
 *   day before      "See you tomorrow": time, Teams link, agenda, how to prepare, calendar buttons
 *   meeting day     "See you today" (only when the meeting is an hour or more away at send time)
 *
 * Every one of them carries the intake "Action needed" box while the form is missing (proposal flow).
 * Behind PlatformSettings sales.booking.reminder_sequence = 1: until Anthony approves it, production keeps
 * sending exactly what it did.
 */
class BookingRunUp
{
    public const WWW = 'https://www.kiddietrac.com';

    /** The film emails, in the order they are sent. Wording is only what the product does (no trial, no extras). */
    public static function films(): array
    {
        $img = self::WWW . '/email-icons/run-up/';
        return [
            'day' => [
                'slug' => 'a-day-with-kiddietrac', 'title' => 'A day with KiddieTrac', 'length' => '1-minute',
                'subject' => 'A day with KiddieTrac, in one minute',
                'eyebrow' => 'Before we meet · A whole day in one minute',
                'headline' => 'See a whole day, from drop-off to pick-up',
                'intro' => 'Before we talk, here&rsquo;s the whole idea in one short film: how a childcare day runs when the paperwork takes care of itself &mdash; and what your team does with the time it gets back.',
                'block' => 'A day with KiddieTrac',
                'points' => [
                    ['&#127749;', 'Drop-off', 'Families check in with One-Tap and your educator sees exactly who brought each child.'],
                    ['&#128248;', 'During the day', 'Daily reports, photos and messages reach parents in the KiddieTrac app as they happen.'],
                    ['&#128179;', 'Behind the scenes', 'Invoices, autopay and subsidy tracking run on their own, so nobody chases balances.'],
                    ['&#128075;', 'Pick-up', 'Checked out in seconds, attendance and ratios always current, parents told straight away.'],
                ],
                'mark' => null, 'photo' => 'educator-child-800.jpg', 'photoAlt' => 'An educator with a child',
            ],
            'safe' => [
                'slug' => 'safe-arrival', 'title' => 'Safe Arrival', 'length' => '100-second',
                'subject' => 'Every child accounted for: meet Safe Arrival',
                'eyebrow' => 'Before we meet · Safe Arrival',
                'headline' => 'Every scheduled child, accounted for',
                'intro' => 'Safe Arrival keeps an eye on every child who is expected today. If one hasn&rsquo;t arrived and no absence was reported, it acts &mdash; so nobody on your team has to remember to.',
                'block' => 'How Safe Arrival works',
                'points' => [
                    ['1', 'Watches the day', 'Every 5 minutes it checks each child scheduled at a centre that is open today.'],
                    ['2', 'Asks the parents', 'Not signed in by drop-off time plus your grace period, and no absence reported? Parents are asked by app, email or text.'],
                    ['3', 'Calls if needed', 'Still no answer? An automated phone call to the parents, at the time you choose.'],
                    ['4', 'Alerts your team', 'The room&rsquo;s educators and the director are alerted, and the Safe Arrival board shows who still needs a call.'],
                ],
                'foot' => 'It closes itself the moment the child is signed in or an absence is reported.',
                'mark' => $img . 'mark-safe-arrival.png', 'photo' => 'parent-hand-800.jpg', 'photoAlt' => 'A parent holding a child&rsquo;s hand',
            ],
            'onetap' => [
                'slug' => 'one-tap', 'title' => 'One-Tap', 'length' => '2-minute',
                'subject' => 'Check-in in seconds, with One-Tap',
                'eyebrow' => 'Before we meet · One-Tap',
                'headline' => 'No sign-in sheet. No shared pen.',
                'intro' => 'One-Tap is KiddieTrac&rsquo;s QR contactless check-in and check-out. Parents scan your centre&rsquo;s code with their own KiddieTrac app &mdash; and your educator sees exactly who is at the door.',
                'block' => 'How One-Tap works',
                'points' => [
                    ['1', 'Today&rsquo;s code at the door', 'On a tablet or a printed One-Tap poster. It changes every day, it is signed, and only your staff can display it.'],
                    ['2', 'Parents scan with their own app', 'Checked on our servers: a photo of yesterday&rsquo;s code, or another centre&rsquo;s, is refused.'],
                    ['3', 'The right adult, verified', 'A large &ldquo;Scanned by&rdquo; card with the parent&rsquo;s photo, name and relationship &mdash; readable at arm&rsquo;s length.'],
                    ['4', 'Everyone in the loop', 'Time-stamped with who did it; the other parent and the room&rsquo;s educators are told instantly.'],
                ],
                'mark' => $img . 'mark-one-tap.png', 'photo' => 'phone-crayons-800.jpg', 'photoAlt' => 'A phone beside a box of crayons',
            ],
            'walk' => [
                'slug' => 'walk-watch', 'title' => 'Walk Watch', 'length' => '2-minute',
                'subject' => 'Out for a walk, never out of sight: Walk Watch',
                'eyebrow' => 'Before we meet · Walk Watch',
                'headline' => 'Every walk and outing, followed live',
                'intro' => 'Walk Watch is KiddieTrac&rsquo;s live GPS walks and outings. From the moment your group heads out the gate until everyone is back, parents and your director know where they are.',
                'block' => 'How Walk Watch works',
                'points' => [
                    ['1', 'One tap starts the walk', 'Your educator picks where they&rsquo;re going and ticks the children &mdash; only those checked in right now &mdash; and shares their live location.'],
                    ['2', 'Every parent is told', 'In the app, by push and by email, with a live map they can follow (updated about every 12 seconds).'],
                    ['3', 'Headcounts on the go', 'Tick each child off by photo; if anyone isn&rsquo;t seen, one tap alerts the director.'],
                    ['4', 'Back safely, on the record', 'Parents hear the group is back, and the walk goes on each child&rsquo;s daily log with the route.'],
                ],
                'mark' => $img . 'mark-walk-watch.png', 'photo' => 'chalk-outdoors-800.jpg', 'photoAlt' => 'Children drawing with chalk outdoors',
            ],
        ];
    }

    /** Test sends show every film, live or not (kt:booking-test-emails --only=sequence). */
    public static bool $allFilmsLive = false;

    public static function on(): bool
    {
        return (string) PlatformSettings::get('sales.booking.reminder_sequence', '0') === '1';
    }

    public static function watchUrl(string $key): string
    {
        return self::WWW . '/watch/' . self::films()[$key]['slug'] . '/';
    }

    public static function poster(string $key): string
    {
        return self::WWW . '/email-icons/run-up/film-' . self::films()[$key]['slug'] . '.jpg';
    }

    /** A film is used only once its share page answers 200 (Walk Watch was still being published). */
    public static function live(string $key): bool
    {
        if (self::$allFilmsLive) return true;
        return Cache::remember('booking-runup-live-' . $key, 3600, function () use ($key) {
            try { return Http::timeout(6)->head(self::watchUrl($key))->status() === 200; } catch (\Throwable $e) { return false; }
        });
    }

    public static function sent(object $b): array
    {
        $s = json_decode((string) ($b->run_up_sent ?? ''), true);
        return is_array($s) ? $s : [];
    }

    /**
     * What this booking should get this morning: [kind, step] — ['runup', 'safe'], ['tomorrow', null],
     * ['today', null], ['intake', null] (the old daily intake reminder) or null for nothing.
     */
    public static function next(object $b, bool $intakeMissing, ?Carbon $now = null): ?array
    {
        $now = $now ?: now();
        $start = Carbon::parse($b->starts_at, 'UTC');
        if ($start->lessThanOrEqualTo($now)) return null;
        $today = $now->copy()->tz(BookingMail::TZ)->startOfDay();
        $days = (int) $today->diffInDays($start->copy()->tz(BookingMail::TZ)->startOfDay(), false);
        $sent = self::sent($b);
        // The day-before and same-day emails are per meeting DATE: a meeting moved to another day gets them again.
        $date = $start->copy()->tz(BookingMail::TZ)->toDateString();
        if ($days <= 0) {
            return (! in_array('today:' . $date, $sent, true) && $start->greaterThan($now->copy()->addHour())) ? ['today', null] : null;
        }
        if ($days === 1) return in_array('tomorrow:' . $date, $sent, true) ? null : ['tomorrow', null];
        foreach (array_keys(self::films()) as $k) {
            if (! in_array($k, $sent, true) && self::live($k)) return ['runup', $k];
        }
        return ($intakeMissing && BookingMail::proposalFlow()) ? ['intake', null] : null;
    }

    /** The key recorded in run_up_sent for what was just sent. */
    public static function key(object $b, string $kind, ?string $step): string
    {
        if ($kind === 'runup') return (string) $step;
        return $kind . ':' . Carbon::parse($b->starts_at, 'UTC')->tz(BookingMail::TZ)->toDateString();
    }

    /** The plain-text part, so the run-up emails are not HTML-only (spam filters weigh that). */
    public static function text(string $kind, ?string $step, array $d): string
    {
        $l = [];
        $first = $d['first'] ? $d['first'] . ', ' : '';
        if ($kind === 'runup' && $step) {
            $f = self::films()[$step];
            $l[] = strtoupper(html_entity_decode(strip_tags($f['eyebrow'])));
            $l[] = html_entity_decode(strip_tags($f['headline']));
            $l[] = '';
            $l[] = html_entity_decode(strip_tags($f['intro']));
            $l[] = '';
            $l[] = 'Watch the film (' . $f['length'] . '): ' . self::watchUrl($step);
            $l[] = '';
            foreach ($f['points'] as $p) $l[] = '- ' . html_entity_decode(strip_tags($p[1])) . ': ' . html_entity_decode(strip_tags($p[2]));
        } else {
            $l[] = $kind === 'today' ? 'SEE YOU TODAY' : 'SEE YOU TOMORROW';
            $l[] = $first . ($kind === 'today' ? 'we\'re looking forward to meeting you today.' : 'we\'re all set for tomorrow.');
        }
        $l[] = '';
        $l[] = 'Your ' . strtolower($d['typeName']) . ': ' . $d['whenEt'] . ($d['whenLocal'] ? ' (' . $d['whenLocal'] . ')' : '');
        $l[] = $d['viaPhone'] ? 'We\'ll call you at ' . $d['phone'] . '.' : ('Join on Microsoft Teams: ' . ($d['teamsUrl'] ?: 'link in your confirmation email'));
        if (! empty($d['proposal']) && empty($d['intakeDone'])) {
            $l[] = '';
            $l[] = 'Before we meet, please tell us about your program (about five minutes): ' . $d['intakeUrl'];
        }
        $l[] = '';
        $l[] = 'Questions, or need another time? Reply to this email or call 1-855-400-9996.';
        $l[] = '';
        $l[] = 'Warm regards,';
        $l[] = $d['host'];
        $l[] = 'KiddieTrac Sales · sales@kiddietrac.com · kiddietrac.com';
        return implode("\n", $l) . "\n";
    }
}
