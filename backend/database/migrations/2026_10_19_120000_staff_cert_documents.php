<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* STAFF CERTIFICATES WITH THE CERTIFICATE ITSELF (2026-10-19). Anthony: "add ability to add
   CPR certificates with issue and expiry dates - full system and add ability for educators
   to upload using their view". The table held dates only (document_url was never written:
   0 of 45 rows), had no owner agency and no record of who entered or checked a row.

   - agency_id: the agency the row was entered under, so a person who works for two agencies
     shows each one only its own paperwork. Backfilled where the person is staff at exactly
     one agency; the rest stay NULL and are read as legacy rows of whoever manages the person.
   - document_path/name: the uploaded file, on the PRIVATE disk (streamed after a check).
   - added_by, verified_by/at: an educator's own upload counts at once but shows as "not yet
     checked" until a director or admin has looked at the certificate. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_certifications', function (Blueprint $t) {
            if (! Schema::hasColumn('staff_certifications', 'agency_id')) $t->unsignedBigInteger('agency_id')->nullable()->after('user_id');
            if (! Schema::hasColumn('staff_certifications', 'title')) $t->string('title', 160)->nullable()->after('cert_type');
            if (! Schema::hasColumn('staff_certifications', 'document_path')) $t->string('document_path', 500)->nullable()->after('document_url');
            if (! Schema::hasColumn('staff_certifications', 'document_name')) $t->string('document_name', 190)->nullable()->after('document_path');
            if (! Schema::hasColumn('staff_certifications', 'added_by')) $t->unsignedBigInteger('added_by')->nullable();
            if (! Schema::hasColumn('staff_certifications', 'verified_by')) $t->unsignedBigInteger('verified_by')->nullable();
            if (! Schema::hasColumn('staff_certifications', 'verified_at')) $t->timestamp('verified_at')->nullable();
            if (! Schema::hasColumn('staff_certifications', 'updated_at')) $t->timestamp('updated_at')->nullable();
        });
        try {
            Schema::table('staff_certifications', fn (Blueprint $t) => $t->index(['agency_id', 'user_id'], 'idx_cert_agency_user'));
        } catch (\Throwable $e) {}

        // Backfill: staff at exactly one agency (agency-level role or a role at one of its centres).
        $rows = DB::table('staff_certifications')->whereNull('agency_id')->pluck('user_id')->unique();
        foreach ($rows as $uid) {
            $agencies = DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
                ->where('ra.user_id', $uid)->where('ra.active', true)
                ->whereIn('ra.role', ['educator', 'home_visitor', 'centre_director', 'agency_admin'])
                ->selectRaw('COALESCE(ra.agency_id, c.agency_id) as a')->pluck('a')->filter()->unique()->values();
            if ($agencies->count() === 1) {
                DB::table('staff_certifications')->where('user_id', $uid)->whereNull('agency_id')->update(['agency_id' => (int) $agencies[0]]);
            }
        }
    }

    public function down(): void
    {
    }
};
