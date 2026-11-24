<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website meetings are REQUESTS that Sales accepts, modifies or declines (2026-10-23,
 * Anthony: "i need the bookings to come to sales to accept, modify or decline before
 * sending out the confirmation email on a booked meeting with a teams link").
 *
 * status: requested -> booked (confirmed) | declined; a confirmed meeting can be
 * cancelled. Rows already 'booked' were confirmed under the old flow and stay so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_bookings', function (Blueprint $t) {
            if (! Schema::hasColumn('marketing_bookings', 'teams_url')) $t->string('teams_url', 1000)->nullable();
            if (! Schema::hasColumn('marketing_bookings', 'teams_event_id')) $t->string('teams_event_id', 300)->nullable();
            if (! Schema::hasColumn('marketing_bookings', 'host_user_id')) $t->unsignedBigInteger('host_user_id')->nullable();
            if (! Schema::hasColumn('marketing_bookings', 'decided_by')) $t->unsignedBigInteger('decided_by')->nullable();
            if (! Schema::hasColumn('marketing_bookings', 'decided_at')) $t->timestamp('decided_at')->nullable();
            if (! Schema::hasColumn('marketing_bookings', 'decline_reason')) $t->string('decline_reason', 500)->nullable();
            if (! Schema::hasColumn('marketing_bookings', 'sales_note')) $t->text('sales_note')->nullable();
            if (! Schema::hasColumn('marketing_bookings', 'requested_starts_at')) $t->dateTime('requested_starts_at')->nullable();
            if (! Schema::hasColumn('marketing_bookings', 'sequence')) $t->unsignedSmallInteger('sequence')->default(0);
            if (! Schema::hasColumn('marketing_bookings', 'confirm_mail')) $t->string('confirm_mail', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_bookings', function (Blueprint $t) {
            $t->dropColumn(['teams_url', 'teams_event_id', 'host_user_id', 'decided_by', 'decided_at', 'decline_reason', 'sales_note', 'requested_starts_at', 'sequence', 'confirm_mail']);
        });
    }
};
