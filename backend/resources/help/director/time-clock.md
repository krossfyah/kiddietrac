---
title: Staff time clock
category: Daily Operations
order: 76
screens: time-clock
---
# Staff time clock

The **Time clock** is where you punch in at the start of your shift, step out for lunch or a break, and punch out at the end. Every punch feeds the hours your agency sees on timesheets and in payroll. Directors use it for their own shifts; to see and fix everyone else's hours, use **Clock-ins & timesheets**.

[[open: #time-clock | Open Time clock]]

The card in the middle tells you where you stand: "You're currently clocked out.", "You're clocked in since" a time with the hours elapsed, or "You're on lunch" / "You're on a break".

## Clocking in

1. Open **Operations > Time clock**.
2. Press the green **Clock in** button.
3. If your agency asks for your location, a window "Share your location to clock in" opens. Press **Share location** to go on.
4. The button turns red and reads **Clock out**, and the card shows the time you clocked in.

## Taking lunch or a short break

While you are clocked in, two buttons show under the big button.

1. Press **🍽️ Lunch** or **☕ Short break** when you leave the floor. You stay on today's shift.
2. The big button turns purple and reads **I'm back**, and the card says since when you have been away.
3. When you return, press **I'm back**.
4. Confirm **Yes, I’m back** in the window that asks "Are you back from lunch?" (or "from your break?"). Press **Still on lunch** or **Still on break** if you tapped by mistake.

## Clocking out

1. Press the red **Clock out** button.
2. If children you signed in are still signed in, a window lists them and says what to do. Sign them out first, or press **Go ahead anyway** if someone else has them.
3. The button turns green again and the shift shows in **Recent punches** with its hours.

## Reading your recent punches

**Recent punches** lists your shifts, newest first. Each row shows the date, the in and out times, "lunch" or "break" when that is why you stepped out, and the hours. A green dot is a finished shift; an amber dot with **In progress** is the shift you are on now.

> 💡 Forgot to clock in or out? You cannot change a punch here. Ask your director or agency admin to correct it on **Clock-ins & timesheets**, or see [Timesheets and correcting hours](timesheets).

## Reminders and auto sign-off

**Settings > Clock settings** (agency admins) holds both halves of the time clock:

- **Reminders**: when to nudge somebody who has not clocked in, and somebody still on the clock. A centre opening at 06:00 can be reminded earlier than everyone else.
- **Auto sign-off**: closes shifts and children's days that somebody forgot to close. The same setting is on **Settings > Room Controls > Auto sign-off**.

Reminders are checked once an hour, so they arrive during the hour you choose rather than at an exact minute, in your agency's time zone.

### Who gets the "still clocked in" reminder

At the hour set for **Remind people still on the clock**, KiddieTrac looks at every shift still open, including one left open on an earlier day (up to 14 days back). A person is reminded when their rostered shift has ended (if they have one) and they have been on the clock longer than their usual day plus an hour. With no history that is 6 hours. It is never less than 5 hours and never more than 8 hours, so the reminder always comes before the nightly auto sign-off. Shifts closed by auto sign-off are left out when working out someone's usual day, and nobody is reminded on a day of approved time off.

At the same hour, educators are told about **children still signed in**: whoever signed each child in, or the room's educators when a parent did. The reminder lists the children and, if auto sign-off for children is on, says when they will be signed out automatically. This only goes out when **Remind people still on the clock** is on.

The **Send by email** and **Send to the app** switches on the **Reminders** tab apply to both reminders. The in-app notification is always added.

## Who can do what

| Role | What they can do |
|---|---|
| Centre director | Punch in and out, take lunch and breaks, see their own punches. Fix other people's shifts on **Clock-ins & timesheets**. |
| Agency admin | The same, plus **Settings > Clock settings** for the whole agency. |
| Educator | Punch in and out and see their own punches only. |

## FAQs and troubleshooting

??? The button says "Could not update the clock." | The punch did not save. Check your connection and press the button again. Your recent punches show whether it went through.
??? I left a shift open overnight. | The time clock only switches between in and out. Clock out now, then ask a director or agency admin to correct the out time on **Clock-ins & timesheets**.
??? Why did it ask about children still signed in? | You are the one who signed them in and they have not been signed out. Sign them out on the attendance screen, or press **Go ahead anyway** if another educator has taken them over.
??? It keeps asking for my location. | Your agency uses the HR add-on's clock-in area. Allow location for the site in your browser or the app, and clock in from the centre.
??? How does it record where I punched from? | Each punch saves whether it came from the web, a kiosk or the mobile app, so managers can tell how a shift was clocked.

## Related

[[open: #timesheets | Clock-ins & timesheets]]

- [Timesheets and correcting hours](timesheets)
- [Auto sign-off](auto-sign-off)
- [[hr-clock]]
- [[time-clock-settings]]
