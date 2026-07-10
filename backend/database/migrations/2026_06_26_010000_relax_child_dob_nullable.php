<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integration support: a child's date_of_birth may be unknown when first fed
 * from an external agency platform, so allow null (it can be filled in later in
 * KiddieTrac). Backward-compatible: existing rows already have a value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $t) {
            $t->date('date_of_birth')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Leaving nullable on rollback is safe; no destructive change.
    }
};
