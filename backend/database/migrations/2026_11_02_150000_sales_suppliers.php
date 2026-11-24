<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac's OWN supplier list for Sales → Expenses (2026-11-02, Anthony: "add a expense in sales -
 * can you make it where I can add a supplier and allow to choose this supplier in the vendor drop down").
 * Platform-level, like platform_expenses: nothing here belongs to an agency. The agency-side Finance →
 * Expenses suppliers (table `suppliers`, agency-scoped) are a different thing and are not touched.
 *
 * name_key = lower(trim(name)) and is unique, so "Staples" and "staples " are one supplier.
 * Expenses keep their `vendor` text (Top vendors and search read it); supplier_id links the row so a
 * rename can carry through. Seeded once from the vendor names already used, so none disappear.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('sales_suppliers')) {
            Schema::create('sales_suppliers', function (Blueprint $t) {
                $t->id();
                $t->string('name', 160);
                $t->string('name_key', 160)->unique();
                $t->string('website', 255)->nullable();
                $t->string('email', 190)->nullable();
                $t->string('phone', 40)->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('archived_at')->nullable();
                $t->unsignedBigInteger('archived_by')->nullable();
                $t->timestamps();
            });
        }
        foreach (['platform_expenses', 'platform_expense_recurring'] as $tbl) {
            if (Schema::hasTable($tbl) && ! Schema::hasColumn($tbl, 'supplier_id')) {
                Schema::table($tbl, function (Blueprint $t) {
                    $t->unsignedBigInteger('supplier_id')->nullable()->after('vendor');
                    $t->index('supplier_id');
                });
            }
        }

        // Seed from the vendor names already in use (most-used spelling wins), then link the rows.
        $seen = [];
        foreach (['platform_expenses', 'platform_expense_recurring'] as $tbl) {
            if (! Schema::hasTable($tbl)) continue;
            foreach (DB::table($tbl)->whereNotNull('vendor')->where('vendor', '<>', '')->selectRaw('TRIM(vendor) v, COUNT(*) n')->groupByRaw('TRIM(vendor)')->get() as $r) {
                $name = mb_substr(trim((string) $r->v), 0, 160);
                if ($name === '') continue;
                $k = mb_strtolower($name);
                if (! isset($seen[$k]) || $r->n > $seen[$k]['n']) $seen[$k] = ['name' => $name, 'n' => ($seen[$k]['n'] ?? 0) + (int) $r->n];
            }
        }
        foreach ($seen as $k => $s) {
            if (! DB::table('sales_suppliers')->where('name_key', $k)->exists()) {
                DB::table('sales_suppliers')->insert(['name' => $s['name'], 'name_key' => $k, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        foreach (DB::table('sales_suppliers')->get(['id', 'name_key']) as $s) {
            foreach (['platform_expenses', 'platform_expense_recurring'] as $tbl) {
                if (! Schema::hasTable($tbl)) continue;
                DB::table($tbl)->whereNull('supplier_id')->whereRaw('LOWER(TRIM(vendor)) = ?', [$s->name_key])->update(['supplier_id' => $s->id]);
            }
        }
    }

    public function down(): void
    {
        foreach (['platform_expenses', 'platform_expense_recurring'] as $tbl) {
            if (Schema::hasTable($tbl) && Schema::hasColumn($tbl, 'supplier_id')) {
                Schema::table($tbl, function (Blueprint $t) { $t->dropIndex(['supplier_id']); $t->dropColumn('supplier_id'); });
            }
        }
        Schema::dropIfExists('sales_suppliers');
    }
};
