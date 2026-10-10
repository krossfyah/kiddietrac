<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * THE OHIO DEMO AGENCY (2026-10-10, Anthony: "Set up an Ohio demo agency").
 *
 * A separate agency, set to US-OH, filled with FAKE people so every Ohio feature (ratio
 * engine v2, drills, staff compliance, training hours, household residents, the ACIP
 * immunization schedule) can be seen and shown without touching iLearn (2) or Test Agency (6).
 *
 * SILENT BY CONSTRUCTION. The agency's own master switch is off (settings.notifications_enabled
 * = false, which Suppression::isAgency / isUser honour for everyone attached to it), SMS and
 * voice are off, summaries are off, every centre has email off, and every address is on the
 * non-routable demo domain below. Everything is inserted directly, so no listener, mailer or
 * push path runs while seeding.
 *
 *   php artisan kt:seed-ohio-demo            create it (does nothing if it already exists)
 *   php artisan kt:seed-ohio-demo --rebuild  delete THIS agency's demo data and create it again
 *
 * Demo logins (random passwords) are written to storage/app/private/ohio-demo-logins.json
 * (mode 600); they are never printed.
 */
final class SeedOhioDemo extends Command
{
    protected $signature = 'kt:seed-ohio-demo {--rebuild : Delete this demo agency first and rebuild it}';

    protected $description = 'Create (or rebuild) the fake Ohio demo agency — silent, US-OH, demo people only';

    private const SLUG = 'ohio-demo';
    private const DOMAIN = 'ohio-demo.kiddietrac.invalid';
    private const TZ = 'America/New_York';

    private int $agencyId = 0;
    private Carbon $now;
    private Carbon $today;   // local midnight, Columbus
    private array $logins = [];
    private int $phone = 100;

    public function handle(): int
    {
        $this->now = now();
        $this->today = Carbon::now(self::TZ)->startOfDay();
        $existing = DB::table('agencies')->where('slug', self::SLUG)->first();
        if ($existing && ! $this->option('rebuild')) {
            $this->info('Ohio demo agency already exists: #' . $existing->id . ' (use --rebuild to recreate it).');
            return self::SUCCESS;
        }
        if ($existing) {
            $this->purge((int) $existing->id);
            $this->info('Removed the previous Ohio demo agency #' . $existing->id . '.');
        }

        DB::transaction(function () {
            $this->agency();
            $this->schedule();
            [$center, $typeB, $typeA] = $this->centres();
            $rooms = $this->rooms($center, $typeB, $typeA);
            $staff = $this->staff($center, $typeB, $typeA, $rooms);
            $this->families($center, $typeB, $typeA, $rooms, $staff);
            $this->compliance($staff);
            $this->household($typeB, $typeA, $staff);
            $this->drills($center, $typeB, $typeA, $rooms, $staff);
            $this->attendance($center, $typeB, $typeA, $rooms, $staff);
        });

        $path = storage_path('app/private/ohio-demo-logins.json');
        if (! is_dir(dirname($path))) @mkdir(dirname($path), 0700, true);
        file_put_contents($path, json_encode(['agency_id' => $this->agencyId, 'created_at' => (string) $this->now, 'note' => 'Fake Ohio demo accounts. Do not reuse these passwords anywhere.', 'logins' => $this->logins], JSON_PRETTY_PRINT));
        @chmod($path, 0600);

        $this->info('Ohio demo agency #' . $this->agencyId . ' ready. Logins: ' . $path);
        return self::SUCCESS;
    }

    /* ───────────── helpers ───────────── */

