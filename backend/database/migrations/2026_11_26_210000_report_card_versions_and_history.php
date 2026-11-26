<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report card versions, reference numbers and history (2026-11-26).
 *
 * Anthony: editing a SENT card makes a new version that is approved again. The earlier one is kept and marked
 * Replaced once the new one is approved. replaces_id points a version at the one it revises; replaced_by_id is set
 * on the old one when the new one is approved. ref_no is the per-agency visible number (RefCode, "ILH-R-0007").
 * report_card_events is each card's own history: made, edited, submitted, approved, sent back, emailed (to whom).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_cards', function (Blueprint $t) {
            if (! Schema::hasColumn('report_cards', 'version')) {
                $t->unsignedSmallInteger('version')->default(1)->after('term');
            }
            if (! Schema::hasColumn('report_cards', 'replaces_id')) {
                $t->unsignedBigInteger('replaces_id')->nullable()->index()->after('version');
            }
            if (! Schema::hasColumn('report_cards', 'replaced_by_id')) {
                $t->unsignedBigInteger('replaced_by_id')->nullable()->after('replaces_id');
            }
            if (! Schema::hasColumn('report_cards', 'replaced_at')) {
                $t->timestamp('replaced_at')->nullable()->after('replaced_by_id');
            }
            if (! Schema::hasColumn('report_cards', 'ref_no')) {
                $t->unsignedInteger('ref_no')->nullable()->after('replaced_at');
                $t->unsignedBigInteger('ref_agency_id')->nullable()->after('ref_no');
            }
        });

        if (! Schema::hasTable('report_card_events')) {
            Schema::create('report_card_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('report_card_id')->index();
                $t->unsignedBigInteger('agency_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('action', 24);
                $t->text('detail')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_events');
        Schema::table('report_cards', function (Blueprint $t) {
            foreach (['version', 'replaces_id', 'replaced_by_id', 'replaced_at', 'ref_no', 'ref_agency_id'] as $c) {
                if (Schema::hasColumn('report_cards', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
