<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Job applications from www.kiddietrac.com/careers (App\Http\Controllers\Api\CareersController).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('career_applications')) return;
        Schema::create('career_applications', function (Blueprint $t) {
            $t->id();
            $t->string('role', 120);
            $t->string('first_name', 80);
            $t->string('last_name', 80);
            $t->string('email', 160);
            $t->string('phone', 40)->nullable();
            $t->string('linkedin', 255)->nullable();
            $t->text('cover_text')->nullable();
            $t->string('resume_path', 255)->nullable();
            $t->string('cover_path', 255)->nullable();
            $t->string('status', 20)->default('new');
            $t->string('ip', 45)->nullable();
            $t->timestamps();
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_applications');
    }
};
