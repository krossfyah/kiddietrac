---
title: Database backups
category: Settings
order: 45
roles: platform_admin
screens: backups
---
# Database backups

**Platform › Backups** shows whether the nightly copy of the platform database is really being taken, when the last good one was, and how many are kept. It is also where you change the backup time and how long copies are kept, and take a copy by hand before risky work. Open it as part of a daily check, before a big change, or whenever Server health shows the newest backup in red.

[[open: #backups | Open Backups]]

## Checking that you are covered

1. Open **Platform › 🗄️ Backups**.
2. Read the coloured line on the top card:
   - **Healthy**: a good copy was taken within the last day or so.
   - **Older than a day**: last night's run did not produce a copy.
   - **Stale — more than 2 days old**: treat this as a failure.
   - **Last run failed**: the error is printed underneath in red.
   - **No backup recorded yet**.
3. Read **Last good copy** (how long ago and the exact time), the number of **copies kept**, the **MB on disk** and the size of the latest copy.
4. Check **Scheduler last seen**. Every scheduled job on the platform, not only backups, depends on one scheduler that runs every minute. If it reads **never — the cron may not be running**, nothing scheduled is happening. A red **backup folder is not writable** means copies cannot be saved.

## Changing the schedule

1. On the second card, leave **Nightly backup** switched on. Off means no copy of the database is taken at all.
2. Set **Time**, in server time. Pick a quiet hour: the copy locks nothing, but it reads every table. The default is 03:30.
3. Set **Keep**, the number of daily copies to retain (1 to 90 days; the default is 14). Older copies are deleted after each run.
4. Press **Save**. You see **✓ Saved**, or, if you switched backups off, an amber **⚠ Saved — nightly backups are now OFF** instead of a tick.

> ⚠️ With backups off there is no nightly copy of the database. Switching them off is recorded in the audit log.

## Taking a backup now

Do this before a risky change, such as a large import or an upgrade.

1. Press **Back up now**.
2. Wait while **Taking a backup… this reads every table, give it a moment.** shows. The button waits for the result.
3. You see **✓ Backup taken** and the new copy appears under **Copies on disk**, or a red error and the status card shows **Last run failed**.

[[show: #backups @ Back up now | Show me]]

## Copies on disk

The bottom card lists the most recent copies with the time each was taken, its file name and its size. Each copy is checked after it is written; a failed or corrupt copy is thrown away rather than kept. Copies are encrypted so that only KiddieTrac's offline key can open them; a copy that could not be encrypted is kept anyway and raises an alert, because a backup beats no backup.

> ℹ️ **Copies cannot be downloaded from the portal, on purpose.** One file holds every child, guardian, address and medical note on the platform. They are stored outside the web site and restored by a person with server access and the offline key, never from a browser.

## Who can do what

| Role | Access |
|---|---|
| Platform admin | Everything on this screen. |
| Agency admins and everyone else | No access. One database serves every agency, so a backup holds everybody's data, not one agency's. The server refuses anyone else. |

## FAQs and when something goes wrong

??? The status says "Last run failed". | Read the red error under it. Fix the cause (often disk space), then press Back up now to prove it works. A failed run also raises a high-severity alert on Security alerts.
??? "Scheduler last seen: never — the cron may not be running". | The scheduler has stopped, so no scheduled job runs. This is urgent: have it restarted on the server.
??? "Backups are managed by platform administrators only." | You are not signed in as a platform admin.
??? I switched backups off on purpose. Will I get alerts every night? | No. Switching them off is not treated as a failure.
??? Can an agency ask for its own data back? | Not from a backup on this screen. Use the agency's own export and privacy request screens.
??? Does anyone get notified when a backup runs? | No. Only failures raise an alert; settings changes and manual runs go in the audit log with before and after values.

## Related screens

[[open: #server-health | Server health]] [[open: #security-alerts | Security alerts]] [[open: #compliance-evidence | Compliance evidence]] [[open: #maintenance | Maintenance]]

See also: [Security alerts & monitoring](security-alerts), [Data retention & compliance](data-retention-compliance).
