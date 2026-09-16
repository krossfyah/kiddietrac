---
title: Timesheets and correcting hours
category: Staff Management
order: 31
---
# Timesheets and correcting hours

**Staff → Timesheets.** Every shift worked at your centres in a date range, per person,
with the total across the top. This is the sheet payroll is built from — the same punches
**Payroll** reads when it works out what somebody is owed — so a wrong time here becomes a
wrong figure on a payslip.

## Choosing what you are looking at

Three controls, all of which apply the moment you change them:

- **Centre** — one provider, or *All centres*, which means every centre you can see
  rather than every centre in the agency.
- **From** and **To** — the period. Type them in either order; the dates are swapped for
  you if you fill them right to left. It opens on the last 30 days.
- **🔄** re-reads the range, **⬇️** downloads it as a CSV for your payroll provider.

## Reading a row

Each row is one shift: the date, who worked it, in, out, break and hours.

- **An open shift** shows an em dash for the clock-out and **0h**. Nobody pressed clock
  out. It is deliberately *not* totalled — an unfinished shift is something to chase, not
  something to pay — and it is deliberately not hidden either.
- **"No hours logged"** is a person who was rostered to your centre and clocked nothing in
  the period. A zero row is a question worth asking; a missing row is invisible.
- **Break** reads 0 min for everybody. The current clock does not record breaks, and the
  hours are stated unadjusted rather than presented as though a break had been deducted.

## Correcting a shift

Each row carries the standard **⋮** menu at its right-hand end. On a phone it sits at the
bottom of the row's card.

### Edit hours

Opens the shift with its clock-in and clock-out ready to change, and a box for the reason.

- Times are the **centre's clock**, not your device's. The dialog says which. An admin
  working from another timezone types the time the person actually worked.
- Leave the clock-out empty to leave a shift open; fill it in to close one that was
  never clocked out.
- The reason is optional here and worth writing anyway — it is stamped on the shift
  itself, so the next person to read the row can see why it changed.

The change is refused, with the reason on screen, if the clock-out is not after the
clock-in or the shift comes to more than 24 hours. A shift that long is almost always the
wrong date on one end, and quietly paying it is worse than refusing it.

### Add a shift

For the shift that was never clocked: a flat tablet, a forgotten press, somebody covering
at short notice. Available on every row, because a split shift is a second punch for
somebody who already has one, and it is the **only** thing offered on a "No hours logged"
row.

- **Centre** says where the shift is filed, and therefore which payroll pays it. It
  matters for anybody posted across several sites; it is not guessed for you.
- A **reason is required**. A hand-entered shift is a claim about hours somebody will be
  paid for, and the note on the shift says who entered it and why.
- Overlapping an existing shift is refused — that would double-count the hours.

### Remove shift

For a shift that never happened: a double punch, a clock-in on somebody else's tablet, an
entry typed against the wrong name. Editing answers "these times are wrong"; this answers
"this is not real".

It asks for a reason and cannot be undone. The audit log keeps the whole removed shift —
the times it held and its notes — because once it is gone there is nothing else to read.

## Who can do this

A director or agency admin, for the centre the shift was **worked at**. That is the centre
recorded on the punch itself, not wherever somebody's staff record happens to be filed
first — so for a person posted across several sites, the director of the site they
actually worked is the one who can fix it.

## What is recorded

Every correction, entry and removal writes an audit row naming the person, the times
before and after, and who made the change. Find them in
**Administration → Audit log** as *Staff hours corrected*, *Staff shift entered by hand*
and *Staff shift removed*. The shift itself also carries a short note, so the row on the
timesheet says it was corrected without anybody having to go and look.

## Worth knowing

- Correcting a punch here is the same correction as the one on the person's own user
  record, writing to the same place — there is one story about a shift, not two.
- An educator cannot edit their own hours. Their clock only toggles, which is why a shift
  left open overnight can only be fixed from here.
- If somebody tells you they forgot to clock in, this is the screen to fix it on.

See also: [Staff time clock](time-clock), [Payroll runs, hours and payslips](payroll-documents),
[Payroll-ready CSV export](payroll-csv), [Auto sign-off](auto-sign-off).
