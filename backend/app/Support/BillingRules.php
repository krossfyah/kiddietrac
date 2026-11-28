<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * THE AGENCY'S OWN BILLING RULES FOR THE FOUR AUTOMATED FEATURES (2026-11-28).
 *
 * Kept in agencies.settings.billing_setup next to the rest of Billing setup, edited from each feature's ⚙ Rules
 * button (agency admins) through PATCH /admin/billing-setup:
 *   tuition_notice_days     days before a tuition increase's effective date that families are told (default 30)
 *   vacation_credit_mode    none | percent | flat  - how an approved vacation hold is credited (default none)
 *   vacation_credit_value   the percent of the daily fee, or dollars per scheduled day
 *   reenrol_reminder_days   days before a re-enrolment deadline that families who have not answered are reminded
 *                           (default 7; 0 = no automatic reminder)
 */
final class BillingRules
{
    public const NOTICE_DAYS_DEFAULT = 30;
    public const REENROL_REMINDER_DEFAULT = 7;

    private static array $memo = [];

    public static function setup(int $agencyId): array
    {
        if (! array_key_exists($agencyId, self::$memo)) {
            $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
            $s = $raw ? json_decode((string) $raw, true) : [];
            self::$memo[$agencyId] = is_array($s['billing_setup'] ?? null) ? $s['billing_setup'] : [];
        }

        return self::$memo[$agencyId];
    }

    public static function forget(): void
    {
        self::$memo = [];
    }

    public static function tuitionNoticeDays(int $agencyId): int
    {
        $v = self::setup($agencyId)['tuition_notice_days'] ?? null;

        return is_numeric($v) ? max(0, min(365, (int) $v)) : self::NOTICE_DAYS_DEFAULT;
    }

    /** @return array{mode:string, value:float} */
    public static function vacationCredit(int $agencyId): array
    {
        $s = self::setup($agencyId);
        $mode = in_array($s['vacation_credit_mode'] ?? null, ['none', 'percent', 'flat'], true) ? $s['vacation_credit_mode'] : 'none';
        $val = is_numeric($s['vacation_credit_value'] ?? null) ? max(0.0, (float) $s['vacation_credit_value']) : 0.0;
        if ($mode === 'percent') $val = min(100.0, $val);

        return ['mode' => $mode, 'value' => $val];
    }

    public static function reenrolReminderDays(int $agencyId): int
    {
        $v = self::setup($agencyId)['reenrol_reminder_days'] ?? null;

        return is_numeric($v) ? max(0, min(90, (int) $v)) : self::REENROL_REMINDER_DEFAULT;
    }

    /** "$1,234.50" */
    public static function money(float $v): string
    {
        return ($v < 0 ? '-' : '') . '$' . number_format(abs($v), 2);
    }

    /** "January 5, 2027" from a Y-m-d (a calendar day, no timezone). */
    public static function day(?string $ymd): string
    {
        if (! $ymd || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m)) return (string) $ymd;

        return \Illuminate\Support\Carbon::createFromDate((int) $m[1], (int) $m[2], (int) $m[3])->format('F j, Y');
    }

    /**
     * The guardians of a family who can be told: [user_id => ['email' => ?, 'first' => ]]. Accounts switched off
     * (deactivated / suspended) or deleted are left out. Invited guardians stay in: billing mail reaches them
     * (MailScope::billing).
     */
    public static function guardians(int $familyId): array
    {
        $out = [];
        foreach (DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $familyId)->whereNull('u.deleted_at')
            ->where(fn ($w) => $w->whereNull('u.status')->orWhereNotIn('u.status', Audience::OFF_STATUSES))
            ->get(['u.id', 'u.email', 'u.first_name']) as $r) {
            $out[(int) $r->id] = [
                'email' => filter_var(trim((string) $r->email), FILTER_VALIDATE_EMAIL) ? mb_strtolower(trim((string) $r->email)) : null,
                'first' => (string) (strtok(trim((string) $r->first_name), ' ') ?: ''),
            ];
        }

        return $out;
    }

    /** Send one framed family email (billing mail: it reaches invited guardians too). Queued; failures are logged. */
    public static function mailFamily(int $agencyId, string $email, string $subject, string $html, bool $billing = true): void
    {
        dispatch(function () use ($agencyId, $email, $subject, $html, $billing) {
            try {
                $svc = \App\Services\AgencyMailer::forAgency($agencyId);
                $from = $svc->fromAddress();
                $fn = $svc->fromName();
                $svc->html($html, function ($m) use ($agencyId, $email, $subject, $from, $fn, $billing) {
                    MailScope::agency($m, $agencyId);
                    if ($billing) MailScope::billing($m);
                    $m->to($email)->from($from, $fn)->subject($subject);
                });
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('family billing notice email failed', ['email' => $email, 'error' => $e->getMessage()]);
            }
        })->onQueue('mail');
    }
}
