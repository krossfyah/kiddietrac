<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * child_awards — educators recognise a child's effort (Star of the Week, etc.)
 * on a daily / weekly / monthly cadence. Parents can see them; educators can
 * print a certificate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('child_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('centre_id')->nullable();
            $table->foreignId('awarded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');                 // e.g. "Star of the Week"
            $table->string('badge', 16)->nullable(); // emoji badge
            $table->string('period', 12)->nullable();// daily | weekly | monthly
            $table->text('note')->nullable();
            $table->date('awarded_on')->nullable();
            $table->timestamps();
            $table->index(['child_id', 'awarded_on']);
            $table->index('centre_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('child_awards');
    }
};
