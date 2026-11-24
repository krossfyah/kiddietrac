<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * MISSED CALLS AND VOICEMAILS REACH YOU WITH THE APP CLOSED (2026-10-23).
 *
 * Anthony: "send email and text notifications (configurable with a setting) to notify of
 * missed calls, voicemails etc", plus a phone notification for missed calls. One sender for
 * both kinds of call on the toll-free line:
 *
 *   who      the department's alert people (Platform → Phone & voicemail); a call with no
 *            department (the general mailbox) goes to every department's alert people.
 *   how      per person, per kind: app (bell + push), email, text. Stored in PlatformSettings
 *            voice.alerts.u{id}. Defaults change nothing that already happened: app on, email
 *            off, text = the department's own "text me" switch.
 *   texts    through SmsController::sendOne on KiddieTrac's line - the normal consent / STOP
 *            gates apply; a person who never agreed to texts is not texted.
 *   email    to the person's own address on their account, never a default one.
 *
 * Missed calls are sent by kt:call-alerts a few minutes after the call, and only when no
 * voicemail followed (the voicemail alert says it all). inbound_calls.notified_at makes
 * every call alert once.
 */
class CallAlerts
{
    public const KINDS = ['missed', 'voicemail'];
    public const WAYS = ['push', 'email', 'sms'];
    private const LINE_AGENCY = 2;

    /** This person's choices, defaults filled in. $dept decides the text default. */
    public static function prefs(int $userId, ?array $dept = null): array
    {
        $saved = json_decode((string) PlatformSettings::get('voice.alerts.u' . $userId, ''), true) ?: [];
        $deptText = (bool) ($dept['text_alert'] ?? false);
        $out = [];
        foreach (self::KINDS as $k) {
            $s = is_array($saved[$k] ?? null) ? $saved[$k] : [];
            $out[$k] = [
                'push' => array_key_exists('push', $s) ? (bool) $s['push'] : true,
                'email' => array_key_exists('email', $s) ? (bool) $s['email'] : false,
                'sms' => array_key_exists('sms', $s) ? (bool) $s['sms'] : ($k === 'voicemail' ? $deptText : false),
            ];
        }
        $out['saved'] = (bool) $saved;
        return $out;
    }

    public static function savePrefs(int $userId, array $in): array
    {
        $clean = [];
        foreach (self::KINDS as $k) {
            foreach (self::WAYS as $w) {
                $clean[$k][$w] = ! empty($in[$k][$w]);
            }
        }
        PlatformSettings::set('voice.alerts.u' . $userId, json_encode($clean));
        return self::prefs($userId);
    }

    /** The people to tell about a call to this department (by name; null = general). */
    public static function recipients(?string $deptName): array
    {
        $ids = [];
        foreach (PhoneMenu::config()['departments'] as $d) {
            if ($deptName === null || $d['name'] === $deptName) {
                foreach ($d['alert_user_ids'] ?? [] as $u) $ids[(int) $u] = $d;
            }
        }
        return $ids;   // user id => department
    }

