---
title: Custom roles and permissions
category: Administration
order: 20
---
# Custom roles and permissions

Kiddietrac ships with five built-in (system) roles: Agency admin, Centre director, Educator, Parent / Guardian, and Auditor. For most agencies these are enough — they cover the staff types most childcare operations actually have.

When you need to model staff structures that don't map cleanly to those five — say a Lead Educator who can sign lesson plans but not invoices, or a Bookkeeper who can run reports but not modify centres — you create a **custom role** with a tailored permission matrix.

## Where to find it

Sidebar → **Settings → Roles & permissions**. You'll see a two-column screen: role list on the left, permission matrix on the right.

## Reading the matrix

The right pane shows the currently selected role's name, description, and a grid of 45 permission keys grouped into 14 categories (Centres, Families & children, Staff, Rooms, Invoicing & billing, Reports, Incidents, Medical, Observations & curriculum, Attendance & kiosk, Documents, Communications, Agency settings).

Each permission has a short label in monospace (e.g. `centres.edit`) and a plain-language description (e.g. "Edit centre settings and branding"). Tick the box for any permission the role should have.

Below each category, click **Toggle all** to flip every permission in that category at once — useful for "this role gets everything in this area, nothing in others".

## System roles vs custom roles

System roles (badged grey **SYSTEM**) can have their permissions tuned but not their name, key, or existence changed — they're referenced by the underlying code. Custom roles (badged blue **CUSTOM**) are fully editable and deletable.

## Creating a custom role

1. Click **+ New** at the top of the role list.
2. Type a name (e.g. "Lead Educator").
3. The new role appears with no permissions checked. Tick the ones it should have.
4. Click **Save changes**.

The role is now visible in the User management Manage modal — assign it to users the same way you'd assign a system role.

## Assigning a custom role to a user

User management → click **Manage** on the user → in the Roles section, select the role from the dropdown → click **Apply**. Roles are additive — adding a new role does not remove existing ones.

## Phase A note

In the current version, permissions stored on custom roles are recorded but not yet enforced — every existing route check uses the original role keys. Phase B (a planned rollout) will switch those checks to consult the permission matrix. Until then, treat the matrix as documentation of what each role SHOULD be able to do — useful for audit purposes.
