<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Food photos on the weekly menu (2026-10-31, Anthony: "can the educators choose pictures for common foods ... or have
 * AI do this on their behalf"). food_images is one shared, licensed library (App\Support\FoodPhotos); every image is
 * CC0 / public domain and credited here. menu_items remembers where its photo came from, so a photo someone removed
 * on purpose is never put back automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('food_images')) {
            Schema::create('food_images', function (Blueprint $t) {
                $t->id();
                $t->string('slug', 60)->unique();
                $t->string('name', 120);
                $t->text('synonyms')->nullable();          // comma-separated, EN / FR / ES
                $t->string('allergens', 160)->nullable();  // a hint for the person writing the menu, never applied by itself
                $t->string('category', 24)->default('lunch');
                $t->string('url', 255);                    // /storage/food-library/<slug>.webp (640px)
                $t->string('thumb_url', 255);              // /storage/food-library/<slug>-thumb.webp (200px)
                $t->string('source_url', 500)->nullable();
                $t->string('title', 255)->nullable();
                $t->string('author', 160)->nullable();
                $t->string('licence', 60)->nullable();
                $t->string('licence_url', 255)->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        Schema::table('menu_items', function (Blueprint $t) {
            if (! Schema::hasColumn('menu_items', 'image_source')) {
                $t->string('image_source', 12)->nullable();   // library | upload | auto | none
            }
            if (! Schema::hasColumn('menu_items', 'food_image_id')) {
                $t->unsignedBigInteger('food_image_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $t) {
            foreach (['image_source', 'food_image_id'] as $c) {
                if (Schema::hasColumn('menu_items', $c)) $t->dropColumn($c);
            }
        });
        Schema::dropIfExists('food_images');
    }
};
