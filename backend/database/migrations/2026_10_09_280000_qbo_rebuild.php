<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* QUICKBOOKS, REBUILT ON THE XERO MODEL (2026-10-09). The old integration kept plaintext
   tokens on `agencies` and could not push an invoice at all (it read invoices.agency_id,
   which does not exist). No agency had connected it. Settings, encrypted tokens, links
   (so re-syncs update instead of duplicating customers) and a sync log now live here; the
   old qbo_* columns on agencies are left in place, unused. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agency_qbo')) {
            Schema::create('agency_qbo', function (Blueprint $t) {
                $t->unsignedBigInteger('agency_id')->primary();
                $t->string('client_id', 255)->nullable();
                $t->text('client_secret')->nullable();          // encrypted
                $t->string('environment', 12)->default('production');
                $t->string('realm_id', 32)->nullable();
                $t->string('company_name', 255)->nullable();
                $t->string('country', 4)->nullable();
                $t->text('access_token')->nullable();           // encrypted
                $t->text('refresh_token')->nullable();          // encrypted
                $t->dateTime('token_expires_at')->nullable();
                $t->unsignedBigInteger('connected_by_id')->nullable();
                $t->dateTime('connected_at')->nullable();
                $t->string('income_item_id', 32)->nullable();
                $t->string('subsidy_item_id', 32)->nullable();
                $t->string('deposit_account_id', 32)->nullable();
                $t->string('tax_code_exempt', 32)->nullable();
                $t->string('tax_code_taxable', 32)->nullable();
                $t->date('sync_from')->nullable();
                $t->boolean('auto_sync')->default(false);
                $t->boolean('sync_payments')->default(true);
                $t->dateTime('last_sync_at')->nullable();
                $t->text('last_error')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('qbo_links')) {
            Schema::create('qbo_links', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->string('realm_id', 32);
                $t->string('kind', 16);                         // customer | invoice | payment
                $t->unsignedBigInteger('local_id');
                $t->string('qbo_id', 32);
                $t->string('qbo_number', 64)->nullable();
                $t->string('fingerprint', 64)->nullable();
                $t->string('qbo_status', 20)->nullable();       // open | paid | voided | deleted
                $t->dateTime('synced_at')->nullable();
                $t->unique(['agency_id', 'realm_id', 'kind', 'local_id'], 'qbo_link_once');
            });
        }
        if (! Schema::hasTable('qbo_sync_log')) {
            Schema::create('qbo_sync_log', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->string('kind', 16);
                $t->unsignedBigInteger('local_id')->nullable();
                $t->string('action', 16);
                $t->text('message')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->index(['agency_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('qbo_sync_log');
        Schema::dropIfExists('qbo_links');
        Schema::dropIfExists('agency_qbo');
    }
};
