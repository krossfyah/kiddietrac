<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of a partner platform's (iLearn) waitlist, plus KiddieTrac-originated
 * waitlist leads — one shared, lightweight table so the two systems stay in
 * 2-way sync. `origin` records where a row was CREATED (never flips), which is
 * how each side knows what it may push vs. what it must not echo back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('external_waitlist')) {
            return;
        }
        Schema::create('external_waitlist', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->string('origin', 20)->default('ilearn');           // ilearn | kiddietrac (where it was created)
            $table->string('external_source', 40)->default('ilearn');  // integration source slug
            $table->string('external_id', 191);                        // stable id from the origin system
            $table->string('child_name', 191)->nullable();
            $table->date('child_dob')->nullable();
            $table->string('age_group', 60)->nullable();
            $table->string('parent_name', 191)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('phone', 60)->nullable();
            $table->date('desired_start')->nullable();
            $table->string('days_needed', 120)->nullable();
            $table->string('status', 40)->default('Waiting');
            $table->string('priority', 40)->nullable();
            $table->string('source', 80)->nullable();
            $table->integer('position')->default(0);
            $table->text('notes')->nullable();
            $table->string('area_of_interest', 191)->nullable();
            $table->dateTime('external_updated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['agency_id', 'external_source', 'external_id'], 'ext_waitlist_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_waitlist');
    }
};
