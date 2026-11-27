---
title: HR settings: leave year, short absences, attendance rules, payroll engine and Google Jobs
category: Staff Management
order: 59
roles: agency_admin, platform_admin
screens: hr-settings
---
# HR settings: leave year, short absences, attendance rules, payroll engine and Google Jobs

**HR settings** holds the agency-wide rules that the rest of KiddieTrac HR follows: when the leave year starts, whether a request may take a balance below zero, how long a working day is, when short absences are flagged, how lateness, absence scores and leave clashes work, the payroll engine switch and Google Jobs. Only agency admins can open it. Who approves time off is on the **Approvers** tab next to it.

[[open: #hr-settings | Open HR settings]]

## Setting the leave year and balances

1. Open **Human Resources > HR settings**. The **Settings** tab opens.
2. In **Leave year starts**, choose **On the same date for everyone** or **On each person's start-date anniversary**.
3. If you chose the same date, pick the month and the day under **Date** (for example January 1).
4. In **Requests that go below zero**, choose **Refuse them** or **Allow them, with a warning**.
5. In **Hours in a working day**, enter the length of a normal day (8 unless you change it). It is used wherever a day of leave has to become hours, for example when a closure is taken from leave.
6. Press **Save settings**. A green toast says "HR settings saved."

> ⚠️ Changing the leave year start does not edit balances already granted. New grants and carry-over follow the new date from the next leave year.

## Flagging repeated short absences

The absence report marks anyone with many short absences in a period. It is a simple count that you set.

1. Under **Repeated short absences**, enter how many **Absences** (3 unless you change it).
2. Enter **Of this many days or less** (2 unless you change it), so only short absences count.
3. Enter **Within (months)** (6 unless you change it).
4. Press **Save settings**.

People who reach it are flagged in **Human Resources > Absence & lateness**.

## Setting the attendance rules

The attendance card sits under the main settings.

1. Under **Lateness**, enter the **Grace time (minutes)**: a first clock-in later than the shift start plus this many minutes counts as late (5 unless you change it).
2. Choose whether to **Tell the centre's directors** each time, and whether **Contractors** are checked.
3. Under **Absence score**, enter **Mark at or above** (50 unless you change it) and **Over the last (months)** (12 unless you change it).
4. Tick the **Leave types it counts**. None ticked = your sick leave types.
5. Under **Leave clashes and minimum staff**, choose whether to **Show who else is off** to the approver.
6. In **Below the minimum staff**, choose **Warn the approver** or **Don't allow approving it**.
7. For each centre, enter the **Minimum staff on a working day**. **Staff placed there** shows how many people work at that centre.
8. Press **Save attendance rules**.

> ℹ️ The absence score multiplies the number of separate absence spells, squared, by the total days off, so many short absences score higher than one long one. Leave that needs no approval (such as sick days) is never refused by the minimum-staff rule, only shown.

## Switching on the payroll engine

The **Payroll engine** card works out CPP, EI, QPIP and income tax for each pay, runs pay runs, issues payslips and shows what to remit. It is off until you switch it on, and your current payroll export is not changed by it.

1. Read the notice on the card.
2. Tick **I have read this and will check the first runs with our accountant** (asked only once).
3. Tick **Payroll engine on**.
4. Enter your **CRA payroll account (RP)**, your **Revenu Québec number (Quebec staff only)** if you have staff in Quebec, the **Remitter type** and, if you want it on reports, the workers' compensation rate.
5. Press **Save payroll engine**. A toast says "The payroll engine is on." and the **Pay runs** and **Remittances** tabs appear under **Payroll**.

For pay runs, remittances and opening balances, see [Payroll engine: pay runs, payslips and remittances](payroll-engine).

## Listing your jobs on Google Jobs

1. Under **Google Jobs**, set **List our open jobs in Google Jobs** to **Yes, list them** (it is **No (default)** until you change it).
2. Press **Save Google Jobs**. A toast says "Google Jobs setting saved."
3. Press **Open your jobs page** to check what applicants see.

Each open job post then carries the details Google reads: title, town, province and country (never a street address), and pay when you set one. Google can take a few days to show new jobs.

## Where the other HR rules live

Rules that belong to one job sit on that job's own tab, beside the work they change: shift publishing and acceptance on **Rota & shifts**, overtime and timesheet rounding on **Clock-ins & timesheets**, closures taken from leave and part-time pro-rata on **Time off > Closures & checks**, recognition on **Educator awards > Recognition**, and probation and invites on the **HR dashboard** (**Probation and invite settings**).

## Who can do what

| Role | HR settings |
|---|---|
| Agency admin | Opens and changes every setting here and on **Approvers** |
| Centre director | Does not see **HR settings**; follows the rules (for example the minimum-staff warning when approving) |
| Educators and home visitors | Not this screen |

## FAQs and when something goes wrong

??? HR settings is not in my menu. | Only agency admins see it, and only when the agency has the KiddieTrac HR add-on.
??? The Payroll engine card is missing. | It shows only to people allowed to change it. Ask your agency admin.
??? It says "Tick the notice first: check with your accountant before your first live run." | Tick the notice box, then save again. It is asked only the first time.
??? My new leave year start did not change anyone's balance. | That is expected: balances already granted are never edited. The new date applies from the next grant.
??? A director could still approve below the minimum staff. | **Warn the approver** only warns. Choose **Don't allow approving it** to stop approvals; leave that needs no approval is still recorded.
??? Does saving these settings email anyone? | No. Settings only change how KiddieTrac works from now on.

## Related screens

[[open: #hr-settings?tab=approvers | Approvers]] [[open: #time-off?tab=types | Leave types]] [[open: #hr-absence | Absence & lateness]] [[open: #hr | HR dashboard]]

See also [[hr-attendance]], [[hr-leave-types]] and [[hr-people-recognition]].
