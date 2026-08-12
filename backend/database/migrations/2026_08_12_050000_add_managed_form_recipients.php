<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Named recipients for a managed form.
 *
 * Until now a form was addressed to whole ROLES ("all guardians", "all educators").
 * Often the real need is narrower: this consent for these three families, this
 * checklist for one home visitor. A form can now carry named recipients ALONGSIDE
 * its role audiences — assignment is "your role is in the audience OR you were
 * named individually", so the existing behaviour is untouched when no one is named.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('managed_form_recipients')) {
            return;
        }
        Schema::create('managed_form_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('managed_form_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('created_at')->nullable();
            $table->unique(['managed_form_id', 'user_id'], 'mfr_form_user_unique');
            $table->index('user_id', 'mfr_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_form_recipients');
    }
};
