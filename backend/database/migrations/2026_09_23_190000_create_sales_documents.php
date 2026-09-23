<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE SALES REFERENCE LIBRARY (2026-09-23)
 *
 * Material the sales side keeps to hand — price sheets, comparison decks, contract
 * templates, licensing paperwork. Platform-wide, belonging to KiddieTrac rather than to
 * any agency, and readable only by a superadmin.
 *
 * ITS OWN TABLE, NOT `documents`, for two reasons and both matter:
 *
 *  - `documents.scope_type` is an ENUM('child','family','user','centre','agency'). There
 *    is no platform-level value, and widening a shared enum to hold one is how an ENUM
 *    starts 500ing the rows that were already fine.
 *  - Everything that reads `documents` is scoped to a tenant. A row that belongs to no
 *    agency sitting in that table is one forgotten `where` away from appearing on a
 *    family's record — and the standing rule here is that a list fails CLOSED. Keeping
 *    these out of that table means no agency query can reach them by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_documents')) {
            return;
        }

        Schema::create('sales_documents', function (Blueprint $t) {
            $t->id();

            $t->string('title', 200);
            $t->text('notes')->nullable();

            /* A loose grouping so the list can be read at a glance once it is long.
               Free text rather than an enum, deliberately: the categories sales actually
               wants are not knowable from here, and the cost of guessing wrong is a
               migration. */
            $t->string('category', 60)->nullable()->index();

            /* Same shape as documents.file_url: a /storage/... path, served through
               MediaFileController behind a signed URL, never linked to directly. */
            $t->string('file_url', 500);
            $t->string('file_type', 100)->nullable();
            $t->unsignedBigInteger('file_size')->nullable();

            $t->unsignedBigInteger('uploaded_by_id');
            $t->timestamps();

            /* Soft delete: removing a reference document is a judgement somebody may
               want back, and the file stays on disk until it is really gone. */
            $t->softDeletes();

            $t->index(['deleted_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_documents');
    }
};
