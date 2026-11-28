---
title: Security alerts & monitoring
category: Compliance
order: 32
roles: agency_admin, platform_admin
screens: security-alerts
---

# Security alerts & monitoring

**🛡️ Security alerts** lists suspicious sign-in activity across the whole platform. Every 15 minutes KiddieTrac reads the audit log and records anything that looks like an attack on accounts, so a platform administrator can review it, decide whether it matters, and keep a clean record for security audits. Open it from **Platform › 🚨 Security alerts**.

[[open: #security-alerts | Open Security alerts]]

## What gets flagged

Each check looks at the last 20 minutes of sign-in history:

| Type | Raised when | Subject column shows |
|---|---|---|
| **🌐 Brute force (IP)** | 8 or more failed sign-ins from one internet address | the IP address |
| **🔑 MFA hammering** | 5 or more failed two-step codes on one account | the account |
| **🎯 Credential stuffing** | 10 or more failed sign-ins aimed at one email or username | the login that was tried |

The same type and subject is recorded only once in that window, so a long attack does not fill the list with copies. Every alert is **high** severity.

## Reviewing an alert

1. Open **Platform › 🚨 Security alerts**.
2. Look at the two counters at the top: **Open alerts** (red when anything needs a look) and **Total recorded**.
3. Read the row: **Type**, **Severity**, **Subject**, **Detail** (for example "35 failed logins from IP … in the last 20 min") and **When**.
4. Open **Administration › Audit log** in another tab and search for the address or account named in **Subject** to see exactly what was tried and when.
5. Decide: a staff member who forgot a password looks just like an attack. If you do not recognise it, block the source and make the targeted account change its password.
6. Press **Mark resolved** on the row. It dims and shows **✓ Resolved**.

> 💡 If many alerts are open and you have reviewed them all, press **Acknowledge all (n)** and confirm with **Acknowledge all**. They stay in the log and stay readable; they are only marked as seen.

## Clearing the log

Alerts are evidence for security reviews, so clearing is a two-step act.

1. Resolve or acknowledge the alerts first (see above).
2. Press **Clear log**.
3. Read the confirmation **Clear n acknowledged alerts?** and press **Clear log**. A **Log cleared** toast says how many were removed.

> ⚠️ Only acknowledged alerts are deleted, and they are deleted permanently. Open alerts are never cleared. The clearing itself is written to the audit log with the types and counts.

If nothing is acknowledged yet, **Clear log** shows **Nothing to clear** and reminds you to acknowledge the open alerts first.

## Who is told

When a new alert is recorded, every active platform administrator with an email address receives a **security monitoring** email listing what was found and what to do next. Agency staff and families are never emailed about these alerts, and nothing appears in their bell.

## Who can do what

- **Platform administrators**: see every alert, resolve, acknowledge and clear.
- **Agency admins, centre directors and all other roles**: cannot open this screen. An agency admin who reaches it sees **This page is available to platform administrators only.** Their own sign-in history is in **Administration › Audit log**.

## FAQs and when something goes wrong

??? The list says "No security alerts — no anomalous authentication activity detected." Is monitoring working? | Yes. That message means the last checks found nothing over the limits. A single person mistyping a password a few times never reaches them.
??? I see "This page is available to platform administrators only." | You are signed in with an agency role. Only a platform administrator can read these alerts.
??? Clear log did nothing and showed "Nothing to clear". | No alert is acknowledged yet. Press **Mark resolved** on each reviewed row, or **Acknowledge all**, then clear.
??? "Could not acknowledge" or "Could not clear" appeared. | The connection dropped or your session ended. Refresh the page and try again; nothing was half-done.
??? The same IP shows up again after I resolved it. | The attack carried on after the 20-minute window, so a fresh alert was recorded. Block the source if it continues.
??? I did not get the alert email. | Check that your platform administrator account is active and has an email address. Alerts are never sent to a no-reply address.
??? Does resolving an alert unlock or block anyone? | No. It only records that you reviewed it. Account locks and password changes are done from the person's account.

## Related screens

[[open: #audit-logs | Audit log]] · [[open: #social-settings | Sign-in methods]] · [[open: #server-health | Server health]]

See also: [[audit-log]] · [[single-sign-on]]
