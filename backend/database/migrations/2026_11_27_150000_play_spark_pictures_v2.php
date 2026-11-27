<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* PLAY SPARK PICTURES v2 (2026-11-27, Anthony: "the playspark images that are drawn needs to be better and more precise
   so the educators can follow this"). version = which drawing pipeline made it (1 = the one-call drawing, 2 = plan,
   draw, check); redraw_at = a fresh drawing was asked for (the old picture stays up until it is ready); meta = JSON
   {steps: [the captions], model, ms, cost, fixed} - the captions also show as text beside the picture. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('play_spark_pictures', function (Blueprint $t) {
            if (! Schema::hasColumn('play_spark_pictures', 'version')) {
                $t->unsignedTinyInteger('version')->default(1)->after('status');
            }
            if (! Schema::hasColumn('play_spark_pictures', 'redraw_at')) {
                $t->timestamp('redraw_at')->nullable()->after('tries');
            }
            if (! Schema::hasColumn('play_spark_pictures', 'meta')) {
                $t->text('meta')->nullable()->after('png_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('play_spark_pictures', function (Blueprint $t) {
            foreach (['version', 'redraw_at', 'meta'] as $c) {
                if (Schema::hasColumn('play_spark_pictures', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
