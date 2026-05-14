<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'onboarded_at')) {
                $table->timestamp('onboarded_at')->nullable()->after('last_login_ip');
            }
            if (! Schema::hasColumn('users', 'profile_extras')) {
                $table->json('profile_extras')->nullable()->after('onboarded_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'profile_extras')) {
                $table->dropColumn('profile_extras');
            }
            if (Schema::hasColumn('users', 'onboarded_at')) {
                $table->dropColumn('onboarded_at');
            }
        });
    }
};
