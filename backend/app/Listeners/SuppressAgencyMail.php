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
        $isEngagement = false;
        try {
            $hdrs2 = $event->message->getHeaders();
            $isInvite = (bool) ($hdrs2 && $hdrs2->has('X-KT-Invite'));
            /* Engagement mail = digests, summaries, chat round-ups: the
               "come and use the portal" family. Only THIS is withheld from
               someone who has not accepted their invite. Everything else is
               transactional and must reach them. */
            $isEngagement = (bool) ($hdrs2 && $hdrs2->has('X-KT-Engagement'));
            if ($isEngagement && $hdrs2) {
                $hdrs2->remove('X-KT-Engagement');
            }
            if ($isInvite && $hdrs2) {
                $hdrs2->remove('X-KT-Invite');
            }
        } catch (\Throwable $e) {
        }

        /* DO NOT WEAKEN THIS. Product decision, 2026-08-24: an account that has not
           been claimed receives NOTHING except its invite. Full stop.

           I inverted it earlier the same day so it blocked only mail tagged as
           engagement, because an NDA receipt was being cancelled for someone whose
           status still read 'invited'. That was the wrong fix for a real bug: the
           defect was that users.status never advanced when somebody actually
           claimed their account. AccountStatus::markClaimed() now promotes
           invited -> active at every login path, so anyone doing anything in the
           portal is 'active' before they do it and is not in scope here.

           If mail ever appears to need an exemption from this gate, fix the mail or
           ask -- do not widen the gate. Superseded reasoning follows:

           This used to read `if (! $isInvite)`, i.e. cancel
           everything that was not an invite -- which cancelled password resets,
           NDA receipts and "your child arrived" notices for anyone still marked
           'invited'. It now cancels only what is explicitly engagement mail. */
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
                    $this->cancel($event, $pending, 'Digest/summary withheld: recipient has not accepted their invite yet.');
                    return false;
                }
            } catch (\Throwable $e) {
                // never let this gate break the mail layer
            }
        }

        /* 0a) FIRST-PASSWORD INVARIANT.

           A message tagged X-KT-Onboarding-Invite exists to hand someone a link to
           set their password for the first time. That is only ever right for an
           account nobody has claimed yet. Sending it to an active user tells them
           to set a password they already have, and mints them a live reset token
           they never asked for.

           Enforced here rather than in each command on purpose: thirteen senders
           write their own audience queries, and one of them getting it wrong is
           how five people were mailed today. A sender may still choose its
           recipients badly -- it just can no longer reach the wrong ones. */
        try {
            $hdrsInv = $event->message->getHeaders();
            if ($hdrsInv && $hdrsInv->has('X-KT-Onboarding-Invite')) {
                $hdrsInv->remove('X-KT-Onboarding-Invite');
                $claimed = DB::table('users')
                    ->whereNotIn('status', ['invited', 'not_invited'])
                    ->whereNotNull('email')
                    ->whereIn(DB::raw('LOWER(TRIM(email))'), $recipients)
                    ->pluck('email')
                    ->map(fn ($e) => mb_strtolower(trim((string) $e)))
                    ->values()->all();
                if ($claimed) {
                    $this->cancel($event, $claimed,
                        'Set-password invite withheld: this account has already been claimed.');
                    return false;
                }
            }
        } catch (\Throwable $e) {
            // A guard must never be the reason mail stops working.
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
                $seenAddr = [];
                /* Block an address only when EVERY account on it is switched off.
                   Several accounts may share one email — the product allows it, told
                   apart at login by username — so the presence of one deactivated
                   namesake says nothing about who the message is for. Matching on the
                   address alone silenced a live person's invite for a full day. */
                $barred = [];
                foreach (DB::table('users')->whereNotNull('email')
                    ->whereIn(DB::raw('LOWER(TRIM(email))'), $recipients)
                    ->get(['email', 'status', 'deleted_at']) as $cand) {
                    $addr = mb_strtolower(trim((string) $cand->email));
                    if (isset($seenAddr[$addr])) {
                        continue;
                    }
                    $onAddr = DB::table('users')->whereRaw('LOWER(TRIM(email)) = ?', [$addr])->get(['status', 'deleted_at']);
                    $allOff = $onAddr->isNotEmpty() && $onAddr->every(
                        fn ($u) => $u->deleted_at !== null
                            || in_array((string) $u->status, ['suspended', 'deactivated'], true)
                    );
                    $seenAddr[$addr] = true;
                    if ($allOff) {
                        $barred[] = $addr;
                    }
                }
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
            /* Every account on the address, not whichever row came back first —
               the third gate in this file to have made that mistake today. */
            $uidsHere = DB::table('users')->where('email', $addr)->pluck('id');
            $uid = $uidsHere->every(fn ($id) => \App\Support\Suppression::isUser((int) $id))
                ? $uidsHere->first()
                : null;
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
            /* Several accounts can share one address — the product allows it, told
               apart at login by username. ->value('id') returned whichever row came
               first, so a deactivated namesake could cancel a live person's mail.
               Block only when EVERY account on the address is switched off. */
            $uids = DB::table('users')->where('email', $addr)->pluck('id');
            $allOff = $uids->isNotEmpty() && $uids->every(
                fn ($id) => \App\Support\Suppression::isUser((int) $id)
            );
            if ($allOff) {
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
                // Same ordering as the delivered-mail logger: the sending agency is
                // authoritative and the recipient is a last resort. A suppressed message
                // filed against the recipient's agency puts one tenant's rows in another's
                // log just as surely as a delivered one does.
                try {
                    if (Schema::hasColumn('email_logs', 'agency_id')) {
                        // Same ordering fix as the sent-mail logger: the recipient is
                        // about THIS message, the static is about a previous one.
                        $supAgency = \App\Support\AgencyMail::agencyForMessage($event->message, null);
                        if (! $supAgency && ! empty($hits[0])) {
                            $supAgency = \App\Support\AgencyMail::agencyOfEmail($hits[0]);
                        }
                        if (! $supAgency) {
                            // Last resort only. An unstamped row is invisible to EVERY
                            // agency, which is worse than one stamped from context.
                            $supAgency = \App\Services\AgencyMailer::$lastAgencyId;
                        }
                    }
                } catch (\Throwable $e) {}
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
                    // Name the agency — "(live agency)" told the reader nothing about
                    // WHICH tenant the blocked message belonged to.
                    'to_name' => 'SUPPRESSED ('.(
                        $supAgency
                            ? (DB::table('agencies')->where('id', $supAgency)->value('name') ?: ('agency '.$supAgency))
                            : 'no agency'
                    ).')',
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

            /* The audit log records "email.sent" when the sender dispatches, and never
               learned that the gate cancelled it — 380 sent rows against 132 real
               suppressions. Both halves are recorded now, so the trail reads as what
               actually happened: dispatched, then stopped, and why. */
            if (Schema::hasTable('audit_logs')) {
                \App\Support\Audit::write([
                    'user_id' => null,
                    'agency_id' => $supAgency,
                    'action' => 'email.suppressed',
                    'entity_type' => 'email',
                    'entity_id' => null,
                    'payload' => json_encode([
                        'to' => array_slice($hits, 0, 5),
                        'subject' => $subject,
                        'reason' => $reason,
                    ]),
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
