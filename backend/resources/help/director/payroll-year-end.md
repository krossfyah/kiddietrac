---
title: Payroll year end: T4, T4A and RL-1 slips
category: Staff Management
order: 69
roles: agency_admin, platform_admin, centre_director
screens: payroll?tab=yearend
---
# Payroll year end: T4, T4A and RL-1 slips

When the payroll engine is on, **Human Resources › Payroll › Year end** makes each person's tax slips for the year, checks them against your payslips and remittances, and gives you the file to upload to the CRA. Slips are due on the **last day of February** (February 28, or 29 in a leap year). From November to March, the HR dashboard shows a tile counting down to the deadline.

KiddieTrac payroll is Canadian: it makes CRA and Revenu Québec slips only.

## Where the amounts come from
- **Finalized pay runs** in KiddieTrac, by pay date (a December 2026 pay period paid on January 8, 2027 belongs on the 2027 slips).
- **Opening balances** (Payroll › Opening balances): what a previous payroll paid someone earlier in the year.
- **Payslips from another payroll that show deductions** (CPP, EI or tax). Payslips with gross pay only never reach a slip.
- **Contractors** (people marked as contractors) get a **T4A** with box 048 (fees for services) when they were paid more than $500 in the year, or tax was withheld.

Nothing is counted twice: a payslip that is inside an opening balance is left out.

## Steps (agency admins)
1. Open **Year-end settings** and choose the **dental benefits code** (box 45) your agency offers, and the filing contact (name, phone, email). Your CRA payroll account number comes from HR settings › Payroll engine.
2. Press **Generate slips**. You can generate them again as often as you like until they are finalized.
3. Use **⋮ › Edit typed boxes** on a slip for amounts KiddieTrac has no record of: union dues (44), charitable donations (46), RPP contributions (20) and number (50), pension adjustment (52), a different dental code (45).
4. Read the **Reconciliation**. Every line has to match within one cent: the slips against the payslips, against the remittance (PD7A) report, and against the opening balances and other-payroll payslips.
5. From **January 1**, when everything reconciles and no pay run with a pay date in the year is still open, press **Finalize slips**. Each person then sees their slips in **My HR › My tax slips**.
6. Press **Summaries & CRA file**: download the **T4 Summary** and the **CRA file (XML)**, and upload the file in CRA **Internet File Transfer** (or enter the slips in My Business Account). KiddieTrac checks the file against the CRA's own schema before it lets you download it. KiddieTrac never files for you.

## Quebec employees (RL-1)
Quebec employees get a T4 (box 22 = federal tax only) and an **RL-1 information sheet** with the RL-1 boxes (A, B.A, B.B, C, E, G, H, I) and an RL-1 Summary sheet. Revenu Québec certifies the software that makes RL-1 slips and files, and KiddieTrac is not certified, so file the RL-1s in Revenu Québec's My Account for businesses or with certified software, using these sheets.

## Corrections after finalizing
A finalized slip never changes.
- **⋮ › Amend this slip**: KiddieTrac works the slip out again from the payroll as it is now (keeping the typed boxes) and marks it **Amended**.
- **⋮ › Cancel this slip**: for a slip issued to the wrong person or one that was not needed.
Then press **Finalize slips** again (the reconciliation has to pass), and download **CRA file: T4 amendments**. The person sees the new version in My HR, marked Amended.

## Who sees what
- Agency admins: everything, and the full SIN on a slip's PDF and in the CRA file.
- Centre directors (with payroll): the slips of people at their own centres, read-only, with the SIN masked.
- Staff: their own finalized slips, in My HR, with their own SIN.
- Nobody can edit, amend or finalize their own slip.

Every generate, finalize, edit, amendment and download is in the audit log. Check the slips with your accountant before you file.
