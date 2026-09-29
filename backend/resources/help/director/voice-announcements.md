---
title: Announcement calls
category: Communications
order: 41
roles: agency_admin, centre_director, platform_admin
---
# Announcement calls

A phone that rings and says a short message out loud. Built for the one class of notice
you cannot assume anybody read — a closure, an evacuation, a lockdown, an illness at the
centre. Parents who never open the app answer their phone.

**SMS broadcast** is the same screen. The **Send by** switch at the top — **💬 Text message** or **📞 Voice call** — chooses the
channel; everything below it — the audience picker, the message box, the record of what
was sent — works the same way for both.

[[open: #sms | Open SMS broadcast]]

## Before it will work

- [ ] **Telnyx** configured and selected in **Settings → Carrier settings**.
- [ ] **Place announcement calls for this agency** ticked on that screen's **Voice calls** tab. It starts off: running a site is not the same as deciding the agency starts telephoning people.
- [ ] The **reasons** you want to call for ticked under **Reasons that may place calls**.
- [ ] Notifications on for the agency, under **Settings → Email settings**.
- [ ] A test call placed to your own phone, so you have heard the voice.

Filling in the carrier settings is an **agency admin's** job, not a director's — so if the
channel switch is there but nothing rings, ask them rather than hunting for a setting
you cannot see. See [Carrier settings](carrier-settings).

## Sending one

1. Sidebar → **SMS broadcast**, then set **Send by** to **📞 Voice call**.
2. Pick a **reason** — *Closure, Evacuation, Lockdown, Illness at the centre, Other
   emergency* or *Not an emergency*. Only the reasons your agency allows are offered.
   This is not a label; it decides who may be rung (below).
3. Pick the audience: by role, by centre, by room, by family, or the whole agency. For
   *by centre* and *by room* you must choose which one — an audience that cannot narrow is
   refused rather than quietly widened to everybody.
4. Write the message. Up to 800 characters — long enough for a closure notice read twice,
   short enough that nobody hangs up. It is read out by a synthetic voice, so write it the
   way you would say it.
5. **Place calls**. A call is neither silent nor undoable, so it confirms first.

[[show: #sms @ Voice call | Show me the Voice call switch]]

![SMS broadcast with Send by set to Voice call — pick the audience and reason, then Place calls](https://api.kiddietrac.com/help-img/sms-broadcast-voice.png)

The result line reports **calling / skipped / total**.

## Which reasons your agency calls for

An agency admin chooses under **Settings → Carrier settings → Voice calls → Reasons that
may place calls**. The five emergencies are on by default; *Not an emergency* is off, so a
routine notice cannot be read down the phone until somebody decides it should be.

[[show: #sms-settings @ Voice calls > Reasons that may place calls | Show me where the reasons are set]]

## Who actually gets rung

Narrower than a text, deliberately. A person is skipped when:

- they have **no phone number** on their record;
- the reason is **switched off for the agency**;
- they have **turned off calls for that reason** in their own choices — see
  [Texts and phone calls](texts-and-phone-calls);
- they, or **anyone on the same phone**, chose **Don't phone me at all**. That covers every
  reason, emergencies included; somebody who has said it has usually said it for a reason;
- the call is **Not an emergency** and nobody on that phone has agreed to be contacted on
  it — the same consent a text needs;
- the same announcement **already went to that phone** a moment ago. Accounts that share
  a number get one call, not one each.

Each skip is listed with its reason, so "why wasn't she called" has an answer.

## What happens after you press send

A call is a conversation with the carrier spread over a minute, not a single event. The
list below the form reports each call as it moves:

- **queued** — dialled.
- **answered** — somebody picked up, and only then is the announcement spoken. Nothing is
  said while the phone is still ringing.
- **completed / no answer / busy** — how it ended.

Refresh the list to watch it settle. A call that shows *no answer* was genuinely not
picked up.

## Test it on yourself first

On **Settings → Carrier settings → Voice calls**:

1. Type what the test should say in **Test message**, and pick a voice — the test uses the
   voice currently picked, even before you save it.
2. Leave **Number to call** blank to ring your own profile number, or type another
   number you control and tick **I control this number**.
3. Press **Call my own number** and answer.

The **Test log** under the button follows the call and says why when one is refused. Tests
work while voice calls are still switched off, and count towards five an hour.

[[show: #sms-settings @ Voice calls > Test log | Show me the test log]]

## Worth knowing

- A director can send one, because a closure or an evacuation is a site-level decision
  made by whoever is standing in the building. The carrier settings behind it stay with
  agency admins.
- Keep the wording plain and the important sentence first. People answer mid-sentence.
- The balance and 30-day usage on the carrier settings screen cover calls as well as texts.

## Questions

??? Why is "Not an emergency" missing from the reasons? | Your agency has not allowed it. An agency admin can tick it under Carrier settings → Voice calls → Reasons that may place calls.
??? A parent says they never want a call. | They can choose "Don't phone me at all" under their own Texts and phone calls settings. It applies to their whole phone, emergencies included.
??? Two parents share a phone. Will it ring twice? | No. One phone gets one call per announcement.

See also: [SMS broadcast](sms-broadcast), [Carrier settings](carrier-settings),
[Texts and phone calls](texts-and-phone-calls), [Closure reminders](closure-reminders),
[Announcements](announcements).
