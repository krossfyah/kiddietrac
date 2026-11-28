---
roles: agency_admin, platform_admin
title: Custom roles and permissions
category: Administration
order: 20
screens: admin-roles
---
# Custom roles and permissions

**Roles & permissions** shows what each role in your agency can do, as a list of permissions grouped by area. KiddieTrac's built-in roles (Agency admin, Centre director, Educator, Home visitor, Parent / Guardian, Auditor and the others) are listed with a grey **SYSTEM** badge. When your agency needs a different set, for example a Lead Educator or a Bookkeeper, you create a **CUSTOM** role and tick the permissions it should have. See [[roles-and-responsibilities]] for what each built-in role does.

[[open: #admin-roles | Open Roles & permissions]]

## Reading a role

1. Open **Settings › Roles & permissions** (🛂 in the menu).
2. Pick a role in the **Roles** list on the left. Each shows how many permissions it has and, for custom roles, whether it covers **one centre** or the **whole agency**. Agency admin opens first.
3. Read the panel on the right: the role's name, its description and, under **Permissions**, every permission grouped by area (Centres, Families & children, Staff, Daily care, Attendance & kiosk, Medical, Communications, Invoicing & billing, Reports, Agency settings and more). Each permission shows its key, such as `centres.edit`, and what it allows.
4. Read the count at the bottom ("… / … permissions selected"): how many are ticked out of all permissions.

> ℹ️ Built-in roles are managed by KiddieTrac and show what each role can do today. Their boxes are greyed out for agency admins.

## Creating a custom role from a built-in one

1. Pick the built-in role closest to what you need, for example **Educator**.
2. Press **Copy as custom role**.
3. Type the role name (for example "Lead Educator") and press **Create**. The new role starts with the same permissions.
4. Tick or untick permissions. Use **Toggle all** beside an area to tick or clear the whole area at once.
5. Press **Save changes**. A **Role saved** message appears and the count in the list updates.

## Creating a custom role from scratch

1. Press **+ New** at the top of the **Roles** list.
2. Type a name and press **Create**.
3. Pick **Based on** (the built-in role it is closest to, or **— none —**).
4. Pick **Covers**: **One centre** or **Whole agency**. Whole agency is only for roles based on Agency admin; for any other base the screen says so and keeps **One centre**.
5. Tick its permissions, add an optional description, and press **Save changes**.

## Renaming or deleting a custom role

1. Pick the custom role and click its name at the top to edit it.
2. Press **Save changes**.
3. To remove it, press **🗑 Delete role** at the bottom and confirm. The role is archived; anyone given it would need another role.

## Who can do what

| Role | What they can do |
|---|---|
| Agency admin | Reads every role; creates, edits and deletes custom roles for their agency. Cannot change built-in roles. |
| Platform admin | Everything above, can tune built-in roles for an agency, and sees the **🔎 Permission check** tab. |
| Centre director and others | Do not see this screen. |

### The permission check (platform admins)

The **🔎 Permission check** tab compares the permission list with what people really did, and blocks nothing. **Would block — must be zero before enforcing** lists cases the list would refuse today. Use **Turn off** or **Turn on watching** to stop or start the watch.

## FAQs and when something goes wrong

??? Why are the boxes greyed out? | You are looking at a built-in role. Built-in roles are managed by KiddieTrac. Press **Copy as custom role** to make your own version.
??? Does ticking a permission change what people can do today? | Not yet. The permissions are recorded and watched, but access is still decided by each person's built-in role. Use the list to document what each role should do.
??? How do I give a custom role to a person? | Not yet: the role picker in user management offers the built-in roles only. Give the person the closest built-in role for now.
??? "Only a role based on Agency admin can cover the whole agency." | Set **Based on** to Agency admin, or choose **One centre** in **Covers**.
??? "Built-in roles are managed by KiddieTrac." | You tried to save a built-in role. Copy it as a custom role instead.
??? "Could not load roles" appears. | Your session ended or no agency is selected. Pick the active agency in the top bar and refresh the page.
??? Is anyone told when I change a role? | No. Changes are recorded in the audit log; nobody is emailed or notified.

## Related screens

[[open: #admin-users | User management]] [[open: #audit-logs | Audit log]]

- [[roles-and-responsibilities]]
- [[audit-log]]
