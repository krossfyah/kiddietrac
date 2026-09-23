<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-agency running counter for invoice numbers (2026-09-17).
 *
 * WHY NOT THE SETTINGS JSON. Every other agency preference lives in agencies.settings,
 * and a counter cannot: read-modify-write on a JSON blob has no way to stop two invoices
 * raised in the same second from reading the same value and both writing back the same
 * number. One narrow row taken with lockForUpdate does, and the unique index means that
 * even if some future caller forgets the lock the database refuses the duplicate row.
 *
 * scope_key exists so a later "reset numbering every year" option can key a second
 * counter ('agency:2:2027') without another migration. Today it is always 'agency:<id>'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_number_sequences')) {
            return;
        }
        Schema::create('invoice_number_sequences', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->nullable()->index();
            $t->string('scope_key', 120);
            $t->unsignedBigInteger('next_value')->default(1);
            $t->timestamps();
            $t->unique(['agency_id', 'scope_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_number_sequences');
    }
};
