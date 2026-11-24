<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Who has received which subscriber campaign (App\Support\SubscriberCampaign), so a re-run sends nothing twice. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('subscriber_campaign_sends')) {
            Schema::create('subscriber_campaign_sends', function (Blueprint $t) {
                $t->id();
                $t->string('campaign', 40);
                $t->string('email', 190);
                $t->dateTime('sent_at');
                $t->unique(['campaign', 'email']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriber_campaign_sends');
    }
};
