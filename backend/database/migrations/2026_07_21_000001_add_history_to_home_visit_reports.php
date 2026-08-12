<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('home_visit_reports', function (Blueprint $t) {
            if (!Schema::hasColumn('home_visit_reports', 'history')) $t->json('history')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('home_visit_reports', function (Blueprint $t) {
            if (Schema::hasColumn('home_visit_reports', 'history')) $t->dropColumn('history');
        });
    }
};
