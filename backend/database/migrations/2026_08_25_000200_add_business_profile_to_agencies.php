<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A business address on the agency profile (2026-08-25).
 *
 * The agency record held a name, a contact email and a phone number — and nothing that
 * identifies the business on a document. So "pull the full business info from the agency
 * profile" had nothing to pull: every invoice could print the agency's NAME and stop.
 *
 * legal_name is separate from name on purpose. `name` is what the portal shows everywhere
 * ("iLearn Home Childcare"); an invoice frequently has to carry the registered entity
 * ("iLearn Home Childcare Inc."), and forcing one field to be both means either the UI
 * reads like a filing or the invoice is addressed to something that is not the legal
 * entity. Null legal_name falls back to name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $t) {
            foreach ([
                'legal_name' => 180,
                'address_line1' => 180,
                'address_line2' => 180,
                'city' => 120,
                'province' => 120,
                'postal_code' => 24,
                'country' => 80,
                'website' => 180,
            ] as $col => $len) {
                if (! Schema::hasColumn('agencies', $col)) {
                    $t->string($col, $len)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $t) {
            $t->dropColumn(['legal_name', 'address_line1', 'address_line2',
                'city', 'province', 'postal_code', 'country', 'website']);
        });
    }
};
