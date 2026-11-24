<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * SERVICE-DESK TICKETS (2026-10-21). Anthony: "raise a ticket should have categories and
 * better input to allowing the user to enter in information - mimic jira service desk".
 *
 *   request_type  what the person chose ("Report a problem", "Maintenance", ...). category
 *                 stays, derived from it, so routing and every existing report keep working.
 *   details       the answers to that type's own questions, as [{label, value}] in the order
 *                 they were asked, so the ticket reads back exactly as it was filled in.
 *   context       what the portal captured on its own (screen, device, app version), kept
 *                 apart from what the person typed.
 *   impact        the plain-language answer the priority was derived from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $t) {
            if (! Schema::hasColumn('support_tickets', 'request_type')) {
                $t->string('request_type', 40)->nullable()->after('category');
            }
            if (! Schema::hasColumn('support_tickets', 'impact')) {
                $t->string('impact', 40)->nullable()->after('priority');
            }
            if (! Schema::hasColumn('support_tickets', 'details')) {
                $t->longText('details')->nullable()->after('body');
            }
            if (! Schema::hasColumn('support_tickets', 'context')) {
                $t->longText('context')->nullable()->after('details');
            }
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $t) {
            foreach (['request_type', 'impact', 'details', 'context'] as $c) {
                if (Schema::hasColumn('support_tickets', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
