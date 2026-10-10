---
title: Understanding ratios
category: Daily Operations
order: 1
---

# Understanding educator-to-child ratios

Ontario's Child Care and Early Years Act (CCEYA) sets maximum educator-to-child ratios for each age group. Kiddietrac calculates these in real time and flags rooms that are out of compliance.

## Ontario CCEYA standard ratios

| Age group | Ratio (educator : children) |
|---|---|
| Infant (0-18 months) | 1 : 3 |
| Toddler (18-30 months) | 1 : 5 |
| Preschool (30-60 months) | 1 : 8 |
| Kindergarten (44-67 months) | 1 : 13 |
| School-age | 1 : 15 |

When you create a room, you set its ratio target. Kiddietrac uses this to calculate compliance.

## How Kiddietrac calculates ratios

For each room, every minute Kiddietrac checks:

1. How many children are currently checked in to this room
2. How many educators are clocked in (distributed by room if shift assignments exist, otherwise distributed evenly across rooms)
3. Required educators = ceil(children_present / ratio_children)
4. If educators_present >= required → COMPLIANT
5. If educators_present == required AND children_present > 0 → TIGHT (you're at the legal minimum — one more child puts you in breach)
6. If educators_present < required → BREACH

## Your province's or state's rules (new, October 2026)

KiddieTrac now checks ratios against the rules of the province or state your agency is set to (Agency overview → Edit agency → Country & compliance). Where those rules are known in detail, they replace the room's own ratio:

- **The youngest child present sets the ratio.** In Ohio, one infant under 12 months in a mixed group makes the whole group 1:5.
- **Group size.** Each age band has a maximum group size; a room over it shows **OVER GROUP SIZE** even when there are enough educators.
- **Home child care.** Ohio Type A and Type B homes use the home rule instead: 7 children per caregiver, no more than 3 under age 2 each, and a total cap (7 for Type B, 14 for Type A). Your own children under 6 count — sign them in too.
- **A second adult on site** once 7 or more children are present (Ohio centres).
- **Nap time.** The card shows how many educators may supervise during nap time (Ohio: double the ratio for up to 2 hours, never with infants). It is shown for planning; it never turns a breach into a pass.

The ratio card says which rule it used — for example *"Youngest child: Under 12 months → 1:5 (Ohio)"* — and lists anything else it found.

Ontario and the other provinces keep each room's own ratio for now, because their age-by-age tables have not been verified in KiddieTrac yet. Nothing about how those rooms are measured changed.

## What to do when a room is in BREACH

- **An educator forgot to clock in** — ask them to clock in (their phone or the tablet)
- **An educator went on break** — make sure another educator is covering
- **You have more children than expected** — temporarily move a child to another room
- **You're chronically under-staffed** — hire another educator

A breach for more than 15 minutes is a CCEYA violation. Document any breach you couldn't avoid.

## Why TIGHT matters

TIGHT means you're at the legal minimum. If anything happens — a child arrives, an educator takes a bathroom break — you're in breach. Treat TIGHT as a warning to bring backup support.

## Shifts vs clock-in

If you've set up educator shifts in advance, Kiddietrac uses the scheduled shifts to determine who's in which room. If no shifts are set, it counts who is clocked in at the centre and shares them evenly across the centre's rooms.

An agency can choose instead to count each clocked-in educator in the room they are assigned to (Room assignments); educators assigned to several rooms, or to none, are still shared — rounded down, so nobody is counted twice. Ask KiddieTrac support to switch this on for your agency.

For most small centres, just clocking in/out is sufficient. Larger centres benefit from shift planning.
