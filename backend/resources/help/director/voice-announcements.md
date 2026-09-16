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

**SMS broadcast** is the same screen. A **Text / Call** switch at the top chooses the
channel; everything below it — the audience picker, the message box, the record of what
was sent — works the same way for both.

## Before it will work

Voice runs over **Telnyx** and needs it configured and selected in
**Settings → Carrier settings**, with the agency's notifications switch on. The master
switch starts off: running a site is not the same as deciding the agency starts
telephoning people.

Filling in the carrier credentials is an **agency admin's** job, not a director's — so if
the channel switch is there but nothing rings, ask them rather than hunting for a setting
you cannot see. See [Carrier settings](carrier-settings).

## Sending one

1. Sidebar → **SMS broadcast**, then switch the channel to **Call**.
2. Pick a **category** — *emergency, closure, evacuation, lockdown* or *illness*. This is
   not a label; it decides who may be rung (below).
3. Pick the audience: by role, by centre, by room, by family, or the whole agency. For
   *by centre* and *by room* you must choose which one — an audience that cannot narrow is
   refused rather than quietly widened to everybody.
4. Write the message. Up to 800 characters — long enough for a closure notice read twice,
   short enough that nobody hangs up. It is read out by a synthetic voice, so write it the
   way you would say it.
5. **Send**. A call is neither silent nor undoable, so it confirms first.

The result line reports **calling / skipped / total**.

## Who actually gets rung

Narrower than a text, deliberately.

- Somebody with **no phone number** on their record is skipped.
- Anybody who has said **do not ring me** is skipped for *every* category, emergencies
  included. Somebody who has said it has usually said it for a reason.
- For anything **outside the five emergency categories**, the person must also have agreed
  to be contacted on that number in the first place — the same consent a text needs. A
  newsletter cannot be read down the phone to somebody who never opted in.

## What happens after you press send

A call is a conversation with the carrier spread over a minute, not a single event. The
list below the form reports each call as it moves:

- **queued** — dialled.
- **answered** — somebody picked up, and only then is the announcement spoken. Nothing is
  said while the phone is still ringing.
- **completed / no answer / busy** — how it ended.

Refresh the list to watch it settle. A call that shows *no answer* was genuinely not
picked up.

## Worth knowing

- **Test it on yourself first.** *Send a test* on the carrier settings screen rings the
  number you type and nobody else.
- A director can send one, because a closure or an evacuation is a site-level decision
  made by whoever is standing in the building. The carrier credentials behind it stay with
  agency admins.
- Keep the wording plain and the important sentence first. People answer mid-sentence.
- The 30-day send counts on the carrier settings screen cover calls as well as texts.

See also: [SMS broadcast](sms-broadcast), [Carrier settings](carrier-settings),
[Closure reminders](closure-reminders), [Announcements](announcements).
