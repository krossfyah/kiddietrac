<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* AI doc extract keeps the uploaded file on the PRIVATE disk (2026-11-28); it was a public /storage/ link. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_doc_extractions', function (Blueprint $t) {
            if (! Schema::hasColumn('ai_doc_extractions', 'document_path')) $t->string('document_path', 255)->nullable()->after('document_url');
            if (! Schema::hasColumn('ai_doc_extractions', 'document_name')) $t->string('document_name', 190)->nullable()->after('document_path');
        });
    }

    public function down(): void
    {
        Schema::table('ai_doc_extractions', function (Blueprint $t) {
            $t->dropColumn(['document_path', 'document_name']);
        });
    }
};
