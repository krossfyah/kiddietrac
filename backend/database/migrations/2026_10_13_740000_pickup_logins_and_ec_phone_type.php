<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* 2026-10-13: emergency contacts get a typed second number (+ ext), an email and an optional
   pick-up login (App\Support\PickupLogin); guardians.billing_hidden marks such a login. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emergency_contacts', function (Blueprint $t) {
            if (! Schema::hasColumn('emergency_contacts', 'alt_phone_type')) $t->string('alt_phone_type', 12)->nullable();
            if (! Schema::hasColumn('emergency_contacts', 'alt_phone_ext')) $t->string('alt_phone_ext', 10)->nullable();
            if (! Schema::hasColumn('emergency_contacts', 'email')) $t->string('email', 180)->nullable();
            if (! Schema::hasColumn('emergency_contacts', 'user_id')) $t->unsignedBigInteger('user_id')->nullable()->index();
        });
        Schema::table('guardians', function (Blueprint $t) {
            if (! Schema::hasColumn('guardians', 'billing_hidden')) $t->boolean('billing_hidden')->default(false);
        });
    }

    public function down(): void
    {
        foreach (['alt_phone_type', 'alt_phone_ext', 'email', 'user_id'] as $c) {
            if (Schema::hasColumn('emergency_contacts', $c)) Schema::table('emergency_contacts', fn (Blueprint $t) => $t->dropColumn($c));
        }
        if (Schema::hasColumn('guardians', 'billing_hidden')) Schema::table('guardians', fn (Blueprint $t) => $t->dropColumn('billing_hidden'));
    }
};
