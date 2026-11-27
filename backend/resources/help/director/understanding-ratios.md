---
title: Understanding ratios
category: Daily Operations
order: 1
screens: room-ratios
---

# Understanding educator-to-child ratios

The **Room ratios** screen compares the children signed in to each room with the staff on
the floor right now, against your licensing rules, and flags any room that is out of
ratio. Agency admins open it from the sidebar: **Administration** → **Room ratios**.

[[open: #room-ratios | Open Room ratios]]

## Reading the screen

1. Look at the four tiles at the top: **Out of ratio**, **Check staffing**, **Rooms tracked** and **In ratio**.
2. Under them, a 🔔 line says whether breach alerts are on and who is told.
3. Each centre is a card with one row per room: **Room** (with its age group), **Children** (and the maximum group size, if there is one), **Staff** (present / needed), **Why / checks** and **Status**.
4. Read the **Status** chip:
   - **✓ In ratio** — enough staff for the children present.
   - **At the limit** — exactly the minimum. One more child, or one educator stepping out, puts the room out of ratio.
   - **Check staffing** — there are enough staff, but another rule is not met, for example a second adult on site or a qualified educator in the group. **Why / checks** says what.
   - **⚠ Out of ratio** — not enough staff for the children present.
   - **⚠ Over group size** — more children than the room's group may hold, even with enough staff.
5. **Why / checks** says which rule was used, for example *"Youngest child: Under 12 months → 1:5 (Ohio)"*, and lists anything else it found.

The screen refreshes on its own while it is open.

## Changing breach alerts

1. On the 🔔 line, press **Change**. The **🔔 Ratio breach alerts** window opens.
2. Leave **Alert when a room goes out of ratio** ticked (it is on by default). Rooms are checked every 15 minutes from 6 AM to 7 PM: one alert per breach, and a note when the room is back in ratio.
3. Under **Who is told**, tick **Educators in the room**, **Centre directors** and/or **Agency admins**. Educators and directors are ticked by default.
4. Press **Save**. A **Ratio alerts saved** message appears and the 🔔 line updates.

## How staff are counted

If you plan shifts in advance, KiddieTrac uses the shifts to know who is in which room. If
there are no shifts, it counts who is clocked in at the centre and shares them across the
centre's rooms. An agency can choose instead to count each clocked-in educator in the room
they are assigned to (Room assignments); people assigned to several rooms, or to none, are
still shared — rounded down, so nobody is counted twice. Ask KiddieTrac support to switch
this on for your agency.

## Your province's or state's rules

KiddieTrac checks ratios against the rules of the province or state your agency is set to
(Agency overview → Edit agency → Country & compliance). Where those rules are known in
detail, they replace the room's own ratio:

- **The youngest child present sets the ratio.** In Ohio, one infant under 12 months in a mixed group makes the whole group 1:5.
- **Group size.** Each age band has a maximum group size; a room over it shows **⚠ Over group size**.
- **Home child care.** Ohio Type A and Type B homes use the home rule: 7 children per caregiver, no more than 3 under age 2 each, and a total cap (7 for Type B, 14 for Type A). Your own children under 6 count — sign them in too.
- **A second adult on site** once 7 or more children are present (Ohio centres).
- **Nap time.** The card can show how many educators may supervise during nap time. It is for planning and never turns a breach into a pass.

Ontario's standard ratios for licensed centres (O. Reg. 137/15, Schedule 1):

| Age group | Educator : children |
|---|---|
| Infant | 1 : 3 |
| Toddler | 1 : 5 |
| Preschool | 1 : 8 |
| Kindergarten | 1 : 13 |
| Primary / junior school age | 1 : 15 |

Ontario home child care, locations with no licence type set, and the other provinces use
each room's own ratio.

## Setting up Ontario licensed-centre rules

Ontario agencies see **⚙ Ratio settings** on each centre's card.

1. Press **⚙ Ratio settings**. The **Ratio settings — (centre)** window opens.
2. Under **This location is a**, choose **Licensed child care centre — O. Reg. 137/15 rules** (or a home child care option, or **Not set — use each room's own ratio**).
3. Set **Program starts** and **Program ends**.
4. Leave **Use reduced ratios at arrival, departure and rest (s.8(4))** ticked if you use them, and set the **Arrival window (min)**, **Departure window (min)**, **Rest period starts** and **Rest period (min, max 120)**.
5. Enter the **Outdoor play times (always full ratio)**, for example 10:00-11:00, 15:30-16:30.
6. Under **Licensed age group of each room**, pick each room's group.
7. Press **Save**. The card shows **Ontario centre rules** and a line with the windows. See [Ontario ratio rules (licensed centres)](ontario-ratio-rules).

## What to do when a room is out of ratio

- **An educator forgot to clock in** — ask them to clock in on their phone or the tablet.
- **An educator went on break** — make sure another educator is covering.
- **More children than expected** — move a child to another room for now.
- **Short-staffed again and again** — plan more staff or call in a substitute.

Treat **At the limit** as a warning to bring backup. Document any breach you could not avoid.

## Who can do what

- **Agency admins** see every centre's rooms, change breach alerts and the Ontario ratio settings. Room ratios is in their **Administration** menu.
- **Centre directors** and **educators** don't have this screen in their menu; they can receive breach alerts when an admin ticks them under **Who is told**.
- Parents never see ratios.

## FAQs and troubleshooting

??? A room shows out of ratio but the educator is there. | They are probably not clocked in, or are clocked in to a shift in another room. Ask them to clock in, or fix their shift.
??? It says "No rooms tracked." | The agency has no active rooms yet. Add rooms to your centres first.
??? I don't see ⚙ Ratio settings. | It shows for Ontario agencies only. Elsewhere each room's own ratio, or your state's rules, are used.
??? Saving the ratio settings says "Outdoor play times look like 10:00-11:00, separated by commas." | Type each window as start-end in 24-hour time, with a comma between windows.
??? Who gets a breach alert? | Only the groups ticked in 🔔 Ratio breach alerts — by default the room's educators and the centre's directors — once per breach and again when the room is back in ratio. A centre with no director sends the directors' alert to the agency admins.

## Related

[[open: #room-ratios | Room ratios]]
[[open: #educator-rooms | Room Controls]]

- [Ontario ratio rules (licensed centres)](ontario-ratio-rules)
- [Room assignments and room rotations](room-assignments)
- [Substitutes](substitutes)
- [Time clock](time-clock)
