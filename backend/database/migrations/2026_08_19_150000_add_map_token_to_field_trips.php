<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The filename of a walk's map image.
 *
 * Email clients cannot authenticate, so a map in a daily summary has to be fetchable
 * without a session. The token IS the access control: 40 random characters, not derived
 * from the trip id and not enumerable. Stored so the same walk keeps the same image
 * rather than rendering a new one per recipient.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('field_trips', 'map_token')) {
            Schema::table('field_trips', function (Blueprint $table) {
                $table->string('map_token', 64)->nullable()->unique()->after('distance_km');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('field_trips', 'map_token')) {
            Schema::table('field_trips', function (Blueprint $table) {
                $table->dropColumn('map_token');
            });
        }
    }
};
