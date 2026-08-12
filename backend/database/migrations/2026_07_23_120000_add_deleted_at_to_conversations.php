<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('conversations', 'deleted_at')) {
            return;
        }
        Schema::table('conversations', function (Blueprint $table) {
            $table->softDeletes();   // nullable deleted_at
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('conversations', 'deleted_at')) {
            return;
        }
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
