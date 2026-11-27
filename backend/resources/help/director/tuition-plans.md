---
title: Tuition & fee plans
category: Billing
order: 20
roles: agency_admin, centre_director, platform_admin
screens: tuition-plans
---
# Tuition & fee plans

A fee plan is a named amount and how often it bills, for example "Toddler full-time, $1,250 monthly". You set plans up once, then use them to create invoices for the families on that plan. Directors find it under **Finance → Tuition plans**; agency admins also have it in **Settings → Billing** on the **💵 Tuition plans** tab.

[[open: #tuition-plans | Open Tuition plans]]

## Creating a plan

1. Open **Finance → Tuition plans** (or **Settings → Billing → 💵 Tuition plans**).
2. Press **+ New plan**. The **New fee plan** window opens.
3. Type the **Plan name**, for example "Toddler full-time".
4. Enter the **Amount ($)**.
5. Choose the **Billing frequency**: **Weekly**, **Bi-weekly**, **Monthly** or **One-time**.
6. If the plan is for one centre, pick it under **Centre (optional)**. Add an **Age group (optional)** and a **Description (optional)** if they help.
7. Leave **Active (available to apply to families)** ticked.
8. Press **Create plan**. A **Plan saved** toast appears and the plan joins the list with its amount and frequency.

## Changing or retiring a plan

1. Click the plan's row. The **Edit plan** window opens.
2. Change what you need and press **Save changes**.
3. To stop using a plan but keep it on record, untick **Active (available to apply to families)** and save. It shows **INACTIVE** in the list and loses its **💸 Generate invoices** button.
4. To remove a plan created by mistake, press **Delete** and confirm. A **Plan deleted** toast appears.

> ℹ️ Changing a plan's amount does not change invoices already created. New invoices use the new amount.

## Creating invoices from a plan

[[show: #tuition-plans @ Generate invoices | Show me Generate invoices]]

1. On an active plan, press **💸 Generate invoices**.
2. Under **Create as**, choose **Draft (review first)** to check the invoices before families see them, or **Sent (issue now)**.
3. Set **Due in (days)**. It starts at 14.
4. Under **Families**, tick the families to bill, or press **Select all**. A plan set to one centre lists only that centre's families.
5. Press **Generate invoices**. A toast says how many were created, for example "Generated 12 invoices (draft)."
6. Open **Finance → Accounting** to find the new invoices, review the drafts and send them.

Each invoice has one tuition line with the plan's name, frequency and amount, dated today, numbered your agency's usual way, in your agency's currency (from **Country & compliance**).

> ⚠️ **Generate invoices** creates a new invoice each time you press it. Pressing it twice for the same families bills them twice: void the extra ones from the invoice list.

## Who can do what

| Role | What they can do |
|---|---|
| Agency admin | Creates, edits and deletes every plan, agency-wide or for one centre, and generates invoices from them. |
| Centre director | Sees their own centres' plans and the agency-wide ones. Creates and changes plans for their own centres and generates invoices for their families. Agency-wide plans stay the agency admin's. |
| Platform admin | The same as an agency admin, in the agency they are working in. |

Educators and parents do not see this screen. Parents only see the invoices.

## FAQs and when something goes wrong

??? There is no Generate invoices button on a plan. | The plan is inactive. Open it, tick **Active (available to apply to families)** and save.
??? The list says "No plans yet. Click + New plan to create your first fee plan." | No plan exists for your centres yet. Press **+ New plan**.
??? "Choose one of your centres." | As a centre director you can only save plans for your own centres. Pick one under **Centre (optional)**; agency-wide plans are set by the agency admin.
??? "Plan name is required" | Type a **Plan name** before saving.
??? "Select at least one family" | Tick at least one family under **Families**, then press **Generate invoices** again.
??? A family I expected is not in the list. | A plan for one centre lists only that centre's families. Families that were removed are not listed.
??? Do families get an email when I generate invoices? | This button sends no email or text. Create them as drafts, check them on **Finance → Accounting**, then send them the usual way.

## Related screens

[[open: #external-billing | Accounting]] [[open: #billing-schedule | Billing schedule]] [[open: #tuition-increases | Tuition increases]]

See also: [Generating monthly invoices](generating-monthly-invoices) · [Invoice statuses](invoice-statuses) · [Tuition increase scheduling](tuition-increases)
