---
title: Background-check expiry
category: Compliance
order: 35
---
# Background-check tracking

Track Vulnerable Sector Search (VSS), criminal record, driver, and reference checks for every staff member. Get warnings 60 days before expiry.

## Add a record

1. Sidebar → **Background checks** (agency admin or centre director).
2. Click **+ Record**.
3. Pick the staff member, check type, reference number, issued date, and expiry date.
4. Optionally paste a document URL (Drive / Dropbox link).

## Status colours

- 🟢 **Valid** — expires more than 60 days from now
- 🟡 **Expiring** — expires within 60 days
- 🔴 **Expired** — past expiry

## Automatic warnings

A daily cron at 08:00 ET checks every record. Records within 60 days of expiry trigger one notification per (record, calendar week) so staff aren't spammed daily. The notification goes to the holder; admins see them in the audit log + compliance dashboard.

## CSV export

Click **⤓ CSV** in the toolbar for a UTF-8-BOM file with: Staff name, Email, Type, Reference, Issued, Expires, Status.
