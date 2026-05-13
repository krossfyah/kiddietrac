<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('invitation_codes')) {
            Schema::create('invitation_codes', function (Blueprint $t) {
                $t->id();
                $t->string('code', 32)->unique();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('created_by_id');
                $t->string('label', 200)->nullable();
                $t->enum('role', ['guardian', 'educator'])->default('guardian');
                $t->unsignedInteger('max_uses')->default(1);
                $t->unsignedInteger('used_count')->default(0);
                $t->timestamp('expires_at')->nullable();
                $t->enum('status', ['active', 'expired', 'revoked'])->default('active');
                $t->timestamps();
                $t->index(['centre_id', 'status']);
                $t->index('code');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_codes');
    }
};
