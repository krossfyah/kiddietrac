<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Your own email" canvas (2026-11-05, Anthony: "have it where it doesn't use the top header and footer as optional
 * where I can create my own campaign from scratch ... totally customizable"): header, footer, signature and the look,
 * as JSON (App\Support\CampaignRich::canvas). NULL = the look "Your own email" always had.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_campaigns', 'sales_campaign_templates'] as $t) {
            if (Schema::hasTable($t) && ! Schema::hasColumn($t, 'canvas')) {
                Schema::table($t, function (Blueprint $b) { $b->mediumText('canvas')->nullable(); });
            }
        }
    }

    public function down(): void
    {
        foreach (['sales_campaigns', 'sales_campaign_templates'] as $t) {
            if (Schema::hasColumn($t, 'canvas')) Schema::table($t, function (Blueprint $b) { $b->dropColumn('canvas'); });
        }
    }
};
