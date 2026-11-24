<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 2026-11-03 (Anthony: "Could not save: The signature html field must not be greater than 20000 characters").
 * A signature pasted from Outlook or Word carries its logo as an embedded picture, which on its own is bigger than
 * the old 20,000-character limit and the 64 KB TEXT column. MEDIUMTEXT holds it; the API limit matches a message body.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $t) {
            $t->mediumText('signature_html')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Left as MEDIUMTEXT: shrinking it back would cut off any signature saved since.
    }
};
