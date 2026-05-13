<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('edocument_templates')) {
            Schema::create('edocument_templates', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('centre_id');
                $t->string('name', 200);
                $t->string('description', 500)->nullable();
                $t->string('storage_path', 500); // relative to storage/app/private
                $t->string('original_filename', 200);
                $t->unsignedInteger('size_bytes')->default(0);
                $t->enum('audience', ['all_families', 'opt_in'])->default('all_families');
                $t->boolean('required')->default(true);
                $t->enum('status', ['active', 'archived'])->default('active');
                $t->unsignedBigInteger('uploaded_by_id');
                $t->timestamps();
                $t->softDeletes();
                $t->index(['centre_id', 'status']);
            });
        }

        if (! Schema::hasTable('edocument_signatures')) {
            Schema::create('edocument_signatures', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('template_id');
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('family_id');
                $t->unsignedBigInteger('signed_by_user_id');
                $t->unsignedBigInteger('child_id')->nullable();
                $t->longText('signature_data'); // canvas dataURL (PNG)
                $t->string('typed_name', 160)->nullable(); // belt-and-suspenders alt
                $t->timestamp('signed_at')->useCurrent();
                $t->string('ip_address', 45)->nullable();
                $t->string('user_agent', 500)->nullable();
                $t->timestamps();
                $t->index(['template_id', 'family_id']);
                $t->index(['family_id', 'signed_at']);
                $t->index('centre_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('edocument_signatures');
        Schema::dropIfExists('edocument_templates');
    }
};
