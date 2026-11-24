<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cold reach first emails wait in a queue and go out paced (ColdReach::run), never in one burst. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_prospects', 'queued_at')) {
                $t->timestamp('queued_at')->nullable()->index();
                $t->json('queued_email')->nullable();   // {campaign_id} or {subject, body}
                $t->unsignedBigInteger('queued_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) { $t->dropColumn(['queued_at', 'queued_email', 'queued_by']); });
    }
};
