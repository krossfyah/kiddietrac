<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* What the AI wrote for each agency lately (App\Support\AiFresh), so the next piece of the same kind never repeats its
   openings or stock phrases. Kept 60 days / 60 per kind; never shown to anyone and never put in another agency's prompt. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_content_log')) return;
        Schema::create('ai_content_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->string('kind', 40);
            $t->mediumText('text');
            $t->timestamp('created_at')->nullable();
            $t->index(['agency_id', 'kind', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_content_log');
    }
};
