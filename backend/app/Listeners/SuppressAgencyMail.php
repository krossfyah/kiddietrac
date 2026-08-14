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
        // Explicit admin test sends carry a one-off bypass header — they target a
        // specific address on purpose (e.g. the tester's own inbox) and must not
        // be caught by the live-agency kill-switch. Only manual test commands set
        // it; the header is stripped so it never rides along on the wire.
        try {
            $hdrs = $event->message->getHeaders();
            if ($hdrs && $hdrs->has('X-KT-Bypass-Suppression')) {
                $hdrs->remove('X-KT-Bypass-Suppression');
                Log::info('Email suppression bypassed for an explicit test send.', [
                    'subject' => (string) ($event->message->getSubject() ?? ''),
                ]);
                return true;
            }
        } catch (\Throwable $e) {
        }

        $recipients = $this->recipientsOf($event);
        if (! $recipients) {
            return true;
        }

        // 0) NOT-ONBOARDED gate. A parent/user who hasn't accepted their invite yet
        //    (users.status = 'invited') must receive NOTHING except the invite /
        //    welcome email itself — no digests, reminders or announcements. The
        //    invite carries an X-KT-Invite header so it's exempt (and reaches them).
        // Detect the invite/account-email exemption ONCE. An invite must reach a
        // not-yet-onboarded user AND skip the pre-boarding centre/room gate (an
        // invite IS the pre-boarding step) — while still respecting the agency's
        // master toggle below.
        $isInvite = false;
        try {
            $hdrs2 = $event->message->getHeaders();
            $isInvite = (bool) ($hdrs2 && $hdrs2->has('X-KT-Invite'));
            if ($isInvite && $hdrs2) {
                $hdrs2->remove('X-KT-Invite');
            }
        } catch (\Throwable $e) {
        }

        if (! $isInvite) {
            try {
                $pending = DB::table('users')
                    // Not-yet-onboarded states: 'invited' (invite sent, awaiting
                    // acceptance) and 'not_invited' (imported, never invited). Both
                    // get NOTHING but the invite/account email itself.
                    ->whereIn('status', ['invited', 'not_invited'])
                    ->whereNotNull('email')
                    ->whereIn(DB::raw('LOWER(TRIM(email))'), $recipients)
                    ->pluck('email')
                    ->map(fn ($e) => mb_strtolower(trim((string) $e)))
                    ->values()->all();
                if ($pending) {
                    $this->cancel($event, $pending, 'Recipient has not accepted their invite yet (not onboarded).');
                    return false;
                }
            } catch (\Throwable $e) {
                // never let this gate break the mail layer
            }
        }

        // 0b) SUSPENDED / DEACTIVATED gate. Suspending a family blocks its guardians
        //     from signing in; it did not stop the mail, so they carried on receiving
        //     daily summaries and "has your child arrived?" reminders about a portal
        //     that refuses them at the door.
        //
        //     Enforced HERE rather than in each command on purpose. Three commands
        //     filtered suspended users and the rest did not, because a rule that lives
        //     at the call sites is a rule every new call site has to remember. In the
        //     mail layer it covers senders that do not exist yet.
        //
        //     The account-notice exemption is not a loophole, it is the point: the
        //     email explaining the suspension is addressed to a suspended user by
        //     definition, as is the deactivation notice. Without it this gate would
        //     swallow the one message that makes the lockout explicable.
        $isAccountNotice = false;
        try {
            $hdrs3 = $event->message->getHeaders();
            $isAccountNotice = (bool) ($hdrs3 && $hdrs3->has('X-KT-Account-Notice'));
            if ($isAccountNotice && $hdrs3) {
                $hdrs3->remove('X-KT-Account-Notice');
            }
        } catch (\Throwable $e) {
        }

        if (! $isAccountNotice) {
            try {
                $barred = DB::table('users')
                    ->whereIn('status', ['suspended', 'deactivated'])
                    ->whereNotNull('email')
                    ->whereIn(DB::raw('LOWER(TRIM(email))'), $recipients)
                    ->pluck('email')
                    ->map(fn ($e) => mb_strtolower(trim((string) $e)))
                    ->values()->all();
                if ($barred) {
                    $this->cancel($event, $barred,
                        'Recipient\'s access is suspended or deactivated — notifications are paused.');
                    return false;
                }
            } catch (\Throwable $e) {
                // never let this gate break the mail layer
            }
        }

        // 1) The agency's OWN toggle ("Send notifications and emails") is
        //    ABSOLUTE — off means off, even for allowlisted addresses. This is
        //    what the Settings switch strictly controls.
        foreach ($recipients as $addr) {
            $uid = DB::table('users')->where('email', $addr)->value('id');
            if (! $uid) {
                continue;
            }
            if (\App\Support\Suppression::agencyOff((int) $uid)) {
                // Name the ACTUAL switch. Falling through to cancel()'s default
                // blamed MAIL_SUPPRESS_AGENCIES, so the log said the env kill-switch
                // stopped mail that the env kill-switch had nothing to do with —
                // which is why 857 suppressed emails looked inexplicable against an
                // empty suppression list.
                $this->cancel($event, [$addr],
                    'The agency master switch for notifications and emails is OFF (Settings).');
                return false;
            }
            // 1a) Centre / room switch (pre-boarding). A recipient whose every
            //     centre / room is switched off for email is held back even while
            //     the agency master switch is on. SKIPPED for invites — an invite
            //     is the pre-boarding step itself and must reach the recipient.
            // The tester allowlist exempts an address from the env kill-switch but not,
            // until now, from this gate - so a requested test send was silently held
            // back because the ADMIN doing the testing happens to belong to centres
            // whose email is switched off. A send you explicitly asked for should
            // arrive; the gate still applies to everyone else.
            if (! $isInvite && ! in_array($addr, $this->allowlist(), true)
                && \App\Support\Suppression::blockedByCentreRoom((int) $uid)) {
                $this->cancel($event, [$addr],
                    'Every centre/room this recipient belongs to has email switched OFF '
                    . '(Settings → Email settings → Centre & room email delivery).');
                return false;
            }
        }

        // 2) The .env testing kill-switch (MAIL_SUPPRESS_AGENCIES) — here the
        //    allowlist DOES exempt the tester's own inbox so requested test
        //    sends still arrive while an agency's own toggle is still ON.
        foreach ($recipients as $addr) {
            if (in_array($addr, $this->allowlist(), true)) {
                continue;
            }
            $uid = DB::table('users')->where('email', $addr)->value('id');
            if ($uid && \App\Support\Suppression::isUser((int) $uid)) {
                $this->cancel($event, [$addr],
                    'Recipient belongs to an agency listed in MAIL_SUPPRESS_AGENCIES (.env kill-switch).');
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
    private function cancel(MessageSending $event, array $hits, ?string $reason = null): void
    {
        $subject = (string) ($event->message->getSubject() ?? '(no subject)');
        $reason = $reason ?: 'Recipient belongs to a suppressed agency (MAIL_SUPPRESS_AGENCIES).';

        Log::warning('Email SUPPRESSED — ' . $reason, [
            'blocked_recipients' => $hits,
            'subject' => $subject,
        ]);

        // Leave a visible trail of what would have been sent.
        try {
            if (Schema::hasTable('email_logs')) {
                $supAgency = null;
                try { if (Schema::hasColumn('email_logs', 'agency_id') && ! empty($hits[0])) $supAgency = \App\Support\AgencyMail::agencyOfEmail($hits[0]); } catch (\Throwable $e) {}
                // Capture the HTML that WOULD have been sent so the email log's 👁
                // preview works for suppressed rows too (previously stored no body).
                $supBody = null;
                try {
                    $h = $event->message->getHtmlBody();
                    if (! is_string($h)) $h = $event->message->getTextBody();
                    if (is_string($h) && $h !== '') $supBody = mb_substr($h, 0, 500000);
                } catch (\Throwable $e) {}
                // Copied recipients matter MOST on a suppressed row: a notice that was
                // blocked was also not copied to anyone, and the log should say who
                // missed it rather than naming only the primary recipient.
                $supCc = null; $supBcc = null;
                try {
                    $m = $event->message;
                    $supCc = collect($m->getCc() ?: [])->map(fn ($a) => $a->getAddress())->filter()->implode(', ') ?: null;
                    $supBcc = collect($m->getBcc() ?: [])->map(fn ($a) => $a->getAddress())->filter()->implode(', ') ?: null;
                } catch (\Throwable $e) {}

                DB::table('email_logs')->insert([
                    'agency_id' => $supAgency,
                    'cc' => \Illuminate\Support\Facades\Schema::hasColumn('email_logs', 'cc') ? $supCc : null,
                    'bcc' => \Illuminate\Support\Facades\Schema::hasColumn('email_logs', 'bcc') ? $supBcc : null,
                    'to_email' => implode(', ', array_slice($hits, 0, 3)),
                    'to_name' => 'SUPPRESSED (live agency)',
                    'from_email' => 'noreply@kiddietrac.com',
                    'subject' => '[SUPPRESSED] ' . $subject,
                    'mailer' => config('mail.default'),
                    'status' => 'suppressed',
                    'error' => $reason,
                    'body_html' => $supBody,
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
        $raw = (string) config('suppression.allowlist', '');
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
        // Single source of truth (mode-aware: denylist env list, or the allowlist
        // complement). Keeps the listener in step with push / SMS / scheduled jobs.
        return \App\Support\Suppression::agencyIds();
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
        $allow = \App\Support\Suppression::allowedAgencyIds();
        sort($allow);
        $key = self::CACHE_KEY . ':' . implode(',', $agencyIds) . '|a:' . implode(',', $allow);

        return Cache::remember($key, self::CACHE_TTL, function () use ($agencyIds, $allow) {
            $blocked = $this->addressesAtAgencies($agencyIds);
            if (! $blocked) {
                return [];
            }

            // Subtract anyone ALSO reachable at an allowed agency, so a shared /
            // duplicate email address still receives its allowed agency's mail.
            $allowed = $this->addressesAtAgencies($allow);

            return array_values(array_diff($blocked, $allowed));
        });
    }

    /**
     * Every address reachable at the given agencies: staff (role_assignments),
     * guardians (through their family's centre), and the agencies' own contacts.
     *
     * @param int[] $agencyIds
     * @return string[]
     */
    private function addressesAtAgencies(array $agencyIds): array
    {
        if (! $agencyIds) {
            return [];
        }

        return Cache::remember(self::CACHE_KEY . '.at:' . implode(',', $agencyIds), self::CACHE_TTL, function () use ($agencyIds) {
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
