<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[AsCommand(
    name: 'kiddietrac:init-customer',
    description: 'Wipe demo data and set up a clean centre for a real customer',
)]
final class InitCustomerCentre extends Command
{
    protected $signature = 'kiddietrac:init-customer
                            {--name= : Centre name (e.g. "Sunshine Daycare")}
                            {--slug= : URL-friendly slug (e.g. "sunshine")}
                            {--director-email= : Director email}
                            {--director-first= : Director first name}
                            {--director-last= : Director last name}
                            {--license= : License number}
                            {--capacity=30 : License capacity}
                            {--city= : City}
                            {--postal= : Postal code}
                            {--address= : Street address}
                            {--phone= : Phone number}';

    public function handle(): int
    {
        $this->info('────────────────────────────────────────────');
        $this->info(' Kiddietrac: Initialize Real Customer Centre');
        $this->info('────────────────────────────────────────────');
        $this->newLine();

        // Gather inputs (prompt if not provided as flags)
        $name = $this->option('name') ?: $this->ask('Centre name (e.g., "Sunshine Daycare")');
        $slug = $this->option('slug') ?: Str::slug($name);
        $directorEmail = $this->option('director-email') ?: $this->ask('Director email');
        $directorFirst = $this->option('director-first') ?: $this->ask('Director first name');
        $directorLast = $this->option('director-last') ?: $this->ask('Director last name');
        $license = $this->option('license') ?: $this->ask('License number (or "TBD")', 'TBD');
        $capacity = (int) ($this->option('capacity') ?: $this->ask('License capacity', '30'));
        $city = $this->option('city') ?: $this->ask('City', 'Caledon');
        $postal = $this->option('postal') ?: $this->ask('Postal code (or leave blank)', '');
        $address = $this->option('address') ?: $this->ask('Street address (or leave blank)', '');
        $phone = $this->option('phone') ?: $this->ask('Phone (or leave blank)', '');

        $this->newLine();
        $this->warn('═══════════════════════════════════════════════════════════');
        $this->warn(' WARNING: This will DELETE all demo data including:');
        $this->warn('  - All [Demo] families, children, and seeded users');
        $this->warn('  - All check-in events, daily events, photos, messages');
        $this->warn('  - All conversations, observations, invoices, payments');
        $this->warn(' Your agency admin (mr.anthonyhosein@gmail.com) is preserved.');
        $this->warn('═══════════════════════════════════════════════════════════');
        $this->newLine();

        if (!$this->confirm('Type "yes" to wipe demo data and create the new centre')) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        $tempPassword = Str::random(12);

        DB::transaction(function () use ($name, $slug, $directorEmail, $directorFirst, $directorLast, $license, $capacity, $city, $postal, $address, $phone, $tempPassword): void {
            $this->info('Wiping demo data...');
            $this->wipeDemoData();

            $this->info("Creating centre '{$name}'...");
            $centreId = $this->createCentre($name, $slug, $license, $capacity, $city, $postal, $address, $phone);

            $this->info("Inviting director {$directorFirst} {$directorLast}...");
            $this->createDirector($centreId, $directorEmail, $directorFirst, $directorLast, $tempPassword);
        });

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════');
        $this->info(' ✓ Centre created. Send these credentials to the director:');
        $this->info('═══════════════════════════════════════════════════════════');
        $this->newLine();
        $this->line("  URL:      https://app.kiddietrac.com");
        $this->line("  Email:    {$directorEmail}");
        $this->line("  Password: {$tempPassword}");
        $this->newLine();
        $this->warn('  ⚠ Tell them to change their password immediately after first login.');
        $this->newLine();
        $this->line(' Next steps:');
        $this->line('  1. Director logs in and adds rooms (Dashboard → New Room)');
        $this->line('  2. Director adds families and children');
        $this->line('  3. Director invites educators');
        $this->line('  4. Director invites parents');
        $this->newLine();

        return self::SUCCESS;
    }

