<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

#[AsCommand(
    name: 'kiddietrac:seed-demo',
    description: 'Create a realistic demo centre with families, children, and a day of activity',
)]
final class SeedDemo extends Command
{
    protected $signature = 'kiddietrac:seed-demo
                            {--reset : Wipe demo data first}
                            {--password=Demo2026! : Password to set on all demo users}';

    public function handle(): int
    {
        if ($this->option('reset')) {
            $this->warn('Resetting all demo data...');
            $this->reset();
        }

        $password = (string) $this->option('password');

        DB::transaction(function () use ($password): void {
            $this->info('Creating agency and centre...');
            $agency = $this->ensureAgency();
            $centre = $this->ensureCentre($agency->id);
            $rooms = $this->ensureRooms($centre->id);

            $this->info('Creating staff...');
            $this->createDirector($centre->id, $agency->id, $password);
            $educators = $this->createEducators($centre->id, $agency->id, $password);

            $this->info('Creating families and children...');
            $families = $this->createFamilies($centre->id, $agency->id, $rooms, $password);

            $this->info("Logging today's activity...");
            $this->createTodaysActivity($families, $educators);
        });

        $this->newLine();
        $this->line('────────────────────────────────────────────');
        $this->line('Demo accounts created. Password: '.$password);
        $this->newLine();
        $this->line('  Director:  director@kiddietrac.com');
        $this->line('  Educator:  educator@kiddietrac.com');
        $this->line('  Parent:    parent@kiddietrac.com');
        $this->line('────────────────────────────────────────────');

        return self::SUCCESS;
    }

    private function reset(): void
    {
        $emails = [
            'director@kiddietrac.com',
            'educator@kiddietrac.com',
            'educator2@kiddietrac.com',
            'parent@kiddietrac.com',
            'parent2@kiddietrac.com',
            'parent3@kiddietrac.com',
            'parent4@kiddietrac.com',
        ];
        $userIds = DB::table('users')->whereIn('email', $emails)->pluck('id');

        if ($userIds->isNotEmpty()) {
            DB::table('role_assignments')->whereIn('user_id', $userIds)->delete();
            DB::table('guardians')->whereIn('user_id', $userIds)->delete();
            DB::table('personal_access_tokens')->whereIn('tokenable_id', $userIds)->delete();
        }

        DB::table('families')->where('family_name', 'LIKE', '[Demo]%')->delete();
        DB::table('users')->whereIn('email', $emails)->delete();

        $this->info('Demo data reset.');
    }

