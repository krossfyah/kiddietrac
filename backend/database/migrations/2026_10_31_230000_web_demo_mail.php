<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The website demo phone's Mail app (2026-10-31): the email a public-demo person WOULD have received (App\Support\WebDemoMail). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('web_demo_mail')) return;
        Schema::create('web_demo_mail', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('agency_id')->nullable();
            $t->string('from_name', 190)->nullable();
            $t->string('from_email', 190)->nullable();
            $t->string('subject', 300)->nullable();
            $t->longText('html')->nullable();
            $t->longText('text')->nullable();
            $t->json('attachments')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_demo_mail');
    }
};
