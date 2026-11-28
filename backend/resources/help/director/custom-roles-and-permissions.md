---
roles: agency_admin, platform_admin
title: Custom roles and permissions
category: Administration
order: 20
screens: admin-roles
---
# Custom roles and permissions

**Roles & permissions** lists KiddieTrac's built-in roles (Agency admin, Centre director, Educator, Home visitor, Auditor and the others) and your agency's own **custom roles**. A custom role is your own job title, for example *Lead educator* or *Bookkeeper*, **based on** one built-in role. It starts with everything its base can do, and you turn off what this job should not do. Whatever you turn off leaves the person's menu and is refused if they try it anyway. See [[roles-and-responsibilities]] for what each built-in role does.

[[open: #admin-roles | Open Roles & permissions]]

> ℹ️ A custom role can only take things away. Who and which centres it covers always comes from its base: a custom role based on Educator sees only the centres and children that educator is placed with, never more.

## Reading the roles list

1. Open **Settings › Roles & permissions** (🛂 in the menu).
2. Read the table: each role's **Type** (Built-in or Custom), what a custom role is **Based on**, its **Permissions** ("3 turned off", or "Everything its base has") and how many **People** hold it.
3. Press **⋮** on a built-in role and choose **👁 View** to see every permission it has, grouped by area.

## Creating a custom role

1. Press **＋ New custom role**, or press **⋮** on the built-in role closest to the job and choose **⧉ Copy as custom role**.
2. Type a **Name** (for example "Lead educator") and pick **Based on**. Agency admin and Auditor cover the whole agency; Centre director, Educator and Home visitor cover the centres each person is placed at.
3. Add an optional **Description**.
4. Under **What this role can do**, untick each permission this job should not have. Use **All on / off** beside a group (Everyday, Children & families, Daily care, Staff & HR, Money and the others) to change the whole group at once.
5. Watch **What this person will see** on the right: the menu items the role hides are struck through in red.
6. Press **Create role**. A **Role created** message appears and the role joins the table.

## Changing, copying or deleting a custom role

1. Press **⋮** on the custom role.
2. Choose **✏️ Edit** to change its name, description or permissions, then press **Save changes**. People who hold it get the new menu the next time their screen refreshes.
3. Choose **👁 Preview menu** to see the menu its holders get, **⧉ Duplicate** to start a new role from this one, or **🗑 Delete** to remove it.

> ⚠️ A role that anyone holds cannot be deleted, and its **Based on** cannot change. Give those people another role in **User management** first.

## Giving a custom role to someone

1. Open **User management** and open the person.
2. Beside **Add / set role**, pick the custom role (marked **★**, under **Your custom roles**) and, for a centre role, the **Centre**.
3. Press **Apply**. The person shows a **★** pill with the role's name. The custom role applies at every centre where they hold its base role in this agency.
4. To take it away, pick the built-in role again and press **Apply**.

## Who can do what

| Role | What they can do |
|---|---|
| Agency admin | Creates, edits, copies and deletes custom roles for their agency and gives them to staff. Cannot change built-in roles. |
| Platform admin | Everything above, can tune the description of a built-in role, and sees the **🔎 Permission check** tab. |
| Centre director and others | Do not see this screen. |

### The permission check (platform admins)

The **🔎 Permission check** tab lists what custom roles were refused, and compares the built-in roles' permission lists with what people really did (built-in roles are watched, never blocked). Use **Turn off watching** or **Turn on watching** to stop or start the watch.

## FAQs and when something goes wrong

??? Does a custom role change anything for people on built-in roles? | No. Built-in roles behave exactly as before. Only people who hold a custom role are narrowed.
??? Can a custom role do more than its base? | No. It can only turn things off. To give someone more, give them a different built-in role.
??? Someone sees "Your role … does not include: …". | Their custom role has that permission turned off. Edit the role and tick it, or give them another role.
??? The person has two roles. Which one wins? | If they also hold a built-in staff role in the same agency, that built-in role's full access applies and the custom role narrows nothing.
??? "… people hold … (counting deactivated accounts)." | The role cannot be deleted while anyone holds it, even a deactivated account. Give those people another role in User management first, then delete it.
??? Someone sees "This part of KiddieTrac is not set up for custom roles yet". | A screen is new and has no permission yet, so custom roles are kept out of it until KiddieTrac adds one. Tell support; give the person their built-in role meanwhile if they need it now.
??? "Keep at least one permission ticked." | A role with nothing ticked would open nothing. Tick at least one permission.
??? "Built-in roles are managed by KiddieTrac." | You tried to change a built-in role. Copy it as a custom role instead.
??? "Could not load roles" appears. | Your session ended or no agency is selected. Pick the active agency in the top bar and refresh the page.
??? Is anyone told when I change a role? | No. Every change is recorded in the audit log; nobody is emailed or notified.

## Related screens

[[open: #admin-users | User management]] [[open: #audit-logs | Audit log]]

- [[roles-and-responsibilities]]
- [[inviting-parents-and-staff]]
- [[audit-log]]
