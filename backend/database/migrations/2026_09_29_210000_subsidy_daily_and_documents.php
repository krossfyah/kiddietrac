<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Provincial subsidies: a daily OR monthly amount, and the government's documents
   (2026-09-29). Anthony: "provincial subsidy popup should have option to add daily
   amount or monthly amount and ability to upload document as well from the province or
   government".

   amount_basis 'daily' uses daily_amount x the child's scheduled care days in the month
   (App\Support\SubsidyAmount); 'monthly' keeps monthly_amount. Existing rows are monthly.

   subsidy_documents: files kept on the private disk (never public), downloaded through
   an authenticated, scope-checked route. Removing one hides it (removed_at) and keeps
   the file, because the approval letter is the evidence behind the invoices. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subsidies', function (Blueprint $t) {
            if (! Schema::hasColumn('subsidies', 'amount_basis')) {
                $t->string('amount_basis', 10)->default('monthly')->after('monthly_amount');
            }
            if (! Schema::hasColumn('subsidies', 'daily_amount')) {
                $t->decimal('daily_amount', 10, 2)->nullable()->after('amount_basis');
            }
        });
        DB::table('subsidies')->whereNull('amount_basis')->update(['amount_basis' => 'monthly']);

        if (! Schema::hasTable('subsidy_documents')) {
            Schema::create('subsidy_documents', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('subsidy_id')->index();
                $t->unsignedBigInteger('family_id')->nullable()->index();
                $t->string('path', 255);
                $t->string('original_name', 255);
                $t->string('mime', 120)->nullable();
                $t->unsignedInteger('size')->default(0);
                $t->unsignedBigInteger('uploaded_by_id')->nullable();
                $t->timestamp('removed_at')->nullable();
                $t->unsignedBigInteger('removed_by_id')->nullable();
                $t->timestamps();
            });
            DB::statement('ALTER TABLE subsidy_documents CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subsidy_documents');
        Schema::table('subsidies', function (Blueprint $t) {
            foreach (['daily_amount', 'amount_basis'] as $c) {
                if (Schema::hasColumn('subsidies', $c)) { $t->dropColumn($c); }
            }
        });
    }
};
