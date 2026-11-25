<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * PRIVATE TO-DOS (2026-11-25, Anthony: admins/directors/super admins add a task for "Me" from the My day panel).
 * A private task belongs to the person who made it: assigned_to = assigned_by = them, is_private = 1. Only they
 * see it; it is never listed to anyone else, never notifies, never reminds (TaskController + every task reader).
 * agency_id becomes nullable: a super admin with no agency picked keeps to-dos of their own (agency_id NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            if (! Schema::hasColumn('tasks', 'is_private')) {
                $t->boolean('is_private')->default(false)->after('assigned_by');
                $t->index(['assigned_by', 'is_private'], 'tasks_private_owner_idx');
            }
            $t->unsignedBigInteger('agency_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            if (Schema::hasColumn('tasks', 'is_private')) {
                $t->dropIndex('tasks_private_owner_idx');
                $t->dropColumn('is_private');
            }
        });
    }
};
