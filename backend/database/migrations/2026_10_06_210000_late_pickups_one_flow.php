<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* ONE LATE-PICKUP FLOW (2026-10-06) — see App\Support\LatePickups.
   late_events becomes the record for every late pickup/arrival: it gains the pickup
   time as typed (wall clock), the close time, the suggested fee and links to what the
   approval created. late_pickup_charges gains who approved it and the event it came from.
   Every existing charge becomes an APPROVED event linked to it, so past fees appear in
   the one list — nothing is re-charged and no invoice is touched. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('late_events')) {
            Schema::create('late_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('child_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable()->index();
                $t->string('kind', 16);
                $t->date('occurred_on');
                $t->unsignedInteger('minutes')->default(0);
                $t->text('note')->nullable();
                $t->unsignedBigInteger('recorded_by_id')->nullable();
                $t->string('status', 16)->default('pending');
                $t->decimal('fee_amount', 8, 2)->nullable();
                $t->unsignedBigInteger('decided_by_id')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->text('decision_note')->nullable();
                $t->timestamps();
                $t->index(['centre_id', 'occurred_on']);
            });
        }
        Schema::table('late_events', function (Blueprint $t) {
            if (! Schema::hasColumn('late_events', 'pickup_at')) $t->dateTime('pickup_at')->nullable()->after('occurred_on');
            if (! Schema::hasColumn('late_events', 'close_time')) $t->time('close_time')->nullable()->after('pickup_at');
            if (! Schema::hasColumn('late_events', 'suggested_fee')) $t->decimal('suggested_fee', 8, 2)->nullable()->after('minutes');
            if (! Schema::hasColumn('late_events', 'charge_id')) $t->unsignedBigInteger('charge_id')->nullable();
            if (! Schema::hasColumn('late_events', 'invoice_line_id')) $t->unsignedBigInteger('invoice_line_id')->nullable();
        });
        Schema::table('late_pickup_charges', function (Blueprint $t) {
            if (! Schema::hasColumn('late_pickup_charges', 'approved_by_id')) $t->unsignedBigInteger('approved_by_id')->nullable();
            if (! Schema::hasColumn('late_pickup_charges', 'late_event_id')) $t->unsignedBigInteger('late_event_id')->nullable()->index();
        });

        foreach (DB::table('late_pickup_charges')->whereNull('late_event_id')->orderBy('id')->get() as $c) {
            $eid = DB::table('late_events')->insertGetId([
                'child_id' => $c->child_id,
                'centre_id' => $c->centre_id,
                'kind' => 'departure',
                'occurred_on' => substr((string) $c->pickup_at, 0, 10),
                'pickup_at' => $c->pickup_at,
                'close_time' => $c->close_time,
                'minutes' => (int) $c->minutes_late,
                'suggested_fee' => $c->fee_amount,
                'note' => $c->notes,
                'recorded_by_id' => $c->logged_by_id,
                // The old flow charged on logging: the person who logged it approved it.
                'status' => 'approved',
                'fee_amount' => $c->fee_amount,
                'decided_by_id' => $c->logged_by_id,
                'decided_at' => $c->created_at,
                'charge_id' => $c->id,
                'invoice_line_id' => $c->invoice_line_id,
                'created_at' => $c->created_at,
                'updated_at' => $c->created_at,
            ]);
            DB::table('late_pickup_charges')->where('id', $c->id)->update([
                'late_event_id' => $eid,
                'approved_by_id' => $c->approved_by_id ?? $c->logged_by_id,
            ]);
        }
    }

    public function down(): void
    {
        // Events created from charges are left in place; only the link columns go.
        Schema::table('late_pickup_charges', function (Blueprint $t) {
            foreach (['approved_by_id', 'late_event_id'] as $c) if (Schema::hasColumn('late_pickup_charges', $c)) $t->dropColumn($c);
        });
        Schema::table('late_events', function (Blueprint $t) {
            foreach (['pickup_at', 'close_time', 'suggested_fee', 'charge_id', 'invoice_line_id'] as $c) if (Schema::hasColumn('late_events', $c)) $t->dropColumn($c);
        });
    }
};
