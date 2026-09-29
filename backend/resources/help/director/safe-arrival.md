---
title: Safe Arrival — every scheduled child, accounted for
category: Operations
order: 5
roles: agency_admin, centre_director, platform_admin
---
# Safe Arrival — every scheduled child, accounted for

Safe Arrival makes sure no child who was expected today goes unnoticed. If a child
hasn't been signed in by their expected drop-off time and nobody has reported an
absence, KiddieTrac asks the parents. If the parents don't answer, it alerts the room's
educators and the director — and the **Safe Arrival** board shows who still needs a
call.

[[open: #safe-arrival | Open Safe Arrival]]

## How it works

1. Every 5 minutes, KiddieTrac looks at each child who is **scheduled today** at a
   centre that is **open today**.
2. When a child's **expected drop-off time + the grace period** passes, and they are
   not signed in and no absence was reported, the parents are asked once: *"Has Maya
   arrived?"* — through the channels each parent chose (app, email, text). They can tap
   **Not attending today** in the app.
3. If there is still no sign-in and no absence after the **escalation time**, the room's
   educators and the centre's director get an alert that opens this board.
4. The moment the child is signed in, or an absence is reported, the check closes by
   itself.

Nothing is checked on a day the child isn't scheduled, or when the centre is closed.

## Turning it on

Safe Arrival is **off** until an agency admin turns it on, because it messages parents.

- [ ] Open **Safe Arrival** and press **⚙️ Settings**.
- [ ] Tick **Check that every scheduled child arrives**.
- [ ] Set how many minutes after the expected time to ask parents (default 15).
- [ ] Set how long to wait for parents before alerting staff (default 20).
- [ ] Set the expected time for children who don't have one (default 9:00 AM).
- [ ] Save.

[[show: #safe-arrival @ Settings | Show me the settings button]]

> **Tip:** Give each child their own drop-off time on their record (**Usual times**). Children without one are checked against the default time, and the board marks them "(default)".

When Safe Arrival is on, it replaces the old 9:30 AM "has your child arrived?" reminder
for your agency, so parents get one message at the right time instead of two.

## The board

**Needs attention** lists overdue children first — the ones where staff have already
been alerted at the top — with each guardian's name and a tap-to-call phone number.
Close each one:

- **Record absence** — with a reason. It is saved as today's absence, exactly like a
  parent's "Not attending today", so the roster and reminders see it.
- **Parent contacted** — with a note, e.g. *"Spoke to mum at 9:40, arriving 10:15"*.
- **Other…** — anything else, with a note.

**Everyone else today** shows who has arrived (and when), who is absent and why, who
has been accounted for, and who isn't due yet. The board refreshes every minute.

Educators see their own rooms, directors their centres, and agency admins every centre
(with a centre filter).

## The record

Each check keeps when the parents were asked, when staff were alerted, and how it was
closed, by whom and with what note. Closing a case is written to the audit log.

## Questions

??? Why wasn't a child checked today? | They aren't scheduled today (check their enrolment days), the centre is closed (holiday or closure on the calendar), or an absence was already reported.
??? A parent says they didn't get the message. | Messages follow each parent's own choices in their profile. Check that they have the app installed with notifications on, or an email address, or agreed to texts.
??? Can educators turn it off? | No. Only an agency admin can change the settings.

See also: [Texts and phone calls](texts-and-phone-calls).
