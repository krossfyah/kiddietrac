---
title: Forms-only parents
category: Enrolment
order: 3
roles: agency_admin, platform_admin
---
# Forms-only parents

When you send forms to a parent who isn't in KiddieTrac yet, a **temporary, forms-only account** is made for them so they can sign. Until their family is added, that account belongs to no family. This page explains how those accounts are linked, flagged and tidied up.

[[open: #notification-settings | Open Notification settings]]

## Linking them to their family

Add the family as usual with the **New family** wizard. On the **Guardians** step:

- Type the parent's name or email. If a forms-only account matches, a yellow **Forms-only account found** box appears with their email and forms signed.
- Press **Link to this family**, check the details in the popup, and press **Use this account**. The guardian is filled in from the account.

Or simply use the **same email** they received the forms at. Either way, when the family is saved the account joins it, with every form they signed. If they had never been sent a login, they're invited like any new guardian. The link is recorded in the audit log.

See [Adding a family](adding-a-family).

## In the digest

Until they're on a family, these parents are listed in the admin digest email under **🧾 Parents with forms but no family yet**: name, email, forms signed, and the date an unused account will be removed. Only agency admins get this section.

## The list and the setting

Sidebar → **Settings** → **Notifications** → **🧾 Forms-only parents**. Only agency admins can change it.

- **Remove unused accounts after … days** — default **30**. Enter **0** to never remove them. Press **Save**.
- The table lists each parent with their forms (**signed / assigned**) and a status:
  - **In use — add their family** — they've signed in or signed a form.
  - **Unused · removed** *(date)* — never signed in and never signed a form.
- **Remove** takes an account away now. Their login is removed; forms they already signed stay on record. Only do this if they are not joining.

## Automatic removal

Every night, unused forms-only accounts older than your limit are removed. An account somebody has used — signed in, or signed a form — is **never** removed automatically. It stays on the list until you add the family or remove it yourself.

??? Will removing an account delete their signed forms? | No. The forms stay on record.
??? Where is Settings → Notifications? | Sidebar → **Settings** → **Notifications**. Each kind of reminder has its own tab; **🧾 Forms-only parents** is one of them.
