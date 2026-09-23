<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A real flag for "the system did this" (2026-08-25).
 *
 * check_events.recorded_by_id is NOT NULL, so AutoSignOffCommand borrows whoever last
 * touched the room. The only thing distinguishing an automatic overnight close from an
 * educator signing a child out was a phrase inside free-text notes.
 *
 * That workaround was caught FOUR times in one day, each after the false statement had
 * already reached somebody: a parent told their child "went home", two educators credited
 * with children they never covered, and a compliance report naming an educator who had
 * gone home. Every new reader of this table inherited the bug until someone remembered
 * to filter on a sentence.
 *
 * Additive and nullable: no existing reader changes behaviour, and recorded_by_id is left
 * exactly as it is — repointing that column is a separate decision with its own blast
 * radius. This just makes the fact checkable without parsing prose.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('check_events', 'is_automatic')) {
            return;
        }

        Schema::table('check_events', function (Blueprint $table) {
            $table->boolean('is_automatic')->default(false)->after('kiosk_source');
            $table->index('is_automatic');
        });

        /* Backfill from the marker the nightly job has been writing all along, so history
           is as queryable as anything recorded from here on. */
        DB::table('check_events')
            ->where('notes', 'like', '%uto sign-off%')
            ->update(['is_automatic' => true]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('check_events', 'is_automatic')) {
            return;
        }

        Schema::table('check_events', function (Blueprint $table) {
            $table->dropIndex(['is_automatic']);
            $table->dropColumn('is_automatic');
        });
    }
};
