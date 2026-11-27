<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * AN AGENCY'S DAY-TO-DAY NOTICES GO TO ITS OWN PEOPLE (2026-11-26, Anthony, after the escalated daily records sweep:
 * "leave super admins out of all agency day-to-day notices").
 *
 * An OUTSIDER (AgencySignatories::outsiderIds: platform admins, integration accounts, reseller grants) can hold an
 * agency_admin row for access. Every sender that picks "the agency's admins and directors" by role rows therefore
 * picked Anthony too: clock-ins, Safe Arrival, absences, the daily summary, countersign, immunizations, drills,
 * attendance corrections, certificates, time off, schedules, payments, auto sign-off, What's New, OCCMS, tasks...
 * (about 500 iLearn emails and 1,000 bell rows in 30 days).
 *
 * ONE RULE, enforced where things leave, so a new sender cannot forget it:
 *   - bell + its push:  Notify::write asks keepsRow();
 *   - direct pushes:    FcmService::sendToUser asks blocksPush();
 *   - email:            SuppressAgencyMail asks mailDrops() for any message stamped with an agency (X-KT-Agency-Id);
 *   - role-built lists: CentreScope::managers and MailOversight::bccFor leave outsiders out at the source.
 *
 * What still reaches an outsider (the allow-list):
 *   - platform work: support tickets, sales, bookings, proposals, meetings, the platform mailbox, campaigns;
 *   - person-to-person: chat, messages, team messages, nudges, calls;
 *   - their own account: sign-in alerts, password reset / expiry, account notices, invites, payslip, statement;
 *   - privacy breach and privacy request alerts (legal duty; Anthony: KEEP);
 *   - anything they asked for themselves in this request ([TEST] / X-KT-Preview sends, a send they triggered);
 *   - notices that reach them as a PARENT in that agency (family-side types, guardian there);
 *   - a safety notice for an agency that has NO admin or director of its own (never nobody).
 * Nightly guard: kt:notify-audience-check fails on an agency bell row to an outsider in the last 24h.
 */
final class AgencyOutsiders
{
    /** Bell types that are platform or person-to-person, never an agency's day-to-day notice. */
    public const PLATFORM_TYPES = [
        'support_ticket', 'email', 'proposal_opened', 'booking_request', 'meeting', 'cold_reach',
        'chat', 'message', 'team_message', 'staff_message', 'nudge', 'call', 'call_missed', 'broadcast',
    ];

    /** Safety notices: if an agency has no admin or director of its own, an outsider still gets them. */
    public const SAFETY_TYPES = [
        'safe_arrival', 'ratio_breach', 'children_still_in', 'headcount_due', 'headcount_strike', 'incident',
        'walk_quiet', 'walk_auto_closed', 'sleep_check', 'running_late', 'late_pickup', 'pickup_alarm',
    ];

    /** Senders (class short names, or prefixes ending in *) whose mail / direct push may reach an outsider. */
    public const PERSONAL_SENDERS = [
        // platform + sales
        'TicketAlerts', 'TicketSlaAlerts', 'SupportTicketController', 'WebsiteSupportController', 'Sales*', 'Proposal*', 'Booking*',
        'Lead*', 'ColdReach*', 'Platform*', 'Reseller*', 'Engagement*', 'MailWatchCommand', 'TestEmail', 'EmailPreviews',
        'Rsvp*', 'Meeting*', 'Company',
        // person-to-person
        'ChatController', 'MessageController', 'TeamChatController', 'WebChatController', 'VoiceMessageController',
        'ChatNudge', 'ChatCall', 'CallAlerts', 'MissedMessagesCommand', 'EmailMissedMessagesCommand',
        // their own account
        'PasswordResetEmail', 'AccountNotice', 'SignInAlert', 'SecurityAlerts', 'PasswordExpiryCommand', 'AccountController',
        'AuthController', 'SignupController', 'Mfa*', 'Passkey*', 'TwoFactor*', 'OnboardingReminderCommand', 'SmsConsentInviteController',
        'PayslipNotices', 'PushController',
        // legal duty (Anthony 2026-11-26: keep super admins on privacy breach alerts)
        'PrivacyBreachAlerts', 'PrivacyRequest*',
        // the bell's own push of a row Notify::write already judged
        'Notify',
    ];

    /** Push categories that are family-side: a guardian somewhere keeps these direct pushes. */
    private const FAMILY_CATEGORIES = ['checkin', 'photos', 'billing', 'forms'];

    private static ?array $ids = null;
    private static array $parent = [];
    private static array $managers = [];
    /** @var array<string,int> what was held back in this process, for proofs and logs */
    public static array $held = [];

    public static function ids(): array
    {
        return self::$ids ??= AgencySignatories::outsiderIds();
    }

    public static function isOutsider(int $userId): bool
    {
        return $userId > 0 && in_array($userId, self::ids(), true);
    }

    /** Leave outsiders out of a role-built recipient query (alias of the users table). */
    public static function exclude($query, string $col = 'u.id')
    {
        $ids = self::ids();
        return $ids ? $query->whereNotIn($col, $ids) : $query;
    }

