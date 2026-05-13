<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v21 — AI Lesson Plans (one week per room).
 *
 * Stores generated lesson plans separately from any v14 lesson_plans table.
 * Each row = 1 week, 1 room. Body is structured JSON with 5 days of activities.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_lesson_plans')) {
            Schema::create('ai_lesson_plans', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('room_id')->nullable();
                $t->unsignedBigInteger('created_by_id'); // user_id
                $t->date('week_starting');
                $t->string('theme', 160);
                $t->string('age_group', 60)->nullable(); // 'infant', 'toddler', 'preschool', 'kindergarten'
                $t->json('plan_body'); // {days: [{day, theme, activities[], hdlh_focus}], materials, family_share}
                $t->text('source_prompt')->nullable();
                $t->string('model_used', 80)->nullable();
                $t->unsignedInteger('tokens_used')->nullable();
                $t->boolean('published')->default(false);
                $t->timestamp('generated_at')->useCurrent();
                $t->timestamps();

                $t->index(['centre_id', 'week_starting']);
                $t->index(['room_id', 'week_starting']);
            });
        }
    }

    public function down(): void
    {
        // Preserve.
    }
};
