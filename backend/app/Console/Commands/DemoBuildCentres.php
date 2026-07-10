<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Rebrands the Test Agency's demo centres and spins up extra centres with a
 * full base dataset (rooms + families + children + staff) so the agency looks
 * like a real multi-centre operator for demos. Idempotent: a centre already
 * present (by name) is only re-branded, not duplicated. Run demo:seed-daily
 * afterwards to add attendance / activity.
 *
 *   php artisan demo:build-centres --agency=6
 */
class DemoBuildCentres extends Command
{
    protected $signature = 'demo:build-centres {--agency=6 : Agency id to build out}';

    protected $description = 'Rebrand + create demo centres (rooms, families, children, staff) for the Test Agency.';

    private function logo(string $seed): string
    {
        return 'https://api.dicebear.com/9.x/shapes/svg?seed=' . rawurlencode($seed)
            . '&backgroundColor=1f6080,16a34a,7c3aed,ea580c,0891b2&radius=16';
    }

    private function kidAvatar(string $seed): string
    {
        return 'https://api.dicebear.com/9.x/adventurer/svg?seed=' . rawurlencode($seed)
            . '&backgroundColor=b6e3f4,c0aede,ffd5dc,ffdfbf,d1f4d9';
    }

    public function handle(): int
    {
        $agencyId = (int) $this->option('agency');
        if (! DB::table('agencies')->where('id', $agencyId)->exists()) {
            $this->error("Agency {$agencyId} not found.");
            return self::FAILURE;
        }

        // Target roster of centres. The first re-brands the original demo centre
        // (whatever it's currently called); the rest are created if missing.
        $centrePlan = [
            ['name' => 'Maple Grove Early Learning', 'city' => 'Orangeville', 'brand' => '#1F6080', 'accent' => '#8EC73C', 'cap' => 64, 'tag' => 'Nature-inspired play & learning', 'rename_existing' => true],
            ['name' => 'Sunny Meadows Childcare',    'city' => 'Caledon',     'brand' => '#16A34A', 'accent' => '#F59E0B', 'cap' => 52, 'tag' => 'Where every day is an adventure'],
            ['name' => 'Little Explorers Academy',   'city' => 'Shelburne',   'brand' => '#7C3AED', 'accent' => '#22D3EE', 'cap' => 44, 'tag' => 'Curiosity, creativity, community'],
        ];

        $roomTemplate = [
            ['name' => 'Sunshine Infants',     'age_group' => 'infant',       'min' => 3,  'max' => 18, 'cap' => 10, 're' => 1, 'rc' => 3, 'color' => '#F59E0B'],
            ['name' => 'Rainbow Toddlers',     'age_group' => 'toddler',      'min' => 18, 'max' => 30, 'cap' => 15, 're' => 1, 'rc' => 5, 'color' => '#16A34A'],
            ['name' => 'Discovery Preschool',  'age_group' => 'preschool',    'min' => 30, 'max' => 48, 'cap' => 16, 're' => 1, 'rc' => 8, 'color' => '#7C3AED'],
            ['name' => 'Explorers Kindergarten', 'age_group' => 'kindergarten', 'min' => 48, 'max' => 72, 'cap' => 20, 're' => 1, 'rc' => 12, 'color' => '#1F6080'],
        ];

        $firstNames = ['Emma', 'Noah', 'Olivia', 'Jack', 'Ava', 'Leo', 'Mia', 'Ethan', 'Sophia', 'Lucas', 'Isla', 'Henry', 'Zara', 'Owen', 'Maya', 'Elijah', 'Nora', 'Aiden', 'Ruby', 'Caleb'];
        $lastNames  = ['Anderson', 'Bennett', 'Carter', 'Diaz', 'Foster', 'Grant', 'Hughes', 'Ibrahim', 'Jensen', 'Khan', 'Lopez', 'Murphy', 'Nguyen', 'Okafor', 'Patel', 'Quinn', 'Reyes', 'Singh', 'Tremblay', 'Walsh'];
        $eduFirst   = ['Hannah', 'Daniel', 'Grace', 'Samuel'];
        $dirFirst   = 'Rebecca';

        $now = Carbon::now();
        $createdCentres = [];

        foreach ($centrePlan as $ci => $p) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $p['name']));

            $existing = null;
            if (! empty($p['rename_existing'])) {
                // Grab the agency's oldest centre and re-brand it.
                $existing = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->orderBy('id')->first();
            } else {
                $existing = DB::table('centres')->where('agency_id', $agencyId)->where('name', $p['name'])->whereNull('deleted_at')->first();
            }

            $brandData = [
                'name'         => $p['name'],
                'city'         => $p['city'],
                'province'     => 'ON',
                'brand_color'  => $p['brand'],
                'accent_color' => $p['accent'],
                'tagline'      => $p['tag'],
                'logo_url'     => $this->logo($p['name']),
                'updated_at'   => $now,
            ];

            if ($existing) {
                DB::table('centres')->where('id', $existing->id)->update($brandData);
                $centreId = $existing->id;
                $this->line("  ~ re-branded centre #{$centreId} → {$p['name']}");
            } else {
                $centreId = DB::table('centres')->insertGetId(array_merge($brandData, [
                    'agency_id'        => $agencyId,
                    'slug'             => substr($slug, 0, 80),
                    'license_capacity' => $p['cap'],
                    'license_number'   => 'DEMO-' . strtoupper(substr($slug, 0, 6)),
                    'status'           => 'active',
                    'country'          => 'CA',
                    'created_at'       => $now,
                ]));
                $createdCentres[] = $centreId;
                $this->line("  + created centre #{$centreId} → {$p['name']}");
            }

            // Rooms — create any that are missing for this centre.
            $roomIds = [];
            foreach ($roomTemplate as $rt) {
                $room = DB::table('rooms')->where('centre_id', $centreId)->where('name', $rt['name'])->first();
                if ($room) { $roomIds[] = $room->id; continue; }
                $roomIds[] = DB::table('rooms')->insertGetId([
                    'centre_id' => $centreId, 'name' => $rt['name'], 'age_group' => $rt['age_group'],
                    'age_min_months' => $rt['min'], 'age_max_months' => $rt['max'], 'capacity' => $rt['cap'],
                    'ratio_educators' => $rt['re'], 'ratio_children' => $rt['rc'], 'color_hex' => $rt['color'],
                    'active' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            // Only populate families/children/staff for NEWLY created centres —
            // the re-branded original already has its cast.
            if (! in_array($centreId, $createdCentres, true)) {
                continue;
            }

            // Staff: 1 director + 3 educators.
            $mkUser = function (string $first, string $last, string $role) use ($slug, $agencyId, $centreId, $now) {
                $email = strtolower("{$first}.{$last}@demo.{$slug}.com");
                if (DB::table('users')->where('email', $email)->exists()) return;
                $uid = DB::table('users')->insertGetId([
                    'email' => $email, 'first_name' => $first, 'last_name' => $last,
                    'password' => Hash::make('Demo1234!'), 'status' => 'active',
                    'photo_url' => 'https://i.pravatar.cc/240?u=' . rawurlencode($email),
                    'timezone' => 'America/Toronto', 'onboarded_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('role_assignments')->insert([
                    'user_id' => $uid, 'role' => $role, 'agency_id' => $agencyId,
                    'centre_id' => $centreId, 'active' => 1, 'created_at' => $now,
                ]);
            };
            $mkUser($dirFirst, explode(' ', 'Sinclair Bright Cole')[$ci] ?? 'Sinclair', 'centre_director');
            foreach ($eduFirst as $k => $ef) {
                if ($k >= 3) break;
                $mkUser($ef, $lastNames[($ci * 7 + $k) % count($lastNames)], 'educator');
            }

            // Families + children (2 kids per family on average).
            $famCount = 7;
            for ($f = 0; $f < $famCount; $f++) {
                $ln = $lastNames[($ci * 5 + $f) % count($lastNames)];
                $famId = DB::table('families')->insertGetId([
                    'centre_id' => $centreId, 'family_name' => "{$ln} Family", 'external_source' => 'demo',
                    'primary_phone' => '519-555-' . str_pad((string) (1000 + $ci * 100 + $f), 4, '0', STR_PAD_LEFT),
                    'primary_email' => strtolower("{$ln}.family@demo.{$slug}.com"),
                    'address_line1' => (100 + $f) . ' ' . ['Maple', 'Cedar', 'Birch', 'Willow', 'Elm', 'Oak', 'Pine'][$f % 7] . ' St',
                    'city' => $p['city'], 'province' => 'ON', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $kids = ($f % 3 === 0) ? 2 : 1; // some siblings
                for ($k = 0; $k < $kids; $k++) {
                    $idx = $ci * 13 + $f * 2 + $k;
                    $fn = $firstNames[$idx % count($firstNames)];
                    $ageMonths = 6 + (($idx * 7) % 60); // 6mo – 5.5yr
                    $roomIdx = $ageMonths < 18 ? 0 : ($ageMonths < 30 ? 1 : ($ageMonths < 48 ? 2 : 3));
                    // ~85% enrolled, the rest waitlisted.
                    $enrolled = (($idx % 7) !== 0);
                    DB::table('children')->insert([
                        'family_id' => $famId, 'first_name' => $fn, 'last_name' => $ln,
                        'date_of_birth' => $now->copy()->subMonths($ageMonths)->toDateString(),
                        'gender' => ($idx % 2 === 0) ? 'female' : 'male',
                        'enrollment_status' => $enrolled ? 'enrolled' : 'waitlist',
                        'enrolled_at' => $enrolled ? $now->copy()->subMonths(3)->toDateString() : null,
                        'applied_at' => $now->copy()->subMonths(4)->toDateString(),
                        'primary_room_id' => $enrolled ? $roomIds[$roomIdx] : null,
                        'photo_url' => $this->kidAvatar($fn . $ln . $idx),
                        'preferred_lang' => 'en-CA', 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
            $this->line("      populated rooms/families/children/staff for #{$centreId}");
        }

        $this->info('Done. Centres in agency ' . $agencyId . ': '
            . DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->count());
        return self::SUCCESS;
    }
}
