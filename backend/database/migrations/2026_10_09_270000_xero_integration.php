<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* XERO (2026-10-09). One settings row per agency (its own Xero app, the connected
   organisation, encrypted tokens, account mapping), a link table so every family /
   invoice / payment maps to exactly one Xero record per organisation (re-syncs update,
   never duplicate), and a sync log the settings screen shows. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agency_xero')) {
            Schema::create('agency_xero', function (Blueprint $t) {
                $t->unsignedBigInteger('agency_id')->primary();
                $t->string('client_id', 255)->nullable();
                $t->text('client_secret')->nullable();          // encrypted
                $t->string('scope_mode', 16)->default('granular');
                $t->string('tenant_id', 64)->nullable();
                $t->string('tenant_name', 255)->nullable();
                $t->text('tenants_json')->nullable();           // organisations offered at connect
                $t->text('access_token')->nullable();           // encrypted
                $t->text('refresh_token')->nullable();          // encrypted
                $t->dateTime('token_expires_at')->nullable();
                $t->unsignedBigInteger('connected_by_id')->nullable();
                $t->dateTime('connected_at')->nullable();
                $t->string('revenue_account_code', 20)->nullable();
                $t->string('subsidy_account_code', 20)->nullable();
                $t->string('payment_account_code', 20)->nullable();
                $t->string('tax_type', 40)->nullable();
                $t->string('invoice_status', 12)->default('AUTHORISED');
                $t->date('sync_from')->nullable();
                $t->boolean('auto_sync')->default(false);
                $t->boolean('sync_payments')->default(true);
                $t->dateTime('last_sync_at')->nullable();
                $t->text('last_error')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('xero_links')) {
            Schema::create('xero_links', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->string('tenant_id', 64);
                $t->string('kind', 16);                         // contact | invoice | payment
                $t->unsignedBigInteger('local_id');
                $t->string('xero_id', 64);
                $t->string('xero_number', 64)->nullable();
                $t->string('fingerprint', 64)->nullable();
                $t->string('xero_status', 20)->nullable();
                $t->dateTime('synced_at')->nullable();
                $t->unique(['agency_id', 'tenant_id', 'kind', 'local_id'], 'xero_link_once');
                $t->index('xero_id');
            });
        }
        if (! Schema::hasTable('xero_sync_log')) {
            Schema::create('xero_sync_log', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->string('kind', 16);
                $t->unsignedBigInteger('local_id')->nullable();
                $t->string('action', 16);                       // created | updated | unchanged | skipped | failed | voided | deleted
                $t->text('message')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->index(['agency_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('xero_sync_log');
        Schema::dropIfExists('xero_links');
        Schema::dropIfExists('agency_xero');
    }
};
