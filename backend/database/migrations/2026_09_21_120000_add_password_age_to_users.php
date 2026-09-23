<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HOW OLD IS THIS PASSWORD? (2026-09-21)
 *
 * Nothing recorded it. 81 active accounts and not one of them had a date against its
 * password, so "are we rotating every 90 days" could not even be answered, let alone
 * enforced. `must_change_password` existed, but that is the one-shot gate for a mailed
 * temporary password, not an age.
 *
 * BACKFILL, AND WHAT IT HAS TO BE. A NULL would read as "never changed" and expire
 * everybody at once - 81 people locked out of a Monday morning, including every educator
 * trying to sign children in. Existing accounts are stamped with the date the account was
 * created, which is the last moment we know something was set, and then STAGGERED so the
 * expiries do not all land on the same day.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'password_changed_at')) {
            Schema::table('users', function (Blueprint $t) {
                $t->timestamp('password_changed_at')->nullable()->after('password');
            });
        }
        if (! Schema::hasColumn('users', 'password_expiry_notified_at')) {
            Schema::table('users', function (Blueprint $t) {
                /* Which warning step this person has already had, as "<step>|<date>" -
                   e.g. "7|2026-09-21". A string rather than a timestamp because the
                   question is not WHEN we last wrote, it is WHICH warning they have
                   seen: that is what stops a nightly sweep becoming a nightly nag while
                   still letting 14 -> 7 -> 1 each send once. */
                $t->string('password_expiry_notified_at', 32)->nullable()->after('password_changed_at');
            });
        }

        /* Spread the first wave over 60 days rather than expiring the backlog at once.
           MOD on the id is deterministic - a re-run lands on the same day for the same
           person - and it means the first cycle is a trickle instead of a wall. */
        /* STAGGER BACKWARDS, NEVER FORWARDS (corrected 2026-09-21).

           This first stamped created_at PLUS up to 60 days. For an account created
           within the last two months that lands in the FUTURE - 17 accounts did, one of
           them dated seven weeks out - and a password with a future date has a negative
           age: it reads as more than 90 days remaining, so it never comes due, and the
           user-management field shows a "last changed" date that has not happened yet.

           A stagger has to be a subtraction. LEAST() pins the base at now, and the
           spread is then taken off it, so a stamp can never sit ahead of the clock. */
        DB::statement("
            UPDATE users
               SET password_changed_at = DATE_SUB(
                     LEAST(COALESCE(created_at, NOW()), NOW()),
                     INTERVAL (MOD(id, 60)) DAY)
             WHERE password_changed_at IS NULL
        ");

        /* Nobody should start already expired: an account created long ago would other-
           wise be 90+ days old the moment this lands. Anything that would already be past
           due is pulled forward to today, so everyone gets a full first cycle and the
           warning emails they are owed. */
        /* Nested rather than `INTERVAL a DAY - INTERVAL b DAY`: MariaDB rejects interval
           arithmetic inside DATE_ADD, which is how the first run of this failed. */
        DB::statement("
            UPDATE users
               SET password_changed_at = DATE_SUB(DATE_ADD(NOW(), INTERVAL (MOD(id, 60)) DAY), INTERVAL 60 DAY)
             WHERE password_changed_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
        ");
    }

    public function down(): void
    {
        foreach (['password_changed_at', 'password_expiry_notified_at'] as $c) {
            if (Schema::hasColumn('users', $c)) {
                Schema::table('users', function (Blueprint $t) use ($c) { $t->dropColumn($c); });
            }
        }
    }
};
