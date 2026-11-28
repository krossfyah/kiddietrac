---
title: Safe Arrival — every scheduled child, accounted for
category: Operations
order: 5
roles: agency_admin, centre_director, platform_admin
screens: safe-arrival
---
# Safe Arrival — every scheduled child, accounted for

Safe Arrival makes sure no child who was expected today goes unnoticed. If a child
hasn't been signed in by their expected drop-off time and nobody has reported an
absence, KiddieTrac asks the parents. If they don't answer, it can **phone them**, and
then it alerts the room's educators and the director — and the **Safe Arrival** board
shows who still needs a call. At the end of the day it can also check on every child still
signed in. Open it from the sidebar: **Operations** → **Safe Arrival**.

> ℹ️ Safe Arrival comes with the **SMS & voice** add-on. Without it, agency admins see it greyed out with an **Upgrade** badge.

[[open: #safe-arrival | Open Safe Arrival]]

## How it works

1. Every 5 minutes, KiddieTrac looks at each child who is **scheduled today** at a centre that is **open today**.
2. When a child's **expected drop-off time + the arrival threshold** passes, and they are not signed in and no absence was reported, the parents are asked once: *"Has Maya arrived?"* — through the channels each parent chose (app, email, text). They can tap **Not attending today** in the app.
   Every message to a family comes **from your agency's name** (your white-label brand, if you have one; a text starts *"Sunny Days Child Care: Maya was expected at..."*, the email is from your agency, the call says *"a Safe Arrival call from Sunny Days Child Care"*), and an educator is only ever named by **first name** (a home named after its provider reads *"Maria's home child care"*).
3. If the phone call is on and there's still no answer, the parents get an automated call (see below).
4. If there is still no sign-in and no absence, the room's educators — and the directors or admins you chose — get an alert that opens this board.
5. The moment the child is signed in, ticked in a headcount, or an absence is reported, the check closes by itself.

Nothing is checked on a day the child isn't scheduled, or when the centre is closed.

## Turning it on

Safe Arrival is **off** until an agency admin turns it on, because it messages parents.

1. Open **Safe Arrival** and press **⚙️ Settings**. The **Safe Arrival settings** window opens.
2. Tick **Check that every scheduled child arrives**.
3. Set the **⏱ Arrival threshold** — how many minutes after the expected drop-off time to ask the parents (default 15).
4. Set how long to wait for the parents before alerting the room's educators (default 20).
5. Optional: tick **Phone the parents if they don't answer**, and set **Call this many minutes after the parents were asked** (default 10).
6. Set the **Expected time for a child without one** (default 9:00 AM).
7. Under **Who is alerted**, tick **Directors of the child's centre** and/or **All agency admins**, choose **When directors/admins are alerted**, **Email directors/admins** and whether to **Also text them (SMS)**.
8. Press **Save**.

[[show: #safe-arrival @ Settings | Show me the settings button]]

> 💡 Give each child their own drop-off time on their record (**Usual times**). Children without one are checked against the default time, and the board marks them **(default)**.

More choices in the same window:

- **Start checking a new child the day after they are added** (on by default), so a family still being set up doesn't get a "where is your child?" text on day one.
- **Headcounts** — **A child ticked in a headcount counts as arrived**, or **Ignore headcounts. Only a sign-in counts**, and how long the **Count holds for**.
- **💛 Not here after every check** — **Mark absent and email the family**: **After the last alert, if still not here** (60 minutes by default), **At the centre's closing time**, or **Never (leave it for staff)**. The family gets a short, kind email that the child was marked absent, with how to reach you.
- **⏰ When a parent says they're running late** — who is told when a parent reports a late drop-off or pickup. Nobody is asked or alerted before that time.

When Safe Arrival is on, it replaces the old 9:30 AM "has your child arrived?" reminder,
so parents get one message at the right time instead of two.

## The phone call

The call is **off** until an agency admin ticks **Phone the parents if they don't answer**. It needs voice calls set up for your agency (Settings → **Carrier settings** → **Voice calls**). The automated call names the child and asks the parent to press a key:

- **1 — absent today.** Recorded as today's absence, exactly like **Not attending today**. The check closes.
- **2 — on the way, arriving late.** The board shows **On the way (parent)**. If the child still isn't signed in in time, staff are alerted after all.
- **3 — already dropped off.** The child is **not** signed in on the parent's word. The room, the director and the admins get an **urgent** alert — *"🚨 Safe Arrival: Maya dropped off but NOT signed in"* — to find the child and sign them in. If the alert reaches nobody's phone, it is emailed too.

Whatever the parent presses, staff are told and it is written to the audit log. Only the first answer counts. Guardians are called **one at a time, primary first**; pick-up logins are never called, and nor is anyone who opted out of phone calls.

## Phone call wording and options

1. In **⚙️ Settings**, open **📞 Phone call — wording and options**.
2. Untick **Offer 2 (on the way)** or **Offer 3 (dropped off)** to leave one out (1 is always offered).
3. Choose **Who is called** (**Each guardian in turn, primary first** or **Primary guardian only**), **Ask the question** (once, 2 or 3 times), **Wait for a key (seconds)** (5 to 30) and **Before calling the next guardian** (default 1 minute).
4. Change the wording boxes if you like. Leave a box empty to use the standard wording shown in grey. Use **{child}**, **{child_full}**, **{agency}** (who is calling: your agency's name), **{centre}**, **{room}** and **{time}**.
5. Press **▶ Play test call to my phone** to hear it on the mobile number on your own profile, with "Test Child". Nothing is recorded. **Reset wording to standard** empties the boxes.
6. Press **Save**.

## Using the board

1. Check the tiles: **EXPECTED TODAY**, **ARRIVED**, **ABSENT**, **NOT DUE YET** and **NEEDS ATTENTION**. Use **All centres** to pick a centre and **↻ Refresh** to update (it also refreshes every minute).
2. Under **Needs attention**, overdue children are listed first — **dropped off — NOT signed in** at the very top — with each guardian's name and a tap-to-call **📞** number, and when the parents were asked and called.
3. Close each one with **Record absence** (with a reason — saved as today's absence), **Parent contacted** (with a note, e.g. *"Spoke to mum at 9:40, arriving 10:15"*), **Other…** (with a note), or **⏰ Late drop-off** to say when the child will come.
4. **📞 Phone messages from parents** and **🎫 Temporary pickups** appear when there is something to act on.
5. **Everyone else today** shows who has arrived, who is absent and why, and who isn't due yet.

## End of day — still signed in

1. In **⚙️ Settings**, tick **End of day: check on every child still signed in**.
2. Set the **⏱ Departure threshold**, **Then alert directors/admins after (minutes)**, **Then ask the parents after (minutes)** and the **Closing time for a centre without one**. A child's own pickup time (**Usual times**) is used when set.
3. On the board, **🕔 End of day — still signed in** lists children past their pickup time. Close each with **Picked up at…** (signs them out), **Still here**, **⏰ Late pickup** or **Other…**.

## History

Open **🕘 History** for past checks, up to 92 days at a time: pick **From** and **To**, **Arrival + end of day** (or **Arrival only**, **End of day only**), a centre, a child's name, and **Only ones that alerted staff**. Each check keeps when the parents were asked and called, what they pressed, when staff were alerted, and how it was closed, by whom and with what note.

## Who can do what

- **Agency admins** see every centre and are the only ones who see **⚙️ Settings**, change settings or place a test call.
- **Centre directors** see and close checks for their own centres.
- **Educators** see and close checks for their own rooms.
- **Parents** answer by app, email, text or phone, and can report an absence or a late drop-off.

## FAQs and troubleshooting

??? Why wasn't a child checked today? | They aren't scheduled today (check their enrolment days), the centre is closed (holiday or closure on the calendar), an absence was already reported, or they were added today and start tomorrow.
??? A parent says they didn't get the message. | Messages follow each parent's own choices in their profile. Check they have the app with notifications on, an email address, or agreed to texts.
??? A parent wasn't phoned. | Check the phone call is on, voice calls are set up, the parent has a phone number and hasn't opted out of calls, and whether **Primary guardian only** is selected.
??? The test call didn't ring. | Add your mobile number to your own profile. The test can't ring a number that opted out of calls.
??? A card says "⚠️ No phone alert reached: … (emailed instead)". | Nobody's phone got the push, so those people were emailed. Ask them to turn on notifications in the KiddieTrac app.
??? Can educators turn it off? | No. Only an agency admin can change the settings or place a test call.

## Related

[[open: #safe-arrival | Safe Arrival]]
[[open: #headcounts | Headcounts]]
[[open: #late-pickups | Late pickups]]

- [Texts and phone calls — choosing what you get](texts-and-phone-calls)
- [Headcounts and the acting supervisor](headcounts-and-acting-supervisor)
- [Late pickups and fees](late-pickup-fees)
- [Auto sign-off](auto-sign-off)
