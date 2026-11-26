<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * WHAT EACH STAFF MEMBER WANTS TO HEAR ABOUT (2026-11-26).
 *
 * Anthony (super admin + iLearn agency admin) was getting every clock-in, absence, countersign reminder, payment and
 * billing-reminder copy and asked to choose. Until now Safe Arrival was the only staff notice with a per-person switch;
 * everything else an admin or director received could not be turned off.
 *
 * Each TOPIC is a group of bell types an admin or director receives in a management capacity. The person's choice is
 * a notification_prefs row keyed by the topic (push = in-app bell + phone, email = its emails); no row = on, so
 * nothing changes for anyone until they switch something off. Shown in Settings > Notifications > "Notify me about"
 * (NotificationPrefsController::EVENTS, audience NotifyAudience::PREF_EVENTS).
 *
 * ENFORCED WHERE THINGS LEAVE, reader and guard together:
 *   - Notify::write drops the bell row (and its queued push) when the person switched the topic's app channel off;
 *   - FcmService::sendToUser skips the direct push a sender makes right after that row (same person + title);
 *   - each sender that EMAILS staff for a topic asks wants(..., 'email'): AbsenceController (absence email),
 *     CountersignReminders, CertExpiryAlerts (manager email), PaymentAlerts (daily summary), TicketAlerts (platform
 *     admins; the support inbox always gets it), InvoiceReminders::scheduledCopies (billing reminder copies).
 *
 * NEVER OPTIONAL here: Safe Arrival for room educators, ratio/headcount/children-still-in alerts, incidents,
 * emergencies, privacy breach and privacy request alerts (legal duty), a person's OWN certificate, training and
 * schedule reminders. A topic only applies to someone who holds one of its roles in the row's agency, and a
 * notice that reaches them as a PARENT in that agency (types shared with families) is never muted by it.
 */
final class StaffNotifyPrefs
{
    private const MGMT = ['agency_admin', 'centre_director', 'platform_admin'];

    /** key => [label, hint, bell types, channels, roles] */
    public const TOPICS = [
        'staff_clock' => ['Team clock-ins', 'When someone clocks in or out, or starts or ends a break.',
            ['clock'], ['push'], self::MGMT],
        'staff_absences' => ['Absences reported', 'When a parent or the office reports a child absent. Room educators are always told.',
            ['absence'], ['push', 'email'], self::MGMT],
        'staff_countersign' => ['Forms to countersign', 'The reminder of forms waiting for your counter-signature.',
            ['countersign_reminder'], ['push', 'email'], self::MGMT],
        'staff_compliance' => ['Staff certificates', 'Staff certificates that are expiring, expired or waiting for approval. Your own certificates, and privacy alerts, always reach you.',
            ['compliance'], ['push', 'email'], self::MGMT],
        'staff_payments' => ['Payments', 'Payments received, failed or refunded, and the daily payments summary email.',
            ['payment', 'pfcc_copay_unpaid'], ['push', 'email'], self::MGMT],
        'staff_billing_copies' => ['Copies of parent billing reminders', 'A blind copy of each invoice reminder sent to a family, when your agency copies you.',
            [], ['email'], self::MGMT],
        'staff_pickup' => ['Pick-up list changes', 'When a family adds or removes someone allowed to pick up. Room educators are always told.',
            ['pickup_auth'], ['push'], self::MGMT],
        'staff_closures' => ['Closures', 'When a closure is added or changed.',
            ['closure'], ['push'], self::MGMT],
        'staff_schedule' => ['Children\'s schedule changes', 'When a child\'s days or hours change.',
            ['schedule'], ['push'], self::MGMT],
        'staff_tips' => ['Tips', 'Occasional tips on getting more out of the app.',
            ['portal_tip'], ['push'], ['agency_admin', 'centre_director', 'educator', 'home_visitor', 'platform_admin']],
        'staff_cold_reach' => ['Outreach replies', 'Replies to sales outreach emails.',
            ['cold_reach'], ['push'], ['sales_rep', 'platform_admin']],
        'staff_hr' => ['Team HR reminders', 'Your team\'s overdue onboarding tasks and courses, reviews to write or sign, and new job applicants (KiddieTrac HR). Your own HR reminders always reach you.',
            ['hr'], ['push'], self::MGMT],
        'staff_tickets' => ['Support tickets', 'New tickets, replies and overdue tickets. The support inbox always gets the email.',
            ['support_ticket'], ['push', 'email'], ['platform_admin']],
    ];

    /** @var array<int,array<string,array{push:bool,email:bool}>> */
    private static array $prefs = [];

    /** @var array<int,array<string,int>> user => [title => time] rows Notify::write just held back */
    private static array $muted = [];

    /** @var array<string,bool> */
    private static array $roleMemo = [];

