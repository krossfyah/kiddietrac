<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Image/screenshot attachments on support feedback (2026-08-06).
 * Stored as a JSON array of {url, name}; files live on the public disk under
 * feedback-attachments/ (served at /storage/feedback-attachments/…).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('parent_feedback', 'attachments')) {
            Schema::table('parent_feedback', function (Blueprint $t) {
                $t->json('attachments')->nullable()->after('comment');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('parent_feedback', 'attachments')) {
            Schema::table('parent_feedback', function (Blueprint $t) {
                $t->dropColumn('attachments');
            });
        }
    }
};
