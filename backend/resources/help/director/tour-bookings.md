---
title: Tour bookings
category: Daily Operations
order: 78
---
# Tour bookings

Prospective families can request a centre tour through your public booking endpoint. Confirmed tours feed into the staff calendar so directors know who's coming when. Find the management view under **Growth → Tours**.

## The public link

Your public booking URL is:

    POST https://api.kiddietrac.com/api/v1/public/tours

Embed it in a custom form on your website that posts `{ agency_slug, centre_id, parent_name, parent_email, parent_phone, child_age_months, preferred_start_date, tour_at, notes }`. A future v22p51 ship will provide a hosted public landing page so you don't need to build the form yourself.

The endpoint is throttled to 8 requests per hour per IP to prevent spam.

## The admin view

The Tours screen lists every booking with:

- Coloured left rail by status (amber = requested, blue = confirmed, green = completed, red = no-show, grey = cancelled)
- Parent name + email + phone
- Tour date and time
- Child age in months (if provided)
- Free-text notes from the family

A status dropdown on each row flips the booking through the workflow. Each request also creates a notification for the centre director and agency admins so they see new ones in their inbox.

## Confirmation emails

Sending an email to the requester on confirm is a v22p50 follow-up. For now, copy their email from the row and reply manually.
