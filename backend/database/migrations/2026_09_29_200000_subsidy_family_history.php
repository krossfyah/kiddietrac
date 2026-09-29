<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Subsidies tie back to the family, and keep their history (2026-09-29).
   Anthony: "subsidy should always tie back to the family records for compliance and
   historical reporting purposes".

   1. subsidies.family_id / centre_id: the family (and centre) the subsidy was granted
      under, fixed at creation. Reports and the family record read these, so a child who
      later moves family or is archived does not take the history with them. Plus who
      created, ended and removed it, and when.
   2. cwelcc_enrolments: CWELCC enrolment as dated periods with their own rate. It was a
      flag on families, overwritten in place -- un-enrolling a family erased it from every
      past CWELCC report, and changing a rate recomputed past months at the new rate.
      families.cwelcc_* stay as the "current" mirror for the screens that read them. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subsidies', function (Blueprint $t) {
            if (! Schema::hasColumn('subsidies', 'family_id')) { $t->unsignedBigInteger('family_id')->nullable()->after('child_id')->index(); }
            if (! Schema::hasColumn('subsidies', 'centre_id')) { $t->unsignedBigInteger('centre_id')->nullable()->after('family_id'); }
            if (! Schema::hasColumn('subsidies', 'created_by_id')) { $t->unsignedBigInteger('created_by_id')->nullable(); }
            if (! Schema::hasColumn('subsidies', 'updated_at')) { $t->timestamp('updated_at')->nullable(); }
            if (! Schema::hasColumn('subsidies', 'ended_by_id')) { $t->unsignedBigInteger('ended_by_id')->nullable(); }
            if (! Schema::hasColumn('subsidies', 'removed_at')) { $t->timestamp('removed_at')->nullable(); }
            if (! Schema::hasColumn('subsidies', 'removed_by_id')) { $t->unsignedBigInteger('removed_by_id')->nullable(); }
        });
        // Existing rows: the child's family today is the best evidence there is.
        DB::statement('UPDATE subsidies s JOIN children ch ON ch.id = s.child_id JOIN families f ON f.id = ch.family_id
            SET s.family_id = f.id, s.centre_id = f.centre_id WHERE s.family_id IS NULL');

        if (! Schema::hasTable('cwelcc_enrolments')) {
            Schema::create('cwelcc_enrolments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('family_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->date('enrolled_from');
                $t->date('enrolled_to')->nullable();
                $t->decimal('subsidy_rate', 5, 2)->nullable();
                $t->string('source', 20)->default('recorded');   // recorded | backfill
                $t->unsignedBigInteger('started_by_id')->nullable();
                $t->unsignedBigInteger('ended_by_id')->nullable();
                $t->timestamps();
                $t->index(['family_id', 'enrolled_from']);
            });
            DB::statement("ALTER TABLE cwelcc_enrolments CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        }
        // Families enrolled today get an open period from their recorded enrolment date.
        $fams = DB::table('families')->where('cwelcc_enrolled', 1)
            ->get(['id', 'centre_id', 'cwelcc_enrolled_at', 'cwelcc_subsidy_rate', 'created_at']);
        foreach ($fams as $f) {
            if (DB::table('cwelcc_enrolments')->where('family_id', $f->id)->exists()) { continue; }
            DB::table('cwelcc_enrolments')->insert([
                'family_id' => $f->id,
                'centre_id' => $f->centre_id,
                'enrolled_from' => substr((string) ($f->cwelcc_enrolled_at ?: $f->created_at), 0, 10),
                'enrolled_to' => null,
                'subsidy_rate' => $f->cwelcc_subsidy_rate,
                'source' => 'backfill',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cwelcc_enrolments');
        Schema::table('subsidies', function (Blueprint $t) {
            foreach (['removed_by_id', 'removed_at', 'ended_by_id', 'updated_at', 'created_by_id', 'centre_id', 'family_id'] as $c) {
                if (Schema::hasColumn('subsidies', $c)) { $t->dropColumn($c); }
            }
        });
    }
};
