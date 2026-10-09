<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* SAGE (2026-10-09, Anthony: "can you wire up sage integration as well" — both products).
   Sage Accounting (cloud) syncs live like Xero/QuickBooks: agency_sage + sage_links +
   sage_sync_log. Sage 50 (desktop) has no API, so it gets journal import files; what has
   been exported is remembered (sage50_exports / sage50_exported) so the next file holds
   only what is new, plus reversals for voids and refunds. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agency_sage')) {
            Schema::create('agency_sage', function (Blueprint $t) {
                $t->unsignedBigInteger('agency_id')->primary();
                // Sage Accounting (cloud)
                $t->string('client_id', 255)->nullable();
                $t->text('client_secret')->nullable();          // encrypted
                $t->string('business_id', 64)->nullable();
                $t->string('business_name', 255)->nullable();
                $t->string('country', 4)->nullable();
                $t->text('businesses_json')->nullable();
                $t->text('access_token')->nullable();           // encrypted
                $t->text('refresh_token')->nullable();          // encrypted
                $t->dateTime('token_expires_at')->nullable();
                $t->unsignedBigInteger('connected_by_id')->nullable();
                $t->dateTime('connected_at')->nullable();
                $t->string('ledger_account_id', 64)->nullable();
                $t->string('subsidy_ledger_account_id', 64)->nullable();
                $t->string('bank_account_id', 64)->nullable();
                $t->string('tax_rate_exempt', 64)->nullable();
                $t->string('tax_rate_taxable', 64)->nullable();
                $t->date('sync_from')->nullable();
                $t->boolean('auto_sync')->default(false);
                $t->boolean('sync_payments')->default(true);
                $t->dateTime('last_sync_at')->nullable();
                $t->text('last_error')->nullable();
                // Sage 50 (desktop) — account numbers for the journal file
                $t->string('s50_receivable', 15)->nullable();
                $t->string('s50_revenue', 15)->nullable();
                $t->string('s50_subsidy', 15)->nullable();
                $t->string('s50_discount', 15)->nullable();
                $t->string('s50_tax', 15)->nullable();
                $t->string('s50_bank', 15)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('sage_links')) {
            Schema::create('sage_links', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->string('business_id', 64);
                $t->string('kind', 16);                         // contact | invoice | payment
                $t->unsignedBigInteger('local_id');
                $t->string('sage_id', 64);
                $t->string('sage_number', 64)->nullable();
                $t->string('fingerprint', 64)->nullable();
                $t->string('sage_status', 20)->nullable();
                $t->dateTime('synced_at')->nullable();
                $t->unique(['agency_id', 'business_id', 'kind', 'local_id'], 'sage_link_once');
            });
        }
        if (! Schema::hasTable('sage_sync_log')) {
            Schema::create('sage_sync_log', function (Blueprint $t) {
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
        if (! Schema::hasTable('sage50_exports')) {
            Schema::create('sage50_exports', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->date('date_from')->nullable();
                $t->date('date_to')->nullable();
                $t->unsignedInteger('entries')->default(0);
                $t->decimal('total_debits', 12, 2)->default(0);
                $t->longText('content');
                $t->dateTime('created_at')->nullable();
                $t->index(['agency_id', 'created_at']);
            });
        }
        if (! Schema::hasTable('sage50_exported')) {
            Schema::create('sage50_exported', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->string('kind', 16);                         // invoice | payment
                $t->unsignedBigInteger('local_id');
                $t->unsignedBigInteger('export_id');
                $t->unsignedBigInteger('reversed_export_id')->nullable();
                $t->text('lines_json')->nullable();                 // the account => amount lines last exported (for adjustments and reversals)
                $t->unique(['agency_id', 'kind', 'local_id'], 'sage50_once');
            });
        }
    }

    public function down(): void
    {
        foreach (['sage50_exported', 'sage50_exports', 'sage_sync_log', 'sage_links', 'agency_sage'] as $t) Schema::dropIfExists($t);
    }
};
