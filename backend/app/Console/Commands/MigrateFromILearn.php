<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PDO;
use Throwable;

#[AsCommand(
    name: 'kiddietrac:migrate-ilearn',
    description: 'Migrate data from an iLearn SQLite database into the Kiddietrac MySQL database',
)]
final class MigrateFromILearn extends Command
{
    protected $signature = 'kiddietrac:migrate-ilearn
                            {--sqlite=/home/k3l6yt3xmih3/public_html/kiddietrac.db : Path to iLearn SQLite DB}
                            {--centre-id=1 : Target Kiddietrac centre ID}
                            {--agency-id=1 : Target Kiddietrac agency ID}
                            {--dry-run : Show what would be migrated without writing}';

    public function handle(): int
    {
        $sqlitePath = $this->option('sqlite');
        $centreId = (int) $this->option('centre-id');
        $agencyId = (int) $this->option('agency-id');
        $dryRun = (bool) $this->option('dry-run');

        if (!file_exists($sqlitePath)) {
            $this->error("iLearn database not found at: {$sqlitePath}");
            return self::FAILURE;
        }

        $this->info('────────────────────────────────────────────');
        $this->info(' iLearn → Kiddietrac Migration');
        $this->info('────────────────────────────────────────────');
        $this->line(" Source:    {$sqlitePath}");
        $this->line(" Target centre: {$centreId}");
        $this->line(" Target agency: {$agencyId}");
        $this->line(' Dry run:   '.($dryRun ? 'YES (no writes)' : 'NO (will write)'));
        $this->newLine();

        if (!$dryRun && !$this->confirm('This will write data to Kiddietrac. Continue?')) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        $sqlite = new PDO("sqlite:{$sqlitePath}");
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try {
            // Show what tables exist in the iLearn DB so the user can map them
            $this->info('Discovering iLearn schema...');
            $tables = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
                ->fetchAll(PDO::FETCH_COLUMN);
            $this->line('Found '.count($tables).' tables: '.implode(', ', $tables));
            $this->newLine();

            //
            // ┌──────────────────────────────────────────────────────────┐
            // │ TODO: USER CUSTOMIZATION ZONE                            │
            // │                                                          │
            // │ iLearn's exact schema varies. Inspect your tables with:  │
            // │   sqlite3 ~/public_html/kiddietrac.db ".schema TABLE"   │
            // │                                                          │
            // │ Then map each iLearn column → Kiddietrac column below.   │
            // └──────────────────────────────────────────────────────────┘
            //

            $stats = [
                'families' => 0,
                'guardians' => 0,
                'children' => 0,
                'staff' => 0,
            ];

            // ─── EXAMPLE: Migrate families ─────────────────────────
            // Adjust the SELECT to match iLearn's actual table/column names.
            // Common iLearn family table names: 'parents', 'families', 'guardians', 'tbl_parent'

            if (in_array('families', $tables) || in_array('parents', $tables)) {
                $this->info('Migrating families...');
                $sourceTable = in_array('families', $tables) ? 'families' : 'parents';

                $rows = $sqlite->query("SELECT * FROM {$sourceTable}")->fetchAll(PDO::FETCH_ASSOC);
                $this->line('  Found '.count($rows).' rows in iLearn.'.$sourceTable);

                foreach ($rows as $row) {
                    // MAP iLearn columns → Kiddietrac columns
                    $mapped = [
                        'centre_id' => $centreId,
                        'family_name' => $row['family_name'] ?? $row['name'] ?? 'Migrated Family',
                        'primary_email' => $row['email'] ?? null,
                        'primary_phone' => $row['phone'] ?? null,
                        'address_line1' => $row['address'] ?? null,
                        'city' => $row['city'] ?? null,
                        'province' => 'ON',
                        'postal_code' => $row['postal_code'] ?? $row['zip'] ?? null,
                        'preferred_lang' => 'en-CA',
                        'billing_split' => 'single',
                        'created_at' => $row['created_at'] ?? now(),
                        'updated_at' => now(),
                    ];

                    if (!$dryRun) {
                        DB::table('families')->insert($mapped);
                    }
                    $stats['families']++;
                }
            }

            // ─── EXAMPLE: Migrate children ──────────────────────────
            // Adjust to match iLearn's actual children/students table

            if (in_array('children', $tables) || in_array('students', $tables)) {
                $this->info('Migrating children...');
                $sourceTable = in_array('children', $tables) ? 'children' : 'students';

                $rows = $sqlite->query("SELECT * FROM {$sourceTable}")->fetchAll(PDO::FETCH_ASSOC);
                $this->line('  Found '.count($rows).' rows in iLearn.'.$sourceTable);

                foreach ($rows as $row) {
                    $mapped = [
                        'family_id' => $row['family_id'] ?? $row['parent_id'] ?? 1, // adjust mapping
                        'first_name' => $row['first_name'] ?? $row['firstName'] ?? '',
                        'last_name' => $row['last_name'] ?? $row['lastName'] ?? '',
                        'preferred_name' => $row['nickname'] ?? null,
                        'date_of_birth' => $row['date_of_birth'] ?? $row['dob'] ?? '2020-01-01',
                        'gender' => $row['gender'] ?? 'prefer_not_to_say',
                        'enrollment_status' => $row['status'] ?? 'enrolled',
                        'preferred_lang' => 'en-CA',
                        'medical_notes' => $row['medical'] ?? null,
                        'dietary_notes' => $row['dietary'] ?? null,
                        'created_at' => $row['created_at'] ?? now(),
                        'updated_at' => now(),
                    ];

                    if (!$dryRun) {
                        DB::table('children')->insert($mapped);
                    }
                    $stats['children']++;
                }
            }

            // ─── EXAMPLE: Migrate staff users ───────────────────────
            // iLearn likely has a 'users' or 'teachers' or 'staff' table

            if (in_array('users', $tables) || in_array('staff', $tables) || in_array('teachers', $tables)) {
                $this->info('Migrating staff users...');
                $sourceTable = in_array('users', $tables) ? 'users' : (in_array('staff', $tables) ? 'staff' : 'teachers');

                $rows = $sqlite->query("SELECT * FROM {$sourceTable} WHERE role != 'parent' OR role IS NULL")->fetchAll(PDO::FETCH_ASSOC);
                $this->line('  Found '.count($rows).' rows in iLearn.'.$sourceTable);

                foreach ($rows as $row) {
                    $email = $row['email'] ?? null;
                    if (!$email) continue;

                    $existing = DB::table('users')->where('email', $email)->first();
                    if ($existing) continue; // Skip duplicates

                    if (!$dryRun) {
                        $userId = DB::table('users')->insertGetId([
                            'email' => $email,
                            'password' => Hash::make('TempPassword123!'), // User will reset
                            'first_name' => $row['first_name'] ?? '',
                            'last_name' => $row['last_name'] ?? '',
                            'phone' => $row['phone'] ?? null,
                            'locale' => 'en-CA',
                            'timezone' => 'America/Toronto',
                            'status' => 'invited', // Force password reset on first login
                            'created_at' => $row['created_at'] ?? now(),
                            'updated_at' => now(),
                        ]);

                        // Determine role based on iLearn's role column
                        $role = match (strtolower($row['role'] ?? '')) {
                            'director', 'admin' => 'centre_director',
                            'teacher', 'educator', 'staff' => 'educator',
                            default => 'educator',
                        };

                        DB::table('role_assignments')->insert([
                            'user_id' => $userId,
                            'role' => $role,
                            'centre_id' => $centreId,
                            'agency_id' => $agencyId,
                            'active' => true,
                            'created_at' => now(),
                        ]);
                    }
                    $stats['staff']++;
                }
            }

            // ─── ADD YOUR OWN MAPPINGS HERE ────────────────────────
            // Examples of things you may want to migrate:
            //
            // - enrollments (child → room assignments)
            // - subsidies (CWELCC eligibility data)
            // - immunization records
            // - daily attendance history
            // - invoices and payment history
            // - staff certifications
            // - incident reports
            //
            // Each follows the same pattern:
            //   1. Read from sqlite via $sqlite->query()
            //   2. Map columns to Kiddietrac schema
            //   3. DB::table('target_table')->insert($mapped) — when !$dryRun

            $this->newLine();
            $this->info('────────────────────────────────────────────');
            $this->info(' Migration summary');
            $this->info('────────────────────────────────────────────');
            $this->line(" Families:  {$stats['families']}");
            $this->line(" Children:  {$stats['children']}");
            $this->line(" Staff:     {$stats['staff']}");
            $this->newLine();

            if ($dryRun) {
                $this->warn('DRY RUN — no data was written. Re-run without --dry-run to actually migrate.');
            } else {
                $this->info('✓ Migration complete.');
                $this->line('Next steps:');
                $this->line('  1. Spot-check the migrated data via the director dashboard');
                $this->line('  2. Email migrated staff with their password-reset instructions');
                $this->line('  3. Inform families of the platform change');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Migration failed: '.$e->getMessage());
            $this->line($e->getTraceAsString());
            return self::FAILURE;
        }
    }
}