    private function ensureAgency(): object
    {
        $existing = DB::table('agencies')->where('slug', 'kiddietrac')->first();
        if ($existing) {
            return $existing;
        }

        DB::table('agencies')->insert([
            'id' => 1,
            'name' => 'Kiddietrac',
            'slug' => 'kiddietrac',
            'contact_email' => 'hello@kiddietrac.com',
            'timezone' => 'America/Toronto',
            'locale' => 'en-CA',
            'billing_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('agencies')->where('id', 1)->first();
    }

    private function ensureCentre(int $agencyId): object
    {
        $existing = DB::table('centres')->where('slug', 'caledon')->first();
        if ($existing) {
            return $existing;
        }

        DB::table('centres')->insert([
            'id' => 1,
            'agency_id' => $agencyId,
            'name' => 'Kiddietrac Caledon',
            'slug' => 'caledon',
            'license_number' => 'CL-2024-CALEDON-001',
            'license_capacity' => 56,
            'address_line1' => '123 Maple Lane',
            'city' => 'Caledon',
            'province' => 'ON',
            'postal_code' => 'L7C 1A1',
            'country' => 'CA',
            'phone' => '905-555-0101',
            'email' => 'caledon@kiddietrac.com',
            'open_time' => '07:00:00',
            'close_time' => '18:00:00',
            'cwelcc_enrolled' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('centres')->where('id', 1)->first();
    }

    private function ensureRooms(int $centreId): array
    {
        $rooms = [
            ['id' => 1, 'name' => 'Acorn Room',     'age_group' => 'infant',    'age_min_months' => 0,  'age_max_months' => 18, 'capacity' => 10, 'ratio_children' => 3, 'color_hex' => '#E27B58'],
            ['id' => 2, 'name' => 'Sapling Room',   'age_group' => 'toddler',   'age_min_months' => 18, 'age_max_months' => 30, 'capacity' => 15, 'ratio_children' => 5, 'color_hex' => '#E8A02E'],
            ['id' => 3, 'name' => 'Sunflower Room', 'age_group' => 'preschool', 'age_min_months' => 30, 'age_max_months' => 60, 'capacity' => 24, 'ratio_children' => 8, 'color_hex' => '#8EC73C'],
        ];

        foreach ($rooms as $r) {
            DB::table('rooms')->updateOrInsert(
                ['id' => $r['id']],
                [
                    ...$r,
                    'centre_id' => $centreId,
                    'ratio_educators' => 1,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        return DB::table('rooms')->where('centre_id', $centreId)->orderBy('id')->get()->all();
    }

    private function createDirector(int $centreId, int $agencyId, string $password): int
    {
        $userId = $this->upsertUser('director@kiddietrac.com', 'Sarah', 'Chen', $password);
        $this->assignRole($userId, 'centre_director', $centreId, $agencyId);

        return $userId;
    }

    private function createEducators(int $centreId, int $agencyId, string $password): array
    {
        $ed1 = $this->upsertUser('educator@kiddietrac.com', 'Maria', 'Santos', $password);
        $this->assignRole($ed1, 'educator', $centreId, $agencyId);

        $ed2 = $this->upsertUser('educator2@kiddietrac.com', 'James', 'Walker', $password);
        $this->assignRole($ed2, 'educator', $centreId, $agencyId);

        foreach ([$ed1, $ed2] as $userId) {
            DB::table('staff_certifications')->insert([
                'user_id' => $userId,
                'cert_type' => 'RECE',
                'certifier' => 'College of ECE Ontario',
                'issued_at' => now()->subYears(3)->toDateString(),
                'expires_at' => now()->addYears(2)->toDateString(),
                'active' => true,
                'created_at' => now(),
            ]);
            DB::table('staff_certifications')->insert([
                'user_id' => $userId,
                'cert_type' => 'First_Aid',
                'certifier' => 'Canadian Red Cross',
                'issued_at' => now()->subMonths(8)->toDateString(),
                'expires_at' => now()->addMonths(28)->toDateString(),
                'active' => true,
                'created_at' => now(),
            ]);
        }

        return [
            (object) ['id' => $ed1, 'first_name' => 'Maria'],
            (object) ['id' => $ed2, 'first_name' => 'James'],
        ];
    }

    private function createFamilies(int $centreId, int $agencyId, array $rooms, string $password): array
    {
        $blueprints = [
            [
                'family' => 'The Patel Family',
                'parent' => ['parent@kiddietrac.com', 'Priya', 'Patel', 'mother'],
                'children' => [
                    ['Aria',  'Patel', 'female', '-2 years -3 months', 2, 'preschool', ['allergy' => 'peanut']],
                ],
            ],
            [
                'family' => 'The Nguyen-Brown Family',
                'parent' => ['parent2@kiddietrac.com', 'Linh', 'Nguyen', 'mother'],
                'children' => [
                    ['Oliver', 'Brown',  'male',   '-3 years',          2, 'preschool', []],
                    ['Mei',    'Nguyen', 'female', '-1 year -2 months', 1, 'toddler',   ['intolerance' => 'lactose']],
                ],
            ],
            [
                'family' => 'The Okonkwo Family',
                'parent' => ['parent3@kiddietrac.com', 'David', 'Okonkwo', 'father'],
                'children' => [
                    ['Zara', 'Okonkwo', 'female', '-2 years',  1, 'toddler', []],
                    ['Kai',  'Okonkwo', 'male',   '-8 months', 0, 'infant',  []],
                ],
            ],
            [
                'family' => 'The MacIntosh Family',
                'parent' => ['parent4@kiddietrac.com', 'Emma', 'MacIntosh', 'mother'],
                'children' => [
                    ['Finn', 'MacIntosh', 'male', '-3 years -8 months', 2, 'preschool', ['medical_condition' => 'asthma']],
                ],
            ],
        ];

        $created = [];

        foreach ($blueprints as $b) {
            [$parentEmail, $parentFirst, $parentLast, $relationship] = $b['parent'];
            $parentUserId = $this->upsertUser($parentEmail, $parentFirst, $parentLast, $password);
            $this->assignRole($parentUserId, 'guardian', null, $agencyId);

            $familyName = '[Demo] '.$b['family'];
            $existing = DB::table('families')->where('family_name', $familyName)->first();

            $familyId = $existing
                ? (int) $existing->id
                : (int) DB::table('families')->insertGetId([
                    'centre_id' => $centreId,
                    'family_name' => $familyName,
                    'primary_email' => $parentEmail,
                    'preferred_lang' => 'en-CA',
                    'billing_split' => 'single',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('guardians')->updateOrInsert(
                ['family_id' => $familyId, 'user_id' => $parentUserId],
                [
                    'relationship' => $relationship,
                    'is_primary' => true,
                    'can_pickup' => true,
                    'can_receive_billing' => true,
                    'billing_share_pct' => 100.00,
                    'created_at' => now(),
                ]
            );

            $childIds = [];
            foreach ($b['children'] as $c) {
                [$firstName, $lastName, $gender, $dobRel, $roomIdx, $ageGroup, $flags] = $c;

                $childId = (int) DB::table('children')->insertGetId([
                    'family_id' => $familyId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'preferred_name' => $firstName,
                    'date_of_birth' => now()->modify($dobRel)->toDateString(),
                    'gender' => $gender,
                    'preferred_lang' => 'en-CA',
                    'enrollment_status' => 'enrolled',
                    'enrolled_at' => now()->subMonths(random_int(2, 18))->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $monthlyFee = match ($ageGroup) {
                    'infant' => 1850,
                    'toddler' => 1650,
                    default => 1450,
                };
                $subsidyAmount = match ($ageGroup) {
                    'infant' => 1650,
                    'toddler' => 1450,
                    default => 1250,
                };

                DB::table('enrollments')->insert([
                    'child_id' => $childId,
                    'room_id' => $rooms[$roomIdx]->id,
                    'start_date' => now()->subMonths(random_int(2, 12))->toDateString(),
                    'schedule' => json_encode(['mon' => 'FT', 'tue' => 'FT', 'wed' => 'FT', 'thu' => 'FT', 'fri' => 'FT']),
                    'monthly_fee' => $monthlyFee,
                    'cwelcc_eligible' => true,
                    'created_at' => now(),
                ]);

                DB::table('subsidies')->insert([
                    'child_id' => $childId,
                    'type' => 'CWELCC',
                    'case_number' => 'CWELCC-'.str_pad((string) $childId, 6, '0', STR_PAD_LEFT),
                    'monthly_amount' => $subsidyAmount,
                    'valid_from' => now()->subYear()->toDateString(),
                    'approved_at' => now()->subYear()->toDateString(),
                    'active' => true,
                    'created_at' => now(),
                ]);

                foreach ($flags as $type => $category) {
                    DB::table('child_health_flags')->insert([
                        'child_id' => $childId,
                        'flag_type' => $type,
                        'category' => $category,
                        'severity' => match ($category) {
                            'peanut' => 'life_threatening',
                            'asthma' => 'severe',
                            default => 'moderate',
                        },
                        'notes' => $this->flagNote($category),
                        'action_plan' => $this->flagActionPlan($category),
                        'active' => true,
                        'created_at' => now(),
                    ]);
                }

                $childIds[] = $childId;
            }

            $created[] = (object) [
                'id' => $familyId,
                'parent_id' => $parentUserId,
                'child_ids' => $childIds,
            ];
        }

        return $created;
    }

    private function createTodaysActivity(array $families, array $educators): void
    {
        $today = now()->setTime(0, 0);
        $educatorIds = array_map(fn ($e) => $e->id, $educators);

        foreach ($families as $family) {
            foreach ($family->child_ids as $i => $childId) {
                if ($i > 0 && random_int(0, 100) > 65) {
                    continue;
                }

                $enrollment = DB::table('enrollments')->where('child_id', $childId)->latest('start_date')->first();
                $roomId = $enrollment->room_id;

                $arrivedAt = $today->copy()->addHours(8)->addMinutes(random_int(0, 75));
                $isCheckedOut = random_int(0, 100) < 20;

                DB::table('check_events')->insert([
                    'child_id' => $childId,
                    'room_id' => $roomId,
                    'event_type' => 'check_in',
                    'occurred_at' => $arrivedAt,
                    'by_user_id' => $family->parent_id,
                    'recorded_by_id' => $educatorIds[array_rand($educatorIds)],
                    'mood_at_event' => ['happy', 'calm', 'happy', 'calm', 'tired'][array_rand([0, 1, 2, 3, 4])],
                    'created_at' => now(),
                ]);

                if ($isCheckedOut) {
                    DB::table('check_events')->insert([
                        'child_id' => $childId,
                        'room_id' => $roomId,
                        'event_type' => 'check_out',
                        'occurred_at' => $today->copy()->addHours(16)->addMinutes(random_int(0, 60)),
                        'by_user_id' => $family->parent_id,
                        'recorded_by_id' => $educatorIds[array_rand($educatorIds)],
                        'mood_at_event' => ['happy', 'tired', 'calm'][array_rand([0, 1, 2])],
                        'created_at' => now(),
                    ]);
                }

                $this->logDayEvents($childId, $roomId, $educatorIds, $arrivedAt, $isCheckedOut);
            }
        }
    }

    private function logDayEvents(int $childId, int $roomId, array $educatorIds, $arrivedAt, bool $checkedOut): void
    {
        $now = now();
        $events = [
            ['snack',     $arrivedAt->copy()->addHours(2),                      ['meal' => 'morning snack', 'items' => ['banana', 'crackers'], 'amount' => 'most']],
            ['diaper',    $arrivedAt->copy()->addHours(2)->addMinutes(20),       ['type' => 'wet']],
            ['activity',  $arrivedAt->copy()->addHours(3),                      ['name' => 'Free play', 'domain' => 'social_emotional', 'duration_min' => 45]],
            ['meal',      $arrivedAt->copy()->addHours(4),                      ['meal' => 'lunch', 'items' => ['chicken', 'rice', 'carrots'], 'amount' => 'all']],
            ['nap_start', $arrivedAt->copy()->addHours(5),                      []],
            ['nap_end',   $arrivedAt->copy()->addHours(7),                      []],
            ['snack',     $arrivedAt->copy()->addHours(7)->addMinutes(15),      ['meal' => 'afternoon snack', 'items' => ['apple slices'], 'amount' => 'all']],
            ['activity',  $arrivedAt->copy()->addHours(8),                      ['name' => 'Story time', 'domain' => 'language_literacy', 'duration_min' => 20]],
            ['mood',      $arrivedAt->copy()->addHours(8)->addMinutes(30),      ['score' => 'happy']],
        ];

        foreach ($events as [$type, $when, $payload]) {
            if ($when->greaterThan($now)) {
                continue;
            }
            if ($checkedOut && $when->greaterThan($now->copy()->subHours(2))) {
                continue;
            }

            DB::table('daily_events')->insert([
                'child_id' => $childId,
                'room_id' => $roomId,
                'event_type' => $type,
                'occurred_at' => $when,
                'payload' => json_encode($payload),
                'recorded_by_id' => $educatorIds[array_rand($educatorIds)],
                'voice_logged' => false,
                'synced_at' => $when,
                'created_at' => $when,
                'updated_at' => $when,
            ]);
        }
    }

    private function upsertUser(string $email, string $first, string $last, string $password): int
    {
        $existing = DB::table('users')->where('email', $email)->first();

        if ($existing) {
            DB::table('users')->where('id', $existing->id)->update([
                'password' => Hash::make($password),
                'first_name' => $first,
                'last_name' => $last,
                'status' => 'active',
                'updated_at' => now(),
            ]);

            return (int) $existing->id;
        }

        return (int) DB::table('users')->insertGetId([
            'email' => $email,
            'password' => Hash::make($password),
            'first_name' => $first,
            'last_name' => $last,
            'locale' => 'en-CA',
            'timezone' => 'America/Toronto',
            'status' => 'active',
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assignRole(int $userId, string $role, ?int $centreId = null, ?int $agencyId = null): void
    {
        DB::table('role_assignments')->updateOrInsert(
            ['user_id' => $userId, 'role' => $role, 'agency_id' => $agencyId, 'centre_id' => $centreId],
            ['active' => true, 'created_at' => now()]
        );
    }

    private function flagNote(string $category): string
    {
        return match ($category) {
            'peanut' => 'Severe peanut allergy. EpiPen in medical cabinet.',
            'asthma' => 'Mild persistent asthma. Inhaler in office.',
            'lactose' => 'Lactose intolerant — provide oat milk substitute.',
            default => '',
        };
    }

    private function flagActionPlan(string $category): string
    {
        return match ($category) {
            'peanut' => '1. Remove from area. 2. Administer EpiPen if symptoms develop. 3. Call 911. 4. Notify parents immediately.',
            'asthma' => '1. Calm the child. 2. Administer inhaler (2 puffs). 3. If no improvement in 5 min, repeat. 4. If still no improvement, call 911.',
            'lactose' => 'Substitute oat milk at meals. Do not serve regular milk or cheese.',
            default => '',
        };
    }
}
