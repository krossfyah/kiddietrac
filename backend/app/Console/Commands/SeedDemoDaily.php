<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demo-data generator for the Test Agency (centre 16 by default).
 *
 *   php artisan demo:seed-daily            # avatars + today's activity
 *   php artisan demo:seed-daily --avatars-only
 *
 * Idempotent per day: re-running the same day tops up avatars but does NOT
 * duplicate a child's attendance / events / observations / the day's incident
 * or announcement. All generated rows are tagged "[Demo]" where a text field
 * allows, so they're identifiable/removable.
 *
 * NOTHING FROM THE FUTURE (2026-11-04, App Store review: at 3 pm the App Review Centre's parent saw Lily "At Centre",
 * "At home" and "isn't attending today" on one screen, her story said she "headed home at 4:10 PM", and the educator
 * saw "7 at Centre" next to "0 children here"). The 05:00 run wrote the whole day at once - the 4:10 pm check-out,
 * the afternoon nap, the 7:30 shift - so every screen that reads "the latest event" saw a day that had not happened
 * yet. Each row is now written only once its time at the centre has passed, and the command runs every hour
 * (routes/console.php), so the day fills in as it goes: arrivals in the morning, naps after lunch, pick-ups at four.
 * Each step has its own guard, so an hourly run adds only what is new.
 */
class SeedDemoDaily extends Command
{
    protected $signature = 'demo:seed-daily {--centre= : Centre id (blank = every centre in --agency)} {--agency=6 : Agency whose centres to seed when --centre is blank} {--avatars-only : Only (re)set avatar photos}';

    protected $description = 'Populate/refresh demo data for the Test Agency (avatars + daily activity across all sections).';

    /** Run a seeding section, never letting one failure abort the whole command. */
    private function section(string $label, callable $fn): void
    {
        try {
            $n = (int) $fn();
            $this->line(sprintf('  ✓ %-14s %d', $label, $n));
        } catch (\Throwable $e) {
            $this->warn(sprintf('  ⚠ %-14s skipped: %s', $label, $e->getMessage()));
        }
    }

    public function handle(): int
    {
        $opt = $this->option('centre');
        if ($opt !== null && $opt !== '') {
            return $this->seedCentre((int) $opt);
        }
        // No --centre: seed every centre in the demo agency (so all centres,
        // including newly created ones, get fresh activity from the daily cron).
        $agencyId = (int) $this->option('agency');
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->orderBy('id')->pluck('id');
        if ($centreIds->isEmpty()) {
            $this->error("No centres found for agency {$agencyId}.");
            return self::FAILURE;
        }
        /* One centre that cannot be seeded (no active staff, no rooms) is a warning, not a
           failed run (2026-10-29, ticket #194): the App Review Centre's educator was suspended
           when Apple's reviewer declined the Terms, and every night after that the WHOLE
           refresh reported failure although the other three centres seeded fine. It fails
           only when no centre could be seeded at all. */
        $ok = 0;
        foreach ($centreIds as $cid) {
            if ($this->seedCentre((int) $cid) === self::SUCCESS) $ok++;
            else $this->warn("  Centre {$cid} skipped (see above).");
        }
        $rc = $ok > 0 ? self::SUCCESS : self::FAILURE;

        $this->seedHomeVisitorRooms($agencyId, $centreIds->all());

        return $rc;
    }

