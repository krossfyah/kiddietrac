---
title: HR: dashboard, reports, probation and missing information
category: Staff Management
order: 68
roles: agency_admin, platform_admin, centre_director
screens: hr, hr-reports
---
# HR: dashboard, reports, probation and missing information

The **HR dashboard** shows your team at a glance: who works here, who is off, what is waiting for a decision and what still needs setting up. **HR reports** turns the same figures into lists you can print or download. Agency admins see the whole agency; centre directors see the staff of their own centres. Both are part of the KiddieTrac HR add-on.

[[open: #hr | Open the HR dashboard]]

## Reading the dashboard

1. Open **Human Resources > HR dashboard**.
2. Choose the period at the top: **This month**, **This quarter** or **This year**.
3. If you look after more than one centre, pick a centre in the list beside it, or leave **All centres**.
4. Read the tiles. Each one compares the period so far with the same point of the period before (for example November 1 to 27 against October 1 to 27). ▲ and ▼ show the change: green when it is good news, red when it is not.
5. Click a tile to open the screen or report behind it, with the dates already chosen. For example **Waiting for a decision** takes you to what needs approving.
6. Scroll down to the charts. Hover a bar, a slice or a day to see its figures.
7. Press **Table** on a chart to see the numbers as a table; press **Chart** to go back.
8. Click a centre in **Staff by centre** to filter the whole dashboard to that centre.

The tiles cover **Headcount** (with FTE: weekly hours on the HR file divided by a full-time week), **Starters**, **Leavers**, **Turnover**, **Off today**, **Absence rate**, **Late clock-ins**, **Waiting for a decision** (time off, clock-in fixes, timesheets to approve, overtime, claims, shift pick-ups), **Overtime**, **Open shifts**, **Certificates to renew**, **Probation reviews due**, **Onboarding in progress**, **Training completed** and, for people who can see pay, **Labour cost this week** against the budget.

> 💡 Charts draw in as you scroll to them. If your device is set to reduce motion, they appear straight away.

> ℹ️ The figures are at most a minute or two behind. Older or corrected clock-ins are compared with their shifts the first time the dashboard opens, so the **Late clock-ins** tile can say some are still being checked; open the dashboard again a moment later.

## Finishing your HR setup

Under **Setup and to-dos**, the **HR setup checklist** shows five steps with a progress bar: leave types, how much leave people get, complete HR files, a manager for everyone, and staff signed in.

1. Find a step without a ✓.
2. Press **Set up** beside it. You go straight to the screen that finishes it.
3. Come back to the dashboard. The step is ticked once the work is done.

In the **To do** card, press **See the list** beside **Missing information**, or **See who** beside **Not signed in yet**, to open those reports.

## Handling probation

The **Probation ending** card lists people whose probation ends in the next 30 days, or ended without being confirmed.

1. Press **⋮** beside the person.
2. Choose **Mark probation passed** when they have passed. A green toast says "Probation marked passed."
3. Or choose **Extend probation**, pick the new end date and press **Save**.
4. Choose **Back to the agency length** to undo a one-off extension or pass.
5. Choose **Open HR file** to see their full file first.

Press **Everyone in probation** to open the **Probation** report.

### Setting the probation and invite rules (agency admins)

1. Press **Probation and invite settings**.
2. Enter the **Probation length** in days (90 unless you change it; 0 = no probation).
3. Enter how many days before the end the manager is reminded (14 unless you change it; 0 = never).
4. Enter after how many days an unused invite counts as expired (60 unless you change it).
5. Press **Save**.

The reminder is a bell to the manager on the person's HR file, or to their centre director if they have none.

## Running a report

1. Open **Human Resources > HR reports**.
2. Press the report you want in the row at the top.
3. For reports with dates (**Starters and leavers**, **Overtime**, **Lateness**, **Schedule vs worked**, **Team absence**), change **From** and **To**. The report reloads on its own.
4. Press **Print** to print it.
5. Use the export bar under each table to download it as CSV, Excel or PDF.

[[show: #hr-reports @ Reports > Print | Show me Print]]

What each report shows:

- **Headcount**: current staff by centre and by employment type.
- **Starters and leavers**: who joined and left, month by month, with turnover (leavers divided by the average headcount) and retention.
- **Retention**: for each year people started, how many are still here, and how many stayed 90 days and a year.
- **Length of service**: average and median years, in bands.
- **Leave liability**: what you would owe if everyone left today (balances paid out on leaving times each person's pay rate). Only people who can see pay can open it.
- **Overtime**: requests by person, hours paid and hours banked.
- **Lateness**: clock-ins against the shift start, by centre and by person.
- **Schedule vs worked**: scheduled hours against clocked hours, up to 93 days.
- **Leave this year vs last**: what each person earned and took.
- **Team absence**: who is off or has asked to be off, and how many are off each day.
- **Missing information**, **Not signed in yet**, **Probation** and **Everything we hold**: see below.

## Fixing missing information

The **Missing information** report flags a missing start date, manager, emergency contact, job title or employment type. Agency admins are not flagged for a manager. People who can see pay also get payroll checks in red: no pay rate, no deposit details, no SIN or SSN (agency admins only) and pay below the minimum wage.

1. Open the **Missing information** report.
2. Find the person and what is missing.
3. Open their HR file from **Human Resources > People** and fill it in.
4. Come back: the row disappears once nothing is missing.

> ℹ️ The minimum wage check uses the main centre's province or state, for hourly pay and for salaries with hours a week on the HR file. Contractors are not checked. The table used is shown under the report with the date it was last checked. City minimums can be higher, so check your own.

## Resending an invite (agency admins)

1. Open the **Not signed in yet** report. An invite older than your setting shows **Expired**.
2. Press **Resend invite** on the person's row.
3. Check the email address and press **Resend**. A toast says "Invite sent".

The new email has a link to set their own password (works once, for 7 days). "Expired" only marks the list; it never stops anyone signing in.

## Seeing everything held about one person

1. Open the **Everything we hold** report.
2. Pick the person in **Choose a person…** (leavers show "(left)").
3. Read the list of record types and counts. Press **Print** to keep a copy.
4. Agency admins can press **Download all their data** for a ZIP file, for example for a privacy request.

> ⚠️ The download leaves out the SIN, bank details and injury health notes.

## Who can do what

| Role | Sees |
|---|---|
| Agency admin | Every centre, the settings, **Resend invite**, **Download all their data**, the SIN check |
| Centre director | Own centres' staff, probation actions, all reports except pay ones unless allowed to see pay |
| Educators and home visitors | Not this screen; they use **My HR** |

Pay figures (**Labour cost this week**, **Leave liability**, payroll checks) only show to people allowed to see pay.

## FAQs and when something goes wrong

??? The HR dashboard is not in my menu. | It comes with the KiddieTrac HR add-on. Ask your agency admin to turn it on in your plan.
??? A tile shows 0 but I expected more. | Check the period and the centre list at the top. A centre filter from **Staff by centre** stays until you choose **All centres** again.
??? I can't press Mark probation passed for myself. | Nobody can decide their own probation. Your own manager does it.
??? A report says "Nothing to show for this report." | Nothing matches the dates or your centres. Widen **From** and **To**, or ask an agency admin, who sees every centre.
??? Leave liability is missing. | It is shown only to people who can see pay, and needs pay rates on HR files.
??? Which leave counts in Leave liability? | The types set to **Paid out when someone leaves** in **Time off > Leave types**: by default your vacation type and the Overtime bank.
??? Does resending an invite change their password? | No. Any password they already have keeps working; the link lets them set a new one.

## Related screens

[[open: #hr-people | People]] [[open: #time-off | Time off]] [[open: #hr-absence | Absence & lateness]] [[open: #timesheets | Clock-ins & timesheets]]

See also [[hr]], [[hr-documents-leaving]] and [[hr-attendance]].
