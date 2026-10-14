<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parents report an issue to the centre (2026-10-13, Anthony: "add a report an issue section
 * for parents to report an issue to staff and add drop down selections to choose from and
 * notes to be added"). One row per report; the conversation about it in parent_issue_messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parent_issues')) {
            Schema::create('parent_issues', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable()->index();
                $t->unsignedBigInteger('family_id')->index();
                $t->unsignedBigInteger('child_id')->nullable()->index();
                $t->unsignedBigInteger('reported_by');
                $t->string('category', 32);
                $t->string('subcategory', 120)->nullable();
                $t->text('notes');
                $t->string('status', 16)->default('open'); // open | in_progress | resolved | closed
                $t->unsignedBigInteger('assigned_to')->nullable();
                $t->timestamp('resolved_at')->nullable();
                $t->unsignedBigInteger('resolved_by')->nullable();
                $t->text('resolution_note')->nullable();
                $t->timestamp('last_activity_at')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'status']);
            });
        }
        if (! Schema::hasTable('parent_issue_messages')) {
            Schema::create('parent_issue_messages', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('issue_id')->index();
                $t->unsignedBigInteger('user_id');
                $t->string('author_side', 8); // parent | staff
                $t->text('body');
                $t->boolean('internal')->default(false); // staff-only note, never shown to the family
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('parent_issue_messages');
        Schema::dropIfExists('parent_issues');
    }
};
