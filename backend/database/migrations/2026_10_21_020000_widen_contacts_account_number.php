<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* contacts.account_number is now stored encrypted (App\Support\Sensitive), and a sealed
   value is ~200+ characters: varchar(80) would truncate it. 2026-10-21. */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE `contacts` MODIFY `account_number` TEXT NULL');
    }

    public function down(): void
    {
        // Only safe once every value has been decrypted back to plaintext.
        DB::statement('ALTER TABLE `contacts` MODIFY `account_number` VARCHAR(80) NULL');
    }
};
