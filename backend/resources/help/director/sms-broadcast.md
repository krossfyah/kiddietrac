---
title: SMS broadcast
category: Communications
order: 40
---
# SMS broadcast

Send a one-off SMS to staff or families. Use it for emergencies (closures, evacuations, severe weather) where email isn't urgent enough.

## Prerequisites

1. Twilio account active on the platform side (set up by KiddieTrac).
2. **Administration → Billing settings → SMS enabled** turned on.
3. Each recipient must:
   - Have a phone number on their user record
   - Have **sms_opt_in** set to yes (they confirm during onboarding or in profile settings).

Anyone without a number, or who's opted out, is silently skipped — the broadcast result shows total / sent / skipped counts.

## Sending

1. Sidebar → **SMS broadcast**.
2. Pick an audience:
   - **By role** — pick guardian / educator / centre_director / agency_admin
   - **By centre** — everyone with a role at that centre
   - **Whole agency** — everyone with any active role
3. Write your message (max 300 characters — Twilio splits at 160).
4. Click **Send broadcast**.

## Cost & limits

Each SMS counts as one Twilio segment (160 chars). Broadcasts above 50 recipients are rate-limited to keep your account in good standing.

## Audit

Every send is logged in **sms_messages** table with delivery status and any Twilio error message. The bottom of the SMS broadcast page shows the 20 most recent.
