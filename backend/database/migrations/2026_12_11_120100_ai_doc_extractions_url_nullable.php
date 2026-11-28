<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* AI doc extract no longer stores a public link (2026-11-28): the file is on the private disk (document_path). */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE ai_doc_extractions MODIFY document_url VARCHAR(500) NULL');
    }

    public function down(): void
    {
        // Left nullable: rows written since hold no link.
    }
};
