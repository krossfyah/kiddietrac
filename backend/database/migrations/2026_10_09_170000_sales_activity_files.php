<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * FILES ON A LEAD'S ACTIVITY (2026-10-09).
 * Anthony: "add ability to upload transcript from MS teams meetings and video to activity
 * and history". A Teams transcript (.vtt / .docx) is read into transcript_text so it can be
 * shown and searched; a recording is stored and played from the signed media route.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_activity_files')) return;
        Schema::create('sales_activity_files', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('activity_id')->index();
            $t->unsignedBigInteger('lead_id')->index();
            $t->string('kind', 20);                 // transcript | video | audio | file
            $t->string('original_name', 255);
            $t->string('file_url', 500);            // /storage/sales-activity/...
            $t->string('mime', 120)->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->longText('transcript_text')->nullable();
            $t->unsignedBigInteger('uploaded_by_id')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_activity_files');
    }
};
