<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge base — articles written by the agency's own people, readable by
 * everyone in that agency whatever their role. This is deliberately separate
 * from Help: Help ships with the product and is role-filtered from files on
 * disk, whereas these are the agency's own notes, open to all of its users.
 *
 * CHARSET IS EXPLICIT AND DELIBERATE. This database defaults to latin1, so a
 * table created without saying otherwise rejects any multi-byte character with
 * SQLSTATE 22007 and a 500 — an article containing an arrow, an accent or an
 * emoji would simply fail to save. See daily_care_logs, which had to be
 * converted after exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_articles', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';

            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->string('title', 200);
            $table->text('body');
            $table->string('category', 60)->nullable()->index();
            $table->string('tags', 200)->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The list is always "this agency's articles, newest edit first".
            $table->index(['agency_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_articles');
    }
};
