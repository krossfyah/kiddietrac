<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Grant tracking (2026-09-29): operating, wage-enhancement and other funding a centre
   must spend and report on, beyond CWELCC fee reduction. A grant is awarded for a period;
   transactions record money received, money spent (by category, optionally tied to an
   expense bill), adjustments and funds returned. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('grants')) {
            Schema::create('grants', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable()->index();     // null = agency-wide
                $t->string('name', 160);
                $t->string('funder', 160)->nullable();
                $t->string('program_type', 30)->default('operating');
                $t->string('reference', 80)->nullable();
                $t->date('period_start');
                $t->date('period_end');
                $t->decimal('amount_awarded', 12, 2);
                $t->date('reporting_due')->nullable();
                $t->text('notes')->nullable();
                $t->string('status', 12)->default('active');
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('grant_transactions')) {
            Schema::create('grant_transactions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('grant_id')->index();
                $t->string('kind', 12);                                      // received | spent | adjustment | returned
                $t->date('txn_date');
                $t->decimal('amount', 12, 2);
                $t->string('category', 30)->nullable();
                $t->string('description', 250)->nullable();
                $t->string('reference', 80)->nullable();
                $t->unsignedBigInteger('expense_invoice_id')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
            });
        }
        foreach (['grants', 'grant_transactions'] as $tb) {
            DB::statement("ALTER TABLE {$tb} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('grant_transactions');
        Schema::dropIfExists('grants');
    }
};
