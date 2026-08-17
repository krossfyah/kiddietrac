<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use App\Support\AgencyTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Tell somebody when biometric unlock is switched on for their account (2026-08-17).
 *
 * Biometric enrolment has been entirely client-side: kt-biometric.js writes
 * kt_biometric_enabled into localStorage and makes no API call at all. Nothing on the
 * server knew it had happened, which means nobody was told — and an unlock method being
 * added to an account is exactly the kind of change that should produce a message, if only
 * so the account holder can say "that was not me".
 *
 * The email is the point, not the record: the row exists so the same device does not send
 * the same alert twice, and so support can answer "when was this turned on".
 */
final class BiometricController extends Controller
{
    // resolveAgencyId lives here, header-aware, and is how every other controller in
    // this API scopes to the agency the caller has switched into.
    use \App\Http\Concerns\ResolvesCentreContext;

    /** Report that biometric unlock has been enabled on the device making this request. */
    public function enrolled(Request $request): JsonResponse
    {
        $data = $request->validate([
            // 'catch_up' marks a device reporting enrolment it did BEFORE this endpoint
            // existed, rather than one enrolling right now. Worth distinguishing: the
            // wording differs, and a burst of them is expected rather than alarming.
            'catch_up' => 'nullable|boolean',
            'label' => 'nullable|string|max:120',
        ]);

        $user = $request->user();
        $ua = (string) $request->userAgent();
        $ip = (string) $request->ip();
        $device = $data['label'] ?? self::describeDevice($ua);
        // One alert per account per device. A device that re-enrols after switching off
        // is a genuinely new event and does send again; the fingerprint changes with the
        // user agent, not with the session.
        $fingerprint = hash('sha256', $user->id . '|' . $ua . '|' . $device);

        $already = DB::table('biometric_enrolments')
            ->where('user_id', $user->id)->where('fingerprint', $fingerprint)->exists();

        if ($already) {
            return response()->json(['recorded' => true, 'alerted' => false]);
        }

        DB::table('biometric_enrolments')->insert([
            'user_id' => $user->id,
            'fingerprint' => $fingerprint,
            'device' => $device,
            'ip' => $ip,
            'user_agent' => mb_substr($ua, 0, 500),
            'was_catch_up' => ! empty($data['catch_up']),
            'enrolled_at' => now(),
            'created_at' => now(),
        ]);

        $sent = self::alert($user, $device, $ip, now(), ! empty($data['catch_up']));

        return response()->json(['recorded' => true, 'alerted' => $sent]);
    }

    /**
     * Biometric unlock switched OFF on this device.
     *
     * Recorded, not alerted: turning a lock off is done from inside an unlocked session, so
     * whoever did it is already in. The value is that the report stays honest — without
     * this it accumulates enrolments that ended months ago and reads as a list of live
     * unlock methods when it is a list of everything that ever happened.
     */
    public function revoked(Request $request): JsonResponse
    {
        $user = $request->user();
        $fingerprint = hash('sha256', $user->id . '|' . (string) $request->userAgent() . '|' . self::describeDevice((string) $request->userAgent()));

        $n = DB::table('biometric_enrolments')
            ->where('user_id', $user->id)
            ->where('fingerprint', $fingerprint)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return response()->json(['revoked' => $n > 0]);
    }

    /**
     * Who has biometric unlock, on what, since when.
     *
     * Scoped to the caller's agency like everything else here — a director should see
     * their own centre's staff and families, not the platform. Live enrolments first,
     * because "who can unlock this account right now" is the question being asked.
     */
    public function report(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);

        $rows = DB::table('biometric_enrolments as b')
            ->join('users as u', 'u.id', '=', 'b.user_id')
            ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->where('ra.agency_id', $agencyId)
            ->where('ra.active', true)
            ->select('b.id', 'b.user_id', 'b.device', 'b.ip', 'b.enrolled_at', 'b.revoked_at',
                'b.was_catch_up', 'u.email', 'ra.role',
                DB::raw("TRIM(CONCAT(u.first_name,' ',COALESCE(u.last_name,''))) as name"))
            ->orderByRaw('b.revoked_at IS NOT NULL')     // live ones first
            ->orderByDesc('b.enrolled_at')
            ->limit(500)
            ->get()
            ->unique('id')->values();

