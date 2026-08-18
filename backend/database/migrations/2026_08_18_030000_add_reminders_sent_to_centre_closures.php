<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which reminders have already gone out for a closure.
 *
 * Without this the nightly pass would re-send every time it ran, and a closure entered
 * well in advance would mail every family once a day until it arrived. Stored as a list of
 * the lead times already sent (e.g. ["7","1"]) rather than a single flag, because the two
 * reminders are separate events and one failing must not suppress the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('centre_closures', function (Blueprint $table) {
            $table->string('reminders_sent', 60)->nullable()->after('affects_billing');
        });
    }

    public function down(): void
    {
        Schema::table('centre_closures', function (Blueprint $table) {
            $table->dropColumn('reminders_sent');
        });
    }
};
