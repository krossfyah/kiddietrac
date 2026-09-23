<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HELCIM AS A CARD PROVIDER (2026-09-21)
 *
 * Two things, and the ENUM is the one that bites.
 *
 * `payments.method` is an ENUM. MySQL does not reject an unlisted value with a helpful
 * error - it throws, and the request 500s. That has already happened once here, when four
 * of six payment methods could not be recorded because nobody widened the column. A
 * Helcim payment written as 'helcim_card' against the old list would fail at exactly the
 * worst moment: after the card has been charged and before the payment is recorded, which
 * is money taken and not credited.
 *
 * So the column is widened FIRST, in its own statement, before any code that writes it
 * ships. 'card' already exists and would have worked, but it cannot tell a Helcim charge
 * from a Zum one - and a refund has to know which provider to call.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* Rebuilt from the current definition rather than assumed: appending to the wrong
           list would silently drop a value that is already in use. */
        $col = DB::selectOne("SHOW COLUMNS FROM payments WHERE Field = 'method'");
        if ($col && stripos((string) $col->Type, 'helcim_card') === false) {
            preg_match_all("/'([^']+)'/", (string) $col->Type, $m);
            $values = $m[1] ?? [];
            if ($values) {
                $values[] = 'helcim_card';
                $list = implode(',', array_map(fn ($v) => "'" . $v . "'", $values));
                DB::statement("ALTER TABLE payments MODIFY method ENUM({$list}) NOT NULL");
            }
        }

        if (! Schema::hasTable('helcim_checkouts')) {
            Schema::create('helcim_checkouts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('family_id')->index();
                $t->unsignedBigInteger('invoice_id')->nullable()->index();
                $t->unsignedBigInteger('user_id');

                /* The browser's half of the session. Unique because it is what complete()
                   looks a payment up by, and two rows sharing one token would make a
                   double-posted result ambiguous rather than idempotent. */
                $t->string('checkout_token', 120)->unique();

                /* The server's half, and the reason this table exists at all: it proves a
                   result genuine and MUST NOT reach the browser. Held only for the life of
                   one payment. */
                $t->string('secret_token', 200)->nullable();

                $t->decimal('amount', 10, 2);
                $t->string('currency', 3)->default('CAD');
                $t->string('status', 16)->default('open');   // open|paid|failed|rejected
                $t->unsignedBigInteger('transaction_id')->nullable()->index();
                $t->unsignedBigInteger('payment_id')->nullable()->index();

                /* Helcim's handle for the card, not the card. It cannot be charged outside
                   this agency's own Helcim account, and it is what lets a later refund be
                   tied back to the card it came from. */
                $t->string('card_token', 120)->nullable();

                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('helcim_checkouts');
        // The ENUM value is deliberately left in place: dropping it would break any
        // payment row already recorded against it.
    }
};
