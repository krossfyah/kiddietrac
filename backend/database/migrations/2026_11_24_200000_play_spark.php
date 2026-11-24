<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* PLAY SPARK (2026-11-24): on-the-spot AI play ideas for the children in the room right now.
   Part of the AI Bundle (feature key ai_tools). App\Support\PlaySpark + PlaySparkController. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('play_spark_ideas')) {
            Schema::create('play_spark_ideas', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable()->index();
                $t->unsignedBigInteger('room_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('title', 160);
                $t->longText('idea');            // JSON: summary, tiers, materials, setup, safety, domains, watch_for, home_idea
                $t->text('inputs')->nullable();  // JSON: bands, setting, minutes, involvement, materials, theme
                $t->string('safety_level', 12)->default('ok');   // ok | caution
                $t->boolean('favourite')->default(false);
                $t->unsignedTinyInteger('rating')->nullable();
                $t->boolean('shared')->default(false);           // in the agency library
                $t->unsignedInteger('times_done')->default(0);
                $t->timestamp('last_done_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('play_spark_runs')) {
            Schema::create('play_spark_runs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('idea_id')->index();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->unsignedBigInteger('room_id')->nullable()->index();
                $t->unsignedBigInteger('user_id');
                $t->text('child_ids');           // JSON
                $t->text('event_ids')->nullable();
                $t->text('observation_ids')->nullable();
                $t->text('note')->nullable();
                $t->timestamp('done_at')->index();
                $t->timestamp('created_at')->nullable();
            });
        }
        if (! Schema::hasTable('play_spark_materials')) {
            Schema::create('play_spark_materials', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->index();
                $t->unsignedBigInteger('room_id')->nullable()->index();
                $t->string('name', 80);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('play_spark_materials');
        Schema::dropIfExists('play_spark_runs');
        Schema::dropIfExists('play_spark_ideas');
    }
};
