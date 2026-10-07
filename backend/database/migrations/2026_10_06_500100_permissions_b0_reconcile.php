<?php

use App\Support\PermissionMap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* PERMISSIONS STEP 1 — reconcile after the first shadow probe (2026-10-06). The probe of
   Test Agency 6 showed agency admins really use My tasks (own_tasks.view), which the first
   pass had left off. ADDS any default key a built-in row is missing; removes nothing. */
return new class extends Migration {
    public function up(): void
    {
        foreach (DB::table('roles')->where('is_system', true)->whereNull('deleted_at')->get(['id', 'agency_id', 'key', 'permissions']) as $r) {
            $defaults = PermissionMap::DEFAULTS[$r->key] ?? null;
            if ($defaults === null) continue;
            $have = json_decode((string) $r->permissions, true) ?: [];
            $add = array_values(array_diff($defaults, $have));
            if (! $add) continue;
            $new = array_values(array_unique(array_merge($have, $add)));
            sort($new);
            DB::table('roles')->where('id', $r->id)->update(['permissions' => json_encode($new), 'updated_at' => now()]);
            DB::table('audit_logs')->insert([
                'user_id' => null, 'agency_id' => $r->agency_id, 'action' => 'roles.matrix_aligned', 'entity_type' => 'role', 'entity_id' => $r->id,
                'payload' => json_encode(['role' => $r->key, 'added' => $add, 'removed' => [], 'source' => 'permissions step 1: shadow probe reconcile']),
                'created_at' => now(),
            ]);
        }
        \App\Support\Permissions::bump();
    }

    public function down(): void {}
};
