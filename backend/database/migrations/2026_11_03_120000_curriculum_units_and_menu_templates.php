<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ontario curriculum library + reusable menu weeks (2026-11-03).
 *
 * curriculum_templates already held KiddieTrac's one-activity ideas (agency_id 0, visibility
 * 'platform') and an agency's own. It now also holds multi-week UNITS (kind = 'unit'):
 *   - the library: agency_id 0, visibility 'platform', loaded by `php8.3 artisan kt:curriculum-library`
 *     (idempotent, matched on slug) - read-only to agencies;
 *   - an agency's copy: agency_id = the agency, visibility 'agency', source_id = the library unit.
 * The whole unit (goals, activities, week schedule, observation prompts, family ideas, inclusion
 * and French notes) is one JSON document in unit_data; the columns beside it are what the
 * library filters on.
 *
 * menu_templates: an agency's saved menu weeks (dishes + allergens + food photos), applied to
 * one or several centres' weeks from the Weekly menu screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('curriculum_templates', function (Blueprint $t) {
            if (! Schema::hasColumn('curriculum_templates', 'kind')) {
                $t->string('kind', 12)->default('activity')->after('agency_id');
            }
            if (! Schema::hasColumn('curriculum_templates', 'slug')) {
                $t->string('slug', 100)->nullable()->after('kind');
            }
            if (! Schema::hasColumn('curriculum_templates', 'theme')) {
                $t->string('theme', 60)->nullable()->after('age_band');
            }
            if (! Schema::hasColumn('curriculum_templates', 'season')) {
                $t->string('season', 12)->nullable()->after('theme');
            }
            if (! Schema::hasColumn('curriculum_templates', 'weeks')) {
                $t->unsignedTinyInteger('weeks')->nullable()->after('season');
            }
            if (! Schema::hasColumn('curriculum_templates', 'foundations')) {
                $t->string('foundations', 80)->nullable()->after('weeks');   // csv of HDLH keys, for filtering
            }
            if (! Schema::hasColumn('curriculum_templates', 'unit_data')) {
                $t->longText('unit_data')->nullable()->after('activities');
            }
            if (! Schema::hasColumn('curriculum_templates', 'source_id')) {
                $t->unsignedBigInteger('source_id')->nullable()->after('unit_data');
            }
            if (! Schema::hasColumn('curriculum_templates', 'content_version')) {
                $t->unsignedInteger('content_version')->nullable()->after('source_id');
            }
            if (! Schema::hasColumn('curriculum_templates', 'updated_by_user_id')) {
                $t->unsignedBigInteger('updated_by_user_id')->nullable()->after('created_by_user_id');
            }
        });
        Schema::table('curriculum_templates', function (Blueprint $t) {
            $t->index(['kind', 'agency_id'], 'idx_curric_kind_agency');
            $t->index('slug', 'idx_curric_slug');
        });

        if (! Schema::hasTable('menu_templates')) {
            Schema::create('menu_templates', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->string('name', 80);
                $t->unsignedBigInteger('source_centre_id')->nullable();
                $t->date('source_week')->nullable();
                $t->longText('items');                 // [{day_of_week, meal_type, name, description, allergens, image_url, image_source, food_image_id}]
                $t->unsignedInteger('item_count')->default(0);
                $t->unsignedInteger('use_count')->default(0);
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->index('agency_id', 'idx_menu_tpl_agency');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_templates');
        Schema::table('curriculum_templates', function (Blueprint $t) {
            $t->dropIndex('idx_curric_kind_agency');
            $t->dropIndex('idx_curric_slug');
            foreach (['kind', 'slug', 'theme', 'season', 'weeks', 'foundations', 'unit_data', 'source_id', 'content_version', 'updated_by_user_id'] as $c) {
                if (Schema::hasColumn('curriculum_templates', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
