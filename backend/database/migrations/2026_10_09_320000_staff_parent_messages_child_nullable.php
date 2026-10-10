<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* Messenger's "also send by text / email" can come from a thread not tied to one child. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE staff_parent_messages MODIFY child_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
    }
};
