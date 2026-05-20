---
title: Command palette (Cmd/Ctrl-K)
category: Getting Started
order: 55
---
# The command palette

Press **`⌘K`** (macOS) or **`Ctrl + K`** (Windows / Linux) from **anywhere** in the portal to open a quick-jump search palette. You can also press **`/`** when you're not already typing in a field.

## What you can do from it

| Type | What it finds |
|---|---|
| Empty | Jump-to-screen shortcuts: Dashboard, Marketing, Calendar, Children, Families, Centres, Users, Audit log, Billing, Help, MFA |
| 2+ chars | Lives matches across **centres**, **rooms**, **families**, **children**, **staff**, plus filtered jump-tos |

Results render grouped by type so a search for `aco` shows everything called Acorn (rooms, children, staff) under their own headings.

## Keyboard

| Key | Action |
|---|---|
| **`⌘K`** / **`Ctrl-K`** | Toggle the palette (works from any screen, even mid-typing) |
| **`/`** | Open the palette (only when you're not typing in another field) |
| **`↑` / `↓`** | Move the highlight |
| **`Enter`** | Open the highlighted result |
| **`Esc`** | Close the palette |

You can also click any row to navigate.

## Who gets the live search

Agency admins and platform admins see live matches across centres, rooms, families, children, and staff. Directors and educators see only the static jump-to-screen shortcuts (their role doesn't have access to the global search backend). Everyone gets the keyboard shortcut.

## Tips

- The search runs against the **active agency** for multi-agency platform admins. Switch agencies via the agency picker (top-right) before searching to scope to a different tenant.
- Results from `/admin/search` are capped at 5–8 per type, sorted by relevance, so the palette stays fast even on large portfolios.
- If the palette feels slow, check your network — each search is one HTTP round-trip with a 180ms debounce.
