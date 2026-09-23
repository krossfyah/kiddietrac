<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may see a piece of parent feedback, and when.
 *
 * Anthony's call (2026-08-26): praise reaches the educator straight away, but anything
 * critical goes to the director FIRST, who decides how and when to pass it on. An
 * educator finding unfiltered criticism of themselves in a portal at 7pm is not a
 * feedback loop, it is an ambush.
 *
 * `released_at` is that gate. Null = not yet visible to the educator.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parent_feedback')) {
            return;
        }
        Schema::table('parent_feedback', function (Blueprint $table) {
            if (! Schema::hasColumn('parent_feedback', 'released_at')) {
                $table->timestamp('released_at')->nullable()->after('educator_id');
            }
            if (! Schema::hasColumn('parent_feedback', 'released_by_id')) {
                $table->unsignedBigInteger('released_by_id')->nullable()->after('released_at');
            }
            // When the named educator actually read it — drives the unread count.
            if (! Schema::hasColumn('parent_feedback', 'educator_read_at')) {
                $table->timestamp('educator_read_at')->nullable()->after('released_by_id');
            }
        });
        Schema::table('parent_feedback', function (Blueprint $table) {
            try { $table->index(['educator_id', 'released_at']); } catch (\Throwable $e) {}
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('parent_feedback')) {
            return;
        }
        Schema::table('parent_feedback', function (Blueprint $table) {
            foreach (['released_at', 'released_by_id', 'educator_read_at'] as $c) {
                if (Schema::hasColumn('parent_feedback', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
