---
title: Payroll runs, hours and payslips
category: Staff Management
order: 30
roles: agency_admin, platform_admin, centre_director
screens: payroll
---
# Payroll runs, hours and payslips

The **Payroll** screen answers one question: what did each person work in a period, and what were they paid for it. It shows clocked hours beside the payslips issued for the same dates, lets you open, print, email or mark each payslip, and is where an agency admin makes a payroll run. Open it from **Human Resources > Payroll**.

[[open: #payroll | Open Payroll]]

The screen has two views: **⏱️ Hours & payslips** (what was worked, with each person's payslips on their row) and **📅 Pay periods** (one row per pay period, with totals and a hours check).

## Seeing hours and payslips for a period

1. Open **Human Resources > Payroll**. The **⏱️ Hours & payslips** view opens on the last 30 days.
2. Press a quick period: **This pay period**, **Last pay period**, **This month** or **Last month**. Or type the **From** and **To** dates and press **Run**.
3. Choose a tab: **🧑‍🏫 Educators**, **👥 Other staff** or **📄 Contractors**. The screen opens on the first tab with anyone in it.
4. Read each row: **Staff**, **Role**, **Centre**, **Punches**, **Hours** and **Payslips**. Each group has its own subtotal, and the overall total stays under the tabs.
5. Press the **Payslips** link on a row (for example "2 payslips · $1,850.00") to list that person's documents for the period.

Only closed punches count. Someone still clocked in is named on their row so you can see what was left out. People paid without clocking in (a salaried manager, a home visitor paid per visit) still appear, with 0 punches and their payslips.

> ℹ️ Contractors do not clock in and have no login, so their tab shows what they were **paid** in the period instead of hours.

## Opening, emailing or marking a payslip

1. Open the person's payslips from the **Payslips** link.
2. Press **👁 View** to read the PDF, **⬇️ Download** to save it, or **🖨 Print** to print it.
3. Press **✉️ Resend payslip** to email it. Confirm the **Email address** (it is filled in from the file but never sent without you confirming), add **Also send a copy to (optional)** or a **Note (optional)**, and send.
4. Press **Mark as paid** once the money has gone out. Press **Mark unpaid** to undo it.
5. Press **Send by Interac** to pay a payslip by Interac. It names the amount and the person and asks you to confirm, because it moves real money.

**Send by Interac** shows only for a person with an account; contractors are paid by hand and marked paid.

## Reviewing a whole pay period

1. Press **📅 Pay periods**.
2. Choose the **Year**, or **All years**.
3. Read the cards: **Net paid**, **People paid**, **Average per person**, **Outstanding** (payslips not marked paid yet) and the **Hours check**.
4. Press **Open period ›** on a row.
5. Compare **Payslip hrs** with **Clock hrs** for each person. A row marked **⚠ to review** has a difference; **✓ all match** means none.
6. Use **View payslip**, **Download payslip**, **✉️ Resend payslip** or **Mark paid** on a row, or **✉️ Resend all payslips** for the period.

## Making a payroll run (agency admins)

1. Press **＋ Generate payroll**.
2. Enter the **Period start** and **Period end**, then press **Read the clock**.
3. On the **Educators**, **Other staff** and **Contractors** tabs, tick the people to pay.
4. Type each person's **Rate**. **Pay** fills in as hours × rate as a starting point; you can overwrite it.
5. Press **Lines & notes** on a row to **+ Add a line** (mileage, a top-up, a deduction) or write **Notes on this payslip**.
6. Press **Approve and create payslips**.

The rate is never filled in for you: type it every run. Anyone who already has a payslip for the same period is skipped, and the run tells you how many were skipped and why, so nobody is paid twice. If timesheets for the period are not approved yet, the run warns you to approve them in **Clock-ins & timesheets > Approve & lock**.

## Exporting the hours

Press **⤓ CSV** to download the hours report for the dates shown. It is an hours file: people paid without punches are on the screen, not in the file. See [Payroll-ready CSV export](payroll-csv).

## Payslip passwords

Every payslip PDF a person downloads or is emailed is password-protected, the way iLearn protects its payslips: payroll engine payslips, payslips from **Generate payroll** or added by hand, gross-only payslips, weekly statements, and T4, T4A and RL-1 copies. The file is locked with AES-256 encryption; once it is open it can be printed and copied.

- **The password:** the first 3 letters of the person's last name in CAPITALS, then their date of birth as MMDDYYYY. For example, Jordan Ellis born March 2, 1990 uses `ELL03021990`.
- **No date of birth on file:** the last day of the pay period stands in (December 31 of the tax year for a tax slip). **No last name:** the first 3 letters of the first name. A payslip is never sent unlocked.
- **Staff are told the format, never the password**, on the payslip list in the app and in the payslip email. The email has no amounts in it.
- **Who is affected:** **Payroll settings → Payslip passwords** lists everyone paid here who has no date of birth or last name on file. Add it in their HR file and their next download uses the usual password.
- Opening a payslip in the portal (the office view, for admins and directors) needs no password. This cannot be switched off.

## Who can do what

| Role | What they can do |
|---|---|
| Agency admin | Everything here, including **＋ Generate payroll** and **Send by Interac**. |
| Centre director | See hours and payslips for their own centres, open, print, email and mark payslips. Directors do not make payroll runs. |
| Staff | See their own payslips in **My HR**, not this screen. |

## FAQs and when something goes wrong

??? It says "No punches in range." | Nobody clocked in between the dates. Try **Last pay period** or wider dates; salaried staff show only when a payslip exists.
??? Someone's hours look wrong. | Hours come from the time clock. Correct the shift in **Clock-ins & timesheets** first, then press **Run** again.
??? "No payroll documents for the agency you are viewing." | No payslips have been issued for this agency yet. They appear once a payroll run is made or payslips arrive from your payroll system.
??? I pressed ＋ Generate payroll and the run did not open. | Payroll runs are for agency admins. Ask an agency admin to run it.
??? A person was skipped in the run. | They already have a payslip for the same period. Open it from the **Payslips** link instead.
??? Where do the Pay runs and Remittances tabs come from? | They belong to the payroll engine, shown only when your agency has switched it on. See [Payroll engine: pay runs, payslips and remittances](payroll-engine).

## Related screens

[[open: #timesheets | Clock-ins & timesheets]] [[open: #payroll?tab=claims | Claims & mileage]]

- [Timesheets and correcting hours](timesheets)
- [HR: expense and mileage claims](hr-claims)
- [Payroll-ready CSV export](payroll-csv)
