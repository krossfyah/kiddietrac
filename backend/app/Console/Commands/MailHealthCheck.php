<?php

namespace App\Console\Commands;

use App\Support\PlatformSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Daily guard so Graph email can never silently die. Verifies the Graph token
 * mints and warns superadmins BEFORE the client secret expires. (Even if Graph
 * is down, sending already falls back to sendmail via the failover transport —
 * this just makes sure a human is told to rotate the secret in time.)
 */
class MailHealthCheck extends Command
{
    protected $signature = 'kiddietrac:mail-health';

    protected $description = 'Verify Microsoft Graph mail credentials and warn before the client secret expires.';

    public function handle(): int
    {
        PlatformSettings::applyMail();
        $mailer = PlatformSettings::get('mail.mailer');
        if ($mailer !== 'graph' && $mailer !== 'failover') {
            $this->info('Graph not the active mailer — nothing to check.');
            return self::SUCCESS;
        }

        $graph = config('mail.mailers.graph', []);
        $problems = [];

        // 1) Token mint test.
        if (! empty($graph['tenant']) && ! empty($graph['client_id']) && ! empty($graph['client_secret'])) {
            try {
                $r = Http::asForm()->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->timeout(20)
                    ->post('https://login.microsoftonline.com/'.rawurlencode($graph['tenant']).'/oauth2/v2.0/token', [
                        'client_id' => $graph['client_id'], 'client_secret' => $graph['client_secret'],
                        'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials',
                    ]);
                if (! $r->json('access_token')) {
                    $problems[] = 'Microsoft Graph authentication FAILED (' . substr((string) ($r->json('error_description') ?? $r->body()), 0, 160) . '). Email is currently falling back to sendmail — rotate the client secret in Azure and update it in the portal.';
                }
            } catch (\Throwable $e) {
                $problems[] = 'Microsoft Graph token check errored: ' . $e->getMessage();
            }
        } else {
            $problems[] = 'Microsoft Graph is selected but not fully configured (tenant/client id/secret missing).';
        }

        // 2) Secret-expiry early warning.
        $exp = PlatformSettings::get('mail.graph.secret_expires_at');
        if ($exp) {
            try {
                $days = (int) floor(now()->floatDiffInDays(Carbon::parse($exp), false));
                if ($days < 0) {
                    $problems[] = 'The Microsoft Graph client secret EXPIRED on ' . $exp . '. Email is falling back to sendmail — create a new secret in Azure and update the portal now.';
                } elseif ($days <= 30) {
                    $problems[] = "The Microsoft Graph client secret expires in {$days} day(s) (on {$exp}). Create a new secret in Azure and update it in the portal before then to keep Graph delivery.";
                }
            } catch (\Throwable $e) {
                // ignore an unparseable date
            }
        }

        if (! $problems) {
            $this->info('Mail health OK.');
            return self::SUCCESS;
        }

        $this->warn(implode(' | ', $problems));

        // Alert superadmins: in-app notification + email (email goes via failover,
        // so it sends even when Graph itself is the thing that's broken).
        try {
            $adminIds = DB::table('role_assignments')->where('role', 'platform_admin')->where('active', 1)
                ->pluck('user_id')->unique()->values();
            $now = now();
            $body = implode(' ', $problems);
            foreach ($adminIds as $uid) {
                DB::table('notifications')->insert([
                    'user_id' => (int) $uid, 'type' => 'mail_health',
                    'title' => '⚠️ Email delivery needs attention',
                    'body' => mb_substr($body, 0, 500),
                    'data' => json_encode(['problems' => $problems]),
                    'created_at' => $now,
                ]);
            }
            $emails = DB::table('users')->whereIn('id', $adminIds)->whereNotNull('email')->pluck('email')->filter()->unique()->all();
            if ($emails) {
                $html = '<h2>KiddieTrac email delivery needs attention</h2>' . implode('', array_map(fn ($p) => '<p>' . e($p) . '</p>', $problems));
                Mail::html($html, function ($m) use ($emails) {
                    $first = array_shift($emails);
                    $m->to($first)->subject('KiddieTrac — email delivery needs attention');
                    foreach ($emails as $cc) { $m->cc($cc); }
                    $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                });
            }
        } catch (\Throwable $e) {
            $this->error('Alert dispatch failed: ' . $e->getMessage());
        }

        return self::SUCCESS;
    }
}
