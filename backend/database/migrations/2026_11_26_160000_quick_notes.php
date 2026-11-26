<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* QUICK NOTES (2026-11-26, Anthony: "add a notepad widget option and a shortcut at the top bar for quick notes that
   are logged for each user or the agency"). App\Support\QuickNotes.
   - visibility 'private' = only the owner, ever (super admins included); 'agency' = the agency's staff, narrowed to
     one centre when centre_id is set.
   - agency_id NULL = a personal note made with no agency picked (super admin / sales).
   - remind_on is an agency-local DATE (the right panel and the notepad show it on that day).
   - quick_note_revisions keeps who changed what (shared notes show it as History). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quick_notes')) {
            Schema::create('quick_notes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->nullable()->index();
                $t->unsignedBigInteger('centre_id')->nullable()->index();
                $t->unsignedBigInteger('owner_user_id')->index();
                $t->string('visibility', 16)->default('private');
                $t->string('title', 160)->nullable();
                $t->text('body')->nullable();
                $t->string('colour', 16)->nullable();
                $t->boolean('pinned')->default(false);
                $t->string('linked_type', 16)->nullable();
                $t->unsignedBigInteger('linked_id')->nullable();
                $t->date('remind_on')->nullable()->index();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->unsignedBigInteger('deleted_by')->nullable();
                $t->index(['linked_type', 'linked_id']);
                $t->index(['agency_id', 'visibility']);
            });
        }
        if (! Schema::hasTable('quick_note_revisions')) {
            Schema::create('quick_note_revisions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('note_id')->index();
                $t->unsignedBigInteger('user_id');
                $t->string('action', 16);
                $t->json('changes')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_note_revisions');
        Schema::dropIfExists('quick_notes');
    }
};
