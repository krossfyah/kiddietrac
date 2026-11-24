<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cold reach batches (2026-10-31, Anthony: "I want the batch that I uploaded to be in a batch in the table vs individual
 * so it's better to manage"). Every import is one batch; prospects added by hand have none. Existing imports are grouped
 * into batches by list name + the minute they were imported.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_prospect_batches')) {
            Schema::create('sales_prospect_batches', function (Blueprint $t) {
                $t->id();
                $t->string('name', 160);
                $t->string('source_file', 255)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasColumn('sales_prospects', 'batch_id')) {
            Schema::table('sales_prospects', function (Blueprint $t) { $t->unsignedBigInteger('batch_id')->nullable()->index()->after('id'); });
        }
        $groups = DB::table('sales_prospects')->whereNull('batch_id')->where('source', 'import')
            ->selectRaw("list_name, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') m, MIN(created_at) first_at, MIN(created_by) by_user")
            ->groupBy('list_name', 'm')->get();
        foreach ($groups as $g) {
            $name = $g->list_name ?: ('Import ' . date('M j, Y', strtotime($g->first_at)));
            if ($g->list_name === null && DB::table('sales_prospects')->where('source', 'import')->whereNull('list_name')->count() === 6325) {
                $name = 'Ontario child care prospects';   // Anthony's Ontario_Child_Care_Prospects workbook (6,325 rows), 2026-10-30
            }
            $bid = DB::table('sales_prospect_batches')->insertGetId(['name' => $name, 'created_by' => $g->by_user, 'created_at' => $g->first_at, 'updated_at' => now()]);
            DB::table('sales_prospects')->whereNull('batch_id')->where('source', 'import')
                ->where(fn ($q) => $g->list_name === null ? $q->whereNull('list_name') : $q->where('list_name', $g->list_name))
                ->whereRaw("DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') = ?", [$g->m])
                ->update(['batch_id' => $bid]);
        }
    }

    public function down(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) { $t->dropColumn('batch_id'); });
        Schema::dropIfExists('sales_prospect_batches');
    }
};
