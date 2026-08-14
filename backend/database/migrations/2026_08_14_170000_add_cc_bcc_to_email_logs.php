<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * email_logs recorded only the primary recipient, so every copied address was invisible
 * — including the agency admins and directors that access-removal, suspension and
 * de-enrolment notices are deliberately BCC'd to. "Was the director copied?" is the
 * question a BCC exists to answer, and the log could not answer it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $t) {
            if (! Schema::hasColumn('email_logs', 'cc'))  $t->text('cc')->nullable()->after('to_name');
            if (! Schema::hasColumn('email_logs', 'bcc')) $t->text('bcc')->nullable()->after('cc');
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $t) {
            if (Schema::hasColumn('email_logs', 'cc'))  $t->dropColumn('cc');
            if (Schema::hasColumn('email_logs', 'bcc')) $t->dropColumn('bcc');
        });
    }
};