    private function phone(): string
    {
        return '614-555-0' . str_pad((string) ($this->phone++ % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function user(string $first, string $last, string $local, array $extra = [], ?string $loginRole = null): int
    {
        $email = strtolower($local) . '@' . self::DOMAIN;
        $password = rtrim(strtr(base64_encode(random_bytes(15)), '+/', 'Ab'), '=');
        $id = DB::table('users')->insertGetId(array_merge([
            'email' => $email, 'first_name' => $first, 'last_name' => $last, 'phone' => $this->phone(),
            'password' => Hash::make($password), 'password_changed_at' => $this->now, 'must_change_password' => 0,
            'email_verified_at' => $this->now, 'status' => 'active', 'onboarded_at' => $this->now, 'locale' => 'en-US',
            'sms_opt_in' => 0, 'voice_opt_out' => 1, 'created_at' => $this->now, 'updated_at' => $this->now,
        ], $extra));
        if ($loginRole) $this->logins[] = ['role' => $loginRole, 'user_id' => $id, 'email' => $email, 'password' => $password];
        return $id;
    }

    private function role(int $userId, string $role, ?int $centreId): void
    {
        DB::table('role_assignments')->insert(['user_id' => $userId, 'role' => $role, 'agency_id' => $this->agencyId, 'centre_id' => $centreId, 'active' => 1, 'created_at' => $this->now]);
    }

    /** UTC timestamp for a local Columbus wall-clock time today. */
    private function at(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return $this->today->copy()->setTime($h, $m)->utc()->toDateTimeString();
    }

    private function dob(int $months, int $days = 0): string
    {
        return Carbon::now(self::TZ)->subMonths($months)->subDays($days)->toDateString();
    }

    /* ───────────── the build ───────────── */

    private function agency(): void
    {
        $this->agencyId = DB::table('agencies')->insertGetId([
            'name' => 'Buckeye Kids Demo (Ohio)', 'slug' => self::SLUG, 'legal_name' => 'Buckeye Kids Demo LLC (demo — not a real business)',
            'contact_email' => 'office@' . self::DOMAIN, 'contact_phone' => $this->phone(),
            'timezone' => self::TZ, 'locale' => 'en-US', 'default_locale' => 'en', 'currency' => 'USD', 'plan_currency' => 'USD',
            'jurisdiction' => 'US-OH', 'country' => 'US', 'province' => 'OH', 'city' => 'Columbus', 'postal_code' => '43215',
            'address_line1' => '900 Demo Way (fictional)', 'billing_status' => 'trial', 'plan_code' => 'growth',
            'sms_enabled' => 0, 'voice_enabled' => 0,
            'settings' => json_encode([
                'demo' => true, 'country' => 'US', 'centre_term' => 'center',
                // The agency's own master switch: nothing is sent for anyone attached to it.
                'notifications_enabled' => false, 'summaries_enabled' => false,
                'brand_address' => "900 Demo Way (fictional)\nColumbus, OH 43215\nUnited States",
            ]),
            'created_at' => $this->now, 'updated_at' => $this->now,
        ]);
    }

    /** The agency's immunization schedule: the ACIP preset the Ohio profile points at. */
    private function schedule(): void
    {
        $m = new \ReflectionMethod(\App\Http\Controllers\Api\ImmunizationScheduleController::class, 'presetRows');
        $m->setAccessible(true);
        $order = 0;
        foreach ($m->invoke(null, 'acip_ohio') as [$vaccine, $dose, $months]) {
            DB::table('immunization_schedule')->insert(['agency_id' => $this->agencyId, 'vaccine' => $vaccine, 'dose_label' => $dose, 'due_at_age_months' => $months,
                'is_required' => 1, 'display_order' => $order++, 'active' => 1, 'created_at' => $this->now, 'updated_at' => $this->now]);
        }
    }

    private function centres(): array
    {
        $base = ['agency_id' => $this->agencyId, 'city' => 'Columbus', 'province' => 'OH', 'country' => 'US', 'status' => 'active',
            'open_time' => '07:00:00', 'close_time' => '18:00:00', 'settings' => json_encode(['email_enabled' => false]), 'created_at' => $this->now, 'updated_at' => $this->now];
        $center = DB::table('centres')->insertGetId($base + ['name' => 'Buckeye Kids Learning Center (Demo)', 'slug' => 'ohio-demo-center', 'license_type' => 'oh_center',
            'license_number' => 'DEMO-OH-CTR-0001', 'license_capacity' => 134, 'address_line1' => '1200 Demo Ave (fictional)', 'postal_code' => '43215',
            'phone' => $this->phone(), 'email' => 'center@' . self::DOMAIN]);
        $typeB = DB::table('centres')->insertGetId($base + ['name' => 'Maple Street Family Child Care (Demo, Type B)', 'slug' => 'ohio-demo-type-b', 'license_type' => 'oh_type_b',
            'license_number' => 'DEMO-OH-FCC-B-0002', 'license_capacity' => 7, 'address_line1' => '4400 Demo St (fictional)', 'postal_code' => '43214',
            'phone' => $this->phone(), 'email' => 'dana.provider@' . self::DOMAIN, 'supervisor_first_name' => 'Dana', 'supervisor_last_name' => 'Whitfield',
            'provider_bio' => 'Demo provider. I have run a licensed Type B home for six years: outdoor play every day, a big garden, and lots of story time.']);
        $typeA = DB::table('centres')->insertGetId($base + ['name' => 'Scioto Kids Family Child Care (Demo, Type A)', 'slug' => 'ohio-demo-type-a', 'license_type' => 'oh_type_a',
            'license_number' => 'DEMO-OH-FCC-A-0003', 'license_capacity' => 12, 'address_line1' => '7700 Demo Rd (fictional)', 'postal_code' => '43204',
            'phone' => $this->phone(), 'email' => 'luis.provider@' . self::DOMAIN, 'supervisor_first_name' => 'Luis', 'supervisor_last_name' => 'Romero']);
        return [$center, $typeB, $typeA];
    }

    private function rooms(int $center, int $typeB, int $typeA): array
    {
        $mk = function (int $centre, string $name, string $group, int $min, int $max, int $cap, int $ratio, string $color) {
            return DB::table('rooms')->insertGetId(['centre_id' => $centre, 'name' => $name, 'age_group' => $group, 'age_min_months' => $min, 'age_max_months' => $max,
                'capacity' => $cap, 'ratio_educators' => 1, 'ratio_children' => $ratio, 'color_hex' => $color, 'active' => 1, 'email_enabled' => 0,
                'created_at' => $this->now, 'updated_at' => $this->now]);
        };
        return [
            'infant' => $mk($center, 'Infants (under 12 months)', 'infant', 0, 12, 10, 5, '#F59E0B'),
            'young_toddler' => $mk($center, 'Young Toddlers (12–18 months)', 'toddler', 12, 18, 12, 6, '#EC4899'),
            'older_toddler' => $mk($center, 'Older Toddlers (18 months–3 years)', 'toddler', 18, 36, 14, 7, '#8B5CF6'),
            'pre3' => $mk($center, 'Preschool 3s', 'preschool', 36, 48, 24, 12, '#0EA5E9'),
            'pre4' => $mk($center, 'Pre-K 4s & 5s', 'preschool', 48, 72, 28, 14, '#10B981'),
            'school' => $mk($center, 'School-Age Club', 'school_age', 72, 144, 36, 18, '#6366F1'),
            'home_b' => $mk($typeB, 'Maple Street Home', 'preschool', 0, 72, 7, 7, '#B45309'),
            'home_a' => $mk($typeA, 'Scioto Kids Home', 'preschool', 0, 72, 12, 7, '#0F766E'),
        ];
    }

    private function staff(int $center, int $typeB, int $typeA, array $rooms): array
    {
        $hire = fn (int $daysAgo) => Carbon::now(self::TZ)->subDays($daysAgo)->toDateString();
        $s = [];
        $s['admin'] = $this->user('Avery', 'Collins', 'avery.admin', ['hire_date' => $hire(900)], 'agency_admin');
        $this->role($s['admin'], 'agency_admin', null);
        $s['director'] = $this->user('Morgan', 'Ellis', 'morgan.director', ['hire_date' => $hire(700), 'registry_number' => 'DEMO-OPIN-100001'], 'centre_director');
        $this->role($s['director'], 'centre_director', $center);

        $eds = [
            ['Jordan', 'Price', 'jordan.educator', 'infant', 400, 'DEMO-OPIN-100011', 'educator'],
            ['Taylor', 'Nguyen', 'taylor.educator', 'young_toddler', 45, 'DEMO-OPIN-100012', null],   // new hire: deadlines due
            ['Riley', 'Bennett', 'riley.educator', 'older_toddler', 120, null, null],                // past 90 days: overdue items
            ['Casey', 'Morales', 'casey.educator', 'pre3', 800, 'DEMO-OPIN-100014', null],
            ['Quinn', 'Foster', 'quinn.educator', 'pre4', 20, null, null],                            // very new: orientation due
            ['Skyler', 'Hayes', 'skyler.educator', 'school', 1500, 'DEMO-OPIN-100016', null],
        ];
        foreach ($eds as [$f, $l, $local, $room, $days, $opin, $login]) {
            $id = $this->user($f, $l, $local, ['hire_date' => $hire($days), 'registry_number' => $opin], $login);
            $this->role($id, 'educator', $center);
            DB::table('educator_rooms')->insert(['user_id' => $id, 'room_id' => $rooms[$room], 'assigned_by_id' => $s['director'], 'created_at' => $this->now]);
            $s['educators'][$room] = $id;
        }

        // Home providers: the provider's email IS the home's email (that is how KiddieTrac knows they are the provider).
        $s['provider_b'] = $this->user('Dana', 'Whitfield', 'dana.provider', ['hire_date' => $hire(2200), 'registry_number' => 'DEMO-OPIN-200001'], 'home_provider');
        $this->role($s['provider_b'], 'educator', $typeB);
        DB::table('educator_rooms')->insert(['user_id' => $s['provider_b'], 'room_id' => $rooms['home_b'], 'assigned_by_id' => $s['admin'], 'created_at' => $this->now]);
        $s['provider_a'] = $this->user('Luis', 'Romero', 'luis.provider', ['hire_date' => $hire(1300), 'registry_number' => 'DEMO-OPIN-200002']);
        $this->role($s['provider_a'], 'educator', $typeA);
        DB::table('educator_rooms')->insert(['user_id' => $s['provider_a'], 'room_id' => $rooms['home_a'], 'assigned_by_id' => $s['admin'], 'created_at' => $this->now]);
        $s['assistant_a'] = $this->user('Priya', 'Shah', 'priya.assistant', ['hire_date' => $hire(70)]);
        $this->role($s['assistant_a'], 'educator', $typeA);
        DB::table('educator_rooms')->insert(['user_id' => $s['assistant_a'], 'room_id' => $rooms['home_a'], 'assigned_by_id' => $s['admin'], 'created_at' => $this->now]);
        return $s;
    }

    private function families(int $center, int $typeB, int $typeA, array $rooms, array &$staff): void
    {
        $famId = null; $surname = null;
        $surnames = ['Anderson', 'Brooks', 'Carter', 'Diaz', 'Evans', 'Fisher', 'Garcia', 'Harper', 'Iverson', 'Jensen', 'Kim', 'Lopez',
            'Mitchell', 'Novak', 'Ortiz', 'Patel', 'Reyes', 'Sullivan', 'Turner', 'Walsh'];
        $firsts = ['Ava', 'Ben', 'Chloe', 'Dev', 'Ella', 'Finn', 'Grace', 'Hugo', 'Isla', 'Jack', 'Kai', 'Lena', 'Milo', 'Nora', 'Owen', 'Piper',
            'Remy', 'Sage', 'Theo', 'Uma', 'Vera', 'Wes', 'Zoe', 'Arlo', 'Bea', 'Cal', 'Dina', 'Eli', 'Faye', 'Gus', 'Hana', 'Ivy', 'Jude', 'Kira', 'Leo', 'Mae'];
        // [room, age in months, how many] — Young Toddlers is deliberately 13 children (group size is 12).
        $plan = [
            ['infant', 6, 1], ['infant', 9, 1], ['infant', 4, 1], ['infant', 11, 1],
            ['young_toddler', 13, 4], ['young_toddler', 15, 5], ['young_toddler', 17, 4],
            ['older_toddler', 22, 3], ['older_toddler', 30, 2],
            ['pre3', 38, 4], ['pre3', 44, 3],
            ['pre4', 50, 5], ['pre4', 60, 5],
            ['school', 80, 3], ['school', 100, 2],
            ['home_b', 8, 1], ['home_b', 14, 1], ['home_b', 26, 2], ['home_b', 40, 1],   // + the provider's own child below = 6, 3 under 2
            ['home_a', 10, 1], ['home_a', 20, 2], ['home_a', 36, 3], ['home_a', 55, 3],
        ];
        $centreOf = fn (string $room) => $room === 'home_b' ? $typeB : ($room === 'home_a' ? $typeA : $center);
        $fi = 0; $ci = 0; $loginGiven = false;
        $children = [];
        foreach ($plan as [$room, $months, $n]) {
            for ($i = 0; $i < $n; $i++) {
                // Twins share a family (keeps the family count realistic while filling the rooms).
                if ($i % 2 === 1 && isset($famId, $surname)) {
                    $childId = $this->child($famId, $firsts[$ci % count($firsts)], $surname, $months, $rooms[$room], $ci);
                    $children[] = [$childId, $months, $ci];
                    $ci++;
                    continue;
                }
                $surname = $surnames[$fi % count($surnames)] . ($fi >= count($surnames) ? ' ' . intdiv($fi, count($surnames)) : '');
                $centreId = $centreOf($room);
                $famId = DB::table('families')->insertGetId(['centre_id' => $centreId, 'family_name' => $surname, 'primary_phone' => $this->phone(),
                    'primary_email' => 'family.' . strtolower(str_replace(' ', '', $surname)) . '@' . self::DOMAIN, 'address_line1' => (100 + $fi) . ' Demo Ln (fictional)',
                    'city' => 'Columbus', 'province' => 'OH', 'postal_code' => '432' . str_pad((string) (10 + $fi % 20), 2, '0', STR_PAD_LEFT), 'preferred_lang' => 'en',
                    'created_at' => $this->now, 'updated_at' => $this->now]);
                $parentFirst = ['Alex', 'Sam', 'Jamie', 'Robin', 'Drew', 'Cameron', 'Kendall', 'Logan'][$fi % 8];
                $local = 'parent.' . strtolower(str_replace(' ', '', $surname));
                $uid = $this->user($parentFirst, $surname, $local, [], $loginGiven ? null : 'parent');
                $loginGiven = true;
                $this->role($uid, 'guardian', $centreId);
                DB::table('guardians')->insert(['family_id' => $famId, 'user_id' => $uid, 'relationship' => $fi % 2 ? 'father' : 'mother', 'is_primary' => 1,
                    'can_pickup' => 1, 'can_receive_billing' => 1, 'created_at' => $this->now]);
                $childId = $this->child($famId, $firsts[$ci % count($firsts)], $surname, $months, $rooms[$room], $ci);
                $children[] = [$childId, $months, $ci];
                $fi++; $ci++;
            }
        }
        // The Type B provider's own child (under 6, so Ohio counts them toward the home's limits).
        $own = DB::table('families')->insertGetId(['centre_id' => $typeB, 'family_name' => 'Whitfield', 'primary_email' => 'dana.provider@' . self::DOMAIN,
            'primary_phone' => $this->phone(), 'city' => 'Columbus', 'province' => 'OH', 'postal_code' => '43214', 'notes' => "Provider's own child (counts toward Ohio home limits)",
            'created_at' => $this->now, 'updated_at' => $this->now]);
        DB::table('guardians')->insert(['family_id' => $own, 'user_id' => $staff['provider_b'], 'relationship' => 'mother', 'is_primary' => 1, 'can_pickup' => 1, 'can_receive_billing' => 0, 'created_at' => $this->now]);
        $staff['own_child'] = $this->child($own, 'Wren', 'Whitfield', 34, $rooms['home_b'], 99);
        $children[] = [$staff['own_child'], 34, 99];
        $this->immunizations($children, $staff['director']);
    }

    private function child(int $famId, string $first, string $last, int $months, int $roomId, int $i): int
    {
        $id = DB::table('children')->insertGetId(['family_id' => $famId, 'first_name' => $first, 'last_name' => $last, 'date_of_birth' => $this->dob($months, $i % 20),
            'gender' => ['female', 'male'][$i % 2], 'enrollment_status' => 'enrolled', 'enrolled_at' => $this->now, 'primary_room_id' => $roomId,
            'allergies' => $i % 7 === 3 ? json_encode([['allergen' => 'Peanuts', 'severity' => 'severe']]) : null, 'preferred_lang' => 'en', 'created_at' => $this->now, 'updated_at' => $this->now]);
        DB::table('enrollments')->insert(['child_id' => $id, 'room_id' => $roomId, 'start_date' => Carbon::now(self::TZ)->subMonths(3)->toDateString(),
            'schedule' => json_encode(['mon', 'tue', 'wed', 'thu', 'fri']), 'monthly_fee' => 1200, 'cwelcc_eligible' => 0, 'created_at' => $this->now]);
        return $id;
    }

    /** Doses on schedule for most children; a few behind; a medical and a conscience exemption. */
    private function immunizations(array $children, int $recordedBy): void
    {
        $sched = DB::table('immunization_schedule')->where('agency_id', $this->agencyId)->where('active', 1)->get();
        foreach ($children as $n => [$childId, $months, $i]) {
            $behind = $n % 6 === 2;   // every sixth child is missing their latest doses
            foreach ($sched as $row) {
                if ($row->due_at_age_months > $months) continue;
                if ($behind && $row->due_at_age_months >= $months - 6) continue;
                $exempt = ($n === 5 && $row->vaccine === 'Influenza') || ($n === 11 && $row->vaccine === 'Varicella');
                DB::table('immunizations')->insert(['child_id' => $childId, 'vaccine' => $row->vaccine, 'dose_label' => $row->dose_label,
                    'administered_on' => $exempt ? null : Carbon::now(self::TZ)->subMonths(max(0, $months - $row->due_at_age_months))->toDateString(),
                    'clinic_name' => $exempt ? null : 'Demo Pediatrics (fictional)', 'exempt' => $exempt ? 1 : 0,
                    'exemption_reason' => $exempt ? ($n === 5 ? 'Medical — signed by a physician (demo)' : 'Reasons of conscience, including religious (demo)') : null,
                    'recorded_by_id' => $recordedBy, 'created_at' => $this->now, 'updated_at' => $this->now]);
            }
        }
    }

    /** Certifications, Ohio background checks and training hours in deliberately mixed states. */
    private function compliance(array $s): void
    {
        $d = fn (int $days) => Carbon::now(self::TZ)->addDays($days)->toDateString();
        $cert = function (int $uid, string $type, ?int $issuedDaysAgo, ?int $expiresInDays = null) use ($d) {
            DB::table('staff_certifications')->insert(['user_id' => $uid, 'cert_type' => $type, 'certifier' => 'Demo Training Co. (fictional)',
                'issued_at' => $issuedDaysAgo === null ? null : $d(-$issuedDaysAgo), 'expires_at' => $expiresInDays === null ? null : $d($expiresInDays), 'active' => 1, 'created_at' => $this->now]);
        };
        $check = function (int $uid, string $type, int $issuedDaysAgo) use ($d) {
            DB::table('background_checks')->insert(['agency_id' => $this->agencyId, 'user_id' => $uid, 'check_type' => $type, 'reference' => 'DEMO-' . strtoupper($type) . '-' . $uid,
                'issued_at' => $d(-$issuedDaysAgo), 'expires_at' => $d(-$issuedDaysAgo + 5 * 365), 'notes' => 'Demo record — DCY 01176 on file (fictional)',
                'created_at' => $this->now, 'updated_at' => $this->now]);
        };
        $all5 = ['bci', 'fbi', 'sex_offender_state', 'sex_offender_national', 'sacwis'];
        $e = $s['educators'];
        // Fully compliant veterans.
        foreach ([$s['director'], $e['infant'], $e['pre3'], $s['provider_b']] as $uid) {
            foreach ($all5 as $t) $check($uid, $t, 300);
            foreach (['First_Aid', 'CPR', 'Child_Abuse_Training', 'Orientation', 'Health_Card', 'Communicable_Disease'] as $t) $cert($uid, $t, 200, $t === 'First_Aid' || $t === 'CPR' ? 500 : null);
        }
        // Skyler: checks done 4 years 11 months ago → renewal due soon; CPR expired.
        foreach ($all5 as $t) $check($e['school'], $t, 5 * 365 - 25);
        $cert($e['school'], 'First_Aid', 600, 130); $cert($e['school'], 'CPR', 800, -20); $cert($e['school'], 'Child_Abuse_Training', 1400); $cert($e['school'], 'Orientation', 1490);
        // Taylor (45 days in): checks done, orientation done, first aid / CPR / abuse training still due.
        foreach ($all5 as $t) $check($e['young_toddler'], $t, 50);
        $cert($e['young_toddler'], 'Orientation', 40);
        // Riley (120 days in): BCI + FBI only → registries missing; first aid/CPR/abuse training overdue.
        $check($e['older_toddler'], 'bci', 125); $check($e['older_toddler'], 'fbi', 125);
        $cert($e['older_toddler'], 'Orientation', 110);
        // Quinn (20 days in): checks pending, nothing else yet → orientation due.
        $check($e['pre4'], 'bci', 22);
        // Luis + Priya at the Type A home.
        foreach ($all5 as $t) $check($s['provider_a'], $t, 900);
        foreach (['First_Aid', 'CPR', 'Child_Abuse_Training', 'Orientation'] as $t) $cert($s['provider_a'], $t, 300, $t === 'CPR' ? 10 : null);
        foreach ($all5 as $t) $check($s['assistant_a'], $t, 75);
        $cert($s['assistant_a'], 'Orientation', 65);

        // Training hours this Ohio year (July–June).
        $pd = function (int $uid, int $daysAgo, string $title, float $hours) {
            DB::table('pd_hours')->insert(['agency_id' => $this->agencyId, 'user_id' => $uid, 'taken_on' => Carbon::now(self::TZ)->subDays($daysAgo)->toDateString(),
                'title' => $title, 'provider' => 'Demo PD Online (fictional)', 'hours' => $hours, 'status' => 'approved', 'created_by' => $uid,
                'created_at' => $this->now, 'updated_at' => $this->now]);
        };
        $pd($e['infant'], 60, 'Infant brain development (demo)', 3); $pd($e['infant'], 20, 'Safe sleep refresher (demo)', 3.5);
        $pd($e['pre3'], 40, 'Early literacy through play (demo)', 2);
        $pd($e['school'], 10, 'Behaviour guidance (demo)', 1.5);
        $pd($s['provider_b'], 30, 'Family child care business basics (demo)', 6);
        $pd($s['director'], 15, 'Ohio rules update 2026 (demo)', 4);
    }

    private function household(int $typeB, int $typeA, array $s): void
    {
        $d = fn (int $days) => Carbon::now(self::TZ)->addDays($days)->toDateString();
        $add = function (int $centre, string $name, string $rel, int $ageYears, array $checks) use ($d, $s) {
            $id = DB::table('household_residents')->insertGetId(['agency_id' => $this->agencyId, 'centre_id' => $centre, 'full_name' => $name, 'relationship' => $rel,
                'date_of_birth' => Carbon::now(self::TZ)->subYears($ageYears)->toDateString(), 'moved_in_on' => $d(-2000), 'notes' => 'Demo person (fictional)',
                'created_by' => $s['admin'], 'created_at' => $this->now, 'updated_at' => $this->now]);
            foreach ($checks as [$type, $issuedDaysAgo]) {
                DB::table('household_resident_checks')->insert(['resident_id' => $id, 'check_type' => $type, 'reference' => 'DEMO-HH-' . strtoupper($type) . '-' . $id,
                    'issued_at' => $d(-$issuedDaysAgo), 'expires_at' => $d(-$issuedDaysAgo + 5 * 365), 'created_by' => $s['admin'], 'created_at' => $this->now, 'updated_at' => $this->now]);
            }
        };
        $add($typeB, 'Chris Whitfield', 'Spouse', 41, [['bci', 400], ['fbi', 400], ['sex_offender_state', 400], ['sex_offender_national', 400], ['sacwis', 400]]);
        $add($typeB, 'Jesse Whitfield', 'Adult child (19)', 19, [['bci', 30]]);   // turned 18 → checks incomplete
        $add($typeA, 'Elena Romero', 'Spouse', 38, [['bci', 1820], ['fbi', 1820], ['sex_offender_state', 1820], ['sex_offender_national', 1820], ['sacwis', 1820]]);   // renewal due
    }

    private function drills(int $center, int $typeB, int $typeA, array $rooms, array $s): void
    {
        $tz = self::TZ;
        $mk = function (int $centre, ?int $room, string $type, Carbon $when, int $secs, int $kids, string $staffPresent, int $by) {
            DB::table('drills')->insert(['agency_id' => $this->agencyId, 'centre_id' => $centre, 'room_id' => $room, 'type' => $type, 'drilled_at' => $when->copy()->utc()->toDateTimeString(),
                'evacuation_seconds' => $secs, 'children_count' => $kids, 'staff_present' => $staffPresent, 'notes' => 'Demo drill', 'recorded_by' => $by,
                'created_at' => $this->now, 'updated_at' => $this->now]);
        };
        $m = function (int $monthsAgo, int $day, int $h) use ($tz) {
            $t = Carbon::now($tz)->startOfMonth()->subMonths($monthsAgo)->addDays($day - 1)->setTime($h, 30);
            return $t->greaterThan(Carbon::now($tz)) ? Carbon::now($tz)->subHour() : $t;   // never a drill in the future
        };
        // Center: fire every month incl. this one; weather done through August, September MISSED; lockdown done last quarter.
        foreach ([5, 4, 3, 2, 1] as $ago) $mk($center, null, 'fire', $m($ago, 8 + $ago, 10), 140 + $ago * 5, 48, 'Morgan Ellis + 6 educators', $s['director']);
        $mk($center, null, 'fire', $m(0, 3, 15), 132, 51, 'Morgan Ellis + 6 educators', $s['director']);
        foreach ([6, 5, 4, 3, 2] as $ago) $mk($center, null, 'weather', $m($ago, 14, 11), 95, 46, 'All staff', $s['director']);
        $mk($center, null, 'lockdown', $m(2, 20, 13), 60, 47, 'All staff', $s['director']);
        // Type B home: fire done last month; this month still DUE.
        $mk($typeB, $rooms['home_b'], 'fire', $m(1, 12, 9), 70, 6, 'Dana Whitfield', $s['provider_b']);
        $mk($typeB, $rooms['home_b'], 'fire', $m(2, 10, 9), 75, 6, 'Dana Whitfield', $s['provider_b']);
        // Type A home: fire MISSED last month (logged two months ago only).
        $mk($typeA, $rooms['home_a'], 'fire', $m(2, 7, 10), 85, 9, 'Luis Romero, Priya Shah', $s['provider_a']);
    }

    /** Today: staff clocked in and children checked in, so the ratio cards show real Ohio maths. */
    private function attendance(int $center, int $typeB, int $typeA, array $rooms, array $s): void
    {
        $punch = function (int $uid, int $centre, string $in) {
            DB::table('time_punches')->insert(['user_id' => $uid, 'centre_id' => $centre, 'punched_in_at' => $this->at($in), 'punched_out_at' => null,
                'notes' => 'Demo', 'source' => 'web', 'created_at' => $this->now]);
        };
        foreach ($s['educators'] as $room => $uid) $punch($uid, $center, '07:30');
        $punch($s['director'], $center, '07:15');
        $punch($s['provider_b'], $typeB, '07:00');
        $punch($s['provider_a'], $typeA, '07:00');
        $punch($s['assistant_a'], $typeA, '08:00');
        // Every enrolled child checked in this morning (Young Toddlers = 13, over Ohio's group size of 12).
        $kids = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->leftJoin('guardians as g', function ($j) { $j->on('g.family_id', '=', 'f.id')->where('g.is_primary', 1); })
            ->where('ce.agency_id', $this->agencyId)->get(['c.id', 'c.primary_room_id', 'g.user_id as parent_id']);
        foreach ($kids as $i => $k) {
            DB::table('check_events')->insert(['child_id' => $k->id, 'room_id' => $k->primary_room_id, 'event_type' => 'check_in',
                'occurred_at' => $this->at(sprintf('08:%02d', $i % 50)), 'by_user_id' => (int) ($k->parent_id ?: $s['director']), 'recorded_by_id' => $s['director'], 'notes' => 'Demo', 'is_automatic' => 0,
                'backdated' => 0, 'created_at' => $this->now]);
        }
    }

    /* ───────────── rebuild ───────────── */

    /** Delete THIS demo agency's rows only. Refuses anything that is not the demo agency. */
    private function purge(int $agencyId): void
    {
        $a = DB::table('agencies')->where('id', $agencyId)->first();
        if (! $a || $a->slug !== self::SLUG || (int) $agencyId === 2 || (int) $agencyId === 6) {
            throw new \RuntimeException('Refusing to purge agency #' . $agencyId);
        }
        DB::transaction(function () use ($agencyId) {
            $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
            $rooms = DB::table('rooms')->whereIn('centre_id', $centres ?: [0])->pluck('id')->all();
            $families = DB::table('families')->whereIn('centre_id', $centres ?: [0])->pluck('id')->all();
            $children = DB::table('children')->whereIn('family_id', $families ?: [0])->pluck('id')->all();
            $users = DB::table('users')->where('email', 'like', '%@' . self::DOMAIN)->pluck('id')->all();
            $residents = DB::table('household_residents')->where('agency_id', $agencyId)->pluck('id')->all();
            DB::table('check_events')->whereIn('child_id', $children ?: [0])->delete();
            DB::table('immunizations')->whereIn('child_id', $children ?: [0])->delete();
            DB::table('enrollments')->whereIn('child_id', $children ?: [0])->delete();
            DB::table('children')->whereIn('id', $children ?: [0])->delete();
            DB::table('guardians')->whereIn('family_id', $families ?: [0])->delete();
            DB::table('families')->whereIn('id', $families ?: [0])->delete();
            DB::table('educator_rooms')->whereIn('room_id', $rooms ?: [0])->delete();
            DB::table('drills')->where('agency_id', $agencyId)->delete();
            DB::table('rooms')->whereIn('id', $rooms ?: [0])->delete();
            DB::table('household_resident_checks')->whereIn('resident_id', $residents ?: [0])->delete();
            DB::table('household_residents')->where('agency_id', $agencyId)->delete();
            DB::table('pd_hours')->where('agency_id', $agencyId)->delete();
            DB::table('background_checks')->where('agency_id', $agencyId)->delete();
            DB::table('staff_certifications')->whereIn('user_id', $users ?: [0])->delete();
            DB::table('time_punches')->whereIn('user_id', $users ?: [0])->delete();
            DB::table('role_assignments')->where('agency_id', $agencyId)->delete();
            DB::table('role_assignments')->whereIn('user_id', $users ?: [0])->delete();
            DB::table('user_profiles')->whereIn('user_id', $users ?: [0])->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', 'like', '%User')->whereIn('tokenable_id', $users ?: [0])->delete();
            DB::table('users')->whereIn('id', $users ?: [0])->delete();
            DB::table('immunization_schedule')->where('agency_id', $agencyId)->delete();
            DB::table('centres')->whereIn('id', $centres ?: [0])->delete();
            DB::table('agencies')->where('id', $agencyId)->delete();
        });
    }
}
