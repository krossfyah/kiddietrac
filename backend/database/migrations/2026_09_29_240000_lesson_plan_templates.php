<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Weekly lesson plan templates (2026-09-29). agency_id NULL = KiddieTrac's library,
   seeded here from database/data/lesson_plan_templates.json; otherwise an agency's own,
   saved from the planner. plan_data is the same shape as lesson_plans.plan_data. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lesson_plan_templates')) {
            Schema::create('lesson_plan_templates', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->nullable()->index();
                $t->string('title', 80);
                $t->string('theme', 160)->nullable();
                $t->string('age_group', 20)->default('mixed');
                $t->string('description', 300)->nullable();
                $t->longText('plan_data');
                $t->boolean('active')->default(true);
                $t->unsignedInteger('use_count')->default(0);
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
            });
            DB::statement('ALTER TABLE lesson_plan_templates CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        }

        $file = database_path('data/lesson_plan_templates.json');
        if (is_file($file) && ! DB::table('lesson_plan_templates')->whereNull('agency_id')->exists()) {
            foreach (json_decode((string) file_get_contents($file), true) ?: [] as $t) {
                DB::table('lesson_plan_templates')->insert([
                    'agency_id' => null,
                    'title' => mb_substr($t['title'], 0, 80),
                    'theme' => mb_substr($t['theme'] ?? $t['title'], 0, 160),
                    'age_group' => $t['age_group'] ?? 'mixed',
                    'description' => mb_substr((string) ($t['description'] ?? ''), 0, 300) ?: null,
                    'plan_data' => json_encode(['days' => \App\Http\Controllers\Api\LessonPlanTemplateController::cleanDays($t['days'] ?? [])]),
                    'active' => 1, 'use_count' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_plan_templates');
    }
};
