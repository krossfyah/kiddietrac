<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FOLDERS AND REAL FILE NAMES FOR THE FILES REPOSITORY (2026-09-23)
 *
 * `title` was the only name a document had, and it defaulted to the uploaded file name —
 * so a file kept its extension only until somebody typed a title, and then ".pdf" was
 * gone. The extension is what tells a reader whether they are about to open a deck or a
 * spreadsheet, so the ORIGINAL name is now kept in its own column and shown as-is.
 *
 * Folders get their own table rather than being a string on the document, because an
 * empty folder has to be able to exist: somebody makes "Contracts" today and fills it
 * tomorrow, and a folder that only exists while a file is in it cannot be created or
 * deleted as a thing in its own right.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_folders')) {
            Schema::create('sales_folders', function (Blueprint $t) {
                $t->id();
                $t->string('name', 80);
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                /* One folder of a given name, so two people creating "Pricing" on the same
                   morning end up in the same place rather than with two of them. */
                $t->unique('name');
            });
        }

        if (Schema::hasTable('sales_documents')) {
            Schema::table('sales_documents', function (Blueprint $t) {
                if (! Schema::hasColumn('sales_documents', 'folder_id')) {
                    /* Nullable = the root of the repository, which is where everything
                       already is. No backfill, and nothing moves on deploy. */
                    $t->unsignedBigInteger('folder_id')->nullable()->index()->after('category');
                }
                if (! Schema::hasColumn('sales_documents', 'file_name')) {
                    $t->string('file_name', 255)->nullable()->after('title');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_documents')) {
            Schema::table('sales_documents', function (Blueprint $t) {
                if (Schema::hasColumn('sales_documents', 'folder_id')) { $t->dropColumn('folder_id'); }
                if (Schema::hasColumn('sales_documents', 'file_name')) { $t->dropColumn('file_name'); }
            });
        }
        Schema::dropIfExists('sales_folders');
    }
};
