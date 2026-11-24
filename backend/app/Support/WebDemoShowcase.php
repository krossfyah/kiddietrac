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
 *   php artisan kt:web-demo-showcase [--dry-run]
 */
final class WebDemoShowcase
{
    private const KEY = 'webdemo.showcase';

    private array $made = [];
    private Carbon $today;
    private string $tz = 'America/Los_Angeles';

    public static function run(bool $dry = false): array
    {
        return (new self())->build($dry);
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

    public function build(bool $dry): array
    {
        $U = PublicDemo::USERS;
        [$megan, $maya, $elena] = [(int) $U['parent'], (int) $U['educator'], (int) $U['director']];
        $fam = (int) DB::table('guardians')->where('user_id', $megan)->value('family_id');
        $family = DB::table('families')->where('id', $fam)->first();
        $agencyId = (int) DB::table('role_assignments')->where('user_id', $elena)->where('role', 'centre_director')->value('agency_id');
        abort_unless($family && $agencyId && PublicDemo::isAgency($agencyId), 500, 'The website demo family / agency was not found.');
        $centreId = (int) $family->centre_id;
        $kids = DB::table('children')->where('family_id', $fam)->whereNull('deleted_at')->orderBy('first_name')->get(['id', 'first_name']);
        $james = $kids->firstWhere('first_name', 'James');
        $evelyn = $kids->firstWhere('first_name', 'Evelyn');
        abort_unless($james && $evelyn, 500, 'James and Evelyn were not found.');
        $roomId = (int) DB::table('enrollments')->where('child_id', $james->id)->whereNull('end_date')->value('room_id');
        $this->tz = AgencyTime::tzForCentre($centreId) ?: $this->tz;
        $this->today = Carbon::now($this->tz)->startOfDay();

        $old = json_decode((string) PlatformSettings::get(self::KEY, '{}'), true) ?: [];
        if ($dry) {
            return ['would_remove' => array_map('count', $old), 'family' => $fam, 'children' => [$james->id, $evelyn->id], 'room' => $roomId, 'centre' => $centreId];
        }

        DB::transaction(function () use ($old, $megan, $maya, $elena, $fam, $agencyId, $centreId, $james, $evelyn, $roomId) {
            // Only what this wrote last time: timeline rows first (they point at photos), then newest tables first.
            $order = array_reverse($old, true);
            if (isset($order['daily_events'])) $order = ['daily_events' => $order['daily_events']] + $order;
            foreach ($order as $table => $ids) {
                if (preg_match('/^[a-z_]+$/', $table) && $ids) DB::table($table)->whereIn('id', $ids)->delete();
            }
            $now = now()->format('Y-m-d H:i:s');
            $days = $this->pastWeekdays(10);

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
                [$this->at(0, 10, 12), $maya, 'James used two words together all morning today - "more blocks!" 🧱 We\'re so proud of him.'],
            ]);
            $chat($evelyn, [
                [$this->at(-1, 7, 58), $megan, 'Hi Maya, Evelyn\'s allergy medicine is in her backpack - 2.5 mL with lunch, please.'],
                [$this->at(-1, 11, 34), $maya, 'Got it! Given at 11:30 and logged in her medication record ✅'],
                [$this->at(-1, 13, 5), $elena, 'Hi Megan, I\'ve added Evelyn\'s medication form to her file. Thanks for completing it so quickly.'],
                [$this->at(0, 15, 40), $maya, 'Evelyn painted her very first rainbow today 🌈 The photo is in the gallery!'],
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
            $ftid = $this->add('field_trips', ['agency_id' => $agencyId, 'centre_id' => $centreId, 'title' => 'Aquarium of the Pacific',
                'destination' => '100 Aquarium Way, Long Beach, CA 90802', 'trip_date' => $this->today->copy()->addDays($trip)->toDateString(),
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
                    'phone' => '562-555-0140', 'notes' => 'Picks up on Wednesdays.', 'active' => 1, 'created_by_user_id' => $megan,
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

            PlatformSettings::set(self::KEY, json_encode($this->made));
        });

        return array_map('count', $this->made);
    }
}
