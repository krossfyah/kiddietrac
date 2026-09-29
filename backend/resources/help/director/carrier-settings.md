---
title: Carrier settings — Twilio and Telnyx
category: Settings
order: 41
roles: agency_admin, platform_admin
---
# Carrier settings — Twilio and Telnyx

**Settings → Carrier settings.** The accounts your agency texts and telephones through.
Two carriers are supported and two channels run over them — SMS, and voice announcement
calls — which is why the screen is no longer named after either one of them.

[[open: #sms-settings | Open Carrier settings]]

Every agency sets up its own carrier here. One agency's numbers, keys, balance and
choices are never visible to another, and only an **agency admin of that agency** can
save them.

## Setting it up, start to finish

- [ ] Enter the carrier's credentials and the number you send from, then **Save**.
- [ ] Press **Test connection** — it checks the credentials and sends nothing.
- [ ] Choose the carrier under **Which carrier sends**.
- [ ] Tick the **kinds of texts this agency sends** (below).
- [ ] For Telnyx, paste the **webhook public key** so replies and delivery reports are trusted.
- [ ] Send yourself a real test text, then place a test call on the **Voice calls** tab.
- [ ] Check **Settings → Email settings**: notifications must be on for the agency.

## Two switches, not one

Nothing sends until both are on, and they are in different places. An admin who does not
know that spends a long time wondering why a broadcast reported "sent" and nobody's phone
moved.

1. **A carrier is configured and selected here.**
2. **Notifications are on for the agency**, under **Settings → Email settings**. That is a
   cross-channel master switch: with it off, nothing sends or rings even with a carrier
   fully set up. The screen says so in red when it is off.

## Choosing which carrier sends

The **Which carrier sends** card sits above the carrier tabs, because it is a decision
about the pair and belongs to neither of them. Pick Twilio or Telnyx; the one currently
sending is marked on its own tab.

[[show: #sms-settings @ Which carrier sends | Show me the carrier choice]]

Both pages are saved together. Filling in Telnyx cannot quietly discard a number typed on
the Twilio page — but you can carefully configure Telnyx and forget to switch to it, so
check the tab marking before you assume.

## Text messages and Voice calls tabs

The screen has two tabs, **Text messages** and **Voice calls**. It remembers the one you
were on, so saving or testing on the voice tab no longer throws you back to texts.

[[show: #sms-settings @ Voice calls | Show me the Voice calls tab]]

## Kinds of texts this agency sends

On the **Text messages** tab, **Kinds of texts this agency sends** lists every reason
KiddieTrac might text somebody:

| Kind | What it is |
|---|---|
| Sign in and sign out | When a child is signed in or out, and who by |
| Sign-in reminders | A child was not signed in, or was never signed out |
| Announcements | Announcements, when they are also sent by text |
| Text broadcasts | Messages sent to a group, a room or everyone from SMS broadcast |
| Replies from staff | A staff reply to a text somebody sent to the agency number |

Untick a kind and nothing of that kind is texted by this agency, whatever anybody has
chosen for themselves. Each person can then switch off kinds they do not want — see
[Texts and phone calls](texts-and-phone-calls). An agency switch always wins over a
personal one: a person cannot turn back on something the agency has turned off.

![Kinds of texts this agency sends, on the Text messages tab](https://api.kiddietrac.com/help-img/carrier-kinds-of-texts.png)

## Reasons that may place calls

On the **Voice calls** tab, **Reasons that may place calls** decides which reasons an
announcement call may be sent for: *Closure, Evacuation, Lockdown, Illness at the centre,
Other emergency* and *Not an emergency*. The five emergencies are on by default; *Not an
emergency* is off, so a routine notice is never read down the phone unless you decide it
should be.

[[show: #sms-settings @ Voice calls > Reasons that may place calls | Show me the call reasons]]

![Reasons that may place calls — the five emergencies are on, "Not an emergency" is off](https://api.kiddietrac.com/help-img/carrier-voice-reasons.png)

The SMS broadcast screen only offers the reasons ticked here.

## Choosing a voice

Pick a voice from **Choose a voice…** and set the **Language** (for example `en-US`,
`en-GB`, `fr-CA`). Neural voices sound far more natural and are billed at a slightly
higher rate. The test call below plays the voice currently picked, **saved or not**, so
you can audition voices before committing to one.

## Testing a call

- **Test message** — what the test call reads out, up to 600 characters.
- **Number to call** — leave it blank to ring the number on your own profile. Typing
  another number asks you to tick **I control this number**; the call announces which
  agency is calling.
- **Call my own number** places it.

The test works **while voice calls are switched off**, so you can hear it before
switching the channel on. Tests count towards **five an hour** per agency.

[[show: #sms-settings @ Voice calls > Call my own number | Show me the test call button]]

![The test call: your own message, an optional number, and the test log below](https://api.kiddietrac.com/help-img/carrier-voice-test.png)

Below the button, the **Test log** shows each test as it moves — dialled, answered,
completed, no answer — and, when a test was refused, **why** (for example, the number is
on a suppressed account, or said *don't phone me*).

## Balance and usage (Telnyx)

With Telnyx selected, a usage card shows your **balance**, texts and calls over the last
30 days, what they cost, and roughly how long the balance will last at that rate. It turns
red when the balance is running low. Counts come from KiddieTrac's own log of what it
sent, priced at Telnyx's current rates, so the card is right even when Telnyx's own
reports are running a few hours behind.

[[show: #sms-settings @ Balance | Show me the balance card]]

## The webhook warning

Telnyx signs every message it sends back to us — replies, STOPs, delivery reports, call
progress. If the **webhook public key** is blank, or the last call from Telnyx was
refused because its signature did not match, a red warning appears at the top of the
page. Until it is fixed, replies and STOPs from families are not recorded. Copy the
public key from the Telnyx portal (**Account settings → Keys & Credentials → Public key**) and save it here.

## Secrets

Credentials are **write-only**. Once saved, the screen tells you whether a secret is
stored — it never shows it back. To change one, type the new value; to leave it alone,
leave the box empty.

Take the field names literally. A Telnyx API key is not a Twilio Account SID, and an
identifier starting `OQ…` is not an Account SID either.

## Testing a text

- **Test connection** checks the credentials with the carrier. It costs nothing and sends
  nothing. The result is kept on the screen, so "did this ever work" is answerable
  tomorrow.
- **Send a real test** sends a **real** text, or places a **real** call, to a number you
  type. It is billed, it names your agency and carries STOP, and it is limited to agency
  admins and to five an hour per agency. Nobody else is contacted.

## Version stamp

A build date is printed under the page title. A stale cached copy of the app is otherwise
indistinguishable from a bug, so when something looks wrong, read that line first.

## Questions

??? A broadcast said "sent" but nobody got it. | Check the two switches first: a carrier selected here, and notifications on under Settings → Email settings. Then check the kind of text is ticked, and that the people had agreed to receive texts.
??? My test call says "not placed". | Read the Test log line under the button — it says why. The most common reasons are a number that belongs to a suppressed or archived account, or a person who chose "Don't phone me at all".
??? Can another agency see our balance or keys? | No. Each agency has its own carrier settings, and only that agency's admins can open or change them.
??? Why is a reason missing from the SMS broadcast screen? | It is unticked under Reasons that may place calls. Tick it here and save.

See also: [SMS broadcast](sms-broadcast), [Announcement calls](voice-announcements),
[Texts and phone calls](texts-and-phone-calls), [Email and digests](email-and-digests).
