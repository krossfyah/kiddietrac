<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Giftbit orders on educator rewards (2026-10-20): which account bought the card (the agency's
   own, or the platform default), Giftbit's order and gift ids, and when the status was last
   checked so a page view never polls more than once every few minutes. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('educator_rewards', function (Blueprint $t) {
            if (! Schema::hasColumn('educator_rewards', 'provider_account')) {
                $t->string('provider_account', 16)->nullable()->after('provider');
            }
            if (! Schema::hasColumn('educator_rewards', 'provider_campaign_uuid')) {
                $t->string('provider_campaign_uuid', 64)->nullable()->after('provider_order_id');
            }
            if (! Schema::hasColumn('educator_rewards', 'provider_gift_uuid')) {
                $t->string('provider_gift_uuid', 64)->nullable()->after('provider_campaign_uuid');
            }
            if (! Schema::hasColumn('educator_rewards', 'provider_checked_at')) {
                $t->timestamp('provider_checked_at')->nullable()->after('provider_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('educator_rewards', function (Blueprint $t) {
            $t->dropColumn(['provider_account', 'provider_campaign_uuid', 'provider_gift_uuid', 'provider_checked_at']);
        });
    }
};
