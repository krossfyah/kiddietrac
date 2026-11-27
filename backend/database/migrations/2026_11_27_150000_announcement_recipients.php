<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * announcement_recipients (2026-11-27): who a marketing campaign's News post is for (App\Support\CampaignNews).
 * Resolved once when the campaign is sent; every News reader shows a campaign post only to a listed person.
 * Additive and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('announcement_recipients')) return;
        Schema::create('announcement_recipients', function (Blueprint $t) {
            $t->unsignedBigInteger('announcement_id');
            $t->unsignedBigInteger('user_id');
            $t->primary(['announcement_id', 'user_id']);
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_recipients');
    }
};
