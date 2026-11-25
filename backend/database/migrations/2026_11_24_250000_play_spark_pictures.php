<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* PLAY SPARK PICTURES (2026-11-24): a Claude-drawn "end result + steps" picture for every Play Spark idea and weekly
   lesson-plan activity. App\Support\PlaySparkPicture, drawn by the queued App\Jobs\DrawPlaySparkPicture. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('play_spark_pictures')) {
            return;
        }
        Schema::create('play_spark_pictures', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->string('kind', 10);              // idea | lesson
            $t->string('ref', 40);               // the idea id, or the lesson activity's content key (PlaySparkPicture::lessonKey)
            $t->string('status', 10)->default('drawing');   // drawing | ready | failed | off
            $t->text('source')->nullable();      // JSON: what to draw from (the idea, or the activity's title/notes/theme) - no people
            $t->mediumText('svg')->nullable();   // sanitised, self-contained
            $t->string('png_path', 200)->nullable();   // private disk, for email (signed route play-spark.picture.png)
            $t->unsignedTinyInteger('tries')->default(0);
            $t->string('error', 255)->nullable();
            $t->timestamps();
            $t->unique(['agency_id', 'kind', 'ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('play_spark_pictures');
    }
};
