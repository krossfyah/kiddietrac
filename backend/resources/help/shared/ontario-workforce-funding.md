---
title: Ontario workforce funding (WEG and CWELCC)
category: Payroll
order: 40
roles: agency_admin, platform_admin
screens: workforce-funding
---
# Ontario workforce funding (WEG and CWELCC)

**Finance → Workforce funding** is for Ontario agencies that receive the Wage Enhancement Grant (WEG) and CWELCC workforce compensation (WCF). KiddieTrac works out each person's hourly amounts, puts them on every payslip as separate lines, checks the 32-day rule for each payment you receive and builds the year-end reconciliation. It keeps records only: it never pays anyone.

[[open: #workforce-funding | Open Workforce funding]]

The screen has four tabs: **Staff wages**, **Pay period**, **Funding received** and **Year-end report**.

## Switching it on

1. Open **Settings → Payroll** and find the **🍁 Ontario workforce funding (WEG + CWELCC)** card.
2. Tick **Use Ontario workforce funding in payroll**.
3. Under **Values for**, check the year. The 2026 values come filled in from the 2026 guidelines: a wage floor of $25.86 an hour for RECE program staff and $26.86 for RECE supervisors and home child care visitors; a WCF increase of up to $4.00 an hour until the wage reaches $28 (program staff) or $31 (supervisors); WEG of up to $2.00 an hour until the wage reaches $33.81.
4. When your service manager publishes next year's values, pick that year and type them in.
5. Write your **Distribution policy**, with **Approved by (name / title)** and **Approved on**, or press **Add to the Policy library** to upload the signed copy and track its yearly review.
6. Press **Save funding settings**.

## Entering a person's wage

1. Open **Finance → Workforce funding** and stay on **Staff wages**.
2. Press **Wages** on the person's row. The **Wage components** window opens.
3. Set **Effective from**, then type the **Base wage ($/h)** and any **2024 GOF kept ($/h)** you still pay.
4. Leave **Funding position** on **Automatic (role + RECE registration)**, or choose one to override it.
5. Leave **WEG override ($/h)**, **WCF increase override** and **Floor top-up override** blank to use the calculation, or type an amount. Going over a ceiling or maximum shows a warning.
6. Press **Save**. A **Wage record saved** toast appears and the row shows **Base**, **WEG**, **WCF increase**, **Floor top-up** and **Total /h**.

KiddieTrac works the amounts out in the guideline's order: WEG, then the WCF increase, then the wage-floor top-up. Eligibility comes from the person's role and an active **RECE** certification on their staff record. A director without an RECE, or an educator approved by the director, counts as non-RECE: they can get WEG but not WCF.

When someone's pay changes, add a **new record** from the date it takes effect; the old one ends the day before. To fix a mistake, press **Correct** on the record instead. Every change is in the audit log with the old and new values.

## Paying it in a payroll run

1. Run payroll as usual and press **Generate payroll**.
2. Each funded person shows their WEG, WCF and floor rates. Ticking someone adds a separate line for each part (hours × rate) to their pay.
3. On the **Pay period** tab, pick the dates and press **Show** to see hours from the clock × each rate, and whether that period's payslips carry the funding lines. **⬇ Lines (CSV)** downloads them.

The payslip PDF shows the base wage, WEG and workforce compensation on separate lines. Funding is paid for every hour worked, including overtime, at the same hourly amount.

## Recording funding you receive

1. Open the **Funding received** tab.
2. Press **+ Record funding received**.
3. Enter **Received on** and the **Amount ($)**, and under **Funding** choose **WEG + workforce compensation**, **WEG only** or **Workforce compensation only**.
4. Add **Period covered from** / **to**, the **Centre (optional)** (or **Whole agency**), a **Reference** and **Notes**.
5. Press **Save**. A **Funding receipt recorded** toast appears.

From the received date you have 32 days to pay it out on regular pay. The **32-day check** column shows **Met** (with the first funded pay date), **Pending** (with the days left) or **MISSED**. A wrong entry is voided with **Void**, with a reason: it stays on record, marked void.

## Building the year-end report

1. Open the **Year-end report** tab and pick the year.
2. Read the tiles (**Funding received**, **WEG paid**, **WCF paid**, **Benefit allocation 17.5%**, and **Surplus to return** or **Shortfall**) and the tables by person and by position, with non-RECE director-approved staff separately.
3. Press **⬇ CSV** or **⬇ PDF** to download it. Each download is in the audit log.

Keep these records for seven years. KiddieTrac never deletes them.

## Who can do what

Only **agency admins** (and platform admins working in the agency) see this screen, and only in an Ontario agency. Centre directors, educators and parents do not. Staff see the funding lines on their own payslips.

## FAQs and when something goes wrong

??? Workforce funding is not in my menu. | It shows only for agency admins of an Ontario agency. Check the agency's province in its settings.
??? The screen says funding lines are off for payroll runs. | Open **Settings → Payroll**, tick **Use Ontario workforce funding in payroll** and press **Save funding settings**.
??? Someone gets WEG but no WCF. | They count as non-RECE: check for an active **RECE** certification on their staff record, or set **Funding position** by hand.
??? "The date and the amount are needed." | Fill in **Received on** and **Amount ($)**, then save again.
??? A receipt shows MISSED. | No funded pay went out within 32 days of the received date. Pay it on the next regular payroll and keep a note for your service manager.
??? Some payslips are not counted in the year-end report. | Payslips without a WEG/WCF breakdown (for example synced from another payroll system) are left out; the report says how many.
??? Does this send money or email anyone? | No. It keeps the records and puts lines on payslips. No email or text is sent from this screen.

## Related screens

[[open: #payroll-settings | Payroll settings]] [[open: #grants | Grants]]

See also: [Grants](grants) · [Payroll runs, hours and payslips](payroll-documents)
