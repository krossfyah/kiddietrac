<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A family asking the centre to SEND THEM an Interac e-Transfer request ("Request money"), so they pay from the
 * link their bank shows them (2026-10-30). App\Support\EtransferRequests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('etransfer_requests')) return;
        Schema::create('etransfer_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('centre_id')->nullable()->index();
            $t->unsignedBigInteger('family_id')->index();
            $t->unsignedBigInteger('guardian_id')->nullable();
            $t->unsignedBigInteger('user_id')->index();            // the parent who asked
            $t->json('items');                                    // [{kind: inv|ext, id, number, amount}]
            $t->string('numbers', 255)->nullable();               // "INV-1001, INV-1002" - the e-Transfer message
            $t->decimal('amount', 10, 2);
            $t->string('contact_type', 10);                       // email | phone
            $t->string('contact', 190);
            $t->string('status', 12)->default('requested');        // requested | sent | paid | cancelled
            $t->timestamp('sent_at')->nullable();
            $t->unsignedBigInteger('sent_by')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->unsignedBigInteger('paid_by')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->unsignedBigInteger('cancelled_by')->nullable();
            $t->timestamps();
            $t->index(['agency_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etransfer_requests');
    }
};
