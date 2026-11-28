---
title: Billing & commissions (reseller)
category: Administration
order: 8
roles: platform_admin, reseller_admin
screens: reseller-billing
---
# Billing & commissions (reseller)

**Billing & commissions** shows, one month at a time, what KiddieTrac billed each of your agencies for its plan, what was collected, what is still owed, and the commission you earned on it. Use it at month end to check your earnings and to send your accountant a statement.

[[open: #reseller-billing | Open Billing & commissions]]

## Checking a month

1. Open **Billing & commissions** from the **Reseller** section of the menu.
2. Pick the month in the month box at the top right. The screen opens on the current month.
3. Read the tiles: **Billed this month**, **Collected this month**, **Still owed (all months)**, **Your commission** and **Agencies that paid** (for example "3 / 5").
4. Read the line under the tiles. It states your terms, for example "Your commission: 10% of the money collected before tax. Set by the platform."
5. Read the table, one row per agency: **Agency**, **Plan**, **Billed**, **Collected**, **Before tax**, **Still owed** and **Commission**.
6. Scroll to **Invoices behind these numbers** to see each plan invoice that counts: its number, period, status, amount and whether it was paid that month, billed that month, or is owed from before.

## How your commission is worked out

- A **percentage** of the money collected that month, **before tax** (the **Before tax** column, not **Collected**).
- Plus, if your terms include one, a **flat amount for each agency that paid** that month.
- Money billed but not yet collected earns nothing until it is paid; it shows in **Still owed** instead.

> ℹ️ Your terms are set by KiddieTrac. You can read them here but not change them.

## Downloading the statement

1. Pick the month.
2. Press **Download statement (CSV)**.
3. Open the file (named like `statement-2026-11.csv`) in a spreadsheet. It has the same rows and totals as the screen.

## Who can do what

| Role | What they can do |
|---|---|
| Reseller | Sees and downloads the statement for their own reseller. |
| Platform admin | Picks any reseller in **Viewing reseller**, and sets commission terms under **Resellers › Commission** (the **Monthly statement →** button there opens this screen for that reseller). |
| Agency admins under a reseller | Do not see the reseller's statement or commission. |

## FAQs and when something goes wrong

??? It says "No commission terms are set for this reseller yet." | KiddieTrac has not recorded your terms. Raise a ticket ([[raise-a-ticket]]) and they will add them; earlier months are then worked out with the new terms.
??? My commission is lower than I expected. | It is worked out on the money collected **before tax** that month, not on what was billed. Check **Still owed** for agencies that have not paid yet.
??? An agency shows $0 billed. | No plan invoice was raised for it in that month (for example, it is still on its trial). Check **Invoices behind these numbers** for what was counted.
??? "Could not download the statement" appears. | Your session may have ended. Refresh the page, sign in again if asked, and press **Download statement (CSV)** again.
??? The table says "No agencies yet." | No agency belongs to your reseller yet. Onboard one on **Onboard an agency**.
??? Is anyone emailed when I open this screen? | No. This screen only reads figures; nothing is sent to your agencies.

## Related screens

[[open: #reseller | All my agencies]] [[open: #reseller-support | Support desk]]

- [[reseller-all-my-agencies]]
- [[managing-resellers]]
- [[reseller-guide]]
