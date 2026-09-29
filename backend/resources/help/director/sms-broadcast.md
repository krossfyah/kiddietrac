---
title: SMS broadcast
category: Communications
order: 40
---
# SMS broadcast

Send a one-off text to staff or families. Use it for emergencies (closures, evacuations,
severe weather) where email isn't urgent enough.

[[open: #sms | Open SMS broadcast]]

## Text or Call

A **Send by** switch — **💬 Text message** or **📞 Voice call** — sits at the top of the screen. Everything below it — the audience
picker, the message box, the record of what was sent — works the same way for either.

A call rings the person's phone and reads a short message out loud, for the one class of
notice you cannot assume anybody read. It is narrower than a text about who it may reach.
See [Announcement calls](voice-announcements).

## Before it will send

- [ ] **A carrier configured and selected** in **Settings → Carrier settings** (Twilio or Telnyx; voice runs over Telnyx). Credentials are an agency admin's job — if you are a centre director and nothing sends, ask them. See [Carrier settings](carrier-settings).
- [ ] **Notifications on for the agency**, under **Settings → Email settings**. It is a cross-channel master switch: with it off, nothing sends however well a carrier is configured.
- [ ] **Text broadcasts** ticked under **Kinds of texts this agency sends** on the carrier settings screen.

## Who receives it

Each person must:

- have a **mobile number** on their record;
- have **agreed to receive texts** — they say yes during onboarding, or later with
  **Agree to receive texts** in their own profile (see
  [Texts and phone calls](texts-and-phone-calls));
- not have switched **Text broadcasts** off in their own choices.

Anyone who does not qualify is skipped, and the result shows **total / sent / skipped**.
A broadcast that reaches nobody says so plainly rather than reporting success.

## Sending

1. Sidebar → **SMS broadcast**.
2. Pick an audience:
   - **By role** — guardian / educator / centre director / agency admin
   - **By centre** (or provider, whichever your agency calls them) — everyone with a
     role at the one you choose
   - **By room** — the educators assigned to that room, plus the guardians of the
     children currently enrolled in it
   - **Whole agency** — everyone with any active role
3. For **by centre** or **by room**, choose which one from the picker that appears. The
   message will not send until you do — an audience that cannot narrow is refused rather
   than quietly widened to everybody.
4. Write your message — up to **300 characters**. Say who it is from (your agency's name)
   and put the important sentence first; people read the preview.
5. Click **Send broadcast**.

[[show: #sms @ Send broadcast | Show me the Send button]]

## Shared phone numbers

Some families use one phone for two accounts — two parents on one number, or a parent who
is also a staff member. KiddieTrac handles that per **phone**, within your agency:

- **One copy per phone.** If three accounts on the same audience share a number, the
  phone gets the message once, not three times.
- **Consent follows the phone.** If anyone on that number agreed to receive texts, the
  phone may be texted; the text is credited to the account that agreed.
- **STOP stops the phone.** A STOP from a handset stops texts to **every** account on that
  number at your agency, not just the newest one. START turns them all back on.

## When people reply

A reply to a broadcast is not lost in a carrier log. It arrives in **Messenger** as a chat
from that person (marked as an SMS thread), the right staff are told, and a reply you type
in that thread goes back out to their phone as a text.

- **STOP** (also UNSUBSCRIBE, CANCEL, END, QUIT) stops all texts to that phone from your agency.
- **START** turns them back on.
- **HELP** (or INFO) sends back your agency's name and how to reach you.

## Cost

Each text is billed by your carrier per segment. A plain message up to 160 characters is
one segment; longer messages, or messages with emoji or accented letters, use more. With
Telnyx selected, **Settings → Carrier settings** shows your balance and what the last 30
days cost.

## The record

**Sent & received** at the bottom of the screen lists recent texts in both directions,
with times in your agency's timezone, the delivery status, and — when one failed — the
carrier's reason. Every message is also written to the audit log.

[[show: #sms @ Sent & received | Show me the message record]]

## Questions

??? Why was a parent skipped? | They have no mobile number, have not agreed to receive texts, have switched Text broadcasts off for themselves, or replied STOP from that phone.
??? A parent says they got one text for two accounts. Is that right? | Yes. Accounts that share a phone get one copy.
??? A parent replied STOP by mistake. | Ask them to text START to the same number. That turns texts back on for every account on that phone.

See also: [Announcement calls](voice-announcements), [Carrier settings](carrier-settings),
[Texts and phone calls](texts-and-phone-calls).
