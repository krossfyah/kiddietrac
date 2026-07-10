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
        foreach (DB::table('audit_logs')->where('action', 'login_failed')->where('created_at', '>=', $since)
                    ->whereNotNull('payload')
                    ->select('payload', DB::raw('COUNT(*) as c'))
                    ->groupBy('payload')->having('c', '>=', 10)->get() as $r) {
            $found[] = ['type' => 'credential_stuffing', 'severity' => 'high', 'subject' => substr((string) $r->payload, 0, 180),
                'details' => "{$r->c} failed logins targeting {$r->payload} in the last {$minutes} min"];
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

    private function notify(array $found): void
    {
        $to = env('SECURITY_ALERT_EMAIL') ?: config('mail.from.address');
        if (! $to) {
            return;
        }
        $body = "KiddieTrac security monitoring detected the following anomalies:\n\n";
        foreach ($found as $a) {
            $body .= "• [{$a['severity']}] {$a['details']}\n";
        }
        $body .= "\nReview the audit log in the portal. This is an automated SOC 2 monitoring alert.\n";

        Mail::raw($body, function ($m) use ($to) {
            $m->to($to)->subject('[KiddieTrac] Security alert — anomalous authentication activity');
        });
    }
}
