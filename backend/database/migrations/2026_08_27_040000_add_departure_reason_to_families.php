<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a family left, alongside who closed them and when.
 *
 * Anthony, 2026-08-27: the archived record should carry who de-enrolled the family, the
 * date and time, and the reason — and the reason should be asked for at the moment of
 * de-enrolling rather than reconstructed later from memory.
 *
 * `departure_by_id`, `departure_date` and `departure_applied_at` already exist. Two
 * columns are added rather than one: the CODE is what reporting can count ("how many
 * families left for school this year"), and the free TEXT is what a human actually needs
 * to read. A picked reason keeps its own words in the text column too, so the notice
 * emails never have to translate a code back into a sentence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $t) {
            if (! Schema::hasColumn('families', 'departure_reason_code')) {
                $t->string('departure_reason_code', 40)->nullable()->after('departure_by_id');
            }
            if (! Schema::hasColumn('families', 'departure_reason')) {
                $t->string('departure_reason', 300)->nullable()->after('departure_reason_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $t) {
            foreach (['departure_reason', 'departure_reason_code'] as $c) {
                if (Schema::hasColumn('families', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
