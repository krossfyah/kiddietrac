<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Careers outcomes (2026-11-24): book a meeting / keep on file for 24 months / decline politely. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('career_applications', function (Blueprint $t) {
            if (! Schema::hasColumn('career_applications', 'keep_until')) $t->date('keep_until')->nullable()->after('status');   // kept on file: delete after this date
            if (! Schema::hasColumn('career_applications', 'meeting_id')) $t->unsignedBigInteger('meeting_id')->nullable()->after('keep_until');
            if (! Schema::hasColumn('career_applications', 'last_email_kind')) $t->string('last_email_kind', 20)->nullable()->after('meeting_id');
            if (! Schema::hasColumn('career_applications', 'last_emailed_at')) $t->timestamp('last_emailed_at')->nullable()->after('last_email_kind');
        });
    }

    public function down(): void
    {
        Schema::table('career_applications', function (Blueprint $t) {
            foreach (['keep_until', 'meeting_id', 'last_email_kind', 'last_emailed_at'] as $c) {
                if (Schema::hasColumn('career_applications', $c)) $t->dropColumn($c);
            }
        });
    }
};
