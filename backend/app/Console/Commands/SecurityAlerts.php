<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * SOC 2 security monitoring (CC7.2 / CC7.3). Scans audit_logs for anomalies and
 * records them to security_alerts + the application log, then best-effort emails
 * the security contact. Scheduled every 15 min (see routes/console.php).
 *
 *   php artisan security:alerts [--window=20]
 */
class SecurityAlerts extends Command
{
    protected $signature = 'security:alerts {--window=20 : minutes of history to scan}';
    protected $description = 'Detect security anomalies in the audit log (brute force, MFA hammering) and record + alert.';

    public function handle(): int
    {
        $minutes = max(5, (int) $this->option('window'));
        $since = now()->subMinutes($minutes);
        $found = [];

        // 1) Brute force from a single IP.
        foreach (DB::table('audit_logs')->where('action', 'login_failed')->where('created_at', '>=', $since)
                    ->whereNotNull('ip_address')
                    ->select('ip_address', DB::raw('COUNT(*) as c'))
                    ->groupBy('ip_address')->having('c', '>=', 8)->get() as $r) {
            $found[] = ['type' => 'brute_force_ip', 'severity' => 'high', 'subject' => $r->ip_address,
                'details' => "{$r->c} failed logins from IP {$r->ip_address} in the last {$minutes} min"];
        }

        // 2) MFA hammering against a single account.
        foreach (DB::table('audit_logs')->where('action', 'mfa_failed')->where('created_at', '>=', $since)
                    ->whereNotNull('user_id')
                    ->select('user_id', DB::raw('COUNT(*) as c'))
                    ->groupBy('user_id')->having('c', '>=', 5)->get() as $r) {
            $found[] = ['type' => 'mfa_hammering', 'severity' => 'high', 'subject' => "user:{$r->user_id}",
                'details' => "{$r->c} failed MFA attempts for user #{$r->user_id} in the last {$minutes} min"];
        }

        // 3) Credential stuffing — many failed logins against one account (payload holds "email: …").
        /* Grouped by the LOGIN, not the whole payload. Grouping by payload split the same
           account across every distinct reason - info@ilearnhcc.com appeared twice on 20
           Sep, once with 11 wrong_password and once with 17 no_account_matching, when it
           was one campaign against one account - and it put raw JSON in the subject line,
           so the alert read "targeting {"login":"info@ilearnhcc.com","reason":...}". */
        $byLogin = [];
        foreach (DB::table('audit_logs')->where('action', 'login_failed')->where('created_at', '>=', $since)
                    ->whereNotNull('payload')->orderByDesc('id')->limit(500)->get(['payload']) as $r) {
            $d = json_decode((string) $r->payload, true) ?: [];
            $who = trim((string) ($d['login'] ?? ''));
            if ($who === '') { continue; }
            $byLogin[$who] = ($byLogin[$who] ?? 0) + 1;
        }
        foreach ($byLogin as $who => $c) {
            if ($c < 10) { continue; }
            $found[] = ['type' => 'credential_stuffing', 'severity' => 'high',
                'subject' => substr($who, 0, 180),
                'details' => "{$c} failed logins targeting {$who} in the last {$minutes} min"];
        }

        if (! $found) {
            $this->info("security:alerts — no anomalies in the last {$minutes}m");
            return self::SUCCESS;
        }

        $new = 0;
        foreach ($found as $a) {
            // De-dupe: skip if the same type+subject is already recorded inside the window.
            $dupe = DB::table('security_alerts')->where('type', $a['type'])->where('subject', $a['subject'])
                ->where('created_at', '>=', $since)->exists();
            if ($dupe) {
                continue;
            }
            DB::table('security_alerts')->insert($a + ['created_at' => now(), 'updated_at' => now()]);
            Log::warning('[SECURITY ALERT] ' . $a['details']);
            $new++;
        }

        $this->warn("security:alerts — {$new} new alert(s) recorded");

        if ($new > 0) {
            try {
                $this->notify($found);
            } catch (Throwable $e) {
                Log::error('security:alerts email failed: ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /**
     * WHO ACTUALLY READS THIS (2026-09-21).
     *
     * Anthony: "security alert should also be emailed to superadmin - i didnt get any of
     * the security alerts sent to me."
     *
     * He did not, and the detection was never the problem. On 20 September this command
     * correctly raised five high-severity alerts inside thirty minutes - "35 failed logins
     * from IP 202.61.157.28", "11 failed logins targeting info@ilearnhcc.com" - and
     * emailed all of them to `SECURITY_ALERT_EMAIL`, which is not set, so it fell through
     * to config('mail.from.address'): noreply@kiddietrac.com. The portal's own email
     * footer says that address is not a monitored inbox. The alerts were logged as SENT.
     *
     * So the recipients are now looked up, not configured: every ACTIVE platform admin,
     * by role, plus SECURITY_ALERT_EMAIL if somebody has set one. A monitoring control
     * whose delivery depends on an unset environment variable is a control that reports
     * success while going nowhere - and that is worse than having none, because the
     * silence reads as "no incidents".
     */
    private function notify(array $found): void
    {
        $to = [];
        try {
            $to = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.role', 'platform_admin')->where('ra.active', true)
                ->whereNull('u.deleted_at')->where('u.status', 'active')
                ->whereNotNull('u.email')->where('u.email', '!=', '')
                ->distinct()->pluck('u.email')->all();
        } catch (Throwable $e) {
        }

        $extra = trim((string) env('SECURITY_ALERT_EMAIL'));
        if ($extra !== '') {
            $to = array_merge($to, array_map('trim', explode(',', $extra)));
        }

        /* Never noreply@. If there is nobody to tell, say so loudly rather than sending
           into a void and recording it as a success. */
        $to = array_values(array_unique(array_filter($to, function ($a) {
            return $a !== '' && ! str_starts_with(mb_strtolower($a), 'noreply@');
        })));

        if (! $to) {
            Log::critical('[SECURITY ALERT] ' . count($found) . ' alert(s) raised and NOBODY to send them to: '
                . 'no active platform_admin has an email address and SECURITY_ALERT_EMAIL is unset.');

            return;
        }
        $rows = '';
        foreach ($found as $a) {
            $rows .= '<tr><td style="padding:10px 14px;border-bottom:1px solid #E2E8F0;font-size:13px;">'
                . '<strong style="color:#B91C1C;text-transform:uppercase;font-size:11px;letter-spacing:.05em;">'
                . e((string) $a['severity']) . '</strong><br>'
                . '<span style="color:#0F172A;">' . e((string) $a['details']) . '</span></td></tr>';
        }

        $body = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr><td style="font-size:15px;color:#334155;line-height:1.65;">'
            . 'KiddieTrac security monitoring has detected authentication activity worth looking at. '
            . 'This is automated and may be harmless &mdash; a member of staff mistyping a password '
            . 'repeatedly looks similar &mdash; but it is the kind of thing somebody should see.'
            . '</td></tr>'
            . '<tr><td style="padding:18px 0 0;"><table role="presentation" width="100%" cellpadding="0" '
            . 'cellspacing="0" style="border:1px solid #E2E8F0;border-radius:10px;overflow:hidden;">'
            . $rows . '</table></td></tr>'
            . '<tr><td style="padding:20px 0 0;font-size:13.5px;color:#475569;line-height:1.6;">'
            . '<strong>What to do:</strong> open the portal&rsquo;s Audit log and filter by the address '
            . 'or account named above. If you do not recognise it, an administrator can block the '
            . 'source and force a password change on the account that was targeted.'
            . '</td></tr>'
            . '<tr><td style="padding:16px 0 0;font-size:12px;color:#94A3B8;line-height:1.55;">'
            . 'Automated SOC 2 monitoring. Sent to every active platform administrator.'
            . '</td></tr></table>';

        $html = \App\Services\EmailTemplate::wrap(null, $body, [
            'eyebrow' => 'SECURITY',
            'title' => count($found) === 1 ? 'A security alert was raised' : count($found) . ' security alerts were raised',
            'subtitle' => 'Anomalous authentication activity',
            'preheader' => (string) ($found[0]['details'] ?? 'Anomalous authentication activity'),
        ]);

        $before = (int) DB::table('email_logs')->max('id');

        Mail::html($html, function ($m) use ($to) {
            // SOC 2 monitoring. A tenant switch must never be able to mute security alerts.
            \App\Support\MailScope::platform($m);
            $m->to($to)->subject('[KiddieTrac] Security alert — anomalous authentication activity');
        });

        /* "Sent" has to mean delivered. The whole reason this needed fixing is that three
           alerts were logged as sent while going to an unmonitored mailbox. */
        $suppressed = DB::table('email_logs')->where('id', '>', $before)
            ->where('status', 'suppressed')->count();
        if ($suppressed > 0) {
            Log::critical('[SECURITY ALERT] ' . $suppressed . ' alert email(s) were SUPPRESSED before delivery.');
        }

        $this->info('security:alerts — notified ' . implode(', ', $to));
    }
}
