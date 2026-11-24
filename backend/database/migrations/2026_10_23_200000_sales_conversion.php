<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Proposal approval + prospect → customer (2026-10-23, App\Http\Controllers\Api\SalesConversionController). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_quotes', function (Blueprint $t) {
            foreach ([
                'approval_status' => fn () => $t->string('approval_status', 12)->nullable(),   // approved | rejected
                'approved_at' => fn () => $t->timestamp('approved_at')->nullable(),
                'approved_by' => fn () => $t->unsignedBigInteger('approved_by')->nullable(),
                'rejected_at' => fn () => $t->timestamp('rejected_at')->nullable(),
                'rejected_by' => fn () => $t->unsignedBigInteger('rejected_by')->nullable(),
                'reject_reason' => fn () => $t->string('reject_reason', 500)->nullable(),
                'contract_path' => fn () => $t->string('contract_path', 255)->nullable(),
                'contract_name' => fn () => $t->string('contract_name', 200)->nullable(),
                'contract_size' => fn () => $t->unsignedInteger('contract_size')->nullable(),
                'contract_uploaded_at' => fn () => $t->timestamp('contract_uploaded_at')->nullable(),
                'contract_uploaded_by' => fn () => $t->unsignedBigInteger('contract_uploaded_by')->nullable(),
                'converted_agency_id' => fn () => $t->unsignedBigInteger('converted_agency_id')->nullable()->index(),
                'invite_sent_at' => fn () => $t->timestamp('invite_sent_at')->nullable(),
            ] as $col => $add) {
                if (! Schema::hasColumn('sales_quotes', $col)) $add();
            }
        });
        Schema::table('sales_leads', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_leads', 'converted_agency_id')) $t->unsignedBigInteger('converted_agency_id')->nullable()->index();
            if (! Schema::hasColumn('sales_leads', 'converted_at')) $t->timestamp('converted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotes', fn (Blueprint $t) => $t->dropColumn(['approval_status', 'approved_at', 'approved_by', 'rejected_at', 'rejected_by', 'reject_reason',
            'contract_path', 'contract_name', 'contract_size', 'contract_uploaded_at', 'contract_uploaded_by', 'converted_agency_id', 'invite_sent_at']));
        Schema::table('sales_leads', fn (Blueprint $t) => $t->dropColumn(['converted_agency_id', 'converted_at']));
    }
};
