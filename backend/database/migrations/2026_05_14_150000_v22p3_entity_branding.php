<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('centres', function (Blueprint $table) {
            if (! Schema::hasColumn('centres', 'logo_url')) {
                $table->string('logo_url', 500)->nullable()->after('email');
            }
            if (! Schema::hasColumn('centres', 'brand_color')) {
                $table->string('brand_color', 20)->nullable()->after('logo_url');
            }
            if (! Schema::hasColumn('centres', 'accent_color')) {
                $table->string('accent_color', 20)->nullable()->after('brand_color');
            }
            if (! Schema::hasColumn('centres', 'tagline')) {
                $table->string('tagline', 200)->nullable()->after('accent_color');
            }
        });

        Schema::table('rooms', function (Blueprint $table) {
            if (! Schema::hasColumn('rooms', 'logo_url')) {
                $table->string('logo_url', 500)->nullable()->after('color_hex');
            }
            if (! Schema::hasColumn('rooms', 'tagline')) {
                $table->string('tagline', 200)->nullable()->after('logo_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            foreach (['tagline', 'logo_url'] as $col) {
                if (Schema::hasColumn('rooms', $col)) $table->dropColumn($col);
            }
        });
        Schema::table('centres', function (Blueprint $table) {
            foreach (['tagline', 'accent_color', 'brand_color', 'logo_url'] as $col) {
                if (Schema::hasColumn('centres', $col)) $table->dropColumn($col);
            }
        });
    }
};
