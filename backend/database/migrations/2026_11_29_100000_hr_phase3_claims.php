<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac HR Phase 3, item 9 (2026-11-26): staff expense and mileage claims (App\Support\HrClaims).
 * An approved claim rides the EXISTING payroll path: ManualPayrollController offers it as a line (claim_id) on the
 * person's next manual payslip and marks it paid there, like educator reward bonuses. Receipts are private files
 * on the local disk under hr-claims/<agency>/<claim>/.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('hr_claims')) {
            Schema::create('hr_claims', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->index();              // the claimant (staff)
                $t->string('kind', 16)->default('expense');               // expense | mileage
                $t->date('claim_date');                                   // the day the money was spent / the trip (agency date)
                $t->string('category', 60)->nullable();
                $t->string('description', 500)->nullable();
                $t->decimal('amount', 10, 2)->default(0);                 // before tax (expense) / km x rate (mileage)
                $t->decimal('tax', 10, 2)->default(0);
                $t->decimal('total', 10, 2)->default(0);                  // what is reimbursed
                $t->decimal('km', 8, 1)->nullable();
                $t->decimal('km_rate', 6, 3)->nullable();                 // the agency's rate when submitted (kept)
                $t->string('from_place', 200)->nullable();
                $t->string('to_place', 200)->nullable();
                $t->string('receipt_path', 255)->nullable();              // local disk, never public
                $t->string('receipt_name', 200)->nullable();
                $t->string('receipt_mime', 80)->nullable();
                $t->string('status', 16)->default('submitted');           // submitted | approved | declined | paid | withdrawn
                $t->unsignedBigInteger('decided_by_id')->nullable();
                $t->dateTime('decided_at')->nullable();
                $t->text('decision_comment')->nullable();
                $t->unsignedBigInteger('payroll_document_id')->nullable()->index();
                $t->dateTime('paid_at')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_claims');
    }
};
