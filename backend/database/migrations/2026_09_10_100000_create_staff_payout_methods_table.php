<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a member of staff is PAID — the detail payroll needs and nobody else does.
 *
 * Until now the profile screen offered staff the parent AUTOPAY page: a place to hand
 * the centre a card so it could charge them for childcare. Exactly backwards for
 * somebody the centre owes money to.
 *
 * ONE ROW PER PERSON, not per agency. A payout method belongs to the human being; an
 * educator working at two agencies is paid into the same account by both, and asking
 * them to key their bank details in twice is how one copy goes stale. agency_id records
 * where it was first entered, for scoping the admin list.
 *
 * SEPARATE TABLE ON PURPOSE. These columns are the most sensitive in the product, and
 * `users` is selected with `->get()` in dozens of places. Keeping them out of that table
 * means a careless select can never carry them into a response.
 *
 * ENCRYPTED AT REST, and never returned. The *_enc columns hold Crypt::encryptString
 * output; the portal reads them only when an admin explicitly asks to issue a payout,
 * and that read is audited. The plain `*_hint` columns exist so both the owner and the
 * payroll list can confirm WHICH account is on file — "•••• 4821" — without anything
 * being decrypted to draw a screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_payout_methods', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('agency_id')->nullable()->index();

            // interac = e-Transfer to an email address. direct_deposit = a bank account.
            $table->enum('method', ['interac', 'direct_deposit'])->default('interac');

            /* The name the money is paid to. NOT encrypted: it is a payee name, it has to
               be legible on a payment run, and it is already on the payroll document. */
            $table->string('legal_name', 160)->nullable();

            $table->text('interac_email_enc')->nullable();
            $table->string('interac_email_hint', 120)->nullable();   // n•••@example.com

            $table->text('institution_number_enc')->nullable();
            $table->text('transit_number_enc')->nullable();
            $table->text('account_number_enc')->nullable();
            $table->string('account_hint', 40)->nullable();          // •••• 4821

            /* Who last changed it. A payout destination changing is the single most
               attractive thing to tamper with in a payroll system. */
            $table->unsignedBigInteger('updated_by_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_payout_methods');
    }
};
