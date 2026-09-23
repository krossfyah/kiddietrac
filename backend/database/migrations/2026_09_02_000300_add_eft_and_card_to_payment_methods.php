<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * payments.method had no value for half the ways money actually arrives (2026-09-02).
 *
 * The enum was ('stripe_card','stripe_ach','interac','cash','cheque','manual'). It grew up
 * around Stripe, so it names the PROCESSOR for cards and the RAIL for everything else — and
 * once a second processor existed there was nowhere to put an EFT or a card taken through
 * Zum. Writing 'zum_interac' failed with "Data truncated for column 'method'".
 *
 * Two values added rather than a per-processor pair for each rail, because the rail is what
 * an accountant reconciles by: an EFT is an EFT whoever moved it. 'stripe_card' stays for
 * the existing rows.
 *
 * Also caught here: StripeParentPayController wrote method 'stripe', which was never a
 * member of this enum either — so that insert would have failed even after its column names
 * were corrected. Fixed in the controller to 'stripe_card', the value its own rows use.
 */
return new class extends Migration
{
    private const AFTER = "'stripe_card','stripe_ach','interac','eft','card','cash','cheque','manual'";
    private const BEFORE = "'stripe_card','stripe_ach','interac','cash','cheque','manual'";

    public function up(): void
    {
        DB::statement('ALTER TABLE payments MODIFY COLUMN method ENUM(' . self::AFTER . ') NOT NULL');
    }

    public function down(): void
    {
        // Anything using a value that is about to vanish becomes 'manual', which is the
        // honest fallback: the money arrived, and this column can no longer say how.
        DB::table('payments')->whereIn('method', ['eft', 'card'])->update(['method' => 'manual']);
        DB::statement('ALTER TABLE payments MODIFY COLUMN method ENUM(' . self::BEFORE . ') NOT NULL');
    }
};
