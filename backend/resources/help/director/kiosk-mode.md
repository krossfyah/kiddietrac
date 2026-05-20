---
title: Kiosk mode for tablet check-in
category: Daily Operations
order: 30
---
# Kiosk mode for tablet check-in

Kiosk mode turns a tablet at your centre's front door into a self-service check-in/out screen for parents. Parents tap their child's card, type a 4-6 digit PIN, and Kiddietrac records the attendance event with the kiosk-source flag — no staff time spent at drop-off and pickup.

## Enabling kiosk for a centre

1. Sidebar → **Administration → Centres**.
2. Click **Edit** on the centre that should run a kiosk.
3. In the **Kiosk mode** section, check **Enable kiosk for this centre**.
4. Kiddietrac mints a unique kiosk URL and shows it in the URL field. Click **Copy URL**.

## Setting up the tablet

Open the copied URL on the tablet's browser. Add it to the home screen as a PWA so it opens fullscreen without a browser bar. The kiosk shows every enrolled child at the centre with their photo (or initial), name, room, and current status (AT CENTRE or NOT IN).

The tablet does not require sign-in — the unique URL token grants read-only access to the roster. PIN entry is required for any write (check-in / check-out).

## Setting parent PINs

Each parent who picks up needs their own 4-6 digit PIN. To set one:

1. Sidebar → **Administration → Families** → click the family card.
2. In the **Guardians** section, find the guardian (must have **can_pickup** enabled).
3. Click **Set kiosk PIN**.
4. Type a 4-6 digit number → Save.
5. Tell the parent their PIN out-of-band (text, email, in person).

Repeat for every guardian who'll be using the kiosk. Parents without pickup rights cannot have a PIN set.

## How the parent flow works

1. Tap the child's card → modal opens with a number pad.
2. Type the 4-digit PIN → Kiddietrac verifies the PIN against the family's guardians who can_pickup.
3. On match, Kiddietrac records a check-in (or check-out — flips based on the child's current state).
4. Card flips to AT CENTRE (green) for check-in or back to NOT IN (grey) for check-out.

## Rotating the kiosk URL

If the URL leaks (someone shared the tablet's home page screenshot, e.g.), click **Rotate token** in the centre's Kiosk section. The old URL stops working immediately. Open the kiosk's browser and paste the new URL.

## Disabling kiosk

Uncheck **Enable kiosk** in the centre Edit modal. The URL stops working immediately. PINs stay stored — re-enable to bring everything back.
