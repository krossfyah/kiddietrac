<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When is this family actually leaving?
 *
 * De-enrolling was instantaneous: children were withdrawn "as of today", guardian logins
 * closed the same second, and the goodbye email went out. But a family gives notice — they
 * tell you in the middle of the month that their last day is the 30th — and there was
 * nowhere to say so. The choice was to close them early and take away the portal they
 * still need, or to remember to come back and do it on the day.
 *
 * Staff and provider off-boarding already take a `last_day`; this gives families the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $t) {
            if (! Schema::hasColumn('families', 'departure_date')) {
                // The agreed last day. Set for a departure that has not happened yet;
                // also stamped when one is applied, so the record says when they left.
                $t->date('departure_date')->nullable()->after('suspended_at')->index();
            }
            if (! Schema::hasColumn('families', 'departure_by_id')) {
                $t->unsignedBigInteger('departure_by_id')->nullable()->after('departure_date');
            }
            if (! Schema::hasColumn('families', 'departure_applied_at')) {
                // Null while scheduled. Set the moment the de-enrolment actually runs,
                // which is what stops the nightly job doing it twice.
                $t->timestamp('departure_applied_at')->nullable()->after('departure_by_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $t) {
            foreach (['departure_date', 'departure_by_id', 'departure_applied_at'] as $c) {
                if (Schema::hasColumn('families', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
