<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('centres', function (Blueprint $table) {
            if (! Schema::hasColumn('centres', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('country');
            }
            if (! Schema::hasColumn('centres', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
        });
    }

    public function down(): void
    {
        Schema::table('centres', function (Blueprint $table) {
            foreach (['latitude', 'longitude'] as $c) {
                if (Schema::hasColumn('centres', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
