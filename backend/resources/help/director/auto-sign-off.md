---
title: Auto sign-off
category: Staff Management
order: 45
---

# Auto sign-off

Somebody always forgets. A shift left running overnight turns into a 40-hour week on the
payroll report, and a child still showing as present at midnight makes the ratio figures
useless. Auto sign-off closes what was forgotten, at a time you choose.

It is **off** until you switch it on, and staff and children are set separately.

## Quick steps

1. Go to **Settings → Clock settings**.
2. Open the **Auto sign-off** tab.
3. Switch on **Close staff shifts** and set the time, e.g. 19:00.
4. Set **Give up on a shift after** — the hours past which an open punch is treated as
   abandoned rather than long. 14 is the default.
5. Switch on **Sign children out** and set that time, e.g. 18:30.
6. Save.

## The bit that matters

A forgotten punch is closed at your chosen time **on the day it started** — never at
whatever time the job happens to run.

That distinction is the whole point. A shift opened on Tuesday and noticed on Thursday
would otherwise be closed "now" and record a 48-hour shift, which is a worse number than
the missing one it replaced. Closing it at Tuesday 19:00 records something a payroll
report can survive.

If a shift began *after* your closing time — an evening or overnight shift — the time
rule cannot apply, so the "give up after N hours" rule closes it instead.

## What it leaves behind

Nothing is closed silently:

- **A staff punch** gets `[auto sign-off: no clock-out recorded]` appended to its notes,
  so anybody reading the timesheet can see the hours were not typed by a person.
- **A child's day** gets a line on it saying no check-out was recorded, marked so the
  system can tell an automatic closure from a note somebody typed.
- **The person themselves** is shown a notice next time they sign in, so a forgotten
  clock-out is something they find out about rather than something that quietly happens
  to their hours.

## What it will not do

- It does not invent hours. A punch it cannot close sensibly is left for a human.
- It does not correct a wrong time — only closes an open one. If the recorded hours are
  wrong, edit the punch on the timesheet.
- It does not run on centres in an agency that has it switched off.

## If the hours are wrong

Auto sign-off is a safety net, not a substitute for clocking out. Where the closed time
is not the real one, edit the punch directly — the note stays, so the correction is
visible rather than hidden.

Educators can see when this happened to them under **My hours**, and the reminder that
nudges people to clock out before it comes to this is on the **Reminders** tab of the
same screen.
