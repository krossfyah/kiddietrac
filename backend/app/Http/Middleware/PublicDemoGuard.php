<?php

namespace App\Http\Middleware;

use App\Support\PublicDemo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PUBLIC DEMO SESSIONS (2026-10-31; see App\Support\PublicDemo). Every API request; does nothing unless the caller is a
 * public demo session. Then:
 *  1. RISKY actions are refused with a friendly message: anything that would reach real people, money or our accounts
 *     (calls, texts, email out, invites, payments, provider/integration settings, passwords/MFA/passkeys, account
 *     deletion, AI that costs credit).
 *  2. SHARED records stay intact for the next visitor: deleting, and editing or archiving existing records, are refused.
 *     Adding new things (messages, check-ins, logs, observations, photos, walks, incidents, tasks…) is the demo.
 *  3. Every row a visitor INSERTS is recorded in web_demo_rows, so kt:reset-web-demo removes exactly those each night.
 */
class PublicDemoGuard
{
    private const RISKY = [
        '#^(admin/)?(voice|sms)(/|-|$)#', '#^admin/sms-settings#', '#^safe-arrival/test-call#', '#^chat/voice#',
        '#^me/(sms-consent|voice-opt-out|payout-method|account-deletion|payroll-documents/\d+/email)#',
        '#^auth/(change-password|set-password|mfa/|passkey/|reset/)#', '#^passkeys#', '#^auth/me$#',
        '#^director/(families/\d+/invite|guardians/\d+/login-invite|staff/invite|invitation-codes)#',
        '#^admin/users(/|$)#', '#^import/#', '#^admin/rsvp/#', '#^report-cards/\d+/send#',
        '#(^|/)email$#', '#^email/#', '#text-email$#', '#^sales/#', '#^marketing/#',
        '#^admin/(email-|invoices/email-batch|donations/campaigns|donation-receipts|account-ledgers/\d+/email|forms/\d+/email|managed-forms/.*/(resend|email)|prospect-ndas)#',
        '#^invoices/\d+/charge#', '#^parent/(billing|helcim|zum)/#', '#^director/zum/#', '#^refunds#',
        '#^admin/(payment-runs|payment-providers|kt-invoices|payroll|payroll-settings|qbo-config|sage-config|xero-config|fee-plans/\d+/generate-invoices|invoices/generate-batch)#',
        '#^director/invoices/(generate|\d+/(issue|payments|receipt|void))#',
        '#^(qbo|xero|sage|sage50)/#', '#^integration/#', '#^educator-points/(giftbit|tango|awards|thanks)#', '#^platform/#',
        '#^ai/#', '#^provider/(payee-invoices/\d+/send|payroll-documents/\d+/email)#', '#^agency/payslip-settings#',
    ];
    /** Edits that only touch the visitor's own screen. */
    private const EDIT_OK = ['#^me/(markers|notification-prefs|notification-sounds|contact-prefs)$#', '#^auth/me/ui-prefs$#', '#(^|/)(read|seen|typing|react)(/|$)#'];
    private const DESTRUCTIVE = '#/(archive|unarchive|withdraw|deactivate|reactivate|close|void|suspend|restore|delete|remove|merge|transfer|discharge|graduate|decline|revoke)(/|$)#';
    private const UNTRACKED = ['web_demo_rows', 'personal_access_tokens', 'audit_logs', 'email_logs', 'jobs', 'failed_jobs', 'cache', 'cache_locks',
        'sessions', 'client_net_samples', 'rate_limits', 'telescope_entries'];

    /** Signed-out ways to change a password. Refused for a published demo address (2026-10-31, password logins). */
    private const RESET_PATHS = '#^auth/(forgot|reset|set-password)#';

