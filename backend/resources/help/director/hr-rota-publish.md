---
title: HR: publish the rota, copy shifts, shift presets, labour budget, clock-in fixes
category: Staff
order: 48
roles: agency_admin, platform_admin, centre_director
---
# HR: publish the rota, copy shifts, shift presets, labour budget, clock-in fixes

Part of the KiddieTrac HR add-on. The rota tools are in **Human Resources > Rota & shifts**; clock-in fixes and the break rule are in **Human Resources > Clock-ins & timesheets**.

## Build the rota as a draft, then publish

An agency admin switches this on in **Rota & shifts > Shift tools > Publish step**. It is off by default, and then the rota works exactly as before.

With it on, new shifts, changes and removals on the **Schedule** wait as drafts. The grid marks them **Draft**, **Changed · not published** or **To be removed**. Staff, clock-in, ratio alerts and payroll only see published shifts. Every shift already on the rota counts as published.

Press **Publish** in the yellow bar when the rota is ready. Each person hears about their own shifts in one notice. A draft that no longer fits (the person left, or it now overlaps another shift) stays a draft and is listed. **Discard all drafts** throws the drafts away and leaves the published rota as it was. The **Rota plan** tab lists the drafts and shows the ratio warnings as they would be once published.

The nightly fill and standing hours still publish straight away.

## Staff accept their shifts

With the publish step on, an admin can also tick **Staff must accept each shift**. Each new shift then waits in the person's **My HR > Shifts to accept**. If they press **I can't do this shift** and say why, the centre's directors are told. **Shift tools > Waiting for staff to accept** lists every shift not accepted yet.

## Copy shifts

Press **📋 Copy** on the Schedule. You can copy last week into this week, one person's week, or one day onto other days of the week. Days the centre is closed or not open are left out, and so are approved leave, unavailability, overlapping shifts and times already past. Each one left out is listed.

## Shift presets

**Shift tools > Shift presets** holds named shifts such as Early 7:00 – 3:00, each with a colour. Pick one in the shift form to fill in the times and the role. The shift then shows in that colour on the Schedule. Archiving a preset keeps the colour on shifts already made from it.

## Weekly labour budget

Only people who can see pay see this. An admin sets a weekly budget for each centre in **Shift tools**. The Schedule and the Rota plan then show the planned week (published shifts and drafts) × each person's hourly rate against that budget. Salaried staff and people with no pay rate are counted but not costed.

## Clock-in fixes

Staff ask in **My HR > Fix my clock-in** when a clock-in is wrong or missing. Requests come to **Clock-ins & timesheets > Clock-in fixes**. Approving one changes the clock-in, marks it as corrected, and the hours follow on the timesheet and in payroll. Nobody approves a fix to their own clock-ins. A day inside an approved, locked timesheet cannot be changed until an agency admin unlocks it.

## Automatic unpaid break

In **Timesheets > Rules**, an admin can take an unpaid break off long clock-ins automatically, for example 30 minutes from any clock-in longer than 6 hours. It is off by default. When it is on, the timesheet, the payroll run, payslips and exports all use the same paid hours. The clock-in record itself never changes.

## Contracted hours

The timesheet has a **Contracted** column next to Scheduled, Worked and Paid. It is the hours per week on the person's HR file, for the days in the period.

??? Do drafts change anything staff see? | No. Until you publish, staff, clock-in, ratio alerts, payroll and shift notices only see the published rota.
??? Can I turn the publish step off? | Yes, once there are no drafts waiting. Publish or discard them first.
