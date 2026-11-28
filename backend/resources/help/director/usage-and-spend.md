---
title: Usage and spend (texts, calls and AI per agency)
category: Administration
order: 65
roles: agency_admin, platform_admin
screens: usage-spend
---
# Usage and spend (texts, calls and AI per agency)

**Platform › Usage & spend** shows what each agency's texts, phone calls and AI cost KiddieTrac, flags agencies over a weekly spending threshold, and runs the monthly **text & call allowance** (the SMS Incremental add-on), where you decide whether an agency that went over pays for the extra. Agencies never see this screen.

[[open: #usage-spend | Open Usage & spend]]

## Billing an agency that went over its text and call allowance

The top card, **📶 Text & call allowance**, counts every text and call that went through, in and out, per calendar month. Each extra block the size of the allowance costs the price shown (for example 1,000 allowed and 2,400 used = 2 blocks). **Nothing is charged until you press Add to next invoice.**

1. Use **‹** and **›** to pick the month.
2. Read the banner: either the agencies over the allowance with their amount, or **✅ No agency is waiting on an overage decision**.
3. In the table, look at each agency's **Texts** and **Calls** bars (amber at 80 %, red with **… blocks over**) and its **Billing** column (**Next invoice …** or **Not billed yet**).
4. In the **Charge** column, press **Add $… to next invoice**, read the confirmation, and press **Add to next invoice**. The row shows **✓ $… on next invoice**.
5. Or press **Waive** to let it go for that month. The row shows **Waived**.
6. Made a mistake? Press **Undo** beside the charge or the waiver. A charge can be undone until its invoice is raised; after that it shows **invoiced**.

> ⚠️ If **Billing** says **Not billed yet**, billing is not on for that agency, so an approved line waits until it is.

## Changing the allowance

1. Type new numbers in **Default texts / month** and **Default calls / month** (these apply to every agency).
2. To give one agency its own allowance, type its numbers in **Own allowance (texts · calls)**. Leave them blank to use the default.
3. Press **Save allowances**. An **Allowances saved** toast appears.

## Checking weekly spend and thresholds

1. Under the allowance card, pick **Last 7 days** or **Last 30 days**.
2. Read the banner. On the 7-day view it lists the agencies **over a threshold this week**, or says **✅ No agency is over a threshold for the last 7 days**. Thresholds are weekly, so the 30-day view shows no flags.
3. In the table, read each agency's **Texts**, **Calls**, **AI** and **Total** cost. A cost over its threshold is red.
4. Under **💲 Rates**, set what one unit costs you: **Text, per segment**, **Call, per minute**, and the two AI rates (they only price an AI model that is not on the built-in price list; listed models use their own prices).
5. Under **🚨 Default weekly thresholds**, set the dollar limit per week for texts, phone calls and AI (0 = never flag).
6. To give one agency its own limits, fill its boxes under **Own weekly thresholds ($)**. Blank uses the default; 0 never flags that agency.
7. Press **Save rates & thresholds**. A **Rates and thresholds saved** toast appears.

## The weekly summary email

Every Monday at 7:15, platform admins are emailed the **Platform weekly summary**, with any agency over its weekly threshold at the top. Press **✉️ Email me this week's summary** to get a copy now at your own address.

## Who can do what

| Role | Access |
|---|---|
| Platform admin | Everything here: rates, thresholds, allowances, charges and waivers. |
| Agency admins and everyone else | No access. An agency sees an approved overage only as a line on its next KiddieTrac invoice. |

## FAQs and when something goes wrong

??? "Your account has no email address." | The test summary goes to your own email. Add one to your profile first.
??? An agency is red but I do not want to bill it. | Press Waive for that month. Nothing is charged unless you press Add to next invoice.
??? I cannot press Undo. | The charge is already on an invoice ("invoiced"). Correct it from Accounting AR/AP instead.
??? "That did not go through". | The charge, waiver or undo failed. Refresh and try again.
??? Are agencies told when they go over? | No. This screen and the weekly summary are for platform admins only.

## Related screens

[[open: #ai-usage | AI usage]] [[open: #sales-invoices | Accounting AR/AP]] [[open: #sales-plans | Plans & pricing]] [[open: #admin-mrr | MRR dashboard]]