    /**
     * A home visitor gets ONE ROOM IN EVERY CENTRE — the cross-centre shape.
     *
     * Not an arbitrary choice. Lloydene's nine assignments sit in nine different
     * centres, because in a home childcare agency each provider's home IS a centre with
     * one room. That shape is what broke bootstrap() on 2026-09-06 — it intersected her
     * assignments with a single resolved centre and showed her one provider out of nine.
     * A home visitor fixture confined to one centre would not have caught it, so the
     * demo agency reproduces the shape that actually fails.
     *
     * Home-visitor roles are agency-attached (centre_id NULL), which is why this runs
     * once for the agency rather than inside the per-centre loop.
     */
    private function seedHomeVisitorRooms(int $agencyId, array $centreIds): void
    {
        $visitors = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.role', 'home_visitor')->where('ra.active', true)
            ->where('u.status', 'active')
            ->where(function ($w) use ($agencyId, $centreIds) {
                $w->where('ra.agency_id', $agencyId)->orWhereIn('ra.centre_id', $centreIds);
            })
            ->distinct()->pluck('ra.user_id');

        if ($visitors->isEmpty() || ! $centreIds) {
            return;
        }

        $this->section('home visitor rooms', function () use ($visitors, $centreIds) {
            $n = 0;
            foreach ($visitors as $uid) {
                foreach ($centreIds as $cid) {
                    // Already has a room in this centre? Leave it exactly as it is.
                    $hasHere = DB::table('educator_rooms as er')
                        ->join('rooms as r', 'r.id', '=', 'er.room_id')
                        ->where('er.user_id', $uid)->where('r.centre_id', $cid)->exists();
                    if ($hasHere) {
                        continue;
                    }
                    $roomId = DB::table('rooms')->where('centre_id', $cid)
                        ->where('active', true)->orderBy('id')->value('id');
                    if (! $roomId) {
                        continue;
                    }
                    DB::table('educator_rooms')->insert([
                        'user_id' => $uid,
                        'room_id' => (int) $roomId,
                        'created_at' => now(),
                    ]);
                    $n++;
                }
            }
            return $n;
        });
    }

    /** US spelling for a US agency's demo text (2026-10-28, Brightwood): center, colors, practiced. */
    private bool $us = false;
    /** A public website-demo agency (Brightwood, Starlight): visitors see this text, so no "[Demo]" tags. */
    private bool $pub = false;
    private function tag(): string { return $this->pub ? '' : '[Demo] '; }

    /** @var callable|null fn(int $h, int $m = 0): bool - set per centre by seedCentre(). */
    private $past = null;

    private function words(string $t): string
    {
        if (! $this->us) return $t;
        return strtr($t, ['centre' => 'center', 'Centre' => 'Center', 'colours' => 'colors', 'Colours' => 'Colors', 'practised' => 'practiced']);
    }

