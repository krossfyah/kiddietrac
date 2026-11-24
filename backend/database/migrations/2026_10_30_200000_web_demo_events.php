<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What rang or arrived on the website demo phone (App\Support\WebDemoPhone). Cleared nightly by kt:reset-web-demo. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('web_demo_events')) return;
        Schema::create('web_demo_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('kind', 20);
            $t->json('payload')->nullable();
            $t->timestamp('deliver_at')->nullable()->index();
            $t->timestamp('seen_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_demo_events');
    }
};
