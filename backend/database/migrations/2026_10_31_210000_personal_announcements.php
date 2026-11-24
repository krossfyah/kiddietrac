<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PERSONAL ANNOUNCEMENTS (2026-10-31, Anthony: "when there is a new invoice or payroll make this an announcement for
 * each user"). An announcement aimed at ONE person: scope_type 'user', scope_id = their user id. It shows only in that
 * person's own announcements (App\Support\PersonalAnnouncements, AnnouncementController::indexParent / indexProvider);
 * agency, centre and room lists never include it, because they ask for those scope types by name.
 *
 * ref_type/ref_id tie it to what it is about (invoice, external_invoice, payslip) - one per thing per person.
 * created_by_id becomes nullable: nobody "wrote" these.
 * announce.personal_since is set to now, so nothing issued before this goes out (no backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE announcements MODIFY scope_type ENUM('agency','centre','room','family','user') NOT NULL");
        DB::statement('ALTER TABLE announcements MODIFY created_by_id BIGINT UNSIGNED NULL');
        if (! Schema::hasColumn('announcements', 'ref_type')) {
            DB::statement('ALTER TABLE announcements
                ADD COLUMN agency_id BIGINT UNSIGNED NULL AFTER scope_id,
                ADD COLUMN ref_type VARCHAR(30) NULL,
                ADD COLUMN ref_id BIGINT UNSIGNED NULL,
                ADD COLUMN ref_hash CHAR(40) NULL,
                ADD COLUMN link VARCHAR(255) NULL,
                ADD COLUMN actions TEXT NULL,
                ADD UNIQUE KEY ann_personal_ref (scope_type, scope_id, ref_type, ref_id),
                ADD KEY ann_ref (ref_type, ref_id)');
        }
        if (! \App\Support\PlatformSettings::get('announce.personal_since')) {
            \App\Support\PlatformSettings::set('announce.personal_since', now()->utc()->format('Y-m-d H:i:s'));
        }
    }

    public function down(): void
    {
        DB::table('announcements')->where('scope_type', 'user')->delete();
        DB::statement('ALTER TABLE announcements DROP INDEX ann_personal_ref, DROP INDEX ann_ref,
            DROP COLUMN agency_id, DROP COLUMN ref_type, DROP COLUMN ref_id, DROP COLUMN ref_hash, DROP COLUMN link, DROP COLUMN actions');
        DB::statement("ALTER TABLE announcements MODIFY scope_type ENUM('agency','centre','room','family') NOT NULL");
    }
};
