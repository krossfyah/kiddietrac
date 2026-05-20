---
title: Bulk actions and CSV exports
category: Administration
order: 59
---
# Bulk actions and CSV exports

Most admin lists support multi-select bulk actions and a one-click CSV download.

## CSV downloads

A green **⤓ CSV** button appears on these screens:

| Screen | Filename | Columns |
|---|---|---|
| **Administration → User management** | `users-YYYY-MM-DD.csv` | ID, Name, Email, Phone, Status, Roles, Last login, Created |
| **Administration → Families** | `families-YYYY-MM-DD.csv` | ID, Family name, Centre, Email, Phone, Address, City, Children, Guardians, Outstanding balance |
| **Administration → Audit log** | `audit-log.csv` | When, Action, Entity type, Entity ID, Actor, Email, IP, Payload |
| **Custom forms → responses modal** | `<title>-responses-YYYY-MM-DD.csv` | Submitted at, Submitter, Email + one column per schema field |
| **Bulk invoice run** | `invoices-YYYY-MM-DD.csv` | Invoice #, Family, Status, Period, Issued, Due, Subtotal, Subsidy, Discount, Tax, Total, Paid, Balance |

All exports use UTF-8 BOM so Excel opens special characters cleanly.

## Bulk actions on Users

The Users tab has a checkbox column. Select multiple rows to reveal a bulk-action bar:

- **Resend welcome** — fires `/admin/users/{id}/resend-welcome` per row
- **Delete** — soft-deletes each user (asks for confirmation first)

A select-all checkbox lives in the header. Your own user row's checkbox is disabled so you can't bulk-delete yourself.

## Bulk actions on Families

Switch the Families tab to **Table** view (toggle in the toolbar). Same checkbox pattern + an amber bulk-action bar with **Delete** that soft-deletes family rows. Children + audit history are preserved — only the family-level row is removed.

## Pattern

Both the CSV download buttons and the bulk-action bars use the same helpers (`downloadCsv()` for downloads, sequential `Api.delete` / `Api.post` for bulk actions). Adding the same pattern to other admin lists is trivial — open a support request if you want it on a list that doesn't yet have it.
