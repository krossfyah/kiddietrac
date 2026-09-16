---
title: Payroll-ready CSV export
category: Staff Management
order: 32
---
# Payroll export

Pull a pay-period CSV of every staff member's clocked hours. Drop straight into ADP, Wave, or your payroll provider.

## How to run

1. Sidebar → **Payroll**.
2. Pick a date range (defaults to the current calendar month).
3. Click **Run** to see a per-staff summary, or **⤓ CSV** to download the file.

## What's included

For each (staff × centre) combination within the range:
- Staff name + email
- Centre name
- Punch count (how many clock-in events)
- Total minutes
- Total hours (rounded to two decimals)

Only closed punches are counted — anyone still clocked in won't show until they punch out.

## File format

`payroll-YYYY-MM-DD-to-YYYY-MM-DD.csv`, UTF-8 with BOM, opens cleanly in Excel / Numbers / Sheets.

## Correcting the hours first

The export reads the same punches as **Timesheets**. If a shift is wrong — a clock-out
nobody pressed, a shift that was never clocked — fix it there before you export, not in
the spreadsheet afterwards. See [Timesheets and correcting hours](timesheets).
