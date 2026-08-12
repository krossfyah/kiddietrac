<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * external_invoices — invoices produced in an external agency platform (first
 * consumer: iLearn) and pushed into KiddieTrac via the integration API so the
 * parent portal can display them. KiddieTrac does NOT generate or collect these;
 * they are a read-only mirror keyed on the source's own id (idempotent upsert).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('family_id')->index();
            $t->string('external_source', 40)->index();   // e.g. 'ilearn'
            $t->string('external_id', 191);                // e.g. 'invoice-123'
            $t->string('number', 120)->nullable();
            $t->string('status', 40)->default('open');     // open|paid|partial|void|overdue
            $t->date('issued_at')->nullable();
            $t->date('due_at')->nullable();
            $t->decimal('total', 12, 2)->default(0);
            $t->decimal('amount_paid', 12, 2)->default(0);
            $t->decimal('balance_due', 12, 2)->default(0);
            $t->string('currency', 8)->default('CAD');
            $t->text('description')->nullable();
            $t->json('items')->nullable();
            $t->string('source_label', 60)->nullable();    // display label, e.g. 'iLearn'
            $t->timestamp('external_updated_at')->nullable();
            $t->timestamps();

            // Idempotency: one row per (agency, source, source's invoice id).
            $t->unique(['agency_id', 'external_source', 'external_id'], 'ext_inv_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_invoices');
    }
};
