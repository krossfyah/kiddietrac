<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'pay_rate')) $t->decimal('pay_rate', 8, 2)->nullable();
            if (!Schema::hasColumn('users', 'pay_type')) $t->string('pay_type', 20)->nullable(); // 'hourly' | 'per_visit'
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'pay_rate')) $t->dropColumn('pay_rate');
            if (Schema::hasColumn('users', 'pay_type')) $t->dropColumn('pay_type');
        });
    }
};
