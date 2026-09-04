<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The agency's contact book — the drawer of business cards, digitised.
 *
 * Deliberately NOT emergency_contacts, which already exists and belongs to a CHILD:
 * who to call about Aria. This is the agency's own directory — the plumber, the food
 * inspector, the insurance broker, the landlord — people no child record should own
 * and who currently live in somebody's phone.
 *
 * AGENCY-SCOPED, with an optional centre. A contact belongs to the agency; naming a
 * centre narrows it to the site that actually uses them (the plumber for the Mono
 * house is not the plumber for the Shelburne one) without hiding it from the agency.
 *
 * Everything except a name is optional. A contact book that refuses a card because it
 * has no postcode is a contact book people stop using — the fields exist so the detail
 * CAN be kept, not so it must be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('agency_id')->index();
            // Null = the whole agency. Set = the site that actually deals with them.
            $table->unsignedBigInteger('centre_id')->nullable()->index();

            /* What this person is TO US. Free text rather than an enum: every agency
               keeps a different drawer, and a schema change to add "window cleaner"
               is a schema change nobody will make. Suggested values are offered by
               the UI and existing ones are proposed back as you type. */
            $table->string('category', 60)->nullable()->index();

            $table->string('first_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->string('company', 160)->nullable();
            $table->string('job_title', 120)->nullable();

            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('mobile', 40)->nullable();
            $table->string('website', 190)->nullable();
            // How they would rather be reached — a plumber who never reads email.
            $table->string('preferred_contact', 16)->nullable();

            $table->string('address_line1', 190)->nullable();
            $table->string('address_line2', 190)->nullable();
            $table->string('city', 90)->nullable();
            $table->string('province', 90)->nullable();
            $table->string('postal_code', 24)->nullable();
            $table->string('country', 90)->nullable();

            /* The things that make a contact useful a year later, when the person who
               added them has left: which account we are on their books, what licence
               they hold, when they can be called. */
            $table->string('account_number', 80)->nullable();
            $table->string('licence_number', 80)->nullable();
            $table->string('hours', 120)->nullable();

            $table->text('notes')->nullable();
            // Free tags, searched alongside the name.
            $table->json('tags')->nullable();

            /* The card itself. Kept even when every field was typed by hand — it is
               the evidence behind the entry, and the thing somebody recognises. */
            $table->string('card_image_url', 500)->nullable();
            $table->string('photo_url', 500)->nullable();

            // Ring first in an emergency; pinned to the top of the list.
            $table->boolean('is_emergency')->default(false)->index();
            $table->boolean('is_favourite')->default(false);
            $table->date('last_contacted_on')->nullable();

            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();
            // Soft-deleted: a contact removed in error is a phone number nobody has.
            $table->softDeletes();

            $table->index(['agency_id', 'category']);
            $table->index(['agency_id', 'company']);
            $table->index(['agency_id', 'last_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
