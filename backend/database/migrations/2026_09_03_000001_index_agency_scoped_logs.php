<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Composite indexes for the two agency-scoped log tables (2026-09-03).
 *
 * Found during a full index sweep. Every other hot query in the portal already
 * resolves through an index in under 5 ms; these two did not, and they sit on the
 * two fastest-growing tables in the database:
 *
 *   audit_logs   14,109 rows, +4,174 in the last 7 days  (~217,000/year)
 *   email_logs    6,435 rows, +1,018 in the last 7 days  (~53,000/year)
 *
 * Both only had a single-column agency_id index, so the agency-scoped list queries
 * -- which filter by agency, then a date window, then sort by created_at DESC --
 * examined every row for the agency and finished with a filesort:
 *
 *   audit page, agency + date window   4,063 rows examined, filesort   13.4 ms
 *   email log, agency + status            index scan                   50.1 ms
 *
 * Fine today, not fine at a year's growth. The composites cover filter, range and
 * sort in one, and each makes the single-column agency_id index it replaces a
 * redundant prefix, so that one is dropped rather than left to cost write time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->index(['agency_id', 'created_at'], 'idx_audit_agency_date');
        });
        $this->dropIfExists('audit_logs', 'audit_logs_agency_id_index');

        Schema::table('email_logs', function (Blueprint $t) {
            $t->index(['agency_id', 'status', 'created_at'], 'idx_email_agency_status_date');
        });
        $this->dropIfExists('email_logs', 'email_logs_agency_id_index');
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->index('agency_id', 'audit_logs_agency_id_index');
        });
        $this->dropIfExists('audit_logs', 'idx_audit_agency_date');

        Schema::table('email_logs', function (Blueprint $t) {
            $t->index('agency_id', 'email_logs_agency_id_index');
        });
        $this->dropIfExists('email_logs', 'idx_email_agency_status_date');
    }

    /** Dropping an index that is not there must not fail the migration. */
    private function dropIfExists(string $table, string $index): void
    {
        $exists = DB::selectOne(
            'SELECT 1 x FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $index]
        );

        if ($exists) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
        }
    }
};
