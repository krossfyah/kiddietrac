<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* A message can be flagged as an EMERGENCY (App\Support\EmergencyMessage): it rings and
   vibrates on the recipients' phones instead of arriving as an ordinary notification. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['messages', 'staff_messages'] as $t) {
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'is_emergency')) {
                Schema::table($t, function (Blueprint $b) {
                    $b->boolean('is_emergency')->default(false);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['messages', 'staff_messages'] as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'is_emergency')) {
                Schema::table($t, function (Blueprint $b) {
                    $b->dropColumn('is_emergency');
                });
            }
        }
    }
};
