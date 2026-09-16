---
title: SMS broadcast
category: Communications
order: 40
---
# SMS broadcast

Send a one-off SMS to staff or families. Use it for emergencies (closures, evacuations, severe weather) where email isn't urgent enough.

## Text or Call

A **Text / Call** switch sits at the top of the screen. Everything below it — the audience
picker, the message box, the record of what was sent — works the same way for either.

A call rings the person's phone and reads a short message out loud, for the one class of
notice you cannot assume anybody read. It is narrower than a text about who it may reach.
See [Announcement calls](voice-announcements).

## Prerequisites

1. **A carrier configured and selected** in **Settings → Carrier settings**. Twilio or
   Telnyx; voice runs over Telnyx. The agency sets this up itself — it is no longer
   something KiddieTrac does for you. Credentials are an agency-admin job, so if nothing
   is sending and you are a centre director, that is who to ask.
   See [Carrier settings](carrier-settings).
2. **Notifications on for the agency**, under **Settings → Email settings**. That is a
   cross-channel master switch, and with it off nothing sends however well a carrier is
   configured.
3. Each recipient must:
   - Have a phone number on their user record
   - Have **sms_opt_in** set to yes (they confirm during onboarding or in profile settings).

Anyone without a number, or who's opted out, is silently skipped — the broadcast result shows total / sent / skipped counts.

## Sending

1. Sidebar → **SMS broadcast**.
2. Pick an audience:
   - **By role** — pick guardian / educator / centre_director / agency_admin
   - **By centre** (or provider, whichever your agency calls them) — everyone with a
     role at the one you choose
   - **By room** — the educators assigned to that room, plus the guardians of the
     children currently enrolled in it
   - **Whole agency** — everyone with any active role
3. For **by centre** or **by room**, choose which one from the picker that appears. The
   message will not send until you do — an audience that cannot narrow is refused rather
   than quietly widened to everybody.
4. Write your message (max 300 characters — Twilio splits at 160).
5. Click **Send broadcast**.

## Cost & limits

Each SMS counts as one Twilio segment (160 chars). Broadcasts above 50 recipients are rate-limited to keep your account in good standing.

## Audit

Every send is logged in **sms_messages** table with delivery status and any Twilio error message. The bottom of the SMS broadcast page shows the 20 most recent.
