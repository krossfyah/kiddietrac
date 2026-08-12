<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Services\AgencyMailer;
use Illuminate\Support\Facades\DB;

/**
 * Shared "email the agency's reviewers" behaviour for home-visit reports and HCC
 * inspection forms. The recipient ROLES are configurable per agency via
 * agencies.settings.report_alert_roles (defaults to agency_admin + centre_director).
 *
 * Sends add an X-KT-Bypass-Suppression header for agencies that are actually allowed
 * to send, so that ONE cross-agency recipient (whose email also belongs to a
 * globally-suppressed agency) can no longer cancel the whole legitimate message —
 * which is exactly why the Test-Agency inspection alert never arrived.
 */
trait ReportAlerts
{
    /** Recipient roles for this agency's report alerts (configurable, sane default). */
    protected function alertRoles(int $agencyId): array
    {
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        $roles = $s['report_alert_roles'] ?? null;
        $valid = ['agency_admin', 'centre_director', 'platform_admin', 'educator', 'home_visitor', 'auditor'];
        if (! is_array($roles) || empty($roles)) {
            return ['agency_admin', 'centre_director'];
        }
        $roles = array_values(array_intersect(array_map('strval', $roles), $valid));
        return $roles ?: ['agency_admin', 'centre_director'];
    }

    /** Emails of the configured alert recipients for an agency. */
    protected function alertRecipients(int $agencyId): array
    {
        return DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.agency_id', $agencyId)
            ->whereIn('ra.role', $this->alertRoles($agencyId))
            ->where('ra.active', true)
            ->whereNull('u.deleted_at')
            ->whereNotNull('u.email')
            ->pluck('u.email')->unique()->values()->all();
    }

    /** The agency has turned comms off entirely (per-agency kill switch). */
    protected function commsDisabled(int $agencyId): bool
    {
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        return ($s['notifications_enabled'] ?? true) === false;
    }

    /** The agency is on the global env suppression list (MAIL_SUPPRESS_AGENCIES). */
    protected function envSuppressed(int $agencyId): bool
    {
        $env = array_filter(array_map('intval', array_map('trim', explode(',', (string) config('suppression.agencies', '')))));
        return in_array($agencyId, $env, true);
    }

    /**
     * Send a branded HTML alert (optionally with a PDF attachment) to $recipients.
     * Adds the bypass header when the sending agency is itself allowed to send, so a
     * cross-agency co-recipient can't cancel the whole message.
     */
    protected function sendReportAlert(int $agencyId, array $recipients, string $subject, string $html, ?string $pdf = null, ?string $pdfName = null): void
    {
        if (empty($recipients) || $this->commsDisabled($agencyId)) {
            return;
        }
        $bypass = ! $this->envSuppressed($agencyId);
        $mailer = AgencyMailer::forAgency($agencyId);
        $fromA = $mailer->fromAddress();
        $fromN = $mailer->fromName();
        $mailer->mailer()->html($html, function ($m) use ($recipients, $subject, $fromA, $fromN, $bypass, $pdf, $pdfName) {
            $m->to($recipients)->from($fromA, $fromN)->subject($subject);
            if ($pdf !== null) {
                $m->attachData($pdf, $pdfName ?: 'report.pdf', ['mime' => 'application/pdf']);
            }
            if ($bypass) {
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            }
        });
    }
}