    /** Does this person reach this agency as a parent (guardian of a family at one of its centres)? */
    public static function parentIn(int $userId, ?int $agencyId): bool
    {
        $k = $userId . '|' . (int) $agencyId;
        if (! array_key_exists($k, self::$parent)) {
            try {
                $q = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
                    ->join('centres as c', 'c.id', '=', 'f.centre_id')->where('g.user_id', $userId);
                if ($agencyId) $q->where('c.agency_id', $agencyId);
                else $q->whereNotIn('c.agency_id', self::testAgencyIds());
                self::$parent[$k] = $q->exists();
            } catch (\Throwable $e) {
                self::$parent[$k] = false;
            }
        }
        return self::$parent[$k];
    }

    private static function testAgencyIds(): array
    {
        try {
            return DB::table('agencies')->where('settings', 'like', '%"demo":true%')->pluck('id')->map(fn ($v) => (int) $v)->all() ?: [0];
        } catch (\Throwable $e) {
            return [0];
        }
    }

    /** Does the agency have at least one admin or director of its own (so a safety notice is never nobody's)? */
    public static function hasOwnManagers(?int $agencyId): bool
    {
        if (! $agencyId) return true;
        if (! array_key_exists($agencyId, self::$managers)) {
            try {
                self::$managers[$agencyId] = CentreScope::managers($agencyId, Live::centreIds($agencyId))
                    ->whereIn('u.status', ['active'])->exists();
            } catch (\Throwable $e) {
                self::$managers[$agencyId] = false;   // unknown: keep the outsider on safety notices
            }
        }
        return self::$managers[$agencyId];
    }

    private static function note(string $channel, int $userId, string $what): void
    {
        $k = $channel . ':' . $what;
        self::$held[$k] = (self::$held[$k] ?? 0) + 1;
        try { \Illuminate\Support\Facades\Log::info('Outsider held back', ['channel' => $channel, 'user' => $userId, 'what' => $what]); } catch (\Throwable $e) {}
    }

    /* ── bell (+ its push) ─────────────────────────────────────────────────────────── */

    /** Notify::write asks for each row (agency already resolved). false = drop the row and its push. */
    public static function keepsRow(array $r, array $data): bool
    {
        $uid = (int) ($r['user_id'] ?? 0);
        if (! self::isOutsider($uid)) return true;
        $type = (string) ($r['type'] ?? '');
        if (in_array($type, self::PLATFORM_TYPES, true)) return true;
        if (! empty($r['__platform']) || ! empty($data['platform'])) return true;
        if ($type === 'compliance' && str_starts_with((string) ($data['kind'] ?? ''), 'privacy')) return true;   // legal duty
        $agencyId = ! empty($r['agency_id']) ? (int) $r['agency_id'] : null;
        if (! $agencyId) return true;   // platform-level or unknown: not an agency notice
        if (NotifyAudience::ofType($type) !== 'staff' && self::parentIn($uid, $agencyId)) return true;
        if (in_array($type, self::SAFETY_TYPES, true) && ! self::hasOwnManagers($agencyId)) return true;
        self::note('bell', $uid, $type);
        return false;
    }

    /** When the rule went live: rows written before it are not a regression. */
    public const SINCE = '2026-11-27 03:00:00';

    /**
     * Nightly guard (kt:notify-audience-check): agency bell rows that reached an outsider in the last 24 hours and
     * that keepsRow() would refuse today. Non-empty = a sender got round Notify::write, or the rule regressed.
     *
     * @return array<string,int> type => rows
     */
    public static function leaks24h(): array
    {
        $ids = self::ids();
        if (! $ids) return [];
        $from = max(now()->subDay()->toDateTimeString(), self::SINCE);
        $out = [];
        foreach (DB::table('notifications')->whereIn('user_id', $ids)->where('created_at', '>=', $from)
                     ->whereNotNull('agency_id')->whereNotIn('type', self::PLATFORM_TYPES)
                     ->get(['user_id', 'type', 'agency_id', 'data']) as $n) {
            $data = is_string($n->data) ? (json_decode($n->data, true) ?: []) : [];
            $row = ['user_id' => (int) $n->user_id, 'type' => (string) $n->type, 'agency_id' => (int) $n->agency_id];
            $before = self::$held;
            if (! self::keepsRow($row, $data)) $out[$n->type] = ($out[$n->type] ?? 0) + 1;
            self::$held = $before;
        }
        return $out;
    }

    /* ── direct pushes ─────────────────────────────────────────────────────────────── */