    public function handle(Request $request, Closure $next)
    {
        $path = preg_replace('#^api/v1/#', '', ltrim($request->path(), '/'));
        $method = strtoupper($request->method());

        /* The demo logins are printed on the website, so nobody may take one over from the signed-out side either:
           no reset code, no reset, no set-password for a demo address. (The code could never arrive - mail and texts
           to a demo person are cancelled - but refusing here means there is nothing to try.) */
        if ($method === 'POST' && preg_match(self::RESET_PATHS, $path)) {
            $id = (string) ($request->input('email') ?? $request->input('login') ?? $request->input('identifier') ?? '');
            if (trim($id) !== '' && PublicDemo::isDemoLogin($id)) {
                return response()->json(['demo' => true, 'message' => 'This is a demo account, so its password cannot be changed here. KiddieTrac sales can give you the demo login.'], 403);
            }
        }

        /* KEYED ON THE PERSON, NOT THE TOKEN (2026-10-31): since the demo phone signs in with a password, a demo
           session holds an ordinary sign-in token. PublicDemo::isSession() is true for ANY session of a demo-only
           person, whatever its token is called. */
        if (! PublicDemo::isSession()) return $next($request);
        /* A demo session's jobs run at once (2026-10-31): its email is never delivered - it is cancelled and kept for the demo
           phone's Mail app - and on the queue (a one-minute cron) it reached the phone up to a minute after the app banner. */
        config(['queue.default' => 'sync']);
        if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'OPTIONS') {
            $why = null;
            /* THE DEMO PHONE'S MAIL APP (2026-10-31): emailing an invoice to its own demo family is allowed. The email is
               never delivered - SuppressAgencyMail cancels every demo send and keeps a copy for the demo phone's Mail app
               (App\Support\WebDemoMail). Only to demo people: any other address keeps this switched off. */
            $demoMailOk = (bool) preg_match('#^admin/invoices/(native|external)/\d+/email$#', $path);
            if ($demoMailOk) {
                $addrs = array_filter(array_merge([(string) $request->input('to', '')], (array) $request->input('to_list', [])), fn ($x) => trim((string) $x) !== '');
                foreach ($addrs as $x) { if (! PublicDemo::isDemoEmail((string) $x)) { $demoMailOk = false; break; } }
            }
            if (! $demoMailOk) { foreach (self::RISKY as $re) { if (preg_match($re, $path)) { $why = 'risky'; break; } } }
            if (! $why && $method === 'DELETE') $why = 'shared';
            if (! $why && in_array($method, ['PATCH', 'PUT'], true)) {
                $ok = false; foreach (self::EDIT_OK as $re) { if (preg_match($re, $path)) { $ok = true; break; } }
                if (! $ok) $why = 'shared';
            }
            if (! $why && preg_match(self::DESTRUCTIVE, $path)) $why = 'shared';
            if ($why) {
                return response()->json(['demo' => true, 'message' => $why === 'risky'
                    ? 'This is the public demo, so this is switched off here (it would reach real people, payments or accounts). Everything else is yours to try.'
                    : 'This is the public demo, so changing or removing existing records is switched off — try adding something new instead.'], 403);
            }
        }

        // Record what this visitor inserts (table + id), for the nightly reset.
        $rows = [];
        DB::listen(function ($q) use (&$rows) {
            if (! preg_match('/^\s*insert\s+(?:ignore\s+)?into\s+[`"]?(\w+)[`"]?\s*\(([^)]*)\)/i', $q->sql, $m)) return;
            $table = strtolower($m[1]);
            if (in_array($table, self::UNTRACKED, true)) return;
            try {
                $first = (int) DB::getPdo()->lastInsertId();
                if ($first <= 0) return;
                $cols = max(1, substr_count($m[2], ',') + 1);
                $n = max(1, intdiv(count($q->bindings), $cols));
                for ($i = 0; $i < $n; $i++) $rows[] = [$table, $first + $i];
            } catch (\Throwable $e) {}
        });
        $response = $next($request);
        if ($rows) {
            try {
                $now = now();
                DB::table('web_demo_rows')->insert(array_map(fn ($r) => ['table_name' => $r[0], 'row_id' => $r[1], 'created_at' => $now], $rows));
            } catch (\Throwable $e) {}
        }
        return $response;
    }
}
