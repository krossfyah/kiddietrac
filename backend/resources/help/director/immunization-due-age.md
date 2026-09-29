---
roles: agency_admin, centre_director, platform_admin
title: Immunization "Due At Age" schedule
category: Compliance
order: 32
---
# Immunization Due At Age

**Immunizations → Due at age.** Each child's recorded doses measured against your agency's
schedule, so overdue vaccines are flagged from the child's age without anybody reviewing
cards by hand.

[[show: #immunizations @ Due at age | Show me the Due at age tab]]

## The tabs

- **Due at age** — the whole roster, each child against the schedule.
- **Overdue only** — the same view, narrowed to children with an overdue dose.
- **Records received** — every immunization record filed for your children, and who sent
  it in. See [Filing an immunization record](filing-an-immunization-record).
- **Schedule defaults** — the schedule itself.

On a child's row, the name or **View immunisations** opens their full schedule next to what
is recorded, and **📄 Records on file** goes straight to the cards filed for them.

## The schedule

Each agency has its own schedule of vaccines and doses, with a target age in months:

| Vaccine | Dose | Due at age |
|---|---|---|
| DTaP-IPV-Hib | 1st dose | 2 months |
| Pneumococcal | 2nd dose | 4 months |
| MMR | 1st dose | 12 months |
| Varicella | 1st dose | 15 months |
| MMRV | 2nd dose | 48 months |
| DTaP-IPV | 5th dose (booster) | 60 months |
| … |

The **Canadian NACI** schedule is filled in for you the first time it is opened. From
there it is yours to change.

### Editing it

On **Schedule defaults**, **+ Add item** adds a row: **Vaccine**, **Dose**, **Due at age
(months)**, **Notes**, and a **Required** tick. Each row's **⋮** menu has **View**,
**Edit** and remove.
Changing a row re-computes every child's status.

- Add agency-specific items (a seasonal flu shot at 24 months, say) alongside the NACI
  defaults.
- Untick **Required** for a vaccine your centre treats as optional. It still shows on the
  schedule, marked **Optional**. The family reminder can be limited to required doses, and
  **Tick all required** on an exemption form ticks only these.

## Per-child status

Each dose on the schedule is one of:

- **Recorded** — a matching dose is on the child's record.
- **Not yet due** — the child is not at that age yet.
- **Due soon** — due within the next two months.
- **Overdue** — past the due age, and nothing recorded.
- **Exempt** — an exemption is recorded, with its reason shown beside it. See
  [Immunization exemptions](immunization-exemptions).

> **Note:** A record on file is not a recorded dose. A card a parent sent in shows as **Details pending** until somebody records the doses from it, and until then the child still shows what is overdue.

## Reminders

Nobody is reminded automatically unless your agency switches it on. Agency admins set up
a team reminder, and optionally a reminder to families, in **Settings → Email settings →
Immunization**. Exempt doses are never included. See
[Immunization reminders](immunization-reminders).

See also: [Filing an immunization record](filing-an-immunization-record),
[Immunization exemptions](immunization-exemptions),
[Immunization report](immunization-report).
