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
            // The sample fire plan's PNG written last time goes with its row.
            if (! empty($old['fire_plans'])) {
                foreach (DB::table('fire_plans')->whereIn('id', $old['fire_plans'])->pluck('png_path') as $png) {
                    if ($png && str_starts_with((string) $png, 'fire-plans/' . $agencyId . '/demo-')) @unlink(storage_path('app/private/' . $png));
                }
            }
            // Pictures someone added to a demo note go with the note (files and rows).
            if (! empty($old['quick_notes'])) \App\Support\QuickNotePictures::purge($old['quick_notes']);
            // Only what this wrote last time: timeline rows first (they point at photos), then newest tables first.
            $order = array_reverse($old, true);
            if (isset($order['daily_events'])) $order = ['daily_events' => $order['daily_events']] + $order;
            // HR rows hanging off this builder's own leave types (the nightly accrual can add to them) go with the types.
            if (! empty($old['hr_leave_types'])) {
                DB::table('hr_leave_ledger')->whereIn('leave_type_id', $old['hr_leave_types'])->delete();
                DB::table('hr_leave_rules')->whereIn('leave_type_id', $old['hr_leave_types'])->delete();
            }
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

            if ($set === 'white_label') $this->firePlanStory($agencyId, $centreId, $maya, $elena);
            if ($set === 'white_label') $this->hrStory($agencyId, $maya, $elena, $olivia, $now);

            PlatformSettings::set($this->key, json_encode($this->made));
        });

        return array_map('count', $this->made);
    }

    /**
     * FIRE PLAN DEMO (2026-11-28, Starlight only): one approved whole-centre fire plan Maya drew from the home template,
     * with the "what we do" steps filled and Elena's approval. Fixed drawing in resources/demo/fire-plan (drawing.json +
     * plan.png); the PNG is copied to the agency's private fire-plans folder and removed with the row on a rerun.
     */
    private function firePlanStory(int $agencyId, int $centreId, int $maya, int $elena): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('fire_plans')) return;
        $dir = resource_path('demo/fire-plan');
        $drawing = \App\Support\FirePlans::cleanDrawing((string) @file_get_contents($dir . '/drawing.json'));
        if (! $drawing) return;
        $fill = [
            's1' => ['Whoever finds it', 'Shout "Fire!", pull the nearest alarm, close the door on the fire', ''],
            's2' => ['Elena', 'Calls 911 from outside on her cell, then the agency', ''],
            's3' => ['Maya', 'Takes the attendance tablet and the red emergency bag', ''],
            's4' => ['Maya', 'Infants go in the evacuation crib; toddlers hold the walking rope', ''],
            's5' => ['Everyone', 'Walk, do not run; close doors behind you', 'Front door, down the side path'],
            's6' => ['Maya', 'Line up by room', 'The church parking lot across the street (alternate: the corner of Birch Lane)'],
            's7' => ['Elena', 'Counts every child and adult against today\'s attendance, out loud', ''],
            's8' => ['Elena', 'Last out: checks the bathroom, nap room and closets if it is safe', ''],
        ];
        $tree = \App\Support\FirePlans::template($agencyId);
        foreach ($tree['steps'] as &$st) {
            [$who, $what, $where] = $fill[$st['k']] ?? ['', '', ''];
            $st += ['who' => $who, 'what' => $what, 'where' => $where];
        }
        unset($st);
        foreach ($tree['branches'] as &$br) {
            $br += ['who' => '', 'what' => $br['after'] === 's5' ? 'Use the back door through the kitchen to the side gate' : 'Tell the firefighters where the child was last seen; never go back inside', 'where' => ''];
        }
        unset($br);
        $tree = \App\Support\FirePlans::cleanTree($tree);
        $submitted = $this->at(-41, 15, 20);
        $approved = $this->at(-40, 9, 10);
        $details = ['children_count' => 12, 'ages' => '3 infants, 5 toddlers, 4 preschool', 'staff_ids' => [$maya, $elena], 'staff_other' => '',
            'exits_count' => 2, 'reviewed_on' => substr($approved, 0, 10), 'notes' => 'Practised monthly. The side gate code is on the key ring in the emergency bag.'];
        $id = $this->add('fire_plans', ['agency_id' => $agencyId, 'centre_id' => $centreId, 'room_id' => null, 'version' => 1, 'status' => 'approved',
            'title' => null, 'drawing' => json_encode($drawing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'png_path' => null, 'underlay_path' => null,
            'tree' => json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'based_on_id' => null, 'drill_id' => null, 'created_by' => $maya, 'submitted_by' => $maya, 'submitted_at' => $submitted,
            'reviewed_by' => $elena, 'reviewed_at' => $approved, 'review_comment' => null, 'created_at' => $submitted, 'updated_at' => $approved]);
        if (is_file($dir . '/plan.png')) {
            $rel = 'fire-plans/' . $agencyId . '/demo-' . $id . '.png';
            $abs = storage_path('app/private/' . $rel);
            if (! is_dir(dirname($abs))) @mkdir(dirname($abs), 0775, true);
            if (@copy($dir . '/plan.png', $abs)) DB::table('fire_plans')->where('id', $id)->update(['png_path' => $rel]);
        }
    }

    /**
     * KIDDIETRAC HR DEMO (2026-11-27, Starlight only): leave types and rules, every staff member's employee file, this
     * leave year's grants, past and upcoming approved leave (Sofia off today, Marcus later this week), two requests
     * waiting, a manual adjustment with its reason, and Lucas's three single sick days so the absence report flags
     * repeated short absences. All rows go in the ledger of this builder, so a rerun replaces them.
     */
    private function hrStory(int $agencyId, int $maya, int $elena, int $olivia, string $now): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('hr_leave_ledger')) return;
        HrLeave::saveSettings($agencyId, ['leave_year' => 'fixed', 'leave_year_start' => '01-01', 'allow_negative' => false, 'hours_per_day' => 8,
            'short_absence' => ['max_days' => 2, 'count' => 3, 'months' => 6]]);
        $types = [];
        foreach (HrLeave::EXAMPLE_SET as $i => $e) {
            $rules = $e['rules'];
            unset($e['rules']);
            $id = $this->add('hr_leave_types', $e + ['periods_per_year' => null, 'hours_per_unit' => null, 'carry_cap' => null,
                'carry_expiry_months' => null, 'agency_id' => $agencyId, 'is_example' => 0, 'sort' => $i + 1,
                'created_by_id' => $olivia ?: null, 'created_at' => $now, 'updated_at' => $now]);
            if ($e['name'] === 'Vacation') $rules[] = ['employment_type' => 'part_time', 'min_years' => 0, 'amount' => 5];
            foreach ($rules as $r) $this->add('hr_leave_rules', $r + ['agency_id' => $agencyId, 'leave_type_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
            $types[$e['maps_request_type']] = (object) ($e + ['id' => $id]);
        }
        $staff = HrLeave::staffIds($agencyId);
        $centreOf = DB::table('role_assignments')->whereIn('user_id', $staff ?: [0])->where('active', 1)->whereNotNull('centre_id')->pluck('centre_id', 'user_id');
        $users = DB::table('users')->whereIn('id', $staff ?: [0])->get(['id', 'first_name', 'hire_date'])->keyBy('id');
        $byName = $users->mapWithKeys(fn ($u) => [$u->first_name => (int) $u->id]);
        $titles = ['Harper' => ['Executive Director', 'full_time'], 'Olivia' => ['Operations Manager', 'full_time'], 'Elena' => ['Center Director', 'full_time'],
            'Maya' => ['Lead Teacher', 'full_time'], 'Ethan' => ['Lead Teacher', 'full_time'], 'Grace' => ['Lead Teacher', 'full_time'], 'Noah' => ['Teacher', 'full_time'],
            'Marcus' => ['Teacher', 'full_time'], 'Sofia' => ['Assistant Teacher', 'part_time'], 'Lucas' => ['Assistant Teacher', 'part_time'],
            'Ava' => ['Teacher Aide', 'casual'], 'Nina' => ['Assistant Teacher', 'student']];
        $etype = [];
        foreach ($users as $u) {
            [$title, $et] = $titles[$u->first_name] ?? ['Teacher', 'full_time'];
            $etype[$u->id] = $et;
            if (DB::table('hr_employee_files')->where('agency_id', $agencyId)->where('user_id', $u->id)->exists()) continue;
            $mine = (int) ($centreOf[$u->id] ?? 0);
            $mgr = in_array($u->first_name, ['Harper', 'Olivia'], true) ? null
                : (($mine && $mine === (int) ($centreOf[$elena] ?? -1) && (int) $u->id !== $elena) ? $elena : ($olivia ?: null));
            $this->add('hr_employee_files', ['agency_id' => $agencyId, 'user_id' => $u->id, 'job_title' => $title, 'employment_type' => $et, 'status' => 'active',
                'manager_user_id' => $mgr, 'primary_centre_id' => $centreOf[$u->id] ?? null, 'updated_by_id' => $olivia ?: null, 'created_at' => $now, 'updated_at' => $now]);
        }
        // This leave year's grants, keyed like the nightly job's, so hr:leave-accrue finds them and adds nothing.
        $today = $this->today->toDateString();
        $s = HrLeave::settings($agencyId);
        foreach ($users as $u) {
            $hire = $u->hire_date ? substr((string) $u->hire_date, 0, 10) : null;
            $ys = HrLeave::yearStart($agencyId, (int) $u->id, $today, $s, $hire);
            foreach ($types as $t) {
                if ($t->accrual !== 'lump') continue;
                [$amt, $pro] = HrLeave::grantAmount($t, $etype[$u->id] ?? null, $hire, $ys);
                if (! $amt) continue;
                $this->add('hr_leave_ledger', ['agency_id' => $agencyId, 'user_id' => $u->id, 'leave_type_id' => $t->id, 'entry_date' => max($ys, $hire ?: $ys),
                    'amount' => $amt, 'kind' => 'grant', 'source_key' => 'grant:' . $ys,
                    'reason' => 'Leave year from ' . Carbon::parse($ys)->format('M j, Y') . ($pro ? ' (prorated from the start date)' : ''), 'created_at' => $now]);
            }
        }
        // A weekday at or after $from days from today.
        $wd = function (int $from) {
            for ($d = $from; $d < $from + 7; $d++) if (! $this->today->copy()->addDays($d)->isWeekend()) return $d;
            return $from;
        };
        $req = function (string $who, string $type, int $d1, int $d2, string $status, string $reason, ?string $note = null, bool $review = false) use ($agencyId, $byName, $centreOf, $types, $elena, $now) {
            $uid = $byName[$who] ?? null;
            if (! $uid || ! isset($types[$type])) return;
            $t = $types[$type];
            $start = $this->today->copy()->addDays($d1)->toDateString();
            $end = $this->today->copy()->addDays($d2)->toDateString();
            $amt = (float) count(HrLeave::workDays($agencyId, (int) $uid, isset($centreOf[$uid]) ? (int) $centreOf[$uid] : null, $start, $end));
            if ($amt <= 0) return;
            $decided = $status === 'pending' ? null : $this->at(min(-1, $d1 - 7), 9, 30);
            $asked = $this->at(min(-2, $d1 - 9), 8, 10);
            $rid = $this->add('time_off_requests', ['agency_id' => $agencyId, 'user_id' => $uid, 'centre_id' => $centreOf[$uid] ?? null, 'request_type' => $type,
                'leave_type_id' => $t->id, 'leave_amount' => $amt, 'start_at' => $start, 'end_at' => $end, 'all_day' => 1, 'reason' => $reason, 'status' => $status,
                'decided_by_id' => $status === 'pending' ? null : ($t->needs_approval ? $elena : $uid), 'decided_at' => $decided, 'decision_notes' => $note,
                'created_at' => $asked, 'updated_at' => $decided ?: $asked]);
            // Sick days need no approval: the latest one is still waiting for Elena's review.
            if ($review && \Illuminate\Support\Facades\Schema::hasColumn('time_off_requests', 'review_pending')) {
                DB::table('time_off_requests')->where('id', $rid)->update(['review_pending' => 1]);
            }
            if ($status === 'approved') {
                $this->add('hr_leave_ledger', ['agency_id' => $agencyId, 'user_id' => $uid, 'leave_type_id' => $t->id, 'entry_date' => substr((string) $decided, 0, 10),
                    'amount' => -$amt, 'kind' => 'taken', 'time_off_request_id' => $rid, 'reason' => 'Time off approved', 'created_by_id' => $elena, 'created_at' => $now]);
            }
        };
        $past = $this->pastWeekdays(25);   // oldest first
        $ago = fn (int $k) => (int) $past[max(0, count($past) - 1 - $k)];   // $ago(0) = the last weekday before today
        $req('Sofia', 'vacation', 0, 2, 'approved', 'Visiting family in Phoenix', 'Have a lovely trip!');
        $req('Marcus', 'personal', $wd(3), $wd(3), 'approved', 'Moving apartments');
        $req('Noah', 'vacation', $wd(21), $wd(21) + 4, 'pending', 'Winter break with my kids');
        $req('Ethan', 'vacation', $ago(6), $ago(6) + 2, 'approved', 'Long weekend away', 'Grace will cover Rainbows.');
        $req('Grace', 'sick', $ago(9), $ago(9), 'approved', 'Migraine');
        $req('Lucas', 'sick', $ago(2), $ago(2), 'approved', 'Cold', null, true);
        $req('Lucas', 'sick', $ago(11), $ago(11), 'approved', 'Stomach bug');
        $req('Lucas', 'sick', $ago(20), $ago(20), 'approved', 'Fever');
        $req('Ava', 'personal', $wd(10), $wd(10), 'pending', 'Dentist appointment');
        // Maya's personal day (the request written above) counts against her Personal days.
        $mayaOff = DB::table('time_off_requests')->whereIn('id', $this->made['time_off_requests'] ?? [0])->where('user_id', $maya)
            ->whereNull('leave_type_id')->where('status', 'approved')->first();
        if ($mayaOff && isset($types['personal'])) {
            $this->add('hr_leave_ledger', ['agency_id' => $agencyId, 'user_id' => $maya, 'leave_type_id' => $types['personal']->id, 'entry_date' => substr((string) $mayaOff->decided_at, 0, 10),
                'amount' => -1, 'kind' => 'taken', 'time_off_request_id' => $mayaOff->id, 'reason' => 'Time off approved', 'created_by_id' => $elena, 'created_at' => $now]);
        }
        if (isset($byName['Ethan'], $types['vacation'])) {
            $this->add('hr_leave_ledger', ['agency_id' => $agencyId, 'user_id' => $byName['Ethan'], 'leave_type_id' => $types['vacation']->id,
                'entry_date' => $this->today->copy()->addDays($ago(15))->toDateString(), 'amount' => 2, 'kind' => 'adjustment',
                'reason' => 'Two days for covering the summer camp weekend, agreed with Olivia', 'created_by_id' => $olivia ?: $elena, 'created_at' => $now]);
        }
        $this->hrTalentStory($agencyId, $byName->all(), $centreOf->all(), $maya, $elena, $olivia, $now);
        $this->hrSafetyStory($agencyId, $byName->all(), $centreOf->all(), $elena, $olivia, $now);
        $this->hrClaimsStory($agencyId, $byName->all(), $centreOf->all(), $elena, $olivia, $now);   // HR Phase 3, item 9
        $this->hrRotaStory($agencyId, $byName->all(), $centreOf->all(), $elena, $now);   // HR Phase 3, item 10
        $this->hrClockStory($agencyId, $byName->all(), $centreOf->all());   // HR Phase 3, item 11
    }

    /**
     * KIDDIETRAC HR PHASE 3 DEMO (2026-11-26, Starlight only): staff injuries and near misses. Marcus slipped on a wet
     * kitchen floor two days ago and saw a doctor (board report still to file, modified duties, one check-in); Ethan's
     * loose shelf bracket was a near miss (closed); Grace's paper cut from last month needed only first aid (closed).
     * Health details go in through the sealed columns like any other write. All rows in this builder's ledger.
     */
    private function hrSafetyStory(int $agencyId, array $byName, array $centreOf, int $elena, int $olivia, string $now): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('hr_injuries')) return;
        $local = fn (int $days, int $h, int $m) => $this->today->copy()->addDays($days)->setTime($h, $m)->format('Y-m-d H:i:s');
        $d = fn (int $days) => $this->today->copy()->addDays($days)->toDateString();
        $boss = $olivia ?: $elena;
        $rows = [
            ['Marcus', 'injury', -2, 15, 40, 'Kitchen, Starlight Learning Center', 'Ethan', 'Slipped on water by the dishwasher while carrying the lunch trays and landed on the left wrist.',
                'Left wrist', 'Sprain, swelling', 'Ice pack, wrist kept raised, incident logged', 'doctor', 'Walk-in clinic the same afternoon: sprain, brace for two weeks', true, null, null,
                [-1, 12], 'No lifting over 5 kg, no diaper changes; reading corner, sign-in desk and planning time.', 'open', [[-1, 'Brace on, managing the sign-in desk well. Wrist sore by the end of the day.']]],
            ['Ethan', 'near_miss', -6, 10, 15, 'Rainbows room', null, 'A wall shelf bracket came loose while Ethan was lifting the paint bins down. Nobody was hurt.',
                null, null, null, 'none', null, false, null, null, null, null, 'closed', []],
            ['Grace', 'injury', -24, 9, 30, 'Art table, Sunflowers room', 'Noah', 'Cut a finger on a box cutter while opening a supply box.',
                'Right index finger', 'Small cut', 'Cleaned and bandaged from the first aid kit', 'none', null, false, null, null, null, null, 'closed', []],
        ];
        foreach ($rows as [$who, $kind, $ago, $h, $m, $where, $wit, $what, $part, $nature, $aid, $care, $notes, $lost, $filed, $ref, $rtw, $duties, $status, $checks]) {
            $uid = $byName[$who] ?? null;
            if (! $uid) continue;
            $id = $this->add('hr_injuries', ['agency_id' => $agencyId, 'centre_id' => isset($centreOf[$elena]) ? (int) $centreOf[$elena] : null, 'user_id' => $uid,   // all at Elena's centre (the location text names it)
               
                'reported_by_id' => $kind === 'near_miss' ? $uid : $boss, 'kind' => $kind, 'occurred_at' => $local($ago, $h, $m), 'learned_on' => $d($ago),
                'location' => $where, 'witnesses' => $wit ? ($byName[$wit] ?? null ? $wit : null) : null, 'what_happened' => $what, 'body_part' => $part, 'injury_nature' => $nature,
                'first_aid' => $aid, 'medical_care' => $care, 'medical_notes' => $notes, 'lost_time' => $lost ? 1 : 0,
                'form7_needed' => $kind === 'injury' && ($care !== 'none' || $lost) ? 1 : 0, 'form7_filed_on' => $filed, 'form7_reference' => $ref,
                'rtw_start' => $rtw ? $d($rtw[0]) : null, 'rtw_end' => $rtw ? $d($rtw[1]) : null, 'rtw_duties' => $duties, 'status' => $status,
                'created_at' => $this->at($ago, $h + 1, 0), 'updated_at' => $now]);
            foreach ($checks as [$on, $note]) {
                $this->add('hr_injury_checkins', ['agency_id' => $agencyId, 'injury_id' => $id, 'on_date' => $d($on), 'note' => $note, 'by_id' => $elena, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    /**
     * KIDDIETRAC HR PHASE 3, ITEM 9 DEMO (2026-11-26, Starlight only): expense and mileage claims in every state, so
     * HR > Claims, My HR, the claims report and the payroll run all have something to show. A $0.70/km rate is set
     * only when the agency has none. Receipts are small generated images at FIXED paths (rewritten, never piled up);
     * the rows are in this builder's ledger, so a rerun replaces them. Nothing is sent.
     */
    private function hrClaimsStory(int $agencyId, array $byName, array $centreOf, int $elena, int $olivia, string $now): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('hr_claims')) return;
        $s = \App\Support\HrClaims::settings($agencyId);
        if (! $s['km_rate']) \App\Support\HrClaims::saveSettings($agencyId, ['km_rate' => 0.7, 'categories' => $s['categories'], 'receipt_required' => true]);
        $rate = \App\Support\HrClaims::settings($agencyId)['km_rate'];
        $d = fn (int $days) => $this->today->copy()->addDays($days)->toDateString();
        $receipt = function (string $key, string $shop, string $total) use ($agencyId): ?string {
            if (! function_exists('imagecreatetruecolor')) return null;
            $im = imagecreatetruecolor(360, 520);
            imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
            $ink = imagecolorallocate($im, 30, 41, 59);
            imagestring($im, 5, 24, 30, $shop, $ink);
            imagestring($im, 3, 24, 70, 'RECEIPT (DEMO)', $ink);
            imagestring($im, 3, 24, 110, 'Items ........................', $ink);
            imagestring($im, 5, 24, 440, 'TOTAL  $' . $total, $ink);
            ob_start(); imagepng($im); $png = (string) ob_get_clean(); imagedestroy($im);
            $path = 'hr-claims/' . $agencyId . '/demo/receipt-' . $key . '.png';
            \Illuminate\Support\Facades\Storage::disk('local')->put($path, $png);
            return $path;
        };
        // [who, kind, days ago, category, description, amount, tax, km, from, to, status, decided by, decision days ago, comment]
        $rows = [
            ['Maya', 'expense', -1, 'Classroom supplies', 'Paint, glue sticks and card stock for the winter art week', 38.50, 3.18, null, null, null, 'submitted', null, null, null],
            ['Sofia', 'mileage', -2, null, 'Picked up the field trip snacks', 0, 0, 18.4, 'Starlight Learning Center', 'Costco, Mission Valley', 'submitted', null, null, null],
            ['Lucas', 'expense', -6, 'Field trips and outings', 'Parking at the aquarium trip', 24.00, 0, null, null, null, 'approved', 'elena', -5, null],
            ['Marcus', 'mileage', -4, null, 'Drove the spare cots to the second centre', 0, 0, 9.2, 'Starlight Learning Center', 'Starlight North', 'approved', 'olivia', -3, null],
            ['Noah', 'expense', -10, 'Food and snacks', 'Lunch on the training day', 15.75, 1.30, null, null, null, 'declined', 'elena', -9, 'Lunch on a training day is not covered. Thanks for checking!'],
            ['Ava', 'expense', -24, 'Training and courses', 'Infant CPR refresher course', 120.00, 0, null, null, null, 'paid', 'elena', -22, null],
        ];
        foreach ($rows as [$who, $kind, $ago, $cat, $desc, $amt, $tax, $km, $from, $to, $status, $by, $byAgo, $comment]) {
            $uid = $byName[$who] ?? null;
            if (! $uid) continue;
            if ($kind === 'mileage') { $amt = round($km * $rate, 2); $tax = 0; }
            $total = round($amt + $tax, 2);
            $path = $kind === 'expense' ? $receipt(strtolower($who), strtoupper($cat === 'Training and courses' ? 'Red Cross Training' : ($cat === 'Classroom supplies' ? 'Craft Depot' : 'Harbor Parking')), number_format($total, 2)) : null;
            $byId = $by === 'elena' ? $elena : ($by === 'olivia' ? ($olivia ?: $elena) : null);
            $this->add('hr_claims', ['agency_id' => $agencyId, 'centre_id' => isset($centreOf[$uid]) ? (int) $centreOf[$uid] : null, 'user_id' => $uid, 'kind' => $kind,
                'claim_date' => $d($ago), 'category' => $kind === 'mileage' ? 'Mileage' : $cat, 'description' => $desc, 'amount' => $amt, 'tax' => $tax, 'total' => $total,
                'km' => $km, 'km_rate' => $kind === 'mileage' ? $rate : null, 'from_place' => $from, 'to_place' => $to,
                'receipt_path' => $path, 'receipt_name' => $path ? 'receipt.png' : null, 'receipt_mime' => $path ? 'image/png' : null,
                'status' => $status, 'decided_by_id' => $byId, 'decided_at' => $byId ? $this->at($byAgo, 10, 0) : null, 'decision_comment' => $comment,
                'paid_at' => $status === 'paid' ? $this->at(-14, 9, 0) : null, 'created_at' => $this->at($ago, 17, 30), 'updated_at' => $now]);
        }
    }

    /**
     * KIDDIETRAC HR PHASE 3 DEMO, ITEM 11 (2026-11-26, Starlight only): clock-in rules. The agency flags (never blocks)
     * clock-ins away from the centre, the San Diego centre has a 150 m area, and this week's latest clock-in of Noah
     * (away, 1.2 km) and of Lucas (no location shared) show on HR > Rota. Only set when not already set.
     */
    private function hrClockStory(int $agencyId, array $byName, array $centreOf): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('time_punches', 'geo_status')) return;
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true);
        if (! is_array($s['hr_clock'] ?? null)) \App\Support\HrClock::saveSettings($agencyId, ['geofence' => 'flag', 'overtime_on' => true, 'overtime_hours' => 44, 'warn_at_hours' => 40, 'skip_contractors' => true]);
        $centre = isset($byName['Noah'], $centreOf[$byName['Noah']]) ? (int) $centreOf[$byName['Noah']] : null;
        if (! $centre) return;
        $c = DB::table('centres')->where('id', $centre)->first();
        if (! \App\Support\HrClock::fence($c)) \App\Support\HrClock::saveFence($centre, ['radius_m' => 150, 'lat' => $c->latitude !== null ? null : 32.7157, 'lng' => $c->latitude !== null ? null : -117.1611]);
        $from = \App\Support\AgencyTime::boundaryUtc($agencyId, 'week')->format('Y-m-d H:i:s');
        foreach ([['Noah', 'outside', 1240], ['Lucas', 'no_location', null]] as [$who, $st, $m]) {
            if (! isset($byName[$who])) continue;
            $id = DB::table('time_punches')->where('user_id', $byName[$who])->where('punched_in_at', '>=', $from)->orderByDesc('punched_in_at')->value('id');
            if ($id) DB::table('time_punches')->where('id', $id)->whereNull('geo_status')->update(['geo_status' => $st, 'geo_distance_m' => $m]);
        }
    }

    /**
     * KIDDIETRAC HR PHASE 3 DEMO, ITEM 10 (2026-11-26, Starlight only): the rota planning view. This week and next on the
     * San Diego centre for Lucas, Sofia, Noah, Ethan and Ava (wall-clock times, as the Schedule screen stores them), hourly
     * pay rates where none is set (Elena salaried), Sofia available Monday to Thursday only (her Friday shifts show the
     * conflict), Noah can't work next Wednesday, Lucas giving away next Tuesday (open) and Ethan / Noah swapping next
     * Thursday and Friday (accepted, waiting for approval). Rooms the five cannot cover show as ratio warnings.
     */
    private function hrRotaStory(int $agencyId, array $byName, array $centreOf, int $elena, string $now): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('hr_shift_swaps')) return;
        $who = ['Lucas' => [23.00, [7, 0, 15, 0]], 'Sofia' => [25.75, [7, 0, 15, 0]], 'Noah' => [22.50, [10, 0, 18, 0]], 'Ethan' => [23.75, [8, 0, 16, 0]], 'Ava' => [26.00, [10, 0, 18, 0]]];
        $centre = null;
        foreach ($who as $n => $_) if (isset($byName[$n], $centreOf[$byName[$n]])) { $centre = (int) $centreOf[$byName[$n]]; break; }
        if (! $centre) return;
        $rooms = DB::table('rooms')->where('centre_id', $centre)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
        if (! $rooms) return;
        foreach ($who as $n => [$rate]) {
            if (isset($byName[$n])) DB::table('users')->where('id', $byName[$n])->whereNull('pay_rate')->whereNull('pay_type')->update(['pay_rate' => $rate, 'pay_type' => 'hourly']);
        }
        if ($elena) DB::table('users')->where('id', $elena)->whereNull('pay_rate')->whereNull('pay_type')->update(['pay_type' => 'salary']);
        $mon = $this->today->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
        $ids = [];   // name|offset => shift id
        $i = 0;
        foreach ($who as $n => [$rate, $hm]) {
            $uid = $byName[$n] ?? null;
            if (! $uid || (int) ($centreOf[$uid] ?? 0) !== $centre) continue;
            $room = (int) (DB::table('educator_rooms')->where('user_id', $uid)->whereIn('room_id', $rooms)->value('room_id') ?: $rooms[$i % count($rooms)]);
            $i++;
            for ($o = 0; $o < 12; $o++) {
                if ($o % 7 > 4) continue;
                $date = $mon->copy()->addDays($o)->toDateString();
                $ids[$n . '|' . $o] = $this->add('shifts', ['user_id' => $uid, 'room_id' => $room, 'starts_at' => sprintf('%s %02d:%02d:00', $date, $hm[0], $hm[1]),
                    'ends_at' => sprintf('%s %02d:%02d:00', $date, $hm[2], $hm[3]), 'role' => 'lead', 'status' => $date < $this->today->toDateString() ? 'completed' : 'scheduled', 'created_at' => $now]);
            }
        }
        if (isset($byName['Sofia'])) {
            foreach ([1, 2, 3, 4] as $dow) {
                $this->add('hr_availability', ['agency_id' => $agencyId, 'user_id' => $byName['Sofia'], 'dow' => $dow, 'start_time' => '07:00:00', 'end_time' => '15:00:00', 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        if (isset($byName['Noah'])) {
            $wed = $mon->copy()->addDays(9)->toDateString();
            $this->add('hr_unavailability', ['agency_id' => $agencyId, 'user_id' => $byName['Noah'], 'date_from' => $wed, 'date_to' => $wed, 'reason' => 'Medical appointment', 'created_at' => $now, 'updated_at' => $now]);
        }
        if (isset($ids['Lucas|8'])) {
            $this->add('hr_shift_swaps', ['agency_id' => $agencyId, 'centre_id' => $centre, 'shift_id' => $ids['Lucas|8'], 'from_user_id' => $byName['Lucas'], 'kind' => 'giveaway',
                'status' => 'open', 'note' => 'Family wedding out of town', 'created_at' => $now, 'updated_at' => $now]);
        }
        if (isset($ids['Ethan|10'], $ids['Noah|11'])) {
            $this->add('hr_shift_swaps', ['agency_id' => $agencyId, 'centre_id' => $centre, 'shift_id' => $ids['Ethan|10'], 'from_user_id' => $byName['Ethan'], 'kind' => 'swap',
                'to_user_id' => $byName['Noah'], 'swap_shift_id' => $ids['Noah|11'], 'accepted_by_id' => $byName['Noah'], 'accepted_at' => $now, 'status' => 'accepted',
                'note' => 'Swapping so I can take my son to his game', 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * KIDDIETRAC HR PHASE 2 DEMO (2026-11-28, Starlight only): two open job posts on the jobs page with four applicants
     * at different stages (one with an interview booked), Nina mid-onboarding (3 of 6 tasks done, one overdue), Maya's
     * annual review in progress (her self review in, Elena's part drafted) with two goals and check-ins and a one-to-one,
     * and two courses with assignments: Maya finished Safe sleep (certificate + PD hours), Lucas is overdue.
     * Inserted directly, all in this builder's ledger: nothing sends, a rerun gives the same rows.
     */
    private function hrTalentStory(int $agencyId, array $byName, array $centreOf, int $maya, int $elena, int $olivia, string $now): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('hr_course_assignments')) return;
        $centre = (int) ($centreOf[$elena] ?? 0) ?: null;
        $nina = $byName['Nina'] ?? null;
        $boss = $olivia ?: $elena;
        $d = fn (int $days) => $this->today->copy()->addDays($days)->toDateString();
        \App\Support\HrTalent::saveSettings($agencyId, ['keep_months' => 24, 'reply_to' => null,
            'apply_intro' => "We are a warm, play-based early learning centre in San Diego. We look for kind, curious people who love being with young children.\nPaid planning time, PD days and a team that has your back."]);

        // ── Hiring ──
        $p1 = $this->add('hr_job_posts', ['agency_id' => $agencyId, 'centre_id' => $centre, 'title' => 'Preschool Teacher', 'employment_type' => 'full_time',
            'pay_min' => 21, 'pay_max' => 25, 'pay_unit' => 'hour', 'status' => 'open', 'created_by_id' => $elena, 'created_at' => $this->at(-12, 9, 0), 'updated_at' => $this->at(-12, 9, 0),
            'description' => "Lead play-based learning for our Rainbows room (ages 3 to 5) with a co-teacher.\n\nYou will plan activities from the children's interests, write learning observations in KiddieTrac and keep families in the loop.\n\nWhat you bring: an ECE permit or degree, CPR and first aid (or willing to get them), and a love of messy play."]);
        $p2 = $this->add('hr_job_posts', ['agency_id' => $agencyId, 'centre_id' => $centre, 'title' => 'Infant Room Assistant', 'employment_type' => 'part_time',
            'pay_min' => 18, 'pay_max' => 20, 'pay_unit' => 'hour', 'status' => 'open', 'created_by_id' => $elena, 'created_at' => $this->at(-6, 10, 0), 'updated_at' => $this->at(-6, 10, 0),
            'description' => "Mornings, Monday to Friday, in our Sprouts infant room.\n\nFeeding, naps, diapering and lots of floor time, alongside our lead teacher. Training provided."]);
        $role = ['Preschool Teacher', 'Infant Room Assistant'];
        $apps = [
            [$p1, 'Jordan', 'Reyes', 'new', -1, 'I have taught preschool for three years and love building forts with four-year-olds.'],
            [$p1, 'Priya', 'Natarajan', 'reviewing', -5, 'Montessori-trained, bilingual in English and Tamil.'],
            [$p1, 'Daniel', 'Kim', 'interview', -9, null],
            [$p2, 'Sam', 'Ortiz', 'offer', -4, 'Two years as a nanny for twins, CPR certified.'],
        ];
        foreach ($apps as [$post, $fn, $ln, $status, $ago, $cover]) {
            $at = $this->at($ago, 14, 20);
            $meeting = null;
            if ($status === 'interview' && \Illuminate\Support\Facades\Schema::hasTable('meetings')) {
                $start = $this->today->copy()->addDays(2)->setTime(10, 0);
                $meeting = $this->add('meetings', array_intersect_key([
                    'scope' => 'agency', 'agency_id' => $agencyId, 'title' => 'Interview: ' . $fn . ' ' . $ln . ' (' . $role[$post === $p1 ? 0 : 1] . ')',
                    'starts_at' => $start->copy()->utc()->format('Y-m-d H:i:s'), 'ends_at' => $start->copy()->addMinutes(45)->utc()->format('Y-m-d H:i:s'),
                    'timezone' => $this->tz, 'all_day' => 0, 'location' => 'Starlight Kids Academy, front office', 'video' => 'none', 'status' => 'scheduled',
                    'organizer_id' => $elena, 'created_by_id' => $elena, 'created_at' => $this->at(-2, 11, 0), 'updated_at' => $this->at(-2, 11, 0),
                ], array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('meetings'))));
            }
            $aid = $this->add('career_applications', ['agency_id' => $agencyId, 'job_post_id' => $post, 'centre_id' => $centre, 'role' => $role[$post === $p1 ? 0 : 1],
                'first_name' => $fn, 'last_name' => $ln, 'email' => strtolower($fn . '.' . $ln) . '@applicant.example', 'phone' => '(619) 555-01' . (10 + count($this->made['career_applications'] ?? [])),
                'cover_text' => $cover, 'status' => $status, 'meeting_id' => $meeting, 'created_at' => $at, 'updated_at' => $at]);
            $this->add('career_application_events', ['application_id' => $aid, 'from_status' => null, 'to_status' => 'new', 'user_id' => null, 'created_at' => $at]);
            $prev = 'new';
            foreach (['reviewing', 'interview', 'offer'] as $k => $st) {
                if (array_search($status, ['new', 'reviewing', 'interview', 'offer'], true) <= $k) break;
                $this->add('career_application_events', ['application_id' => $aid, 'from_status' => $prev, 'to_status' => $st, 'user_id' => $elena, 'created_at' => $this->at($ago + $k + 1, 9, 30)]);
                $prev = $st;
            }
        }

        // ── Courses ──
        $video = \Illuminate\Support\Facades\Schema::hasTable('training_videos') ? DB::table('training_videos')->where('status', 'published')->orderBy('id')->value('id') : null;
        $c1 = $this->add('hr_courses', ['agency_id' => $agencyId, 'title' => 'Safe sleep for infants', 'content_kind' => 'link', 'url' => 'https://safetosleep.nichd.nih.gov/',
            'description' => 'Read the Safe to Sleep guide and the centre\'s nap room routine. About 90 minutes.', 'pd_hours' => 1.5, 'counts_pd' => 1, 'needs_ack' => 1,
            'created_by_id' => $boss, 'created_at' => $this->at(-30, 9, 0), 'updated_at' => $this->at(-30, 9, 0)]);
        $c2 = $this->add('hr_courses', ['agency_id' => $agencyId, 'title' => 'Positive guidance refresher', 'content_kind' => $video ? 'library' : 'link',
            'url' => $video ? null : 'https://www.naeyc.org/resources/topics/guidance', 'training_video_id' => $video ?: null,
            'description' => 'Redirecting, naming feelings and setting kind limits.', 'pd_hours' => 1, 'counts_pd' => 1, 'needs_ack' => 1,
            'created_by_id' => $boss, 'created_at' => $this->at(-20, 9, 0), 'updated_at' => $this->at(-20, 9, 0)]);
        $prefix = \App\Support\RefCode::prefix($agencyId);
        $assign = function (int $course, ?int $uid, int $dueIn, ?int $doneAgo = null, ?string $ack = null, ?string $roleName = null) use ($agencyId, $boss, $prefix, $now) {
            if (! $uid) return null;
            $pd = null;
            $cert = null;
            if ($doneAgo !== null) {
                $c = DB::table('hr_courses')->where('id', $course)->first();
                $pd = $this->add('pd_hours', ['agency_id' => $agencyId, 'user_id' => $uid, 'taken_on' => $this->today->copy()->addDays($doneAgo)->toDateString(),
                    'title' => $c->title, 'provider' => DB::table('agencies')->where('id', $agencyId)->value('name'), 'hours' => $c->pd_hours, 'status' => 'approved',
                    'reviewed_by' => $boss, 'reviewed_at' => $this->at($doneAgo, 16, 0), 'created_by' => $uid, 'created_at' => $this->at($doneAgo, 16, 0), 'updated_at' => $this->at($doneAgo, 16, 0)]);
                $cert = $prefix . '-CRT-0001';   // the demo's one completion
            }
            return $this->add('hr_course_assignments', ['agency_id' => $agencyId, 'course_id' => $course, 'user_id' => $uid, 'assigned_role' => $roleName,
                'due_date' => $this->today->copy()->addDays($dueIn)->toDateString(), 'completed_at' => $doneAgo !== null ? $this->at($doneAgo, 16, 0) : null,
                'acknowledged_name' => $ack, 'certificate_no' => $cert, 'pd_hours_id' => $pd, 'assigned_by_id' => $boss, 'created_at' => $this->at(-21, 9, 0), 'updated_at' => $now]);
        };
        $assign($c1, $maya, -3, -6, DB::table('users')->where('id', $maya)->value('first_name') . ' ' . DB::table('users')->where('id', $maya)->value('last_name'), 'educator');
        $assign($c1, $byName['Noah'] ?? null, 9, null, null, 'educator');
        $assign($c1, $byName['Sofia'] ?? null, 9, null, null, 'educator');
        $assign($c1, $byName['Lucas'] ?? null, -2, null, null, 'educator');
        $assign($c2, $byName['Grace'] ?? null, 14);
        $ninaCourse = $assign($c2, $nina, 5);

        // ── Onboarding: Nina, started five days ago ──
        if ($nina) {
            $start = $this->today->copy()->addDays(-5)->toDateString();
            $cert = collect((array) (\App\Support\Jurisdictions::forAgency($agencyId)['certifications'] ?? []))->first();
            $tasks = [
                ['document', 'Upload your background check clearance', null, null, 0, -4, 'upload'],
                ['custom', 'Tour of the centre with Elena', null, null, 0, -5, 'manager'],
                ['custom', 'Read the emergency and evacuation plan', null, null, 2, -2, 'self'],
                ['certificate', 'CPR and first aid on file', null, $cert['code'] ?? 'cpr', 7, null, null],
                ['course', 'Finish: Positive guidance refresher', $c2, null, 10, null, null],
                ['custom', 'Shadow Maya in Sprouts for a full day', null, null, 3, null, null],
            ];
            $tpl = $this->add('hr_onboarding_templates', ['agency_id' => $agencyId, 'name' => 'New educator', 'role' => 'educator', 'created_by_id' => $boss,
                'created_at' => $this->at(-40, 9, 0), 'updated_at' => $this->at(-40, 9, 0)]);
            foreach ($tasks as $i => $t) {
                $this->add('hr_onboarding_template_tasks', ['agency_id' => $agencyId, 'template_id' => $tpl, 'kind' => $t[0], 'title' => $t[1], 'ref_id' => $t[2], 'ref_key' => $t[3],
                    'due_days' => $t[4], 'sort' => $i, 'created_at' => $now, 'updated_at' => $now]);
            }
            $ob = $this->add('hr_onboardings', ['agency_id' => $agencyId, 'user_id' => $nina, 'template_id' => $tpl, 'start_date' => $start, 'status' => 'active',
                'created_by_id' => $boss, 'created_at' => $this->at(-6, 15, 0), 'updated_at' => $now]);
            foreach ($tasks as $i => $t) {
                $this->add('hr_onboarding_tasks', ['agency_id' => $agencyId, 'onboarding_id' => $ob, 'user_id' => $nina, 'kind' => $t[0], 'title' => $t[1], 'ref_id' => $t[2], 'ref_key' => $t[3],
                    'due_date' => Carbon::parse($start)->addDays($t[4])->toDateString(), 'sort' => $i,
                    'done_at' => $t[5] !== null ? $this->at($t[5], 13, 0) : null, 'done_by_id' => $t[5] !== null ? ($t[6] === 'manager' ? $elena : $nina) : null, 'done_how' => $t[6],
                    'file_name' => $t[6] === 'upload' ? 'Background-check-clearance.pdf' : null, 'created_at' => $this->at(-6, 15, 0), 'updated_at' => $now]);
            }
        }

        // ── Performance: Maya's annual review, in progress ──
        $scale = \App\Support\HrTalent::DEFAULT_SCALE;
        $secs = \App\Support\HrTalent::DEFAULT_SECTIONS;
        $self = ['sections' => [], 'overall' => 'A year I am proud of: the Sprouts routines are calmer and families tell me they feel in the loop every day.'];
        // In the order of HrTalent::DEFAULT_SECTIONS (safety, learning, families, teamwork).
        $mine = [[5, 'Nap and meal routines run smoothly; I updated the allergy board with Elena.'],
            [4, 'I write learning observations most days and plan from what the twins and their friends are curious about.'],
            [4, 'Daily notes and photos go out every day. I want to try a family morning in spring.'],
            [4, 'I mentor new staff when they shadow in Sprouts.']];
        foreach ($secs as $i => $s0) $self['sections'][] = ['rating' => $mine[$i][0] ?? 4, 'comment' => $mine[$i][1] ?? ''];
        $mgr = ['sections' => [['rating' => 5, 'comment' => 'Calm, consistent routines; the infant room is a model for the centre.'], ['rating' => 5, 'comment' => 'Maya\'s observations are some of the most thoughtful in the centre.']], 'overall' => ''];
        $rev = $this->add('hr_reviews', ['agency_id' => $agencyId, 'user_id' => $maya, 'manager_user_id' => $elena, 'template_id' => null, 'cycle' => 'annual',
            'title' => 'Annual review ' . $this->today->year, 'period_start' => $this->today->year . '-01-01', 'period_end' => $d(0), 'due_date' => $d(10),
            'scale' => json_encode($scale), 'sections' => json_encode($secs), 'self_answers' => json_encode($self), 'manager_answers' => json_encode($mgr),
            'self_submitted_at' => $this->at(-2, 17, 10), 'status' => 'self_done', 'created_by_id' => $elena, 'created_at' => $this->at(-14, 9, 0), 'updated_at' => $this->at(-1, 16, 0)]);
        $g1 = $this->add('hr_goals', ['agency_id' => $agencyId, 'user_id' => $maya, 'review_id' => $rev, 'title' => 'Host a spring family morning in Sprouts',
            'detail' => 'Invite families for songs and a shared snack; ask two parents to help plan.', 'due_date' => $d(120), 'status' => 'on_track', 'progress' => 25,
            'created_by_id' => $elena, 'created_at' => $this->at(-14, 9, 30), 'updated_at' => $this->at(-3, 11, 0)]);
        $g2 = $this->add('hr_goals', ['agency_id' => $agencyId, 'user_id' => $maya, 'review_id' => $rev, 'title' => 'Finish the infant and toddler PD series (6 hours)',
            'due_date' => $d(60), 'status' => 'at_risk', 'progress' => 30, 'created_by_id' => $maya, 'created_at' => $this->at(-14, 9, 40), 'updated_at' => $this->at(-4, 15, 0)]);
        $this->add('hr_goal_checkins', ['agency_id' => $agencyId, 'goal_id' => $g1, 'author_id' => $maya, 'note' => 'Picked a date in April and asked the Kims and the Garcias to help.',
            'status' => 'on_track', 'progress' => 25, 'created_at' => $this->at(-3, 11, 0)]);
        $this->add('hr_goal_checkins', ['agency_id' => $agencyId, 'goal_id' => $g2, 'author_id' => $elena, 'note' => 'Two of six sessions done; the November one was cancelled. Let\'s find a makeup date.',
            'status' => 'at_risk', 'progress' => 30, 'created_at' => $this->at(-4, 15, 0)]);
        $this->add('hr_one_on_ones', ['agency_id' => $agencyId, 'user_id' => $maya, 'manager_user_id' => $elena, 'met_on' => $d(-7),
            'notes' => 'Talked about the twins\' transition to Rainbows in the new year and Maya\'s interest in mentoring.', 'next_steps' => 'Elena to look at a mentoring stipend; Maya to draft the family morning plan.',
            'author_id' => $elena, 'created_at' => $this->at(-7, 15, 30), 'updated_at' => $this->at(-7, 15, 30)]);
    }
}
