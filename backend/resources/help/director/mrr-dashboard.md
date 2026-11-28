---
title: MRR dashboard
category: Administration
order: 63
roles: agency_admin, platform_admin
screens: admin-mrr
---
# MRR dashboard

**Platform › MRR dashboard** shows KiddieTrac's own recurring revenue: what agencies pay each month, what complimentary and trial licences are worth, what has been invoiced and collected, who owes money and which licences renew soon. Open it for a monthly revenue check, before a renewal call, or when chasing unpaid invoices. The screen only reads figures; nothing on it changes a licence or an invoice.

[[open: #admin-mrr | Open MRR dashboard]]

## Checking this month's revenue

1. Open **Platform › 💰 MRR dashboard**. The banner says when the figures were worked out (**as of …**).
2. Read the four coloured tiles at the top:
   - **MRR**: monthly recurring revenue from paid licences, with the net change this month (▲ or ▼), or *No change this month*.
   - **ARR**: MRR × 12.
   - **Paying agencies**: how many agencies pay, out of all your customers, plus how many are complimentary and on trial.
   - **ARPA**: average revenue per paying agency per month.
3. Read the second row: **Complimentary value** (what free licences would cost at list price, per month and per year), **Trial pipeline** (agencies on trial at list price), **Collected this month** (with the amount invoiced) and **Outstanding** (turns red when anything is overdue, with the overdue amount and the number of invoices).
4. Under **This month**, check the **MRR movement**: **New**, **Churned** and **Net**.

> ℹ️ No paying customers yet? A note explains how many agencies are on complimentary licences and what they are worth at list price. That value is never charged.

## Following the trend and the plan mix

1. Look at **MRR, last 12 months**. The solid line is paid MRR; the dashed line is complimentary value at list price.
2. Look at **Plan mix** for the share of MRR by plan (or of complimentary value, when no one pays yet). Each plan shows how many agencies are on it.
3. Look at **Invoiced vs collected** for KiddieTrac invoices issued and payments received each month. Refunds come off the collected bar.

## Chasing money owed and renewals

1. Check **Receivables**: what agencies owe, split into **Current**, **1–30 days**, **31–60**, **61–90** and **90+**. Hover a colour to see its amount.
2. Check **Agencies by value**: each agency's plan, type, **Boarded** date and **Per month** value, paid MRR first, then complimentary value.
3. Check **Renewals, next 90 days**: when each licence term ends. **✓** under **Auto** means it renews by itself; **Ends** means it stops unless someone renews it.
4. Check **By part number** for live lines, paid lines, **MRR** and **Comp / mo** per product.
5. To act on an agency (renew, change, invoice), go to **Licences** or **Accounting AR/AP**.

[[show: #admin-mrr @ Renewals, next 90 days | Show me]]

## Who can do what

| Role | What they see |
|---|---|
| Platform admin | The whole dashboard, for every agency. |
| Agency admin, centre director, anyone else | Nothing: the screen is not in their menu and the server refuses the figures. Agencies see their own bill on their own Billing screen. |

## FAQs and when something goes wrong

??? A tile shows $0. | Nothing of that kind exists yet, for example no paid licences or no invoices this month. The tables say so too ("No agencies on a licence yet.", "Nothing owing.").
??? "No licence renews in the next 90 days." | No licence term ends in the next 90 days. Check Licences for later dates.
??? The numbers do not match an invoice. | MRR normalises each paid licence to a month (a yearly licence counts as a twelfth). Invoiced and collected are the real invoices and payments, refunds removed.
??? A red message appears instead of the page. | The figures could not be loaded. Refresh; only platform admins can open this screen.
??? Does the dashboard email anyone? | No. It only reads. Renewal notices and invoices are sent from Licences and Accounting AR/AP.

## Related screens

[[open: #licences | Licences]] [[open: #sales-invoices | Accounting AR/AP]] [[open: #platform-sales | Sales reports]] [[open: #usage-spend | Usage & spend]] [[open: #admin-features | Feature flags]]
