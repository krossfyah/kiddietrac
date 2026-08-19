---
title: Staff time clock
category: Daily Operations
order: 76
---
# Staff time clock

Each staff member punches in at the start of their shift and out at the end. Find it under **Staff → Time clock** in the sidebar.

## Punching

The screen shows a single big button:

- **Green "Clock in"** when you're not currently on a shift
- **Red "Clock out"** when you are — with the elapsed hours displayed

Tap it. That's the entire flow.

## Recent punches

The list below the button shows your last 60 punches with in/out timestamps and total hours. An open (in-progress) shift shows "— in progress —" instead of a duration.

## Manager view

Directors and agency admins see every staff member's punches at their centre via the existing **Timesheets** screen, which rolls up time-clock entries plus any manual shift records.

## Source field

Every punch records `source` (web / kiosk / mobile). The web button uses `source=web`; future kiosk + mobile integrations will use the other two.


## Reminder and auto sign-off settings

**Settings → Clock settings** holds both halves of the time clock:

- **Reminders** — when to nudge somebody who has not clocked in, and somebody still on
  the clock. Set the hours to suit your day; a centre opening at 06:00 should not be
  reminded at 10:00 like everybody else.
- **Auto sign-off** — close shifts and children's days that somebody forgot to close.

Reminders are checked once an hour, so they go out during the hour you choose rather
than at an exact minute. Both are set per agency, in your own timezone.
