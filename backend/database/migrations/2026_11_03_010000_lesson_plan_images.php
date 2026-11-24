<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pictures on lesson plans (2026-11-02) - see App\Support\LessonImages.
 *  - lesson_images: looked-up, openly licensed pictures, one row per search term ('none' = nothing suitable found).
 *  - lesson_plans.image_url / image_source / lesson_image_id: the plan's cover (its theme). Activities keep the same
 *    fields inside plan_data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lesson_images')) {
            Schema::create('lesson_images', function (Blueprint $t) {
                $t->id();
                $t->string('term', 120)->unique();
                $t->string('status', 8)->default('ok');          // ok | none
                $t->string('provider', 20)->nullable();          // commons
                $t->string('url', 500)->nullable();              // absolute, 640x480 webp
                $t->string('thumb_url', 500)->nullable();        // absolute, 200x150 webp
                $t->string('title', 255)->nullable();
                $t->string('creator', 255)->nullable();
                $t->string('licence', 40)->nullable();           // CC0 | Public domain | CC BY x.x
                $t->string('licence_url', 500)->nullable();
                $t->string('source_url', 500)->nullable();       // the file's page on Commons
                $t->string('original_url', 1000)->nullable();    // what was downloaded
                $t->boolean('attribution_required')->default(false);
                $t->timestamps();
            });
        }
        Schema::table('lesson_plans', function (Blueprint $t) {
            if (! Schema::hasColumn('lesson_plans', 'image_url')) $t->string('image_url', 500)->nullable()->after('theme');
            if (! Schema::hasColumn('lesson_plans', 'image_source')) $t->string('image_source', 12)->nullable()->after('image_url');
            if (! Schema::hasColumn('lesson_plans', 'lesson_image_id')) $t->unsignedBigInteger('lesson_image_id')->nullable()->after('image_source');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_plans', function (Blueprint $t) {
            foreach (['lesson_image_id', 'image_source', 'image_url'] as $c) {
                if (Schema::hasColumn('lesson_plans', $c)) $t->dropColumn($c);
            }
        });
        Schema::dropIfExists('lesson_images');
    }
};
