---
title: Carrier settings — Twilio and Telnyx
category: Settings
order: 41
roles: agency_admin, platform_admin
---
# Carrier settings — Twilio and Telnyx

**Settings → Carrier settings.** The accounts your agency texts and telephones through.
Two carriers are supported and two channels run over them — SMS, and voice announcement
calls — which is why the screen is no longer named after either one of them.

## Two switches, not one

Nothing sends until both are on, and they are in different places. An admin who does not
know that spends a long time wondering why a broadcast reported "sent" and nobody's phone
moved.

1. **A carrier is configured and selected here.**
2. **Notifications are on for the agency**, under **Settings → Email settings**. That is a
   cross-channel master switch: with it off, nothing sends or rings even with a carrier
   fully set up. The screen says so in red when it is off.

## Choosing which carrier sends

The **which carrier sends** card sits above the carrier tabs, because it is a decision
about the pair and belongs to neither of them. Pick Twilio or Telnyx; the one currently
sending is marked on its own tab.

This matters more than it looks. The carriers have a page each — they have no fields in
common, and reading past nine Twilio boxes to reach the Telnyx ones is how people fill in
the wrong set. The cost of separating them is that you can carefully configure Telnyx and
forget to actually switch to it, so check the tab marking before you assume.

Both pages are saved together. Filling in Telnyx cannot quietly discard a number typed on
the Twilio page.

## Secrets

Credentials are **write-only**. Once saved, the screen tells you whether a secret is
stored — it never shows it back. To change one, type the new value; to leave it alone,
leave the box empty.

Take the field names literally. A Telnyx API key is not a Twilio Account SID, and an
identifier starting `OQ…` is not an Account SID either.

## Testing

Two different tests, and the difference is the point:

- **Test connection** checks the credentials with the carrier. It costs nothing and sends
  nothing. The result is kept on the screen, so "did this ever work" is answerable
  tomorrow.
- **Send a test** sends a **real** text, or places a **real** call, to a number you type.
  It spends money and can ring a stranger, so it is limited to agency admins and to five
  an hour per agency. Nobody else is contacted.

## Recent activity

The screen reports sends per carrier over the last 30 days. It is the first question after
switching carriers, and the quickest way to notice that the switch did not take.

## Version stamp

A build date is printed under the page title. A stale cached copy of the app is otherwise
indistinguishable from a bug, so when something looks wrong, read that line first.

See also: [SMS broadcast](sms-broadcast), [Announcement calls](voice-announcements),
[Email and digests](email-and-digests).
