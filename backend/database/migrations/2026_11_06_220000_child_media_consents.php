<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHOTO CONSENT PER CHILD (2026-11-06). Anthony: "wire this up and add guards for all new agencies as well so we never
 * run into this issue ever again" - a parent answered "No, please do not photograph my child" at onboarding, nothing read
 * it, and the child was tagged in 19 photos (one of them a group photo another family could see).
 * See App\Support\MediaConsent.
 *
 * child_media_consents: ONE answer per guardian per child. The child's effective permission is the most restrictive
 *   answer (any No wins); no answer at all = the child's own family only.
 * photos.hidden_at: a photo kept on file but shown to nobody (no consent). Nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('child_media_consents')) {
            Schema::create('child_media_consents', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('child_id');
                $t->unsignedBigInteger('user_id');                  // the guardian whose answer this is
                $t->string('level', 8);                            // none | family | group
                $t->boolean('marketing')->default(false);          // separate, explicit; never implied
                $t->string('source', 16)->default('parent');       // parent | onboarding | paper (staff recorded a signed form)
                $t->unsignedBigInteger('recorded_by_id')->nullable();
                $t->string('note', 300)->nullable();
                $t->timestamps();
                $t->unique(['child_id', 'user_id']);
                $t->index('user_id');
            });
        }
        Schema::table('photos', function (Blueprint $t) {
            if (! Schema::hasColumn('photos', 'hidden_at')) $t->timestamp('hidden_at')->nullable();
            if (! Schema::hasColumn('photos', 'hidden_reason')) $t->string('hidden_reason', 40)->nullable();
            if (! Schema::hasColumn('photos', 'hidden_by_id')) $t->unsignedBigInteger('hidden_by_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_media_consents');
        Schema::table('photos', function (Blueprint $t) {
            foreach (['hidden_at', 'hidden_reason', 'hidden_by_id'] as $c) if (Schema::hasColumn('photos', $c)) $t->dropColumn($c);
        });
    }
};
