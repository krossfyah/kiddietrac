---
roles: agency_admin, platform_admin
title: CACFP meal tracking
category: Compliance
order: 70
---
# CACFP meal tracking

Track meals served per child per day for state / provincial reimbursement claims under the **Canadian** Child and Adult Care Food Program equivalent.

## Daily roster (educator)

1. Sidebar → **CACFP meals**.
2. See today's roster — every enrolled child + 5 meal-type columns (breakfast / morning snack / lunch / afternoon snack / dinner).
3. Tap each checkbox to record a meal as served.
4. Changes save instantly.

## Family eligibility tier

Each family has a `cacfp_tier` of `free` / `reduced` / `paid` (set by the admin based on family income). The system tags every meal with the tier so the monthly report breaks down meal counts by tier — that's the data your state agency wants for the reimbursement check.

## Setting tier (admin)

Edit a family record → set `cacfp_tier` + `cacfp_eligibility_date`. Eligibility certifications need re-running annually.

## Monthly report

Sidebar → CACFP meals → top of page shows:
- Free meals served this month: N
- Reduced meals served this month: N
- Paid meals served this month: N
- Grand total: N

Export as PDF or XLSX for filing with your state / provincial CACFP office.

## Best practice

- Mark meals at the time of service (not at end-of-day) — easier to be accurate.
- Re-certify tier annually with each family.
- Cross-check kitchen meal counts vs. roster counts monthly.
