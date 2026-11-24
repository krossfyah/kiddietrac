<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A LIVED-IN WEBSITE DEMO (2026-10-30, Anthony: "add sample videos and images to the phone emulator for children,
 * menus, lesson plans and fill in demo data across other sections to simulate a live environment including the ability
 * to pay invoices").
 *
 * The baseline content of every PUBLIC demo agency (App\Support\PublicDemo - Brightwood 25, Starlight 26): a photo and
 * video gallery, weekly menus with food photos, lesson plans with activity photos, announcements, an incident report,
 * learning observations, itemised invoices with one bill due now. kt:web-demo-content writes it, and kt:reset-web-demo
 * writes it again every night, so the dates roll forward ("this week" always has a menu and a plan, the newest photos
 * are from the last school day) and anything a visitor changed is gone.
 *
 * Idempotent: every row it owns is found again by a marker (media under photos/web-demo or videos/web-demo, menu weeks
 * and lesson plans of the demo weeks, "web-demo" in director_notes / raw_text, invoice numbers ending -FT) and replaced.
 * Media are CC0 / public-domain images from Wikimedia Commons (storage/app/public/photos/web-demo/CREDITS.txt) and short
 * slideshow clips made from them - no real child anywhere.
 */
final class WebDemoContent
{
    public const IMG = '/storage/photos/web-demo/';
    public const VID = '/storage/videos/web-demo/';
    public const MARK = 'web-demo';

    /** Every public demo agency. */
    public static function run(bool $dry = false): array
    {
        $out = [];
        foreach (PublicDemo::agencyIds() as $id) {
            try { $out[$id] = self::seedAgency((int) $id, $dry); }
            catch (\Throwable $e) { $out[$id] = ['error' => mb_substr($e->getMessage(), 0, 300)]; }
        }
        return $out;
    }

    public static function seedAgency(int $agencyId, bool $dry = false): array
    {
        if (! PublicDemo::isAgency($agencyId)) throw new \RuntimeException("Agency {$agencyId} is not a public demo agency.");
        $agency = DB::table('agencies')->where('id', $agencyId)->first(['id', 'name', 'timezone', 'country']);
        $tz = $agency->timezone ?: 'America/Los_Angeles';
        $c = new self($agencyId, $tz);
        if ($dry) return $c->plan();
        $res = [];
        DB::transaction(function () use ($c, &$res) {
            $res['educator_room'] = $c->educatorRoom();
            $res['photos'] = $c->photos();
            $res['menus'] = $c->menus();
            $res['lesson_plans'] = $c->lessonPlans();
            $res['announcements'] = $c->announcements();
            $res['incidents'] = $c->incidents();
            $res['observations'] = $c->observations();
            $res['billing'] = $c->billing();
        });
        return $res;
    }

    // ───────────────────────────────────────────────────────────────────────────────────────────
    private int $agencyId;
    private string $tz;
    private Carbon $today;
    private array $centres;       // id => row
    private int $main;            // the learning center (most rooms)
    private array $rooms;         // id => row (+ centre_id)
    private array $kidsByRoom;    // room_id => [child rows]
    private array $people;        // parent / educator / director ids of this agency's website logins
    private array $megan;         // the website parent's children
    private int $family;

    private function __construct(int $agencyId, string $tz)
    {
        $this->agencyId = $agencyId;
        $this->tz = $tz;
        $this->today = Carbon::now($tz)->startOfDay();
        $this->centres = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->orderBy('id')->get(['id', 'name'])->keyBy('id')->all();
        $rooms = DB::table('rooms')->whereIn('centre_id', array_keys($this->centres) ?: [0])->orderBy('id')->get(['id', 'centre_id', 'name', 'age_group']);
        $this->rooms = $rooms->keyBy('id')->all();
        $count = $rooms->groupBy('centre_id')->map->count()->sortDesc();
        $this->main = (int) $count->keys()->first();
        $kids = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->whereIn('f.centre_id', array_keys($this->centres) ?: [0])->whereNull('ch.deleted_at')
            ->orderBy('ch.id')->get(['ch.id', 'ch.first_name', 'ch.last_name', 'ch.family_id', 'ch.primary_room_id']);
        $this->kidsByRoom = [];
        foreach ($kids as $k) if ($k->primary_room_id) $this->kidsByRoom[(int) $k->primary_room_id][] = $k;
        $this->people = [];
        foreach (PublicDemo::sets() as $set) {
            if (PublicDemo::agencyOf((int) $set['parent']['id']) === $agencyId) {
                foreach ($set as $role => $a) $this->people[$role] = (int) $a['id'];
            }
        }
        $this->family = (int) DB::table('guardians')->where('user_id', $this->people['parent'] ?? 0)->value('family_id');
        $this->megan = $kids->where('family_id', $this->family)->values()->all();
    }

    private function plan(): array
    {
        return ['centres' => array_keys($this->centres), 'main' => $this->main, 'rooms' => count($this->rooms),
            'people' => $this->people, 'family' => $this->family, 'children_of_website_parent' => array_map(fn ($k) => $k->first_name, $this->megan),
            'photos_now' => DB::table('photos')->whereIn('centre_id', array_keys($this->centres) ?: [0])->where('url', 'like', '%/web-demo/%')->count()];
    }

    /** The last $n school days before today, newest first. */
    private function schoolDays(int $n): array
    {
        $out = []; $d = $this->today->copy();
        while (count($out) < $n) { $d->subDay(); if (! $d->isWeekend()) $out[] = $d->copy(); }
        return $out;
    }

    private function at(Carbon $day, string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return $day->copy()->setTime($h, $m)->utc()->toDateTimeString();
    }

    private function staffFor(int $roomId): int
    {
        $u = DB::table('educator_rooms')->where('room_id', $roomId)->orderBy('id')->value('user_id');
        return (int) ($u ?: ($this->people['director'] ?? 0));
    }

    /** Maya teaches the room the website parent's children are in (the logins file says so; her calls and texts are about them). */
    private function educatorRoom(): int
    {
        $room = (int) ($this->megan[0]->primary_room_id ?? 0);
        $edu = $this->people['educator'] ?? 0;
        if (! $room || ! $edu) return 0;
        if (DB::table('educator_rooms')->where('user_id', $edu)->where('room_id', $room)->exists()) return 0;
        DB::table('educator_rooms')->insert(['user_id' => $edu, 'room_id' => $room, 'assigned_by_id' => $this->people['director'] ?? null, 'created_at' => now()]);
        return 1;
    }

    // ── Photos and videos ───────────────────────────────────────────────────────────────────────
    private const PHOTOS = [
        ['watercolours', 'Watercolor painting in the art studio 🎨'], ['colour-experiment', 'Color mixing experiments - look what happens with red and yellow!'],
        ['playdough', 'Play dough creations ✨'], ['bricks', 'Building towers with bricks'], ['puzzle', 'Puzzle time - teamwork!'],
        ['xylophone', 'Music and movement 🎶'], ['sandbox', 'The sandbox construction crew 🚧'], ['dino-slide', 'Outdoor play on the dinosaur slide 🦕'],
        ['sprout', 'Our bean sprouts are growing! 🌱'], ['leaves', 'Leaf hunt - sorting by color 🍂'], ['pumpkins', 'Pumpkin patch day 🎃'],
        ['bubble', 'Bubble science 🫧'], ['ride-on', 'Ride-on races in the yard'], ['chalk', 'Sidewalk chalk art'], ['kindness-rocks', 'Painting kindness rocks ❤️'],
        ['pine-cone', 'Nature walk treasures'], ['teddy', 'Teddy bear picnic 🧸'], ['egg-shakers', 'Making music with egg shakers'],
        ['brushes', 'Getting the art studio ready'], ['dino-pinata', 'Dinosaur week! 🦖'], ['infant-toys', 'Sensory play time'], ['kite', 'Windy day kites 🪁'],
        ['garden-visitors', 'Spotting bees in the garden 🐝'], ['garden-beds', 'Watering the garden beds'], ['sunflower', 'Our sunflower is taller than us! 🌻'],
        ['crayon-drawing', 'Drawing ourselves and our homes'], ['mark-making', 'Early mark making'], ['puzzle-green', 'Puzzle pieces everywhere'],
        ['sandbox-slide', 'Digging and scooping'], ['ride-on-yellow', 'Taking turns on the ride-on cars'], ['maracas', 'Shake, shake, shake! 🪇'],
    ];
    private const VIDEOS = [
        ['art-studio', 'Art studio: painting and color mixing 🎨'], ['music-movement', 'Music and movement 🎶'], ['garden-time', 'Garden time 🌱'],
        ['fall-leaf-hunt', 'Fall leaf hunt and the pumpkin patch 🍂'], ['outdoor-play', 'Outdoor play'], ['snack-time', 'Healthy snack time 🍉'],
        ['building-puzzles', 'Building and puzzles'], ['bubbles-and-chalk', 'Bubbles, chalk and kindness rocks'],
    ];

    private function photos(): int
    {
        $centres = array_keys($this->centres);
        DB::table('photos')->whereIn('centre_id', $centres)->where(fn ($q) => $q->where('url', 'like', self::IMG . '%')->orWhere('url', 'like', self::VID . '%'))->delete();
        if (Schema::hasTable('videos')) DB::table('videos')->whereIn('centre_id', $centres)->where('url', 'like', self::VID . '%')->delete();
        $days = $this->schoolDays(10);
        $times = ['09:20', '10:05', '10:40', '11:15', '13:30', '14:10', '15:00', '15:45'];
        $rows = []; $n = 0;
        $megRoom = (int) ($this->megan[0]->primary_room_id ?? 0);
        $meganIds = array_map(fn ($k) => (int) $k->id, $this->megan);
        foreach ($this->rooms as $rid => $room) {
            $kids = array_map(fn ($k) => (int) $k->id, $this->kidsByRoom[$rid] ?? []);
            if (! $kids) continue;
            $others = array_values(array_diff($kids, $meganIds));
            $isMeg = $rid === $megRoom;
            $count = $isMeg ? 18 : ((int) $room->centre_id === $this->main ? 5 : 4);
            for ($i = 0; $i < $count; $i++) {
                [$slug, $caption] = self::PHOTOS[($i * 7 + $rid) % count(self::PHOTOS)];
                if ($room->age_group === 'infant' && $i % 3 === 0) [$slug, $caption] = ['infant-toys', 'Sensory play time'];
                $tag = [];
                if ($isMeg && $i % 5 !== 4) $tag[] = $meganIds[$i % max(1, count($meganIds))] ?? null;
                if ($isMeg && $i % 3 === 0 && count($meganIds) > 1) $tag[] = $meganIds[($i + 1) % count($meganIds)];
                for ($k = 0; $k < 1 + $i % 3 && $others; $k++) $tag[] = $others[($i * 3 + $k) % count($others)];
                $tag = array_values(array_unique(array_filter($tag)));
                $day = $days[intdiv($i, 2) % count($days)];
                $rows[] = ['centre_id' => $room->centre_id, 'uploaded_by_id' => $isMeg ? ($this->people['educator'] ?? $this->staffFor($rid)) : $this->staffFor($rid),
                    'url' => self::IMG . $slug . '.jpg', 'thumbnail_url' => self::IMG . $slug . '-thumb.jpg', 'media_type' => 'image', 'caption' => $caption,
                    'child_ids' => json_encode($tag), 'taken_at' => $this->at($day, $times[($i + $rid) % count($times)]), 'created_at' => now()];
                $n++;
            }
            // Videos: five for the website parent's room, one each for the rest of the main centre.
            $vids = $isMeg ? 5 : ((int) $room->centre_id === $this->main ? 1 : 0);
            for ($v = 0; $v < $vids; $v++) {
                [$slug, $caption] = self::VIDEOS[($v + $rid) % count(self::VIDEOS)];
                $tag = $isMeg ? array_values(array_unique(array_merge($meganIds, array_slice($others, $v, 2)))) : array_slice($others, $v, 3);
                $when = $this->at($days[$v % count($days)], ['10:20', '14:35', '11:05', '15:20', '09:50'][$v % 5]);
                $by = $isMeg ? ($this->people['educator'] ?? $this->staffFor($rid)) : $this->staffFor($rid);
                $rows[] = ['centre_id' => $room->centre_id, 'uploaded_by_id' => $by, 'url' => self::VID . $slug . '.mp4',
                    'thumbnail_url' => self::VID . $slug . '-poster.jpg', 'media_type' => 'video', 'caption' => $caption,
                    'child_ids' => json_encode($tag), 'taken_at' => $when, 'created_at' => now()];
                if (Schema::hasTable('videos')) {
                    DB::table('videos')->insert(['centre_id' => $room->centre_id, 'uploaded_by_id' => $by, 'url' => self::VID . $slug . '.mp4',
                        'thumbnail_url' => self::VID . $slug . '-poster.jpg', 'duration_seconds' => 10, 'caption' => $caption, 'child_ids' => json_encode($tag),
                        'taken_at' => $when, 'file_size_bytes' => self::size(self::VID . $slug . '.mp4'), 'created_at' => now()]);
                }
                $n++;
            }
        }
        foreach (array_chunk($rows, 100) as $chunk) DB::table('photos')->insert($chunk);
        return $n;
    }

    private static function size(string $url): int
    {
        $p = storage_path('app/public/' . preg_replace('#^/storage/#', '', $url));
        return is_file($p) ? (int) filesize($p) : 0;
    }

    // ── Menus ─────────────────────────────────────────────────────────────────────────────────
    /** Monday..Friday x breakfast, morning snack, lunch, afternoon snack: [name, description, allergens, photo]. */
    private const MENU = [
        [['Oatmeal with cinnamon and apples', 'Whole oats, diced apple, milk', 'Milk', 'food-oatmeal'], ['Orange slices', 'Fresh oranges', null, 'food-orange'],
         ['Macaroni and cheese with broccoli', 'Whole-wheat pasta, cheddar, steamed broccoli', 'Wheat, Milk', 'food-mac-cheese'], ['Crackers, apple and carrots', 'Whole-grain crackers, apple slices, carrot sticks', 'Wheat', 'food-crackers-apple']],
        [['Scrambled eggs on whole-wheat toast', 'Eggs, toast, fruit', 'Egg, Wheat', 'food-eggs-toast'], ['Yogurt and berries', 'Plain yogurt, blueberries, raspberries', 'Milk', 'food-yogurt-berries'],
         ['Bean and cheese quesadilla', 'Whole-wheat tortilla, black beans, cheese, cucumber', 'Wheat, Milk', 'food-quesadilla'], ['Watermelon', 'Fresh watermelon', null, 'food-watermelon']],
        [['Whole-wheat pancakes', 'With blueberries and a little butter', 'Wheat, Egg, Milk', 'food-pancakes'], ['Rainbow carrots', 'Steamed carrot sticks', null, 'food-rainbow-carrots'],
         ['Turkey meatballs with noodles', 'Turkey meatballs, egg noodles, peas', 'Wheat, Egg', 'food-meatballs'], ['Hummus and vegetables', 'Hummus, broccoli, carrots', 'Sesame', 'food-hummus-veg']],
        [['Blueberry muffin and milk', 'Baked here this morning', 'Wheat, Egg, Milk', 'food-muffin'], ['Cucumber slices', 'Crunchy cucumber', null, 'food-cucumber'],
         ['Grilled cheese and vegetable soup', 'Whole-wheat bread, cheddar, tomato-vegetable soup', 'Wheat, Milk', 'food-soup-grilled-cheese'], ['Citrus slices', 'Oranges and clementines', null, 'food-citrus']],
        [['Veggie egg muffins', 'Eggs, spinach, peppers, cheese', 'Egg, Milk', 'food-egg-muffins'], ['Watermelon', 'Fresh watermelon', null, 'food-watermelon'],
         ['Roasted sweet potato with chicken and broccoli', 'Sweet potato, chicken, broccoli', null, 'food-sweet-potato'], ['Yogurt and berries', 'Plain yogurt, mixed berries', 'Milk', 'food-yogurt-berries']],
    ];
    private const MEALS = ['breakfast', 'morning_snack', 'lunch', 'afternoon_snack'];

    private function menus(): int
    {
        $mon = $this->today->copy()->startOfWeek(Carbon::MONDAY);
        $weeks = [$mon->copy()->subWeek(), $mon->copy(), $mon->copy()->addWeek()];
        $img = Schema::hasColumn('menu_items', 'image_url');
        $n = 0;
        foreach (array_keys($this->centres) as $cid) {
            $old = DB::table('menu_weeks')->where('centre_id', $cid)->whereIn('week_start', array_map(fn ($w) => $w->toDateString(), $weeks))->pluck('id');
            if ($old->count()) { DB::table('menu_items')->whereIn('menu_week_id', $old)->delete(); DB::table('menu_weeks')->whereIn('id', $old)->delete(); }
            foreach ($weeks as $wi => $w) {
                $id = DB::table('menu_weeks')->insertGetId(['centre_id' => $cid, 'week_start' => $w->toDateString(), 'status' => 'published',
                    'notes' => 'Fall menu. Water is offered all day. Tell us about any new allergies or dietary needs.',
                    'meal_times' => json_encode(['breakfast' => '08:30', 'morning_snack' => '10:15', 'lunch' => '12:00', 'afternoon_snack' => '15:15']),
                    'created_by_id' => $this->people['director'] ?? null, 'published_at' => $w->copy()->subDays(3)->setTime(16, 0)->utc()->toDateTimeString(),
                    'created_at' => now(), 'updated_at' => now()]);
                $items = [];
                for ($d = 0; $d < 5; $d++) {
                    $day = self::MENU[($d + $wi) % 5];
                    foreach (self::MEALS as $mi => $meal) {
                        [$name, $desc, $all, $photo] = $day[$mi];
                        $row = ['menu_week_id' => $id, 'day_of_week' => $d + 1, 'meal_type' => $meal, 'name' => $name, 'description' => $desc, 'allergens' => $all];
                        if ($img) $row['image_url'] = self::IMG . $photo . '-thumb.jpg';
                        $items[] = $row;
                    }
                }
                DB::table('menu_items')->insert($items);
                $n++;
            }
        }
        return $n;
    }

    // ── Lesson plans ────────────────────────────────────────────────────────────────────────────
    private const THEMES = [
        'infant'     => ['All about me: senses', [['Tummy time with crinkle books', 'physical', 'infant-toys'], ['Shaker bottles and songs', 'creative_arts', 'egg-shakers'], ['Outdoor stroller walk', 'outdoor', 'leaves'], ['Board book cuddles', 'language_literacy', null]]],
        'toddler'    => ['Fall harvest', [['Leaf hunt and color sorting', 'cognitive', 'leaves'], ['Pumpkin painting', 'creative_arts', 'pumpkins'], ['Music and movement', 'physical', 'xylophone'], ['Story time: "The Little Seed"', 'language_literacy', null], ['Planting bean sprouts', 'outdoor', 'sprout'], ['Play dough pumpkins', 'creative_arts', 'playdough']]],
        'preschool'  => ['Gardens and growing', [['Garden bed watering crew', 'outdoor', 'garden-beds'], ['Sunflower measuring', 'cognitive', 'sunflower'], ['Kindness rocks', 'social_emotional', 'kindness-rocks'], ['Watercolor sunflowers', 'creative_arts', 'watercolours'], ['Bug hunt with magnifiers', 'outdoor', 'garden-visitors'], ['Letter of the week: G', 'language_literacy', null]]],
        'school_age' => ['Science club', [['Bubble science', 'cognitive', 'bubble'], ['Kite design and test flights', 'outdoor', 'kite'], ['Homework hour', 'language_literacy', null], ['Team puzzle challenge', 'social_emotional', 'puzzle-green']]],
    ];

    private function lessonPlans(): int
    {
        $mon = $this->today->copy()->startOfWeek(Carbon::MONDAY);
        $weeks = [$mon->copy()->subWeek(), $mon->copy(), $mon->copy()->addWeek()];
        $slots = ['09:30', '10:30', '11:15', '14:00', '15:15'];
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
        $n = 0;
        foreach ($this->rooms as $rid => $room) {
            DB::table('lesson_plans')->where('room_id', $rid)->whereIn('week_starting', array_map(fn ($w) => $w->toDateString(), $weeks))->delete();
            [$theme, $acts] = self::THEMES[$room->age_group] ?? self::THEMES['preschool'];
            foreach ($weeks as $wi => $w) {
                $plan = ['web_demo' => true, 'days' => []];
                foreach ($days as $di => $day) {
                    $list = [['time' => '08:45', 'title' => 'Morning circle and weather chart', 'domain' => 'social_emotional', 'notes' => '']];
                    for ($k = 0; $k < 3; $k++) {
                        [$title, $domain, $photo] = $acts[($di * 2 + $k + $wi) % count($acts)];
                        $a = ['time' => $slots[$k + ($k > 1 ? 1 : 0)], 'title' => $title, 'domain' => $domain, 'notes' => ''];
                        if ($photo) $a['image'] = self::IMG . $photo . '-thumb.jpg';
                        $list[] = $a;
                    }
                    $plan['days'][$day] = $list;
                }
                DB::table('lesson_plans')->insert(['room_id' => $rid, 'centre_id' => $room->centre_id, 'week_starting' => $w->toDateString(),
                    'theme' => $theme . ($wi === 2 ? ' (week 2)' : ''), 'status' => 'published', 'published_at' => $w->copy()->subDays(3)->setTime(15, 0)->utc()->toDateTimeString(),
                    'plan_data' => json_encode($plan), 'created_by_id' => $this->staffFor($rid) ?: null, 'created_at' => now(), 'updated_at' => now()]);
                $n++;
            }
        }
        return $n;
    }

    // ── Announcements ───────────────────────────────────────────────────────────────────────────
    private function announcements(): int
    {
        $titles = ['Pumpkin patch field trip on Friday 🎃', 'Fall picture day next week 📸', 'Our garden harvest is in! 🥕', 'Family Fall Festival - you are invited 🍂'];
        DB::table('announcements')->where('scope_type', 'centre')->whereIn('scope_id', array_keys($this->centres))->whereIn('title', $titles)->delete();
        $days = $this->schoolDays(8);
        $rows = [
            [$titles[0], "We're heading to Rancho Pumpkin Patch on Friday morning. Please pack rain boots, a labeled water bottle and a warm layer. The $12 trip fee is on this month's invoice.", 0, 4],
            [$titles[1], 'Picture day is Tuesday and Wednesday next week. Order forms are in your child\'s cubby - the fall package is $25 and is on your invoice.', 2, null],
            [$titles[2], 'The children harvested carrots, kale and two very proud sunflowers from our garden beds this week. Look for the photos in the gallery!', 4, null],
            [$titles[3], 'Join us for the Family Fall Festival: crafts, music, a story corner and a healthy snack bar. Siblings welcome!', 6, 14],
        ];
        foreach (array_keys($this->centres) as $cid) {
            foreach ($rows as [$t, $b, $ago, $due]) {
                $sent = $days[$ago]->copy()->setTime(16, 30);
                DB::table('announcements')->insert(['scope_type' => 'centre', 'scope_id' => $cid, 'audience' => 'parents', 'title' => $t, 'body' => $b,
                    'send_email' => 0, 'send_sms' => 0, 'send_push' => 0, 'created_by_id' => $this->people['director'] ?? null,
                    'due_at' => $due ? $this->today->copy()->addDays($due)->setTime(10, 0)->utc()->toDateTimeString() : null,
                    'sent_at' => $sent->utc()->toDateTimeString(), 'created_at' => $sent->utc()->toDateTimeString()]);
            }
        }
        return count($rows) * count($this->centres);
    }

    // ── Incident reports ────────────────────────────────────────────────────────────────────────
    private function incidents(): int
    {
        $kids = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')->whereIn('f.centre_id', array_keys($this->centres))->pluck('ch.id');
        DB::table('incidents')->whereIn('child_id', $kids)->where('director_notes', 'like', '%(' . self::MARK . ')%')->delete();
        $n = 0;
        $days = $this->schoolDays(6);
        $k = $this->megan[0] ?? null;
        if ($k) {
            $when = $days[2]->copy()->setTime(10, 40);
            DB::table('incidents')->insert(['child_id' => $k->id, 'room_id' => $k->primary_room_id, 'incident_type' => 'injury', 'severity' => 'low',
                'occurred_at' => $when->toDateTimeString(), 'location' => 'Outdoor play yard - climber',   // wall clock, not UTC
                'description' => $k->first_name . ' slipped on the bottom step of the climber and bumped his knee. He was upset for a minute, then went back to play.',
                'action_taken' => 'Cleaned the area, applied a cold pack for 5 minutes, comforted. No swelling or broken skin.',
                'first_aid_administered' => 1, 'recorded_by_id' => $this->people['educator'] ?? null, 'status' => 'acknowledged',
                'submitted_at' => $when->copy()->addMinutes(25)->utc()->toDateTimeString(), 'director_reviewed_at' => $when->copy()->addHours(1)->utc()->toDateTimeString(),
                'reviewed_at' => $when->copy()->addHours(1)->utc()->toDateTimeString(), 'reviewed_by_id' => $this->people['director'] ?? null,
                'parent_notified_at' => $when->copy()->addHours(1)->utc()->toDateTimeString(), 'parent_acknowledged_at' => $when->copy()->addHours(6)->utc()->toDateTimeString(),
                'acknowledged_at' => $when->copy()->addHours(6)->utc()->toDateTimeString(), 'director_notes' => 'Reviewed - no follow-up needed. (' . self::MARK . ')',
                'witnesses' => json_encode([['name' => 'Maya Thompson', 'role' => 'Educator']]), 'body_parts_affected' => json_encode(['left knee']), 'created_at' => now(), 'updated_at' => now()]);
            $n++;
        }
        // One for the director to review, in another room.
        foreach ($this->kidsByRoom as $rid => $list) {
            if ((int) ($this->rooms[$rid]->centre_id ?? 0) !== $this->main || ($k && $rid === (int) $k->primary_room_id)) continue;
            $c = $list[1] ?? $list[0]; $when = $days[0]->copy()->setTime(14, 15);
            DB::table('incidents')->insert(['child_id' => $c->id, 'room_id' => $rid, 'incident_type' => 'injury', 'severity' => 'low',
                'occurred_at' => $when->toDateTimeString(), 'location' => 'Classroom - block area',   // wall clock, not UTC
                'description' => $c->first_name . ' caught a finger between two large blocks while building. Small red mark, no broken skin.',
                'action_taken' => 'Cold cloth, comforted, checked again after 20 minutes.', 'first_aid_administered' => 1,
                'recorded_by_id' => $this->staffFor($rid) ?: null, 'status' => 'submitted', 'submitted_at' => $when->copy()->addMinutes(30)->utc()->toDateTimeString(),
                'parent_notified_at' => $when->copy()->addMinutes(40)->utc()->toDateTimeString(), 'director_notes' => '(' . self::MARK . ')',
                'created_at' => now(), 'updated_at' => now()]);
            $n++;
            break;
        }
        return $n;
    }

    // ── Observations for the website parent's children ──────────────────────────────────────────
    private function observations(): int
    {
        $ids = array_map(fn ($k) => (int) $k->id, $this->megan);
        if (! $ids) return 0;
        DB::table('observations')->whereIn('child_id', $ids)->where('raw_text', self::MARK)->delete();
        $notes = [
            ['social_emotional', 'Shared the shovel without being asked', 'In the sandbox {name} handed a friend the big shovel and said "your turn". A lovely moment of empathy and turn-taking.'],
            ['language_literacy', 'Named five colors during the leaf hunt', '{name} sorted leaves into piles and named red, yellow, orange, green and brown. New word today: "crunchy"!'],
            ['physical', 'Climbed the ladder to the slide independently', '{name} climbed all four steps holding the rail, then slid down with a big smile. Growing confidence on the climber.'],
            ['creative_arts', 'Mixed red and yellow to make orange', 'At the paint table {name} discovered that red and yellow make orange, and announced it to the whole room.'],
        ];
        $days = $this->schoolDays(9); $n = 0;
        foreach ($this->megan as $ki => $k) {
            foreach ($notes as $i => [$dom, $title, $body]) {
                if (($i + $ki) % 2 && $i > 1) continue;
                DB::table('observations')->insert(['child_id' => $k->id, 'framework' => 'HDLH', 'domain' => $dom, 'title' => $title,
                    'body' => str_replace('{name}', $k->first_name, $body), 'observed_at' => $this->at($days[($i * 2 + $ki) % count($days)], '10:30'),
                    'recorded_by_id' => $this->people['educator'] ?? null, 'shared_with_family' => 1, 'raw_text' => self::MARK, 'ai_generated' => 0, 'created_at' => now()]);
                $n++;
            }
        }
        return $n;
    }

    // ── Billing ─────────────────────────────────────────────────────────────────────────────────
    /**
     * Itemised invoices: each tuition line per child, and this month's and next month's bills exist whatever the date
     * (the seeder only billed the months around the day it ran). The website parent always has something to pay: the
     * current tuition bill (put back unpaid each night by WebDemoPayments::restore) and a small "field trip + picture
     * day" bill due within the week.
     */
    private function billing(): array
    {
        $fams = DB::table('families as f')->whereIn('f.centre_id', array_keys($this->centres))
            ->leftJoin('guardians as g', function ($j) { $j->on('g.family_id', '=', 'f.id')->where('g.is_primary', 1); })
            ->orderBy('f.id')->get(['f.id', 'f.centre_id', 'f.family_name', 'g.id as guardian_id']);
        $prefix = (string) (DB::table('invoices')->whereIn('centre_id', array_keys($this->centres))->value('invoice_number') ?: 'DM-');
        $prefix = preg_match('/^([A-Z]+)-/', $prefix, $m) ? $m[1] . '-' : 'DM-';
        $made = 0; $lines = 0;
        foreach ($fams as $f) {
            $enr = DB::table('enrollments as e')->join('children as ch', 'ch.id', '=', 'e.child_id')->join('rooms as r', 'r.id', '=', 'e.room_id')
                ->where('ch.family_id', $f->id)->whereNull('ch.deleted_at')->get(['ch.id', 'ch.first_name', 'e.monthly_fee', 'r.name as room']);
            $fee = (float) $enr->sum('monthly_fee');
            if ($fee <= 0) continue;
            // A provider's own children are not billed (the seeder skips them too).
            $staffFamily = DB::table('guardians as g')->join('role_assignments as r', 'r.user_id', '=', 'g.user_id')
                ->where('g.family_id', $f->id)->where('r.agency_id', $this->agencyId)->where('r.active', 1)->exists();
            for ($k = 1; $k >= -1 && ! $staffFamily; $k--) {
                $start = $this->today->copy()->startOfMonth()->subMonths($k);
                $num = $prefix . $start->format('Ym') . '-' . str_pad((string) $f->id, 4, '0', STR_PAD_LEFT);
                if (DB::table('invoices')->where('invoice_number', $num)->exists()) continue;
                $issued = $start->copy()->subDays(5);
                if ($issued->gt($this->today)) continue;
                $due = $start->copy()->addDays(9);
                $paid = $k >= 1 || ($k === 0 && $due->lte($this->today) && (int) $f->id !== $this->family);
                $newId = DB::table('invoices')->insertGetId(['family_id' => $f->id, 'centre_id' => $f->centre_id, 'invoice_number' => $num,
                    'period_start' => $start->toDateString(), 'period_end' => $start->copy()->endOfMonth()->toDateString(),
                    'subtotal' => $fee, 'subsidy_amount' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'total' => $fee,
                    'amount_paid' => $paid ? $fee : 0, 'balance_due' => $paid ? 0 : $fee, 'issued_at' => $issued->toDateString(),
                    'due_at' => $due->toDateString(), 'status' => $paid ? 'paid' : ($due->lt($this->today) ? 'overdue' : 'sent'),
                    'notes' => 'Monthly tuition', 'created_at' => now(), 'updated_at' => now()]);
                if ($paid) {
                    DB::table('payments')->insert(['invoice_id' => $newId, 'family_id' => $f->id, 'payer_guardian_id' => $f->guardian_id, 'amount' => $fee,
                        'method' => ['stripe_card', 'stripe_ach', 'cheque'][$f->id % 3], 'status' => 'succeeded', 'reference_number' => 'DEMO-' . $newId,
                        'paid_at' => $due->copy()->subDays(2)->setTime(10, 15)->utc()->toDateTimeString(), 'notes' => 'Autopay',
                        'created_at' => now(), 'updated_at' => now()]);
                }
                $made++;
            }
            // Lines for every tuition invoice that has none.
            foreach (DB::table('invoices')->where('family_id', $f->id)->get(['id', 'period_start', 'invoice_number']) as $inv) {
                if (str_ends_with((string) $inv->invoice_number, '-FT') || DB::table('invoice_lines')->where('invoice_id', $inv->id)->exists()) continue;
                $month = Carbon::parse($inv->period_start)->format('F Y');
                foreach ($enr as $e) {
                    DB::table('invoice_lines')->insert(['invoice_id' => $inv->id, 'child_id' => $e->id, 'description' => "Tuition - {$e->first_name} ({$e->room}) - {$month}",
                        'line_type' => 'tuition', 'quantity' => 1, 'unit_amount' => $e->monthly_fee, 'amount' => $e->monthly_fee, 'tax_rate' => 0]);
                    $lines++;
                }
            }
        }
        // The website parent: this month's or next month's tuition unpaid (whichever is in the due-now window), and the trip bill.
        $extra = 0;
        if ($this->family) {
            $start = $this->today->copy()->startOfMonth();
            $cur = DB::table('invoices')->where('family_id', $this->family)->where('period_start', '<=', $this->today->toDateString())
                ->where('invoice_number', 'not like', '%-FT')->orderByDesc('period_start')->first();
            $nextNum = $prefix . $start->copy()->addMonth()->format('Ym') . '-' . str_pad((string) $this->family, 4, '0', STR_PAD_LEFT);
            $next = DB::table('invoices')->where('invoice_number', $nextNum)->first();
            $open = $next ?: $cur;
            // Older tuition bills are history: paid (a month that rolled past while still open is settled here).
            if ($open) {
                foreach (DB::table('invoices')->where('family_id', $this->family)->where('invoice_number', 'not like', '%-FT')
                    ->where('period_start', '<', $open->period_start)->where('balance_due', '>', 0)->get() as $old) {
                    DB::table('payments')->insert(['invoice_id' => $old->id, 'family_id' => $this->family,
                        'payer_guardian_id' => DB::table('guardians')->where('family_id', $this->family)->where('user_id', $this->people['parent'] ?? 0)->value('id'),
                        'amount' => $old->balance_due, 'method' => 'stripe_card', 'status' => 'succeeded', 'reference_number' => 'DEMO-' . $old->id,
                        'paid_at' => Carbon::parse($old->due_at, $this->tz)->subDays(2)->setTime(10, 15)->utc()->toDateTimeString(),
                        'notes' => 'Autopay', 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('invoices')->where('id', $old->id)->update(['amount_paid' => $old->total, 'balance_due' => 0, 'status' => 'paid', 'updated_at' => now()]);
                }
            }
            if ($open && (float) $open->balance_due < 0.005 && ! DB::table('payments')->where('invoice_id', $open->id)->where('reference_number', 'like', 'DEMO-PAY-%')->exists()) {
                DB::table('payments')->where('invoice_id', $open->id)->delete();
                DB::table('invoices')->where('id', $open->id)->update(['amount_paid' => 0, 'balance_due' => $open->total,
                    'status' => Carbon::parse($open->due_at)->lt($this->today) ? 'overdue' : 'sent', 'updated_at' => now()]);
            }
            $ftNum = $prefix . $start->format('Ym') . '-' . str_pad((string) $this->family, 4, '0', STR_PAD_LEFT) . '-FT';
            $ft = DB::table('invoices')->where('invoice_number', $ftNum)->first();
            if (! $ft) {
                // Last month's trip bill (if any) stays as history, paid.
                DB::table('invoices')->where('family_id', $this->family)->where('invoice_number', 'like', '%-FT')->where('balance_due', '>', 0)
                    ->update(['amount_paid' => DB::raw('total'), 'balance_due' => 0, 'status' => 'paid', 'updated_at' => now()]);
                $kids = $this->megan; $total = 12 * count($kids) + 25;
                $id = DB::table('invoices')->insertGetId(['family_id' => $this->family, 'centre_id' => DB::table('families')->where('id', $this->family)->value('centre_id'),
                    'invoice_number' => $ftNum, 'period_start' => $start->toDateString(), 'period_end' => $start->copy()->endOfMonth()->toDateString(),
                    'subtotal' => $total, 'subsidy_amount' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'total' => $total, 'amount_paid' => 0,
                    'balance_due' => $total, 'issued_at' => $this->today->copy()->subDays(2)->toDateString(), 'due_at' => $this->today->copy()->addDays(5)->toDateString(),
                    'status' => 'sent', 'notes' => 'Field trip and picture day', 'created_at' => now(), 'updated_at' => now()]);
                foreach ($kids as $k) {
                    DB::table('invoice_lines')->insert(['invoice_id' => $id, 'child_id' => $k->id, 'description' => "Pumpkin patch field trip - {$k->first_name}",
                        'line_type' => 'field_trip', 'quantity' => 1, 'unit_amount' => 12, 'amount' => 12, 'tax_rate' => 0]);
                }
                DB::table('invoice_lines')->insert(['invoice_id' => $id, 'child_id' => null, 'description' => 'Fall picture day package',
                    'line_type' => 'supply', 'quantity' => 1, 'unit_amount' => 25, 'amount' => 25, 'tax_rate' => 0]);
                $extra = 1;
            } else {
                DB::table('invoices')->where('id', $ft->id)->update(['due_at' => $this->today->copy()->addDays(5)->toDateString()]);
            }
        }
        return ['invoices_added' => $made, 'lines_added' => $lines, 'trip_bill' => $extra];
    }
}
