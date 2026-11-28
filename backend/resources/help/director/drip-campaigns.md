---
roles: agency_admin, centre_director, platform_admin
title: Drip campaigns
category: Marketing
order: 65
screens: drip-campaigns
---
# Drip campaigns

**Engagement › Drip campaigns** sends a follow-up email automatically a set number of days after something happens:
a family's tour, a child's enrolment or a child's birthday. Set it up once and every family that reaches that moment
gets the email, so nobody is forgotten: a "thanks for visiting" note the day after a tour, a welcome pack three days
after enrolment.

[[open: #drip-campaigns | Open Drip campaigns]]

## Setting up a drip campaign

1. Open **Engagement › Drip campaigns**. The banner reads **💧 Drip campaigns**.
2. Press **+ New drip campaign**. A popup opens.
3. Enter a **Title** your team will recognise ("Tour thank-you").
4. Choose **Starts when**: **After a tour is booked**, **After enrolment is complete** or **On a child's birthday**.
5. Enter **Days after (0 = the same day)**.
6. Choose the **Centre**. An agency admin can pick **All centres**; a centre director picks one of their own centres.
7. Write the **Email subject** and the **Email text**. Type **{first_name}** where you want the parent's first name.
8. Press **Create**. The drip appears in the list as **Running**.

> 💡 For **After a tour is booked**, the days count from the tour date, so 1 sends the day after the visit.

## Changing, pausing or deleting a drip

Open the **⋮** menu on the drip's row.

1. **View** shows the status, what starts it, the centre, how many were sent and the email itself.
2. **Edit** opens the same popup. Change the title, days, centre, subject or text and press **Save**.
3. **⏸ Pause** stops it sending. **▶ Resume** starts it again.
4. **🗑 Delete** asks you to confirm, then the drip stops and leaves the list.

> ℹ️ A change reaches only the people still to come. Nobody who already got the email is sent it again, even after
> an edit. Once a drip has sent, **Starts when** cannot change: create a new drip instead.

> ℹ️ While a drip is paused, nothing is caught up later: families whose moment passed during the pause do not get it.

## Checking it is working

1. Look at the tiles at the top: **Running**, **Sent (all time)**, **Waiting to send** and **Failed**.
2. Each row shows **Sent**, **Failed** and **Last sent** for that drip.
3. If **Failed** grows, check the email addresses on the families' records.

KiddieTrac checks every hour for families who have reached a trigger, and sends each drip to each person once.

## Who receives it

- **After a tour is booked**: the email address given when the tour was booked.
- **After enrolment is complete** and **On a child's birthday**: the family's main email, for children still enrolled.
- A drip for one centre reaches only that centre's families.

The usual email rules apply: families who have left and closed accounts get nothing, and test agencies send nothing.

## Who can do what

| Role | Can |
|---|---|
| Agency admin | Create, change, pause and delete every drip, including **All centres** ones. |
| Centre director | Create, change, pause and delete drips for their own centres; view agency-wide ones. |
| Platform admin | The agency chosen in the agency switcher. |
| Educator | Not available. |

## FAQs and when something goes wrong

??? How do I stop a drip for a while? | Open the row's **⋮** menu and choose **⏸ Pause**. Choose **▶ Resume** to start it again.
??? I deleted a drip by mistake. | The drip and its sending history are kept. Raise a ticket from Help and KiddieTrac support can restore it.
??? A family got nothing. | Check the trigger date (for example the tour date or enrolment date on the record), the days after, that the drip is **Running**, and that the family has an email on file. Sending runs hourly, so allow an hour.
??? Does {first_name} work? | Yes. It becomes the person's first name when the address belongs to someone's account, otherwise "there".

## Related screens

[[open: #marketing-campaigns | Campaigns]] [[open: #tours | Tours]] [[open: #engagement | Engagement score]]

See also [[marketing-campaigns]] and [[tour-bookings]].
