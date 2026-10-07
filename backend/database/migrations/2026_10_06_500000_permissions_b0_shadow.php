<?php

use App\Support\PermissionMap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* PERMISSIONS STEP 1 (B0 + B1), 2026-10-06.
   B0 - every agency's built-in roles now describe what each role can REALLY reach today
        (App\Support\PermissionMap::DEFAULTS, computed from the live route gates), with
        the ~30 areas the matrix never covered added. The only access change behind it is
        the approved one: home visitors and auditors no longer log late pickups.
        An admin's own ticks/unticks are kept only where they don't contradict reality;
        none existed when this ran (every row matched its seed baseline) - each row's
        added/removed keys are written to audit_logs either way.
        Custom roles get scope (centre | agency) and base_role.
   B1 - permission_shadow_log (watch-only disagreements) and the kill switch
        permissions.mode = shadow, with the 14-day window starting now.
   role_assignments.role (ENUM, general_ci) and roles.key (unicode_ci) are left alone:
   nothing joins them in SQL; App\Support\Permissions matches them in PHP. */
return new class extends Migration {
    /** What the seeds wrote (v22p8 + 2026-10-06 additions) - to tell an admin's edit from a seed. */
    private const OLD_BASELINE = [
        'centre_director' => ['centres.view', 'families.view', 'families.edit', 'children.view', 'children.edit', 'children.archive',
            'staff.view', 'staff.invite', 'staff.schedule', 'rooms.view', 'rooms.edit', 'invoices.view', 'invoices.generate',
            'reports.compliance', 'reports.attendance', 'incidents.view', 'incidents.review', 'incidents.notify_parent',
            'medications.view', 'medications.authorize', 'observations.view', 'observations.create', 'lesson_plans.view',
            'lesson_plans.create', 'kiosk.manage', 'edocuments.view', 'edocuments.send', 'announcements.create',
            'tasks.assign', 'late_pickups.log', 'late_pickups.decide', 'home_visits.review', 'help.assign'],
        'educator' => ['children.view', 'incidents.view', 'incidents.create', 'observations.view', 'observations.create',
            'lesson_plans.view', 'medications.view', 'attendance.record', 'own_tasks.view', 'late_pickups.log'],
        'guardian' => ['own_children.view', 'own_invoices.view', 'own_invoices.pay', 'incidents.acknowledge', 'edocuments.sign', 'own_tasks.view'],
        'home_visitor' => ['home_visits.file', 'own_tasks.view', 'edocuments.sign'],
        'auditor' => ['centres.view', 'families.view', 'children.view', 'staff.view', 'rooms.view', 'invoices.view',
            'reports.compliance', 'reports.attendance', 'audit.view'],
    ];

    private const NAMES = [
        'agency_admin' => ['Agency Admin', 'Full access to every agency feature.'],
        'centre_director' => ['Centre Director', 'Operational lead at one centre.'],
        'educator' => ['Educator', 'Day-to-day care staff.'],
        'guardian' => ['Parent / Guardian', 'Self-serve parent portal.'],
        'home_visitor' => ['Home Visitor', 'Visits families and centres; files reports and inspections.'],
        'auditor' => ['Auditor', 'Read-only access for review.'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permission_shadow_log')) {
            Schema::create('permission_shadow_log', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->date('day');
                $t->unsignedBigInteger('user_id');
                $t->string('roles', 191)->nullable();
                $t->unsignedBigInteger('agency_id')->nullable();
                $t->string('method', 10);
                $t->string('route', 191);
                $t->string('path', 255)->nullable();
                $t->string('permission', 100);
                $t->string('matrix_decision', 10);   // allow | deny
                $t->unsignedSmallInteger('actual_status');
                $t->unsignedInteger('hits')->default(1);
                $t->dateTime('first_seen');
                $t->dateTime('last_seen');
                $t->unique(['day', 'user_id', 'method', 'route'], 'psl_day_user_route');
                $t->index(['permission', 'day'], 'psl_perm_day');
            });
        }

        if (! Schema::hasColumn('roles', 'scope')) {
            Schema::table('roles', function (Blueprint $t) {
                $t->string('scope', 10)->nullable()->after('permissions');      // custom roles: centre | agency
                $t->string('base_role', 60)->nullable()->after('scope');        // custom roles: the built-in it starts from
            });
        }
        DB::table('roles')->where('is_system', false)->whereNull('scope')->update(['scope' => 'centre']);

        $all = array_keys(PermissionMap::CATALOG);
        foreach (DB::table('agencies')->whereNull('deleted_at')->pluck('id') as $aid) {
            $rows = DB::table('roles')->where('agency_id', $aid)->where('is_system', true)->get()->keyBy('key');
            foreach (PermissionMap::DEFAULTS as $key => $defaults) {
                $row = $rows->get($key);
                if (! $row) {
                    if (DB::table('roles')->where('agency_id', $aid)->where('key', $key)->exists()) continue;
                    DB::table('roles')->insert([
                        'agency_id' => $aid, 'key' => $key, 'name' => self::NAMES[$key][0], 'description' => self::NAMES[$key][1],
                        'permissions' => json_encode(array_values($defaults)), 'is_system' => true,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $this->audit((int) $aid, $key, 'created', $defaults, []);
                    continue;
                }
                if ($row->deleted_at) continue;
                $stored = json_decode((string) $row->permissions, true) ?: [];
                $base = $key === 'agency_admin' ? $all : (self::OLD_BASELINE[$key] ?? $stored);
                // An admin's own edits = differences from the seed. Kept only where they
                // agree with reality: a tick on a key the role can't reach, or an untick of
                // one it can, would make the matrix lie, so the default wins (and is logged).
                $adminAdded = array_diff($stored, $base);
                $adminRemoved = array_diff($base, $stored);
                $overridden = array_values(array_merge(array_diff($adminAdded, $defaults), array_intersect($adminRemoved, $defaults)));
                $new = array_values($defaults);
                sort($new);
                $added = array_values(array_diff($new, $stored));
                $removed = array_values(array_diff($stored, $new));
                if ($added || $removed) {
                    DB::table('roles')->where('id', $row->id)->update(['permissions' => json_encode($new), 'updated_at' => now()]);
                    $this->audit((int) $aid, $key, 'aligned', $added, $removed, $overridden, (int) $row->id);
                }
            }
        }

        foreach (['permissions.mode' => 'shadow', 'permissions.shadow_started_at' => now()->toDateTimeString()] as $k => $v) {
            if (! DB::table('platform_settings')->where('key', $k)->exists()) {
                DB::table('platform_settings')->insert(['key' => $k, 'value' => $v, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        Cache::forget('platform_settings.all');
    }

    private function audit(int $aid, string $key, string $what, array $added, array $removed, array $overridden = [], ?int $id = null): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => null, 'agency_id' => $aid, 'action' => 'roles.matrix_' . $what, 'entity_type' => 'role', 'entity_id' => $id,
            'payload' => json_encode(['role' => $key, 'added' => array_values($added), 'removed' => array_values($removed),
                'admin_edits_overridden' => $overridden, 'source' => 'permissions step 1 (B0): defaults = real access today']),
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        // The previous rows are in audit_logs (roles.matrix_aligned); the log table is harmless.
    }
};
