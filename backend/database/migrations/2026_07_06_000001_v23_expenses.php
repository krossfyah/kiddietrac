<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v23 — Expense management (accounts payable): suppliers, purchase orders,
 * and supplier bills (expense invoices) with payments. Kept SEPARATE from the
 * parent-billing `invoices`/`payments` tables (which are the income side).
 *
 * Scoping: every record carries agency_id (required) + centre_id (nullable =
 * agency-wide), matching the tenant model used across the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->string('name', 200);
                $t->string('contact_name', 150)->nullable();
                $t->string('email', 190)->nullable();
                $t->string('phone', 60)->nullable();
                $t->text('address')->nullable();
                $t->string('tax_number', 60)->nullable();
                $t->string('default_category', 80)->nullable();
                $t->text('notes')->nullable();
                $t->boolean('is_active')->default(true);
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index('agency_id');
                $t->index('centre_id');
            });
        }

        if (! Schema::hasTable('purchase_orders')) {
            Schema::create('purchase_orders', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->unsignedBigInteger('supplier_id');
                $t->string('po_number', 40);
                $t->enum('status', ['draft', 'ordered', 'received', 'cancelled'])->default('draft');
                $t->date('order_date')->nullable();
                $t->date('expected_date')->nullable();
                $t->decimal('subtotal', 12, 2)->default(0);
                $t->decimal('tax', 12, 2)->default(0);
                $t->decimal('total', 12, 2)->default(0);
                $t->string('category', 80)->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index('agency_id');
                $t->index('centre_id');
                $t->index('supplier_id');
                $t->index('status');
            });
        }

        if (! Schema::hasTable('purchase_order_lines')) {
            Schema::create('purchase_order_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('purchase_order_id');
                $t->string('description', 200);
                $t->decimal('quantity', 10, 2)->default(1);
                $t->decimal('unit_price', 12, 2)->default(0);
                $t->decimal('amount', 12, 2)->default(0);
                $t->timestamp('created_at')->useCurrent();
                $t->index('purchase_order_id');
            });
        }

        if (! Schema::hasTable('expense_invoices')) {
            Schema::create('expense_invoices', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->unsignedBigInteger('supplier_id');
                $t->unsignedBigInteger('purchase_order_id')->nullable();
                $t->string('reference', 40);                 // internal EXP-000001
                $t->string('invoice_number', 120)->nullable(); // supplier's own number
                $t->enum('status', ['draft', 'awaiting_approval', 'approved', 'partial', 'paid', 'overdue', 'void'])->default('draft');
                $t->string('category', 80)->nullable();
                $t->date('issue_date')->nullable();
                $t->date('due_date')->nullable();
                $t->decimal('subtotal', 12, 2)->default(0);
                $t->decimal('tax', 12, 2)->default(0);
                $t->decimal('total', 12, 2)->default(0);
                $t->decimal('amount_paid', 12, 2)->default(0);
                $t->unsignedBigInteger('approved_by_id')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->text('notes')->nullable();
                $t->string('file_url', 500)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index('agency_id');
                $t->index('centre_id');
                $t->index('supplier_id');
                $t->index('status');
                $t->index('due_date');
            });
        }

        if (! Schema::hasTable('expense_invoice_lines')) {
            Schema::create('expense_invoice_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('expense_invoice_id');
                $t->string('description', 200);
                $t->decimal('quantity', 10, 2)->default(1);
                $t->decimal('unit_price', 12, 2)->default(0);
                $t->decimal('amount', 12, 2)->default(0);
                $t->string('category', 80)->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index('expense_invoice_id');
            });
        }

        if (! Schema::hasTable('expense_payments')) {
            Schema::create('expense_payments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('expense_invoice_id');
                $t->unsignedBigInteger('agency_id');
                $t->decimal('amount', 12, 2);
                $t->enum('method', ['cash', 'cheque', 'e_transfer', 'bank_transfer', 'credit_card', 'other'])->default('bank_transfer');
                $t->timestamp('paid_at')->useCurrent();
                $t->string('reference', 120)->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('recorded_by_id')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index('expense_invoice_id');
                $t->index('agency_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_payments');
        Schema::dropIfExists('expense_invoice_lines');
        Schema::dropIfExists('expense_invoices');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
