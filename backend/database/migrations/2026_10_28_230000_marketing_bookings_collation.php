<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* marketing_bookings was created as utf8mb4_general_ci; sales_campaign_recipients (and 274 of 329 tables) are
   utf8mb4_unicode_ci. Joining them on email failed with "Illegal mix of collations" (tickets 188/189), so the
   sales-campaigns job could never mark a recipient who went on to book. Same charset, so no data changes. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE marketing_bookings CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE marketing_bookings CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    }
};
