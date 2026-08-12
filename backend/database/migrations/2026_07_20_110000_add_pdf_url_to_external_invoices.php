<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_invoices', function (Blueprint $t) {
            if (! Schema::hasColumn('external_invoices', 'pdf_url')) {
                $t->string('pdf_url', 500)->nullable()->after('source_label');
            }
        });
    }

    public function down(): void
    {
        Schema::table('external_invoices', function (Blueprint $t) {
            $t->dropColumn('pdf_url');
        });
    }
};
