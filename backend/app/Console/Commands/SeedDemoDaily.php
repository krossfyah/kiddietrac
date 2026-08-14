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
 * or announcement. Scheduled daily so the demo tenant always shows fresh,
 * realistic activity across every section. All generated rows are tagged
 * "[Demo]" where a text field allows, so they're identifiable/removable.
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
        $rc = self::SUCCESS;
        foreach ($centreIds as $cid) {
            if ($this->seedCentre((int) $cid) !== self::SUCCESS) $rc = self::FAILURE;
        }
        return $rc;
    }

    private function seedCentre(int $centreId): int
    {
        $centre = DB::table('centres')->where('id', $centreId)->first();
        if (! $centre) {
            $this->error("Centre {$centreId} not found.");
            return self::FAILURE;
        }
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
            ->select('ch.id', 'ch.first_name', 'ch.family_id')->get()->values();
        $rooms = DB::table('rooms')->where('centre_id', $centreId)->orderBy('id')->pluck('id')->values();
        if ($children->isEmpty() || $rooms->isEmpty()) {
            $this->error('No demo children / rooms for this centre — run the base demo seeder first.');
            return self::FAILURE;
        }
        $roomFor = fn ($i) => $rooms[$i % $rooms->count()];

        $this->info("Seeding demo data for centre {$centreId} ({$centre->name}) — {$children->count()} children, {$staff->count()} staff.");

        // ── 1) Avatars (users = real photos, children = kid-friendly illustrated) ──
        $this->section('avatars', function () use ($agencyId, $children) {
            $n = 0;
            $userIds = DB::table('role_assignments')->where('agency_id', $agencyId)->where('active', true)->pluck('user_id')->unique();
            foreach (DB::table('users')->whereIn('id', $userIds)->get() as $u) {
                $seed = rawurlencode((string) ($u->email ?: $u->id));
                DB::table('users')->where('id', $u->id)->update(['photo_url' => "https://i.pravatar.cc/240?u={$seed}"]);
                $n++;
            }
            foreach ($children as $c) {
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

        $today = Carbon::today();
        $dstamp = $today->toDateString();

        // ── 1b) Staff on the floor — clock in the centre's educators/director for
        //        today so "staff on floor" is realistic (and no false ratio breach). ──
        $this->section('staff clock-in', function () use ($staff, $centreId, $today) {
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
                    ->whereDate('punched_in_at', '<', $today->toDateString())
                    ->update(['punched_out_at' => DB::raw("DATE_ADD(DATE(punched_in_at), INTERVAL 17.5 HOUR)")]);

                // time_punches — the table the clock actually writes. Seeding the
                // legacy one left demo staff invisible everywhere that matters: no one
                // on the floor, nothing on a timesheet, and every demo educator's
                // nightly summary reporting 0h. This runs at 05:00 daily, so it was
                // re-creating that every morning.
                $open = DB::table('time_punches')->where('user_id', $s->id)->where('centre_id', $centreId)
                    ->whereNull('punched_out_at')->exists();
                if ($open) continue;
                DB::table('time_punches')->insert([
                    'user_id' => $s->id, 'centre_id' => $centreId,
                    'punched_in_at' => $today->copy()->setTime(7, 30),
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
        $this->section('attendance', function () use ($children, $roomFor, $recorder, $today) {
            $n = 0;
            foreach ($children as $i => $c) {
                $already = DB::table('check_events')->where('child_id', $c->id)
                    ->where('event_type', 'check_in')->whereDate('occurred_at', $today->toDateString())->exists();
                if ($already) continue;
                // Demo realism: leave ~30% of children NOT checked in, so the
                // agency-overview "who's in / who's out" view shows both sides.
                if ($i % 10 >= 7) continue;
                $room = $roomFor($i);
                $moods = ['happy', 'calm', 'tired', 'happy'];
                DB::table('check_events')->insert([
                    'child_id' => $c->id, 'room_id' => $room, 'event_type' => 'check_in',
                    'occurred_at' => $today->copy()->setTime(8, 5 + ($i % 40)),
                    'by_user_id' => $recorder->id, 'recorded_by_id' => $recorder->id, 'mood_at_event' => $moods[$i % 4],
                    'notes' => '[Demo] Dropped off by parent', 'created_at' => now(),
                ]);
                DB::table('check_events')->insert([
                    'child_id' => $c->id, 'room_id' => $room, 'event_type' => 'check_out',
                    'occurred_at' => $today->copy()->setTime(16, 10 + ($i % 40)),
                    'by_user_id' => $recorder->id, 'recorded_by_id' => $recorder->id, 'notes' => '[Demo] Picked up', 'created_at' => now(),
                ]);
                $n += 2;
            }
            return $n;
        });

        // ── 3) Daily log events (meal / nap / mood / activity) ──
        $this->section('daily events', function () use ($children, $roomFor, $educators, $today) {
            $n = 0;
            $plan = [
                ['activity', 9, 30, '[Demo] Circle time — songs and calendar'],
                ['snack', 10, 0, '[Demo] Morning snack — apple slices, ate well'],
                ['meal', 12, 0, '[Demo] Lunch — pasta and veggies, finished most'],
                ['nap_start', 13, 0, '[Demo] Settled for nap'],
                ['nap_end', 14, 45, '[Demo] Woke up rested'],
                ['mood', 15, 30, '[Demo] Cheerful during free play'],
            ];
            foreach ($children as $i => $c) {
                if (DB::table('daily_events')->where('child_id', $c->id)->whereDate('occurred_at', $today->toDateString())->exists()) continue;
                $ed = $educators[$i % max(1, $educators->count())];
                foreach ($plan as $p) {
                    DB::table('daily_events')->insert([
                        'child_id' => $c->id, 'room_id' => $roomFor($i), 'event_type' => $p[0],
                        'occurred_at' => $today->copy()->setTime($p[1], $p[2]), 'payload' => '{}',
                        'notes' => $p[3], 'recorded_by_id' => $ed->id ?? null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $n++;
                }
            }
            return $n;
        });

        // ── 4) Observations (learning stories) — a few new ones per day ──
        $this->section('observations', function () use ($children, $educators, $today, $dstamp) {
            $n = 0;
            $samples = [
                ['physical', 'Confident on the climbing structure', 'Reached the top of the climber independently and beamed with pride. Growing gross-motor confidence.'],
                ['language_literacy', 'Retold the story in their own words', 'After story time, narrated the plot back to a friend, using new vocabulary from the book.'],
                ['social_emotional', 'Comforted a friend', 'Noticed a peer was upset and offered a hug and a toy without prompting. Lovely empathy.'],
                ['creative_arts', 'Mixed colours at the easel', 'Discovered that blue and yellow make green and spent a long, focused stretch painting.'],
                ['cognitive', 'Sorted blocks by size', 'Independently arranged the blocks from smallest to largest and counted them aloud.'],
            ];
            $picks = $children->shuffle()->take(3);
            foreach ($picks as $k => $c) {
                $s = $samples[($c->id + $k) % count($samples)];
                $title = "[Demo] {$s[1]} ({$dstamp})";
                if (DB::table('observations')->where('child_id', $c->id)->where('title', $title)->exists()) continue;
                $ed = $educators[$k % max(1, $educators->count())];
                DB::table('observations')->insert([
                    'child_id' => $c->id, 'domain' => $s[0], 'title' => $title, 'body' => $s[2],
                    'observed_at' => $today->copy()->setTime(11, 0)->addMinutes($k * 7),
                    'recorded_by_id' => $ed->id ?? null, 'shared_with_family' => 1,
                    'created_at' => now(),
                ]);
                $n++;
            }
            return $n;
        });

        // ── 5) One minor incident per day (best-effort — status enum varies) ──
        $this->section('incident', function () use ($children, $roomFor, $recorder, $today, $dstamp) {
            $marker = "[Demo] Minor bump ({$dstamp})";
            if (DB::table('incidents')->where('description', 'LIKE', "%({$dstamp})%")->where('description', 'LIKE', '[Demo]%')->exists()) return 0;
            $idx = $today->day % $children->count();
            $c = $children[$idx];
            DB::table('incidents')->insert([
                'child_id' => $c->id, 'room_id' => $roomFor($idx), 'incident_type' => 'injury', 'severity' => 'minor',
                'occurred_at' => $today->copy()->setTime(10, 45), 'location' => 'Outdoor play area',
                'description' => $marker . ' — small bump on the knee after a trip on the grass.',
                'action_taken' => 'Cleaned, cold compress applied, comforted. No further concern.',
                'first_aid_administered' => 1, 'recorded_by_id' => $recorder->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return 1;
        });

        // ── 6) A daily announcement to families ──
        $this->section('announcement', function () use ($centreId, $director, $recorder, $today, $dstamp) {
            $title = "[Demo] Today at the centre ({$dstamp})";
            if (DB::table('announcements')->where('scope_type', 'centre')->where('scope_id', $centreId)->where('title', $title)->exists()) return 0;
            $bodies = [
                'We had a wonderful day exploring colours in the art studio and a long play outside in the sunshine. Reminder: please label water bottles!',
                'Today the children planted seeds in our garden bed and practised counting during snack. Show-and-tell is this Friday.',
                'Great energy today — lots of building, stories and music. A gentle reminder to pack an extra set of clothes for messy play.',
            ];
            DB::table('announcements')->insert([
                'scope_type' => 'centre', 'scope_id' => $centreId, 'title' => $title,
                'body' => $bodies[$today->day % count($bodies)],
                'send_email' => 0, 'send_sms' => 0, 'send_push' => 0,
                'created_by_id' => ($director->id ?? $recorder->id), 'sent_at' => now(), 'created_at' => now(),
            ]);
            return 1;
        });

        $this->info('Done.');
        return self::SUCCESS;
    }
}
