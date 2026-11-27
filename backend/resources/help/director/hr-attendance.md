---
title: HR: absence report, lateness, absence score and leave clashes
category: Staff Management
order: 68
roles: agency_admin, platform_admin, centre_director
screens: hr-absence
---
# HR: absence report, lateness, absence score and leave clashes

**Human Resources > Absence & lateness** shows who has been away and who has been late, for any dates you choose. It has two views: **Time off** (approved time off, repeated short absences and the absence score) and **Lateness** (first clock-ins compared with the shift on the Schedule). Use it before a check-in conversation, at month end, or when you notice a pattern. It is part of the KiddieTrac HR add-on.

[[open: #hr-absence | Open Absence & lateness]]

## Running the absence report

1. Open **Human Resources > Absence & lateness**. The **Time off** view opens.
2. Set the **From** and **To** dates.
3. Choose how to group it: **By person**, **By leave type**, **By month** or **By centre**.
4. Narrow it with **All centres** (when you have more than one) and **All leave types**.
5. Read the totals at the top: **Days off**, **Hours off**, **People**, **Repeated short absences** and **Above the absence score**.
6. Press **Export to Excel** to download what you see.

The table lists **Days off**, **Hours off**, **Absences**, **Short absences**, **Absence score** and a **By leave type** breakdown. Press **⋮ > View HR profile** on a person to see their balances and recent time off.

## Finding repeated short absences

1. Group the report **By person**.
2. Tick **Only repeated short absences**.
3. People who reached your agency's rule (for example 3 or more absences of 2 days or less in 6 months) are listed with an amber **Repeated short absences** pill.

The line under the totals says what the rule is. Agency admins change it in **Human Resources > HR settings** (**Absences**, **Of this many days or less**, **Within (months)**).

## Using the absence score

1. Group the report **By person**.
2. Tick **Only above the absence score**.
3. Read the **Absence score** column. Under each score you see how it was worked out, for example "3 × 3 × 3".

The score is absences × absences × days off over the period (the Bradford formula), so many short absences score higher than one long one: three one-day absences score 27, one three-day absence scores 3. Absences joined only by a weekend or a closed day count as one. Anyone at or above the threshold (50 unless your agency changes it) gets an **Above 50** pill.

> 💡 The score is a prompt for a conversation, never an automatic decision. Look at the reasons behind the absences first.

## Checking lateness

1. Press **Lateness** at the top of the screen.
2. Set **From**, **To** and, if shown, the centre.
3. Tick **Only people who were late** to hide everyone who was on time.
4. Read the totals: **Clock-ins compared**, **Late**, **Late in all** and **People late**.
5. On a row, press **⋮ > View clock-ins**. The **Clock-ins against the schedule** window lists each day: the shift start, the clock-in time and **On time**, "within grace" or "X min late".
6. Press **Export to Excel** to download the list.

Only each person's first clock-in of a scheduled shift is compared. Late means more than the grace time (5 minutes unless your agency changes it) after the shift started. Coming back from a break, and days with no shift, are not compared. The **Pattern** column points out habits such as "Mostly Mondays".

## Setting the attendance rules (agency admins)

1. Open **Human Resources > HR settings**.
2. Scroll to the **Lateness**, **Absence score** and **Leave clashes and minimum staff** sections.
3. Set **Grace time (minutes)**, **Tell the centre's directors** and whether **Contractors** are compared.
4. Set **Mark at or above**, **Over the last (months)** and tick the **Leave types it counts** (none ticked = sick leave).
5. Choose **Show who else is off** and what happens **Below the minimum staff**: **Warn the approver** or **Don't allow approving it**.
6. Type a **Minimum staff on a working day** for each centre, or leave **No minimum**.
7. Press **Save attendance rules**.

## Leave clashes and minimum staff

When someone asks for time off, their form shows how many others at their centre are already off or asking for those days (never who). The person approving sees who, in the **Approve time off** window and the approval email ("Also away at…"). With a minimum set, the approver is told which days would go below it; in **Don't allow approving it** mode those approvals are refused. Leave that needs no approval, such as sick days, is never refused, only shown.

## Who can do what

- **Agency admins**: every centre; change the rules in HR settings.
- **Centre directors**: the people at their own centres; read-only rules.
- **Staff**: do not see this screen. They see only the clash count on their own request.

## FAQs and when something goes wrong

??? Lateness says "No clock-ins to compare in this range." | Lateness needs shifts on the Schedule. A clock-in on a day with no shift is not compared. Check that the rota for those dates is published.
??? The absence report is empty. | It shows approved time off only. Pending and declined requests are left out. Widen the dates or clear the centre and leave type filters.
??? Are directors told when someone is late? | Only if an agency admin set **Tell the centre's directors** to **Yes, a notice when someone is late**. Then the centre's directors get a bell notice. Otherwise it is in the report only.
??? Someone was late but is not on the list. | Only the first clock-in of a shift is compared, and contractors are skipped unless your agency compares them. A clock-in within the grace time counts as on time.
??? Can I change the score threshold? | Agency admins can, in **Human Resources > HR settings** under **Absence score**.

## Related screens

[[open: #time-off | Time off requests]] [[open: #timesheets | Clock-ins & timesheets]] [[open: #hr-reports | HR reports]]

- [[hr]] — people and leave balances
- [[time-off]] — approving time off
- [[hr-reports]] — the lateness and absence reports
