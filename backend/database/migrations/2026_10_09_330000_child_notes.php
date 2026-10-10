<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Staff notes on a child's record, and every earlier version of an edited note (2026-10-09). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('child_notes')) {
            Schema::create('child_notes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('child_id');
                $t->unsignedBigInteger('author_id');
                $t->string('author_role', 32)->nullable();
                $t->text('body');
                $t->unsignedInteger('edit_count')->default(0);
                $t->dateTime('edited_at')->nullable();
                $t->unsignedBigInteger('edited_by')->nullable();
                $t->dateTime('deleted_at')->nullable();
                $t->timestamps();
                $t->index(['child_id', 'created_at']);
            });
        }
        if (! Schema::hasTable('child_note_revisions')) {
            Schema::create('child_note_revisions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('note_id');
                $t->text('body_before');
                $t->text('body_after');
                $t->unsignedBigInteger('edited_by');
                $t->dateTime('created_at')->nullable();
                $t->index('note_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('child_note_revisions');
        Schema::dropIfExists('child_notes');
    }
};
