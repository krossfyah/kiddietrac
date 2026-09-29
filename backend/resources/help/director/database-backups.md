---
title: Database backups
category: Settings
order: 45
roles: agency_admin, platform_admin
---
# Database backups

**Settings → Backups.** Whether the nightly copy of the platform database is actually
being taken, when the last good one was, and how many are kept.

[[open: #backups | Open Backups]]

## Who can use it

**Platform admins only.** One database sits behind every agency on the platform, so a
backup holds everybody's data, not one agency's. An agency admin does not see the menu
item, and the server refuses anyone else.

## The status card

The top card answers "are we covered?" in one line:

- **Healthy**: a good copy was taken within the last day or so.
- **Older than a day**: last night's run did not produce one.
- **Stale — more than 2 days old**: treat this as a failure.
- **Last run failed**: the error is printed underneath in red.
- **No backup recorded yet**.

Under it: **Last good copy** (how long ago, and the exact time), how many **copies kept**,
the total **MB on disk**, and the size of the latest one.

**Scheduler last seen** is the line to check when anything looks off. Every scheduled job
on the platform, not just backups, depends on one scheduler that runs every minute. If that
reads *never — the cron may not be running*, nothing scheduled is happening. A warning also
appears if the backup folder is not writable.

## Settings

- **Nightly backup**: on or off. Off means no copy of the database is taken at all.
- **Time**: when the nightly copy runs, in **server time**. Pick a quiet hour. The copy
  locks nothing, but it reads every table. The default is 03:30.
- **Keep**: how many daily copies to retain, from 1 to 90. The default is 14. Older copies
  are deleted after each run.

Press **Save**. Turning backups off saves with an amber warning, *Saved — nightly backups
are now OFF*, rather than a green tick.

> **Important:** With backups off there is no nightly copy of this database. Switching them off is recorded in the audit log in exactly those words.

## Back up now

**Back up now** takes a copy immediately and waits for the result, so you see whether it
**worked**, not just that it was requested. It reads every table, so give it a moment.

[[show: #backups @ Back up now | Show me the Back up now button]]

## Copies on disk

The bottom card lists up to 30 of the most recent copies, with the time each was taken,
its file name and its size.

## Why you cannot download one

**Backups cannot be downloaded from the portal, and that is deliberate.** One file holds
every child, guardian, address and medical note on the platform. The copies are stored
outside the web root, and a restore is done over SSH by a person. The screen shows the
restore command for whoever does that job.

## When something goes wrong

- Each copy is checked for integrity after it is written. A failed or corrupt copy is thrown
  away rather than kept.
- A failed run shows as **Last run failed** here, and raises a high-severity alert under
  **Settings → Security alerts**.
- Switching backups off on purpose is not treated as a failure, so it does not raise an
  alert every night.
- Changing the settings and running a backup by hand are both written to the audit log,
  with the before and after values.

See also: [Security alerts & monitoring](security-alerts),
[Platform admin (overview)](platform-admin-overview),
[Data retention & compliance](data-retention-compliance).
