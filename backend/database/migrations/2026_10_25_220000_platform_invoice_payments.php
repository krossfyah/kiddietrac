<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac accounts (2026-10-25): a ledger of payments against platform invoices (full, part,
 * early; reported by an agency admin or recorded by KiddieTrac; each with a receipt number), and a
 * log of the due / overdue emails so each one goes once. App\Support\PlatformAccounts.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('platform_invoice_payments')) {
            Schema::create('platform_invoice_payments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('invoice_id');
                $t->unsignedBigInteger('agency_id')->nullable();
                $t->unsignedInteger('amount_cents');
                $t->string('currency', 3)->default('CAD');
                $t->string('method', 16)->default('other');   // card | eft | etransfer | pad | cheque | credit | other
                $t->string('reference', 120)->nullable();
                $t->date('paid_on');
                $t->string('status', 12)->default('confirmed'); // pending | confirmed | rejected
                $t->unsignedBigInteger('reported_by')->nullable();
                $t->unsignedBigInteger('recorded_by')->nullable();
                $t->string('receipt_number', 32)->nullable()->unique();
                $t->string('note', 500)->nullable();
                $t->timestamp('confirmed_at')->nullable();
                $t->timestamps();
                $t->index(['invoice_id', 'status']);
                $t->index('agency_id');
            });
            // Payments recorded before the ledger existed become one confirmed row each.
            foreach (DB::table('platform_invoices')->where('amount_paid_cents', '>', 0)->get() as $i) {
                $pid = DB::table('platform_invoice_payments')->insertGetId(['invoice_id' => $i->id, 'agency_id' => $i->agency_id, 'amount_cents' => $i->amount_paid_cents,
                    'currency' => $i->currency ?: 'CAD', 'method' => 'other', 'reference' => $i->payment_reference, 'paid_on' => substr((string) ($i->paid_at ?: $i->updated_at), 0, 10),
                    'status' => 'confirmed', 'note' => 'Recorded before the payments ledger', 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('platform_invoice_payments')->where('id', $pid)->update(['receipt_number' => 'R-' . str_replace('-', '', substr((string) ($i->paid_at ?: $i->updated_at), 0, 10)) . '-' . str_pad((string) $pid, 4, '0', STR_PAD_LEFT)]);
            }
        }
        if (! Schema::hasTable('platform_invoice_notices')) {
            Schema::create('platform_invoice_notices', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('invoice_id');
                $t->string('notice_key', 24);   // before:7 | due | overdue:14
                $t->string('kind', 12);
                $t->unsignedSmallInteger('recipients')->default(0);
                $t->timestamp('sent_at')->nullable();
                $t->unique(['invoice_id', 'notice_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoice_notices');
        Schema::dropIfExists('platform_invoice_payments');
    }
};
