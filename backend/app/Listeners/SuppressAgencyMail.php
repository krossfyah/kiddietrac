<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Email kill-switch for a live agency (2026-07-14).
 *
 * While the platform is being tested against the Test Agency, no email may reach
 * anybody at the LIVE agency (iLearn). One stray invite, digest or check-in alert
 * to a real parent is not a bug you can take back.
 *
 * This is enforced in the MAIL LAYER, not at the call sites: returning false from
 * the MessageSending event cancels the send. Every path — controllers, queued
 * closures, scheduled commands, the daily summary, anything written later —
 * passes through here, so nothing has to remember to check.
 *
 * The whole message is cancelled if ANY recipient is suppressed, rather than
 * quietly stripping that person out: a half-delivered announcement is worse than
 * one that visibly didn't go.
 *
 * Configure with a comma-separated list of agency ids in .env:
 *
 *     MAIL_SUPPRESS_AGENCIES=2        # iLearn — remove to go live
 *     MAIL_SUPPRESS_AGENCIES=          # empty = send to everyone
 *
 * Suppressed sends are recorded in email_logs with status 'suppressed', so it is
 * obvious what WOULD have gone out — silence with no trail is its own hazard.
 */
class SuppressAgencyMail
{
    private const CACHE_KEY = 'kt.mail.suppressed_recipients';
    private const CACHE_TTL = 120;   // seconds — long enough to matter, short enough to react

    public function handle(MessageSending $event): bool
    {
        $recipients = $this->recipientsOf($event);
        if (! $recipients) {
            return true;
        }

        // An agency that has switched notifications off in its own settings is
        // silenced too, not just the .env kill-switch — so check the recipients
        // against BOTH rules via the shared Suppression service.
        foreach ($recipients as $addr) {
            if (in_array($addr, $this->allowlist(), true)) {
                continue;
            }
            $uid = DB::table('users')->where('email', $addr)->value('id');
            if ($uid && \App\Support\Suppression::isUser((int) $uid)) {
                $this->cancel($event, [$addr]);
                return false;
            }
        }

        $agencyIds = $this->suppressedAgencyIds();
        if (! $agencyIds) {
            return true;   // nothing suppressed → send normally
        }

        $blocked = $this->blockedAddresses($agencyIds);

        // Your own addresses still get mail — you hold a role at the live agency
        // too, so a blanket block would also stop the test sends you asked for.
        $allowed = $this->allowlist();
        $hits = array_values(array_diff(array_intersect($recipients, $blocked), $allowed));

        if (! $hits) {
            return true;
        }

        $this->cancel($event, $hits);

        return false;   // cancels the send
    }

    /** Record what we stopped — silence with no trail is its own hazard. */
    private function cancel(MessageSending $event, array $hits): void
    {
        $subject = (string) ($event->message->getSubject() ?? '(no subject)');

        Log::warning('Email SUPPRESSED — recipient belongs to a suppressed agency', [
            'blocked_recipients' => $hits,
            'subject' => $subject,
        ]);

        // Leave a visible trail of what would have been sent.
        try {
            if (Schema::hasTable('email_logs')) {
                DB::table('email_logs')->insert([
                    'to_email' => implode(', ', array_slice($hits, 0, 3)),
                    'to_name' => 'SUPPRESSED (live agency)',
                    'from_email' => 'noreply@kiddietrac.com',
                    'subject' => '[SUPPRESSED] ' . $subject,
                    'mailer' => config('mail.default'),
                    'status' => 'suppressed',
                    'error' => 'Recipient belongs to a suppressed agency (MAIL_SUPPRESS_AGENCIES).',
                    'tracking_token' => \Illuminate\Support\Str::random(32),
                    'opens' => 0,
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // Never let the audit trail break the kill-switch.
        }
    }


    /**
     * Addresses that are exempt from suppression even though they belong to a
     * suppressed agency — the tester's own inbox.
     *
     *     MAIL_SUPPRESS_ALLOWLIST=me@example.com,other@example.com
     *
     * @return string[]
     */
    private function allowlist(): array
    {
        $raw = (string) env('MAIL_SUPPRESS_ALLOWLIST', '');
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($v) => mb_strtolower(trim($v)),
            explode(',', $raw)
        )));
    }

    /** @return int[] */
    private function suppressedAgencyIds(): array
    {
        $raw = (string) env('MAIL_SUPPRESS_AGENCIES', '');
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($v) => (int) trim($v),
            explode(',', $raw)
        )));
    }

    /** Every address the message is addressed to, lower-cased. @return string[] */
    private function recipientsOf(MessageSending $event): array
    {
        $out = [];
        foreach (['getTo', 'getCc', 'getBcc'] as $method) {
            foreach ((array) $event->message->{$method}() as $address) {
                $email = method_exists($address, 'getAddress') ? $address->getAddress() : (string) $address;
                if ($email) {
                    $out[] = mb_strtolower(trim($email));
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Everyone at the suppressed agencies: staff (role_assignments), guardians
     * (through their family's centre), and the agency's own contact addresses.
     *
     * @param int[] $agencyIds
     * @return string[]
     */
    private function blockedAddresses(array $agencyIds): array
    {
        sort($agencyIds);
        $key = self::CACHE_KEY . ':' . implode(',', $agencyIds);

        return Cache::remember($key, self::CACHE_TTL, function () use ($agencyIds) {
            $emails = [];

            // Staff and admins holding a role at the agency.
            $staff = DB::table('role_assignments as ra')
                ->join('users as u', 'u.id', '=', 'ra.user_id')
                ->whereIn('ra.agency_id', $agencyIds)
                ->whereNotNull('u.email')
                ->pluck('u.email')->all();

            // Guardians, reached through the agency's centres.
            $centreIds = DB::table('centres')->whereIn('agency_id', $agencyIds)->pluck('id');
            $guardians = DB::table('guardians as g')
                ->join('families as f', 'f.id', '=', 'g.family_id')
                ->join('users as u', 'u.id', '=', 'g.user_id')
                ->whereIn('f.centre_id', $centreIds)
                ->whereNotNull('u.email')
                ->pluck('u.email')->all();

            // Also any staff attached to those centres but without an agency-level row.
            $centreStaff = DB::table('role_assignments as ra')
                ->join('users as u', 'u.id', '=', 'ra.user_id')
                ->whereIn('ra.centre_id', $centreIds)
                ->whereNotNull('u.email')
                ->pluck('u.email')->all();

            // The agency's own addresses.
            $agencyAddrs = DB::table('agencies')
                ->whereIn('id', $agencyIds)
                ->get(['contact_email', 'brand_support_email', 'email_from_address'])
                ->flatMap(fn ($a) => [$a->contact_email, $a->brand_support_email, $a->email_from_address])
                ->filter()
                ->all();

            // Family contact addresses (a family can carry an email of its own).
            $familyAddrs = DB::table('families')
                ->whereIn('centre_id', $centreIds)
                ->whereNotNull('primary_email')
                ->pluck('primary_email')->all();

            $emails = array_merge($staff, $guardians, $centreStaff, $agencyAddrs, $familyAddrs);

            return array_values(array_unique(array_map(
                fn ($e) => mb_strtolower(trim((string) $e)),
                array_filter($emails)
            )));
        });
    }
}
