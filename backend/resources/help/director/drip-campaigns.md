---
roles: agency_admin, platform_admin
title: Drip campaigns
category: Marketing
order: 65
---
# Drip campaigns

Auto-send follow-up emails N days after a trigger event. Useful for nurturing tour-bookers and re-engaging quiet families.

## Creating a campaign

1. Sidebar → **Drip campaigns**.
2. Tap **+ New drip**.
3. Pick a trigger:
   - **After tour booked** (e.g. send a "thanks for visiting" email 1 day after)
   - **After enrollment complete** (e.g. welcome packet 3 days after)
   - **On birthday** (e.g. age-appropriate program suggestion)
   - **After 30 days inactive** (re-engagement)
4. Pick the delay in days (0-365).
5. Write the subject + body HTML.
6. Save.

## How dispatching works

A cron runs every hour. It scans for trigger events that match each active campaign's `(trigger_event, trigger_delay_days)`. Matching events get added to the `drip_sends` queue + sent immediately.

Each (campaign, source) is dispatched once — replays are idempotent.

## Tracking

Campaign page shows: # campaigns · # sent · # queued · # failed.

## Email templates

Use the standard EmailTemplate variables in your body HTML:
- `{{first_name}}` — recipient's first name
- `{{family_name}}` — family name
- `{{centre_name}}` — centre name
- `{{agency_name}}` — your agency name

## Best practice

- Start with 1-2 campaigns; measure response before adding more
- Mix tone: tour-booked = warm welcome, inactivity = soft re-engagement
- Don't send too frequently — quality over quantity
- Use AI rewrite (`/marketing/ai-rewrite` endpoint) to polish copy