    /** "Anika Patel" for a number someone in the portal has, else null. */
    public static function nameFor(?string $number): ?string
    {
        try {
            $u = Handset::accounts($number)->first();
            if ($u) {
                $n = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
                if ($n !== '') return $n;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    /**
     * Tell everyone who should hear about this call, each the way they chose.
     * $kind 'missed' | 'voicemail'. $extra: duration, voicemail_id.
     */
    public static function send(string $kind, ?string $from, ?string $deptName, array $extra = []): int
    {
        $pretty = $from ? Dialer::pretty((string) $from) : 'an unknown caller';
        $name = self::nameFor($from);
        $who = $name ? $name . ' (' . $pretty . ')' : $pretty;
        $where = $deptName ? $deptName . ' · ' : '';
        if ($kind === 'voicemail') {
            $title = '📼 New ' . ($deptName ? $deptName . ' ' : '') . 'voicemail';
            $body = 'From ' . $who . (! empty($extra['duration']) ? ', ' . (int) $extra['duration'] . ' seconds' : '') . '. Tap to listen.';
            $link = '#phone?tab=vm';
        } else {
            $title = '📞 Missed call' . ($deptName ? ' · ' . $deptName : '');
            $body = $who . ' called the KiddieTrac line and nobody answered. Tap to call back.';
            $link = '#phone?tab=missed';
        }
        $sent = 0;
        /* IN THE PORTAL, ALWAYS (2026-10-23, Anthony: "notifications in the portal should show me
           new voicemail and calls as well"). The bell entry used to ride on the push switch, so
           anyone who turned push off - and every platform admin not on a department's list -
           never saw the call in Notifications. Now everyone who can see the call in the Phone
           screen gets the bell entry; push, text and email still follow their own switches. */
        $recips = self::recipients($deptName);
        $bell = array_keys($recips);
        foreach (DB::table('role_assignments')->where('role', 'platform_admin')->where('active', true)->pluck('user_id') as $pa) $bell[] = (int) $pa;
        $bell = array_values(array_unique(array_map('intval', $bell)));
        $data = json_encode(['link' => $link] + array_intersect_key($extra, ['voicemail_id' => 1]));
        foreach ($bell as $bid) {
            try {
                if (! DB::table('users')->where('id', $bid)->whereNull('deleted_at')->exists()) continue;
                Notify::write(['user_id' => $bid, 'type' => $kind === 'voicemail' ? 'voicemail' : 'missed_call', 'title' => $title, 'body' => $body,
                    'data' => $data, 'created_at' => now()]);
            } catch (\Throwable $e) {
            }
        }
        foreach ($recips as $uid => $dept) {
            $p = self::prefs($uid, $dept)[$kind];
            $u = DB::table('users')->where('id', $uid)->whereNull('deleted_at')->first(['id', 'first_name', 'email', 'phone']);
            if (! $u) continue;
            try {
                if ($p['push']) {
                    app(\App\Services\FcmService::class)->sendToUser($uid, $title, $body, $link);
                }
                if ($p['sms'] && $u->phone) {
                    app(\App\Http\Controllers\Api\SmsController::class)->sendOne(self::LINE_AGENCY, $uid, (string) $u->phone,
                        'KiddieTrac: ' . ($kind === 'voicemail' ? 'new ' . ($deptName ? $deptName . ' ' : '') . 'voicemail from ' . $who . '. Listen in the Phone screen.'
                            : 'missed call from ' . $who . ($deptName ? ' (' . $deptName . ')' : '') . '. Call back from the Phone screen.'),
                        $kind === 'voicemail' ? 'voicemail_alert' : 'missed_call_alert');
                }
                if ($p['email'] && $u->email) {
                    self::email($u, $kind, $title, $who, $where, $extra);
                }
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Call alert failed', ['kind' => $kind, 'user' => $uid, 'error' => $e->getMessage()]);
            }
        }
        return $sent;
    }

    private static function email(object $u, string $kind, string $title, string $who, string $where, array $extra): void
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $when = now('America/Toronto')->format('l, F j \a\t g:i A') . ' ET';
        $inner = '<p style="font-size:15px;color:#334155;margin:0 0 12px">' . $e($kind === 'voicemail'
                ? 'A caller left a voicemail on 1-855-400-9996.' : 'A call to 1-855-400-9996 was not answered, and no message was left.') . '</p>'
            . '<table style="border-collapse:collapse;margin-bottom:14px">'
            . '<tr><td style="padding:4px 14px 4px 0;color:#64748b">Caller</td><td style="font-weight:700">' . $e($who) . '</td></tr>'
            . ($where ? '<tr><td style="padding:4px 14px 4px 0;color:#64748b">Department</td><td>' . $e(rtrim($where, ' ·')) . '</td></tr>' : '')
            . '<tr><td style="padding:4px 14px 4px 0;color:#64748b">When</td><td>' . $e($when) . '</td></tr>'
            . (! empty($extra['duration']) ? '<tr><td style="padding:4px 14px 4px 0;color:#64748b">Length</td><td>' . (int) $extra['duration'] . ' seconds</td></tr>' : '')
            . '</table>'
            . '<p style="margin:0 0 16px"><a href="https://app.kiddietrac.com/dashboard.html' . ($kind === 'voicemail' ? '#phone?tab=vm' : '#phone?tab=missed') . '" '
            . 'style="display:inline-block;background:#047857;color:#ffffff !important;text-decoration:none;font-weight:700;padding:11px 20px;border-radius:10px">'
            . ($kind === 'voicemail' ? 'Listen in the Phone screen' : 'Call back from the Phone screen') . '</a></p>'
            . '<p style="font-size:12px;color:#94A3B8;margin:0">You get this because you chose email alerts for ' . ($kind === 'voicemail' ? 'voicemails' : 'missed calls')
            . ' (Phone screen → 🔔 Alerts).</p>';
        $html = \App\Services\EmailTemplate::wrap(null, $inner, ['force_brand' => 'kt', 'title' => $kind === 'voicemail' ? 'New voicemail' : 'Missed call']);
        Mail::html($html, function ($m) use ($u, $title) {
            \App\Support\MailScope::platform($m);   // platform mail: filed under Platform in the email log
            $m->to($u->email)->subject(preg_replace('/^\S+\s/u', '', $title) . ' — KiddieTrac line');
        });
    }
}
