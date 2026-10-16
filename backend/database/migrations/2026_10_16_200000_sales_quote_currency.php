<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proposal currency (2026-10-16, Anthony: "when creating proposal allow to choose currency type
 * and if annual or monthly"). billing_period already existed ('month' | 'year'); proposals made
 * before this were all Canadian dollars. Same day: taxes ([{label, rate}] on the total after
 * discount) and terms ("valid for 90 days" + editable wording; defaults in platform_settings).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_quotes', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_quotes', 'currency')) $t->string('currency', 3)->default('CAD')->after('total');
            if (! Schema::hasColumn('sales_quotes', 'taxes')) $t->json('taxes')->nullable()->after('discount');
            if (! Schema::hasColumn('sales_quotes', 'tax_total')) $t->decimal('tax_total', 12, 2)->default(0)->after('taxes');
            if (! Schema::hasColumn('sales_quotes', 'terms')) $t->text('terms')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotes', fn (Blueprint $t) => $t->dropColumn(['currency', 'taxes', 'tax_total', 'terms']));
    }
};
