<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHO RECORDED THE CONSENT (2026-09-18).
 *
 * A director may record a yes they were given in person, which carriers accept when it is
 * documented. "Somebody ticked a box" is not documentation, so the row carries the name of
 * the person who put it there. Nullable: consent given by the person themselves, through
 * the app or the emailed link, has no recorder and should not pretend to.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'sms_consent_recorded_by')) {
            return;
        }
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedBigInteger('sms_consent_recorded_by')->nullable()->after('sms_consent_source');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'sms_consent_recorded_by')) {
            return;
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('sms_consent_recorded_by');
        });
    }
};
