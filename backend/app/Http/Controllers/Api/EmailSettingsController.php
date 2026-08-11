<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Per-agency email settings (admin / director).
 * - From name / address / encryption (agencies columns, legacy).
 * - Outbound SMTP: host / port / encryption / username / password — lets each
 *   agency send through their own Google / Microsoft 365 / any SMTP provider.
 * - Microsoft 365 (Graph) app details for the in-portal email client (Phase 1).
 * Secrets (SMTP password, Graph client secret) are ENCRYPTED at rest (Crypt) and
 * never returned to the client — only a "has it" flag.
 * New fields live in agencies.settings->email_config (no schema change).
 */
final class EmailSettingsController extends Controller
{
    private function resolveAgencyId(Request $request): int
    {
        $header = (int) $request->header('X-Active-Agency-Id');
        if ($header && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
                ->where(function ($q) use ($header) { $q->where('role', 'platform_admin')->orWhere('agency_id', $header); })->exists()) {
            return $header;
        }
        $u = $request->user();
        return (int) DB::table('role_assignments')
            ->where('user_id', $u->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])
            ->value('agency_id');
    }

    /** Admins AND centre directors may configure email. */
    private function assertAdmin(Request $request): void
    {
        $u = $request->user();
        $ok = DB::table('role_assignments')
            ->where('user_id', $u->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])
            ->exists();
        abort_unless($ok, 403, 'Admin or director only');
    }

    private function readConfig(int $agencyId): array
    {
        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $settings = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        return (isset($settings['email_config']) && is_array($settings['email_config'])) ? $settings['email_config'] : [];
    }

    private function writeConfig(int $agencyId, array $cfg): void
    {
        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $settings = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        $settings['email_config'] = $cfg;
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($settings), 'updated_at' => now()]);
    }

    /** GET /admin/email-settings */
    public function show(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('agencies')->where('id', $agencyId)
            ->select('id', 'name', 'email_from_name', 'email_from_address', 'email_smtp_encryption')
            ->first();
        abort_unless($row, 404, 'Agency not found');
        $cfg = $this->readConfig($agencyId);
        $settingsRaw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $settingsTop = $settingsRaw ? (json_decode((string) $settingsRaw, true) ?: []) : [];
        $mailEnabled = ($settingsTop['notifications_enabled'] ?? true) !== false;

        return response()->json([
            'agency_id'             => $row->id,
            'agency_name'           => $row->name,
            // Master mail/notifications switch (absent = ON).
            'mail_enabled'          => $mailEnabled,
            // Onboarding-reminder daily email (absent = ON, default 7am agency-local).
            'onboarding_reminders_enabled' => ($settingsTop['onboarding_reminders_enabled'] ?? true) !== false,
            'onboarding_reminder_hour'     => (int) ($settingsTop['onboarding_reminder_hour'] ?? 7),
            // Weekly nudge to parents over-using MANUAL (staff) check-in vs the QR.
            'manual_checkin_reminders_enabled' => ($settingsTop['manual_checkin_reminders_enabled'] ?? true) !== false,
            'email_from_name'       => $row->email_from_name,
            'email_from_address'    => $row->email_from_address,
            'email_smtp_encryption' => $row->email_smtp_encryption ?: 'tls',
            'default_from'          => config('mail.from.address'),
            // Outbound SMTP (secret redacted)
            'mode'                  => $cfg['mode'] ?? 'default',
            'smtp_host'             => $cfg['smtp_host'] ?? '',
            'smtp_port'             => $cfg['smtp_port'] ?? 587,
            'smtp_encryption'       => $cfg['smtp_encryption'] ?? 'tls',
            'smtp_username'         => $cfg['smtp_username'] ?? '',
            'has_smtp_password'     => !empty($cfg['smtp_password']),
            // Microsoft 365 / Graph (secret redacted)
            'graph_tenant_id'       => $cfg['graph_tenant_id'] ?? '',
            'graph_client_id'       => $cfg['graph_client_id'] ?? '',
            'has_graph_secret'      => !empty($cfg['graph_client_secret']),
        ]);
    }

    /** PATCH /admin/email-settings */
    public function update(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);

        $data = $request->validate([
            'email_from_name'       => ['nullable', 'string', 'max:120'],
            'email_from_address'    => ['nullable', 'email', 'max:190'],
            'email_smtp_encryption' => ['nullable', 'in:tls,ssl,none'],
            'mode'                  => ['nullable', 'in:default,smtp'],
            'smtp_host'             => ['nullable', 'string', 'max:190'],
            'smtp_port'             => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption'       => ['nullable', 'in:tls,ssl,none'],
            'smtp_username'         => ['nullable', 'string', 'max:190'],
            'smtp_password'         => ['nullable', 'string', 'max:500'],
            'graph_tenant_id'       => ['nullable', 'string', 'max:120'],
            'graph_client_id'       => ['nullable', 'string', 'max:120'],
            'graph_client_secret'   => ['nullable', 'string', 'max:500'],
            'mail_enabled'          => ['sometimes', 'boolean'],
            'onboarding_reminders_enabled' => ['sometimes', 'boolean'],
            'onboarding_reminder_hour'     => ['sometimes', 'integer', 'min:0', 'max:23'],
            'manual_checkin_reminders_enabled' => ['sometimes', 'boolean'],
        ]);

        // Legacy "from" columns
        DB::table('agencies')->where('id', $agencyId)->update([
            'email_from_name'       => $data['email_from_name'] ?? null,
            'email_from_address'    => $data['email_from_address'] ?? null,
            'email_smtp_encryption' => $data['email_smtp_encryption'] ?? 'tls',
            'updated_at'            => now(),
        ]);

        // Merge new config; keep existing secrets when the field is left blank.
        $cfg = $this->readConfig($agencyId);
        if (array_key_exists('mode', $data))            $cfg['mode'] = $data['mode'] ?? 'default';
        if (array_key_exists('smtp_host', $data))       $cfg['smtp_host'] = $data['smtp_host'];
        if (array_key_exists('smtp_port', $data))       $cfg['smtp_port'] = $data['smtp_port'] ?: 587;
        if (array_key_exists('smtp_encryption', $data)) $cfg['smtp_encryption'] = $data['smtp_encryption'] ?: 'tls';
        if (array_key_exists('smtp_username', $data))   $cfg['smtp_username'] = $data['smtp_username'];
        if (!empty($data['smtp_password']))             $cfg['smtp_password'] = Crypt::encryptString($data['smtp_password']);
        if (array_key_exists('graph_tenant_id', $data)) $cfg['graph_tenant_id'] = $data['graph_tenant_id'];
        if (array_key_exists('graph_client_id', $data)) $cfg['graph_client_id'] = $data['graph_client_id'];
        if (!empty($data['graph_client_secret']))       $cfg['graph_client_secret'] = Crypt::encryptString($data['graph_client_secret']);
        $this->writeConfig($agencyId, $cfg);

        // Master mail/notifications switch lives at the TOP level of settings (the
        // same flag the kill-switch reads), NOT inside email_config. Absent = ON.
        if (array_key_exists('mail_enabled', $data)) {
            $row2 = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
            $settings = ($row2 && $row2->settings) ? (json_decode($row2->settings, true) ?: []) : [];
            $settings['notifications_enabled'] = (bool) $data['mail_enabled'];
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($settings), 'updated_at' => now()]);
            \Illuminate\Support\Facades\Cache::forget('kt.agency_notifications:' . $agencyId);
        }

        // Onboarding-reminder daily email settings (top-level, read by the
        // kiddietrac:onboarding-reminders command). Defaults: enabled, 07:00.
        if (array_key_exists('onboarding_reminders_enabled', $data) || array_key_exists('onboarding_reminder_hour', $data)) {
            $row3 = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
            $settings3 = ($row3 && $row3->settings) ? (json_decode($row3->settings, true) ?: []) : [];
            if (array_key_exists('onboarding_reminders_enabled', $data)) $settings3['onboarding_reminders_enabled'] = (bool) $data['onboarding_reminders_enabled'];
            if (array_key_exists('onboarding_reminder_hour', $data))     $settings3['onboarding_reminder_hour'] = (int) $data['onboarding_reminder_hour'];
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($settings3), 'updated_at' => now()]);
        }

        // Manual-check-in (QR-nudge) reminder toggle (top-level, read by the
        // kiddietrac:manual-checkin-reminders command). Default: enabled.
        if (array_key_exists('manual_checkin_reminders_enabled', $data)) {
            $row5 = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
            $settings5 = ($row5 && $row5->settings) ? (json_decode($row5->settings, true) ?: []) : [];
            $settings5['manual_checkin_reminders_enabled'] = (bool) $data['manual_checkin_reminders_enabled'];
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($settings5), 'updated_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Build a Mailer from the agency's own SMTP config, or null to use the
     * platform default. Registered as a runtime mailer so we don't touch .env.
     */
    private function agencyMailer(array $cfg): ?\Illuminate\Contracts\Mail\Mailer
    {
        if (($cfg['mode'] ?? 'default') !== 'smtp' || empty($cfg['smtp_host'])) {
            return null;
        }
        $password = '';
        if (!empty($cfg['smtp_password'])) {
            try { $password = Crypt::decryptString($cfg['smtp_password']); } catch (Throwable $e) { $password = ''; }
        }
        $enc = $cfg['smtp_encryption'] ?? 'tls';
        config()->set('mail.mailers.kt_agency_smtp', [
            'transport'  => 'smtp',
            'host'       => $cfg['smtp_host'],
            'port'       => (int) ($cfg['smtp_port'] ?? 587),
            'encryption' => $enc === 'none' ? null : $enc,
            'username'   => $cfg['smtp_username'] ?? null,
            'password'   => $password,
            'timeout'    => 20,
        ]);
        try { Mail::purge('kt_agency_smtp'); } catch (Throwable $e) {}
        return Mail::mailer('kt_agency_smtp');
    }

    /** POST /admin/email-settings/test — send a test email to the current admin. */
    public function sendTest(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);
        $u = $request->user();
        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $to = $u->email;
        if (!$to) {
            return response()->json(['ok' => false, 'message' => 'Your account has no email address'], 422);
        }

        $cfg = $this->readConfig($agencyId);
        $fromAddr = $agency->email_from_address ?: config('mail.from.address');
        $fromName = $agency->email_from_name ?: ($agency->name ?? config('mail.from.name'));
        $via = (($cfg['mode'] ?? 'default') === 'smtp' && !empty($cfg['smtp_host']))
            ? ('your SMTP server (' . $cfg['smtp_host'] . ')') : 'the platform default mailer';

        $body = "This is a test email from KiddieTrac for {$agency->name}.\n\n"
            . "If you received this, your agency email settings are working.\n\n"
            . "Sent via: {$via}\nFrom: {$fromName} <{$fromAddr}>";

        $build = function ($m) use ($to, $fromAddr, $fromName) {
            $m->to($to)->subject('KiddieTrac email test')->from($fromAddr, $fromName);
        };

        try {
            $mailer = $this->agencyMailer($cfg);
            if ($mailer) {
                $mailer->raw($body, $build);
            } else {
                Mail::raw($body, $build);
            }
            return response()->json(['ok' => true, 'sent_to' => $to, 'via' => $via]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }
    }
}
