---
roles: platform_admin
title: All agencies: adding and managing agencies
category: Administration
order: 5
screens: platform-agencies
---
# All agencies: adding and managing agencies

**All agencies** is the platform team's list of every customer agency on KiddieTrac, with its status, plan and size. From here you add a new agency with a four-step wizard, open any agency to work inside it, edit its details, resend its admin's invite, suspend or resume it, and lock sign-in for the whole agency.

[[open: #platform-agencies | Open All agencies]]

## Reading the list

1. Open **Platform › All agencies**.
2. Read the table: **Name**, **Status** (for example trial, active or suspended), **Plan** (with its monthly amount), **Centres**, **Families**, **Children**, **Created** and **Actions**.
3. Press **Open** on a row to switch into that agency. You land on its home screen and its menu shows that agency's plan and add-ons.

## Adding a new agency

1. Press **+ Create agency** (top right). The **🌐 Create new agency** wizard opens at "Step 1 of 4".
2. **Plan & logo**: pick the **Plan** (**Starter — $49/mo**, **Growth — $149/mo (white-label included)** or **Enterprise — $349/mo (white-label included)**). Optionally press **Upload logo** (up to 2 MB) and set the **Primary colour**. Press **Next →**.
3. **Agency details**: fill in **Agency name**, **Address line 1**, **Address line 2** (optional), **City**, **Province**, **Postal code**, **Country (residence — sets currency & compliance)**, **Default language**, **Contact email** and **Contact phone**. Press **Next →**.
4. **Admin**: fill in the first agency admin's **First name**, **Last name**, **Email** and **Phone**. Press **Next →**.
5. **Review**: check everything (use **← Back** to fix a step), then press **✓ Create agency**.
6. Read the confirmation: "… is ready. Provisioned on a 30-day trial with a default centre." It also shows the admin's invite link in case the email is delayed. Press **Done**.

> ℹ️ The default centre is named after the agency and uses its address. The agency admin renames it or adds more centres under **Centres**.

## Editing an agency

1. Press **✏️** (**Edit agency**) on the row.
2. Use the tabs: **Details** (name, contact, owner), **Address** (legal name, website, address, country, default language), **Email** (the agency's own outgoing mail server, with **✉️ Send test**), **Plan** and **White-label** (logo, primary colour, support email, **Enable**).
3. On **Plan**, read the agency's licence and add-ons. Press **Manage in Licences →** to change the plan, add-ons or term; they are not changed here.
4. Press **Save changes**.

## Resending the admin's invite

1. Press **✉️** (**Resend invite**) on the row.
2. The **✉ Invite sent** box shows who it was emailed to and the set-password link.
3. Press **Copy link** if you want to send it to them another way, then **Done**.

## Suspending or locking an agency

1. Press **⏸️** (**Suspend**) and confirm "Users will lose dashboard access immediately." Press **▶️** (**Resume**) to give access back.
2. Press **🚷** (**Sign-in lock**), add a note and press **Lock sign-in** to stop every staff member and parent of that agency signing in. **Unlock sign-in** in the same box lifts it.

## Who can do what

| Role | What they can do |
|---|---|
| Platform admin | Everything on this screen. |
| Reseller | Adds agencies under their own brand on **Onboard an agency**, not here. |
| Agency admin | Does not see this screen; sees only their own agency under **My agency**. |

## FAQs and when something goes wrong

??? The wizard says "Required: …". | A required field on that step is empty. Fill in the fields it names and press **Next →** again.
??? "Could not create the agency" with a message. | Read the message (for example the admin's email is already used), press **← Back to review**, fix it and press **✓ Create agency** again.
??? The admin never got the invite. | Press **✉️** (**Resend invite**) and send them the link yourself with **Copy link**. Check **Audit log › 📧 Email log** to confirm the email was sent.
??? "Upload failed" or "Max 2 MB" under the logo. | Use a PNG, JPG or WebP under 2 MB. You can also add the logo later on the **White-label** tab.
??? Why can't I change the plan when editing? | The plan and add-ons come from the agency's licence. Use **Manage in Licences →**.
??? What do families and staff get when an agency is suspended? | No email is sent; they simply cannot reach the dashboard until you resume it.

## Related screens

[[open: #licences | Licences]] [[open: #resellers | Resellers]] [[open: #audit-logs | Audit log]]

- [[managing-resellers]]
- [[white-label-branding]]
- [[plan-and-add-ons]]
