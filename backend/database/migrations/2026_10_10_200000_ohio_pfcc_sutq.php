<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* OHIO PUBLICLY FUNDED CHILD CARE + STEP UP TO QUALITY (2026-10-10). Additive only: nothing
   here touches subsidies, invoices or any Ontario table. Used only by agencies whose
   jurisdiction is US-OH (or that switch the features on). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pfcc_authorizations')) {
            Schema::create('pfcc_authorizations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('child_id');
                $t->unsignedBigInteger('family_id');
                $t->string('case_number', 64)->nullable();
                $t->date('starts_on');
                $t->date('ends_on')->nullable();
                $t->string('care_unit', 16);                 // hourly | part_time | full_time
                $t->decimal('units_per_week', 8, 2)->default(1);
                $t->decimal('rate_per_unit', 10, 2)->default(0);
                $t->decimal('weekly_copay', 10, 2)->default(0);
                $t->string('agreement_path', 255)->nullable();   // the signed copay agreement (private disk)
                $t->string('agreement_name', 190)->nullable();
                $t->date('agreement_signed_on')->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->dateTime('ended_at')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'child_id']);
            });
        }
        if (! Schema::hasTable('pfcc_copays')) {
            Schema::create('pfcc_copays', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('authorization_id');
                $t->date('week_start');                       // Sunday
                $t->decimal('amount_due', 10, 2)->default(0);
                $t->decimal('amount_paid', 10, 2)->default(0);
                $t->date('paid_on')->nullable();
                $t->string('status', 12)->default('due');     // due | partial | paid | waived
                $t->string('note', 255)->nullable();
                $t->unsignedBigInteger('recorded_by')->nullable();
                $t->timestamps();
                $t->unique(['authorization_id', 'week_start'], 'pfcc_copay_week');
            });
        }
        if (! Schema::hasTable('pfcc_alerts')) {
            Schema::create('pfcc_alerts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('ref_id');             // authorization or child
                $t->string('kind', 24);                       // copay_unpaid | absent_near_cap | absent_over_cap
                $t->string('period', 16);                     // the week or half-year it is about
                $t->dateTime('created_at')->nullable();
                $t->unique(['kind', 'ref_id', 'period'], 'pfcc_alert_once');
            });
        }
        if (! Schema::hasTable('pfcc_invoice_splits')) {
            // A draft invoice's state/family split, applied on request (never automatically).
            Schema::create('pfcc_invoice_splits', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('invoice_id')->unique();
                $t->decimal('state_amount', 10, 2);
                $t->decimal('copay_amount', 10, 2);
                $t->text('detail');                           // per child: weeks, rates, authorization
                $t->unsignedBigInteger('applied_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('sutq_child_events')) {
            Schema::create('sutq_child_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('child_id');
                $t->string('kind', 16);                       // screening | family_needs | assessment
                $t->date('completed_on');
                $t->date('shared_with_family_on')->nullable();
                $t->string('tool', 120)->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('recorded_by')->nullable();
                $t->timestamps();
                $t->index(['child_id', 'kind']);
            });
        }
        if (! Schema::hasTable('sutq_referrals')) {
            Schema::create('sutq_referrals', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('child_id');
                $t->date('referred_on');
                $t->string('referred_to', 190);
                $t->string('reason', 255)->nullable();
                $t->date('follow_up_due')->nullable();
                $t->date('followed_up_on')->nullable();
                $t->text('outcome')->nullable();
                $t->unsignedBigInteger('recorded_by')->nullable();
                $t->timestamps();
                $t->index(['centre_id', 'referred_on']);
            });
        }
        if (! Schema::hasTable('sutq_cip_items')) {
            Schema::create('sutq_cip_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id');
                $t->string('goal', 255);
                $t->text('action')->nullable();
                $t->string('owner', 120)->nullable();
                $t->date('due_on')->nullable();
                $t->string('status', 16)->default('planned');  // planned | in_progress | done
                $t->text('notes')->nullable();
                $t->timestamps();
                $t->index('centre_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['pfcc_invoice_splits', 'sutq_cip_items', 'sutq_referrals', 'sutq_child_events', 'pfcc_alerts', 'pfcc_copays', 'pfcc_authorizations'] as $t) Schema::dropIfExists($t);
    }
};
