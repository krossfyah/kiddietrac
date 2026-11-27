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

    /* THE OPEN RATE WAS MEASURING TWO EMAILS.

       Of 539 emails actually delivered in a week, exactly 2 carried a tracking pixel —
       the platform invite and its resend, the only two senders that embed one. Every
       other path (daily summaries, absence notices, invoices, reminders, every notice
       built through the EmailTemplate wrap step) went out untracked, so the Platform overview's
       Open-rate card was the open rate of a 2-message sample and read 0%. Anthony,
       2026-09-15: "emails opened etc in the platform overview the card doesnt update at
       all." It was updating; it had almost nothing to count.

       The mistake was putting the pixel at the SEND SITES. There are dozens and each new
       one starts untracked. There is exactly one moment every email passes through on its
       way out, and this listener already owns it.

       It has to be MessageSending, not MessageSent — by MessageSent the body has already
       gone down the wire. And it has to be INSIDE this listener rather than a second
       MessageSending listener, because this one returns false to cancel and that stops
       the others running at all (see the note on gates belonging inside this class).

       So: decide() is the original gate, unchanged, with every one of its allow-paths
       intact. handle() is a wrapper that runs it and, only on the ones that survive,
       attaches the pixel. A suppressed message gets nothing — it is not going anywhere. */
    public function handle(MessageSending $event): bool
    {
        /* ONE DECISION PER MESSAGE (2026-10-07). This listener is registered twice
           (AppServiceProvider plus event discovery), so every message went through the
           gate twice. The first pass strips X-KT-Account-Notice before the message
           leaves; the second pass then saw ordinary mail to a closed account and held
           it — the de-enrolment notices for families 39 and 137 were resent and
           suppressed exactly that way. Keyed on the message object (a WeakMap, so
           nothing outlives the message in a long-running queue worker). */
        $msg = $event->message;
        self::$decided ??= new \WeakMap();
        if (isset(self::$decided[$msg])) {
            return self::$decided[$msg];
        }

        $send = $this->decide($event);
        if ($send) {
            // In the recipients' language (2026-10-22) — here, because a separate
            // MessageSending listener after this one never runs. Before the pixel.
            \App\Support\MailTranslation::apply($event);
            $this->ensurePlainText($event);
            $this->attachOpenPixel($event);
        }
        self::$decided[$msg] = $send;

        return $send;
    }

    /**
     * EVERY EMAIL CARRIES A PLAIN-TEXT VERSION (2026-10-23). Almost every KiddieTrac email
     * is built as HTML only. An HTML-only message is one of the things spam filters score,
     * Outlook's most of all, and announcements were landing in junk for some families.
     * When a message has HTML and no text, the text is made from the HTML here: links kept
     * as "words (address)", paragraphs and list items on their own lines. A sender that
     * wrote its own text part is left alone. Best-effort: never worth a failed send.
     */
    private function ensurePlainText(MessageSending $event): void
    {
        try {
            $m = $event->message;
            if (! $m instanceof \Symfony\Component\Mime\Email) {
                return;
            }
            $html = $m->getHtmlBody();
            $text = $m->getTextBody();
            if (! is_string($html) || trim($html) === '' || (is_string($text) && trim($text) !== '')) {
                return;
            }
            $plain = self::htmlToText($html);
            if ($plain !== '') {
                $m->text($plain);
            }
        } catch (\Throwable $e) {
        }
    }

    public static function htmlToText(string $html): string
    {
        $h = preg_replace('#<(style|script|head|title)\b[^>]*>.*?</\1>#is', '', $html);
        $h = preg_replace('#<!--.*?-->#s', '', (string) $h);
        // A hidden preheader is for the inbox preview, not the reader.
        $h = preg_replace('#<(div|span)\b[^>]*display\s*:\s*none[^>]*>.*?</\1>#is', '', (string) $h);
        $h = preg_replace_callback('#<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function ($m) {
            $label = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (stripos($url, 'mailto:') === 0 || stripos($url, 'tel:') === 0) {
                return $label !== '' ? $label : preg_replace('/^(mailto|tel):/i', '', $url);
            }
            if ($label === '' || $label === $url) {
                return $url;
            }
            return $label . ' (' . $url . ')';
        }, (string) $h);
        $h = preg_replace('#<br\s*/?>#i', "\n", (string) $h);
        $h = preg_replace('#<li\b[^>]*>#i', "\n- ", (string) $h);
        $h = preg_replace('#</(p|div|h[1-6]|tr|li|table|blockquote|ul|ol)>#i', "\n", (string) $h);
        $t = html_entity_decode(strip_tags((string) $h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t\x{00A0}]+/u", ' ', (string) $t);
        $t = preg_replace("/ *\n */", "\n", (string) $t);
        $t = preg_replace("/\n{3,}/", "\n\n", (string) $t);
        return trim((string) $t);
    }

    /** @var \WeakMap<object, bool>|null */
    private static ?\WeakMap $decided = null;

    /**
     * Give this message a tracking token and a 1x1 pixel that reports it.
     *
     * Best-effort throughout: an email that cannot be tracked must still be sent.
     * The token travels on an X-KT-Track header so the MessageSent logger in
     * AppServiceProvider can write it onto the row it is about to insert — the row and
     * the pixel have to agree, and only the message itself can carry that between two
     * listeners (a static would leak across messages in a queue worker, which is a bug
     * this codebase has already had once).
     */
    private function attachOpenPixel(MessageSending $event): void
    {
        try {
            $msg = $event->message;
            $hdrs = $msg->getHeaders();

            /* The two senders that build their own pixel also insert their own
               email_logs row and mark it X-KT-Logged. Adding a second pixel would give
               one email two tokens and two rows' worth of opens. */
            if ($hdrs && $hdrs->has('X-KT-Logged')) {
                return;
            }
            if ($hdrs && $hdrs->has('X-KT-Track')) {
                return;   // already done (a message re-entering the gate)
            }
            /* RSVP mail opts out. A hidden 1x1 image from a third domain is one of the
               things that sends a personal Gmail sender's invitations to junk, and the RSVP
               report counts opens from the RSVP page itself, so it gains nothing. The marker
               is removed so it never leaves the server. */
            if ($hdrs && $hdrs->has('X-KT-No-Track')) {
                $hdrs->remove('X-KT-No-Track');
                return;
            }

            $html = $msg->getHtmlBody();
            if (! is_string($html) || trim($html) === '') {
                return;   // text-only mail has nowhere to put an image
            }

            $token = bin2hex(random_bytes(16));
            $apiBase = preg_replace('#/api/v1/?$#', '', rtrim((string) config('app.url', 'https://api.kiddietrac.com'), '/'));
            /* PATH-ONLY, no query string. A mail client that does not decode &amp; turns
               a query-string token into a 403 — the same trap that broke every image in
               already-sent mail once before. */
            $pixel = '<img src="'.$apiBase.'/api/v1/e/o/'.$token.'" width="1" height="1" alt="" '
                .'style="display:none;border:0;max-width:1px;max-height:1px;">';

            // Just inside </body> where clients expect it; appended if there is no body tag.
            if (stripos($html, '</body>') !== false) {
                $html = preg_replace('#</body>#i', $pixel.'</body>', $html, 1);
            } else {
                $html .= $pixel;
            }

            $msg->html($html);
            if ($hdrs) {
                $hdrs->addTextHeader('X-KT-Track', $token);
            }
        } catch (\Throwable $e) {
            // Tracking is never worth a failed send.
        }
    }

    /** Subjects of form mail a not-yet-onboarded parent must still receive. Shared with kt:mail-gate-watch. */
    /* Widened 2026-10-25 after "Counter-signed: ..." slipped past the list of exact openings: any subject that is
       about a form, a signature, a countersignature or a document. Word-bounded, so "uniform" does not count. */
    // "signed" but not "signed-out"/"signed in" (attendance reminders are not form mail).
    public const FORM_MAIL_RX = '/\b(forms?|signed(?![- ]?(out|in)\b)|signatures?|counter-?signed|countersign(ed|ature)?|e-?signatures?|documents?|upload|resubmit)\b/iu';

    private function decide(MessageSending $event): bool
    {
        /* THE PUBLIC WEBSITE DEMO NEVER MAILS ANYONE (2026-10-31, App\Support\PublicDemo). Before every other rule and
           every bypass (X-KT-Bypass-Suppression included): a message sent during a public demo session, from a public
           demo agency, or to a demo-only person is cancelled. Visitors type freely into that demo; this is what stops
           an "invite" or a "forward" reaching a real inbox. */
        try {
            $pdAgency = null;
            $pdH = $event->message->getHeaders();
            if ($pdH && $pdH->has('X-KT-Agency-Id')) {
                $raw = trim((string) $pdH->get('X-KT-Agency-Id')->getBodyAsString());
                if ($raw !== '' && ctype_digit($raw)) $pdAgency = (int) $raw;
            }
            $pdTo = [];
            foreach (array_merge($event->message->getTo() ?: [], $event->message->getCc() ?: [], $event->message->getBcc() ?: []) as $pdA) {
                if (method_exists($pdA, 'getAddress')) $pdTo[] = $pdA->getAddress();
            }
            $pdHit = \App\Support\PublicDemo::isSession() || \App\Support\PublicDemo::isAgency($pdAgency);
            if (! $pdHit) { foreach ($pdTo as $pdE) { if (\App\Support\PublicDemo::isDemoEmail($pdE)) { $pdHit = true; break; } } }
            if ($pdHit && \App\Support\PublicDemo::agencyIds()) {
                // The demo phone's Mail app shows what a demo person WOULD have received (only demo-only people; 2026-10-31).
                \App\Support\WebDemoMail::capture($event->message, $pdTo);
                $this->cancel($event, $pdTo, 'Public website demo: demo accounts never send email.');
                return false;
            }
        } catch (\Throwable $e) { /* never break the mail layer */ }
        /* TEST AGENCIES NEVER MAIL ANYONE (2026-11-24, App\Support\TestAgency; Anthony: "not send anything out to
           anyone"). Also before every bypass: mail stamped for a test agency (X-KT-Agency-Id), sent while working in a
           test agency, or addressed only to test-only people is cancelled. A test-only person among real recipients is
           dropped per person further down (Suppression::isUser). */
        try {
            $taAgency = null;
            $taH = $event->message->getHeaders();
            if ($taH && $taH->has('X-KT-Agency-Id')) {
                $raw = trim((string) $taH->get('X-KT-Agency-Id')->getBodyAsString());
                if ($raw !== '' && ctype_digit($raw)) $taAgency = (int) $raw;
            }
            $taTo = [];
            foreach (array_merge($event->message->getTo() ?: [], $event->message->getCc() ?: [], $event->message->getBcc() ?: []) as $taA) {
                if (method_exists($taA, 'getAddress')) $taTo[] = $taA->getAddress();
            }
            $taHit = \App\Support\TestAgency::isAgency($taAgency) || \App\Support\TestAgency::isSession();
            if (! $taHit && $taTo) {
                $taHit = true;
                foreach ($taTo as $taE) { if (! \App\Support\TestAgency::isEmail($taE)) { $taHit = false; break; } }
            }
            // The one exception (Anthony, 2026-11-24): a support-ticket alert to the platform admins / HQ inbox only.
            if ($taHit && \App\Support\TicketAlerts::allowsAddresses($taTo)) {
                $taHit = false;
            }
            // And Anthony's own preview inbox, when it is the ONLY recipient (Anthony, 2026-11-24: "Allow your Gmail only")
            // AND the message is a preview ("[TEST] …" or X-KT-Preview): a real #6 notification to his Gmail is not (2026-11-25).
            if ($taHit && \App\Support\TestAgency::previewOnly($taTo, (string) $event->message->getSubject(), (bool) ($taH && $taH->has('X-KT-Preview')))) {
                $taHit = false;
            }
            if ($taHit) {
                \App\Support\TestAgency::note('email', ['to' => $taTo, 'agency' => $taAgency, 'subject' => (string) $event->message->getSubject()]);
                $this->cancel($event, $taTo, 'Test agency: nothing is sent from a test agency.');
                return false;
            }
        } catch (\Throwable $e) { /* never break the mail layer */ }
/* X-KT-Bypass-Suppression SKIPS THE SWITCHES, NEVER THE PERSON.

           It used to return here outright, which skipped every gate below — including
           the not-onboarded gate and the suspended/deactivated/deleted check. The
           comment said "Only manual test commands set it", and that stopped being true:
           TimeOffController sets it on closure notices and rota changes, reasoning that
           an operational message must arrive even where an agency has bulk notifications
           switched off. That is a fair thing to say about the SWITCHES. It is not a
           reason to write to somebody whose account is closed — and on 2026-09-02 a
           deactivated director and a suspended one each received a rota notification
           because of it.

           So the header now means what its callers meant. The account-level gates still
           run for every message; only the agency / centre / room switches and the .env
           kill-switch are skipped. Somebody who has left is not staff any more. */
        $bypassSwitches = false;
        /* SCHEDULED REPORTS ARE NEVER SUPPRESSED (2026-10-10, Anthony: "add logic to never
           supress emails with scheduled reports at all"). A report schedule is an address
           somebody typed in on purpose: a shared mailbox, an accountant, a team inbox. That
           address can also be the login of an account that has since been closed. iLearn's
           attendance@ilearnhcc.com is one (user 9, deactivated), so the attendance log was
           withheld every hour as "suspended or deactivated". X-KT-Scheduled-Report skips
           EVERY gate: switches, kill-switches, invite state and account state. The only thing
           still dropped is an address that cannot exist (demo.*, example.*, .invalid), which
           would only bounce. Only SendScheduledReports::sendOne sets this header. */
        $isScheduledReport = false;
        try {
            $hdrs = $event->message->getHeaders();
            if ($hdrs && $hdrs->has('X-KT-Scheduled-Report')) {
                $hdrs->remove('X-KT-Scheduled-Report');
                $isScheduledReport = true;
            }
            if ($hdrs && $hdrs->has('X-KT-Bypass-Suppression')) {
                $hdrs->remove('X-KT-Bypass-Suppression');
                $bypassSwitches = true;
                Log::info('Email suppression: switches bypassed (operational or test send); '
                    . 'account-level gates still apply.', [
                        'subject' => (string) ($event->message->getSubject() ?? ''),
                    ]);
            }
        } catch (\Throwable $e) {
        }

        $recipients = $this->recipientsOf($event);
        if (! $recipients) {
            return true;
        }

        /* ADDRESSES THAT CANNOT EXIST ARE NEVER WRITTEN TO (2026-10-09).

           Test Agency is full of demo people at demo.testagency.com, demo.<centre>.com,
           example.com and .invalid. With its email switched on (Anthony, 2026-10-09: "turn
           on emails in test agency"), every reminder and digest to them would bounce — and
           bounces from noreply@kiddietrac.com cost EVERY agency's deliverability. Such
           recipients are dropped from To/Cc/Bcc; a message left with nobody is cancelled. */
        $deadEnd = array_values(array_filter($recipients, [self::class, 'isUndeliverable']));
        if ($deadEnd) {
            if (count($deadEnd) === count($recipients)) {
                $this->cancel($event, $deadEnd, count(array_filter($deadEnd, [self::class, 'isIntegrationAccount'])) === count($deadEnd)
                    ? 'Integration account (no mailbox) - never sent.'
                    : 'Demo / non-existent address (demo.*, example.*, .invalid, .test) - never sent.');
                return false;
            }
            try {
                $m = $event->message;
                $keep = fn ($list) => array_values(array_filter($list ?: [], fn ($a) => ! self::isUndeliverable($a->getAddress())));
                $to = $keep($m->getTo()); $cc = $keep($m->getCc()); $bcc = $keep($m->getBcc());
                if ($to) { $m->to(...$to); } elseif ($cc || $bcc) { $m->to(...($cc ?: $bcc)); }
                $m->cc(...$cc);
                $m->bcc(...$bcc);
            } catch (\Throwable $e) {
            }
            $recipients = array_values(array_diff($recipients, $deadEnd));
            Log::info('Demo addresses dropped from a message', ['dropped' => $deadEnd]);
        }

        if ($isScheduledReport) {
            return true;   // never suppressed: see the note at the top of decide()
        }

        /* KIDDIETRAC'S OWN CAMPAIGNS ANSWER TO THEIR OWN UNSUBSCRIBE LIST, NOTHING ELSE (2026-11-11).

           Anthony: "when sending out campaigns why are you supressing emails to users ... (has nothing to do with
           accessing kiddietrac)". A Sales campaign or subscriber email (X-KT-Campaign sales-* / subscribers-*, sent as
           platform mail) is KiddieTrac writing to its own subscriber or prospect. Whether that person ever accepted
           an agency's portal invite, or still has a portal login, says nothing about whether they asked to stop
           hearing from KiddieTrac. The invite gate and the account gates below were cancelling it: 14 iLearn
           parents (status invited / not_invited, two with closed portal accounts) missed One-Tap, Halloween,
           Diwali and Remembrance Day while the campaign counted them as sent.

           So for this mail only: the public demo and dead-end addresses above still apply, then the one question
           that matters, has this address unsubscribed (sales_suppressions or site_subscribers.unsubscribed_at).
           Agency switches are not consulted either, since this is not the agency's mail. Fails closed. */
        try {
            $hdrsCamp = $event->message->getHeaders();
            $campTag = ($hdrsCamp && $hdrsCamp->has('X-KT-Campaign')) ? trim((string) $hdrsCamp->get('X-KT-Campaign')->getBodyAsString()) : '';
            $isKtCampaign = $hdrsCamp && $hdrsCamp->has('X-KT-Platform') && preg_match('/^(sales|subscribers)-[a-z0-9_-]+$/i', $campTag);
        } catch (\Throwable $e) {
            $isKtCampaign = false;
        }
        if ($isKtCampaign) {
            try {
                $gone = DB::table('sales_suppressions')->whereIn('email', $recipients)->pluck('email')
                    ->merge(Schema::hasTable('site_subscribers')
                        ? DB::table('site_subscribers')->whereIn('email', $recipients)->whereNotNull('unsubscribed_at')->pluck('email')
                        : [])
                    ->map(fn ($e) => mb_strtolower(trim((string) $e)))->unique()->values()->all();
            } catch (\Throwable $e) {
                $this->cancel($event, $recipients, 'Campaign held: the unsubscribe list could not be checked.');
                return false;
            }
            if ($gone) {
                $this->cancel($event, $gone, 'Unsubscribed from KiddieTrac emails.');
                return false;
            }
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
        $isFormPackage = false;
        $isBilling = false;
        $isFamilyCampaign = false;
        try {
            $hdrs2 = $event->message->getHeaders();
            $isInvite = (bool) ($hdrs2 && $hdrs2->has('X-KT-Invite'));

            /* PAPERWORK A FAMILY MUST SIGN BEFORE THEY ONBOARD (2026-09-17).

               Agreed with Anthony after Safia's 7-form send to a new parent was cancelled
               here. A form package is the FIRST thing a new family is sent - enrolment
               agreements, consents - and it carries per-recipient SIGNED links that need
               no login and no account (SignedFormController::page checks only that the
               form is assigned). It is the agency asking for something, not the portal
               asking to be used, so it is transactional and not engagement.

               NARROW ON PURPOSE. This exempts form packages from the not-onboarded gate
               BELOW and nothing else: the agency master toggle, the suppression allowlist
               and every other gate still apply, and every other kind of mail is still
               withheld from an unclaimed account. Named rather than folded into
               X-KT-Invite so the audit log and this file both say which rule let it
               through. */
            $isFormPackage = (bool) ($hdrs2 && $hdrs2->has('X-KT-Form-Package'));
            /* GUARD (2026-10-25): form mail is recognised by its subject too, so a sender that forgets
               X-KT-Form-Package can never again withhold a parent's form receipt, correction request or
               signing request (Sheldon's Consent Form receipt was cancelled here). Logged when it
               rescues one, so the sender gets fixed; kt:mail-gate-watch reports any that still slip. */
            if (! $isFormPackage && preg_match(self::FORM_MAIL_RX, (string) $event->message->getSubject())) {
                $isFormPackage = true;
                \Illuminate\Support\Facades\Log::warning('Form mail let through by the subject guard (sender is missing X-KT-Form-Package)', ['subject' => (string) $event->message->getSubject()]);
            }
            if ($isFormPackage && $hdrs2) {
                $hdrs2->remove('X-KT-Form-Package');
            }
            /* BILLING MAIL TO AN UNCLAIMED BILLING CONTACT (2026-11-06). Agreed with Anthony, asked against the rule
               below: "Yes, billing mail only". An iLearn family with $1,890 invoiced had two billing guardians who had
               not accepted their invite, so no invoice, reminder or receipt ever reached anyone. Money owed or paid
               is transactional, like a form package. NARROW ON PURPOSE: only mail stamped by MailScope::billing
               (invoices, payment reminders, receipts, refunds, failed payments) skips the not-onboarded gate below;
               every other gate still applies, and every other kind of mail is still withheld. */
            $isBilling = (bool) ($hdrs2 && $hdrs2->has('X-KT-Billing'));
            if ($isBilling && $hdrs2) {
                $hdrs2->remove('X-KT-Billing');
            }
            /* AN AGENCY'S CAMPAIGN TO ITS FAMILIES (2026-11-27). Agreed with Anthony, asked against the rule below:
               "the campaigns users should all receive it whether or not they have accepted their invite". iLearn's
               "Get the KiddieTrac app" campaign exists to reach the parents who have not signed in yet, and all 12 of
               them were withheld here. NARROW ON PURPOSE, like billing: only mail stamped by MailScope::campaign (the
               campaign and drip senders) AND carrying the sending agency (tenant mail) skips the not-onboarded gate
               below. Every other gate still applies - test agency (above), closed / suspended accounts, demo
               addresses, agency switches, allowlist, AgencyOutsiders - and every other kind of mail is still
               withheld. The header is removed here, before AgencyOutsiders reads the message. */
            $isFamilyCampaign = (bool) ($hdrs2 && $hdrs2->has('X-KT-Family-Campaign') && $hdrs2->has('X-KT-Agency-Id'));
            if ($hdrs2 && $hdrs2->has('X-KT-Family-Campaign')) {
                $hdrs2->remove('X-KT-Family-Campaign');
            }
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
           ask -- do not widen the gate. The exceptions so far were each asked of Anthony
           and named above: form packages (09-17), billing mail (11-06) and an agency's
           campaigns to its families (11-27). Superseded reasoning follows:

           This used to read `if (! $isInvite)`, i.e. cancel
           everything that was not an invite -- which cancelled password resets,
           NDA receipts and "your child arrived" notices for anyone still marked
           'invited'. It now cancels only what is explicitly engagement mail. */
        if (! $isInvite && ! $isFormPackage && ! $isBilling && ! $isFamilyCampaign) {
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

                /* THE INVARIANT IS ABOUT AN ACCOUNT, NOT AN ADDRESS (2026-09-15).

                   This asked "does ANY claimed account use this address?" and withheld
                   the invite if so. But this platform deliberately allows several
                   accounts under one email, told apart by username — that is the whole
                   point of the username feature. So the moment somebody had one claimed
                   account, no further account on that address could ever be invited.

                   Lloydene King hit it today: she already had a claimed home-visitor
                   account (#197, active), was then added as an agency admin (#198,
                   invited), and the set-password invite for the NEW account was
                   withheld as "this account has already been claimed" — about a
                   different account entirely.

                   The invariant itself is right and is kept: a first-password link must
                   never reach somebody who already has a password. It just has to be
                   asked about the account being invited.

                   X-KT-Invite-User carries that account's id; both senders set it. For
                   any sender that does not, fall back to a question that is still safe
                   and still address-shaped: is there ANY account here awaiting a first
                   password? If there is, this invite has a legitimate target and goes;
                   if there is not, nobody at this address could correctly receive it
                   and it is withheld exactly as before. */
                $targetId = null;
                if ($hdrsInv->has('X-KT-Invite-User')) {
                    $targetId = (int) trim((string) $hdrsInv->get('X-KT-Invite-User')->getBodyAsString());
                    $hdrsInv->remove('X-KT-Invite-User');
                }

                if ($targetId > 0) {
                    $claimedTarget = DB::table('users')
                        ->where('id', $targetId)
                        ->whereNotIn('status', ['invited', 'not_invited'])
                        ->exists();

                    if ($claimedTarget) {
                        $this->cancel($event, $recipients,
                            'Set-password invite withheld: account #'.$targetId
                            .' has already been claimed.');

                        return false;
                    }
                } else {
                    $anyUnclaimed = DB::table('users')
                        ->whereNull('deleted_at')
                        ->whereIn('status', ['invited', 'not_invited'])
                        ->whereNotNull('email')
                        ->whereIn(DB::raw('LOWER(TRIM(email))'), $recipients)
                        ->exists();

                    if (! $anyUnclaimed) {
                        $this->cancel($event, $recipients,
                            'Set-password invite withheld: no account on this address is '
                            .'awaiting a first password.');

                        return false;
                    }
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

        /* WHICH AGENCY IS SENDING THIS.

           Every sender stamps X-KT-Agency-Id (the daily summaries, digests, reminders).
           Without it the gate below judged a send by EVERY account sharing the
           recipient's address, so one agency's off switch silenced another agency's
           mail to the same inbox — mr.anthonyhosein@gmail.com holds an agency_admin in
           iLearn and a home_visitor in Test Agency, and switching Test Agency off would
           have stopped both.

           Absent header → unchanged behaviour, which errs towards holding mail back
           rather than releasing it. */
        $sendingAgencyId = null;
        try {
            $hdrsAg = $event->message->getHeaders();
            if ($hdrsAg && $hdrsAg->has('X-KT-Agency-Id')) {
                $raw = trim((string) $hdrsAg->get('X-KT-Agency-Id')->getBodyAsString());
                if ($raw !== '' && ctype_digit($raw)) { $sendingAgencyId = (int) $raw; }
            }
        } catch (\Throwable $e) { /* never break the mail layer over a header */ }

        /* AN AGENCY'S MAIL GOES TO ITS OWN PEOPLE (2026-11-26, Anthony: "leave super admins out of all agency
           day-to-day notices"). A KiddieTrac super admin, integration account or reseller grant holding an agency role
           for access is taken off To/Cc/Bcc of anything the agency sends, unless it is platform, person-to-person, their
           own account, privacy breach, something they asked for, or a safety notice for an agency with nobody of its own
           (App\Support\AgencyOutsiders::mailDrops). A message left with nobody is cancelled and logged. */
        if ($sendingAgencyId) {
            $outs = \App\Support\AgencyOutsiders::mailDrops($event->message, $recipients, $sendingAgencyId,
                ['invite' => $isInvite ?? false, 'account_notice' => $isAccountNotice ?? false]);
            if ($outs) {
                if (count($outs) >= count($recipients)) {
                    $this->cancel($event, $outs, 'Not one of the agency\'s own people (KiddieTrac super admin, integration account or reseller access) - never sent.');
                    return false;
                }
                try {
                    $m = $event->message;
                    $keepA = fn ($list) => array_values(array_filter($list ?: [], fn ($a) => ! in_array(mb_strtolower(trim($a->getAddress())), $outs, true)));
                    $to = $keepA($m->getTo()); $cc = $keepA($m->getCc()); $bcc = $keepA($m->getBcc());
                    if ($to) { $m->to(...$to); } elseif ($cc || $bcc) { $m->to(...($cc ?: $bcc)); }
                    $m->cc(...$cc);
                    $m->bcc(...$bcc);
                } catch (\Throwable $e) {
                }
                $recipients = array_values(array_diff($recipients, $outs));
            }
        }

        /* THE SENDER'S OWN SWITCH, BEFORE ANY QUESTION ABOUT THE RECIPIENT.

           The gate below asks "does this recipient hold an account in a switched-off
           agency?", which answers a different question than the switch does. A message
           FROM a switched-off agency to somebody who happens to have no account there
           sailed straight out — a demo tenant mailing a real person is exactly what the
           switch exists to prevent, and it was the one case it did not cover.

           It could not be fixed before this was reliable: the header was absent on 31 of
           38 send sites, so acting on it would have blocked whatever the stale
           AgencyMailer::$lastAgencyId happened to be pointing at. Now every send stamps
           (AgencyMailer::html/raw do it), so the switch can finally mean what it says.

           X-KT-Bypass-Suppression still skips it — that header is how operational mail
           gets out of an agency with bulk notifications turned off, and the
           account-level gates above have already had their say. (2026-09-16) */
        if (! $bypassSwitches && $sendingAgencyId) {
            try {
                /* The SETTINGS switch, not suppressedAgencyIds() — that one is the
                   .env denylist and is empty here, which is why the first cut of this
                   check did nothing. agencyNotificationsEnabled() is what the Settings
                   toggle actually writes, and what the per-recipient rule below reads. */
                $senderOff = ! \App\Support\Suppression::agencyNotificationsEnabled($sendingAgencyId);
                if ($senderOff || in_array($sendingAgencyId, $this->suppressedAgencyIds(), true)) {
                    $this->cancel($event, $recipients);

                    return false;
                }
            } catch (\Throwable $e) { /* never break the mail layer */ }
        }

        // 1) The agency's OWN toggle ("Send notifications and emails") is
        //    ABSOLUTE — off means off, even for allowlisted addresses. This is
        //    what the Settings switch strictly controls.
        foreach ($recipients as $addr) {
            /* Every account on the address, not whichever row came back first —
               one address can carry accounts in two agencies.

               THE SENSE OF THIS USED TO BE INVERTED, and it silently disabled both
               switches below. It read:

                   $uid = $uidsHere->every(fn ($id) => Suppression::isUser($id))
                       ? $uidsHere->first() : null;
                   if (! $uid) { continue; }

               but isUser() answers "is this user at a SUPPRESSED agency" — TRUE means
               hold. So $uid was only resolved when every account on the address was
               ALREADY suppressed by something else, and for everyone else the loop
               continued straight past agencyOff() and blockedByCentreRoom(). The two
               switches Settings actually controls therefore never ran for the people
               they were meant to stop: Test Agency's centres were all switched off and
               its mail kept sending.

               Now each account is asked in its own right and ANY account that says
               hold, holds. For a suppression gate, failing closed is the only safe
               direction. */
            /* A DELETED ACCOUNT HAS NO OPINIONS (2026-09-14).

               This used to take every users row on the address, soft-deleted ones
               included. Delete a member of staff and re-add them — the ordinary way a
               role is corrected — and the deleted row, which is always 'deactivated',
               vetoed every message to the address forever. The new account could never
               be invited, could never set a password, and the log said only "this
               account is deactivated or suspended", which was true of a row nobody
               could see and false of the person waiting for the email.

               That is Lloydene King, deleted and re-added on 2026-09-14: the invite
               reached her, the password reset did not. */
            $uidsHere = DB::table('users')->where('email', $addr)
                ->whereNull('deleted_at')
                ->pluck('id');
            if ($uidsHere->isEmpty()) {
                continue;
            }

            /* CAN ANYBODY ON THIS ADDRESS ACTUALLY RECEIVE MAIL?

               "Any account that says hold, holds" is the right rule for the agency and
               centre switches below — those are the sender's policy, and a policy that
               fails open is not a policy. It is the WRONG rule for accountOff, which
               describes the RECIPIENT: if one person on this address is deactivated and
               another is live, the message is for the live one, and holding it helps
               nobody.

               Worse, a deactivated account with no active role resolves to no agency at
               all, and the agency filter below treats "no agency" as "belongs to every
               agency" — so one dead account poisoned the address for the whole platform.

               So accountOff is decided ACROSS the address rather than by the first row
               to object: held only when there is genuinely nobody left to reach. */
            /* An account notice is ABOUT the closed account, so a closed account is
               exactly who it is for (the 2026-09-16 rule below: "emails to closed
               educators should not be supressed"). This check, added 2026-09-14, ran
               ahead of that exemption and swallowed the de-enrolment notice whenever
               every guardian login was already closed. */
            $reachable = $isAccountNotice ? $uidsHere->values() : $uidsHere->filter(
                fn ($id) => ! \App\Support\Suppression::accountOff((int) $id)
            )->values();

            if ($reachable->isEmpty()) {
                $this->cancel($event, [$addr],
                    'Every account on this address is deactivated or suspended, so nothing is sent to it.');

                return false;
            }

            /* allowlist() lowercases its entries; comparing the raw address under a
               strict in_array meant a capitalised recipient missed its own exemption. */
            $isAllowlisted = in_array(mb_strtolower($addr), $this->allowlist(), true);

            foreach ($reachable as $uidOnAddr) {
                $uid = (int) $uidOnAddr;

                /* Only the account belonging to the agency actually sending. A person
                   with accounts in two agencies is governed, for this message, by the
                   one that wrote it — otherwise Test Agency's switch decides whether
                   iLearn may write to them. */
                if ($sendingAgencyId !== null) {
                    $uidAgency = \App\Support\Suppression::agencyOfUser($uid);
                    if ($uidAgency !== null && $uidAgency !== $sendingAgencyId) {
                        continue;
                    }
                }

                /* accountOff is settled above, across the whole address, so by here
                   every $uid in this loop is one that can actually be written to. */

                if (! $bypassSwitches && \App\Support\Suppression::agencyOff($uid)) {
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
                if (! $bypassSwitches && ! $isInvite && ! $isAllowlisted
                    && \App\Support\Suppression::blockedByCentreRoom($uid)) {
                    $this->cancel($event, [$addr],
                        'Every centre/room this recipient belongs to has email switched OFF '
                        . '(Settings → Email settings → Centre & room email delivery).');
                    return false;
                }
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

            /* FOR A CLOSURE NOTICE, accountOff IS THE WHOLE SUBJECT OF THE EMAIL.

               Suppression::isUser() is true as soon as the account is deactivated or
               suspended — the right answer for ordinary mail, and exactly backwards for
               the message whose entire purpose is to say the account has been closed.
               The X-KT-Account-Notice exemption above was written for this and only
               guarded the gate above; this one runs afterwards and swallowed the notice
               anyway.

               Measured 2026-09-16: Chearstine Fitzpatrick's closure notice was blocked
               here, while Safia Ali's went through — not because the rule was kinder to
               her, but because her address carries a second, still-active account, and
               this gate only fires when EVERY account on the address is off. Whether a
               departing educator is told they have been off-boarded should not depend on
               whether they happen to share an inbox with a colleague.

               The .env kill-switch still applies: that one is a hard stop for testing and
               is nobody's idea of a courtesy. (Anthony, 2026-09-16: "emails to closed
               educators should not be supressed") */
            $allOff = $uids->isNotEmpty() && $uids->every(function ($id) use ($isAccountNotice) {
                if ($isAccountNotice) {
                    return \App\Support\Suppression::enabled()
                        && in_array((int) $id, \App\Support\Suppression::userIds(), true);
                }

                return \App\Support\Suppression::isUser((int) $id);
            });
            if ($allOff) {
                $this->cancel($event, [$addr], $isAccountNotice
                    ? 'Recipient is on the .env user kill-switch (MAIL_SUPPRESS_USERS).'
                    /* The old wording named MAIL_SUPPRESS_AGENCIES for a check that
                       usually fires on the account's own status — which sent anybody
                       reading the log looking in the wrong file. */
                    : 'Recipient\'s account is switched off, or is on the .env kill-switch.');
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
    /** A domain that cannot receive mail: demo.*, example.*, and the reserved TLDs. */
    public static function isUndeliverable(?string $addr): bool
    {
        $d = mb_strtolower((string) substr(strrchr((string) $addr, '@') ?: '', 1));
        if ($d === '') return false;
        if (self::isIntegrationAccount($addr)) return true;

        return (bool) preg_match('/^demo\.|^(example\.(com|org|net)|localhost)$|\.(invalid|test|example|localhost|local)$/', $d);
    }

    /* INTEGRATION ACCOUNTS HAVE NO MAILBOX (2026-11-01, Anthony: "stop emailing
       integration+ilearn"). integration+<agency>@kiddietrac.com is the sign-in an outside
       system uses; it holds an agency_admin role, so it was sent every admin digest and
       notice (116 in 30 days) and each one bounced off our own domain. The account keeps
       working; it just never gets mail. */
    public static function isIntegrationAccount(?string $addr): bool
    {
        return (bool) preg_match('/^integration(\+[^@]*)?@kiddietrac\.com$/i', trim((string) $addr));
    }

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
                    'created_at' => now()->format(\App\Support\Audit::TS),
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
