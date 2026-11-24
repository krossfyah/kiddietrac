<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac's OWN expenses (Sales → Expenses, 2026-10-24): travel, third-party services, monthly
 * running costs, food… Sales reps log their own; superadmins see, approve and report on all.
 * Not agency data — nothing here belongs to a customer.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('platform_expenses')) {
            Schema::create('platform_expenses', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');                 // who spent it / submitted it
                $t->string('category', 40);
                $t->string('vendor', 160)->nullable();
                $t->string('description', 500)->nullable();
                $t->date('expense_date');
                $t->unsignedInteger('amount_cents');              // before tax
                $t->unsignedInteger('tax_cents')->default(0);
                $t->string('currency', 3)->default('CAD');
                $t->string('payment_method', 20)->default('company_card'); // company_card | personal | bank_transfer | other
                $t->boolean('reimbursable')->default(false);       // paid personally → owed back
                $t->string('status', 16)->default('submitted');    // submitted | approved | rejected | reimbursed
                $t->unsignedBigInteger('lead_id')->nullable();     // travel/meal for a prospect
                $t->unsignedBigInteger('recurring_id')->nullable();
                $t->string('receipt_path', 255)->nullable();
                $t->string('receipt_name', 200)->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->string('reject_reason', 500)->nullable();
                $t->unsignedBigInteger('reimbursed_by')->nullable();
                $t->timestamp('reimbursed_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['expense_date']);
                $t->index(['user_id', 'status']);
                $t->index(['category']);
            });
        }
        if (! Schema::hasTable('platform_expense_recurring')) {
            Schema::create('platform_expense_recurring', function (Blueprint $t) {
                $t->id();
                $t->string('category', 40);
                $t->string('vendor', 160)->nullable();
                $t->string('description', 500)->nullable();
                $t->unsignedInteger('amount_cents');
                $t->unsignedInteger('tax_cents')->default(0);
                $t->string('currency', 3)->default('CAD');
                $t->string('payment_method', 20)->default('company_card');
                $t->string('interval', 12)->default('monthly');    // monthly | quarterly | annual
                $t->date('next_date');
                $t->boolean('active')->default(true);
                $t->unsignedBigInteger('created_by');
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_expense_recurring');
        Schema::dropIfExists('platform_expenses');
    }
};
