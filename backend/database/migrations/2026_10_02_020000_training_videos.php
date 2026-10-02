<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Training (2026-10-01): a narrated tutorial video per help article, and who has
   watched what. A video is keyed to its article — audience folder + slug, exactly as
   HelpService names them — so whoever may read the article may watch its video, and
   nobody else. status: draft (platform admins only, for review) | published. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('training_videos')) {
            Schema::create('training_videos', function (Blueprint $t) {
                $t->id();
                $t->string('audience', 30);                 // parent | educator | home_visitor | director | sales | shared
                $t->string('slug', 120);                    // the help article's slug
                $t->string('title', 190);
                $t->string('video_url', 255);
                $t->string('captions_url', 255)->nullable();
                $t->string('poster_url', 255)->nullable();
                $t->unsignedInteger('duration_sec')->default(0);
                $t->json('chapters')->nullable();           // [{t: seconds, label}]
                $t->string('status', 12)->default('draft');
                $t->timestamp('recorded_at')->nullable();
                $t->timestamp('published_at')->nullable();
                $t->unsignedBigInteger('published_by')->nullable();
                $t->timestamps();
                $t->unique(['audience', 'slug']);
            });
        }
        if (! Schema::hasTable('training_progress')) {
            Schema::create('training_progress', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('video_id');
                $t->unsignedInteger('position_sec')->default(0);
                $t->timestamp('completed_at')->nullable();
                $t->timestamps();
                $t->unique(['user_id', 'video_id']);
                $t->index('video_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('training_progress');
        Schema::dropIfExists('training_videos');
    }
};
