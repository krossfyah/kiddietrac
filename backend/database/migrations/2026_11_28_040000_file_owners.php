<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which agency a stored file belongs to (2026-11-28, App\Support\FileRefs). Written when a write request creates a
 * protected file (source "upload", by SignProtectedMedia) and rebuilt nightly from the rows that reference each file
 * (source "row", by kt:leak-integrity). RefuseForeignFileRefs reads it to refuse a save that names another agency's file.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('file_owners')) {
            return;
        }
        Schema::create('file_owners', function (Blueprint $t) {
            $t->id();
            $t->char('rel_hash', 40);
            $t->string('rel', 500);
            $t->unsignedBigInteger('agency_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('source', 10);
            $t->timestamp('created_at')->nullable();
            $t->unique(['rel_hash', 'agency_id', 'source']);
            $t->index('rel_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_owners');
    }
};
