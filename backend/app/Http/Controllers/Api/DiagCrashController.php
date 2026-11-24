<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

/**
 * POST /diag/crash - the public crash-report sink (web, iOS and Android apps).
 *
 * Moved out of routes/api.php on 2026-11-04, unchanged. As a route CLOSURE it was serialized by route:cache, and
 * running that cached copy killed the PHP worker ("buffer overflow detected") right after the report was appended:
 * Apache answered 503, so from at least 2026-10-28 no crash became a ticket or an email. A controller is never
 * serialized.
 */
class DiagCrashController extends Controller
{
    public function store(\Illuminate\Http\Request $r)
    {
        // Resolve the reporter FIRST: their agency decides which timezone this
        // report is stamped in, and the agency's own zone is the only one that
        // lines up with "what were you doing when it broke".
        $claimedUser = $r->input('user_id') ? (int) $r->input('user_id') : null;
        if ($claimedUser && ! \Illuminate\Support\Facades\DB::table('users')->where('id', $claimedUser)->exists()) {
            $claimedUser = null;                       // client-supplied; never trust it blindly
        }
        $agencyId = $r->input('agency_id') ? (int) $r->input('agency_id') : null;
        if ($claimedUser) {
            // Prefer the agency we can PROVE from their roles over the one the
            // browser claims — a stale active-agency id has misfiled data before.
            $real = \Illuminate\Support\Facades\DB::table('role_assignments')
                ->where('user_id', $claimedUser)->where('active', true)
                ->whereNotNull('agency_id')->value('agency_id');
            if ($real) $agencyId = (int) $real;
        }
        if ($agencyId && ! \Illuminate\Support\Facades\DB::table('agencies')->where('id', $agencyId)->exists()) {
            $agencyId = null;
        }
        $tz = \App\Support\AgencyTime::tz($agencyId);   // null agency → the system default
        $nowLocal = now()->setTimezone($tz);

        // Context, not just a stack. A trace with no screen, no user and no last
        // action tells you something broke but not what anyone was doing.
        $data = [
            'at'          => $nowLocal->format('D, M j, Y g:i A') . ' (' . $nowLocal->format('T') . ')',
            'at_utc'      => now()->toDateTimeString(),
            'timezone'    => $tz,
            'lang'        => mb_substr((string) $r->input('lang'), 0, 20),
            'screen_size' => mb_substr((string) $r->input('screen_size'), 0, 24),
            'dpr'         => mb_substr((string) $r->input('dpr'), 0, 8),
            'sw'          => mb_substr((string) $r->input('sw'), 0, 60),
            'device'      => mb_substr((string) $r->input('device'), 0, 200),
            'os'          => mb_substr((string) $r->input('os'), 0, 80),
            'app'         => mb_substr((string) $r->input('app'), 0, 80),
            'app_version' => mb_substr((string) $r->input('app_version'), 0, 40),
            'url'         => mb_substr((string) $r->input('url'), 0, 300),
            'screen'      => mb_substr((string) $r->input('screen'), 0, 120),
            'user_id'     => $r->input('user_id') ? (int) $r->input('user_id') : null,
            'user_email'  => mb_substr((string) $r->input('user_email'), 0, 160),
            'user_name'   => mb_substr((string) $r->input('user_name'), 0, 120),
            'role'        => mb_substr((string) $r->input('role'), 0, 40),
            'agency_id'   => $r->input('agency_id') ? (int) $r->input('agency_id') : null,
            'last_action' => mb_substr((string) $r->input('last_action'), 0, 160),
            'viewport'    => mb_substr((string) $r->input('viewport'), 0, 24),
            'native'      => (bool) $r->input('native'),
            'trace'       => mb_substr((string) $r->input('trace'), 0, 8000),
            /* What the session was doing before it broke — navigations, failed
               requests, service-worker takeovers, clicks. A stack trace says WHERE it
               died; this says how it got there. Added after ticket #18 filed with
               "Last action: not captured". (2026-08-26) */
            'breadcrumbs' => mb_substr((string) $r->input('breadcrumbs'), 0, 2400),
            /* Which script blocked the main thread, and for how long. The freeze
               watchdog collects this from PerformanceObserver('longtask') at the
               moment it decides to report, so a freeze ticket names a file instead
               of leaving the reader to guess. (2026-09-04) */
            'long_tasks'  => mb_substr((string) $r->input('long_tasks'), 0, 600),
            'ip'          => $r->ip(),
        ];
        try { \Illuminate\Support\Facades\Storage::append('crash-reports.log', json_encode($data)); } catch (\Throwable $e) {}

        /* A FREEZE also goes in the audit log, next to perf.slow_request.
           The ticket is for acting on; this is for asking whether it is getting
           better -- which screen, how long, how often, and whether a fix moved the
           number. Everything here is guarded: this endpoint exists to survive a bad
           client, so a freeze that cannot be summarised must still be stored above. */
        try {
            $trace = (string) $data['trace'];
            if (preg_match('/UI (FROZE|LONG STALL)/', $trace, $kind)) {
                $secs = null;
                if (preg_match('/responding for ([0-9.]+)s/', $trace, $m)) { $secs = (float) $m[1]; }
                \App\Support\Audit::write([
                    'user_id'     => $data['user_id'],
                    'agency_id'   => $agencyId,
                    'action'      => 'perf.ui_freeze',
                    'entity_type' => 'screen',
                    'user_agent'  => mb_substr((string) $data['device'], 0, 500),
                    'payload'     => json_encode([
                        'kind'        => $kind[1] === 'FROZE' ? 'freeze' : 'long_stall',
                        'seconds'     => $secs,
                        'screen'      => $data['screen'] ?: $data['url'],
                        'long_tasks'  => $data['long_tasks'],
                        'app_version' => $data['app_version'],
                        'sw'          => $data['sw'],
                        'native'      => $data['native'],
                        'summary'     => trim(($kind[1] === 'FROZE' ? 'Interface froze' : 'Interface stalled')
                                         . ($secs ? ' for ' . $secs . 's' : '')
                                         . ' on ' . ($data['screen'] ?: 'unknown screen')
                                         . ($data['long_tasks'] ? ' — blocked by ' . $data['long_tasks'] : '')),
                    ]),
                    'created_at'  => now(),
                ]);
            }
        } catch (\Throwable $e) { /* never break the crash sink */ }

        // The first line of the trace is the error itself; the rest is where it came
        // from. That first line is what makes two reports "the same crash".
        // Neither preg_replace nor strtok is safe to chain into trim(): the first
        // returns null on a pattern failure and the second returns FALSE when the
        // string is empty, and trim() rejects both with a TypeError - a 500 on the
        // one endpoint whose whole job is to survive a bad client. A report whose
        // JSON does not decode arrives here with an empty trace, which is exactly
        // the case that hit. Cast, guard, then split.
        $cleanTrace = (string) preg_replace('/^\S+\s+(JS ERROR|UNHANDLED PROMISE)\s+/', '', $data['trace']);
        if (trim($cleanTrace) === '') $cleanTrace = (string) $data['trace'];
        $lines = explode("\n", $cleanTrace);
        $firstLine = trim((string) ($lines[0] ?? ''));
        $subject = 'Crash: ' . mb_substr($firstLine !== '' ? $firstLine : 'Unknown error', 0, 150);

        /* AN OPAQUE "Script error." IS NOT OURS AND CANNOT BE WORKED ON.

           The browser refuses to describe an exception raised by a cross-origin script —
           an extension, a content blocker, a password manager, an injected script — so
           there is no file, no line and no stack, and nothing in KiddieTrac to change.
           The reporter already recognises this class and replaces the useless trace with
           an explanation; what it could not do was stop it becoming a ticket.

           That mattered little while reports were opt-in, because somebody had to tap
           Send. Since 2026-08-31 they send automatically, so every extension misbehaving
           on somebody's phone would file a high-priority technical ticket nobody can act
           on — the queue becomes noise and the real crashes get lost in it.

           Still LOGGED (crash-reports.log), because the pattern is worth seeing if it
           ever becomes twenty users in a day. Just not filed, and not emailed. Found
           when Eisha Wright hit exactly this at 8:06 on 2026-08-31. */
        /* A BROWSER NOTICE, NOT A CRASH (tickets #216 / #217, 2026-11-04). "ResizeObserver loop completed with
           undelivered notifications" is the browser saying a resize callback changed layout and the rest of its
           notifications will be delivered next frame - by the spec it is reported through window.onerror, but nothing
           failed and nothing is lost. The reporter drops it now; this catches older cached copies. */
        /* A SIGN-IN GATE, NOT A CRASH (ticket #256, 2026-11-24): the two-factor / password-change / paused-access
           refusal, rejected on purpose after the gate is shown. The reporter drops it now; this catches older copies. */
        if (preg_match('/needs two-factor sign-in|Set up two-factor sign-in to continue|choose a new password to continue|Your access is paused/i', $firstLine)) {
            return response()->json(['ok' => true, 'ticket_id' => null, 'note' => 'Logged. No ticket filed: a sign-in gate, not an error.']);
        }
        if (preg_match('/ResizeObserver loop/i', $firstLine)) {
            return response()->json(['ok' => true, 'ticket_id' => null, 'note' => 'Logged. No ticket filed: a browser layout notice, not an error.']);
        }
        $isOpaque = (bool) preg_match('/^script error/i', trim($firstLine))
            || str_contains($data['trace'], 'Script error (opaque)');
        if ($isOpaque) {
            return response()->json([
                'ok' => true,
                'ticket_id' => null,
                'note' => 'Logged. No ticket filed: the browser withheld the detail, so there is nothing actionable.',
            ]);
        }

        /* NOT OUR CODE (tickets #154 and #156, 2026-10-16): "Error: blocked" with a trace made
           only of a browser extension (chrome-extension://…) and the host's injected traffic
           script (img1.wsimg.com). When every URL in the trace is another origin, there is
           nothing in KiddieTrac to fix. Logged above like the opaque case, not filed. */
        if (preg_match_all('#(?:https?|chrome-extension|moz-extension|safari-web-extension)://([^/\s:)]+)#i', (string) $data['trace'], $hostM)
            && ! array_filter($hostM[1], fn ($h) => (bool) preg_match('/(^|\.)kiddietrac\.com$/i', $h))) {
            return response()->json([
                'ok' => true,
                'ticket_id' => null,
                'note' => 'Logged. No ticket filed: the error came from a third-party script or browser extension, not KiddieTrac.',
            ]);
        }

        /* A REPORT THAT SAYS NOTHING IS NOT A CRASH (ticket #86, 2026-09-21).

           #86 read, in full: "Crash: Unknown error. Who: unknown. Screen: ? URL: ?
           Last action: not captured. App: ? Viewport: ? Device: OS: Trace: (empty)."
           Every field a question mark, and nothing to work on.

           It came from 202.61.157.28 at 08:08 on 20 September — the first request of the
           probe that spent the next twenty minutes guessing passwords. This endpoint is
           public by necessity (a crashed client has no session to prove), which means
           anyone can post an empty body and file a technical ticket. Left alone, that is
           a way to bury a real crash queue under junk, and it is free to do.

           So: no trace, no screen, no URL, and nobody signed in — log it and stop.
           LOGGED, never silently dropped, because a flood of these is itself worth
           seeing; it is the same rule the opaque-error guard above already follows. */
        $hasNothing = trim((string) $data['trace']) === ''
            && trim((string) $data['screen']) === ''
            && trim((string) $data['url']) === ''
            && ! $claimedUser;
        if ($hasNothing) {
            return response()->json([
                'ok' => true,
                'ticket_id' => null,
                'note' => 'Logged. No ticket filed: the report carried no error, screen or URL.',
            ]);
        }

        // Track it as a support ticket, so a crash is something you can work through
        // and close rather than a line in a log nobody opens.
        $ticketId = null;
        try {
            // The SAME crash reported repeatedly is one problem, not twenty tickets:
            // three identical traces arrived within an hour of each other in August.
            // Reopen nothing, spam nothing — only file a new one if there is no open
            // ticket for this error already.
            $existing = \Illuminate\Support\Facades\DB::table('support_tickets')
                ->where('category', 'technical')->where('status', 'open')
                ->where('subject', $subject)
                ->orderByDesc('id')->first();

            $userId = $claimedUser;

            $bodyText = "Automatically filed from a crash report.\n\n"
                . 'When: ' . $data['at'] . "\n"
                . 'Who: ' . ($data['user_name'] ?: 'unknown') . ' <' . ($data['user_email'] ?: 'unknown') . '>'
                . ($data['role'] ? ' · ' . $data['role'] : '') . "\n"
                . 'Screen: ' . ($data['screen'] ?: '?') . '   URL: ' . ($data['url'] ?: '?') . "\n"
                . 'Last action: ' . ($data['last_action'] ?: 'not captured') . "\n"
                . 'App: ' . ($data['app'] ?: '?') . ' ' . $data['app_version']
                . ($data['native'] ? ' (native app)' : ' (browser)') . '   Viewport: ' . ($data['viewport'] ?: '?')
                . ($data['screen_size'] ? '   Screen: ' . $data['screen_size'] : '')
                . ($data['dpr'] ? ' @' . $data['dpr'] . 'x' : '') . "\n"
                . 'Device: ' . $data['device'] . "\n"
                . 'OS: ' . $data['os'] . ($data['lang'] ? '   Language: ' . $data['lang'] : '')
                . '   IP: ' . $data['ip'] . "\n"
                . 'Service worker: ' . ($data['sw'] ?: 'not reported')
                . '   Recorded ' . $data['at_utc'] . ' UTC' . "\n\n"
                . ($data['breadcrumbs']
                    ? "What led up to it (oldest first):\n" . $data['breadcrumbs'] . "\n\n"
                    : '')
                . "Trace:\n" . $data['trace'];

            if ($existing) {
                // Append the recurrence to the open ticket instead of filing a twin.
                \Illuminate\Support\Facades\DB::table('support_tickets')->where('id', $existing->id)->update([
                    'body' => mb_substr($existing->body . "\n\n--- reported again ---\n" . $bodyText, 0, 60000),
                    'updated_at' => now(),
                ]);
                $ticketId = (int) $existing->id;
            } else {
                $ticketId = \Illuminate\Support\Facades\DB::table('support_tickets')->insertGetId([
                    'agency_id'         => $agencyId,
                    'centre_id'         => null,
                    'raised_by_user_id' => $userId,
                    'category'          => 'technical',
                    'priority'          => 'high',
                    'subject'           => $subject,
                    'body'              => $bodyText,
                    'status'            => 'open',
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Crash report: could not file a support ticket', ['error' => $e->getMessage()]);
        }

        // Email the crash to the team so it lands in the inbox, not just a log —
        // branded like every other KiddieTrac email, and led by the ticket number
        // so the report and the thing you track it in are never separate items.
        /* ONE EMAIL PER TICKET, NOT PER REPORT (2026-10-22). A crash that repeats is
           appended to its open ticket above, but every repeat was ALSO emailed: tickets
           #168 and #174 each sent info@ five identical emails within minutes. Now a ticket
           emails when it opens, and a recurrence emails again at most once every six
           hours, saying how many repeats there have been. Every report still lands on
           the ticket. */
        $crashMailKey = $ticketId ? 'crash-mail:' . (int) $ticketId : null;
        $repeats = 0;
        if ($crashMailKey && ! empty($existing)) {
            $repeats = (int) \Illuminate\Support\Facades\Cache::increment($crashMailKey . ':n');
            if (! \Illuminate\Support\Facades\Cache::add($crashMailKey, 1, now()->addHours(6))) {
                return response()->json(['ok' => true, 'ticket_id' => $ticketId]);
            }
        } elseif ($crashMailKey) {
            \Illuminate\Support\Facades\Cache::put($crashMailKey, 1, now()->addHours(6));
            \Illuminate\Support\Facades\Cache::put($crashMailKey . ':n', 0, now()->addDays(7));
        }
        try {
            $row = function ($label, $value) {
                return '<tr><td class="kt-muted" style="padding:5px 14px 5px 0;color:#64748B;font-size:13px;white-space:nowrap;vertical-align:top;">'
                    . e($label) . '</td><td class="kt-strong" style="padding:5px 0;color:#0F172A;font-size:13px;font-weight:600;word-break:break-word;">'
                    . e($value ?: '—') . '</td></tr>';
            };
            $ticketLine = $ticketId
                ? '<div style="display:inline-block;background:#13b7cc;color:#04263a;font-weight:800;font-size:13px;letter-spacing:.4px;padding:6px 14px;border-radius:999px;">SUPPORT TICKET #' . (int) $ticketId . '</div>'
                : '<div style="display:inline-block;background:#FEE2E2;color:#991B1B;font-weight:800;font-size:13px;padding:6px 14px;border-radius:999px;">NO TICKET FILED — SEE THE LOG</div>';

            $body = '<!doctype html><html><head><meta charset="UTF-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                // Declaring the scheme is what stops a client inventing its own inversion
                // of a white panel full of near-black text.
                . '<meta name="color-scheme" content="light dark">'
                . '<meta name="supported-color-schemes" content="light dark">'
                . '<style>@media (prefers-color-scheme: dark){'
                . '.kt-bg{background:#0E1726 !important;}'
                . '.kt-panel{background:#152033 !important;border-color:#26344A !important;}'
                . '.kt-panel td,.kt-panel div,.kt-panel span,.kt-panel p{color:#D6DEE9 !important;}'
                . '.kt-strong{color:#F1F5F9 !important;}'
                . '.kt-muted{color:#9AA8BC !important;}'
                . '}</style></head><body style="margin:0;padding:0;">'
                . '<div class="kt-bg" style="margin:0;padding:0;background:#F1F5F9;">'
                . '<div style="max-width:640px;margin:0 auto;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">'
                // Branded navy header, matching the portal's other emails.
                . '<div style="background-color:#0a1f44;background-image:linear-gradient(168deg,#0a1f44 0%,#0c2857 46%,#0a1f44 100%);padding:22px 24px;border-radius:14px 14px 0 0;text-align:center;">'
                .   '<img src="https://app.kiddietrac.com/login-wordmark.png" alt="KiddieTrac" width="190" style="max-width:190px;height:auto;display:block;margin:0 auto 10px;">'
                .   '<div style="color:#fff;font-size:17px;font-weight:800;">Crash report</div>'
                .   '<div style="color:rgba(255,255,255,.72);font-size:12.5px;margin-top:3px;">' . e($data['at']) . '</div>'
                . '</div>'
                . '<div class="kt-panel" style="background:#fff;padding:20px 24px;border:1px solid #E2E8F0;border-top:0;">'
                .   '<div style="text-align:center;margin-bottom:16px;">' . $ticketLine . '</div>'
                .   '<div class="kt-strong" style="font-size:15px;font-weight:800;color:#0F172A;margin:0 0 4px;">' . e($firstLine ?: 'Unknown error') . '</div>'
                .   '<div class="kt-muted" style="font-size:12.5px;color:#64748B;margin-bottom:16px;">on ' . e($data['screen'] ?: 'an unknown screen') . '</div>'
                .   '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;">'
                .     $row('When', $data['at'])
                .     $row('Who', ($data['user_name'] ?: 'Not signed in') . ($data['user_email'] ? ' <' . $data['user_email'] . '>' : '') . ($data['role'] ? ' · ' . $data['role'] : ''))
                .     $row('Screen', $data['screen'])
                .     $row('URL', $data['url'])
                .     $row('Last action', $data['last_action'] ?: 'not captured')
                .     $row('App', trim(($data['app'] ?: '?') . ' ' . $data['app_version']) . ($data['native'] ? ' · native app' : ' · browser'))
                .     $row('Viewport', trim($data['viewport'] . ($data['screen_size'] ? ' (screen ' . $data['screen_size'] . ')' : '')))
                .     $row('Device', $data['device'])
                .     $row('OS', $data['os'] . ($data['lang'] ? ' · ' . $data['lang'] : ''))
                .     $row('Service worker', $data['sw'])
                .     $row('IP', $data['ip'])
                .   '</table>'
                .   '<div class="kt-muted" style="font-size:11px;font-weight:800;letter-spacing:1px;color:#64748B;text-transform:uppercase;margin-bottom:6px;">Trace</div>'
                .   '<pre style="white-space:pre-wrap;word-break:break-word;background:#0B1220;color:#E2E8F0;padding:14px;border-radius:10px;font-size:11.5px;line-height:1.55;margin:0;">'
                .   e($data['trace']) . '</pre>'
                . '</div>'
                . '<div class="kt-muted" style="text-align:center;padding:14px 24px 24px;color:#94A3B8;font-size:11.5px;">'
                .   'Times shown in ' . e($data['timezone']) . ' · recorded ' . e($data['at_utc']) . ' UTC<br>'
                .   'Filed automatically by KiddieTrac from an in-app crash report.'
                . '</div></div></div></body></html>';

            $subject = '⚠️ KiddieTrac crash' . ($ticketId ? ' · ticket #' . (int) $ticketId : '')
                . ($repeats ? ' · happening again (' . $repeats . ' more report' . ($repeats === 1 ? '' : 's') . ')' : '')
                . ' · ' . mb_substr($firstLine ?: 'Unknown error', 0, 80);
            // A platform alert (TicketAlerts): reaches the HQ inbox even for a test agency, nobody else (2026-11-24).
            \App\Support\TicketAlerts::asPlatform(fn () => \App\Services\AgencyMailer::forAgency($agencyId)->mailer()->html($body, function ($m) use ($subject) {
                \App\Support\MailScope::platform($m);   // a crash alert to KiddieTrac: Platform in the email log
                $m->to('info@kiddietrac.com')->subject($subject);
            }));
        } catch (\Throwable $e) {
            // Do NOT swallow this silently again: the undefined-$data bug hid every
            // crash email for weeks because the failure had nowhere to be seen.
            \Illuminate\Support\Facades\Log::error('Crash report: could not email the team', ['error' => $e->getMessage()]);
        }
        return response()->json(['ok' => true, 'ticket_id' => $ticketId]);
    }
}
