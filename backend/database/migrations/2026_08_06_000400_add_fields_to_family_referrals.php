<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_referrals', function (Blueprint $table) {
            if (! Schema::hasColumn('family_referrals', 'referred_name')) {
                $table->string('referred_name', 120)->nullable()->after('referred_email');
            }
            if (! Schema::hasColumn('family_referrals', 'referred_phone')) {
                $table->string('referred_phone', 40)->nullable()->after('referred_name');
            }
            if (! Schema::hasColumn('family_referrals', 'message')) {
                $table->text('message')->nullable()->after('referred_phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('family_referrals', function (Blueprint $table) {
            foreach (['referred_name', 'referred_phone', 'message'] as $c) {
                if (Schema::hasColumn('family_referrals', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
