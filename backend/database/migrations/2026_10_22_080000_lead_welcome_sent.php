<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* When a lead was sent the welcome email (App\Support\LeadWelcome), so it goes once per
   address however many forms or chats they come through (2026-10-22). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_subscribers') && ! Schema::hasColumn('site_subscribers', 'lead_welcome_sent_at')) {
            Schema::table('site_subscribers', function (Blueprint $t) {
                $t->dateTime('lead_welcome_sent_at')->nullable()->after('subscribed_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('site_subscribers', 'lead_welcome_sent_at')) {
            Schema::table('site_subscribers', function (Blueprint $t) {
                $t->dropColumn('lead_welcome_sent_at');
            });
        }
    }
};
