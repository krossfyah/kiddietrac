---
title: Filing an immunization record
category: Compliance
order: 33
roles: agency_admin, centre_director, platform_admin
screens: immunizations
---
# Filing an immunization record

The **Immunizations** screen shows which children have a dose overdue or due soon, the
records families and staff have handed in, and the schedule every child is measured
against. Filing a card and recording what it says is one action: the doses you tick move
the child off the overdue list. Open it from the sidebar: **Operations** → **Immunizations**.

[[open: #immunizations | Open Immunizations]]

## The four tabs

- **Due at age** — every child, with a status worked out from their date of birth against your schedule.
- **Overdue only** — the same list, showing only children with a dose overdue.
- **Records received** — every record handed in, marked **Parent** or **Staff**, with **Sent in by**.
- **Schedule defaults** — what every child is measured against. Changing a row re-computes every child's status. See [Immunization "Due At Age" schedule](immunization-due-age).

## Filing a record for a child

1. Press **↑ Upload a record**. It is on every tab, and also on a child's record → **Immunization** tab, where the child is already chosen.
2. Choose the child.
3. Add **the record** — a PDF or a photo of the card, up to 10 MB. A phone photo is normally well under.
4. Under **What does this record cover?**, tick each dose the card shows, against your agency's schedule. A date is optional: leave it blank when the card is unclear and the dose still counts as recorded.
5. If the card shows something that is not on your schedule (a family that moved provinces, a travel vaccine), add it underneath: type the vaccine and the dose label.
6. Add **Notes** for anything the record does not say: a faded line, a dose the clinic is confirming.
7. Press **File record**. The child's status updates straight away.

A dose already on the child's record is **skipped, not duplicated**, so re-filing a clearer
photo of the same card never doubles the history.

## What happens when you file it

- The document is filed on the child's record, so it also appears on their **Documents** tab.
- Each dose you ticked is recorded against the child, **joined to the document it was read off**.
- If the ticks clear everything the schedule expects by their age, the child stops being overdue.
- **The office is emailed**: your other directors and agency admins are told which child, by whom, which doses were recorded, which were skipped as already known, your notes, and **what is still outstanding afterwards**. You are not emailed about your own filing.
- The team is notified in the app, and an audit row is written **naming every dose**.

## Adding details to a record that arrived with nothing recorded

Most cards come from parents. A parent can tick what they think it shows, but that only
points you to it — it records no dose. Until somebody at the centre reads it, the record
shows **Details pending**, with whatever the uploader listed marked *not confirmed*.

1. Find it under **📄 Records on file** on the child, or **Immunizations → Records received** for everybody's.
2. Press **➕ Add details**.
3. Choose what the document is: **Doses given** (an immunization card or a clinic printout) or **Exemption form** (a statement of belief or a medical exemption — see [Immunization exemptions](immunization-exemptions)).
4. For doses, tick each dose it shows, with a date if the card has one. Anything the uploader ticked is pre-ticked and marked *uploader ticked this*; check each one.
5. Press **Record doses** (or **Record exemptions**).

Once a record has doses against it, **Add details** is no longer offered on it; correct a
dose on the child's own record instead.

[[show: #immunizations @ Records received | Show me Records received]]

## The child's Immunization tab

A child's Immunization tab has two sub-tabs: **💉 What is due** (the schedule, and what is
outstanding) and **📄 Records on file** (the cards themselves, with the doses read off each
one and any notes). **View** opens a record in the portal's document panel, with **Print** in
its header — it does not open a new tab. See
[Opening and printing documents](opening-and-printing-documents).

## Who can do what

- **Agency admins and centre directors** for the child's centre can file a record, record the doses off it, add details to one that arrived bare, and edit or delete a dose.
- **Parents can send a record in** and say what they think it shows, and that is all. Reading a smudged line as "DTaP-IPV-Hib, 2nd dose" is the centre's judgement to make.
- **Educators** don't manage immunizations; they see "Immunizations are managed by the centre director."

## FAQs and troubleshooting

??? A parent sent a card but the child still shows overdue. | Only the doses recorded from it clear the flag. Open it under Records received, press ➕ Add details and record the doses.
??? It says "No children to file against". | No enrolled children were returned for this agency. Check the children are enrolled and you are in the right agency.
??? The upload failed. | The file must be a PDF or a photo up to 10 MB. Try a smaller photo, or refresh the page and try again.
??? It says "Vaccine name required". | When you add a vaccine that is not on your schedule, type its name before saving.
??? Can I delete a record? | A parent cannot. Staff remove a filed record through the child's Documents tab, which is audited. Correct a dose on the child's own record; the Immunization report only reads.

## Related

[[open: #immunizations | Immunizations]]
[[open: #reports | Reports]]

- [Immunization exemptions](immunization-exemptions)
- [Immunization reminders](immunization-reminders)
- [Immunization report](immunization-report)
- [Immunization "Due At Age" schedule](immunization-due-age)
- [The child record](child-record-tabs)
- [Opening and printing documents](opening-and-printing-documents)
