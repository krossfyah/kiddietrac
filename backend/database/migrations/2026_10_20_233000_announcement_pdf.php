<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* A PDF attached to an announcement (2026-10-20). Stored privately; served only through
   a signed link (AnnouncementController::pdf). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $t) {
            if (! Schema::hasColumn('announcements', 'pdf_path')) $t->string('pdf_path', 255)->nullable()->after('image_path');
            if (! Schema::hasColumn('announcements', 'pdf_name')) $t->string('pdf_name', 200)->nullable()->after('pdf_path');
            if (! Schema::hasColumn('announcements', 'pdf_size')) $t->unsignedInteger('pdf_size')->nullable()->after('pdf_name');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $t) {
            $t->dropColumn(['pdf_path', 'pdf_name', 'pdf_size']);
        });
    }
};
