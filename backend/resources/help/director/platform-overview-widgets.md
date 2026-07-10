---
roles: agency_admin, platform_admin
title: Platform overview widgets
category: Administration
order: 90
---
# Platform overview widgets

The **Platform overview** screen (visible to platform admins only) renders a SaaS-style executive dashboard with seven KPI tiles, two trend charts, two insight cards, an eighth-tile sessions metric, and a dark-themed **Business metrics** section.

## KPI tiles

Agencies · Centres · Children · Families · Staff · MRR · Sessions (active last 24h).

Each tile has an accent left-stripe colour-coded by category, with a hint line beneath the number.

## Trend charts

**MRR trend** — six-month history. Greenscale bar chart, dollar labels above each bar.

**Agency growth** — six-month history of signups vs cancellations. Each bar coloured by net direction: purple = net positive month, amber = had any cancellations, red = net negative.

## Top agencies + recent activity

**Top agencies by enrolment** — five largest tenants by child count, with brand accent swatches and share-of-leader bars.

**Recent platform activity** — last 10 audit-log rows joined to users so every event shows who did what when, with emoji icons per action type.

## Business metrics section

Dark-themed strip at the bottom with eight tiles:

- **ARR** — annualised recurring revenue
- **ARPA** — average revenue per active agency
- **ARPU** — per enrolled child
- **MRR growth %** — month over month, with absolute dollar delta
- **Churn %** (30d) — cancelled / starting count
- **LTV** — estimated lifetime value
- **NRR (6m)** — current MRR / MRR six months ago
- **Capacity %** — total enrolled / total licensed seats

All inputs are derived from aggregates already loaded for the trend charts (no extra SQL).

## Access

Visible only to users with the `platform_admin` role. Agency admins see their own scoped dashboard at `#dashboard`.
