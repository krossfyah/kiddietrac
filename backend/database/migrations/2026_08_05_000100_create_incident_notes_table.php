<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * incident_notes — staff-internal, append-only notes/details on an incident with
 * a full audit trail (author + timestamp + IP). Not visible to guardians.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            // Keep the note even if the author's user record is later removed.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name')->nullable();   // denormalised snapshot of who wrote it
            $table->text('note');
            $table->string('ip_address', 64)->nullable();
            $table->timestamps();
            $table->index(['incident_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_notes');
    }
};
