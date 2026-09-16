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
            // Reminder to an educator who logs care for a child who is not signed in.
            // Defaults ON — a safety check that ships off is off forever.
            /* $settingsTop, not $settings — which does not exist in this method. `??`
               swallowed the undefined variable silently, so this reported ON for every
               agency however the switch was actually set. Found 2026-09-09 when the line
               below threw for using the same name without `??`. */
            'attendance_reminders'  => ($settingsTop['attendance_reminders'] ?? true) ? true : false,
            /* Minutes a chat message may sit unread before the missed-message email goes
               out. The EFFECTIVE value, never a blank that silently means "the default" —
               resolved by the same helper the sending command uses, so what this screen
               shows is exactly what will happen. */
            'chat_email_delay_minutes' => \App\Console\Commands\EmailMissedMessagesCommand::delayForAgency(
                (object) ['settings' => json_encode($settingsTop)]
            ),
            // Onboarding-reminder daily email (absent = ON, default 7am agency-local).
            'onboarding_reminders_enabled' => ($settingsTop['onboarding_reminders_enabled'] ?? true) !== false,
            'onboarding_reminder_hour'     => (int) ($settingsTop['onboarding_reminder_hour'] ?? 7),
            // Weekly nudge to parents over-using MANUAL (staff) check-in vs the QR.
            'manual_checkin_reminders_enabled' => ($settingsTop['manual_checkin_reminders_enabled'] ?? true) !== false,

            // Closure notices. Read by closures:remind and by the immediate announcement.
            'closure_reminders_enabled'  => ($settingsTop['closure_reminders_enabled'] ?? true) !== false,
            'closure_reminder_immediate' => ($settingsTop['closure_reminder_immediate'] ?? true) !== false,
            'closure_reminder_days'      => (string) ($settingsTop['closure_reminder_days'] ?? '5,3,1'),

            /* Statutory holidays. Defaulted OFF: generating closures an agency did not ask
               for would cancel real care on a day they intended to open. */
            'stat_holidays_enabled'      => (bool) ($settingsTop['stat_holidays']['enabled'] ?? false),
            'stat_holidays_country'      => \App\Support\StatHolidays::countryCode(
                $settingsTop['stat_holidays']['country'] ?? ($settingsTop['country'] ?? $row->country ?? null)
            ),
            'stat_holidays_optional'     => array_values((array) ($settingsTop['stat_holidays']['optional'] ?? [])),
            'stat_holidays_notice_days'  => (string) implode(',', (array) ($settingsTop['stat_holidays']['notice_days'] ?? [1])),
            // What the screen offers as tick-boxes, so the list lives in one place.
            'stat_holidays_optional_available' => \App\Support\StatHolidays::optionalFor(
                \App\Support\StatHolidays::countryCode(
                    $settingsTop['stat_holidays']['country'] ?? ($settingsTop['country'] ?? $row->country ?? null)
                )
            ),
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
            'attendance_reminders'  => ['sometimes', 'boolean'],
            /* Bounded here as well as in the command. The scheduler ticks every five
               minutes, so anything under that is just "next tick" with extra confusion,
               and a delay longer than a day is not a notification any more. */
            'chat_email_delay_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'onboarding_reminders_enabled' => ['sometimes', 'boolean'],
            'onboarding_reminder_hour'     => ['sometimes', 'integer', 'min:0', 'max:23'],
            'manual_checkin_reminders_enabled' => ['sometimes', 'boolean'],
            'closure_reminders_enabled'  => ['sometimes', 'boolean'],
            'closure_reminder_immediate' => ['sometimes', 'boolean'],
            // Free text like "5,3,1" — validated loosely and normalised on save, so a
            // stray space or a duplicate cannot leave the nightly pass never matching.
            'closure_reminder_days'      => ['sometimes', 'string', 'max:40'],

            'stat_holidays_enabled'      => ['sometimes', 'boolean'],
            'stat_holidays_country'      => ['sometimes', 'string', 'max:40'],
            'stat_holidays_optional'     => ['sometimes', 'array'],
            'stat_holidays_optional.*'   => ['string', 'max:60'],
            'stat_holidays_notice_days'  => ['sometimes', 'string', 'max:40'],
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
        if (array_key_exists('attendance_reminders', $data)) {
            $settings['attendance_reminders'] = (bool) $data['attendance_reminders'];
        }
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

        /* Missed-chat email delay (top-level, read by kiddietrac:chat-emails).

           Re-reads the row and writes immediately, like every block around it. That is
           not ceremony: the mail_enabled branch above REPLACES $settings with a fresh
           read, so anything staged in memory before it is silently discarded. Each group
           owning its own read-modify-write is what keeps them independent.

           Moved here from the agency editors on 2026-09-09 — it belongs with the other
           mail settings, where an agency admin can reach it, rather than behind a pencil
           icon on a platform screen most admins never see. (Anthony) */
        if (array_key_exists('chat_email_delay_minutes', $data)) {
            $row7 = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
            $settings7 = ($row7 && $row7->settings) ? (json_decode($row7->settings, true) ?: []) : [];
            $settings7['chat_email_delay_minutes'] = (int) $data['chat_email_delay_minutes'];
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($settings7), 'updated_at' => now()]);
        }

        // Closure notices (top-level, read by closures:remind and announceClosure).
        $closureKeys = ['closure_reminders_enabled', 'closure_reminder_immediate', 'closure_reminder_days'];
        if (array_intersect($closureKeys, array_keys($data))) {
            $row6 = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
            $settings6 = ($row6 && $row6->settings) ? (json_decode($row6->settings, true) ?: []) : [];

            foreach (['closure_reminders_enabled', 'closure_reminder_immediate'] as $k) {
                if (array_key_exists($k, $data)) {
                    $settings6[$k] = (bool) $data[$k];
                }
            }
            if (array_key_exists('closure_reminder_days', $data)) {
                // Whole positive days, de-duplicated, furthest out first. Storing the raw
                // text would leave the nightly pass comparing against "3 " or "0".
                $days = collect(explode(',', (string) $data['closure_reminder_days']))
                    ->map(fn ($d) => (int) trim($d))
                    ->filter(fn ($d) => $d > 0 && $d <= 120)
                    ->unique()->sortDesc()->values()->all();
                $settings6['closure_reminder_days'] = implode(',', $days);
            }
            DB::table('agencies')->where('id', $agencyId)
                ->update(['settings' => json_encode($settings6), 'updated_at' => now()]);
        }

        /* Statutory holidays. Nested under one key so the whole feature can be read as a
           unit by holidays:sync and by the closure reminder, rather than four loose
           top-level flags that can drift apart. */
        $holidayKeys = ['stat_holidays_enabled', 'stat_holidays_country', 'stat_holidays_optional', 'stat_holidays_notice_days'];
        if (array_intersect($holidayKeys, array_keys($data))) {
            $row7 = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
            $settings7 = ($row7 && $row7->settings) ? (json_decode($row7->settings, true) ?: []) : [];
            $h = is_array($settings7['stat_holidays'] ?? null) ? $settings7['stat_holidays'] : [];

            if (array_key_exists('stat_holidays_enabled', $data)) {
                $h['enabled'] = (bool) $data['stat_holidays_enabled'];
            }
            if (array_key_exists('stat_holidays_country', $data)) {
                // Normalised on the way in: "Canada", "CA" and "ca" must not become three
                // different settings that the generator then treats differently.
                $h['country'] = \App\Support\StatHolidays::countryCode((string) $data['stat_holidays_country']);
            }
            if (array_key_exists('stat_holidays_optional', $data)) {
                // Only keys the country actually offers, so a stale tick-box from a
                // country switch cannot silently keep generating a holiday.
                $allowed = array_keys(\App\Support\StatHolidays::optionalFor($h['country'] ?? 'CA'));
                $h['optional'] = array_values(array_intersect(
                    array_map('strval', (array) $data['stat_holidays_optional']), $allowed
                ));
            }
            if (array_key_exists('stat_holidays_notice_days', $data)) {
                $days = collect(explode(',', (string) $data['stat_holidays_notice_days']))
                    ->map(fn ($d) => (int) trim($d))
                    ->filter(fn ($d) => $d > 0 && $d <= 60)
                    ->unique()->sortDesc()->values()->all();
                $h['notice_days'] = $days ?: [1];
            }

            $settings7['stat_holidays'] = $h;
            DB::table('agencies')->where('id', $agencyId)
                ->update(['settings' => json_encode($settings7), 'updated_at' => now()]);
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
            /* An admin pressed "send test" and is watching for it to land. Suppression
               would swallow it and read as "your SMTP settings are broken" — the exact
               opposite of what this button is for. Switches only: a genuinely
               unsubscribed or suppressed address still will not receive it. */
            \App\Support\MailScope::platform($m);
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
