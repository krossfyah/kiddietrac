---
title: Immunization report
category: Reporting
order: 25
---
# Immunization report

**Reports → Immunizations.** Every child, what they have had, and what is due —
against the schedule set in **Immunization due at age**.

## Reading it

Each child is a row with a status:

- **Up to date** — every dose the schedule expects by their age is recorded.
- **Due** — a dose is expected now.
- **Overdue** — a dose was expected before now.
- **Nothing recorded** — no immunization record at all for this child.

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
