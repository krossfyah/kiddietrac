<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\LeakGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * kt:leak-canary - plant (idempotently) the leak guard's canary records in the two test agencies (LeakGuard::AGENCIES).
 *
 * Each agency gets ONE canary centre ("Leak Guard canary - internal") so no real demo centre's roster changes, with a
 * room, seven people (agency admin, director, educator, home visitor, parent, a staff member who is also a parent,
 * an integration account), two families, two children, an enrolment, and one of each record type the probe looks
 * for: family chat message, team chat message, daily event, incident, child note, quick note, announcement, bell row,
 * custom form, document, family file, invoice (paid, $0) and HR employee file. Every text field that can carry it
 * carries the agency's marker KTCANARY-<agency>-<10 hex>.
 *
 * Safe by construction: test agencies send nothing; the people have @leakguard.invalid addresses, random unknown
 * passwords and no devices, so nobody can sign in as them and nothing can reach them; chat rows are stamped
 * email_notified_at so the chat-email job never mails them; the invoice is paid at $0 so no payment job charges it.
 * Every row is listed in leak_canaries (that list IS the flag). --remove deletes them all.
 */
final class KtLeakCanary extends Command
{
    protected $signature = 'kt:leak-canary {--remove : delete every canary row} {--show}';

    protected $description = 'Plant (idempotent) the leak guard canary records in the two test agencies.';

    public function handle(): int
    {
        if ($this->option('remove')) {
            $n = self::remove();
            $this->info("Removed {$n} canary rows.");
            return self::SUCCESS;
        }
        foreach (LeakGuard::AGENCIES as $a) {
            $made = self::ensure($a);
            $this->line("agency {$a}: marker " . LeakGuard::marker($a) . ', ' . count(LeakGuard::canaries($a)) . ' canary rows' . ($made ? ", {$made} (re)planted" : ''));
            if ($this->option('show')) {
                foreach (LeakGuard::canaries($a) as $k => $id) {
                    $this->line("   {$k} #{$id}");
                }
            }
        }
        return self::SUCCESS;
    }

