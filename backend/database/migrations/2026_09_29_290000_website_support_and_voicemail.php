<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Website support tickets + toll-free voicemail (2026-09-29). A website ticket has no
   agency and no user, so the requester's own details are stored on the ticket. Voicemails
   are kept (with the downloaded recording) and emailed to info@. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('support_tickets', 'requester_email')) {
            Schema::table('support_tickets', function (Blueprint $t) {
                $t->string('source', 20)->nullable()->after('status');
                $t->string('requester_name', 120)->nullable()->after('source');
                $t->string('requester_email', 160)->nullable()->after('requester_name');
                $t->string('requester_phone', 40)->nullable()->after('requester_email');
                $t->string('requester_org', 160)->nullable()->after('requester_phone');
            });
        }
        if (! Schema::hasTable('voicemails')) {
            Schema::create('voicemails', function (Blueprint $t) {
                $t->id();
                $t->string('recording_sid', 100)->unique();
                $t->string('call_sid', 100)->nullable();
                $t->string('from_number', 40)->nullable();
                $t->string('to_number', 40)->nullable();
                $t->unsignedInteger('duration')->default(0);
                $t->string('recording_url', 1000)->nullable();
                $t->string('stored_path', 300)->nullable();
                $t->timestamp('emailed_at')->nullable();
                $t->string('email_status', 12)->nullable();
                $t->timestamps();
            });
            DB::statement('ALTER TABLE voicemails CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('voicemails');
        if (Schema::hasColumn('support_tickets', 'requester_email')) {
            Schema::table('support_tickets', function (Blueprint $t) {
                $t->dropColumn(['source', 'requester_name', 'requester_email', 'requester_phone', 'requester_org']);
            });
        }
    }
};
