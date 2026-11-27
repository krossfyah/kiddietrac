---
title: HR: overtime, time in lieu and timesheets
category: Staff Management
order: 66
roles: agency_admin, platform_admin, centre_director, educator, home_visitor
screens: timesheets?tab=lock, timesheets?tab=overtime
---
# HR: overtime, time in lieu and timesheets

Two tabs of **Human Resources > Clock-ins & timesheets**, part of the KiddieTrac HR add-on. **Approve & lock** compares each person's scheduled shifts with their clock-ins for one centre and pay period; approving the period locks it for payroll. **Overtime** is where extra hours are approved, then paid on the next payslip or banked as time in lieu.

[[open: #timesheets?tab=lock | Open Approve & lock]]

## Approving and locking a pay period

1. Open **Human Resources > Clock-ins & timesheets** and choose the **Approve & lock** tab.
2. Pick the centre (if you have more than one).
3. Set the dates, or press **Last week**, **Last 2 weeks**, **Last month** or **This month so far**.
4. Read the counts across the top: **People**, **Scheduled**, **Contracted**, **Worked**, **Paid**, **No clock-out**, **Shift with no clock-in** and **Hours with no shift**.
5. Press **View clock-ins** on a person's row to see each day. Fix a missing clock-out on the **Timesheets** tab first.
6. Press **Approve and lock this period**, check the summary in the popup, and press **Approve and lock**.

A green **Approved and locked** note replaces the button and the period is added to **Approved periods**. Clock-ins on those days can no longer be added, corrected or removed, on any screen.

[[show: #timesheets @ Approve & lock > Approve and lock this period | Show me]]

> ℹ️ The button stays grey, with the reason beside it, until the period has ended, every clock-in has a clock-out, and your own hours are not on the sheet (then another director or an agency admin approves it).

## Unlocking a period (agency admins)

1. Open the period on **Approve & lock**.
2. Press **Unlock** in the green note.
3. Say why it is unlocked and press **Unlock**.

The unlock is in the audit log, and the period needs approving again before payroll.

## Approving overtime

1. Choose the **Overtime** tab. **To approve** lists what is waiting; **Approved**, **Paid**, **Declined** and **All** show the rest.
2. Press **View overtime** on a row to see the date, hours, what it was for and what the person would like.
3. Choose one:
   - **Approve as pay**: it is offered as a line on the person's next payslip in **Payroll**, priced from the hours, the overtime rate and their hourly rate.
   - **Bank as time in lieu**: the hours times the overtime rate go into their **Overtime bank** balance straight away.
   - **Decline**: write why (the person sees it) and press **Decline overtime**.

To record overtime for one of your people, press **Log overtime for someone**, fill in **Date worked**, **Extra hours** and **What the extra hours were for**, and press **Send**.

## Setting the rules (agency admins)

1. On the **Overtime** tab press **Overtime and timesheet rules** (or **Rules** on **Approve & lock**).
2. Set the **Overtime rate (1 = straight time, 1.5 = time and a half)**.
3. Tick whether **Staff can log their own overtime**, and whether overtime can be paid, banked, or both.
4. Under **Timesheets**, choose **Round clock-in and clock-out** (**Off: exact clock times** or to the nearest 5, 6, 10 or 15 minutes), and tick **Count from the shift start when someone clocks in early** or **Count to the shift end when someone clocks out late**.
5. Under **Before payroll**, choose **Warn when a period is not approved** or **Only pay approved, locked periods**.
6. Under **Unpaid break**, tick **Take an unpaid break off long clock-ins automatically** and set the length and after how long.
7. Press **Save rules**. A **Rules saved.** toast appears.

All these rules start **off**, so paid hours equal the clock exactly. When on, they change paid hours everywhere: this tab, the Timesheets screen (a **Paid** figure), payroll runs, summaries, exports, payslips and contractor invoices. The clock-in record itself never changes.

## Logging your own overtime (staff)

1. Open **My HR** and find **Overtime and time in lieu**.
2. Press **Log overtime**.
3. Fill in the date, the extra hours, what they were for, and whether you would like pay or time in lieu.
4. Press **Send**. You see **Sent for approval.**

You can withdraw it while it waits. To use banked hours, book time off with the **Overtime bank** leave type. If you see "Your director logs overtime for you.", your agency has staff logging turned off.

## Who can do what

| Role | Can do |
|---|---|
| Agency admin | Everything, every centre; change the rules; unlock periods. |
| Centre director | Approve overtime and lock periods for their own centres, never their own. |
| Educator, home visitor | Log their own overtime (if allowed) and see their Overtime bank in My HR. |

## FAQs and when something goes wrong

??? Approve and lock this period is grey. | Read the line beside it: the period has not ended, a clock-in has no clock-out ("Add the clock-out first"), or your own hours are on the sheet.
??? Can I decline overtime I already banked? | No. Banked hours are final. Adjust the person's balance in **Time off > Balances** instead.
??? Someone needs a shift fixed inside a locked period. | An agency admin unlocks the period, the shift is fixed, then the period is approved again.
??? What does "Only pay approved, locked periods" do? | Payroll refuses hours from a period that has not been approved and locked. "Warn" only shows a warning.
??? Is the person told about my decision? | Yes. They get a notice when their overtime is approved, banked or declined, with your comment.

## Related screens

[[open: #timesheets | Timesheets]]
[[open: #timesheets?tab=punchfix | Clock-in fixes]]
[[open: #time-off?tab=balances | Leave balances]]

- [[timesheets]]
- [[hr-rota-publish]]
- [[hr-clock]]
