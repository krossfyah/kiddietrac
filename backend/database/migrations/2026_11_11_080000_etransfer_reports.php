<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* A FAMILY SAYS THEY SENT AN e-TRANSFER (2026-11-11, EtransferRequestController::report). The same table as the
   requests: kind 'request' (every row so far) | 'report'. A report carries the bank's reference number and waits as
   'reported' until a person approves it (paid) or rejects it (rejected, with the reason). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etransfer_requests', function (Blueprint $t) {
            if (! Schema::hasColumn('etransfer_requests', 'kind')) $t->string('kind', 10)->default('request')->after('id');
            if (! Schema::hasColumn('etransfer_requests', 'reference')) $t->string('reference', 64)->nullable()->after('contact');
            if (! Schema::hasColumn('etransfer_requests', 'reported_at')) $t->timestamp('reported_at')->nullable()->after('reference');
            if (! Schema::hasColumn('etransfer_requests', 'approved_amount')) $t->decimal('approved_amount', 10, 2)->nullable()->after('paid_by');
            if (! Schema::hasColumn('etransfer_requests', 'rejected_at')) $t->timestamp('rejected_at')->nullable()->after('approved_amount');
            if (! Schema::hasColumn('etransfer_requests', 'rejected_by')) $t->unsignedBigInteger('rejected_by')->nullable()->after('rejected_at');
            if (! Schema::hasColumn('etransfer_requests', 'reject_reason')) $t->string('reject_reason', 255)->nullable()->after('rejected_by');
        });
        Schema::table('etransfer_requests', function (Blueprint $t) {
            $t->index(['agency_id', 'reference'], 'etr_agency_reference');
        });
    }

    public function down(): void
    {
        Schema::table('etransfer_requests', function (Blueprint $t) {
            $t->dropIndex('etr_agency_reference');
            $t->dropColumn(['kind', 'reference', 'reported_at', 'approved_amount', 'rejected_at', 'rejected_by', 'reject_reason']);
        });
    }
};