        return response()->json([
            'data' => $rows,
            'active' => $rows->whereNull('revoked_at')->count(),
            'revoked' => $rows->whereNotNull('revoked_at')->count(),
            // People, not devices: one person with a phone and a tablet is one person who
            // can unlock, and that is what somebody reviewing this wants counted.
            'users_with_biometrics' => $rows->whereNull('revoked_at')->pluck('user_id')->unique()->count(),
        ]);
    }

    /**
     * Send the alert. Returns whether it was handed to the mailer.
     *
     * Public and static so the test send and any backfill use the exact same email —
     * an alert you approved and an alert that ships should not be two pieces of code.
     */
    public static function alert($user, string $device, string $ip, Carbon $when, bool $catchUp = false, ?string $overrideTo = null): bool
    {
        $to = $overrideTo ?: (string) ($user->email ?? '');
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $agencyId = DB::table('role_assignments')->where('user_id', $user->id)
            ->where('active', true)->value('agency_id');
        $tz = AgencyTime::tz($agencyId ? (int) $agencyId : null);
        // Spelled out in the reader's own timezone, with the zone named. "17/08 3:24"
        // is not enough to tell whether it was you: the point of this email is that the
        // recipient can compare it against what they remember doing.
        $stamp = $when->copy()->timezone($tz)->format('l j F Y \a\t g:i A T');

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'there';

        $lead = $catchUp
            ? 'We are letting you know that biometric unlock — Face ID, Touch ID or a fingerprint — '
              . 'is switched on for your KiddieTrac account on the device below. This is a one-off '
              . 'notice covering a device that already had it enabled.'
            : 'Biometric unlock — Face ID, Touch ID or a fingerprint — has just been switched on for '
              . 'your KiddieTrac account on the device below.';

        $row = fn ($k, $v) => '<tr>'
            . '<td style="padding:7px 0;font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#64748B;width:96px;vertical-align:top;">' . $e($k) . '</td>'
            . '<td style="padding:7px 0;font-size:14px;color:#0F172A;font-weight:600;">' . $e($v) . '</td></tr>';

        $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#334155;">Hello ' . $e($name) . ',</p>'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">' . $lead . '</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 18px">'
            . '<tr><td style="background:#F1F5F9;border-radius:10px;padding:14px 16px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            . $row('Device', $device)
            . $row('IP address', $ip ?: 'not recorded')
            . $row('When', $stamp)
            . '</table></td></tr></table>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 16px">'
            . '<tr><td style="background:#FEF2F2;border-left:4px solid #EF4444;border-radius:10px;padding:14px 16px;'
            . 'font-size:14px;line-height:1.6;color:#7F1D1D;"><strong>Was this not you?</strong> Turn biometric unlock off '
            . 'under Settings, change your password, and tell your centre straight away.</td></tr></table>'
            . '<p style="margin:0;font-size:13px;line-height:1.6;color:#64748B;">If this was you, no action is needed. '
            . 'Biometric data never leaves your device and is never sent to us — your phone only tells the app whether '
            . 'the check passed.</p>';

        $html = EmailTemplate::wrap($agencyId ? (int) $agencyId : null, $body, [
            'eyebrow' => 'SECURITY ALERT',
            'title' => 'Biometric unlock enabled',
            'subtitle' => $device . ' · ' . $when->copy()->timezone($tz)->format('j M Y, g:i A'),
            'preheader' => 'Biometric unlock was enabled on ' . $device . ' at ' . $stamp,
        ]);

        try {
            AgencyMailer::forAgency($agencyId ? (int) $agencyId : null)->mailer()
                ->html($html, function ($m) use ($to, $name) {
                    $m->to($to, $name !== 'there' ? $name : null)
                      ->subject('🔐 Biometric unlock was enabled on your KiddieTrac account');
                    // A security alert about an account is addressed to the account
                    // holder and must arrive even where ordinary notifications are
                    // paused — the same reasoning the account-notice exemption uses.
                    try { $m->getHeaders()->addTextHeader('X-KT-Account-Notice', '1'); } catch (\Throwable $e) {
                    }
                });

            return true;
        } catch (\Throwable $ex) {
            Log::warning('Biometric alert failed', ['user' => $user->id ?? null, 'error' => $ex->getMessage()]);

            return false;
        }
    }

    /**
     * A human description of the device from its user agent.
     *
     * Deliberately coarse. "iPhone · Safari" is what somebody can recognise as theirs;
     * a full UA string is noise in an email and tells an attacker more than it tells them.
     */
    public static function describeDevice(string $ua): string
    {
        $os = 'Unknown device';
        foreach ([
            'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android',
            'Macintosh' => 'Mac', 'Mac OS X' => 'Mac', 'Windows' => 'Windows',
            'CrOS' => 'Chromebook', 'Linux' => 'Linux',
        ] as $needle => $label) {
            if (stripos($ua, $needle) !== false) { $os = $label; break; }
        }

        $browser = '';
        // Order matters: Edge and Chrome both claim Safari, Chrome claims Safari too.
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $needle => $label) {
            if (stripos($ua, $needle) !== false) { $browser = $label; break; }
        }
        if (stripos($ua, 'KiddieTrac') !== false || stripos($ua, 'wv') !== false) {
            $browser = 'KiddieTrac app';
        }

        return $browser ? $os . ' · ' . $browser : $os;
    }
}
