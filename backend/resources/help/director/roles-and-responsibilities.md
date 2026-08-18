---
title: Roles & responsibilities
category: Getting Started
order: 5
---
# Roles & responsibilities

KiddieTrac has six built-in roles. Each role sees a different sidebar, different screens, and different actions. Custom roles can be created on top (see [[custom-roles-and-permissions]]).

> **New this release:** Every data table and card list now shares the same look — a **⋮ actions menu** on each row (View / Edit / Delete, and more), a bottom **record-count bar**, and **zebra striping**. New admin/director powers, all reached from the ⋮ menu: **suspend, reactivate or inactivate a family** (blocks or restores that family's parent logins), add **internal notes to incident reports** (audit-trailed — who wrote what, when), **edit or delete staff certifications**, and **delete field trips**. **Data-retention auto-enforcement** now clears old chat messages *and* announcements nightly when you enable it (Settings → Data retention). Times display in your centre's timezone everywhere.
>
> **Earlier:** every role has a **Tasks** experience (admins/directors assign; educators, home visitors and parents get **My tasks**), and desktop **Messages / chat** is a minimisable window with inline voice notes, pasted screenshots (Ctrl/⌘-V), and delete-your-own-message.

## Platform admin
You. The owner of the KiddieTrac platform. Cross-agency access:

- All agencies + agency switching from the header
- Platform overview dashboard with MRR + ARR + business metrics
- Create / suspend / resume any agency
- White-label branding controls (logo, colour, footer)
- Feature flags per agency
- All audit logs across every agency
- Billing dashboard for Stripe Connect platform fees

## Agency admin
Full control of one childcare agency:

- Centres, families, children, staff at every level
- Billing settings (late-fee config, tuition increases, payment plans)
- Marketing campaigns + drip campaigns
- Compliance dashboard, audit log, inspection prep
- All XLSX exports + bulk imports
- Stripe + QBO + Twilio + Anthropic integration config
- Custom forms builder + custom report builder
- Roles & permissions matrix
- **Assign & track Tasks** for educators, home visitors and parents (Staff → Tasks)
- **Suspend / reactivate / inactivate families** (Families → ⋮) — suspend temporarily blocks the family's parent logins; reactivate restores them; inactivate archives the family (children + history are kept)
- **Manage staff certifications** — add, edit or delete (Certifications → ⋮)
- **Internal incident notes** — add audit-trailed notes to any incident report
- **Data-retention auto-enforcement** — optional nightly purge of old chat + announcements (Settings → Data retention)

## Centre director
Day-to-day management of one centre:

- Family + child records at their centre
- Schedule + time-clock + payroll for their staff
- Observations + lesson plans + curriculum
- Daily care logs, photos, videos
- Approve time-off + vacation holds + reenrollment + permission slips
- CACFP meal tracking, wellness screening review
- Field-trip GPS monitoring, plus **plan and delete field trips** (Field trips → ⋮)
- Marketing campaigns (no global setting changes)
- **Assign & track Tasks** for the educators (and parents) at their centre (Staff → Tasks)
- **Suspend / reactivate / inactivate families** at their centre (Families → ⋮)
- **Add internal notes to incident reports** (audit-trailed) and **manage staff certifications**

## Educator
Classroom focus:

- Today screen (sign-ins, ratios, current room)
- Observations, lesson plans, daily logs
- Photo + video uploads
- Chat with parents (voice notes, pasted screenshots, delete your own messages)
- Time clock for their own punches
- Personal time-off requests
- Care logs + milestone tracking
- **My tasks** — work assigned to you by your director/admin; mark it in progress or done

## Guardian / parent
Their own family only:

- Today screen for their children
- Photos, videos, observations, milestones (their kids only)
- Daily wellness check-in + drop-off survey
- Chat with centre staff
- Billing (invoices, wallet, autopay, ledger)
- Payment plans, vacation hold requests, refund visibility
- Pickup authorizations + family directory opt-in
- Forms to sign + document workflows
- Conferences + field-trip permission slips
- Refer a friend + send feedback / NPS
- Chat with staff (voice notes, pasted screenshots, delete your own messages)
- **My tasks** — anything the centre has asked you to do

## Home visitor
An agency-attached practitioner who visits families/centres and files reports:

- Home-visit reports against any centre in the agency (view, edit drafts, branded PDF download)
- Inspection forms (Monthly Monitoring + Ministry checklist) where enabled
- Chat with families & staff
- **My tasks** — work assigned to you by an admin/director
- Notifications, settings, help

## Custom roles
Beyond these five built-in roles, agency admins can create their own custom roles with picked permissions (see [[custom-roles-and-permissions]]). Useful for assistant-director, billing-only, marketing-only, etc.
