---
roles: agency_admin, platform_admin
title: Platform admin (overview)
category: Administration
order: 35
---
# Platform admin (overview)

Platform admin is the highest-tier role on Kiddietrac. It sits above the per-tenant Agency admin role and grants oversight of every agency on the platform — useful when you operate Kiddietrac as a SaaS for multiple unrelated childcare brands.

If you have this role, you'll see a purple **Platform** section at the top of your sidebar with two entries: Platform overview and All agencies. The agency switcher button gains a small purple **PLAT** badge.

## What platform admins can do

- See every user on the platform in User management, not just users in the active agency.
- Switch into any agency in seconds via the switcher — no per-tenant invitation needed.
- Provision a brand-new customer agency via Platform → All agencies → + Create agency.
- Suspend or resume an agency's billing status from the All Agencies table.
- See cross-agency totals (MRR, signups, churn) on the Platform overview screen.

## Platform-only tools

A few screens exist only for platform admins, because they concern the whole platform
rather than one agency:

- **View as** at the foot of the sidebar: preview a role, or see the portal as one named
  person. See [View as another user](view-as).
- **Settings → Backups**: the nightly database backup and whether it is running. See
  [Database backups](database-backups).
- **Sales → Files repository**: price sheets, decks and contract templates for the sales
  side. See [Files repository](files-repository).
- **Settings → Security alerts**: see [Security alerts & monitoring](security-alerts).

## Licences and billing status

An agency's plan and add-ons live on its licence, in **Platform → Licences**.

- Each agency is one group. Click its header (or press Enter or Space on it) to open or close
  its plan and add-on lines. Closed, the header lists the add-ons as chips beside the term, line
  count and total. **Expand all** and **Collapse all** work on the whole list, and your choice for
  each agency is remembered on this computer.
- A group opens by itself only when the list is filtered to that agency, when the agency is in its
  grace period or has no live plan, or when its licence ends within 30 days without auto-renew.
- The **⋮** on an agency's header holds **Convert to paid** (trials), **Renew all**, **Term &
  renewal**, **＋ Add add-on** (or **＋ Add plan**), **Included centres** and, last, **Cancel
  licence**. The **⋮** on each line holds **Edit** (price, quantity, note) and, for add-ons,
  **Remove add-on**.
- **All agencies → Edit → Plan** shows **Plan & add-ons** read-only: the plan, every add-on with
  its part number and price, and the renewal date. **Manage in Licences →** opens Licences
  filtered to that agency. Change the licence there, not in the Edit popup.
- A licence or feature change reaches the agency's open tabs without anyone signing out.

**Billing status follows the licence.** A paid or complimentary plan makes the agency
**active**, a trial makes it **trial**, the grace period keeps it **active** (renewal invoices
keep being raised), and no live plan makes it **suspended**. The status is read-only in
**My agency → Manage** and **Features**. **Suspend** holds the agency suspended through every
licence change until you press **Resume**. **Pin the status** in **Manage** keeps an agency on
**trial** or **active** whatever its licence says (used for demo agencies); a pin never starts
billing, and a Suspend still wins.

## What platform admins cannot do (yet)

- Modify a tenant's data without first switching into that agency. The active-agency context still scopes most write operations.
- Run billing for the platform itself (Stripe integration is per-tenant).

## Granting platform admin to another user

From User management → click **Manage** on the user → in the Roles section, pick **Platform admin** in the role dropdown and click **Apply**. Note: only an existing platform admin can grant the role — there's no privilege escalation path from agency_admin upward.

## Removing platform admin

To remove the role, contact your platform support contact. The Manage modal currently adds roles additively for safety; revoking a role is a deliberate manual step.
