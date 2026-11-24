<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Sign in with Microsoft for Email client mailboxes (support ticket #157):
   Microsoft 365 refuses a password over IMAP, so those mailboxes are read through
   Graph with the person's own Microsoft sign-in. See App\Support\MicrosoftMailbox. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $t) {
            if (! Schema::hasColumn('email_accounts', 'auth_type')) $t->string('auth_type', 20)->default('password')->after('secret');
            if (! Schema::hasColumn('email_accounts', 'oauth_token')) $t->text('oauth_token')->nullable()->after('auth_type');
            if (! Schema::hasColumn('email_accounts', 'oauth_identity')) $t->string('oauth_identity', 190)->nullable()->after('oauth_token');
            if (! Schema::hasColumn('email_accounts', 'oauth_connected_at')) $t->timestamp('oauth_connected_at')->nullable()->after('oauth_identity');
        });
    }

    public function down(): void
    {
        Schema::table('email_accounts', function (Blueprint $t) {
            $t->dropColumn(['auth_type', 'oauth_token', 'oauth_identity', 'oauth_connected_at']);
        });
    }
};
