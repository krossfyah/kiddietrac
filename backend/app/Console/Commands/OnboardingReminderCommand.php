<?php

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Daily reminder to users who were INVITED but never onboarded (never accepted
 * their invite / set a password). White-labelled per agency: agencies on the
 * white-label plan get their OWN logo in the banner + footer via EmailTemplate.
 *
 * The email carries the X-KT-Invite header so it is EXEMPT from the not-onboarded
 * suppression gate (the whole point is to reach someone who hasn't accepted yet),
 * while still honouring the agency's master notification / suppression rules.
 *
 *   php artisan kiddietrac:onboarding-reminders                 # send to all pending
 *   php artisan kiddietrac:onboarding-reminders --test=me@x.com # one white-label sample
 */
class OnboardingReminderCommand extends Command
{
    protected $signature = 'kiddietrac:onboarding-reminders {--test= : send a single white-labelled sample to this address and exit} {--force : ignore each agency\'s configured send-hour and send now}';

    protected $description = 'Remind invited-but-not-onboarded users to finish setting up (white-labelled per agency; configurable per agency).';

    public function handle(): int
    {
        $test = (string) $this->option('test');
        if ($test !== '') {
            // Use an agency that IS white-labelled so the branded logo/header/footer
            // are visible in the sample. Prefer iLearn; fall back to the first agency.
            $agencyId = (int) (DB::table('agencies')->where('name', 'like', '%iLearn%')->value('id')
                ?: DB::table('agencies')->orderBy('id')->value('id'));
            $agencyName = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'Your agency');
            $link = 'https://app.kiddietrac.com/set-password.html?token=SAMPLE-PREVIEW-TOKEN';
            $this->send($test, 'Anthony', $agencyName, $agencyId, $link, true);
            $this->info("Sample onboarding reminder sent to {$test} using agency #{$agencyId} ({$agencyName}) branding.");

            return self::SUCCESS;
        }

        // Runs HOURLY (see Kernel). Each agency configures whether reminders are on
        // and which hour to send — defaults: ENABLED, 07:00 agency-local (Eastern).
        // The command self-gates so only the agencies due this hour actually send.
        $force = (bool) $this->option('force');
        $nowHour = (int) \Illuminate\Support\Carbon::now('America/Toronto')->format('G'); // 0-23 Eastern

        $agencies = DB::table('agencies')->get(['id', 'name', 'settings']);
        $totalSent = 0;
        $agenciesRun = 0;
        foreach ($agencies as $ag) {
            $s = json_decode($ag->settings ?? '{}', true) ?: [];
            $enabled = array_key_exists('onboarding_reminders_enabled', $s) ? (bool) $s['onboarding_reminders_enabled'] : true; // default ON
            $hour = isset($s['onboarding_reminder_hour']) ? (int) $s['onboarding_reminder_hour'] : 7;                            // default 7am
            if (! $enabled) {
                continue;
            }
            if (! $force && $hour !== $nowHour) {
                continue;
            }
            $agenciesRun++;

            $users = DB::table('users as u')
                ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
                ->whereNull('u.onboarded_at')
                ->whereNull('u.deleted_at')
                ->whereNotNull('u.email')
                ->where('ra.active', 1)
                ->where('ra.agency_id', $ag->id)
                ->groupBy('u.id', 'u.email', 'u.first_name', 'u.last_name')
                ->select('u.id', 'u.email', 'u.first_name', 'u.last_name')
                ->get();

            foreach ($users as $u) {
                try {
                    $token = bin2hex(random_bytes(32));
                    DB::table('password_resets')->insert([
                        'email'      => $u->email,
                        'user_id'    => $u->id,
                        'token'      => hash('sha256', $token),
                        'expires_at' => now()->addDays(7),
                        'created_at' => now(),
                    ]);
                    $link = 'https://app.kiddietrac.com/set-password.html?token=' . $token;
                    $this->send($u->email, (string) ($u->first_name ?: 'there'), (string) $ag->name, (int) $ag->id, $link, false);
                    $totalSent++;
                } catch (\Throwable $e) {
                    Log::warning('Onboarding reminder failed', ['user' => $u->id, 'error' => $e->getMessage()]);
                }
            }
        }

        $this->info("Onboarding reminders — hour {$nowHour} ET, {$agenciesRun} agency(ies) due, {$totalSent} email(s) sent.");

        return self::SUCCESS;
    }

    private function send(string $email, string $firstName, string $agencyName, int $agencyId, string $link, bool $sync): void
    {
        $first      = htmlspecialchars($firstName ?: 'there');
        $safeAgency = htmlspecialchars($agencyName);
        $safeLink   = htmlspecialchars($link);

        $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">Hi ' . $first . ', you were invited to <strong>' . $safeAgency . '</strong> on KiddieTrac, but your account isn\'t set up yet.</p>'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;">It only takes a minute — set your password and you\'ll go straight in to see your child\'s day, messages and forms.</p>'
            . EmailTemplate::button('Finish setting up →', $link)
            . '<p style="margin:16px 0 0;font-size:12px;color:#64748B;">Or paste this into your browser:<br><a href="' . $safeLink . '" style="color:#1F6080;">' . $safeLink . '</a></p>'
            . '<p style="margin:14px 0 0;font-size:12px;color:#94A3B8;">This link expires in 7 days. If you didn\'t expect this, you can safely ignore it.</p>';

        // White-label wrap: agency logo on the banner + agency footer (falls back to
        // the KiddieTrac look for non-white-label agencies).
        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow'   => 'REMINDER',
            'title'     => $agencyName,
            'subtitle'  => 'Finish setting up your account',
            'preheader' => 'You haven\'t set up your ' . $agencyName . ' account yet — it only takes a minute.',
        ]);
        $subject = 'Reminder: finish setting up your ' . $agencyName . ' account';
        $name    = $firstName;

        $sendClosure = function () use ($agencyId, $email, $name, $subject, $html) {
            AgencyMailer::forAgency($agencyId)->mailer()->html($html, function ($m) use ($email, $name, $subject) {
                $m->to($email, $name ?: null)
                    ->from('noreply@kiddietrac.com', 'KiddieTrac')
                    ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
                    ->subject($subject);
                $m->getHeaders()->addTextHeader('X-KT-Logged', '1');
                // Reaches a not-yet-onboarded user (exempt from the not-onboarded gate).
                $m->getHeaders()->addTextHeader('X-KT-Invite', '1');
                $m->getHeaders()->addTextHeader('List-Unsubscribe', '<mailto:support@kiddietrac.com>');
            });
        };

        // Test = send now (so it lands immediately); bulk run = queue it.
        if ($sync) {
            $sendClosure();
        } else {
            dispatch($sendClosure)->onQueue('mail');
        }

        if (Schema::hasTable('email_logs')) {
            DB::table('email_logs')->insert([
                'agency_id'      => $agencyId ?: null,
                'to_email'       => $email,
                'to_name'        => $name,
                'from_email'     => 'noreply@kiddietrac.com',
                'subject'        => $subject,
                'mailer'         => config('mail.default'),
                'status'         => 'sent',
                'body_html'      => mb_substr($html, 0, 500000),
                'tracking_token' => Str::random(32),
                'opens'          => 0,
                'created_at'     => now(),
            ]);
        }
    }
}
