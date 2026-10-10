<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* staff_certifications.cert_type: ADD the types the jurisdiction catalogues use (2026-10-09).
   The API already accepted Vulnerable_Sector_Check, Health_Card and Other, but the column
   did not, so under STRICT_TRANS_TABLES those saves failed outright. Values are only
   added, never removed or reordered (existing rows keep their index). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE staff_certifications MODIFY cert_type ENUM("
            . "'RECE','ECE_Assistant','First_Aid','CPR','Food_Handler','VSC','WHMIS','custom',"
            . "'Vulnerable_Sector_Check','Health_Card','Other','Child_Abuse_Training','Communicable_Disease','Orientation','OPIN'"
            . ") NOT NULL");
    }

    public function down(): void
    {
    }
};
