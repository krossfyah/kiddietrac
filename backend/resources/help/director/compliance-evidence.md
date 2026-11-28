---
title: Compliance evidence (SOC 2 readiness)
category: Compliance
order: 67
roles: platform_admin
screens: compliance-evidence
---
# Compliance evidence (SOC 2 readiness)

**Platform › Compliance evidence** is what an auditor asks for first when KiddieTrac prepares for a SOC 2 report: the written policies and control matrix, and live evidence that the controls exist and run (who has admin access, security events, backups, scheduled jobs and code changes). The quarterly access review is signed off here too. The SOC 2 report itself is issued by an independent CPA firm.

[[open: #compliance-evidence | Open Compliance evidence]]

## Reading the control tiles

1. Open **Platform › 🛡️ Compliance evidence**.
2. Read the tiles at the top. Green is in order, amber needs attention, red is a gap:
   - **Admins with two-factor** (and how many also use a passkey): should be 100 %.
   - **All users with two-factor**.
   - **Admins never signed in**: unused access to remove in the review.
   - **Sign-ins, last 30 days**: successful and failed sign-ins, and two-factor failures.
   - **Latest backup** and how many copies are kept.
   - **TLS certificate (app / api)**: days left; certificates renew automatically.
   - **Audit log**: how many events are kept and since when.
   - **Access review**: the date of the last sign-off, or **Due now (quarterly)**.

## Downloading evidence for an auditor

1. Go to **Evidence exports**.
2. Press the export you need: **👥 Access review (CSV)**, **🔐 Security events, 90 days (CSV)**, **🗄️ Backups (CSV)**, **⏱️ Scheduled jobs** or **🧩 Code changes, 90 days**.
3. The file downloads. It is made from live data at that moment.

> ℹ️ Every download is recorded in the audit log, with who downloaded what and when.

## Downloading the policies

1. Go to **Policies and control matrix**.
2. Start with the README, the control matrix and the remediation plan.
3. Press **Download** beside each document you need. The **Updated** column shows each document's date.

## Signing off the quarterly access review

Do this every quarter. The sign-off keeps a snapshot of every admin account as it was that day.

1. Under **Evidence exports**, press **👥 Access review (CSV)** and open the file.
2. Check every admin account: is it still needed, and does it have two-factor?
3. Fix what is not right (remove unused admin access, ask people to turn on two-factor).
4. Under **Quarterly access review**, write **What did you find?** (for example how many accounts you reviewed and what was wrong).
5. Write **What did you change?**
6. Tick **I reviewed every admin-level account listed in the access review.**
7. Press **✍️ Sign off access review**. An **Access review signed off** toast appears and the review is added to the list above the form (**Date**, **Reviewer**, **Accounts**, **Findings**, **Changes**).

[[show: #compliance-evidence @ Sign off access review | Show me]]

## Who can do what

| Role | Access |
|---|---|
| Platform admin | Everything on this screen. |
| Agency admins, directors, auditors | No access: this is KiddieTrac's own evidence. An agency's own compliance lives on its Compliance and Data retention screens. |

## FAQs and when something goes wrong

??? "Write down what you found first." | The What did you find? box is empty. Describe what the review found before signing off.
??? "Tick the box to confirm you reviewed every account." | Tick the confirmation box above the sign-off button.
??? "Download failed (…)". | The export could not be made. Refresh and try again; only platform admins can download.
??? "No access review has been recorded yet." | Nobody has signed one off. Do the first review now.
??? The Access review tile is red. | The last review is more than a quarter old, or there is none. Do a review and sign it off.
??? Does signing off email anyone? | No. It is saved here and kept as evidence.

## Related screens

[[open: #audit-logs | Audit log]] [[open: #backups | Backups]] [[open: #security-alerts | Security alerts]] [[open: #server-health | Server health]] [[open: #data-retention | Data retention & compliance]]
