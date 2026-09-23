<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notes on a family, with an author and a time.
 *
 * `families.notes` is a single free-text column: no author, no date, and saving it
 * overwrites whatever the last person wrote. For anything worth writing down about a
 * family — a custody arrangement, a conversation about fees, a concern — "who said this,
 * and when" is most of the value, and it was the part being thrown away.
 *
 * Append-only by design. Notes are edited by adding another one; a record of what was
 * known at a point in time stops being that the moment it can be rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('family_notes')) {
            return;
        }

        Schema::create('family_notes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('family_id')->index();
            // Nullable so a note written by an automated process is still keepable —
            // but every note the UI writes carries its author.
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->text('body');
            /* Pinned notes surface at the top of the family record. The custody
               arrangement should not scroll away under six months of small talk. */
            $t->boolean('pinned')->default(false);
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();

            $t->index(['family_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_notes');
    }
};