    public static function topicOfType(string $type, array $data = []): ?string
    {
        // KiddieTrac HR (2026-11-28): only notices about someone else (kind=team) can be muted; your own tasks always come.
        if ($type === 'hr') return ($data['kind'] ?? '') === 'team' ? 'staff_hr' : null;
        if ($type === 'compliance') {
            // Only notices ABOUT STAFF certificates. A person's own certificate/training and privacy alerts stay on.
            $kind = (string) ($data['kind'] ?? '');
            if (str_starts_with($kind, 'privacy')) return null;
            $to = ltrim((string) ($data['hash'] ?? $data['link'] ?? ''), '#');
            return $to === 'certifications' ? 'staff_compliance' : null;
        }
        foreach (self::TOPICS as $key => $t) {
            if (in_array($type, $t[2], true)) return $key;
        }
        return null;
    }

    /** The person's choice for one topic: ['push' => bool, 'email' => bool]; both on until they choose. */
    public static function prefs(int $userId, string $topic): array
    {
        if (! isset(self::$prefs[$userId])) {
            self::$prefs[$userId] = [];
            try {
                foreach (DB::table('notification_prefs')->where('user_id', $userId)->where('event_key', 'like', 'staff\_%')->get(['event_key', 'push', 'email']) as $r) {
                    self::$prefs[$userId][(string) $r->event_key] = ['push' => (bool) $r->push, 'email' => (bool) $r->email];
                }
            } catch (\Throwable $e) {
                // Unknown = on: a lookup failure must never silence anyone.
            }
        }
        return self::$prefs[$userId][$topic] ?? ['push' => true, 'email' => true];
    }

    public static function forget(int $userId): void
    {
        unset(self::$prefs[$userId]);
    }

    /** Does this person hold one of the topic's roles (in this agency when known)? */
    private static function holds(int $userId, string $topic, ?int $agencyId): bool
    {
        $roles = self::TOPICS[$topic][4];
        $k = $userId . '|' . $topic . '|' . (int) $agencyId;
        if (array_key_exists($k, self::$roleMemo)) return self::$roleMemo[$k];
        try {
            $q = DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)->whereIn('role', $roles);
            if ($agencyId) {
                $q->where(function ($w) use ($agencyId) {
                    $w->where('agency_id', $agencyId)->orWhere('role', 'platform_admin')
                      ->orWhereIn('centre_id', DB::table('centres')->where('agency_id', $agencyId)->select('id'));
                });
            }
            return self::$roleMemo[$k] = $q->exists();
        } catch (\Throwable $e) {
            return self::$roleMemo[$k] = false;   // fail open: deliver
        }
    }

    private static function parentIn(int $userId, ?int $agencyId): bool
    {
        try {
            $q = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
                ->join('centres as c', 'c.id', '=', 'f.centre_id')->where('g.user_id', $userId);
            if ($agencyId) $q->where('c.agency_id', $agencyId);
            return $q->exists();
        } catch (\Throwable $e) {
            return true;   // fail open: deliver
        }
    }

    /** Is a topic's channel switched off for this person, in a capacity the topic covers? */
    public static function off(int $userId, string $topic, string $channel, ?int $agencyId = null): bool
    {
        if (! isset(self::TOPICS[$topic]) || ! in_array($channel, self::TOPICS[$topic][3], true)) return false;
        $p = self::prefs($userId, $topic);
        if ($p[$channel] ?? true) return false;
        return self::holds($userId, $topic, $agencyId);
    }

    public static function wants(int $userId, string $topic, string $channel, ?int $agencyId = null): bool
    {
        return ! self::off($userId, $topic, $channel, $agencyId);
    }

    /** Called by Notify::write for each row: true = hold this bell row (and its push) back. */
    public static function holdsBack(array $row, array $data): bool
    {
        $uid = (int) ($row['user_id'] ?? 0);
        $type = (string) ($row['type'] ?? '');
        if (! $uid || $type === '') return false;
        $topic = self::topicOfType($type, $data);
        if (! $topic) return false;
        $agencyId = ! empty($row['agency_id']) ? (int) $row['agency_id'] : null;
        if (! self::off($uid, $topic, 'push', $agencyId)) return false;
        // A notice shared with families reaches a parent as a parent: never muted by a staff topic.
        if (NotifyAudience::ofType($type) === 'all' && self::parentIn($uid, $agencyId)) return false;
        self::$muted[$uid][(string) ($row['title'] ?? '')] = time();
        return true;
    }

    /** FcmService asks: did Notify::write just hold this person's row with this title back? */
    public static function justMuted(int $userId, string $title): bool
    {
        $at = self::$muted[$userId][$title] ?? 0;
        return $at && time() - $at < 120;
    }

    /** Drop the people who switched a topic's email off from a list of addresses (address => user ids are looked up). */
    public static function filterEmails(array $emails, string $topic, ?int $agencyId = null): array
    {
        if (! $emails) return [];
        try {
            $ids = DB::table('users')->whereIn(DB::raw('LOWER(email)'), array_map(fn ($e) => mb_strtolower(trim((string) $e)), $emails))
                ->whereNull('deleted_at')->get(['id', 'email']);
        } catch (\Throwable $e) {
            return array_values($emails);
        }
        $drop = [];
        foreach ($ids as $u) {
            if (self::off((int) $u->id, $topic, 'email', $agencyId)) $drop[mb_strtolower(trim((string) $u->email))] = true;
        }
        return array_values(array_filter($emails, fn ($e) => ! isset($drop[mb_strtolower(trim((string) $e))])));
    }
}
