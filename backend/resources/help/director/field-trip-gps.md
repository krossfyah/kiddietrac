---
roles: agency_admin, platform_admin
title: Field-trip live GPS tracker
category: Daily Operations
order: 45
---
# Field-trip GPS

Live location of staff lead on each field trip. Parents can see where their child's bus / group is in real time.

## Staff setup

1. The staff lead opens the field trip on their phone (web browser, must be HTTPS).
2. Tap **Start GPS sharing**.
3. Browser prompts for location permission — accept.
4. Phone reports lat/lon every 30 seconds to `/field-trips/{id}/ping` for the duration of the trip.

## Parent view

1. Sidebar → **Field trip GPS**.
2. Enter the trip ID and tap **Load**.
3. See:
   - Latest lat/lon + timestamp of last ping
   - 100-ping trail rendered on an OpenStreetMap embed
   - Trip metadata (title, destination, depart/return times)
4. Only guardians with an **approved** field-trip permission slip can view their trip's location.

## Privacy + safety

- Location tracking stops when the trip ends.
- Pings stored in `field_trip_pings` for the trip duration only.
- Trail trimmed to 100 most-recent pings on display.

## Requires

- Modern browser with Geolocation API (Chrome 90+, Safari 14+, Firefox 80+)
- HTTPS (Geolocation won't work over HTTP)
- Active internet connection on the lead's device


## When a walk ends

Ending a walk now does three things beyond stopping the tracker:

1. **The distance is worked out** from the GPS trail and stored against the walk.
2. **Each child gets a daily-log entry** — where they went, the start and end time, how
   long, and how far. Parents see it in their child's day.
3. **The route map is kept.** It stays on the Daily Overview after the walk finishes,
   which is when you are usually writing the day up, and the same picture is emailed to
   parents in the daily summary.

The distance ignores GPS noise: a fix accurate to worse than 100 m is skipped, and so is
any jump over 250 m between two fixes, which is a receiver relocating itself rather than
a toddler sprinting. Once a walk has ended its distance is fixed, so the figure a parent
was emailed does not drift afterwards.

The **Daily Overview** also totals the day's distance in a **Walked** card, beside Meals
and Naps.
