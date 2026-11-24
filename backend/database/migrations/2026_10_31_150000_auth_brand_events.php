<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every brand-lock sign-in decision (App\Support\BrandLock): the impact report and the record of refusals. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('auth_brand_events')) return;
        Schema::create('auth_brand_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('path', 20);
            $t->string('context', 24);
            $t->string('user_brands', 120)->nullable();
            $t->string('decision', 16)->index();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_brand_events');
    }
};
