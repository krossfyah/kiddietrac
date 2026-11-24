<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* AI usage per agency (App\Support\UsageMeter), for the superadmin weekly spend summary. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_usage')) return;
        Schema::create('ai_usage', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('feature', 120)->nullable();
            $t->string('model', 80)->nullable();
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->timestamp('created_at')->nullable();
            $t->index(['agency_id', 'created_at']);
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
