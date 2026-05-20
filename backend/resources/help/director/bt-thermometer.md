---
title: Bluetooth thermometer at kiosk
category: Daily Operations
order: 80
---
# Bluetooth thermometer

Read a child's temperature directly from a paired Bluetooth thermometer at the kiosk.

## Setup

1. Pair the thermometer with the kiosk tablet (one-time setup via OS Bluetooth settings).
2. The kiosk's web browser (Chrome 90+, Safari 16.4+) requests Bluetooth permission the first time a temperature is needed.

## At drop-off

1. Parent / staff selects the child on the kiosk.
2. Tap **Take temperature** button.
3. Browser shows the Web Bluetooth pairing dialog.
4. Pick the paired thermometer.
5. The reading appears on screen + auto-fills the kiosk record.

## What's recorded

Every reading saves to `check_events.temperature_c` with:
- Temperature in °C
- Method: `bluetooth` / `manual` / `scanner`
- Linked check-in event ID (so the temperature attaches to today's drop-off)

## Fever alerts

If the reading is **≥ 38°C**:
- A **fever_alert** notification fires to centre staff
- The kiosk displays a "Please contact centre" message
- The reading is flagged in the daily wellness digest

## Requires

- HTTPS (Web Bluetooth API doesn't work over HTTP)
- Compatible thermometer (any device exposing standard BLE Health Thermometer Service `00001809-0000-1000-8000-00805f9b34fb`)
- Modern browser with Web Bluetooth API
- Granted Bluetooth permission

## Compatible thermometers

Tested working: iHealth, A&D, Beurer FT 90, Withings Thermo. Most consumer BLE thermometers using the standard service will work without custom code.

## Fallback

If Bluetooth isn't available, the same kiosk modal lets staff enter the temperature manually with **Method = manual**.
