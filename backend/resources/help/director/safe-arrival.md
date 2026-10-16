---
title: Safe Arrival — every scheduled child, accounted for
category: Operations
order: 5
roles: agency_admin, centre_director, platform_admin
---
# Safe Arrival — every scheduled child, accounted for

Safe Arrival makes sure no child who was expected today goes unnoticed. If a child
hasn't been signed in by their expected drop-off time and nobody has reported an
absence, KiddieTrac asks the parents. If they don't answer, it can **phone them**, and
then it alerts the room's educators and the director — and the **Safe Arrival** board
shows who still needs a call.

[[open: #safe-arrival | Open Safe Arrival]]

## How it works

1. Every 5 minutes, KiddieTrac looks at each child who is **scheduled today** at a
   centre that is **open today**.
2. When a child's **expected drop-off time + the grace period** passes, and they are
   not signed in and no absence was reported, the parents are asked once: *"Has Maya
   arrived?"* — through the channels each parent chose (app, email, text). They can tap
   **Not attending today** in the app.
3. If the phone call is turned on and there's still no answer after the **call time**,
   the parents get an automated call (see below).
4. If there is still no sign-in and no absence after the **escalation time**, the room's
   educators and the centre's director get an alert that opens this board.
5. The moment the child is signed in, or an absence is reported, the check closes by
   itself.

Nothing is checked on a day the child isn't scheduled, or when the centre is closed.

## Turning it on

Safe Arrival is **off** until an agency admin turns it on, because it messages parents.

- [ ] Open **Safe Arrival** and press **⚙️ Settings**.
- [ ] Tick **Check that every scheduled child arrives**.
- [ ] Set how many minutes after the expected time to ask parents (default 15).
- [ ] Set how long to wait for parents before alerting staff (default 20).
- [ ] Set the expected time for children who don't have one (default 9:00 AM).
- [ ] Optional: tick **Phone the parents if they don't answer**, and set **Call this many minutes after the parents were asked** (default 10).
- [ ] Save.

[[show: #safe-arrival @ Settings | Show me the settings button]]

> **Tip:** Give each child their own drop-off time on their record (**Usual times**). Children without one are checked against the default time, and the board marks them "(default)".

When Safe Arrival is on, it replaces the old 9:30 AM "has your child arrived?" reminder
for your agency, so parents get one message at the right time instead of two.

## The phone call

The phone call is **off** until an agency admin ticks **Phone the parents if they don't answer**. It needs voice calls set up for your agency (Settings → **Carrier settings** → **Voice calls**).

An automated call names the child and asks the parent to press a key:

- **1 — absent today.** Recorded as today's absence, exactly like **Not attending today**, so it shows on the calendar and the roster. The check closes.
- **2 — on the way, arriving late.** Noted on the check. The child is still expected. If they still aren't signed in by the escalation time (counted from the parent's answer), staff are alerted after all.
- **3 — already dropped off.** The child is **not** signed in on the parent's word. Instead the room, the director and the admins get an **urgent** alert — *"🚨 Safe Arrival: Maya dropped off but NOT signed in"* — to find the child and sign them in. If the alert reaches nobody's phone, it is emailed too.

Whatever the parent presses, the room's educators, the centre's directors and the agency's admins are told, and it's written to the audit log. Only the first answer counts; if another guardian or staff already dealt with it, the caller hears that the child is already accounted for.

Guardians are called **one at a time, primary first**. If a call ends with no key pressed, the next guardian is rung. Pick-up logins are never called, and nor is anyone who has opted out of phone calls.

## Phone call wording and options

In **⚙️ Settings**, open **📞 Phone call — wording and options**:

- **Offer 2 (on the way)** and **Offer 3 (dropped off)** — 1 is always offered. Untick one to leave it out of the call.
- **Who is called** — **Each guardian in turn, primary first**, or **Primary guardian only**.
- **Ask the question** — once, 2 times or 3 times if no key is pressed.
- **Wait for a key (seconds)** — 5 to 30 (default 10).
- **The wording** — the opening, each "press" sentence, and what the parent hears after each key, after no key, after a wrong key, and when the child is already accounted for. Leave a box empty to use the standard wording, shown in grey. Use **{child}**, **{child_full}**, **{centre}**, **{room}** and **{time}**; they're filled in on each call.

The keys always mean the same thing — 1 absent, 2 on the way, 3 dropped off — whatever the wording says.

**▶ Play test call to my phone** rings the mobile number on **your own profile** with the wording as it is in the boxes right now, before you save, using "Test Child". Press a key to hear the reply. Nothing is recorded on any child. **Reset wording to standard** empties the boxes.

## The board

**Needs attention** lists overdue children first — a parent saying **dropped off — NOT signed in** at the very top, then the ones where staff have already been alerted — with each guardian's name and a tap-to-call phone number. A child whose parent pressed 2 shows **On the way (parent)**. Each card shows when the parents were called and what they answered. Close each one:

- **Record absence** — with a reason. It is saved as today's absence, exactly like a
  parent's "Not attending today", so the roster and reminders see it.
- **Parent contacted** — with a note, e.g. *"Spoke to mum at 9:40, arriving 10:15"*.
- **Other…** — anything else, with a note.

**Everyone else today** shows who has arrived (and when), who is absent and why, who
has been accounted for, and who isn't due yet. The board refreshes every minute.

Educators see their own rooms, directors their centres, and agency admins every centre
(with a centre filter).

## The record

Each check keeps when the parents were asked, when they were called and what they pressed, when staff were alerted, and how it was
closed, by whom and with what note. Closing a case and every phone answer are written to the audit log.

## Questions

??? Why wasn't a child checked today? | They aren't scheduled today (check their enrolment days), the centre is closed (holiday or closure on the calendar), or an absence was already reported.
??? A parent says they didn't get the message. | Messages follow each parent's own choices in their profile. Check that they have the app installed with notifications on, or an email address, or agreed to texts.
??? A parent wasn't phoned. | Check the phone call is turned on, that voice calls are set up, that the parent has a phone number on file and hasn't opted out of calls, and whether **Primary guardian only** is selected.
??? The test call didn't ring. | Add your mobile number to your own profile. The test can't ring a number that opted out of calls.
??? Can educators turn it off? | No. Only an agency admin can change the settings or place a test call.

See also: [Texts and phone calls](texts-and-phone-calls).
