<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v22p8 Phase A — custom roles (config only; no permission-check changes yet).
 *
 * Creates a per-agency `roles` table with a permissions JSON document and
 * seeds 5 system roles per agency that mirror the existing role_assignments
 * enum. is_system rows can have their permissions edited but not their key
 * or name; non-system rows can be freely renamed/edited/deleted by agency_admin.
 *
 * No changes to role_assignments yet. Phase B will add a custom_role_id FK
 * and update EnsureRole middleware to consult the JSON.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->string('key', 60);            // slug, immutable for system rows
                $t->string('name', 120);          // human-readable
                $t->text('description')->nullable();
                $t->json('permissions');          // array of permission key strings
                $t->boolean('is_system')->default(false);
                $t->timestamps();
                $t->softDeletes();

                $t->unique(['agency_id', 'key']);
                $t->index('agency_id');
                $t->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            });
        }

        // Seed 5 system roles per agency with sensible baseline permissions.
        $baseline = [
            'agency_admin' => [
                'name' => 'Agency Admin',
                'description' => 'Full access to every agency feature.',
                'permissions' => array_keys(self::permissionCatalog()),
            ],
            'centre_director' => [
                'name' => 'Centre Director',
                'description' => 'Operational lead at one centre.',
                'permissions' => [
                    'centres.view', 'families.view', 'families.edit',
                    'children.view', 'children.edit', 'children.archive',
                    'staff.view', 'staff.invite', 'staff.schedule',
                    'rooms.view', 'rooms.edit',
                    'invoices.view', 'invoices.generate',
                    'reports.compliance', 'reports.attendance',
                    'incidents.view', 'incidents.review', 'incidents.notify_parent',
                    'medications.view', 'medications.authorize',
                    'observations.view', 'observations.create',
                    'lesson_plans.view', 'lesson_plans.create',
                    'kiosk.manage',
                    'edocuments.view', 'edocuments.send',
                    'announcements.create',
                ],
            ],
            'educator' => [
                'name' => 'Educator',
                'description' => 'Day-to-day care staff.',
                'permissions' => [
                    'children.view',
                    'incidents.view', 'incidents.create',
                    'observations.view', 'observations.create',
                    'lesson_plans.view',
                    'medications.view',
                    'attendance.record',
                ],
            ],
            'guardian' => [
                'name' => 'Parent / Guardian',
                'description' => 'Self-serve parent portal.',
                'permissions' => [
                    'own_children.view',
                    'own_invoices.view', 'own_invoices.pay',
                    'incidents.acknowledge',
                    'edocuments.sign',
                ],
            ],
            'auditor' => [
                'name' => 'Auditor',
                'description' => 'Read-only access for compliance review.',
                'permissions' => [
                    'centres.view', 'families.view', 'children.view',
                    'staff.view', 'rooms.view', 'invoices.view',
                    'reports.compliance', 'reports.attendance',
                    'audit.view',
                ],
            ],
        ];

        $agencies = DB::table('agencies')->whereNull('deleted_at')->pluck('id')->all();
        foreach ($agencies as $agencyId) {
            foreach ($baseline as $key => $config) {
                $exists = DB::table('roles')
                    ->where('agency_id', $agencyId)
                    ->where('key', $key)
                    ->exists();
                if ($exists) continue;
                DB::table('roles')->insert([
                    'agency_id' => $agencyId,
                    'key' => $key,
                    'name' => $config['name'],
                    'description' => $config['description'],
                    'permissions' => json_encode($config['permissions']),
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }

    /**
     * Master catalog of permission keys → human-readable description.
     * Kept in the migration so the seed can reference array_keys() and stay
     * in sync; RoleController also returns this list via /permission-keys.
     */
    public static function permissionCatalog(): array
    {
        return [
            // Centres
            'centres.view' => 'View centre list and details',
            'centres.edit' => 'Edit centre settings and branding',
            'centres.create' => 'Create new centres in the agency',
            'centres.delete' => 'Delete (archive) centres',

            // Families + children
            'families.view' => 'View all families at scoped centres',
            'families.edit' => 'Edit family records',
            'families.invite' => 'Send invitations to new families',
            'children.view' => 'View all child records',
            'children.edit' => 'Edit child records (name, medical, photo, etc.)',
            'children.archive' => 'Archive (soft-delete) child records',
            'own_children.view' => 'View own children only (parent scope)',

            // Staff
            'staff.view' => 'View staff list',
            'staff.invite' => 'Invite new staff',
            'staff.schedule' => 'Create + edit staff shifts and timesheets',
            'staff.certifications' => 'Manage staff certifications',

            // Rooms
            'rooms.view' => 'View room layout and rosters',
            'rooms.edit' => 'Create / edit / archive rooms',

            // Invoicing + billing
            'invoices.view' => 'View invoices',
            'invoices.generate' => 'Generate monthly invoice batches',
            'invoices.refund' => 'Issue refunds',
            'own_invoices.view' => 'View own invoices only (parent scope)',
            'own_invoices.pay' => 'Pay own invoices (parent scope)',

            // Reports
            'reports.compliance' => 'Run + export compliance reports',
            'reports.attendance' => 'Run + export attendance reports',

            // Incidents
            'incidents.view' => 'View incident reports',
            'incidents.create' => 'Create new incident reports',
            'incidents.review' => 'Review submitted incidents',
            'incidents.notify_parent' => 'Send incident reports to parents',
            'incidents.acknowledge' => 'Acknowledge incident reports (parent scope)',

            // Medical
            'medications.view' => 'View medication records',
            'medications.authorize' => 'Authorize new medications',

            // Observations + curriculum
            'observations.view' => 'View child observations',
            'observations.create' => 'Create observations',
            'lesson_plans.view' => 'View lesson plans',
            'lesson_plans.create' => 'Create + publish lesson plans',

            // Attendance + kiosk
            'attendance.record' => 'Record check-in / check-out events',
            'kiosk.manage' => 'Manage kiosk tokens + parent PINs',

            // Documents
            'edocuments.view' => 'View eDocuments',
            'edocuments.send' => 'Send eDocuments to families',
            'edocuments.sign' => 'Sign eDocuments (parent scope)',

            // Comms
            'announcements.create' => 'Create + send announcements',

            // Settings + admin
            'settings.edit' => 'Edit agency settings (branding, billing, etc.)',
            'roles.manage' => 'Create + edit custom roles',
            'mfa.enforce' => 'Require MFA for other staff users',
            'audit.view' => 'View the audit log',
        ];
    }
};
