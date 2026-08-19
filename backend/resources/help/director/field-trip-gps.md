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