    /** The calling class's short name, skipping the plumbing. */
    public static function caller(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $f) {
            $cls = (string) ($f['class'] ?? '');
            if ($cls === '') continue;
            $short = substr($cls, (int) strrpos($cls, '\\') + 1);
            if (in_array($short, ['AgencyOutsiders', 'FcmService', 'WebPushService', 'SuppressAgencyMail', 'NotificationSounds', 'PushAudit'], true)) continue;
            if (str_starts_with($cls, 'Illuminate\\') || str_starts_with($cls, 'Symfony\\')) continue;
            if (in_array($short, ['AgencyMailer', 'MailScope', 'EmailTemplate', 'AgencyMail', 'MailSend', 'AgencyRouterTransport'], true)) continue;
            return $short;
        }
        return '';
    }

    public static function personalSender(string $short): bool
    {
        if ($short === '') return false;
        foreach (self::PERSONAL_SENDERS as $p) {
            if (str_ends_with($p, '*') ? str_starts_with($short, substr($p, 0, -1)) : $short === $p) return true;
        }
        return false;
    }

    /** FcmService asks before a push: true = do not ring this outsider's phone. */
    public static function blocksPush(int $userId, string $title = ''): bool
    {
        if (! self::isOutsider($userId)) return false;
        try {
            if (TicketAlerts::allowsUser($userId)) return false;
            $who = self::caller();
            if (self::personalSender($who)) return false;
            $cat = NotificationSounds::categoryFromCaller();
            if (in_array($cat, ['chat', 'nudge', 'call'], true)) return false;
            if (in_array($cat, self::FAMILY_CATEGORIES, true) && self::parentIn($userId, null)) return false;
            if ($cat === 'safety' && self::anyAgencyWithoutManagers($userId)) return false;
            self::note('push', $userId, $who ?: 'unknown');
            return true;
        } catch (\Throwable $e) {
            return false;   // never break a push over this
        }
    }

    /** An outsider holding a role in an agency that has nobody of its own keeps that agency's safety pushes. */
    private static function anyAgencyWithoutManagers(int $userId): bool
    {
        foreach (DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)->whereNotNull('agency_id')
                     ->whereIn('role', ['agency_admin', 'centre_director'])->pluck('agency_id') as $a) {
            if (! self::hasOwnManagers((int) $a)) return true;
        }
        return false;
    }

    /* ── email ─────────────────────────────────────────────────────────────────────── */

    /**
     * Addresses to take off an agency-stamped message (SuppressAgencyMail). [] = leave it alone.
     * An address is dropped only when an outsider holds it and none of its accounts is one of the agency's own
     * people (staff via ownStaff, or a guardian there).
     *
     * @param \Symfony\Component\Mime\Email $message
     */
    public static function mailDrops($message, array $recipients, int $agencyId, array $flags = []): array
    {
        if (! $recipients || ! self::ids()) return [];
        try {
            $lower = array_map(fn ($e) => mb_strtolower(trim((string) $e)), $recipients);
            $accounts = DB::table('users')->whereIn(DB::raw('LOWER(email)'), $lower)->whereNull('deleted_at')->get(['id', 'email']);
            if (! $accounts->contains(fn ($a) => in_array((int) $a->id, self::ids(), true))) return [];   // the usual case: nothing to do

            $h = $message->getHeaders();
            foreach (['X-KT-Platform', 'X-KT-Account-Notice', 'X-KT-Invite', 'X-KT-Invite-User', 'X-KT-Onboarding-Invite',
                         'X-KT-Preview', 'X-KT-Campaign', 'X-KT-Engagement'] as $hk) {
                if ($h && $h->has($hk)) return [];
            }
            if (! empty($flags['invite']) || ! empty($flags['account_notice'])) return [];
            if (preg_match('/^\s*\[(TEST|Test)\]/', (string) $message->getSubject())) return [];
            $who = self::caller();
            if (self::personalSender($who)) return [];

            $byAddr = [];
            foreach ($accounts as $a) $byAddr[mb_strtolower(trim((string) $a->email))][] = (int) $a->id;
            $own = array_map(fn ($p) => (int) $p['id'], AgencySignatories::ownStaff($agencyId));
            $me = (int) (optional(request()->user())->id ?? 0);
            $safety = in_array($who, ['SafeArrival', 'SafeArrivalCall', 'SafeArrivalCommand', 'SafeArrivalAbsentMail', 'RatioAlerts',
                'HeadcountController', 'HeadcountRemindCommand', 'IncidentController', 'IncidentRemindersCommand', 'SleepChecksAlertCommand'], true);

            $drop = [];
            foreach ($lower as $addr) {
                $ids = $byAddr[$addr] ?? [];
                $outs = array_values(array_intersect($ids, self::ids()));
                if (! $outs) continue;
                if (array_diff($ids, $outs) && array_intersect(array_diff($ids, $outs), $own)) continue;   // a shared inbox that is also one of theirs
                $keep = false;
                foreach ($outs as $o) {
                    if ($o === $me || self::parentIn($o, $agencyId)) { $keep = true; break; }   // asked for it / a parent there
                }
                if (! $keep && $safety && ! self::hasOwnManagers($agencyId)) $keep = true;
                if ($keep) continue;
                $drop[] = $addr;
                self::note('email', $outs[0], $who ?: 'unknown');
            }
            return $drop;
        } catch (\Throwable $e) {
            return [];   // never break the mail layer
        }
    }
}
