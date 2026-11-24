<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Status history for website job applications (2026-11-02, CareersController::stats).
 * career_applications only keeps the CURRENT status and one updated_at, so "how long until someone
 * looked at it" and "how long to hire" could not be measured. Every status change is now one row here.
 * Existing applications get their "new" row at the time they arrived; nothing else is back-filled
 * (their earlier changes were never recorded, and are not invented).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('career_application_events')) {
            Schema::create('career_application_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('application_id');
                $t->string('from_status', 20)->nullable();
                $t->string('to_status', 20);
                $t->unsignedBigInteger('user_id')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['application_id', 'created_at']);
                $t->index('to_status');
            });
        }
        if (Schema::hasTable('career_applications')) {
            $have = DB::table('career_application_events')->pluck('application_id')->all();
            $rows = DB::table('career_applications')->whereNotIn('id', $have ?: [0])->get(['id', 'created_at']);
            foreach ($rows as $r) {
                DB::table('career_application_events')->insert([
                    'application_id' => $r->id, 'from_status' => null, 'to_status' => 'new',
                    'user_id' => null, 'created_at' => $r->created_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('career_application_events');
    }
};
