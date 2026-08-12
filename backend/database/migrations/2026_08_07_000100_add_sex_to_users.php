<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'sex')) {
            Schema::table('users', function (Blueprint $table) {
                // 'male' | 'female' | null. Drives the default silhouette avatar
                // when a user has no uploaded photo. Captured (required) during
                // onboarding for parents/providers/staff.
                $table->string('sex', 16)->nullable()->after('last_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'sex')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('sex');
            });
        }
    }
};