    private function wipeDemoData(): void
    {
        // Order matters — delete dependent records first
        $demoEmails = [
            'director@kiddietrac.com',
            'educator@kiddietrac.com',
            'educator2@kiddietrac.com',
            'parent@kiddietrac.com',
            'parent2@kiddietrac.com',
            'parent3@kiddietrac.com',
            'parent4@kiddietrac.com',
        ];
        $demoUserIds = DB::table('users')->whereIn('email', $demoEmails)->pluck('id')->all();

        // 1. Clean records linked to demo users
        if (!empty($demoUserIds)) {
            DB::table('personal_access_tokens')->whereIn('tokenable_id', $demoUserIds)->delete();
            DB::table('device_tokens')->whereIn('user_id', $demoUserIds)->delete();
            DB::table('time_entries')->whereIn('user_id', $demoUserIds)->delete();
            DB::table('shifts')->whereIn('user_id', $demoUserIds)->delete();
            DB::table('staff_certifications')->whereIn('user_id', $demoUserIds)->delete();
            DB::table('role_assignments')->whereIn('user_id', $demoUserIds)->delete();
            DB::table('guardians')->whereIn('user_id', $demoUserIds)->delete();
        }

        // 2. Find demo families and their dependent data
        $demoFamilyIds = DB::table('families')->where('family_name', 'LIKE', '[Demo]%')->pluck('id')->all();
        if (!empty($demoFamilyIds)) {
            $demoChildIds = DB::table('children')->whereIn('family_id', $demoFamilyIds)->pluck('id')->all();

            if (!empty($demoChildIds)) {
                // Child-related data
                DB::table('daily_events')->whereIn('child_id', $demoChildIds)->delete();
                DB::table('check_events')->whereIn('child_id', $demoChildIds)->delete();
                DB::table('ai_daily_digests')->whereIn('child_id', $demoChildIds)->delete();
                DB::table('media')->whereIn('child_id', $demoChildIds)->delete();
                DB::table('observations')->whereIn('child_id', $demoChildIds)->delete();
                DB::table('conversations')->whereIn('child_id', $demoChildIds)->delete();
                DB::table('child_health_flags')->whereIn('child_id', $demoChildIds)->delete();
                DB::table('subsidies')->whereIn('child_id', $demoChildIds)->delete();
                DB::table('immunizations')->whereIn('child_id', $demoChildIds)->delete();
                DB::table('enrollments')->whereIn('child_id', $demoChildIds)->delete();
            }

            // Invoice-related data
            $demoInvoiceIds = DB::table('invoices')->whereIn('family_id', $demoFamilyIds)->pluck('id')->all();
            if (!empty($demoInvoiceIds)) {
                DB::table('invoice_lines')->whereIn('invoice_id', $demoInvoiceIds)->delete();
                DB::table('payments')->whereIn('invoice_id', $demoInvoiceIds)->delete();
                DB::table('invoices')->whereIn('id', $demoInvoiceIds)->delete();
            }

            // Now the children and families themselves
            DB::table('children')->whereIn('family_id', $demoFamilyIds)->delete();
            DB::table('families')->whereIn('id', $demoFamilyIds)->delete();
        }

        // 3. Clean messages from now-orphaned conversations
        $orphanConvos = DB::table('conversations')
            ->whereNotIn('parent_id', DB::table('users')->select('id'))
            ->orWhereNotIn('child_id', DB::table('children')->select('id'))
            ->pluck('id')->all();
        if (!empty($orphanConvos)) {
            DB::table('messages')->whereIn('conversation_id', $orphanConvos)->delete();
            DB::table('conversations')->whereIn('id', $orphanConvos)->delete();
        }

        // 4. Delete the demo users themselves
        if (!empty($demoUserIds)) {
            DB::table('users')->whereIn('id', $demoUserIds)->delete();
        }

        // 5. Delete the demo centre (Kiddietrac Caledon, id=1)
        DB::table('rooms')->where('centre_id', 1)->delete();
        DB::table('centres')->where('id', 1)->delete();

        $this->line('  ✓ Demo data wiped');
    }

    private function createCentre(string $name, string $slug, string $license, int $capacity, string $city, string $postal, string $address, string $phone): int
    {
        return (int) DB::table('centres')->insertGetId([
            'agency_id' => 1, // Stay in your single agency
            'name' => $name,
            'slug' => $slug,
            'license_number' => $license,
            'license_capacity' => $capacity,
            'address_line1' => $address ?: null,
            'city' => $city,
            'province' => 'ON',
            'postal_code' => $postal ?: null,
            'country' => 'CA',
            'phone' => $phone ?: null,
            'open_time' => '07:00:00',
            'close_time' => '18:00:00',
            'cwelcc_enrolled' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createDirector(int $centreId, string $email, string $first, string $last, string $tempPassword): void
    {
        // Check if user already exists
        $existing = DB::table('users')->where('email', $email)->first();

        if ($existing) {
            $userId = (int) $existing->id;
            DB::table('users')->where('id', $userId)->update([
                'password' => Hash::make($tempPassword),
                'first_name' => $first,
                'last_name' => $last,
                'status' => 'active',
                'updated_at' => now(),
            ]);
        } else {
            $userId = (int) DB::table('users')->insertGetId([
                'email' => $email,
                'password' => Hash::make($tempPassword),
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

        DB::table('role_assignments')->updateOrInsert(
            ['user_id' => $userId, 'role' => 'centre_director', 'centre_id' => $centreId, 'agency_id' => 1],
            ['active' => true, 'created_at' => now()]
        );
    }
}
