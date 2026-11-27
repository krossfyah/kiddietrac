---
roles: agency_admin, platform_admin
title: Drip campaigns
category: Marketing
order: 65
screens: drip-campaigns
---
# Drip campaigns

**Engagement › Drip campaigns** sends a follow-up email automatically a set number of days after something happens —
a family's tour, a child's enrolment, a child's birthday, or a family going quiet. Set it up once and every family that
reaches that moment gets the email, so nobody is forgotten: a "thanks for visiting" note the day after a tour, a
welcome pack three days after enrolment.

[[open: #drip-campaigns | Open Drip campaigns]]

## Setting up a drip campaign

1. Open **Engagement › Drip campaigns**. The banner reads **💧 Drip campaigns**.
2. Press **+ New drip campaign**.
3. Enter a **Title** your team will recognise ("Tour thank-you").
4. Choose the **Trigger event**: **After tour booked**, **After enrollment complete**, **On child's birthday** or **After 30 days inactive**.
5. Enter the **Delay (days)** — how many days after the event to send (0 sends on the day).
6. Write the **Email subject** and the **Email body (HTML allowed)**. Type **{first_name}** where you want the parent's first name.
7. Press **Create**. The campaign appears in the list as active.

> 💡 For **After tour booked**, the delay counts from the tour date, so a delay of 1 sends the day after the visit.

## Checking it is working

1. Look at the tiles at the top: **Active drip campaigns**, **Sent (all time)**, **Queued** and **Failed**.
2. Read the list: each campaign's **Title**, **Trigger**, **Delay** and **Status**.
3. If **Failed** grows, check the email addresses on the families' records.

KiddieTrac checks every hour for families who have reached a trigger, and sends each campaign to each person once —
it never repeats for the same tour, child or family.

## Who receives it

- **After tour booked** — the email address given when the tour was booked.
- **After enrollment complete** and **On child's birthday** — the family's main email, for children still enrolled.
- **After 30 days inactive** — families who have not used the portal for 30 days.

The usual email rules apply: families who have left and closed accounts get nothing.

## Who can do what

| Role | Can |
|---|---|
| Agency admin | Create drip campaigns and see their numbers. |
| Platform admin | The agency chosen in the agency switcher. |
| Centre director, educator | Not available. |

## FAQs and when something goes wrong

??? How do I change or stop a drip campaign? | There is no edit, pause or delete button on this screen yet. Raise a ticket from Help and KiddieTrac support will change or stop it for you.
??? A family got nothing. | Check the trigger date (for example the tour date or enrolment date on the record) and the delay, and that the family has an email on file. Sending runs hourly, so allow an hour.
??? Can I preview the email? | Not here. Send yourself a test by writing it first as a campaign under **Marketing › Campaigns** and using **👁 Preview**, then paste the body here.
??? Does {first_name} work? | Yes. It becomes the person's first name when the address belongs to someone's account, otherwise "there".

## Related screens

[[open: #marketing-campaigns | Campaigns]] [[open: #tours | Tours]] [[open: #engagement | Engagement score]]

See also [[marketing-campaigns]] and [[tour-bookings]].
