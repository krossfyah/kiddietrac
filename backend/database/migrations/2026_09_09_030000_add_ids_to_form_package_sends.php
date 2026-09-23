<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHAT WAS ACTUALLY SENT, not just what it was called.
 *
 * form_package_sends stores SNAPSHOTS -- the titles as they read that day, the names and
 * addresses as they were -- so renaming a form next month cannot rewrite history. That is
 * right, and it is also not enough: a snapshot cannot be re-sent. "Consent form" does not
 * identify a row, two people can share a name, and matching an old send back to today's
 * records by title is exactly the kind of guess that quietly sends the wrong paperwork.
 *
 * So the ids ride along beside the snapshot. The snapshot stays the record of what the
 * package said; the ids are what a re-send acts on, re-checked against the agency at the
 * time it runs -- a form withdrawn since, or a person who has left, must not come back
 * to life because a row remembers them.
 *
 * Nullable: the sends already on file predate this and keep working, with re-send
 * unavailable rather than approximated. (Anthony, 2026-09-09)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_package_sends', function (Blueprint $t) {
            $t->text('form_ids')->nullable()->after('form_titles');
            $t->text('recipient_ids')->nullable()->after('recipients');
        });
    }

    public function down(): void
    {
        Schema::table('form_package_sends', function (Blueprint $t) {
            $t->dropColumn(['form_ids', 'recipient_ids']);
        });
    }
};
