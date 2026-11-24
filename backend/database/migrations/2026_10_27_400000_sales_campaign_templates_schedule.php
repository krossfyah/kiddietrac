<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Sales -> Campaigns carries the KiddieTrac subscriber emails too (2026-10-27): `template` (null = the prospect intro
   email; 'onetap' / 'halloween' = App\Support\SubscriberCampaign), and approve + schedule: the scheduler starts a
   campaign at `scheduled_for` (UTC) only once it is approved. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_campaigns', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_campaigns', 'template')) $t->string('template', 40)->nullable()->after('consent_basis');
            if (! Schema::hasColumn('sales_campaigns', 'scheduled_for')) $t->dateTime('scheduled_for')->nullable()->index();
            if (! Schema::hasColumn('sales_campaigns', 'approved_at')) $t->dateTime('approved_at')->nullable();
            if (! Schema::hasColumn('sales_campaigns', 'approved_by')) $t->unsignedBigInteger('approved_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_campaigns', function (Blueprint $t) {
            foreach (['template', 'scheduled_for', 'approved_at', 'approved_by'] as $c) {
                if (Schema::hasColumn('sales_campaigns', $c)) $t->dropColumn($c);
            }
        });
    }
};
