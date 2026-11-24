<?php

declare(strict_types=1);

namespace App\Support;

/**
 * HOW LOUD AN ALERT IS (2026-10-30, Anthony: "head count alert should be a yellow screen vs the red which should only
 * be used for high alerts").
 *
 * One answer per notification, stamped into its data as `severity` by Notify::write(), so every surface (the
 * full-screen takeover, toasts, the bell, pushes) colours it the same way:
 *
 *   high    red    - a child may be unaccounted for or unsafe: emergency messages, Safe Arrival "not here" /
 *                    dropped-off escalations, out of ratio, serious incidents, medical/allergy emergencies.
 *   warning amber  - something needs doing soon: headcounts due, still signed in / clocked in, check-in reminders,
 *                    late pickups, walk check-ins, overdue paperwork, ordinary incidents to review.
 *   info    teal   - everything else: chats, announcements, photos, activity.
 *
 * kt-urgent-alert.js carries the same rules as a fallback for rows written before this existed.
 */
final class AlertSeverity
{
    public const HIGH = 'high';
    public const WARNING = 'warning';
    public const INFO = 'info';

    private const WARNING_TYPES = [
        'headcount_due', 'clock_reminder', 'checkin_reminder', 'children_still_in', 'late_pickup',
        'walk_quiet', 'walk_auto_closed', 'countersign_reminder', 'records_reminder', 'immunization_reminder',
        'time_off_reminder', 'bio_reminder', 'compliance', 'pfcc_copay_unpaid', 'incident', 'medication_due',
    ];

    public static function of(?string $type, ?string $title, array $data = []): string
    {
        $type = (string) $type;
        $t = mb_strtolower((string) $title);
        $flag = $data['emergency'] ?? null;
        if ($flag === true || $flag === 1 || $flag === '1') return self::HIGH;
        $sev = mb_strtolower((string) ($data['severity'] ?? $data['incident_severity'] ?? ''));

        switch ($type) {
            case 'safe_arrival':
                // "Not here" / dropped off without a sign-in reach staff when a child is unaccounted for.
                if (str_contains($t, 'not here') || str_contains($t, '🚨') || str_contains($t, 'unaccounted')) return self::HIGH;
                if (str_contains($t, 'still signed in') || str_contains($t, 'has ') && str_contains($t, 'arrived?')) return self::WARNING;
                return self::INFO;
            case 'ratio_breach':
                return (str_contains($t, 'back in ratio') || str_contains($t, '✓')) ? self::INFO : self::HIGH;
            case 'incident':
                return in_array($sev, ['serious', 'major', 'critical', 'high', 'severe'], true) || str_contains($t, 'serious')
                    ? self::HIGH : self::WARNING;
            case 'walk_missing':
            case 'child_missing':
            case 'allergy_emergency':
            case 'medical_emergency':
                return self::HIGH;
        }
        if (in_array($type, self::WARNING_TYPES, true)) return self::WARNING;
        return self::INFO;
    }
}
