---
title: Cohort retention
category: Reporting
order: 46
roles: agency_admin, centre_director, platform_admin
screens: retention
---
# Cohort retention

**Compliance › Retention** shows how many of the children who started with you in each of the last 12 months are
still enrolled today. It is about keeping families, not about how long records are kept (that is
[Data retention & compliance](data-retention-compliance)). Use it to spot a month when more families than usual left
soon after starting, and ask why.

[[open: #retention | Open Cohort retention]]

## Reading the report

1. Open **Compliance › Retention**. The banner reads **📊 Cohort retention** and gives your **Average retention** across the months.
2. Look at the **Cohort breakdown** table. Each row is one start month (for example 2026-09).
3. **Enrolled** is how many children started that month. **Still** is how many of them have not withdrawn.
4. **Retention** is Still ÷ Enrolled, with a bar: green at 80% or more, amber from 50%, red under 50%. A dash (—) means nobody started that month.
5. Compare the newest months with the older ones: recent cohorts have had less time to leave, so they usually look better.

> 💡 A red month with only one or two children is not a trend. Look at months with enough starts to matter.

## Acting on a weak month

1. Note the start month that is amber or red.
2. Open **Children** or **Families** and look at who started that month and has since withdrawn, and the reason recorded.
3. If the same reason keeps coming up (fees, hours, a room change), raise it with your team. A short survey through **Engagement › NPS** can tell you more.

## How the numbers are counted

- A child counts in the month of their enrolment date.
- A child counts as "still enrolled" until a withdrawal date is set on their record.
- Children whose record has been deleted are left out.
- The report covers the 12 most recent months, this month included, and is worked out fresh each time you open it.

## Who can do what

| Role | Sees |
|---|---|
| Agency admin | The whole agency. |
| Centre director | The agency's figures (the report is not split by centre). |
| Platform admin | The agency chosen in the agency switcher. |

The report shows counts only: no child or family names.

## FAQs and when something goes wrong

??? Every month shows a dash. | No child in the agency has an enrolment date in the last 12 months. Check that enrolment dates are filled in on children's records.
??? A family left but the report still counts their child. | The child's withdrawal date has not been set. Withdraw the child on their record and the report updates next time you open it.
??? Can I export this? | Not from this screen. Use **Administration › Reports** to build a report with enrolment and withdrawal dates.
??? Does this send anything to families? | No.

## Related screens

[[open: #forecast | Enrolment forecast]] [[open: #ai-churn | Churn risk]]

See also [[enrolment-forecast]] and [[churn-risk]].
