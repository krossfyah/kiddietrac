<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* CALIFORNIA (2026-10-28). Two things the US-CA profile needs from the schema:
   - background_checks.expires_at may be empty: a California clearance (Live Scan, CACI) does not expire — the state
     sends subsequent-arrest notices instead (H&SC 1596.871). household_resident_checks already allowed it.
   - staff_certifications.cert_type gains the California training codes. Values are only added, never removed or
     reordered (existing rows keep their index), as in 2026_10_09_340000_cert_types. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE background_checks MODIFY expires_at DATE NULL');
        DB::statement("ALTER TABLE staff_certifications MODIFY cert_type ENUM("
            . "'RECE','ECE_Assistant','First_Aid','CPR','Food_Handler','VSC','WHMIS','custom',"
            . "'Vulnerable_Sector_Check','Health_Card','Other','Child_Abuse_Training','Communicable_Disease','Orientation','OPIN',"
            . "'TB_Clearance','Staff_Immunizations','Mandated_Reporter','Health_Safety_15','ECE_Units','CD_Permit'"
            . ") NOT NULL");
    }

    public function down(): void
    {
    }
};
