<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('centres', 'provider_bio')) {
            Schema::table('centres', function (Blueprint $table) {
                // A warm first-person introduction from the provider (home childcare
                // person). Emailed to parents in the welcome email when a family is
                // assigned to this provider. Required in the edit UI.
                $table->text('provider_bio')->nullable()->after('tagline');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('centres', 'provider_bio')) {
            Schema::table('centres', function (Blueprint $table) {
                $table->dropColumn('provider_bio');
            });
        }
    }
};
