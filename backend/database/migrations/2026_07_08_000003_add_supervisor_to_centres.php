<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('centres', function (Blueprint $t) {
            if (! Schema::hasColumn('centres', 'supervisor_first_name')) $t->string('supervisor_first_name', 80)->nullable()->after('name');
            if (! Schema::hasColumn('centres', 'supervisor_last_name')) $t->string('supervisor_last_name', 80)->nullable()->after('supervisor_first_name');
        });
    }
    public function down(): void
    {
        Schema::table('centres', function (Blueprint $t) {
            $t->dropColumn(['supervisor_first_name', 'supervisor_last_name']);
        });
    }
};
