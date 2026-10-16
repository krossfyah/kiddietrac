---
title: Auto sign-off
category: Staff Management
order: 45
---

# Auto sign-off

Somebody always forgets. A shift left running overnight turns into a 40-hour week on the
payroll report, and a child still showing as present at midnight makes the ratio figures
useless. Auto sign-off closes what was forgotten, at a time you choose, and tells the
people who need to know that same evening.

It is **off** until you switch it on, and staff and children are set separately.
Directors and agency admins can change it.

## Quick steps

1. Go to **Settings → Room Controls** and open the **⏱️ Auto sign-off** tab. (The same
   setting is also under **Settings → Clock settings → Auto sign-off**.)
2. Switch on **Close staff shifts** and set the time, e.g. 19:00.
3. Set **Give up on a shift after** — the hours past which an open punch is treated as
   abandoned rather than long. 14 is the default.
4. Switch on **Sign children out** and set that time, e.g. 18:30.
5. Press **Save auto sign-off**.

[[open: #educator-rooms?tab=signoff | Open auto sign-off settings]]

## The bit that matters

A forgotten punch is closed at your chosen time **on the day it started** — never at
whatever time the job happens to run.

That distinction is the whole point. A shift opened on Tuesday and noticed on Thursday
would otherwise be closed "now" and record a 48-hour shift, which is a worse number than
the missing one it replaced. Closing it at Tuesday 19:00 records something a payroll
report can survive.

If a shift began *after* your closing time — an evening or overnight shift — the time
rule cannot apply, so the "give up after N hours" rule closes it instead.

## Who is told

Nothing is closed silently. Each time auto sign-off closes something, KiddieTrac sends
notices the same evening.

### When a staff shift is closed

- **The person** gets a notification, a push to their phone and an email: "You were
  clocked out automatically". It gives the clock-in time, the automatic clock-out time
  and the centre, explains why it matters (ratios and licensing, payroll, reports and
  funding) and asks them to tell their director or administrator when they really left.
- **The centre's directors and the agency admins** get a notification and an email:
  "Auto clock-out:" and the person's name, with the same details and a reminder to
  correct the time in **Timesheets**.

### When it keeps happening

If the same person has been clocked out automatically **3 or more times in 7 days**, the
directors and agency admins also get **Repeated missed clock-outs**, listing the dates.
It suggests a conversation, since the reminders have not changed the habit. This is sent
once a day at most.

### When children are signed out

- **Staff at the centre** — educators, home visitors, directors — and the agency admins
  get one summary per centre. It lists each child, when they were signed in and the
  automatic sign-out time, and asks staff to correct the times in **Attendance**.
- **Each family** gets a notification and an email: "Please confirm your pick-up time".
  It asks them to let the centre know when they really picked up, and gives the centre's
  phone number and email if you have them on file. It also tells them nothing is wrong.

Notices follow your agency's email settings. People whose accounts are switched off are
not contacted. If a notice cannot be sent, the sign-off still happens.

## What it leaves behind

- **A staff punch** gets `[auto sign-off: no clock-out recorded]` appended to its notes,
  so anybody reading the timesheet can see the hours were not typed by a person.
- **A child's day** gets a line on it saying no check-out was recorded, marked so the
  system can tell an automatic closure from a note somebody typed.
- **The person themselves** is also shown a notice next time they sign in.

Automatically closed shifts are not used to work out someone's "usual day" for clock-out
reminders, so a habit of forgetting does not stop the reminders reaching them.

## What it will not do

- It does not invent hours. A punch it cannot close sensibly is left for a human.
- It does not correct a wrong time — only closes an open one. If the recorded hours are
  wrong, edit the punch on the timesheet.
- It does not run on centres in an agency that has it switched off.

## If the hours are wrong

Auto sign-off is a safety net, not a substitute for clocking out. Where the closed time
is not the real one, edit the punch directly — the note stays, so the correction is
visible rather than hidden. For a child, correct the sign-out time in **Attendance** once
the family tells you when they picked up.

Educators can see when this happened to them under **My hours**. The reminder that
nudges people to clock out before it comes to this is on the **Reminders** tab of
**Settings → Clock settings**. See [Staff time clock](time-clock).

??? Will headcount reminders keep going after hours? | No. Headcount reminders stop at the end of an educator's rostered shift, or the centre's closing time if they have no roster, even if they are still clocked in.
??? Do parents get a message every time? | Only when their own child was signed out automatically. Each family gets one email for all their children at that centre.
