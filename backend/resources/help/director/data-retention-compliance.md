---
title: Data retention & compliance
category: Compliance
order: 30
roles: agency_admin, platform_admin
screens: data-retention, ~compliance-evidence
---
# Data retention & compliance

**Settings › Data retention & compliance** is where an agency admin writes down how long each kind of record is kept,
chooses whether KiddieTrac clears old records automatically, and sets the privacy notice parents are asked to read.
Together these are your agency's data-retention policy — the page an auditor or a parent asking "how long do you keep
this?" needs. Childcare rules often set minimums (for example Ontario's CCEYA), so check yours before changing a period.

[[open: #data-retention | Open Data retention & compliance]]

## Setting retention periods

1. Open **Settings › Data retention & compliance**. The **🗓 Retention periods** tab opens first.
2. For each line, enter how long to keep it, in the years or months shown: **Child & enrolment records (after departure)**, **Uploaded documents**, **Attendance & daily logs**, **Parent–educator messages (chat)**, **Announcements & news**, **Suspended families**, **Call recordings & transcripts**, **Walk & field trip GPS trails**, **Log of texts sent**, **Log of emails sent** and **Security & audit trail**.
3. Read the badge on each line: **Enforced** means the nightly clean-up acts on it (once automatic enforcement is on); **Policy only** means it is recorded as your policy and nothing deletes it automatically.
4. Press **Save changes**. A green **Saved** toast appears.

> 💡 For the lines that say "0 = keep", 0 means keep forever. For a call recording only the audio and transcript are removed; for a walk only the location points are removed — the call and walk records stay.

## Turning on automatic clean-up

1. Open the **⚙️ Automatic enforcement** tab.
2. Choose **When enforcing, records are**: **Anonymised** (the content is blanked, the row and statistics stay) or **Permanently deleted** (removed; cannot be undone).
3. Switch on **Automatically enforce retention**.
4. Press **Save changes**.

From that night on, KiddieTrac clears only the lines marked **Enforced**: chat messages, announcements, suspended
families, call recordings, GPS trails, and the logs of texts and emails, once they are older than your period.

> ⚠️ Deletion is permanent. Agree the periods with your team, and check them twice, before you switch this on.

## Asking parents to read your privacy notice

1. Open the **🔒 Privacy & consent** tab.
2. Turn on **Ask parents to read our privacy notice**. Each parent is shown the notice once in the portal and taps "I've read it"; the date is recorded.
3. Enter your **Privacy policy URL** and the **Data-protection contact email** where parents send access or deletion requests.
4. Press **Save changes**. Under the switch you can see who has and has not read the current notice.
5. If you changed the wording of the notice but kept the same link, press **Ask parents again** and confirm with **Ask again**. Every parent is shown it again next time they open the portal.

> ℹ️ Changing the link asks everyone again automatically. Turning the switch off is recorded in the audit log.

Use the **📝 Notes** tab for internal notes on your obligations; only your admins see them.

## Who can do what

| Role | Can |
|---|---|
| Agency admin | Change every setting for the active agency. |
| Centre director, educator | Not available. |
| Platform admin | The agency chosen in the agency switcher. |

## FAQs and when something goes wrong

??? Nothing was deleted after I saved a shorter period. | Either **Automatically enforce retention** is off, or the line is **Policy only**. Policy-only records are never deleted automatically.
??? Can I get back records that were permanently deleted? | No. Choose **Anonymised** if you may need the rows later.
??? "Could not save" | Check the numbers are whole and not negative, then try again. If it keeps happening, raise a ticket from Help.
??? Do parents get an email when I ask again? | No. They see the notice the next time they open the portal.

## Related screens

[[open: #privacy-requests | Privacy requests]] [[open: #privacy-breaches | Privacy breaches]] [[open: #audit-logs | Audit log]]

See also [[privacy-requests]] and [[privacy-breaches]].
