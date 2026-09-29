<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Announcements can also go out as a phone call (2026-09-29). send_voice says it did;
   voice_category is the reason picked, which decides who may be rung. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            if (! Schema::hasColumn('announcements', 'send_voice')) {
                $table->boolean('send_voice')->default(false)->after('send_push');
            }
            if (! Schema::hasColumn('announcements', 'voice_category')) {
                $table->string('voice_category', 40)->nullable()->after('send_voice');
            }
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            foreach (['voice_category', 'send_voice'] as $c) {
                if (Schema::hasColumn('announcements', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
