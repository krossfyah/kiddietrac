<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The letter sent with a thank-you award (App\\Support\\ThankYouAward::note, edited by the sender), 2026-10-31. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('educator_rewards', 'letter')) {
            Schema::table('educator_rewards', function (Blueprint $t) { $t->text('letter')->nullable()->after('note'); });
        }
    }

    public function down(): void
    {
        Schema::table('educator_rewards', function (Blueprint $t) { $t->dropColumn('letter'); });
    }
};
