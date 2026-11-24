<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* A FORM CAN BELONG TO ONE CENTRE (2026-11-13). Centre directors got Forms Manager, scoped to their own
   centres (Anthony: "Scoped to their centres"). NULL = an agency-wide form, which is every form that existed
   before today - nothing about them changes. A director's upload is tied to their centre, and
   App\Support\FormAudience then offers it only to that centre's people. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('managed_forms', 'centre_id')) {
            Schema::table('managed_forms', function (Blueprint $t) {
                $t->unsignedBigInteger('centre_id')->nullable()->after('agency_id');
                $t->index('centre_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('managed_forms', 'centre_id')) {
            Schema::table('managed_forms', function (Blueprint $t) {
                $t->dropIndex(['centre_id']);
                $t->dropColumn('centre_id');
            });
        }
    }
};
