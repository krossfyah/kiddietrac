<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE THREE WEBSITE DEMO LOGINS, LINKED TO EACH OTHER (2026-11-13, Anthony: "populate demo data to show customer on
 * these accounts and link them all together"). Brightwood: Megan Gonzalez (parent of James and Evelyn, Sprouts), Maya
 * Thompson (Sprouts educator), Elena Brooks (director, Long Beach) - PublicDemo::USERS.
 *
 * WebDemoContent gives the agency its photos, menus, invoices and lesson plans, and demo:seed-daily its day. Neither
 * puts these three people in touch, so a customer signing in as Megan saw no chat with her children's teacher, and
 * Maya had no shifts. This writes, for that one family and those two staff:
 *  - parent chat: Megan with Maya (and Elena) about James and Evelyn - the latest one unread by Megan;
 *  - a staff chat between Maya and Elena;
 *  - Maya's Sprouts shifts two weeks either side of today; her approved personal day;
 *  - a parent-teacher conference (Maya, with Megan and Elena) - reminders marked done, so none is ever sent;
 *  - a field trip to the Aquarium of the Pacific with permission forms for every Sprouts child (Megan's signed);
 *  - Evelyn's allergy medication and the doses Maya gave; Grandma Rosa as an authorized pickup; milestones;
 *  - the twins' last two weeks of attendance and daily notes in Sprouts (they had none since Oct 28): meals, naps,
 *    diapers, sunscreen, outdoor play, circle time;
 *  - 14 gallery photos of the twins (CC0 library, no faces) on their timelines, 8 learning stories shared with the
 *    family, and Fall report cards written by Maya, approved by Elena and sent.
 *
 * SILENT: every row is inserted directly - no mailer, listener, push or bell runs. RE-RUNNABLE: the ids it wrote are
 * kept in PlatformSettings webdemo.showcase and removed before it writes again - nothing else is ever deleted.
 *   php artisan kt:web-demo-showcase [--set=kiddietrac|white_label|all] [--dry-run]
 *
 * BOTH WEBSITE DEMOS (2026-11-26, Anthony: "under the demo agency - starlight academy make sure there is demo data for
 * playspark and any other sections that may be missing ... linked to show a demo"). The same story is written for each
 * login set in PublicDemo::sets(): 'kiddietrac' = Brightwood (agency 25, ledger webdemo.showcase) and 'white_label' =
 * Starlight Kids Academy (agency 26, ledger webdemo.showcase.white_label). Both agencies are test agencies: nothing
 * here can send. Added the same day, for both:
 *  - Play Spark: two of Maya's ideas with their end-result drawings (fixed assets in resources/demo/play-spark, no
 *    personal data, never an AI call), one shared to the agency library and a favourite; one "We did it" run that put
 *    the activity on the children's day and left draft observations, exactly as PlaySparkController::done does;
 *  - quick notes (Maya's private one, Elena's shared centre note with a reminder, a note linked to Evelyn, Olivia's
 *    private one) and tasks (Elena -> Maya, Olivia's private to-do);
 *  - bell rows so each login has something in the notifications list: Megan's arrivals, moments, observation, photo;
 *    Maya's new task; Elena's time-off request; Olivia's task - inserted directly, so nothing rings or is sent;
 *  - Safe Arrival history for Sprouts over the last four weekdays, read off the room's REAL demo sign-ins: a late
 *    arrival (alert, then the sign-in), a family's "running late" notice, an absence the parent answered (with the
 *    child_absences row) and an all-clear day; every check already resolved, so the live run never touches them;
 *  - tour bookings (completed, confirmed, requested) and agency waitlist families - fictional, .example addresses.
 * NIGHTLY (routes/console.php, after kt:reset-web-demo): the ledger makes a rerun replace, never pile up.
 */
final class WebDemoShowcase
{
    private const KEY = 'webdemo.showcase';

    private array $made = [];
    private string $key = self::KEY;
    private Carbon $today;
    private string $tz = 'America/Los_Angeles';

    public static function run(bool $dry = false, string $set = 'kiddietrac'): array
    {
        return (new self())->build($dry, $set);
    }

    private function add(string $table, array $row): int
    {
        $id = (int) DB::table($table)->insertGetId($row);
        $this->made[$table][] = $id;
        return $id;
    }

    /** A wall-clock time at the centre $days from today, as the UTC string that gets stored. */
    private function at(int $days, int $h, int $m = 0): string
    {
        return $this->today->copy()->addDays($days)->setTime($h, $m)->utc()->format('Y-m-d H:i:s');
    }

    /** The last $n weekdays before today, oldest first, as day offsets (negative). */
    private function pastWeekdays(int $n): array
    {
        $out = [];
        for ($d = -1; count($out) < $n && $d > -40; $d--) {
            if (! $this->today->copy()->addDays($d)->isWeekend()) $out[] = $d;
        }
        return array_reverse($out);
    }

    private function nextWeekday(int $from, int $dow): int
    {
        for ($d = $from; $d < $from + 14; $d++) if ($this->today->copy()->addDays($d)->dayOfWeekIso === $dow) return $d;
        return $from;
    }

    public function build(bool $dry, string $set = 'kiddietrac'): array
    {
        $U = PublicDemo::sets()[$set] ?? null;
        abort_unless(is_array($U), 500, 'Unknown website demo set: ' . $set);
        [$megan, $maya, $elena] = [(int) $U['parent']['id'], (int) $U['educator']['id'], (int) $U['director']['id']];
        $olivia = isset($U['admin']['id']) ? (int) $U['admin']['id'] : 0;
        $this->key = $set === 'kiddietrac' ? self::KEY : self::KEY . '.' . $set;
        $fam = (int) DB::table('guardians')->where('user_id', $megan)->value('family_id');
        $family = DB::table('families')->where('id', $fam)->first();
        $agencyId = (int) DB::table('role_assignments')->where('user_id', $elena)->where('role', 'centre_director')->value('agency_id');
        abort_unless($family && $agencyId && PublicDemo::isAgency($agencyId), 500, 'The website demo family / agency was not found.');
        // Belt and braces for the nightly run: only ever a TEST agency (they send nothing - TestAgency::blocks).
        abort_unless(TestAgency::isAgency($agencyId), 500, 'The website demo story only runs in a test agency.');
        $centreId = (int) $family->centre_id;
        $kids = DB::table('children')->where('family_id', $fam)->whereNull('deleted_at')->orderBy('first_name')->get(['id', 'first_name']);
        $james = $kids->firstWhere('first_name', 'James');
        $evelyn = $kids->firstWhere('first_name', 'Evelyn');
        abort_unless($james && $evelyn, 500, 'James and Evelyn were not found.');
        $roomId = (int) DB::table('enrollments')->where('child_id', $james->id)->whereNull('end_date')->value('room_id');
        $this->tz = AgencyTime::tzForCentre($centreId) ?: $this->tz;
        $this->today = Carbon::now($this->tz)->startOfDay();

        $old = json_decode((string) PlatformSettings::get($this->key, '{}'), true) ?: [];
        if ($dry) {
            return ['set' => $set, 'agency' => $agencyId, 'would_remove' => array_map('count', $old), 'family' => $fam, 'children' => [$james->id, $evelyn->id], 'room' => $roomId, 'centre' => $centreId];
        }

        $area = $set === 'white_label' ? '619' : '562';   // San Diego / Long Beach
        [$tripName, $tripWhere] = $set === 'white_label'
            ? ['Birch Aquarium at Scripps', '2300 Expedition Way, La Jolla, CA 92037']
            : ['Aquarium of the Pacific', '100 Aquarium Way, Long Beach, CA 90802'];

        DB::transaction(function () use ($old, $megan, $maya, $elena, $olivia, $fam, $agencyId, $centreId, $james, $evelyn, $roomId, $area, $tripName, $tripWhere, $set) {
            // The drawings' PNG copies (email) written last time go with their rows.
            if (! empty($old['play_spark_pictures'])) {
                foreach (DB::table('play_spark_pictures')->whereIn('id', $old['play_spark_pictures'])->pluck('png_path') as $png) {
                    if ($png && str_starts_with((string) $png, 'play-spark-pictures/' . $agencyId . '/')) @unlink(storage_path('app/private/' . $png));
                }
            }
            // Pictures someone added to a demo note go with the note (files and rows).
            if (! empty($old['quick_notes'])) \App\Support\QuickNotePictures::purge($old['quick_notes']);
            // Only what this wrote last time: timeline rows first (they point at photos), then newest tables first.
            $order = array_reverse($old, true);
            if (isset($order['daily_events'])) $order = ['daily_events' => $order['daily_events']] + $order;
            foreach ($order as $table => $ids) {
                if (preg_match('/^[a-z_]+$/', $table) && $ids) DB::table($table)->whereIn('id', $ids)->delete();
            }
            $now = now()->format('Y-m-d H:i:s');
            $days = $this->pastWeekdays(10);
            // The newest day of the story is the last weekday BEFORE today: a row stamped later today (offset 0) showed
            // "6 hours from now" in the parent's chat and a future learning story until the day caught up (2026-11-26).
            $recent = (int) $days[count($days) - 1];

            // ── The twins' last two weeks in Sprouts ──
            $moods = ['happy', 'calm', 'happy', 'tired'];
            $plan = [['snack', 10, 0, 'Morning snack: apple slices and cheese, ate well'], ['meal', 12, 0, 'Lunch: chicken, rice and veggies, finished most'],
                ['nap_start', 12, 45, 'Settled for nap'], ['nap_end', 14, 30, 'Woke up rested'], ['snack', 15, 15, 'Afternoon snack: yogurt and crackers']];
            foreach ($days as $k => $d) {
                foreach ([$james, $evelyn] as $j => $c) {
                    $dayStart = $this->today->copy()->addDays($d)->utc();
                    if (DB::table('check_events')->where('child_id', $c->id)->where('event_type', 'check_in')
                        ->whereBetween('occurred_at', [$dayStart->format('Y-m-d H:i:s'), $dayStart->copy()->addDay()->format('Y-m-d H:i:s')])->exists()) continue;
                    $this->add('check_events', ['child_id' => $c->id, 'room_id' => $roomId, 'event_type' => 'check_in', 'occurred_at' => $this->at($d, 8, 5 + $j + ($k % 4) * 3),
                        'by_user_id' => $megan, 'recorded_by_id' => $maya, 'mood_at_event' => $moods[($k + $j) % 4], 'notes' => 'Dropped off by Mom', 'created_at' => $now]);
                    $this->add('check_events', ['child_id' => $c->id, 'room_id' => $roomId, 'event_type' => 'check_out', 'occurred_at' => $this->at($d, 16, 35 + $j + ($k % 3) * 4),
                        'by_user_id' => $megan, 'recorded_by_id' => $maya, 'notes' => 'Picked up by Mom', 'created_at' => $now]);
                    foreach ($plan as $p) {
                        $this->add('daily_events', ['child_id' => $c->id, 'room_id' => $roomId, 'event_type' => $p[0], 'occurred_at' => $this->at($d, $p[1], $p[2]),
                            'payload' => '{}', 'notes' => $p[3], 'recorded_by_id' => $maya, 'created_at' => $now, 'updated_at' => $now]);
                    }
                }
            }

            // ── Parent chat: Megan with Maya, about each child ──
            $chat = function (object $child, array $lines) use ($centreId, $fam, $megan) {
                $last = end($lines)[0];
                $cid = $this->add('conversations', ['centre_id' => $centreId, 'family_id' => $fam, 'child_id' => $child->id, 'subject' => $child->first_name,
                    'channel' => null, 'last_message_at' => $last, 'created_at' => $lines[0][0]]);
                $n = count($lines);
                foreach ($lines as $i => [$when, $who, $body]) {
                    $fromFamily = $who === $megan;
                    $this->add('messages', ['conversation_id' => $cid, 'sender_id' => $who, 'body' => $body, 'created_at' => $when,
                        'read_at' => $fromFamily ? Carbon::parse($when)->addMinutes(6)->format('Y-m-d H:i:s') : null,
                        // The family has read everything but the newest message from the centre: one unread to show.
                        'family_read_at' => $fromFamily || $i === $n - 1 ? null : Carbon::parse($when)->addMinutes(20)->format('Y-m-d H:i:s'),
                        'delivered_at' => $when, 'is_system' => 0, 'is_emergency' => 0]);
                }
            };
            $chat($james, [
                [$this->at(-4, 7, 52), $megan, 'Good morning Maya! James had a rough night with his molars, so he might need a few extra cuddles today.'],
                [$this->at(-4, 8, 14), $maya, 'Thanks for letting me know, Megan. His teether is in the fridge and I\'ll send you an update at lunch 💛'],
                [$this->at(-4, 12, 38), $maya, 'Update: James ate all of his lunch and is fast asleep. Much happier boy this morning!'],
                [$this->at(-4, 12, 51), $megan, 'That\'s such a relief, thank you!'],
                [$this->at(-2, 16, 4), $megan, 'Is there anything we need to pack for the aquarium trip?'],
                [$this->at(-2, 16, 27), $maya, 'Just a labeled water bottle and a sun hat - we\'ll bring snacks. Both permission forms came through, thank you for signing!'],
                [$this->at($recent, 10, 12), $maya, 'James used two words together all morning today - "more blocks!" 🧱 We\'re so proud of him.'],
            ]);
            $chat($evelyn, [
                [$this->at(-1, 7, 58), $megan, 'Hi Maya, Evelyn\'s allergy medicine is in her backpack - 2.5 mL with lunch, please.'],
                [$this->at(-1, 11, 34), $maya, 'Got it! Given at 11:30 and logged in her medication record ✅'],
                [$this->at(-1, 13, 5), $elena, 'Hi Megan, I\'ve added Evelyn\'s medication form to her file. Thanks for completing it so quickly.'],
                [$this->at($recent, 15, 40), $maya, 'Evelyn painted her very first rainbow today 🌈 The photo is in the gallery!'],
            ]);

            // ── Maya's Sprouts shifts, two weeks back and two ahead; her personal day ──
            $off = $this->nextWeekday(5, 5);   // a Friday next week or the one after
            for ($d = -14; $d <= 14; $d++) {
                $day = $this->today->copy()->addDays($d);
                if ($day->isWeekend() || $d === $off) continue;
                $this->add('shifts', ['user_id' => $maya, 'room_id' => $roomId, 'starts_at' => $this->at($d, 7, 30), 'ends_at' => $this->at($d, 15, 30),
                    'role' => 'lead', 'status' => $d < 0 ? 'completed' : 'scheduled', 'created_at' => $now]);
            }
            $offDate = $this->today->copy()->addDays($off);
            $this->add('time_off_requests', ['agency_id' => $agencyId, 'user_id' => $maya, 'centre_id' => $centreId, 'request_type' => 'personal',
                'start_at' => $offDate->toDateString(), 'end_at' => $offDate->toDateString(), 'all_day' => 1, 'reason' => 'Family wedding',
                'status' => 'approved', 'decided_by_id' => $elena, 'decided_at' => $this->at(-3, 9, 20), 'decision_notes' => 'Enjoy the wedding! Sofia will cover Sprouts.',
                'created_at' => $this->at(-3, 8, 45), 'updated_at' => $this->at(-3, 9, 20)]);

            // ── Staff chat: Maya and Elena ──
            $tid = $this->add('staff_threads', ['agency_id' => $agencyId, 'title' => null, 'is_group' => 0, 'created_by' => $maya,
                'last_message_at' => $this->at(-1, 16, 12), 'created_at' => $this->at(-3, 8, 45), 'updated_at' => $this->at(-1, 16, 12)]);
            foreach ([$maya, $elena] as $p) {
                $this->add('staff_thread_participants', ['thread_id' => $tid, 'user_id' => $p, 'last_read_at' => $this->at(-1, 16, 30), 'created_at' => $this->at(-3, 8, 45), 'updated_at' => $now]);
            }
            $conf = $this->nextWeekday(1, 1);   // next Monday
            foreach ([
                [$this->at(-3, 8, 45), $maya, 'Hi Elena, could I take ' . $offDate->format('l, F j') . ' off? It\'s a family wedding. I\'ve put the request in.'],
                [$this->at(-3, 9, 21), $elena, 'Approved! I\'ll ask Sofia to cover Sprouts that day.'],
                [$this->at(-1, 15, 58), $elena, 'The Gonzalez conference is booked for ' . $this->today->copy()->addDays($conf)->format('l') . ' at 4:30. I\'ll join you in Sprouts.'],
                [$this->at(-1, 16, 12), $maya, 'Perfect, I\'ll bring James and Evelyn\'s portfolios and milestone notes.'],
            ] as [$when, $who, $body]) {
                $this->add('staff_messages', ['thread_id' => $tid, 'sender_id' => $who, 'body' => $body, 'is_system' => 0, 'is_emergency' => 0, 'created_at' => $when, 'updated_at' => $when]);
            }

            // ── Parent-teacher conference ──
            $start = $this->at($conf, 16, 30);
            $mid = $this->add('meetings', ['scope' => 'agency', 'agency_id' => $agencyId, 'centre_id' => $centreId,
                'title' => 'Parent-teacher conference: James & Evelyn', 'description' => 'Fall check-in on James and Evelyn\'s progress, milestones and goals for winter.',
                'location' => 'Sprouts room', 'starts_at' => $start, 'ends_at' => $this->at($conf, 17, 0), 'all_day' => 0, 'timezone' => $this->tz,
                'video' => 'none', 'join_url' => null, 'organizer_id' => $maya, 'status' => 'scheduled', 'sequence' => 0,
                'created_at' => $this->at(-1, 15, 50), 'updated_at' => $this->at(-1, 15, 50)]);
            foreach ([[$megan, 'parent'], [$elena, 'staff']] as [$uid, $kind]) {
                $u = DB::table('users')->where('id', $uid)->first(['first_name', 'last_name', 'email']);
                $this->add('meeting_attendees', ['meeting_id' => $mid, 'user_id' => $uid, 'email' => $u->email, 'name' => trim($u->first_name . ' ' . $u->last_name),
                    'kind' => $kind, 'optional' => 0, 'response' => 'accepted', 'responded_at' => $this->at(-1, 18, 2), 'invited_at' => $this->at(-1, 15, 50),
                    // Marked as already reminded: a demo meeting never sends the 30-minute reminder (MeetingReminders).
                    'reminded_for' => $start, 'reminder_result' => 'demo: no reminder', 'created_at' => $this->at(-1, 15, 50), 'updated_at' => $now]);
            }

            // ── Field trip: permission forms for the whole room ──
            $trip = $this->nextWeekday(6, 4);   // a Thursday about a week out
            $ftid = $this->add('field_trips', ['agency_id' => $agencyId, 'centre_id' => $centreId, 'title' => $tripName,
                'destination' => $tripWhere, 'trip_date' => $this->today->copy()->addDays($trip)->toDateString(),
                'depart_time' => '09:30:00', 'return_time' => '12:30:00', 'description' => 'Sprouts visit the touch pools and the shark lagoon. Please pack a labeled water bottle and a sun hat.',
                'transport_method' => 'bus', 'distance_km' => 6.5, 'staff_lead_id' => $maya, 'cost_per_child' => 12.00,
                'permission_deadline' => $this->today->copy()->addDays($trip - 2)->toDateString(), 'status' => 'planned', 'created_by_id' => $elena,
                'created_at' => $this->at(-5, 10, 0), 'updated_at' => $this->at(-5, 10, 0)]);
            $roomKids = DB::table('enrollments as e')->join('children as ch', 'ch.id', '=', 'e.child_id')->where('e.room_id', $roomId)->whereNull('e.end_date')
                ->whereNull('ch.deleted_at')->orderBy('ch.id')->get(['ch.id', 'ch.family_id'])->unique('id')->values();
            foreach ($roomKids as $i => $c) {
                $mine = (int) $c->family_id === $fam;
                $signed = $mine || $i % 4 !== 3;
                $g = $mine ? $megan : (int) DB::table('guardians')->where('family_id', $c->family_id)->whereNotNull('user_id')->value('user_id');
                $this->add('field_trip_permissions', ['field_trip_id' => $ftid, 'child_id' => $c->id, 'guardian_user_id' => $g ?: null,
                    'status' => $signed ? 'approved' : 'pending', 'responded_at' => $signed ? $this->at($mine ? -2 : -4, 19, 10 + $i) : null,
                    'notes' => $mine ? 'Happy to chaperone if you need an extra adult!' : null, 'created_at' => $this->at(-5, 10, 0), 'updated_at' => $now]);
            }

            // ── Evelyn's allergy medication and the doses Maya gave ──
            $medId = $this->add('medications', ['child_id' => $evelyn->id, 'centre_id' => $centreId, 'name' => 'Children\'s cetirizine (Zyrtec)', 'strength' => '1 mg/mL',
                'route' => 'oral', 'dosage' => '2.5 mL', 'frequency' => 'Once daily with lunch', 'reason' => 'Seasonal allergies',
                'starts_on' => $this->today->copy()->addDays(-12)->toDateString(), 'expires_on' => $this->today->copy()->addDays(78)->toDateString(),
                'special_instructions' => 'Give with food. Call Mom if Evelyn has hives or trouble breathing.', 'requires_refrigeration' => 0,
                'storage_location' => 'Sprouts medication cabinet', 'authorized_by_id' => $megan, 'authorized_at' => $this->at(-12, 7, 50),
                'is_prescription' => 0, 'status' => 'active', 'created_by_id' => $megan, 'created_at' => $this->at(-12, 7, 50), 'updated_at' => $this->at(-12, 7, 50)]);
            foreach ($days as $d) {
                if ($d < -12) continue;
                $this->add('medication_logs', ['medication_id' => $medId, 'child_id' => $evelyn->id, 'centre_id' => $centreId, 'administered_at' => $this->at($d, 11, 30),
                    'dose_given' => '2.5 mL', 'outcome' => 'given', 'notes' => 'Given with lunch', 'administered_by_id' => $maya,
                    'parent_notified_at' => $this->at($d, 11, 31), 'created_at' => $this->at($d, 11, 31), 'updated_at' => $this->at($d, 11, 31)]);
            }

            // ── Grandma Rosa can pick up ──
            foreach ([$james, $evelyn] as $c) {
                $this->add('pickup_authorizations', ['child_id' => $c->id, 'family_id' => $fam, 'full_name' => 'Rosa Gonzalez', 'relationship' => 'Grandmother',
                    'phone' => $area . '-555-0140', 'notes' => 'Picks up on Wednesdays.', 'active' => 1, 'created_by_user_id' => $megan,
                    'created_at' => $this->at(-20, 19, 0), 'updated_at' => $this->at(-20, 19, 0)]);
            }

            // ── Milestones, as Maya recorded them ──
            foreach ([
                [$james, 'Expression', 'tod_x_2_words', 'Combines two words to make a phrase', 'achieved', 0, 'Said "more blocks" and "bye Mama" this week.'],
                [$james, 'Engagement', 'tod_e_pretend_play', 'Begins simple pretend play', 'in_progress', -3, 'Feeds the baby doll in the home corner.'],
                [$james, 'Wellbeing', 'tod_w_uses_cup', 'Drinks from an open cup', 'achieved', -8, null],
                [$evelyn, 'Belonging', 'tod_b_parallel_play', 'Engages in parallel play', 'achieved', -2, 'Happily paints next to her friends at the easel.'],
                [$evelyn, 'Wellbeing', 'tod_w_hand_wash', 'Attempts hand-washing routine', 'in_progress', -5, 'Needs a reminder to use soap.'],
                [$evelyn, 'Expression', 'tod_x_names_objects', 'Names 5+ familiar objects', 'achieved', -9, null],
            ] as [$c, $domain, $key, $label, $status, $d, $note]) {
                $this->add('milestone_records', ['child_id' => $c->id, 'recorded_by_id' => $maya, 'framework' => 'ELOF', 'domain' => $domain, 'milestone_key' => $key,
                    'milestone_label' => $label, 'status' => $status, 'observed_at' => $this->today->copy()->addDays($d)->toDateString(), 'notes' => $note,
                    'created_at' => $this->at($d, 15, 0), 'updated_at' => $this->at($d, 15, 0)]);
            }

            /* ── Photo consent for the centre's (fictional) families ──
               A group photo reaches a family only if every OTHER child in it is cleared for group photos
               (App\Support\MediaConsent), and no demo family had answered - so Megan's gallery was empty although 16
               photos showed her twins. Each child's primary parent is recorded at "group" (marketing no), inserted
               directly (no staff bell), and only where that parent has not answered: a visitor's own answer stands. */
            $centreKids = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->where('f.centre_id', $centreId)
                ->whereNull('c.deleted_at')->pluck('c.id')->map(fn ($v) => (int) $v)->all();
            foreach (MediaConsent::decidersFor($centreKids) as $cid => $parents) {
                foreach ($parents as $pid) {
                    if (DB::table('child_media_consents')->where('child_id', $cid)->where('user_id', $pid)->exists()) continue;
                    $this->add('child_media_consents', ['child_id' => $cid, 'user_id' => $pid, 'level' => MediaConsent::GROUP, 'marketing' => 0,
                        'source' => 'demo', 'recorded_by_id' => $pid, 'note' => 'Demo family', 'created_at' => $this->at(-30, 18, 0), 'updated_at' => $this->at(-30, 18, 0)]);
                }
            }

            // ── Photos of the twins' days (the CC0 library in /storage/photos/web-demo), each on their timeline too ──
            $twins = [(int) $james->id, (int) $evelyn->id];
            $friends = $roomKids->pluck('id')->map(fn ($v) => (int) $v)->reject(fn ($v) => in_array($v, $twins, true))->values()->all();
            $shots = [
                ['bricks', 'Building the tallest tower we could! 🧱', 'both', 9, 40],
                ['food-mac-cheese', 'Lunch today: mac and cheese with peas. Clean plates all around!', 'both', 12, 10],
                ['puzzle', 'Puzzle time - James found every corner piece', 'james', 10, 15],
                ['watercolours', 'Watercolor rainbows 🌈', 'evelyn', 9, 50],
                ['bubble', 'Bubble chasing on the patio', 'both', 10, 45],
                ['ride-on-yellow', 'Racing the ride-ons on the bike path', 'james', 10, 35],
                ['teddy', 'Teddy bear picnic in the reading nook 🧸', 'evelyn', 15, 20],
                ['food-yogurt-berries', 'Afternoon snack: yogurt and berries', 'both', 15, 10],
                ['sandbox-slide', 'Sandbox and slide adventures', 'both', 10, 30],
                ['egg-shakers', 'Music time with egg shakers 🎶', 'evelyn', 9, 35],
                ['playdough', 'Rolling play dough snakes', 'james', 15, 30],
                ['food-pancakes', 'Pancake morning for our helpers', 'both', 8, 40],
                ['garden-visitors', 'Bug hunt in the garden with magnifiers 🐞', 'both', 10, 50],
                ['xylophone', 'Evelyn played a song on the xylophone for her friends', 'evelyn', 9, 45],
            ];
            $nd = count($days);
            foreach ($shots as $i => [$img, $caption, $who, $h, $m]) {
                $d = $days[min($nd - 1, intdiv($i * $nd, count($shots)))];
                $subjects = $who === 'both' ? $twins : [$who === 'james' ? $twins[0] : $twins[1]];
                $tagged = array_merge($subjects, array_slice($friends, $i % max(1, count($friends)), 2));
                $when = $this->at($d, $h, $m);
                $pid = $this->add('photos', ['centre_id' => $centreId, 'uploaded_by_id' => $maya, 'url' => '/storage/photos/web-demo/' . $img . '.jpg',
                    'thumbnail_url' => '/storage/photos/web-demo/' . $img . '-thumb.jpg', 'media_type' => 'image', 'caption' => $caption,
                    'child_ids' => json_encode($tagged), 'taken_at' => $when, 'created_at' => $when]);
                foreach ($subjects as $cid) {
                    $this->add('daily_events', ['child_id' => $cid, 'room_id' => $roomId, 'event_type' => 'note', 'occurred_at' => $when,
                        'payload' => json_encode(['kind' => 'media', 'media_type' => 'photo', 'photo_id' => $pid, 'note' => $caption]),
                        'notes' => $caption, 'photo_id' => $pid, 'recorded_by_id' => $maya, 'created_at' => $when, 'updated_at' => $when]);
                }
            }

            // ── The rest of a toddler's day: arrival mood, circle time, sunscreen, outdoor play, diapers ──
            $circle = ['Circle time: weather song and counting friends', 'Story time: "Brown Bear, Brown Bear"', 'Circle time: naming feelings with puppets',
                'Music and movement: freeze dance', 'Story time: "The Very Hungry Caterpillar"'];
            $outside = ['Outdoor play: sandbox and bikes (45 min)', 'Outdoor play: bubbles and chalk (40 min)', 'Garden walk and bug hunt (30 min)'];
            $arrive = ['Happy and chatty at drop-off', 'A little sleepy at drop-off, perked up with breakfast', 'Ran straight to the block corner!'];
            foreach ($days as $k => $d) {
                foreach ([$james, $evelyn] as $j => $c) {
                    foreach ([
                        ['mood', 8, 20, $arrive[($k + $j) % 3], '{}'],
                        ['activity', 9, 30, $circle[$k % 5], '{}'],
                        ['diaper', 9, 15, 'Diaper change: wet', '{"type":"wet"}'],
                        ['sunscreen', 10, 20, 'Sunscreen applied before outdoor play', '{}'],
                        ['outdoor', 10, 30, $outside[($k + $j) % 3], '{}'],
                        ['diaper', 11, 45, ($k + $j) % 3 === 0 ? 'Diaper change: BM' : 'Diaper change: wet', ($k + $j) % 3 === 0 ? '{"type":"bm"}' : '{"type":"wet"}'],
                        ['diaper', 14, 40, 'Diaper change: wet', '{"type":"wet"}'],
                    ] as [$type, $h, $m, $note, $payload]) {
                        $this->add('daily_events', ['child_id' => $c->id, 'room_id' => $roomId, 'event_type' => $type, 'occurred_at' => $this->at($d, $h, $m + $j),
                            'payload' => $payload, 'notes' => $note, 'recorded_by_id' => $maya, 'created_at' => $now, 'updated_at' => $now]);
                    }
                }
            }

            // ── Learning stories (observations), shared with the family ──
            foreach ([
                [$james, 'language_literacy', 'Asked for "more blocks" at the building table', 'James has started joining two words to ask for what he wants. Today he held out his hands and said "more blocks!", then "big tower!" when it grew taller than him.', 'James is putting two words together to tell us what he wants. Ask him about his "big tower" at home!', -1],
                [$james, 'physical', 'Pedaled the ride-on all the way around the bike path', 'With lots of concentration, James pushed with both feet and steered the ride-on around the whole loop for the first time. He waved at Ms. Maya at every turn.', 'James rode all the way around the bike path today - his balance is getting stronger every week.', -3],
                [$james, 'cognitive', 'Matched every corner piece of the farm puzzle', 'James turned the pieces to find the straight edges and placed all four corners before filling in the middle. He named the cow, pig and horse as he went.', 'James solved the farm puzzle and named the animals - a great problem-solving moment.', -6],
                [$james, 'social_emotional', 'Comforted a friend who missed her mom', 'When a classmate was crying at drop-off, James brought her his favorite teddy and sat beside her until she smiled.', 'James showed real kindness today by sharing his teddy with a sad friend.', -8],
                [$evelyn, 'creative_arts', 'Painted her first rainbow', 'Evelyn chose red, yellow and blue and painted careful arches across the page. She told us "rainbow, like outside!" and asked to hang it on the wall.', 'Evelyn painted her very first rainbow - it is hanging in Sprouts for everyone to see.', 0],
                [$evelyn, 'creative_arts', 'Kept the beat on the xylophone', 'During music time Evelyn tapped the xylophone in time with the song and then played a "song" of her own for her friends, who clapped for her.', 'Evelyn played a little song on the xylophone for her friends today.', -2],
                [$evelyn, 'social_emotional', 'Took turns at the easel', 'Evelyn waited for a friend to finish, then said "my turn?" and handed the brush back when she was done. Big growth in sharing this month!', 'Evelyn is learning to take turns - she shared the easel beautifully today.', -5],
                [$evelyn, 'language_literacy', 'Named all the animals in "Brown Bear, Brown Bear"', 'Evelyn turned the pages herself and named every animal before we read it: bear, bird, duck, horse, frog, cat and dog.', 'Evelyn can name every animal in "Brown Bear, Brown Bear" - try reading it together at home.', -7],
            ] as [$c, $domain, $title, $body, $family, $d]) {
                $d = $d ?: $recent;
                $this->add('observations', ['child_id' => $c->id, 'framework' => 'HDLH', 'domain' => $domain, 'title' => $title, 'body' => $body,
                    'observed_at' => $this->at($d, 10, 20), 'recorded_by_id' => $maya, 'shared_with_family' => 1, 'family_summary' => $family,
                    'ai_generated' => 0, 'educator_reviewed_at' => $this->at($d, 15, 45), 'created_at' => $this->at($d, 15, 45)]);
            }

            // ── Fall report cards: written by Maya, approved by Elena, sent to Megan ──
            foreach ([
                [$james, [
                    'belonging' => 'James has built warm relationships with his teachers and friends in Sprouts. He greets everyone by name at drop-off and often checks that his sister has arrived too.',
                    'wellbeing' => 'James eats well, naps soundly and is confident on the climber and the ride-ons. He is starting to wash his hands with a reminder.',
                    'engagement' => 'James loves construction and puzzles. He stays with a block tower for long stretches and solves simple puzzles by turning the pieces.',
                    'expression' => 'James now combines two words ("more blocks", "bye Mama") and joins in songs with actions. He loves "The Wheels on the Bus".',
                ], "1. Keep building two-word phrases into short sentences.\n2. Practice taking turns with a timer in the block corner.\n3. Continue independent hand-washing with picture prompts."],
                [$evelyn, [
                    'belonging' => 'Evelyn has settled beautifully into Sprouts. She has a few close friends she seeks out at the art table and she loves helping set out snack cups.',
                    'wellbeing' => 'Evelyn is healthy and active. Her allergy medicine is given with lunch each day and she has had no reactions at the center.',
                    'engagement' => 'Evelyn is a focused artist who will paint for twenty minutes at a time. She is curious about mixing colors and asks "what happens?"',
                    'expression' => 'Evelyn names familiar objects and animals, keeps a steady beat in music time and tells us stories about her paintings.',
                ], "1. Offer more color-mixing experiments to build on her curiosity.\n2. Encourage turn-taking at the easel and the sensory table.\n3. Introduce simple counting songs during music time."],
            ] as [$c, $n, $next]) {
                $this->add('report_cards', ['child_id' => $c->id, 'term' => 'Fall 2026', 'generated_by_user_id' => $maya,
                    'narrative_belonging' => $n['belonging'], 'narrative_wellbeing' => $n['wellbeing'], 'narrative_engagement' => $n['engagement'],
                    'narrative_expression' => $n['expression'], 'narratives' => json_encode($n), 'framework' => 'HDLH', 'next_steps' => $next,
                    'status' => 'sent', 'sent_at' => $this->at(-1, 16, 0), 'submitted_at' => $this->at(-3, 15, 30), 'submitted_by_user_id' => $maya,
                    'approved_by_user_id' => $elena, 'approved_at' => $this->at(-2, 11, 0),
                    'created_at' => $this->at(-4, 14, 0), 'updated_at' => $this->at(-1, 16, 0)]);
            }

            // ── Play Spark: two of Maya's ideas with their end-result drawings; one "We did it" ──
            $psDir = resource_path('demo/play-spark');
            $ps = [];
            foreach ([['chalk-color-walk', 1, 1, -6], ['tube-letter-hunt', 0, 0, -2]] as [$slug, $shared, $fav, $made]) {
                $j = json_decode((string) @file_get_contents($psDir . '/' . $slug . '.json'), true);
                if (! is_array($j) || empty($j['idea'])) continue;
                $when = $this->at($made, 10, 5);
                $iid = $this->add('play_spark_ideas', ['agency_id' => $agencyId, 'centre_id' => $centreId, 'room_id' => $roomId, 'user_id' => $maya,
                    'title' => $j['title'], 'idea' => json_encode($j['idea'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'inputs' => json_encode($j['inputs'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'safety_level' => $j['safety_level'] ?? 'ok',
                    'favourite' => $fav, 'rating' => $fav ? 5 : null, 'shared' => $shared, 'times_done' => 0, 'last_done_at' => null,
                    'created_at' => $when, 'updated_at' => $when]);
                $png = null;
                if (is_file($psDir . '/' . $slug . '.png')) {
                    $rel = 'play-spark-pictures/' . $agencyId . '/idea-' . $iid . '.png';
                    $abs = storage_path('app/private/' . $rel);
                    if (! is_dir(dirname($abs))) @mkdir(dirname($abs), 0775, true);
                    if (@copy($psDir . '/' . $slug . '.png', $abs)) $png = $rel;
                }
                $this->add('play_spark_pictures', ['agency_id' => $agencyId, 'kind' => 'idea', 'ref' => (string) $iid, 'status' => 'ready',
                    'source' => json_encode($j['picture_source'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'svg' => (string) @file_get_contents($psDir . '/' . $slug . '.svg'), 'png_path' => $png, 'tries' => 1, 'error' => null,
                    'created_at' => $when, 'updated_at' => $when]);
                $ps[] = ['id' => $iid, 'j' => $j];
            }
            if ($ps) {
                // "We did it" on the last weekday, as PlaySparkController::done writes it: the day log, draft observations, the run.
                $run = $ps[0];
                $dd = end($days);
                $doneAt = $this->at($dd, 10, 40);
                $runKids = array_values(array_unique(array_merge([(int) $james->id, (int) $evelyn->id], array_slice($friends, 0, 2))));
                $body = $run['j']['idea'];
                try { $fwKey = (string) (LearningFrameworks::forAgency($agencyId)['key'] ?? 'HDLH'); } catch (\Throwable $e) { $fwKey = 'HDLH'; }
                $evs = [];
                $obsIds = [];
                $note = 'Everyone found the red circle first!';
                foreach ($runKids as $cid) {
                    $evs[] = $this->add('daily_events', ['child_id' => $cid, 'room_id' => $roomId, 'event_type' => 'activity', 'occurred_at' => $doneAt,
                        'payload' => json_encode(['play_spark_id' => $run['id'], 'title' => $body['title'], 'home_idea' => $body['home_idea'] ?? null, 'domains' => $body['domains'] ?? []], JSON_UNESCAPED_UNICODE),
                        'notes' => '✨ ' . $body['title'] . ' - ' . $note, 'recorded_by_id' => $maya, 'created_at' => $doneAt, 'updated_at' => $doneAt]);
                    $obsIds[] = $this->add('observations', ['child_id' => $cid, 'framework' => $fwKey, 'domain' => $body['domains'][0] ?? 'physical',
                        'title' => 'Play Spark: ' . $body['title'], 'body' => 'Took part in "' . $body['title'] . '". ' . $note
                            . (! empty($body['watch_for']) ? "\nLook for: " . implode(' ', $body['watch_for']) : ''),
                        'raw_text' => $note, 'observed_at' => $doneAt, 'recorded_by_id' => $maya, 'media_ids' => '[]', 'hdlh_milestones' => '[]',
                        'shared_with_family' => 0, 'ai_generated' => 0, 'created_at' => $doneAt]);
                }
                $this->add('play_spark_runs', ['idea_id' => $run['id'], 'agency_id' => $agencyId, 'centre_id' => $centreId, 'room_id' => $roomId, 'user_id' => $maya,
                    'child_ids' => json_encode($runKids), 'event_ids' => json_encode($evs), 'observation_ids' => json_encode($obsIds), 'note' => $note,
                    'done_at' => $doneAt, 'created_at' => $doneAt]);
                DB::table('play_spark_ideas')->where('id', $run['id'])->update(['times_done' => 1, 'last_done_at' => $doneAt]);
            }

            // ── Quick notes and tasks ──
            $soon = $this->nextWeekday(2, 5);   // a Friday in the next week or so
            foreach ([
                [$maya, 'private', null, null, null, 'Aquarium trip', 'Pack spare sun hats and the first-aid bag. Ask Megan if she can chaperone.', 'yellow', 1, null, -1],
                [$elena, 'agency', $centreId, null, null, 'Fire drill', 'Fire drill at 10:00. Sprouts and Seedlings go out the garden door; take the attendance tablets.', 'red', 1, $this->today->copy()->addDays($soon)->toDateString(), -2],
                [$maya, 'agency', $centreId, 'child', (int) $evelyn->id, 'Evelyn: allergy medicine', 'Cetirizine 2.5 mL with lunch. Bottle is in her backpack; Mom refills on Mondays.', 'blue', 0, null, -3],
                [$olivia, 'private', null, null, null, 'December', 'Check the December tuition run and the holiday closure notice before the 1st.', 'purple', 0, $this->today->copy()->addDays(3)->toDateString(), -1],
            ] as [$owner, $vis, $cen, $lt, $lid, $title, $text, $colour, $pin, $remind, $d]) {
                if (! $owner) continue;
                $this->add('quick_notes', ['agency_id' => $agencyId, 'centre_id' => $cen, 'owner_user_id' => $owner, 'visibility' => $vis, 'title' => $title,
                    'body' => $text, 'colour' => $colour, 'pinned' => $pin, 'linked_type' => $lt, 'linked_id' => $lid, 'remind_on' => $remind,
                    'updated_by' => $owner, 'created_at' => $this->at($d, 16, 10), 'updated_at' => $this->at($d, 16, 10)]);
            }
            $taskTitle = 'Add this week\'s photos to the Sprouts portfolios';
            $this->add('tasks', ['agency_id' => $agencyId, 'centre_id' => $centreId, 'assigned_to' => $maya, 'assigned_by' => $elena, 'is_private' => 0,
                'title' => $taskTitle, 'description' => 'Three photos per child, before the parent-teacher conferences.', 'priority' => 'normal',
                'due_date' => $this->today->copy()->addDays(3)->toDateString(), 'status' => 'open', 'created_at' => $this->at(-1, 9, 15), 'updated_at' => $this->at(-1, 9, 15)]);
            if ($olivia) {
                $this->add('tasks', ['agency_id' => $agencyId, 'centre_id' => null, 'assigned_to' => $olivia, 'assigned_by' => $olivia, 'is_private' => 1,
                    'title' => 'Approve the December menu', 'description' => null, 'priority' => 'normal',
                    'due_date' => $this->today->copy()->addDays(1)->toDateString(), 'status' => 'open', 'created_at' => $this->at(-1, 8, 30), 'updated_at' => $this->at(-1, 8, 30)]);
            }

            // ── Safe Arrival history (last four weekdays, Sprouts) ──
            $saDays = array_slice($days, -4);
            $sprouts = DB::table('enrollments as e')->join('children as ch', 'ch.id', '=', 'e.child_id')->where('e.room_id', $roomId)->whereNull('e.end_date')
                ->whereNull('ch.deleted_at')->whereNotIn('ch.id', [(int) $james->id, (int) $evelyn->id])->orderBy('ch.id')
                ->get(['ch.id', 'ch.family_id', 'ch.first_name'])->unique('id')->values();
            $checkIn = function (int $cid, int $d) {
                $from = $this->today->copy()->addDays($d)->utc();
                $v = DB::table('check_events')->where('child_id', $cid)->where('event_type', 'check_in')
                    ->whereBetween('occurred_at', [$from->format('Y-m-d H:i:s'), $from->copy()->addDay()->format('Y-m-d H:i:s')])->min('occurred_at');
                return $v ? Carbon::parse($v, 'UTC')->setTimezone($this->tz) : null;
            };
            $parentOf = fn (int $famId) => (int) DB::table('guardians')->where('family_id', $famId)->whereNotNull('user_id')->orderBy('id')->value('user_id');
            $used = [];
            foreach ($saDays as $k => $d) {
                $date = $this->today->copy()->addDays($d)->toDateString();
                $in = $out = [];
                foreach ($sprouts as $c) {
                    if (isset($used[$c->id . ':' . $d])) continue;
                    $t = $checkIn((int) $c->id, $d);
                    if ($t) $in[] = [$c, $t]; else $out[] = $c;
                }
                $isLast = $k === count($saDays) - 1;
                // A late arrival: the alert went out 15 minutes after the expected time, the sign-in followed.
                if (! $isLast && $in) {
                    [$c, $t] = $in[($k * 3) % count($in)];
                    $exp = $t->copy()->subMinutes(30 + $t->minute % 5)->second(0);
                    $due = $exp->copy()->addMinutes(15);
                    $this->add('safe_arrival_checks', ['agency_id' => $agencyId, 'centre_id' => $centreId, 'room_id' => $roomId, 'child_id' => $c->id,
                        'check_date' => $date, 'expected_time' => $exp->format('H:i:s'), 'due_at' => $due->copy()->utc()->format('Y-m-d H:i:s'), 'status' => 'arrived',
                        'parents_notified_at' => $due->copy()->utc()->format('Y-m-d H:i:s'), 'parents_notified' => 1, 'staff_alerted' => 0, 'up_alerted' => 0, 'calls_placed' => 0,
                        'arrived_at' => $t->copy()->utc()->format('Y-m-d H:i:s'), 'resolved_at' => $t->copy()->addMinute()->utc()->format('Y-m-d H:i:s'), 'resolution' => 'arrived',
                        'note' => null, 'created_at' => $due->copy()->utc()->format('Y-m-d H:i:s'), 'updated_at' => $t->copy()->addMinute()->utc()->format('Y-m-d H:i:s')]);
                    $used[$c->id . ':' . $d] = 1;
                }
                // The family said "running late" before the day started: no alert, the sign-in came by the time they gave.
                if ($k === 1 && count($in) > 1) {
                    [$c, $t] = $in[(($k * 3) + 1) % count($in)];
                    if (! isset($used[$c->id . ':' . $d]) && ($pu = $parentOf((int) $c->family_id))) {
                        $by = $t->copy()->addMinutes(10 - $t->minute % 5)->second(0);
                        $told = $t->copy()->setTime(7, 10);
                        $this->add('safe_arrival_late', ['agency_id' => $agencyId, 'centre_id' => $centreId, 'child_id' => $c->id, 'kind' => 'arrival', 'late_date' => $date,
                            'arrive_by' => $by->format('H:i:s'), 'note' => 'Doctor\'s appointment first', 'source' => 'parent', 'reported_by_id' => $pu,
                            'created_at' => $told->copy()->utc()->format('Y-m-d H:i:s'), 'updated_at' => $told->copy()->utc()->format('Y-m-d H:i:s')]);
                        $used[$c->id . ':' . $d] = 1;
                    }
                }
                // Not signed in that day: the alert, the parent's answer, marked absent.
                if (! $isLast && $out && $k !== 1) {
                    $c = $out[$k % count($out)];
                    $exp = $this->today->copy()->addDays($d)->setTime(8, 30);
                    $due = $exp->copy()->addMinutes(15);
                    $ans = $due->copy()->addMinutes(6);
                    $pu = $parentOf((int) $c->family_id);
                    $this->add('safe_arrival_checks', ['agency_id' => $agencyId, 'centre_id' => $centreId, 'room_id' => $roomId, 'child_id' => $c->id,
                        'check_date' => $date, 'expected_time' => '08:30:00', 'due_at' => $due->copy()->utc()->format('Y-m-d H:i:s'), 'status' => 'absent',
                        'parents_notified_at' => $due->copy()->utc()->format('Y-m-d H:i:s'), 'parents_notified' => 1, 'staff_alerted' => 0, 'up_alerted' => 0, 'calls_placed' => 0,
                        'resolved_at' => $ans->copy()->utc()->format('Y-m-d H:i:s'), 'resolved_by_id' => $pu ?: null, 'resolution' => 'absent',
                        'parent_response' => 'absent', 'parent_response_at' => $ans->copy()->utc()->format('Y-m-d H:i:s'), 'parent_response_by_id' => $pu ?: null,
                        'note' => 'Parent replied: staying home with a cold today.',
                        'created_at' => $due->copy()->utc()->format('Y-m-d H:i:s'), 'updated_at' => $ans->copy()->utc()->format('Y-m-d H:i:s')]);
                    $this->add('child_absences', ['child_id' => $c->id, 'absent_on' => $date, 'reason' => 'sick', 'note' => 'A cold - back tomorrow.',
                        'reported_by_id' => $pu ?: null, 'created_at' => $ans->copy()->utc()->format('Y-m-d H:i:s')]);
                    $used[$c->id . ':' . $d] = 1;
                }
                // The newest day is the all-clear: everyone expected was signed in before an alert was due.
            }

            // ── Tours and the agency waitlist (fictional families; .example addresses never deliver) ──
            $em = $set === 'white_label' ? 'starlightkids.example' : 'brightwood.example';
            foreach ([
                ['Priya Shah', 'priya.shah', 14, -6, 10, 'completed', 'Loved the garden. Asked about the toddler waitlist.'],
                ['Daniel Kim', 'daniel.kim', 30, 2, 10, 'confirmed', 'Twins; wants to see the toddler room.'],
                ['Hannah Lee', 'hannah.lee', 26, 6, 15, 'requested', 'Can only come after 3 pm.'],
            ] as $i => [$name, $mail, $months, $d, $h, $status, $note]) {
                $off = $d;
                while ($this->today->copy()->addDays($off)->isWeekend()) $off += $d < 0 ? -1 : 1;   // tours are on weekdays
                $when = $this->today->copy()->addDays($off)->setTime($h, 0);
                $this->add('tour_bookings', ['agency_id' => $agencyId, 'centre_id' => $centreId, 'parent_name' => $name, 'parent_email' => $mail . '@' . $em,
                    'parent_phone' => $area . '-555-01' . (60 + $i), 'child_age_months' => $months,
                    'preferred_start_date' => $this->today->copy()->addMonths(2)->startOfMonth()->toDateString(), 'notes' => $note,
                    'tour_at' => $when->copy()->utc()->format('Y-m-d H:i:s'), 'status' => $status,
                    'created_at' => $this->at(min($d, 0) - 5, 19, 20 + $i), 'updated_at' => $this->at(min($d, 0) - 1, 9, 0)]);
            }
            foreach ([
                ['Mia Rivera', 'Carlos Rivera', 'carlos.rivera', 13, 'Infant', 1, 'Mon-Fri', 'High', 'Waiting', 'Website', 'Sibling of a former Sprouts family.'],
                ['Leo Nguyen', 'Anna Nguyen', 'anna.nguyen', 22, 'Toddler', 2, 'Mon, Wed, Fri', 'Normal', 'Contacted', 'Tour', 'Toured last week; waiting on a Sprouts spot.'],
                ['Zoe Patel', 'Ravi Patel', 'ravi.patel', 38, 'Preschool', 4, 'Mon-Fri', 'Normal', 'Waiting', 'Website', null],
            ] as $i => [$child, $parent, $mail, $months, $group, $start, $days5, $prio, $wst, $src, $note]) {
                $this->add('external_waitlist', ['agency_id' => $agencyId, 'from_centre_id' => null, 'origin' => 'kiddietrac', 'external_source' => 'kiddietrac',
                    'external_id' => 'ktwl-demo-' . $agencyId . '-' . ($i + 1), 'child_name' => $child,
                    'child_dob' => $this->today->copy()->subMonths($months)->subDays(9 * $i)->toDateString(), 'age_group' => $group, 'parent_name' => $parent,
                    'email' => $mail . '@' . $em, 'phone' => $area . '-555-01' . (70 + $i), 'desired_start' => $this->today->copy()->addMonths($start)->startOfMonth()->toDateString(),
                    'days_needed' => $days5, 'status' => $wst, 'priority' => $prio, 'source' => $src, 'position' => $i + 1, 'notes' => $note,
                    'created_at' => $this->at(-30 + 7 * $i, 20, 0), 'updated_at' => $this->at(-2 - $i, 11, 0)]);
            }

            // ── Something in each login's bell (inserted directly: nothing rings, nothing is sent) ──
            $last = end($days);
            $bell = function (int $uid, string $type, string $title, string $body, array $data, string $when, bool $read) use ($agencyId) {
                $this->add('notifications', ['user_id' => $uid, 'agency_id' => $agencyId, 'type' => $type, 'title' => $title, 'body' => $body,
                    'data' => json_encode($data + ['severity' => 'info'], JSON_UNESCAPED_UNICODE), 'read_at' => $read ? $when : null, 'created_at' => $when]);
            };
            $bell($megan, 'checkin', '👋 James arrived', 'Signed in to Sprouts', ['link' => '#today', 'child_id' => (int) $james->id], $this->at($last, 8, 6), true);
            $bell($megan, 'checkin', '👋 Evelyn arrived', 'Signed in to Sprouts', ['link' => '#today', 'child_id' => (int) $evelyn->id], $this->at($last, 8, 7), true);
            $bell($megan, 'moment', '🍽️ Meal — James', 'Lunch: chicken, rice and veggies, finished most', ['link' => '#today', 'child_id' => (int) $james->id, 'count' => 1, 'kind' => 'moments'], $this->at($last, 12, 2), true);
            if ($ps) {
                $bell($megan, 'moment', '✨ ' . $ps[0]['j']['title'] . ' — Evelyn', 'Everyone found the red circle first!', ['link' => '#today', 'child_id' => (int) $evelyn->id, 'count' => 1, 'kind' => 'moments'], $this->at($last, 10, 41), false);
            }
            $bell($megan, 'observation', '🌱 New learning observation — James', 'Asked for "more blocks" at the building table', ['link' => '#today', 'child_id' => (int) $james->id, 'count' => 1, 'kind' => 'observations'], $this->at(-1, 15, 46), false);
            $bell($megan, 'photo', 'New photo shared', 'Maya shared a photo of James and Evelyn', ['link' => '#photos'], $this->at($last, 15, 12), false);
            $bell($maya, 'task_assigned', '📋 New task from Elena', $taskTitle . ' · due ' . $this->today->copy()->addDays(3)->format('M j'), ['link' => '#my-tasks'], $this->at(-1, 9, 15), false);
            $bell($elena, 'time_off', 'New time-off request from Maya', 'personal · Family wedding', ['link' => '#time-off'], $this->at(-3, 8, 45), true);
            if ($olivia) {
                $bell($olivia, 'task_assigned', '📋 Your to-do is due tomorrow', 'Approve the December menu', ['link' => '#my-tasks'], $this->at(-1, 8, 30), false);
            }

            PlatformSettings::set($this->key, json_encode($this->made));
        });

        return array_map('count', $this->made);
    }
}
