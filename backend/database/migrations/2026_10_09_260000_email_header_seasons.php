<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Seasonal email header banners (2026-10-09): during a season every KiddieTrac-branded
   email uses that season's banner instead of the standard one. See App\Support\EmailHeader. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_header_seasons')) return;
        Schema::create('email_header_seasons', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('image_url', 300);
            $t->dateTime('starts_at');                    // agency-neutral: America/Toronto wall clock
            $t->dateTime('ends_at');
            $t->boolean('repeat_yearly')->default(true);
            $t->boolean('active')->default(true);
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_header_seasons');
    }
};
