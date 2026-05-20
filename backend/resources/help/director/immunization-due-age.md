---
title: Immunization "Due At Age" schedule
category: Compliance
order: 32
---
# Immunization Due At Age

Auto-flag vaccines as overdue based on each child's age. No more manual review.

## How it works

Each agency has a schedule of vaccines + doses with target ages in months:

| Vaccine | Dose | Due at age |
|---|---|---|
| DTaP-IPV-Hib | 1st dose | 2 months |
| Pneumococcal | 2nd dose | 4 months |
| MMR | 1st dose | 12 months |
| Varicella | 1st dose | 15 months |
| MMRV | 2nd dose | 48 months |
| DTaP-IPV | 5th dose (booster) | 60 months |
| … |

The system pre-seeds the **Canadian NACI** schedule on first load. Edit / add / remove items per agency.

## Per-child status

For each child, the system compares their age to the schedule + their administered records (in the `immunizations` table) and assigns one of:

- ✅ **done** — record exists
- 🔵 **future** — child not yet at the age
- 🟡 **due_soon** — child within 2 months of due
- 🔴 **overdue** — child past the due age, no record
- ➖ **exempt** — exemption recorded

## Agency-wide report

Sidebar → **Immunization due** (admin/director):
- Children with overdue doses (red)
- Children with due-soon doses (amber)
- Tap a child to see full schedule vs. their records

## Notifications

Overdue children's guardians get a weekly reminder until either a record is entered or an exemption is filed.

## Editing the schedule

Add an agency-specific item (e.g. seasonal flu shot at 24 months) — appears alongside the NACI defaults. Mark items as not-required if your centre allows them as optional.
