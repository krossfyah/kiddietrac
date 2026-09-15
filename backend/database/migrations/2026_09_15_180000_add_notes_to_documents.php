<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the person filing a document wanted to say about it.
 *
 * The immunization record modal asks for a note — "card is faded, 3rd dose unreadable",
 * "clinic confirming the booster by phone" — and that sentence is exactly the context a
 * director or an educator needs when they open the file weeks later. It belongs with the
 * document, not in the audit log: the audit trail records that something happened, and
 * nobody reads it to do their job.
 *
 * Nullable and generic, because a note is not specific to immunization records and the
 * next flow that files a document will want one too.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('documents', 'notes')) {
            Schema::table('documents', function (Blueprint $t) {
                $t->text('notes')->nullable()->after('title');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('documents', 'notes')) {
            Schema::table('documents', function (Blueprint $t) {
                $t->dropColumn('notes');
            });
        }
    }
};
