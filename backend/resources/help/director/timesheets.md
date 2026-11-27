---
title: Timesheets and correcting hours
category: Staff Management
order: 31
screens: timesheets
---
# Timesheets and correcting hours

The **Timesheets** screen lists every shift worked at your centres in a date range: the date, who worked it, the clock-in, the clock-out, the break and the hours, with the total hours and the number of staff at the top. Directors and agency admins use it to check hours before payroll and to fix a shift that was clocked wrong or not clocked at all. **Payroll** reads these same clock-ins, so a wrong time here becomes a wrong figure on a payslip.

[[open: #timesheets | Open Timesheets]]

## Choosing the hours you look at

1. Open **Human Resources > Clock-ins & timesheets**. The **Timesheets** tab opens first.
2. Pick a centre in the centre list, or leave **All centres** (every centre you can see).
3. Set the first and last day in the two date boxes. The screen opens on the last 30 days, and each change applies at once.
4. Type a name or a date in **Search staff or date…** to narrow the list.
5. Press 🔄 (**Reload**) to read the period again, or ⬇️ (**Download CSV**) to save it for your payroll provider.

> 💡 Filled the dates in backwards? The screen swaps them for you.

## Reading a row

- **An open shift** has no clock-out and shows **0h**. Nobody pressed clock out. It is not added to the total: chase it and close it (see below).
- **No hours logged** is a person rostered at your centre who clocked nothing in the period.
- **Paid** under the hours appears only when your agency has timesheet rules on (rounding, counting to the shift, unpaid break). The line at the top then also says how many hours are paid after the rules: those are the hours payroll uses.
- A 🔒 instead of the buttons means the shift is inside a timesheet that was approved and locked for payroll.

## Fixing the times on a shift

1. Find the shift and press ✏️ (**Edit hours**) at the end of its row. On a phone the buttons sit at the bottom of the row's card.
2. Change **Clock in** or **Clock out**. Times are the centre's clock, not your device's; the popup names the time zone.
3. To close a shift nobody clocked out of, fill in **Clock out**. Leave it empty to keep the shift open.
4. Write why in **Reason (optional)**. It is stamped on the shift, so the next reader sees why it changed.
5. Press **Save changes**. An **Hours corrected** toast appears and the row updates.

The screen refuses the change, and says why, when the clock-out is not after the clock-in or the shift would be longer than 24 hours.

## Adding a shift that was never clocked

1. On the person's row (or their **No hours logged** row), press ➕ (**Add a shift**).
2. Choose the **Centre** the shift is filed under. It decides which payroll pays it, so check it for anyone who works at several sites.
3. Enter **Clock in** and **Clock out**.
4. Write the **Reason**. It is required for a shift entered by hand.
5. Press **Add shift**. A **Shift added** toast appears.

A shift that overlaps one the person already has is refused, because the hours would count twice.

## Removing a shift that did not happen

1. Press 🗑️ (**Remove shift**) on the row: a double clock-in, or a clock-in under the wrong name.
2. Say why the shift is being removed.
3. Press **Remove shift**. A **Shift removed** toast appears.

> ⚠️ A removal cannot be undone. The audit log keeps the whole shift (its times and notes) and who removed it.

## Who can do what

| Role | What they can do |
|---|---|
| Agency admin | See and fix every centre's timesheets. |
| Centre director | See and fix shifts worked at their own centres (the centre on the clock-in, not the person's home centre). |
| Educator, home visitor | Cannot open this screen. They ask for a fix in **My HR > Fix my clock-in** when the HR add-on is on. |

Every correction, hand-entered shift and removal writes an audit row: **Administration > Audit log** as *Staff hours corrected*, *Staff shift entered by hand* and *Staff shift removed*.

## FAQs and when something goes wrong

??? There is a 🔒 where the edit buttons should be. | The shift is inside a pay period that was approved and locked in **Clock-ins & timesheets > Approve & lock**. An agency admin unlocks the period there first, with a reason.
??? The list says "No timesheets in this date range." | Nobody clocked in at that centre in those dates. Check the centre list and both dates, or choose **All centres**.
??? Someone forgot to clock in. Where do I fix it? | Here: press ➕ **Add a shift** on their row, with a reason.
??? I get "The clock-out has to be after the clock-in." | The clock-out is earlier than the clock-in. A shift past midnight needs the next day's date on the clock-out.
??? Can educators change their own hours? | No. Their clock only toggles in and out. A shift left open overnight is closed by a director here, or the person asks for a clock-in fix in My HR.
??? Does the person get told when I change their hours? | No notice is sent from this screen. The change is shown on the shift and recorded in the audit log. (A fix asked for in My HR does notify them when it is decided.)
??? Why does the Paid figure differ from the hours? | Your agency's timesheet rules (rounding, counting from the shift start or to the shift end, unpaid break) apply to paid hours only. The clock-in record never changes.

## Related screens

[[open: #timesheets?tab=lock | Approve & lock]]
[[open: #timesheets?tab=punchfix | Clock-in fixes]]
[[open: #payroll | Payroll]]

- [[hr-overtime-timesheets]]
- [[hr-rota-publish]]
- [Payroll runs, hours and payslips](payroll-documents)
- [Payroll-ready CSV export](payroll-csv)
