<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Files attached to sent emails, shown in the email log (App\Support\EmailLogAttachments). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_log_attachments')) return;
        Schema::create('email_log_attachments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('email_log_id')->index();
            $t->string('filename', 255);
            $t->string('mime', 120)->nullable();
            $t->unsignedInteger('size')->default(0);
            $t->string('path', 300)->nullable();   // null = too large to keep
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_log_attachments');
    }
};
