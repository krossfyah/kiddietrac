---
title: Staff calendar
category: Daily Operations
order: 75
---
# Staff calendar

Open the full calendar from **Staff → Calendar** in the sidebar. Directors and agency admins can see and edit every shift across their centres.

## Two views

- **Week** — Monday–Sunday with one column per day. Today's column is tinted blue. Shifts appear as colour-banded pills with time, staff name, room, and role.
- **Month** — 5-row calendar grid. Leading and trailing days from neighbouring months are greyed. Each cell shows up to three compact chips; if more shifts exist that day, a small "+ N more" indicator appears.

Toggle between them with the segmented control at the top of the toolbar. Your preference is remembered in the browser (localStorage `kt_cal_view`).

## Filtering

Two dropdowns sit beside the view toggle:

- **Centre** — agency admins see every centre in their agency. Directors with one centre don't see this dropdown.
- **Role** — show only Lead / Support / Floater / Volunteer shifts. Default is *All roles*.

## Adding a shift

- Click any empty area in a day cell — opens the **New shift** modal with the date pre-filled.
- Or click **+ Add shift** in the toolbar to open the modal blank.

The modal collects staff member, room, date, start time, end time, and role. **Save** to commit.

## Editing or deleting

Click any shift pill or chip. The **Edit shift** modal opens with the same fields. A red **Delete** button appears at the bottom-left of the modal when editing.

Both actions take effect immediately when you save / confirm.

## Role colours

| Role | Colour |
|---|---|
| Lead | purple |
| Support | blue |
| Floater | amber |
| Volunteer | green |

The colour appears as a left-edge band on each shift pill and a tinted background.

## Tips

- Drag-to-create across multiple days is not yet supported — click each day cell individually.
- Shifts that span midnight count as belonging to the day they START on (their `starts_at` date).
- The calendar caps at 100 days of data per request so very long ranges paginate naturally.
- Use the **Today** button in the toolbar to jump back to the current day at any time.