    /** @return int rows (re)created */
    public static function ensure(int $agencyId): int
    {
        if (! DB::table('agencies')->where('id', $agencyId)->exists()) {
            return 0;
        }
        $settings = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        if (($settings['demo'] ?? false) !== true) {
            throw new \RuntimeException("agency {$agencyId} is not a test agency - canaries are planted in test agencies only");
        }
        $marker = LeakGuard::marker($agencyId) ?: ('KTCANARY-' . $agencyId . '-' . bin2hex(random_bytes(5)));
        $have = LeakGuard::canaries($agencyId);
        $made = 0;
        $now = now();

        $get = function (string $kind, string $table, callable $make) use (&$have, &$made, $agencyId, $marker, $now) {
            $id = $have[$kind] ?? null;
            if ($id && DB::table($table)->where('id', $id)->exists()) {
                return $id;
            }
            $id = (int) $make();
            DB::table('leak_canaries')->updateOrInsert(['agency_id' => $agencyId, 'kind' => $kind],
                ['table_name' => $table, 'row_id' => $id, 'marker' => $marker, 'created_at' => $now]);
            $have[$kind] = $id;
            $made++;
            return $id;
        };

        return (int) DB::transaction(function () use ($get, $agencyId, $marker, $now, &$made) {
            $centre = $get('centre', 'centres', fn () => \App\Support\CentreCap::allows($agencyId) ? DB::table('centres')->insertGetId([
                'agency_id' => $agencyId, 'name' => 'Leak Guard canary - internal ' . $marker,
                'slug' => 'leakguard-canary-' . $agencyId . '-' . Str::lower(Str::random(6)), 'status' => 'active',
                'city' => 'Canary', 'created_at' => $now, 'updated_at' => $now,
            ]) : throw new \RuntimeException("agency {$agencyId} is at its licence centre cap (CentreCap) - the canary centre needs room for one more"));
            $room = $get('room', 'rooms', fn () => DB::table('rooms')->insertGetId([
                'centre_id' => $centre, 'name' => 'Canary room ' . $marker, 'age_group' => 'preschool',
                'age_min_months' => 30, 'age_max_months' => 72, 'capacity' => 8, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]));

            $user = function (string $role) use ($get, $agencyId, $marker, $now) {
                return $get('user:' . $role, 'users', fn () => DB::table('users')->insertGetId([
                    'email' => ($role === 'integration' ? 'integration+' : '') . 'canary-' . $role . '-a' . $agencyId . '@' . LeakGuard::EMAIL_DOMAIN,
                    'password' => Hash::make(bin2hex(random_bytes(32))),
                    'first_name' => 'Canary', 'last_name' => ucfirst(str_replace('_', ' ', $role)) . ' ' . $marker,
                    'status' => 'active', 'onboarded_at' => $now, 'password_changed_at' => $now, 'must_change_password' => 0,
                    'two_factor_enabled' => 1, 'last_agency_id' => $agencyId, 'sms_opt_in' => 0, 'voice_opt_out' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]));
            };
            $role = function (string $kind, int $uid, string $r, ?int $centreId) use ($get, $agencyId, $now) {
                return $get('ra:' . $kind, 'role_assignments', fn () => DB::table('role_assignments')->insertGetId([
                    'user_id' => $uid, 'role' => $r, 'agency_id' => $agencyId, 'centre_id' => $centreId, 'active' => 1, 'created_at' => $now,
                ]));
            };

            $admin = $user('agency_admin');       $role('agency_admin', $admin, 'agency_admin', null);
            $director = $user('centre_director'); $role('centre_director', $director, 'centre_director', $centre);
            $educator = $user('educator');        $role('educator', $educator, 'educator', $centre);
            $hv = $user('home_visitor');          $role('home_visitor', $hv, 'home_visitor', $centre);
            $parent = $user('guardian');          $role('guardian', $parent, 'guardian', $centre);
            $sp = $user('staff_parent');          $role('staff_parent_staff', $sp, 'educator', $centre); $role('staff_parent_guardian', $sp, 'guardian', $centre);
            $integ = $user('integration');        $role('integration', $integ, 'integration', null);

            foreach (['educator' => $educator, 'staff_parent' => $sp] as $k => $uid) {
                $get('educator_room:' . $k, 'educator_rooms', fn () => DB::table('educator_rooms')->insertGetId([
                    'user_id' => $uid, 'room_id' => $room, 'assigned_by_id' => $admin, 'created_at' => $now,
                ]));
            }

            $family = function (string $k, int $guardianUid) use ($get, $centre, $marker, $now) {
                $fid = $get('family' . $k, 'families', fn () => DB::table('families')->insertGetId([
                    'centre_id' => $centre, 'family_name' => 'Canary' . $k . ' ' . $marker,
                    'primary_email' => 'canary-family' . $k . '@' . LeakGuard::EMAIL_DOMAIN, 'notes' => 'Leak guard canary ' . $marker,
                    'created_at' => $now, 'updated_at' => $now,
                ]));
                $get('guardian' . $k, 'guardians', fn () => DB::table('guardians')->insertGetId([
                    'family_id' => $fid, 'user_id' => $guardianUid, 'relationship' => 'guardian', 'is_primary' => 1,
                    'can_pickup' => 1, 'can_receive_billing' => 1, 'created_at' => $now,
                ]));
                return $fid;
            };
            $fam = $family('', $parent);
            $fam2 = $family('2', $sp);

            $child = function (string $k, int $fid) use ($get, $room, $marker, $now) {
                $cid = $get('child' . $k, 'children', fn () => DB::table('children')->insertGetId([
                    'family_id' => $fid, 'first_name' => 'Canary' . $k, 'last_name' => $marker, 'date_of_birth' => '2022-06-15',
                    'enrollment_status' => 'enrolled', 'enrolled_at' => '2026-09-01', 'primary_room_id' => $room,
                    'medical_notes' => 'Canary medical ' . $marker, 'created_at' => $now, 'updated_at' => $now,
                ]));
                $get('enrollment' . $k, 'enrollments', fn () => DB::table('enrollments')->insertGetId([
                    'child_id' => $cid, 'room_id' => $room, 'start_date' => '2026-09-01', 'schedule' => null,
                    'monthly_fee' => 0, 'notes' => 'Leak guard canary', 'created_at' => $now,
                ]));
                return $cid;
            };
            $kid = $child('', $fam);
            $child('2', $fam2);

            $conv = $get('conversation', 'conversations', fn () => DB::table('conversations')->insertGetId([
                'centre_id' => $centre, 'family_id' => $fam, 'child_id' => $kid, 'subject' => 'Canary ' . $marker,
                'last_message_at' => $now, 'created_at' => $now,
            ]));
            $get('message', 'messages', fn () => DB::table('messages')->insertGetId([
                'conversation_id' => $conv, 'sender_id' => $educator, 'body' => 'Canary message ' . $marker,
                'created_at' => $now, 'email_notified_at' => $now, 'delivered_at' => $now, 'read_at' => $now, 'family_read_at' => $now,
            ]));
            $thread = $get('staff_thread', 'staff_threads', fn () => DB::table('staff_threads')->insertGetId([
                'agency_id' => $agencyId, 'title' => 'Canary team ' . $marker, 'is_group' => 1, 'created_by' => $admin,
                'last_message_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]));
            foreach (['admin' => $admin, 'educator' => $educator, 'director' => $director] as $k => $uid) {
                $get('staff_thread_participant:' . $k, 'staff_thread_participants', fn () => DB::table('staff_thread_participants')->insertGetId([
                    'thread_id' => $thread, 'user_id' => $uid, 'last_read_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]));
            }
            $get('staff_message', 'staff_messages', fn () => DB::table('staff_messages')->insertGetId([
                'thread_id' => $thread, 'sender_id' => $admin, 'body' => 'Canary team message ' . $marker,
                'created_at' => $now, 'updated_at' => $now, 'email_notified_at' => $now,
            ]));
            $get('daily_event', 'daily_events', fn () => DB::table('daily_events')->insertGetId([
                'child_id' => $kid, 'room_id' => $room, 'event_type' => 'note', 'occurred_at' => now()->subDay()->setTime(15, 0),
                'payload' => json_encode(['text' => 'Canary daily ' . $marker, 'note' => 'Canary daily ' . $marker]),
                'notes' => 'Canary daily ' . $marker, 'recorded_by_id' => $educator, 'created_at' => $now, 'updated_at' => $now,
            ]));
            $get('incident', 'incidents', fn () => DB::table('incidents')->insertGetId([
                'child_id' => $kid, 'room_id' => $room, 'incident_type' => 'general', 'severity' => 'low',
                'occurred_at' => now()->subDays(2), 'location' => 'Canary ' . $marker, 'description' => 'Canary incident ' . $marker,
                'recorded_by_id' => $educator, 'status' => 'closed', 'created_at' => $now, 'updated_at' => $now,
            ]));
            $get('child_note', 'child_notes', fn () => DB::table('child_notes')->insertGetId([
                'agency_id' => $agencyId, 'centre_id' => $centre, 'child_id' => $kid, 'author_id' => $educator, 'author_role' => 'educator',
                'body' => 'Canary note ' . $marker, 'created_at' => $now, 'updated_at' => $now,
            ]));
            $get('quick_note', 'quick_notes', fn () => DB::table('quick_notes')->insertGetId([
                'agency_id' => $agencyId, 'centre_id' => $centre, 'owner_user_id' => $admin, 'visibility' => 'private',
                'title' => 'Canary quick note ' . $marker, 'body' => 'Canary quick note ' . $marker, 'created_at' => $now, 'updated_at' => $now,
            ]));
            $ann = $get('announcement', 'announcements', fn () => DB::table('announcements')->insertGetId([
                'scope_type' => 'centre', 'scope_id' => $centre, 'agency_id' => $agencyId, 'audience' => 'all',
                'title' => 'Canary announcement ' . $marker, 'body' => 'Canary announcement ' . $marker,
                'send_email' => 0, 'send_sms' => 0, 'send_push' => 0, 'send_voice' => 0, 'sent_at' => $now, 'created_by_id' => $admin, 'created_at' => $now,
            ]));
            foreach ([$parent, $educator, $sp] as $uid) {
                if (! DB::table('announcement_recipients')->where('announcement_id', $ann)->where('user_id', $uid)->exists()) {
                    DB::table('announcement_recipients')->insert(['announcement_id' => $ann, 'user_id' => $uid]);
                }
            }
            foreach (['admin' => $admin, 'parent' => $parent, 'educator' => $educator] as $k => $uid) {
                $get('notification:' . $k, 'notifications', fn () => DB::table('notifications')->insertGetId([
                    'user_id' => $uid, 'agency_id' => $agencyId, 'type' => 'general', 'title' => 'Canary bell ' . $marker,
                    'body' => 'Canary bell ' . $marker, 'data' => json_encode(['centre_id' => $centre]), 'read_at' => $now, 'created_at' => $now,
                ]));
            }
            $get('custom_form', 'custom_forms', fn () => DB::table('custom_forms')->insertGetId([
                'agency_id' => $agencyId, 'centre_id' => $centre, 'created_by_user_id' => $admin, 'title' => 'Canary form ' . $marker,
                'description' => 'Canary form ' . $marker, 'schema_json' => json_encode(['fields' => []]), 'audience' => 'staff',
                'status' => 'draft', 'response_count' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]));
            $get('document', 'documents', fn () => DB::table('documents')->insertGetId([
                'scope_type' => 'child', 'scope_id' => $kid, 'category' => 'file', 'title' => 'Canary document ' . $marker,
                'notes' => 'Canary ' . $marker, 'file_url' => '/storage/leakguard/canary.txt', 'file_type' => 'text/plain', 'file_size' => 1,
                'uploaded_by_id' => $admin, 'created_at' => $now,
            ]));
            $get('family_file', 'family_files', fn () => DB::table('family_files')->insertGetId([
                'agency_id' => $agencyId, 'centre_id' => $centre, 'family_id' => $fam, 'child_id' => $kid, 'direction' => 'from_family',
                'uploaded_by' => $admin, 'title' => 'Canary file ' . $marker, 'note' => 'Canary ' . $marker,
                'path' => 'leakguard/canary.txt', 'original_name' => 'canary-' . $marker . '.txt', 'mime' => 'text/plain', 'size' => 1,
                'first_viewed_at' => $now, 'created_at' => $now,
            ]));
            $get('invoice', 'invoices', fn () => DB::table('invoices')->insertGetId([
                'family_id' => $fam, 'centre_id' => $centre, 'invoice_number' => $marker,
                'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'subtotal' => 0, 'total' => 0, 'amount_paid' => 0, 'balance_due' => 0,
                'issued_at' => '2026-11-01', 'issued_by_user_id' => $admin, 'due_at' => '2026-11-01', 'status' => 'paid',
                'notes' => 'Leak guard canary ' . $marker, 'created_at' => $now, 'updated_at' => $now,
            ]));
            $get('hr_employee_file', 'hr_employee_files', fn () => DB::table('hr_employee_files')->insertGetId([
                'agency_id' => $agencyId, 'user_id' => $educator, 'job_title' => 'Canary ' . $marker, 'employment_type' => 'full_time',
                'status' => 'active', 'primary_centre_id' => $centre, 'updated_by_id' => $admin, 'created_at' => $now, 'updated_at' => $now,
            ]));
            return $made;
        });
    }

    public static function remove(): int
    {
        $n = 0;
        $rows = DB::table('leak_canaries')->orderByDesc('id')->get();
        foreach ($rows as $r) {
            if ($r->table_name === 'announcements') {
                DB::table('announcement_recipients')->where('announcement_id', $r->row_id)->delete();
            }
            $n += DB::table($r->table_name)->where('id', $r->row_id)->delete();
        }
        DB::table('leak_canaries')->delete();
        return $n;
    }
}
