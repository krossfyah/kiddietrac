---
roles: agency_admin, platform_admin
title: CACFP meal tracking
category: Compliance
order: 70
screens: cacfp
---
# CACFP meal tracking

**CACFP meals** records which meals each child was served today, for agencies that claim meal reimbursement under the U.S. Child and Adult Care Food Program (CACFP). You tick a box per child per meal; every tick is stored against the family's eligibility tier, and the month's totals by tier are shown at the top, ready for your claim.

**Where it is:** sidebar → **Administration** → **CACFP meals**. The banner reads **🍽 CACFP meal tracking**.

[[open: #cacfp | Open CACFP meals]]

## Recording today's meals

1. Open **CACFP meals**.
2. If your agency has more than one centre, choose one from **Centre** (it starts at **Select a centre…**). With a single centre this step is skipped.
3. The card **Today's roster** lists the children enrolled at that centre and booked to attend today, with a **Tier** column and five meal columns: **Breakfast**, **AM snack**, **Lunch**, **PM snack** and **Dinner**.
4. Tick the box under each meal a child was served. Each tick saves on its own; there is no Save button. If a save is refused, the tick flips back and a red message says why, so a ticked box is always a recorded meal.
5. Untick a box to correct a mistake. The change saves straight away.
6. To work on another centre, use the centre bar at the top of the screen. The roster reloads for that centre.

> 💡 Tick meals at the time of service, not at the end of the day. A claim is only as good as the count behind it.

## Reading the monthly totals

The tiles above the roster count every meal ticked this calendar month at the centre on screen, by tier: a tile for each tier in use (**Tier I**, **Tier II**, **Free**, **Reduced**), **Paid**, **No tier** when some meals were served to a child with no tier on file, and **Total meals this month**.

## The monthly claim: CSV and printout

The **Monthly claim** card builds the claim for the centre on screen.

1. Pick the month in the month box (it starts at this month).
2. Press **⬇️ Claim CSV** to download a spreadsheet, or **🖨 Print claim** to print it or save it as a PDF.
3. Read the line beside the buttons: it says how many meals and operating days the month has, or how many children have no tier on file.

The claim lists what a sponsor's claim form asks for: the site, licence and address; operating days (days with at least one meal recorded); children enrolled by tier; average daily participation (children served a meal, per operating day); meals by type (breakfast, AM snack, lunch, PM snack, supper) and tier; and every day's meals. The CSV also has one line per day and meal type. Children with no tier on file are named at the end of the printout.

## Family and child tiers

The **Tier** column shows the tier that applies today. Use the row's ⋮ → **Set tier** to change it:

1. Choose **The whole family** or **Only this child** (a child can have a different tier from the family, for example a foster child, or a Tier I child in a Tier II home).
2. Pick the **Tier**: **Tier I** or **Tier II** for a family child care home, **Free**, **Reduced** or **Paid** for a centre. For a child, **Use the family's tier** removes the child's own tier.
3. Enter the **Eligibility date**: the day eligibility was determined. **Tier I**, **Free** and **Reduced** count for 12 months from it; **Tier II** and **Paid** need no date.
4. Note the **Evidence seen** (for example the signed income eligibility form, or a SNAP letter), then press **Save**.

Meals already recorded are re-counted under the tier that applied on the day each was served. When an eligibility year ends, the column shows **Expired** and that child's new meals count as **No tier** until the tier is renewed.

## Who can do what

| Role | What they can do |
|---|---|
| Agency admin | Open the screen from the menu, pick any centre, tick meals, set family and child tiers, download or print the monthly claim. |
| Platform admin | The same, inside the agency they have switched into. |
| Centre director and educator | Not in their menu. Meals can only be recorded at a centre the person works at, and a director only ever sees their own centres' totals. |
| Parent | Does not see CACFP counts or tiers. |

## FAQs and troubleshooting

??? The roster says "No children enrolled." | No child is enrolled at this centre and booked for today's weekday. Check the children's rooms and their attendance days.
??? A child who is here today is missing from the roster. | The roster follows each child's enrolment and the days they are booked. Add today's weekday to their pattern on Attendance days, or check that they have an open enrolment in one of this centre's rooms.
??? Can I record meals for yesterday? | No. The roster is always today. Record meals on the day they are served.
??? A tick flipped back, or I see "You can only record meals at a centre you work at." | You are not linked to that centre. Ask an agency admin to add you to it.
??? I see "That child is not enrolled at this centre." | The child's enrolment is at another centre. Switch to that centre and tick the meal there.
??? Can I export the totals? | Yes. Use **⬇️ Claim CSV** or **🖨 Print claim** in the **Monthly claim** card.
??? The screen says "No centres." | Your agency has no open centre you can see. Ask an agency admin to check your centre access.

## Related

- [[open: #cacfp | Open CACFP meals]]
- [Multi-day attendance pattern](attendance-pattern)
- [Daily care logs](daily-care-logs)
- [Ohio publicly funded child care (PFCC)](ohio-pfcc)
- [Compliance and reporting](compliance-and-reporting)
