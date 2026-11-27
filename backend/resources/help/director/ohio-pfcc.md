---
title: Ohio publicly funded child care (PFCC)
category: Billing
order: 60
roles: agency_admin, centre_director, platform_admin
screens: pfcc
---
# Ohio publicly funded child care (PFCC)

For Ohio agencies. Keep each child's PFCC authorization, the family's weekly copay and absent days in one place, and check each week's attendance before you submit it in TAP. Open it from the sidebar: **Operations** → **Ohio PFCC**. It shows for agencies in Ohio, or where an agency admin has switched it on (**Switch it on anyway**, or **Limits & alerts**).

[[open: #pfcc | Open Ohio PFCC]]

The screen has five tabs: **Authorizations**, **Copays**, **TAP reconciliation**, **Invoice split** and **Limits & alerts**. Weeks run Sunday to Saturday.

## Adding an authorization

1. Open the **Authorizations** tab and press **Add authorization**. The **Add a PFCC authorization** window opens.
2. Choose the **Child**.
3. Enter the **Case number**.
4. Choose the **Care unit**: hourly (under 10 hours a week), part-time (10–33) or full-time (33+), and enter the **Units per week**.
5. Enter the **Provider rate per unit ($)** shown on the child's authorization, and the **Weekly family copay ($)**.
6. Set **Starts**, and **Ends (optional)** if there is an end date. Add the date the **Copay agreement signed** and any **Notes**.
7. Press **Add**. An **Authorization added** message appears.
8. On the row, press **Upload** under **Agreement** to attach the signed copay agreement. **View** opens it again later, and **Replace** swaps it.

KiddieTrac does not hold Ohio's rate table. Use **Edit** to change an authorization. When it ends, press **End**, give the last day of care and press **End it**.

## Recording copays

1. Open the **Copays** tab. The **Weekly copay ledger** lists every week of the chosen authorization.
2. Pick the child in the list at the top.
3. On a week, press **Record**.
4. Enter the **Amount paid ($)** and **Paid on** date. Leave **Status** on **Work it out**, or choose **Waived** or **Not paid**, and add a **Note**.
5. Press **Save**.

Copays are kept separately from invoices, and **families are never messaged about them**. When a family has missed the number of weeks set under **Limits & alerts** (2 by default), the centre's directors and the agency admins get a bell notice. Ohio expects non-payment past 2 weeks to be reported to the county within 3 weeks.

## Absent days

Each authorization shows the child's absent days for the current half-year (January–June or July–December) against your limit. Directors and admins get a bell notice when a child is 2 days from the limit, and again if the child goes over it. The limit starts at **10**. Ohio sources give 10 or 20 days for different cases, so **check it against your authorizations** and change it under **Limits & alerts**.

## Checking a week for TAP

1. Open the **TAP reconciliation** tab.
2. Choose the week with **‹ Week** and **Week ›** (and a centre, if you have several).
3. Check each child flagged for care with **no authorization**, a check-in **without a matching check-out** (or the other way round), **hours over** what the care unit allows, or an authorized child with **no attendance** that week.
4. Match the attendance in TAP by hand. **Download CSV** gives you the same list as a spreadsheet.

Ohio's TAP system has no connection KiddieTrac can use. A week more than 3 weeks old carries a warning: TAP sweeps unsubmitted matched attendance after 4 weeks. When everything matches you see **Nothing flagged this week.**

## Splitting an invoice between the state and the family

1. Open the **Invoice split** tab and find a **draft** invoice.
2. Press **Preview**. KiddieTrac works out the state's share for the invoice period: authorized units × rate − weekly copay, pro-rated by week. It shows what the **Family owes after the split**.
3. If it looks right, press **Apply to this draft**. A **Split applied** message appears and the state's share goes on the invoice as a subsidy.
4. To take it off before the invoice is sent, press **View**, then **Undo the split**.

Invoices that have been sent are never touched, an invoice that already carries another subsidy is not split, and children without an authorization are not affected. What the family pays above the copay follows **After a split, the family pays** under Limits & alerts: **Only the copay (the gap is written off)**, or **The copay plus tuition above what the state pays**.

## Limits & alerts

An agency admin sets the **Absent days allowed per half-year**, **Alert after unpaid copay weeks**, **After a split, the family pays** and the **Step Up To Quality rating**, then presses **Save settings**. Outside Ohio, two more ticks switch on **Show publicly funded child care for this agency (not in Ohio)** and **Show Step Up To Quality for this agency**.

## Who can do what

- **Agency admins** see every centre and change Limits & alerts.
- **Centre directors** see and manage their own centres' authorizations, copays, reconciliation and invoice splits. Limits & alerts is read-only for them: "Only agency admins can change these."
- Educators and parents do not see Ohio PFCC. Families are never messaged about copays.

## FAQs and troubleshooting

??? I can't see Ohio PFCC in the menu. | It shows only for Ohio agencies, or when an agency admin switches it on. Check the agency's province or state.
??? It says "Ohio publicly funded child care is not switched on for this agency." | An agency admin can press Switch it on anyway.
??? Add says "Choose a child." | Pick the child before pressing Add. Only enrolled children are listed.
??? Preview shows no Apply button. | The invoice isn't a draft, it already has another subsidy, or no child on it has an authorization.
??? Do bell notices ever go by email or text? | No. Absent-day and unpaid-copay alerts are bell notices for directors and admins only.

## Related

[[open: #pfcc | Ohio PFCC]]
[[open: #sutq | Step Up To Quality]]

- [Step Up To Quality](step-up-to-quality)
- [Subsidies](subsidies)
- [Generating monthly invoices](generating-monthly-invoices)
