<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record when a platform invoice was emailed, and to whom.
 *
 * Without this, "did we ever send this invoice?" had no answer in the data — issued_at
 * only records that somebody clicked Issue, which sends nothing. It also guards deletion:
 * a draft that has already reached a customer must not be deletable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_invoices', function (Blueprint $t) {
            if (! Schema::hasColumn('platform_invoices', 'sent_at')) {
                $t->timestamp('sent_at')->nullable()->after('issued_at');
            }
            if (! Schema::hasColumn('platform_invoices', 'sent_to')) {
                $t->string('sent_to', 190)->nullable()->after('sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('platform_invoices', function (Blueprint $t) {
            $t->dropColumn(['sent_at', 'sent_to']);
        });
    }
};
