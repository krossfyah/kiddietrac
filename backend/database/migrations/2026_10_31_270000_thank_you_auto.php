<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Automatic monthly thank-you awards (App\Support\ThankYouAuto): one run per agency+month, one draft per educator+month. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('thank_you_auto_runs')) {
            Schema::create('thank_you_auto_runs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->char('month', 7);
                $t->string('mode', 12);
                $t->unsignedInteger('qualified')->default(0);
                $t->unsignedInteger('drafts')->default(0);
                $t->unsignedInteger('sent')->default(0);
                $t->timestamps();
                $t->unique(['agency_id', 'month']);
            });
        }
        if (! Schema::hasTable('thank_you_auto_drafts')) {
            Schema::create('thank_you_auto_drafts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('run_id')->nullable()->index();
                $t->unsignedBigInteger('user_id');
                $t->char('month', 7);
                $t->integer('points')->default(0);
                $t->text('letter')->nullable();
                $t->boolean('letter_ai')->default(false);
                $t->text('message')->nullable();
                $t->string('signer', 20)->nullable();
                $t->string('status', 12)->default('pending');   // pending | sending | sent | failed | skipped | expired
                $t->unsignedBigInteger('reward_id')->nullable();
                $t->unsignedBigInteger('decided_by_id')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->timestamps();
                $t->unique(['agency_id', 'user_id', 'month']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('thank_you_auto_drafts');
        Schema::dropIfExists('thank_you_auto_runs');
    }
};
