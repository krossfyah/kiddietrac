<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Donations & fundraising (2026-09-29). Campaigns, donations (pledged or received) and
   donation receipts. A receipt is a SNAPSHOT: donor, amounts and charity details are copied
   at issue time, because an official receipt must never change after it is given -- it
   can only be voided, and replaced by a new serial that says which one it replaces. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('donation_campaigns')) {
            Schema::create('donation_campaigns', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->string('title', 120);
                $t->string('slug', 80)->unique();
                $t->text('description')->nullable();
                $t->decimal('goal_amount', 10, 2)->nullable();
                $t->date('starts_on')->nullable();
                $t->date('ends_on')->nullable();
                $t->string('status', 12)->default('active');      // draft | active | closed
                $t->boolean('show_progress')->default(true);
                $t->string('suggested_amounts', 120)->nullable();   // "25,50,100,250"
                $t->text('thank_you_message')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('donations')) {
            Schema::create('donations', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('campaign_id')->nullable()->index();
                $t->unsignedBigInteger('family_id')->nullable()->index();
                $t->string('donor_first_name', 80);
                $t->string('donor_last_name', 80)->nullable();
                $t->string('donor_email', 160)->nullable()->index();
                $t->string('donor_phone', 40)->nullable();
                $t->string('address_line1', 160)->nullable();
                $t->string('city', 80)->nullable();
                $t->string('province', 40)->nullable();
                $t->string('postal_code', 20)->nullable();
                $t->string('country', 40)->nullable();
                $t->decimal('amount', 10, 2);
                $t->decimal('advantage_amount', 10, 2)->default(0);   // value of anything the donor received back
                $t->string('currency', 3)->default('CAD');
                $t->string('method', 16);                            // cash | cheque | etransfer | card | other
                $t->string('status', 12)->default('received');       // pledged | received | cancelled
                $t->date('received_on')->nullable();
                $t->string('reference', 80)->nullable();
                $t->boolean('anonymous')->default(false);
                $t->text('message')->nullable();
                $t->text('notes')->nullable();
                $t->string('source', 12)->default('admin');          // admin | public
                $t->unsignedBigInteger('recorded_by_id')->nullable();
                $t->string('ip', 45)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('donation_receipts')) {
            Schema::create('donation_receipts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('donation_id')->index();
                $t->string('serial', 30);
                $t->string('kind', 16);                              // official | acknowledgement
                $t->decimal('amount', 10, 2);
                $t->decimal('advantage_amount', 10, 2)->default(0);
                $t->decimal('eligible_amount', 10, 2);
                $t->string('currency', 3)->default('CAD');
                $t->date('received_on');
                $t->date('issued_on');
                $t->string('issued_at_location', 80)->nullable();
                $t->string('donor_name', 170);
                $t->string('donor_address', 300)->nullable();
                $t->string('donor_email', 160)->nullable();
                $t->longText('charity');                             // JSON snapshot: legal name, address, number, signatory
                $t->string('status', 12)->default('issued');         // issued | void
                $t->string('void_reason', 200)->nullable();
                $t->unsignedBigInteger('replaces_receipt_id')->nullable();
                $t->unsignedBigInteger('replaced_by_receipt_id')->nullable();
                $t->timestamp('emailed_at')->nullable();
                $t->string('email_status', 12)->nullable();
                $t->unsignedBigInteger('issued_by_id')->nullable();
                $t->timestamps();
                $t->unique(['agency_id', 'serial']);
            });
        }
        foreach (['donation_campaigns', 'donations', 'donation_receipts'] as $tb) {
            DB::statement("ALTER TABLE {$tb} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('donation_receipts');
        Schema::dropIfExists('donations');
        Schema::dropIfExists('donation_campaigns');
    }
};
