---
title: HR: publish the rota, copy shifts, shift presets, labour budget, clock-in fixes
category: Staff
order: 48
roles: agency_admin, platform_admin, centre_director
screens: schedule?tab=rotatools, timesheets?tab=punchfix
---
# HR: publish the rota, copy shifts, shift presets, labour budget, clock-in fixes

Rota tools from the KiddieTrac HR add-on. **Human Resources > Rota & shifts > Shift tools** holds the publish step, shift presets and the weekly labour budget; the **Schedule** tab gets **📋 Copy** and a **Publish** bar. **Human Resources > Clock-ins & timesheets > Clock-in fixes** is where staff requests to correct a clock-in are approved.

[[open: #schedule?tab=rotatools | Open Shift tools]]

## Turning on the publish step (agency admins)

1. Open **Human Resources > Rota & shifts** and choose **Shift tools**.
2. Under **Publish step**, tick **Build the rota as a draft and publish it when it is ready**.
3. If staff should confirm each shift, also tick **Staff must accept each shift they are given when it is published**.
4. Press **Save**. A **Rota settings saved.** toast appears.

It is off by default, and then the rota works exactly as before. With it on, new shifts, changes and removals on the **Schedule** wait as drafts, marked **Draft**, **Changed · not published** or **To be removed**. Staff, clock-in, ratio alerts and payroll only see published shifts. Every shift already on the rota counts as published.

## Publishing the rota

1. Build or change the week on the **Schedule** tab as usual (**+ New Shift**, **⚡ Autofill week**).
2. Read the bar above the grid: "📝 N changes not published yet".
3. Press **Publish N** in that bar.
4. Check the count of new, changed and removed shifts, then press **Publish**. A **Rota published** toast appears.

Each person gets one notice for all their own changes. A draft that no longer fits (the person left, or it now overlaps another shift) stays a draft and is listed. To throw all drafts away, press **Discard all drafts** in the same popup and confirm with **Discard drafts**: published shifts stay as they were. The **Rota plan** tab lists the drafts and the ratio warnings as they would be once published.

> ℹ️ The nightly fill and standing hours still publish straight away.

## Copying shifts

1. On the **Schedule** tab, in week view, press **📋 Copy**.
2. Under **What to copy**, choose **Last week into this week (everyone)**, **One person's week**, or **One day onto other days**.
3. For a person, pick them and **Copy their week starting**; for a day, pick **Copy this day** and tick the days under **Onto**.
4. Press **Copy**.

Closed days, approved leave, can't-work dates, overlapping shifts and times already past are left out, and the result lists each one. With the publish step on, the copies are drafts until you publish.

## Making shift presets

1. On **Shift tools**, press **+ New shift preset**.
2. Give it a name (for example "Early 7:00 – 3:00"), the times, the role, the centre (or **Every centre**) and a **Colour**.
3. Press **Save**.

In the shift form, pick it in **Shift preset** to fill the times and role; the shift then shows in that colour. **Archive** hides a preset (shifts already made keep their colour); **Restore** brings it back.

## Setting a weekly labour budget (agency admins)

1. On **Shift tools**, find **Weekly labour budget**. Only people who can see pay see this table; directors who can see pay see the figures but not the button.
2. Press **Set budget** on a centre's row.
3. Enter the weekly amount and press **Save**. Leave it empty to remove the budget.

The **Schedule** then shows "💵 Planned … projected … of a … budget", and the table shows **This week planned**, **Projected cost** and **Of budget**. Salaried staff and people with no pay rate are counted but not costed.

## Approving clock-in fixes

1. Open **Human Resources > Clock-ins & timesheets** and choose **Clock-in fixes**.
2. **Waiting** lists requests, each with what was **Recorded**, what is **Asked for** and **Why**.
3. Press **Approve**, add an optional note, and press **Approve and fix**. Or press **Decline**, write why, and press **Decline**.

Approving changes the clock-in (marked as corrected) and the hours follow on the timesheet and in payroll. The person is told either way. **Decided** shows past requests.

## Who can do what

| Role | Can do |
|---|---|
| Agency admin | Turn on the publish step and acceptance, set budgets, everything below. |
| Centre director | Publish, copy, make presets and approve fixes for their own centres. Sees budgets only if they can see pay. |
| Educator, home visitor | Accept shifts in **My HR > Shifts to accept**, ask for a fix in **My HR > Fix my clock-in**. |

## FAQs and when something goes wrong

??? Do drafts change anything staff see? | No. Until you publish, staff, clock-in, ratio alerts, payroll and shift notices only see the published rota.
??? Can I turn the publish step off? | Yes, once no drafts are waiting. Publish or discard them first.
??? There are no Approve buttons on a fix. | It is your own request: another director or an agency admin decides it.
??? A fix is refused with "Locked timesheet". | The day is inside an approved, locked pay period. An agency admin unlocks it on **Approve & lock** first.
??? A staff member pressed "I can't do this shift". | The centre's directors are told. **Shift tools > Waiting for staff to accept** shows it as **Can't do it**, with their reason.
??? I don't see the 📋 Copy button. | It shows in week view only, for agencies with the HR add-on.

## Related screens

[[open: #schedule | Schedule]]
[[open: #schedule?tab=rota | Rota plan]]
[[open: #timesheets?tab=lock | Approve & lock]]

- [[hr-rota]]
- [[hr-open-shifts]]
- [[hr-overtime-timesheets]]
