---
roles: agency_admin, platform_admin
title: Multi-day attendance pattern
category: Enrollment
order: 36
---
# Attendance pattern

Declare which days your child normally attends. Used for ratio calculations and tuition projections.

## Setting the pattern

1. Sidebar → **Attendance days**.
2. Pick the child (if more than one).
3. Tap each day-of-week tile that they attend (Mon–Sun).
4. Pick an **Effective from** date (defaults to today).
5. Save.

## What changes

- The **Today** screen on the staff side only shows children expected to attend that day.
- **Room ratios** calculate based on expected attendance, not total enrolled.
- **Pro-rated tuition** uses the pattern to calculate fair monthly costs (e.g. 3-day/week kids billed proportionally vs 5-day kids).

## Effective-from history

When you change a pattern, the old one is preserved with `effective_until = new pattern's effective_from`. This means the staff schedule from 6 months ago still reflects the pattern at that time — useful for audit.

## Common patterns

- **Full-time**: Mon-Fri yes, Sat/Sun no
- **Part-time / Tue-Thu**: Tuesday + Thursday only
- **Alternating**: Mon/Wed/Fri vs Tue/Thu
- **Weekend care**: Sat-Sun yes (for shift-worker families)
- **Holiday week only**: blank pattern + manual reservation

## When attending an unscheduled day

Tap the staff schedule and add a one-off entry — doesn't change the recurring pattern.
