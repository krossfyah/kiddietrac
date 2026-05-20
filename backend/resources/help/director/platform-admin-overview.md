---
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

## What platform admins cannot do (yet)

- Modify a tenant's data without first switching into that agency. The active-agency context still scopes most write operations.
- Run billing for the platform itself (Stripe integration is per-tenant).

## Granting platform admin to another user

From User management → click **Manage** on the user → in the Roles section, pick **Platform admin** in the role dropdown and click **Apply**. Note: only an existing platform admin can grant the role — there's no privilege escalation path from agency_admin upward.

## Removing platform admin

To remove the role, contact your platform support contact. The Manage modal currently adds roles additively for safety; revoking a role is a deliberate manual step.
