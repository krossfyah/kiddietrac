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

Directors and agency admins see every staff member's punches at their centre on
**Timesheets**, which rolls up time-clock entries plus any manual shift records.

Each row there carries a **⋮** menu: correct a shift, add one that was never clocked, or
remove one that never happened. An educator's own clock only toggles, so a shift left open
overnight can only be fixed from there. See [Timesheets and correcting hours](timesheets).

## Source field

Every punch records where it came from (web, kiosk or mobile), so you can tell how a shift was clocked.

## Reminder and auto sign-off settings

**Settings → Clock settings** holds both halves of the time clock:

- **Reminders** — when to nudge somebody who has not clocked in, and somebody still on
  the clock. Set the hours to suit your day; a centre opening at 06:00 should not be
  reminded at 10:00 like everybody else.
- **Auto sign-off** — close shifts and children's days that somebody forgot to close.
  The same setting is also on **Settings → Room Controls → Auto sign-off**.

Reminders are checked once an hour, so they go out during the hour you choose rather
than at an exact minute. Both are set per agency, in your own timezone.

## Who gets the "still clocked in" reminder

At the hour you set for **Remind people still on the clock**, KiddieTrac looks at every
shift still open, including one left open on an earlier day (up to 14 days back). A
person is reminded when:

- their rostered shift for the day has ended, if they have one; and
- they have been on the clock longer than their usual day plus an hour. With no history,
  that is 6 hours. It is never less than 5 hours and **never more than 8 hours**, so the
  reminder always arrives before the nightly auto sign-off.

Shifts that auto sign-off closed are left out when working out someone's usual day.
Without that, a person who often forgets would look like they work 12-hour days and would
never be reminded.

Nobody is reminded on a day they have approved time off.

## Children still signed in

At the same hour, educators are also told about **children still signed in**. The
reminder goes to whoever signed each child in. If a parent signed the child in, it goes
to the educators assigned to that child's room. It lists the children, asks the educator
to sign them out at pick-up, and, if auto sign-off for children is on, says what time
they will be signed out automatically.

This only goes out when **Remind people still on the clock** is switched on.

## Email and push switches

The **Send by email** and **Send to the app** switches on the **Reminders** tab apply to
both reminders. Turn off email and only the notification and push are sent; turn off push
and only the notification and email are sent. The in-app notification is always added.

See also: [Auto sign-off](auto-sign-off), [Timesheets and correcting hours](timesheets).
