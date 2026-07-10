<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('children', 'school')) {
            Schema::table('children', function (Blueprint $t) {
                $t->string('school', 160)->nullable()->after('cultural_notes');
            });
        }

        if (! Schema::hasTable('emergency_contacts')) {
            Schema::create('emergency_contacts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('family_id')->index();
                $t->string('name', 120);
                $t->string('relationship', 60)->nullable();
                $t->string('phone', 40)->nullable();
                $t->string('alt_phone', 40)->nullable();
                $t->boolean('can_pickup')->default(false);
                $t->text('notes')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_contacts');
        if (Schema::hasColumn('children', 'school')) {
            Schema::table('children', function (Blueprint $t) { $t->dropColumn('school'); });
        }
    }
};
