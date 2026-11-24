<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Payslip email notices (App\Support\PayslipNotices): one row per email, unique per document + key. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payslip_notices')) {
            Schema::create('payslip_notices', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('payroll_document_id');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('agency_id');
                $t->string('kind', 16);          // new | updated
                $t->string('dedupe_key', 40);    // new | upd:<net>
                $t->decimal('net', 12, 2)->nullable();
                $t->string('to_email', 190)->nullable();
                $t->string('status', 16);        // sent | held | skipped
                $t->string('reason', 190)->nullable();
                $t->timestamp('created_at')->nullable();
                $t->unique(['payroll_document_id', 'dedupe_key']);
                $t->index(['agency_id', 'created_at']);
            });
        }
        // No backfill: only payslips created from now on are emailed automatically.
        if (! \App\Support\PlatformSettings::get('payroll.payslip_email_since')) {
            \App\Support\PlatformSettings::set('payroll.payslip_email_since', now()->format('Y-m-d H:i:s'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_notices');
    }
};
