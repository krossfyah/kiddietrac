---
title: KiddieTrac sales reports
category: Reporting
order: 93
roles: platform_admin
screens: platform-sales
---

# KiddieTrac sales reports

**Platform › 💹 Sales reports** shows what KiddieTrac itself has sold and billed: revenue by part number, plan, add-on, service and customer. These are platform figures only. No agency ever sees them, and they have nothing to do with the fees agencies charge families. Use it for month-end and year-end reviews, to see which products sell, and to check how much billed money is still unpaid.

[[open: #platform-sales | Open Sales reports]]

## Choosing the period

1. Open **Platform › 💹 Sales reports**. It starts on **This year**.
2. Press a period button: **This year**, **This quarter**, **This month**, **Last 12 months** or **All time**.
3. Or set your own dates in **From** and **to**. The period buttons switch off and the report reloads for your dates.

## Reading the tiles

| Tile | What it counts |
|---|---|
| **Revenue invoiced** | Platform invoices in the period, before tax, with the number of invoices |
| **Collected** | Payments received, and the share of what was billed including tax |
| **Outstanding** | Billed but not yet paid |
| **Recurring (MRR)** | Monthly recurring revenue as of today, with the yearly figure (ARR) |
| **Booked on proposals** | Signed proposals in the period, invoiced or not |
| **Paying customers** | Agencies invoiced in the period |
| **Tax collected** | Tax on the invoices in the period |

Void invoices are never counted.

## Reading the charts and tables

1. **📈 Invoiced vs collected — last 12 months**: compare each month's billing with what came in. A gap means money still owed.
2. **🧩 Revenue by kind**: the split between **Plans**, **Add-ons**, **Services**, **Discounts** and **Other**.
3. **🏆 Top customers**: the eight agencies billed the most in the period.
4. **🔢 Invoiced by part number**: every invoiced item with **Part #**, **Item**, **Kind**, **Qty**, **Invoiced** and **Share**, and a **Total** row.
5. **📝 Sold on proposals by part number**: what was sold on signed or approved proposals, including one-time services that are not invoiced yet.

> 💡 Older invoices made before invoices had itemised lines count under their plan, so their part number may show as the plan's.

## Using the numbers

1. To check a single customer's invoices, open **Platform › 🏦 Accounting AR/AP** and type the agency's name in the table filter.
2. To change what a product costs or which part number it carries, use **Platform › 🔑 Licences** and the plans catalogue; this report only reads what was billed.
3. To see the proposals behind **Booked on proposals**, open **Sales › Proposals**.

## Who can do what

- **Platform super-admins**: see the whole report.
- **Sales reps**: do not see this screen. They see their own proposals in **Sales › Proposals**.
- **Agency admins and every other role**: cannot see it; anyone else who opens it sees **KiddieTrac sales reports are for superadmins.**

## FAQs and when something goes wrong

??? "Could not load the report" appeared. | The connection dropped or your session ended. Refresh the page and pick the period again.
??? The tables say "Nothing in this range." | No invoices or signed proposals fall between your **From** and **to** dates. Try **All time**.
??? Why is Booked on proposals higher than Revenue invoiced? | Proposals count when they are signed; invoices follow over the contract's billing periods. One-time services may also not be invoiced yet.
??? Why does Collected show a share above what I expected? | The share compares payments with what was billed including tax, while **Revenue invoiced** is before tax.
??? Do discounts lower the totals? | Yes. They show as their own kind in **🧩 Revenue by kind** and reduce what was invoiced.

## Related screens

[[open: #sales-invoices | Accounting AR/AP]] · [[open: #licences | Licences]] · [[open: #admin-mrr | MRR dashboard]] · [[open: #sales-quotes | Proposals]]

See also: [[platform-invoices]] · [[platform-licences]]