    private function seedCentre(int $centreId): int
    {
        $centre = DB::table('centres')->where('id', $centreId)->first();
        if (! $centre) {
            $this->error("Centre {$centreId} not found.");
            return self::FAILURE;
        }
        $this->us = strtoupper((string) (DB::table('agencies')->where('id', $centre->agency_id)->value('country') ?? '')) === 'US';
        $this->pub = \App\Support\PublicDemo::isAgency((int) $centre->agency_id);
        $agencyId = (int) $centre->agency_id;

        $staff = DB::table('users')
            ->join('role_assignments as ra', 'ra.user_id', '=', 'users.id')
            ->where('ra.centre_id', $centreId)->where('ra.active', true)
            ->whereIn('ra.role', ['educator', 'centre_director'])
            ->select('users.*', 'ra.role')->get()->unique('id')->values();
        $director = $staff->firstWhere('role', 'centre_director') ?: $staff->first();
        $educators = $staff->where('role', 'educator')->values();
        if ($educators->isEmpty()) $educators = $staff;
        $recorder = $educators->first() ?: $director;
        if (! $recorder) {
            $this->error('No staff found for this centre.');
            return self::FAILURE;
        }

        $children = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->where('f.centre_id', $centreId)
            // photo_url so the avatar pass can tell a generated picture from a real one.
            ->select('ch.id', 'ch.first_name', 'ch.family_id', 'ch.photo_url')->get()->values();
        $rooms = DB::table('rooms')->where('centre_id', $centreId)->orderBy('id')->pluck('id')->values();
        if ($children->isEmpty() || $rooms->isEmpty()) {
            $this->error('No demo children / rooms for this centre — run the base demo seeder first.');
            return self::FAILURE;
        }
        /* THE CHILD'S OWN ROOM (2026-11-13). This was $rooms[$i % count] - a room by list position - so a toddler
           enrolled in Sprouts was checked in to Seedlings and her day logged there. Their open enrolment first;
           position only for a child with none. */
        $enrolledRoom = DB::table('enrollments')->whereIn('child_id', $children->pluck('id')->all() ?: [0])
            ->whereIn('room_id', $rooms->all() ?: [0])->whereNull('end_date')->orderBy('start_date')
            ->pluck('room_id', 'child_id')->map(fn ($v) => (int) $v)->all();
        $roomFor = fn ($i) => $enrolledRoom[(int) ($children[$i]->id ?? 0)] ?? $rooms[$i % $rooms->count()];

        $this->info("Seeding demo data for centre {$centreId} ({$centre->name}) — {$children->count()} children, {$staff->count()} staff.");

        /* ── 0) ROOM ASSIGNMENTS ────────────────────────────────────────────────
           Without these the demo agency cannot exercise anything room-scoped:
           assignedRoomIds() returns null for a person with no assignments, so an
           educator is "unrestricted" and a scoping test proves nothing. Test Agency
           had 12 educators and zero assignments, which is why the room-scoped paths
           had to be verified against a live agency.

           Round-robin, one room each, and only ever INSERTS — an assignment somebody
           made by hand is never removed by the nightly cron. */
        $this->section('room assignments', function () use ($staff, $rooms, $centreId) {
            $n = 0;
            $educators = $staff->where('role', 'educator')->values();
            foreach ($educators as $i => $e) {
                $roomId = (int) $rooms[$i % $rooms->count()];
                $exists = DB::table('educator_rooms')
                    ->where('user_id', $e->id)->where('room_id', $roomId)->exists();
                if ($exists) {
                    continue;
                }
                // Only if they hold NO room in this centre yet, so a hand-made
                // assignment elsewhere in the centre is left alone.
                $hasHere = DB::table('educator_rooms as er')
                    ->join('rooms as r', 'r.id', '=', 'er.room_id')
                    ->where('er.user_id', $e->id)->where('r.centre_id', $centreId)->exists();
                if ($hasHere) {
                    continue;
                }
                DB::table('educator_rooms')->insert([
                    'user_id' => $e->id,
                    'room_id' => $roomId,
                    'created_at' => now(),
                ]);
                $n++;
            }
            // section() casts this to int and prints it as the count.
            return $n;
        });

        // ── 1) Avatars (users = real photos, children = kid-friendly illustrated) ──
        $this->section('avatars', function () use ($agencyId, $children) {
            $n = 0;
            $userIds = DB::table('role_assignments')->where('agency_id', $agencyId)->where('active', true)->pluck('user_id')->unique();
            foreach (DB::table('users')->whereIn('id', $userIds)->get() as $u) {
                /* Only ever REPLACE a demo photo, never a real one.

                   This used to overwrite photo_url for every account holding a role in
                   the demo agency, unconditionally, every night at 05:00. That is fine
                   for the generated staff, and quietly destructive for anyone real who
                   also holds a role here — which is how the platform admin's uploaded
                   photo became a pravatar face on 2026-09-10, the morning after a test
                   family gave him a guardian role in this agency.

                   The seeder's job is to fill in demo data, not to take a picture away
                   from someone who chose one. An empty slot or a photo this command
                   itself generated is ours; anything else belongs to a person. */
                $current = trim((string) ($u->photo_url ?? ''));
                $isOurs = $current === '' || preg_match('~(pravatar\.cc|api\.dicebear\.com)~i', $current);
                if (! $isOurs) {
                    continue;
                }
                $seed = rawurlencode((string) ($u->email ?: $u->id));
                DB::table('users')->where('id', $u->id)->update(['photo_url' => "https://i.pravatar.cc/240?u={$seed}"]);
                $n++;
            }
            foreach ($children as $c) {
                // Same rule as the staff above: a real photo is never replaced.
                $cur = trim((string) ($c->photo_url ?? ''));
                if ($cur !== '' && ! preg_match('~(pravatar\.cc|api\.dicebear\.com)~i', $cur)) {
                    continue;
                }
                $seed = rawurlencode(($c->first_name ?: 'kid') . $c->id);
                DB::table('children')->where('id', $c->id)->update([
                    'photo_url' => "https://api.dicebear.com/9.x/adventurer/svg?seed={$seed}&backgroundColor=b6e3f4,c0aede,ffd5dc,ffdfbf,d1f4d9",
                ]);
                $n++;
            }
            return $n;
        });

        if ($this->option('avatars-only')) {
            $this->info('Avatars refreshed.');
            return self::SUCCESS;
        }

        // Carbon::today() is midnight in app.timezone, which is UTC. Every setTime() below
        // then meant a UTC wall clock: setTime(8, 5) wrote 08:05 UTC, which is 04:05 in
        // Toronto. Ten thousand demo events were seeded at four in the morning — naps at
        // 05:50, lunch at 08:00 — and anyone checking a timezone fix against Test Agency
        // saw times that looked broken no matter how correct the display was.
        //
        // Midnight AT THE CENTRE, converted to UTC for storage. seedAt() does the
        // conversion once so no call site has to remember.
        $seedTz = \App\Support\AgencyTime::tzForCentre($centreId);
        $today = Carbon::today($seedTz);
        $dstamp = $today->toDateString();

        /** A wall-clock time at the centre, as the UTC instant that gets stored. */
        $seedAt = fn (int $h, int $m = 0) => $today->copy()->setTime($h, $m)->utc();
        /** ...and whether it has happened yet. Nothing is written ahead of the clock. */
        $past = fn (int $h, int $m = 0) => $seedAt($h, $m)->lte(now());
        $this->past = $past;
        // The App Review Centre's families always come in (a reviewer signs in as one of them, any day).
        $everyone = (string) ($centre->name ?? '') === 'App Review Centre';
        /* The website demo parent's children always come in too (2026-11-13: Megan Gonzalez's James and Evelyn had
           no day since Oct 28 - the 30% left out were the same children every day). */
        $showFamilies = [];
        if ($this->pub) {
            foreach (DB::table('guardians')->whereIn('user_id', array_map(fn ($set) => (int) ($set['parent']['id'] ?? 0), array_values(\App\Support\PublicDemo::sets())))->pluck('family_id') as $f) $showFamilies[(int) $f] = true;
        }
        $dayTurn = (int) $today->dayOfYear;

        // ── 1b) Staff on the floor — clock in the centre's educators/director for
        //        today so "staff on floor" is realistic (and no false ratio breach). ──
        $this->section('staff clock-in', function () use ($staff, $centreId, $today, $seedAt, $past) {
            $n = 0;
            foreach ($staff as $s) {
                // Guard on ANY open shift (not just today's) so re-runs / day
                // rollovers don't stack multiple open entries for one staffer.
                // Close anything left open from an earlier day FIRST. Seeding only
                // ever opened shifts and never closed them, so demo staff would have
                // accumulated permanently-open punches — showing as on the floor
                // forever, which is precisely the stale-punch problem being cleaned up
                // on the live agency. A demo day should end as well as start.
                DB::table('time_punches')
                    ->where('user_id', $s->id)->where('centre_id', $centreId)
                    ->whereNull('punched_out_at')
                    // "Punched in before today began" — the start of the agency day.
                    ->where('punched_in_at', '<', \App\Support\AgencyTime::dayRangeForCentre($centreId)[0])
                    /* Ten hours after it began (2026-10-31). "DATE() + 17.5 h" was 17:30 UTC - 10:30 am in California,
                       1:30 pm in Toronto - so a 7:30 shift was closed at a wall-clock time that depended on the zone. */
                    ->update(['punched_out_at' => DB::raw("DATE_ADD(punched_in_at, INTERVAL 10 HOUR)")]);

                // time_punches — the table the clock actually writes. Seeding the
                // legacy one left demo staff invisible everywhere that matters: no one
                // on the floor, nothing on a timesheet, and every demo educator's
                // nightly summary reporting 0h. This runs at 05:00 daily, so it was
                // re-creating that every morning.
                $open = DB::table('time_punches')->where('user_id', $s->id)->where('centre_id', $centreId)
                    ->whereNull('punched_out_at')->exists();
                if ($open || ! $past(7, 30)) continue;
                // One shift a day: a shift already punched out today (auto sign-off, a person) is not reopened.
                if (DB::table('time_punches')->where('user_id', $s->id)->where('centre_id', $centreId)
                    ->where('punched_in_at', '>=', \App\Support\AgencyTime::dayRangeForCentre($centreId)[0])->exists()) continue;
                DB::table('time_punches')->insert([
                    'user_id' => $s->id, 'centre_id' => $centreId,
                    'punched_in_at' => $seedAt(7, 30),
                    // NOT NULL enum(web|kiosk|mobile); a seeded shift stands in for
                    // somebody tapping in at the door.
                    'source' => 'kiosk',
                    'created_at' => now(),
                ]);
                $n++;
            }
            return $n;
        });

        // ── 2) Attendance (check in + out) — one pair per child per day ──
        $this->section('attendance', function () use ($children, $roomFor, $recorder, $today, $seedAt, $past, $centreId, $everyone, $showFamilies, $dayTurn) {
            $n = 0;
            [$d0, $d1] = \App\Support\AgencyTime::dayRangeForCentre($centreId);
            foreach ($children as $i => $c) {
                // Demo realism: leave ~30% of children NOT checked in, so the
                // agency-overview "who's in / who's out" view shows both sides.
                // ...a different 30% each day, so no child goes weeks with no day at all.
                if (! $everyone && ! isset($showFamilies[(int) $c->family_id]) && ($i + $dayTurn) % 10 >= 7) continue;
                $today_ = fn ($type) => DB::table('check_events')->where('child_id', $c->id)->where('event_type', $type)
                    ->where('occurred_at', '>=', $d0)->where('occurred_at', '<', $d1)->exists();
                $room = $roomFor($i);
                $in = $today_('check_in');
                if (! $in && $past(8, 5 + ($i % 40))) {
                    $moods = ['happy', 'calm', 'tired', 'happy'];
                    DB::table('check_events')->insert([
                        'child_id' => $c->id, 'room_id' => $room, 'event_type' => 'check_in',
                        'occurred_at' => $seedAt(8, 5 + ($i % 40)),
                        'by_user_id' => $recorder->id, 'recorded_by_id' => $recorder->id, 'mood_at_event' => $moods[$i % 4],
                        'notes' => $this->tag() . 'Dropped off by parent', 'created_at' => now(),
                    ]);
                    $in = true; $n++;
                }
                if ($in && ! $today_('check_out') && $past(16, 10 + ($i % 40))) {
                    DB::table('check_events')->insert([
                        'child_id' => $c->id, 'room_id' => $room, 'event_type' => 'check_out',
                        'occurred_at' => $seedAt(16, 10 + ($i % 40)),
                        'by_user_id' => $recorder->id, 'recorded_by_id' => $recorder->id, 'notes' => $this->tag() . 'Picked up', 'created_at' => now(),
                    ]);
                    $n++;
                }
            }
            return $n;
        });

        // ── 3) Daily log events (meal / nap / mood / activity) ──
        $this->section('daily events', function () use ($children, $roomFor, $educators, $today, $seedAt, $past, $centreId) {
            $n = 0;
            $plan = [
                ['activity', 9, 30, $this->tag() . 'Circle time — songs and calendar'],
                ['snack', 10, 0, $this->tag() . 'Morning snack — apple slices, ate well'],
                ['meal', 12, 0, $this->tag() . 'Lunch — pasta and veggies, finished most'],
                ['nap_start', 13, 0, $this->tag() . 'Settled for nap'],
                ['nap_end', 14, 45, $this->tag() . 'Woke up rested'],
                ['mood', 15, 30, $this->tag() . 'Cheerful during free play'],
            ];
            [$d0, $d1] = \App\Support\AgencyTime::dayRangeForCentre($centreId);
            foreach ($children as $i => $c) {
                // Only a child who came in today has a day to log.
                if (! DB::table('check_events')->where('child_id', $c->id)->where('event_type', 'check_in')
                    ->where('occurred_at', '>=', $d0)->where('occurred_at', '<', $d1)->exists()) continue;
                $ed = $educators[$i % max(1, $educators->count())];
                foreach ($plan as $p) {
                    if (! $past($p[1], $p[2])) continue;
                    if (DB::table('daily_events')->where('child_id', $c->id)->where('event_type', $p[0])
                        ->where('occurred_at', $seedAt($p[1], $p[2]))->exists()) continue;
                    DB::table('daily_events')->insert([
                        'child_id' => $c->id, 'room_id' => $roomFor($i), 'event_type' => $p[0],
                        'occurred_at' => $seedAt($p[1], $p[2]), 'payload' => '{}',
                        'notes' => $p[3], 'recorded_by_id' => $ed->id ?? null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $n++;
                }
            }
            return $n;
        });

        // ── 4) Observations (learning stories) — a few new ones per day ──
        $this->section('observations', function () use ($children, $educators, $today, $dstamp, $seedAt, $centreId) {
            $n = 0;
            $samples = [
                ['physical', 'Confident on the climbing structure', 'Reached the top of the climber independently and beamed with pride. Growing gross-motor confidence.'],
                ['language_literacy', 'Retold the story in their own words', 'After story time, narrated the plot back to a friend, using new vocabulary from the book.'],
                ['social_emotional', 'Comforted a friend', 'Noticed a peer was upset and offered a hug and a toy without prompting. Lovely empathy.'],
                ['creative_arts', 'Mixed colours at the easel', 'Discovered that blue and yellow make green and spent a long, focused stretch painting.'],
                ['cognitive', 'Sorted blocks by size', 'Independently arranged the blocks from smallest to largest and counted them aloud.'],
            ];
            $picks = $children->sortBy(fn ($c) => crc32($dstamp . '#' . $c->id))->values()->take(3);
            foreach ($picks as $k => $c) {
                $s = $samples[($c->id + $k) % count($samples)];
                $title = $this->pub ? $s[1] : "[Demo] {$s[1]} ({$dstamp})";
                $seen = DB::table('observations')->where('child_id', $c->id)->whereIn('title', [$title, $this->words($title)]);
                if ($this->pub) { [$d0, $d1] = \App\Support\AgencyTime::dayRangeForCentre($centreId); $seen->where('observed_at', '>=', $d0)->where('observed_at', '<', $d1); }
                if ($seen->exists() || ! ($this->past)(11, $k * 7)) continue;
                $ed = $educators[$k % max(1, $educators->count())];
                DB::table('observations')->insert([
                    'child_id' => $c->id, 'domain' => $s[0], 'title' => $this->words($title), 'body' => $this->words($s[2]),
                    'observed_at' => $seedAt(11, 0)->addMinutes($k * 7),
                    'recorded_by_id' => $ed->id ?? null, 'shared_with_family' => 1,
                    'created_at' => now(),
                ]);
                $n++;
            }
            return $n;
        });

        // ── 5) One minor incident per day (best-effort — status enum varies) ──
        $this->section('incident', function () use ($children, $roomFor, $recorder, $today, $dstamp, $seedAt) {
            $marker = "[Demo] Minor bump ({$dstamp})";
            if (! ($this->past)(10, 45)) return 0;
            $idx = $today->day % $children->count();
            $c = $children[$idx];
            // occurred_at is WALL CLOCK at the centre (what an educator types), not a UTC instant like seedAt().
            $wall = $today->copy()->setTime(10, 45)->toDateTimeString();
            if ($this->pub) {
                if (DB::table('incidents')->whereIn('child_id', $children->pluck('id'))->where('occurred_at', $wall)->where('location', 'Outdoor play area')->exists()) return 0;
            } elseif (DB::table('incidents')->where('description', 'LIKE', "%({$dstamp})%")->where('description', 'LIKE', '[Demo]%')->exists()) return 0;
            DB::table('incidents')->insert([
                'child_id' => $c->id, 'room_id' => $roomFor($idx), 'incident_type' => 'injury', 'severity' => 'low',
                'occurred_at' => $wall, 'location' => 'Outdoor play area',
                'description' => $this->pub ? 'Small bump on the knee after a trip on the grass.' : $marker . ' — small bump on the knee after a trip on the grass.',
                'action_taken' => 'Cleaned, cold compress applied, comforted. No further concern.',
                'first_aid_administered' => 1, 'recorded_by_id' => $recorder->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return 1;
        });

        // ── 6) A daily announcement to families ──
        $this->section('announcement', function () use ($centreId, $director, $recorder, $today, $dstamp) {
            if (! ($this->past)(9, 0)) return 0;
            $title = $this->words($this->pub ? 'Today at the centre' : "[Demo] Today at the centre ({$dstamp})");
            $seen = DB::table('announcements')->where('scope_type', 'centre')->where('scope_id', $centreId)->where('title', $title);
            if ($this->pub) $seen->where('sent_at', '>=', \App\Support\AgencyTime::dayRangeForCentre($centreId)[0]);
            if ($seen->exists()) return 0;
            $bodies = [
                'We had a wonderful day exploring colours in the art studio and a long play outside in the sunshine. Reminder: please label water bottles!',
                'Today the children planted seeds in our garden bed and practised counting during snack. Show-and-tell is this Friday.',
                'Great energy today — lots of building, stories and music. A gentle reminder to pack an extra set of clothes for messy play.',
            ];
            DB::table('announcements')->insert([
                'scope_type' => 'centre', 'scope_id' => $centreId, 'title' => $title,
                'body' => $this->words($bodies[$today->day % count($bodies)]),
                'send_email' => 0, 'send_sms' => 0, 'send_push' => 0,
                'created_by_id' => ($director->id ?? $recorder->id), 'sent_at' => now(), 'created_at' => now(),
            ]);
            return 1;
        });

        /* ── 7) Photos (2026-11-04) ── Parents' "Photos & video" and "Photos this week" were empty for the App Review
           Centre's families: nothing ever added a photo to the Test Agency. Two a day per child who came in (mid-morning
           and after nap), from the website demo's set in storage/photos/web-demo - activity shots with no faces,
           already cleared for public use (CREDITS.txt there). Not for the public demo agencies: kt:seed-*-demo
           --content gives those their own. */
        if (! $this->pub) {
            $this->section('photos', function () use ($children, $centreId, $educators, $recorder, $seedAt, $past) {
                $set = [
                    ['bricks', 'Building with bricks'], ['brushes', 'Getting the art studio ready'], ['bubble', 'Bubbles in the yard'],
                    ['chalk', 'Sidewalk chalk art'], ['colour-experiment', 'Colour mixing - red and yellow make orange!'],
                    ['crayon-drawing', 'Drawing our homes'], ['egg-shakers', 'Music with egg shakers'], ['garden-beds', 'Checking on the garden beds'],
                    ['kindness-rocks', 'Painting kindness rocks'], ['kite', 'Kite day'], ['leaves', 'Leaf collecting walk'],
                    ['maracas', 'Music and movement'], ['mark-making', 'Early mark making'], ['pine-cone', 'Nature table finds'],
                    ['playdough', 'Playdough creations'], ['pumpkins', 'Pumpkin patch play'], ['puzzle', 'Puzzle time'],
                    ['ride-on', 'Ride-on races in the yard'], ['sandbox', 'The sandbox construction crew'], ['sprout', 'Our seeds sprouted!'],
                    ['sunflower', 'Measuring the sunflowers'], ['teddy', 'Teddy bear picnic'], ['watercolours', 'Watercolour painting'],
                    ['xylophone', 'Making music on the xylophone'],
                ];
                $base = '/storage/photos/web-demo/';
                [$d0, $d1] = \App\Support\AgencyTime::dayRangeForCentre($centreId);
                $n = 0;
                foreach ($children as $i => $c) {
                    if (! DB::table('check_events')->where('child_id', $c->id)->where('event_type', 'check_in')
                        ->where('occurred_at', '>=', $d0)->where('occurred_at', '<', $d1)->exists()) continue;
                    foreach ([[9, 40], [14, 20]] as $k => $hm) {
                        $m = $hm[1] + ($i % 15);
                        if (! $past($hm[0], $m)) continue;
                        $at = $seedAt($hm[0], $m);
                        if (DB::table('photos')->where('centre_id', $centreId)->where('taken_at', $at)
                            ->whereJsonContains('child_ids', (int) $c->id)->exists()) continue;
                        $p = $set[(crc32($at->toDateString() . '#' . $c->id) + $k * 7) % count($set)];
                        $ed = $educators[$i % max(1, $educators->count())] ?? $recorder;
                        DB::table('photos')->insert([
                            'centre_id' => $centreId, 'uploaded_by_id' => $ed->id ?? $recorder->id,
                            'url' => $base . $p[0] . '.jpg', 'thumbnail_url' => $base . $p[0] . '-thumb.jpg',
                            'media_type' => 'image', 'caption' => $this->words($p[1]), 'child_ids' => json_encode([(int) $c->id]),
                            'taken_at' => $at, 'created_at' => now(),
                        ]);
                        $n++;
                    }
                }
                return $n;
            });
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}
