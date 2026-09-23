<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closing an account must close the roles it holds (2026-09-02).
 *
 * Every off-boarding path already set users.status to 'deactivated' or 'suspended', and
 * the login gate honours that — so a closed account genuinely cannot sign in. What no path
 * did was touch role_assignments, so 7 of 11 closed accounts were still carrying an active
 * role: a de-boarded family's guardian was, on paper, still a guardian of that agency.
 * Nothing leaked, because the readers that matter run through Audience::excludeOff() — but
 * "safe only because every reader remembers a second check" is the shape of the next leak,
 * and it is why User management could show a closed account sitting in a role list.
 *
 * The column is what makes it reversible. Restoring a family re-activates the assignments
 * that the closure took, and only those: a role an administrator had deliberately revoked
 * before the off-boarding has a null stamp and stays revoked. Without it, "reactivate"
 * would have to guess, and guessing here hands someone back access they were denied.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('role_assignments', 'closed_with_account_at')) {
            return;
        }
        Schema::table('role_assignments', function (Blueprint $table) {
            $table->timestamp('closed_with_account_at')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('role_assignments', 'closed_with_account_at')) {
            return;
        }
        Schema::table('role_assignments', function (Blueprint $table) {
            $table->dropColumn('closed_with_account_at');
        });
    }
};
