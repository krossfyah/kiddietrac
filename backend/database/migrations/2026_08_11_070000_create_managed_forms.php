<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('managed_forms')) {
            Schema::create('managed_forms', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('agency_id')->index();
                $t->string('title', 190);
                $t->text('description')->nullable();
                $t->string('file_url', 500);              // /storage/... public path
                $t->string('file_type', 60)->nullable();
                $t->unsignedBigInteger('file_size')->nullable();
                $t->json('audiences')->nullable();         // ["guardian","educator","home_visitor"]
                $t->boolean('active')->default(true);
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('managed_form_signoffs')) {
            Schema::create('managed_form_signoffs', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('managed_form_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('signer_name', 190)->nullable();
                $t->longText('signature')->nullable();     // base64 PNG
                $t->timestamp('signed_at')->nullable();
                $t->string('ip_address', 45)->nullable();
                $t->timestamps();
                $t->unique(['managed_form_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_form_signoffs');
        Schema::dropIfExists('managed_forms');
    }
};
