<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Who a counter-signed form was shared with (staff Documents + typed addresses), so the
   Completed tab in Forms Manager can say so. 2026-10-20. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('managed_form_signoffs', function (Blueprint $t) {
            if (! Schema::hasColumn('managed_form_signoffs', 'shared_at')) {
                $t->timestamp('shared_at')->nullable();
            }
            if (! Schema::hasColumn('managed_form_signoffs', 'shared_to')) {
                $t->string('shared_to', 1000)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('managed_form_signoffs', function (Blueprint $t) {
            $t->dropColumn(['shared_at', 'shared_to']);
        });
    }
};
