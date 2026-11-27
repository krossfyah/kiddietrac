---
title: Payroll-ready CSV export
category: Staff Management
order: 32
screens: ~payroll
---
# Payroll-ready CSV export

Download a spreadsheet of every staff member's clocked hours for a pay period, ready to import into ADP, Wave or whichever payroll provider your agency uses. The file comes from the **Payroll** screen in **Human Resources**.

[[open: #payroll | Open Payroll]]

## Downloading the hours file

1. Open **Human Resources > Payroll**. The **⏱️ Hours & payslips** view opens on the last 30 days.
2. Press a quick period such as **Last pay period** or **Last month**, or type the **From** and **To** dates.
3. Press **Run** to check the hours on screen first.
4. Press **⤓ CSV**. The file downloads to your computer.

The file is named `payroll-YYYY-MM-DD-to-YYYY-MM-DD.csv`. It is saved as UTF-8 with a byte-order mark, so accents and names open cleanly in Excel, Numbers and Google Sheets.

## What the file contains

One row for each staff member at each centre they clocked in at, with:

- staff name and email;
- centre name;
- punch count (how many times they clocked in);
- total minutes;
- total hours, rounded to two decimals.

Only closed punches count. Anyone still clocked in is left out until they clock out.

> ℹ️ It is an hours file. People paid without clocking in (salaried staff, contractors) have no punches, so they are on the screen with their payslips but not in the CSV.

## Correcting the hours first

The export reads the same punches as **Clock-ins & timesheets**. If a shift is wrong (a clock-out nobody pressed, a shift that was never clocked), fix it there before you export, not in the spreadsheet afterwards.

1. Open **Human Resources > Clock-ins & timesheets**.
2. Correct the shift with its **⋮** menu.
3. Come back to **Payroll** and press **⤓ CSV** again.

## Who can use it

Agency admins and centre directors. Directors get the staff of their own centres.

## FAQs

??? The file is empty. | Nobody clocked in between the dates. Widen the dates and press **Run** to check.
??? A person is missing from the file. | They have no closed punch in the period, or they are still clocked in.

## Related

- [Payroll runs, hours and payslips](payroll-documents)
- [Timesheets and correcting hours](timesheets)
