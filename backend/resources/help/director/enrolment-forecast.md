---
title: Enrolment forecast
category: Reporting
order: 47
roles: agency_admin, centre_director, platform_admin
screens: forecast
---
# Enrolment forecast

**Marketing › Enrolment forecast** projects how many children you are likely to have enrolled over the next six
months. It starts from today's enrolment, adds the families who toured with you in the last 60 days (assuming about
3 in 10 of them enrol), and takes away a typical 2.5% of families leaving each month. Use it to plan staffing,
rooms and how hard to push tours.

[[open: #forecast | Open Enrolment forecast]]

## Reading the forecast

1. Open **Marketing › Enrolment forecast**. The banner reads **📈 Enrolment forecast** and says how many tours are in the pipeline.
2. Read the four tiles: **Current enrolment**, **Tour pipeline · 60d**, **Monthly adds** and **Monthly churn %**.
3. Look at the **6-month enrolment trajectory** chart. The green dot is **Current**; the blue line is **Projected**, with the number above each month.
4. Scroll to **Month-by-month breakdown** for each month's **Projected** total, **+ Adds** and **− Churn**.

> ℹ️ The forecast is an estimate, worked out fresh each time you open the page. It uses fixed assumptions (30% of toured families enrol, 2.5% of families leave each month); it does not know about a new room opening or a fee change.

## Using it to plan

1. If the line falls over the six months, book more tours: open **Marketing › Tours** and follow up the families who visited.
2. If the line rises towards your licensed capacity, check the **Compliance** capacity card and your waitlist before taking more enrolments.
3. Compare with **Compliance › Retention**: if recent months keep fewer families than 97.5% a month, the real line will be lower than this one.

## Who can do what

| Role | Sees |
|---|---|
| Agency admin | The forecast for the active agency. |
| Centre director | The same agency-wide forecast. |
| Platform admin | The agency chosen in the agency switcher. |

The forecast shows counts only and sends nothing to anyone.

## FAQs and when something goes wrong

??? Tour pipeline is 0. | No tours were recorded in the last 60 days. Book and record tours on **Marketing › Tours** and the forecast picks them up.
??? The current number does not match my count. | It counts children enrolled today. Check that children who left have a withdrawal date and new children have an enrolment date.
??? Can I change the 30% or 2.5% assumptions? | Not on this screen.
??? The page stays on "Computing forecast…" | Refresh. If it keeps happening, raise a ticket from Help.

## Related screens

[[open: #tours | Tours]] [[open: #retention | Cohort retention]] [[open: #ai-churn | Churn risk]]

See also [[tour-bookings]] and [[cohort-retention]].
