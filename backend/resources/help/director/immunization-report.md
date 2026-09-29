---
title: Immunization report
category: Reporting
order: 25
---
# Immunization report

**Reports → Immunizations.** Every child, what they have had, and what is due —
against the schedule set in **Immunization due at age**.

## Reading it

Each child is a row with a status, worst first:

- **Overdue** — a dose was expected by their age and is not recorded.
- **Nothing on file** — no doses recorded for this child. A card filed with nothing read
  off it yet still counts as nothing.
- **Due soon** — a dose falls due within the next three months.
- **Up to date** — every dose the schedule expects by their age is recorded.
- **Exempt** — an exemption is recorded for the child. The reason is in the **Exemption**
  column.

The row also names the overdue and due-soon doses, how many doses are on file, and the
date of the last one.

> **Note:** A child shows **Exempt** as soon as any one dose carries an exemption. Check the **Overdue** column on those rows — a partial exemption does not cover the rest of the schedule. See [Immunization exemptions](immunization-exemptions).

## Matching doses

Records and schedules rarely word doses identically: a schedule may say "4th dose
(booster)" where the record says "4th dose". The report matches them on the vaccine and
the dose number, ignoring wording, brackets and ordinal suffixes — so a child is not
reported overdue for a dose they were given, which is what naive matching did.

## Worth knowing

- Print or export it like any other report, for a licensing visit or a ministry return.
- A child with nothing recorded is listed rather than skipped: an empty record is the
  thing you most need to see.
- Fix the underlying data on the child's own record — the report only reads.
- To file a card **and** record the doses off it in one action, use **＋ Upload a record**
  on the Immunizations screen or the child's Immunization tab. See
  [Filing an immunization record](filing-an-immunization-record).
- A record sitting on file is not a recorded dose. A parent's upload does not clear a
  due flag until somebody reads the card and ticks the doses.

See also: [Filing an immunization record](filing-an-immunization-record),
[Immunization exemptions](immunization-exemptions),
[Immunization due at age](immunization-due-age).
