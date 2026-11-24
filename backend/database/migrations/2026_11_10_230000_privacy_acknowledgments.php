<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRIVACY NOTICE ACKNOWLEDGMENTS (2026-11-10, PIPEDA review). The Data retention screen had a "Require parent consent
 * at enrolment" switch that nothing read: no parent was ever shown the agency's privacy notice and nothing was
 * recorded. One row per parent, per agency, per notice version they confirmed reading (App\Http\Controllers\Api\
 * PrivacyNoticeController). Never deleted: it is the evidence that the notice was given.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('privacy_acknowledgments')) return;
        Schema::create('privacy_acknowledgments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('agency_id');
            $t->unsignedInteger('version');
            $t->string('notice_url', 300)->nullable();               // what was linked when they confirmed
            $t->string('source', 20)->default('portal');
            $t->timestamp('acknowledged_at');
            $t->unique(['user_id', 'agency_id', 'version']);
            $t->index(['agency_id', 'version']);
        });
    }

    public function down(): void
    {
        // Deliberately empty: these rows are the record that each parent was given the notice.
    }
};
