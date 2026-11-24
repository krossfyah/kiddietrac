<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per daily summary sent (or deliberately skipped) per person per day - App\Support\SummaryTiming.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('summary_sends')) return;
        Schema::create('summary_sends', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 12);                 // parent (subject = child id) | educator (subject = user id)
            $t->unsignedBigInteger('subject_id');
            $t->date('for_date');                   // the agency-local day the summary is about
            $t->string('how', 16)->nullable();      // due | run | backfill
            $t->timestamp('created_at')->nullable();
            $t->unique(['kind', 'subject_id', 'for_date']);
            $t->index('for_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('summary_sends');
    }
};
