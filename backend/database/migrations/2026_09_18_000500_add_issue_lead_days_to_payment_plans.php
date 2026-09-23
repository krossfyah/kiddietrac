<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much notice THIS schedule gives, overriding the agency default.
 *
 * Anthony: "add an option in the pop up to indicate due immediately or can choose date
 * when are actually due (grace period)."
 *
 * The agency-wide setting (billing_setup.invoice_issue_lead_days, default 5) says how
 * many days before its due date an invoice goes out. That is the right default and the
 * wrong answer for a one-off: a schedule agreed today for money owed today should be due
 * on issue, and forcing it through the agency setting would mean changing the policy for
 * every family to bill one.
 *
 * NULL means "use the agency setting", which is not the same as 0. Zero is a deliberate
 * choice — issue it and it is due the same day — and a nullable column keeps the two
 * apart, where a 0 default would silently opt every existing schedule out of the policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payment_plans', 'issue_lead_days')) {
            return;
        }
        Schema::table('payment_plans', function (Blueprint $t) {
            $t->unsignedSmallInteger('issue_lead_days')->nullable()->after('line_items');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('payment_plans', 'issue_lead_days')) {
            return;
        }
        Schema::table('payment_plans', function (Blueprint $t) {
            $t->dropColumn('issue_lead_days');
        });
    }
};
