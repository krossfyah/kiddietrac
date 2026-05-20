---
title: Auto sign-out for security
category: Settings
order: 60
---
# Why Kiddietrac signs you out

Kiddietrac portals carry sensitive information about children, families, billing, and medications. To meet **PHIPA / PIPEDA** expectations and to protect shared devices (kiosks, tablets, front-desk workstations), the portal signs you out automatically in two cases:

## Idle timeout (30 minutes)

If you don't click, type, scroll, or touch the screen for **30 minutes**, the portal signs you out and returns to the login page. Five minutes before that happens you'll see a warning:

> *⏱️ You will be signed out soon*
> [Stay signed in]   [Sign out now]

Click **Stay signed in** to reset the timer. Click **Sign out now** to end the session immediately.

## Absolute timeout (12 hours)

Even if you're active the whole time, every sign-in is capped at **12 hours**. This catches the edge case of a tab left open overnight on a shared computer. When you hit the 12-hour mark you'll be returned to the login screen — sign in again to continue.

## What happens to my work?

The portal saves changes when you click **Save**, not when you sit on a screen. If you were typing into a form when the timer fired, that unsaved text is lost — you'll need to retype it after signing in again. (We recommend saving every few minutes for long forms.)

## Per-device override

If a director or admin needs a different idle window for a particular device (for example a wall-mounted tablet that should stay live during the day), open the browser's developer console on that device and run:

    KT.SessionTimeout.setIdleMinutes(60);    // change idle to 60 minutes
    KT.SessionTimeout.setAbsoluteHours(8);   // change absolute cap to 8 hours

Settings are stored on the device — other devices keep the defaults.

## Why the message on the login screen

When you're signed out automatically, the login page shows a small amber banner saying *"You were signed out after a period of inactivity"* (or *"capped at 12 hours"*). This is so you know nothing went wrong — you weren't locked out, your session simply ended on schedule.
