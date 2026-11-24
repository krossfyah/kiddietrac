<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Rows website demo visitors inserted (PublicDemoGuard), removed nightly by kt:reset-web-demo; Brightwood made public. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('web_demo_rows')) {
            Schema::create('web_demo_rows', function (Blueprint $t) {
                $t->id();
                $t->string('table_name', 64);
                $t->unsignedBigInteger('row_id');
                $t->timestamp('created_at')->nullable();
                $t->index(['table_name', 'row_id']);
            });
        }
        // Brightwood Early Learning is the public demo (only an agency already marked demo can be made public).
        $a = DB::table('agencies')->where('slug', 'brightwood')->orWhere('id', 25)->first(['id', 'settings']);
        if ($a) {
            $s = json_decode((string) $a->settings, true) ?: [];
            if (($s['demo'] ?? false) === true && ($s['demo_public'] ?? false) !== true) {
                $s['demo_public'] = true;
                DB::table('agencies')->where('id', $a->id)->update(['settings' => json_encode($s)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('web_demo_rows');
    }
};
