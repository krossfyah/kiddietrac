<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Files exchanged between educators and families — printables, homework, forms back
   (2026-10-09). One stored file can go to several families: one row per family, the same
   path. Private disk; served only through FamilyFilesController. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('family_files')) {
            Schema::create('family_files', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('family_id');
                $t->unsignedBigInteger('child_id')->nullable();
                $t->string('direction', 12);                 // to_family | from_family
                $t->unsignedBigInteger('uploaded_by');
                $t->string('batch', 40)->nullable();          // one send = one batch
                $t->string('title', 190);
                $t->text('note')->nullable();
                $t->string('path', 255);                     // local disk
                $t->string('original_name', 190);
                $t->string('mime', 120)->nullable();
                $t->unsignedBigInteger('size')->default(0);
                $t->dateTime('first_viewed_at')->nullable();
                $t->unsignedBigInteger('first_viewed_by')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('deleted_at')->nullable();
                $t->index(['family_id', 'deleted_at', 'id']);
                $t->index(['agency_id', 'created_at']);
                $t->index(['uploaded_by', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('family_files');
    }
};
