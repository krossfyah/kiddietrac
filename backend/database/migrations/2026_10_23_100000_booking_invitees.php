<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Others on a website meeting's Outlook invite (JSON list of emails), 2026-10-23. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_bookings', function (Blueprint $t) {
            if (! Schema::hasColumn('marketing_bookings', 'invitees')) $t->text('invitees')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_bookings', function (Blueprint $t) {
            $t->dropColumn('invitees');
        });
    }
};
