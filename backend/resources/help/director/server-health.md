---
title: Server health and the leak guard
category: Administration
order: 61
roles: platform_admin
screens: server-health
---
# Server health and the leak guard

**Platform › Server health** shows, on one page, whether the portal is healthy: the machine (processor, memory, disk), the database, background jobs, the scheduler, backups, what people felt in the last 24 hours (errors, slow and failed requests), how fast the portal was on people's devices, the search words, the **Leak guard** checks and the web certificates. Open it when people say the portal is slow, after a deploy, or as a daily check.

[[open: #server-health | Open Server health]]

The page refreshes itself every 30 seconds while it is open. It has two tabs: **🖥️ This server** (the portal's own figures) and **☁️ Cloudways · live server** (the hosting account's graphs).

## Doing a daily health check

1. Open **Platform › 🖥️ Server health** and press **🖥️ This server**.
2. Read the alert lines at the top. **✅ Everything looks healthy.** means no limit is crossed; otherwise each problem shows as a 🟠 (warning) or 🔴 (urgent) line in plain words.
3. Read the cards: **Processor**, **Memory**, **Disk** (with uploads, backups and logs), **Database** (response time and connections), **Background jobs** (waiting, oldest wait, failed today), **Scheduled jobs** (**Running** or **Not running**, last check-in), **Newest backup** (age, encrypted or not, copies kept) and **Last 24 hours** (errors, slow requests, requests that never arrived, rate-limited). Green is fine, amber needs a look, red needs action.
4. To look further back, pick a period (**Last 6 hours** to **Last 14 days**) and look at the **History** graphs, filled in every 5 minutes.
5. Press **↻ Refresh** to read everything again straight away.

## Checking the leak guard

The **Leak guard** card shows the automatic checks that make sure no agency can see another agency's data.

1. Scroll to **Leak guard**.
2. Read each row's **Result**:
   - **Route probe (latest)**: the hourly test of a slice of the portal's requests as each role.
   - **Route probe (full, nightly)**: the same test over every route, every night.
   - **Data check**: checks on the stored data itself.
   - **Live sentinel**: a sample of real responses over the **Last 7 days**.
3. **Clean** (green) means nothing was found. **… finding(s)** or **… hit(s)** (red) means a possible leak: the details are listed in red under the table. **Blind: … role(s)** (amber) means the test could not sign in as some roles, so it checked less than it should. **Not run yet** means the check has not run since it was set up.

> ⚠️ Treat any red Leak guard result as urgent: read the lines under the table and fix the cause the same day.

## Finding why the portal feels slow

1. Read **Connections (devices, last …)**: **Sessions measured**, **On a slow connection** (percentage on 2G/3G or waiting over 2 s), **Typical wait per request** and **Requests that failed**. A table lists the agencies with the slowest sessions.
2. Read **Where problems happened (24 h)**: each **Server error**, **Slow** or **Never arrived** request, the screen or request it hit, and how many **Times**.
3. Check the **Database** section for slow queries and the largest tables.
4. Press **☁️ Cloudways · live server**, pick a period, and look at the hosting graphs (processor, memory, disk, bandwidth, disk use by app). Press **↻ Cloudways** to read them again.

## Other sections

- **Search words**: how many words the portal's search knows per role, when a screen was last reported, what was learned and turned down this week. Red **Last screen reported** means the search has stopped learning.
- **Certificates**: each web address, when its certificate **Expires**, **Days left** and the **Issuer**. Certificates renew automatically; a low **Days left** means renewal failed.

## Connecting the hosting account

1. Press **☁️ Cloudways · live server**.
2. Follow the instructions on the tab to create an access token in the hosting account.
3. Paste it into the box and press **Connect Cloudways**.
4. To remove it, press **Disconnect** and confirm. The token is deleted from KiddieTrac; you can connect again any time.

## Who can do what

| Role | Access |
|---|---|
| Platform admin | The whole page. |
| Anyone else | The page says it is available to **platform administrators** only. |

## FAQs and when something goes wrong

??? "Scheduled jobs: Not running". | The scheduler that runs every timed job (backups, reminders, digests) has stopped. This is urgent: nothing scheduled is happening until it is restarted.
??? "Newest backup" is red or says NOT encrypted. | No good backup in the last 30 hours, or the copy is not encrypted. Open Backups to see the last error.
??? "The server could not be read just now." | The figures could not be loaded. Press ↻ Refresh in a minute.
??? "Collecting — the history fills in every 5 minutes." | History is new or was cleared. Wait a few minutes.
??? Leak guard says "Blind". | The probe could not act as one or more roles, so its check is incomplete. Treat it as not yet proven clean.
??? Does this page alert anyone? | The page itself sends nothing; it only shows. A failed backup also raises an alert on Security alerts.

## Related screens

[[open: #backups | Backups]] [[open: #security-alerts | Security alerts]] [[open: #ai-usage | AI usage]] [[open: #maintenance | Maintenance]] [[open: #compliance-evidence | Compliance evidence]]
