---
title: HR: people, employee files and leave balances
category: Staff Management
order: 60
roles: agency_admin, platform_admin, centre_director
screens: hr-people, time-off?tab=balances
---
# HR: people, employee files and leave balances

**Human Resources > People** is the list of everyone on your team, with each person's HR file: job title, employment type, centre, start date, manager and status. **Human Resources > Time off > Balances** shows how much leave each person has left. Both are part of the KiddieTrac HR add-on. Open them when someone joins, changes job, needs a manager set, or asks how many days they have left.

[[open: #hr-people | Open People]]

## Finding someone

1. Open **Human Resources > People**. The **People** tab lists name, **Job title**, **Employment**, **Centre**, **Start date**, **Manager** and **Status**.
2. Use the first filter to show **Everyone** or only one status (for example active, on leave or terminated).
3. If you have more than one centre, pick a centre in **All centres** to see only the people whose primary centre it is.
4. The count beside the filters ("8 of 12 people") tells you how many match.

> 💡 The other tabs on this screen are **Directory**, **Org chart** and **Leavers**. See [[hr-people-recognition]] and [[hr-documents-leaving]].

## Opening an HR profile

1. On a person's row, press **⋮ > View HR profile**.
2. Read the cards: **Employment**, **Leave balances**, **Recent time off**, **Certifications**, **Policies & training**, **Emergency contact**, and **Pay** when you are allowed to see pay.
3. Press **Balance history** under **Leave balances** to see every grant, accrual, approved request and adjustment behind each balance.
4. Use the links in the cards (**Open Time off requests**, **Open Certifications**, **Open Policies**, **Open Payroll**, **Open user record**) to jump to that screen.
5. Scroll down for the person's documents, pay and role history, reviews and goals, and leaving, when those parts of HR are in use.
6. Press **Close**, or **Edit file** / **Adjust balance** at the bottom.

## Editing an employee file

1. On the row, press **⋮ > Edit file**. The **Edit employee file** window opens.
2. Fill in **Job title** (for example Lead Teacher).
3. Choose the **Employment type**: full-time, part-time, casual, student or contract.
4. Set the **Status**, the **Start date**, the **Primary centre** and the **Manager**.
5. If the person has left, choose the terminated status and fill in **End date** and **End reason**.
6. Press **Save**. A green **Saved** toast says "Employee file updated."

> ℹ️ Choosing **contract** also marks the person as a contractor on the Staff screen (used for "All contractors" announcements). Changing the type away from contract clears it.

> ⚠️ A terminated file needs an end date, or the window shows "Add the end date." Leave balances stop growing for terminated staff. To process a full leaving (final pay, record of employment, checklist), use **Leavers** instead: see [[hr-documents-leaving]].

## Adjusting a leave balance

1. On the **People** or **Balances** tab, press **⋮ > Adjust balance**. The **Adjust leave balance** window opens.
2. Pick the **Leave type**. The list shows the balance now.
3. Under **Change**, choose **Add to the balance** or **Take off the balance**.
4. Type the **Amount** (quarter steps are allowed, for example 0.5).
5. Write the **Reason (required)** so anyone reading the history understands it.
6. Press **Save adjustment**. The toast shows the new balance.

> ⚠️ Adjustments are never edited or deleted. To undo one, add the opposite adjustment with a reason.

## Reading the Balances tab

1. Open **Human Resources > Time off > Balances**.
2. Each row is a person; each column is a leave type, with its colour.
3. A cell shows the balance. When requests are waiting, it also shows "X pending, Y after", which is what will be left once they are approved.
4. A balance below zero is shown in red.
5. Press **⋮ > View HR profile** to see the history, or **⋮ > Adjust balance** to correct it.

[[show: #time-off?tab=balances @ Balances | Show me the Balances tab]]

A balance is the sum of its history: grants, accruals, approved time off and adjustments. Approving a request takes it off; denying or withdrawing it gives it back.

## Who can do what

| Role | People and balances |
|---|---|
| Agency admin | Everyone in the agency. Can edit files, adjust balances, and see and change the SIN/SSN card (stored encrypted; every reveal is in the audit log). |
| Centre director | Staff of their own centres. Can edit their files and adjust their balances. Sees pay only when payroll is on for the agency. Never sees the SIN/SSN. |
| Educators and home visitors | Do not see this screen. They see their own balance in **My HR** and on **Time off**. |

Nobody can adjust their own balance, not even an agency admin.

## FAQs and when something goes wrong

??? "Not your own balance" appears when I press Adjust balance. | Nobody adjusts their own leave. Ask another director or an agency admin to do it.
??? The Balances tab says "No leave types yet". | An agency admin has to add leave types first under **Human Resources > Time off > Leave types**. See [[hr-leave-types]].
??? Someone is missing from People. | People lists active staff accounts of your agency (directors see only their own centres). Deactivated accounts and people from outside the agency (such as platform staff) are not listed. Check their account on the Staff screen.
??? A balance looks wrong. | Open **⋮ > View HR profile** and press **Balance history**. Every change is listed with its reason. If an entitlement rule was wrong, fix the rule, then add an adjustment for the difference.
??? How do I give someone a manager? | **⋮ > Edit file**, choose the **Manager**, press **Save**. The manager is used by the org chart, reviews and approvals.
??? Is the person told when I edit their file or adjust their balance? | No email is sent. They see the new balance in **My HR** and on their time-off form.

## Related screens

[[open: #time-off | Time off requests]] [[open: #hr-absence | Absence & lateness]] [[open: #hr | HR dashboard]]

- [[hr-leave-types]] — leave types and entitlement rules
- [[hr-documents-leaving]] — documents, pay history and leaving
- [[hr-people-recognition]] — directory, org chart and approvers
- [[time-off]] — requesting and approving time off
