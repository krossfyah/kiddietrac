<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* CACFP tiers (2026-11-28): an evidence note on the family's tier, and a child's own tier over the family's. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('families', function (Blueprint $t) {
            if (! Schema::hasColumn('families', 'cacfp_evidence_note')) $t->text('cacfp_evidence_note')->nullable()->after('cacfp_eligibility_date');
        });
        Schema::table('children', function (Blueprint $t) {
            if (! Schema::hasColumn('children', 'cacfp_tier')) $t->string('cacfp_tier', 10)->nullable();
            if (! Schema::hasColumn('children', 'cacfp_eligibility_date')) $t->date('cacfp_eligibility_date')->nullable();
            if (! Schema::hasColumn('children', 'cacfp_evidence_note')) $t->text('cacfp_evidence_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('families', fn (Blueprint $t) => $t->dropColumn('cacfp_evidence_note'));
        Schema::table('children', fn (Blueprint $t) => $t->dropColumn(['cacfp_tier', 'cacfp_eligibility_date', 'cacfp_evidence_note']));
    }
};
