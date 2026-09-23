<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Announcements: who it goes to, and a picture to go with it.
 *
 * AUDIENCE. Until now `recipientUserIds()` returned guardians for every scope, so an
 * announcement could only ever reach parents — an educator or a director never received
 * one, whatever the compose screen implied. `audience` adds the missing dimension
 * alongside scope: the scope says WHERE, the audience says WHO.
 *
 * The default is deliberately `parents`, not `all`. Every announcement written before
 * today went to parents only, and a migration must not retroactively change who was
 * spoken to.
 *
 * CONTRACTOR. There is no existing concept of one — no staff table, no employment type,
 * and the roles in use are guardian / educator / agency_admin. So it is an explicit flag
 * set per person rather than something inferred from a role that does not mean it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            if (! Schema::hasColumn('announcements', 'audience')) {
                // parents | educators | contractors | admins | all
                $table->string('audience', 20)->default('parents')->after('scope_id');
            }
            if (! Schema::hasColumn('announcements', 'image_path')) {
                // Public path under the API's web root. Carriers fetch MMS media over
                // plain HTTP with no credentials, so it HAS to be reachable without a
                // login — the filename is a long random token, which is what keeps it
                // from being guessable. Same approach as the walk maps.
                $table->string('image_path', 300)->nullable()->after('body');
            }
        });

        if (! Schema::hasColumn('users', 'is_contractor')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_contractor')->default(false)->after('sms_opt_in');
            });
        }
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            foreach (['audience', 'image_path'] as $c) {
                if (Schema::hasColumn('announcements', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        if (Schema::hasColumn('users', 'is_contractor')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('is_contractor');
            });
        }
    }
};
