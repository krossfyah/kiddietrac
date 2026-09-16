<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WHO IS SENDING THIS, AND WHOSE SWITCHES APPLY.
 *
 * The suppression gate decides whether to send by looking at the RECIPIENT'S accounts.
 * One email address can hold accounts in several agencies (by design — one person, many
 * roles, many tenants), so without being told which tenant is sending, the gate has to
 * judge the address against every one of them and any single agency with its master
 * switch OFF cancels the mail. That is not theoretical: Test Agency's OFF switch silently
 * killed iLearn's provider-welcome emails to a shared address, and they still showed as
 * sent in the audit log. See [[kiddietrac-mail-gate-agency-scoping]].
 *
 * So every send declares one of exactly two things:
 *
 *   agency()/centre()  — TENANT mail. "This is agency N writing." The gate then applies
 *                        only agency N's switches and ignores the recipient's accounts
 *                        in other tenants.
 *
 *   platform()         — PLATFORM mail: KiddieTrac writing as itself (password resets,
 *                        signup, sales, security alerts, the agency's own onboarding).
 *                        No tenant owns it, so no tenant's switch may silence it.
 *                        This skips the agency/centre/room SWITCHES ONLY — never the
 *                        person's own unsubscribe or a suppressed address.
 *
 * A header must never be the reason a real email fails to go out, so every method here
 * swallows its own errors.
 */
class MailScope
{
    /** Tenant mail: stamp the sending agency. */
    public static function agency($message, ?int $agencyId): void
    {
        if (! $agencyId) {
            return;
        }
        try {
            $message->getHeaders()->addTextHeader('X-KT-Agency-Id', (string) $agencyId);
        } catch (\Throwable $e) {
            Log::warning('MailScope::agency could not stamp: ' . $e->getMessage());
        }
    }

    /** Tenant mail where only the centre is in hand. */
    public static function centre($message, ?int $centreId): void
    {
        if (! $centreId) {
            return;
        }
        try {
            self::agency($message, self::agencyOfCentre($centreId));
        } catch (\Throwable $e) {
            Log::warning('MailScope::centre could not stamp: ' . $e->getMessage());
        }
    }

    /** KiddieTrac writing as itself — no tenant's master switch may silence it. */
    public static function platform($message): void
    {
        try {
            $message->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
        } catch (\Throwable $e) {
            Log::warning('MailScope::platform could not stamp: ' . $e->getMessage());
        }
    }

    /** The agency a centre belongs to, memoised per request. */
    public static function agencyOfCentre(?int $centreId): ?int
    {
        static $cache = [];
        if (! $centreId) {
            return null;
        }
        if (array_key_exists($centreId, $cache)) {
            return $cache[$centreId];
        }
        $id = DB::table('centres')->where('id', $centreId)->value('agency_id');

        return $cache[$centreId] = $id ? (int) $id : null;
    }

    /** The agency a user belongs to, for sends that only hold a user. */
    public static function agencyOfUser(?int $userId): ?int
    {
        if (! $userId) {
            return null;
        }
        try {
            return Suppression::agencyOfUser($userId);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
