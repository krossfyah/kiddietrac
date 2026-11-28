---
title: Payroll engine: pay runs, payslips and remittances
category: Staff Management
order: 67
roles: agency_admin, platform_admin, centre_director
screens: payroll?tab=payruns, payroll?tab=remit, payroll?tab=ytd, hr-settings
---
# Payroll engine: pay runs, payslips and remittances

The payroll engine works out each person's pay with every Canadian deduction (CPP, EI, QPIP and income tax), issues payslips and shows what you owe the CRA. It is part of the KiddieTrac HR add-on and is **off** until an agency admin switches it on. While it is off, your payroll export works exactly as before.

## Switching it on (agency admins)

Open **HR settings** and find **Payroll engine**.

1. Read the notice and tick it: **check with your accountant before your first live pay run**. You accept it once.
2. Tick **Payroll engine on**.
3. Enter your **CRA payroll account** (9 digits, RP, 4 digits), your **Revenu Québec number** if you have staff in Quebec, your **remitter type** and, if you like, your **workers' compensation rate** (used only for an estimate on the remittance report).

Each person also needs a **Payroll tax setup** on their HR profile (province of employment, pay frequency) and a pay rate. Staff fill in their own TD1 in **My HR → Tax forms**.

## A pay run

Open **Payroll → Pay runs** and choose **New pay run**. Pick the centre (or every centre), the period and the pay date.

- Hours come from the clock (timesheets): the paid time for each person, with approved overtime and claims added.
- Each line shows hours, gross pay, CPP, EI, income tax and net pay. Open **⋮ → How it was worked out** to see every figure, the year to date before this pay and the CRA tables used.
- On a draft you can add a bonus, a taxable benefit or a deduction after tax, or leave someone out. **Recalculate** reads the clock again.

Then: **Send for review → Approve → Finalize and issue payslips.** The person who approves cannot be paid in the run. Finalizing gives each person their payslip in the app and an email that says it is ready, with no amounts in the email.

A finalized run is never changed. To correct one, use **⋮ → Make an adjustment**: it pays what changed since (for example a clock-in fixed late).

## Viewing a payslip

Admins and directors (for their own centres' staff) can open a payslip in the portal without its password. **Download**, and any payslip emailed to the staff member, is always the password-protected file. Every view is in the audit log.

## Remittances

**Payroll → Remittances** adds up the finalized runs per remitting period: CPP and EI (both shares) and income tax owed to the CRA, what is owed to Revenu Québec for Quebec staff, and the due date for your remitter type. Use it to fill in your PD7A voucher and check it with your accountant.

## Opening balances (agency admins)

If you start mid-year, enter in **Payroll → Opening balances** what your previous payroll paid each person this year, so CPP and EI stop at the yearly maximum and the year to date on payslips is right. You can paste a spreadsheet. You cannot enter your own.

## What the payslip shows

Every payslip PDF has the same layout:

- **A band in your agency's colour** with your logo in a round badge, your legal name, address, phone, email, CRA business number (when set) and the centre.
- **The employee**: full name, employee ID, position, centre, employment type, start date, home address, phone and email.
- **Pay details**: SIN shown as **XXX-XXX-123** only (never the full number), province of employment, pay period, pay date, payslip number, pay run, pay rate and how they are paid (direct deposit shows only the last 4 digits of the account).
- **Year to date and this pay**: gross, deductions and net, with **net pay** in a large box.
- **Earnings** (rate, hours, this pay, year to date) and **Deductions** (CPP or QPP, EI or QPIP, federal and provincial income tax, other) with totals. Employer contributions and leave balances are shown for information.
- **Remarks** from the pay run, your **terms and conditions**, and a light diagonal **NON-NEGOTIABLE** watermark with the line **NON-NEGOTIABLE — THIS IS NOT A CHEQUE** on every page.

A payslip where payroll did not work out deductions (a manual payslip, or gross pay only) shows what was recorded and says **Deductions not calculated in KiddieTrac**. No figures are made up.

## Payslip terms and conditions (agency admins)

Open **Payroll settings** and find **Payslip terms and conditions**. Write one statement per line. Set how many days staff have to report a pay error (30 by default); `{days}` in your text becomes that number. Leave the standard wording and each person reads it in their own language (English, French, Spanish or Hindi). **Use the standard wording** puts it back.
