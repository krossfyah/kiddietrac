<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* documents.file_type was varchar(20): "application/octet-stream" (24, what browsers send for a HEIC photo) and
   the Office types (up to 73) could not be stored, so the upload failed with a raw 500 (ticket #255, 2026-11-13).
   Widening only: no existing value changes. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE documents MODIFY file_type VARCHAR(150) NULL");
    }

    public function down(): void
    {
        // Not narrowed back: that would truncate stored types.
    }
};
