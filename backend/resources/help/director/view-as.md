---
title: View as another user
category: Administration
order: 36
roles: agency_admin, platform_admin
---
# View as another user

**Sidebar foot → View as….** Lets a platform admin see the portal exactly as a named
person sees it (their screens *and their data*), and then return to their own session.

## Who can use it

**Platform admins only.** The controls appear at the foot of the sidebar, under your name
and the agency switcher, and nobody else has them. The server refuses anyone who is not a
platform admin.

## Two controls, two different things

There are two "View as" controls at the foot of the sidebar, and they do different jobs.

| Control | What it changes | Whose data you see |
|---|---|---|
| **👁 View as** (a dropdown of roles) | The menus and screens, as that *role* would have them | Still yours |
| **View as…** (a button that opens a people picker) | Everything. You are signed in as that *person* | Theirs |

Use the role dropdown to check what a role's menu looks like. Use **View as…** when you need
to see what one particular parent, educator or director is actually seeing, such as for a
support ticket that says "I can't see my child".

## Previewing a role

Pick a role from the **👁 View as** dropdown. The page reloads on that role's home screen and
the control turns purple, reading **👁 Viewing as**, so it is hard to miss. Choose
**Super admin (default)** to go back.

The platform-only sidebar sections (Platform, Sales, Reseller, Website) are hidden while you
preview another role, because that role would never see them. Help & guides also switches to
that role's articles.

## Viewing as a person

1. Press **View as…** at the foot of the sidebar.
2. Search by name or email, and narrow by **Parents**, **Educators**, **Directors** or
   **Admins** if you like. Each result shows the person's role, agency and centre.
3. Press the person's row (**View →**).

The portal reloads as that person, on their home screen, in their agency.

This is a **real sign-in as that person**, not a costume. Every screen asks the server for
*their* data, with *their* permissions. What you see is what they see, and anything you do
is done with their account.

> **Important:** You are acting as them. Look, don't change. A message sent, a form signed or a record edited while viewing as somebody is done under their name.

## The exit banner

While you are viewing as somebody, a dark orange pill sits at the top of every screen:
**👁 Viewing as *name* · *role*** with an **Exit** button.

**Exit** puts your own session back (your account, your active agency and any role preview
you had) and reloads the dashboard. You do not need to sign in again.

## Worth knowing

- **It only affects this browser tab.** Your own session is set aside in the tab and restored
  on Exit. Another tab where you are signed in stays as you.
- **The borrowed session lasts two hours.** After that it stops working; press **Exit** and
  start again if you still need it.
- **Every start is audited.** The audit log records who started viewing as whom, with the IP
  address and device.
- **A role preview is remembered for your account on this device**, so your next sign-in
  opens in the same preview. Set it back to **Super admin (default)** when you are done.

## Common questions

??? Does the person know I viewed as them? | They are not notified. The start is written to the audit log.
??? Why can't an agency admin do this? | Seeing another person's data as them is a platform-level power, so only platform admins have it.
??? I previewed a role and now my menu is missing items. | You are still previewing. Set the View as dropdown back to Super admin (default).

See also: [Platform admin (overview)](platform-admin-overview),
[Audit log](audit-log), [Managing more than one agency](multi-agency-switching).
