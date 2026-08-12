<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Short, typeable check-in codes. The QR still encodes the day's KTCHK code, but
 * a fresh 6-char code is minted each time an educator opens the check-in QR so a
 * parent whose camera fails can type a short code instead of the long KTCHK string
 * — and each code is unique, so manual check-ins are traceable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('checkin_short_codes')) return;
        Schema::create('checkin_short_codes', function ($t) {
            $t->bigIncrements('id');
            $t->string('code', 12)->unique();
            $t->unsignedBigInteger('centre_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedInteger('used_count')->default(0);
            $t->timestamp('expires_at')->nullable()->index();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkin_short_codes');
    }
};
