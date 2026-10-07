<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* ROLES & PERMISSIONS MATRIX — features added since it was written (2026-10-06).
   The matrix is DESCRIPTIVE ("Phase A: stored only" — no route or screen reads it), so
   this changes nobody's access. It brings each agency's built-in rows up to date with
   what the roles can actually do now, adding only the NEW keys (an admin's own ticks
   and unticks on the old keys are left exactly as they are), and adds the Home Visitor
   built-in row the matrix never had. */
return new class extends Migration {
    private const ADD = [
        'agency_admin' => ['tasks.assign', 'own_tasks.view', 'late_pickups.log', 'late_pickups.decide', 'home_visits.file',
            'home_visits.review', 'forms.manage', 'rsvp.manage', 'rsvp.senders', 'email_templates.manage', 'help.assign'],
        'centre_director' => ['tasks.assign', 'late_pickups.log', 'late_pickups.decide', 'home_visits.review', 'help.assign'],
        'educator' => ['own_tasks.view', 'late_pickups.log'],
        'guardian' => ['own_tasks.view'],
    ];

    public function up(): void
    {
        foreach (DB::table('roles')->where('is_system', true)->whereIn('key', array_keys(self::ADD))->whereNull('deleted_at')->get(['id', 'key', 'permissions']) as $r) {
            $have = json_decode((string) $r->permissions, true) ?: [];
            $new = array_values(array_unique(array_merge($have, self::ADD[$r->key])));
            if ($new !== $have) {
                DB::table('roles')->where('id', $r->id)->update(['permissions' => json_encode($new), 'updated_at' => now()]);
            }
        }
        $agencies = DB::table('roles')->distinct()->pluck('agency_id');
        foreach ($agencies as $aid) {
            if (DB::table('roles')->where('agency_id', $aid)->where('key', 'home_visitor')->exists()) continue;
            DB::table('roles')->insertOrIgnore([
                'agency_id' => $aid, 'key' => 'home_visitor', 'name' => 'Home Visitor',
                'description' => 'Visits families and centres; files reports and inspections.',
                'permissions' => json_encode(['home_visits.file', 'own_tasks.view', 'edocuments.sign']),
                'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Descriptive data only; the added keys are harmless to leave.
    }
};
