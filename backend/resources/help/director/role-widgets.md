---
title: Role-specific dashboard widgets
category: Getting Started
order: 12
---
# Role widgets on your dashboard

When you load the dashboard, a row of KPI cards appears at the top of your role's home view (just under the hero banner). The cards are role-specific — they show you the numbers most relevant to what you do day-to-day.

## What each role sees

**Guardian / parent (4 cards):**
- Today's check-in status (signed in / signed out / not yet)
- Outstanding family balance
- Observations shared with you this week
- Number of children on file

**Educator (4 cards):**
- Children signed in now / total enrolled at your centre
- Meds given today
- Observations you logged this week
- Total enrolled at your centre

**Centre director (4 cards):**
- Capacity % today (colour-coded green / amber / red)
- Open invoice dollars + count
- Children not signed in by 9:30 AM (suppressed before cutoff)
- Total enrolled

**Agency admin / platform admin** — you already have a richer agency-wide dashboard, so the role widgets skip silently.

## How they update

Cards re-fetch from `/widgets/me` every time you load a dashboard-y hash (`#dashboard`, `#today`, etc). They don't auto-refresh while you sit on the page — refresh the browser to pull fresh numbers.

## Why some cards say "—"

Empty agency, no enrolled children, or no role assignments. The cards always render; the placeholder dash means "no data, not a bug".
