---
title: Reference IDs for children, families and staff
category: Administration
order: 30
---
# Reference IDs for children, families and staff

Every child, family, staff member and parent in your agency has a reference ID, like **ILH-C-0042**. Use it to find a record fast, to quote on the phone, and to match people across documents and spreadsheets.

## How an ID is built

- **Your agency's letters**, for example **ILH**. By default these are the initials of your agency's name.
- **A type letter:** **C** child, **F** family, **S** staff, **P** parent or guardian.
- **A number** that counts up within your agency, for each type.

Every new record gets its ID automatically. Numbers are never reused and never renumbered, even when a child is withdrawn or a family leaves.

A person who is both staff and a parent at your agency has two IDs: an **S** one and a **P** one.

## Where IDs appear in the portal

You see an ID in a small grey box. Press the copy button (**⧉**) beside it to copy it.

- **Children list and child record:** under the child's name, labelled **Child ID**. The family section of the record shows the **Family ID**.
- **Families list and family details:** under the family name, with each child's ID beside it.
- **User management:** under each person's name. Their record shows their **Staff ID**, **Parent ID**, or both.
- **Your own IDs:** **Settings → Profile → Your KiddieTrac ID**.

Educators see each child's ID on their own children list and on the child's page.

## Searching by ID

Type an ID into the **🔍 Search…** box at the top of the sidebar, or press **Ctrl + K** (**⌘K** on a Mac) to open the command palette. Results show the ID beside each name, and parents now have their own **Parents** group.

The search is forgiving. All of these find **ILH-C-0042**:

- the full ID: `ILH-C-0042`
- spaces or no dashes: `ilh c 42`, `ILHC42`
- the type and number: `C-0042`, `c42`
- just the number: `0042` or `42`
- the start of an ID: `ILH-C-00` lists the IDs that begin that way

A plain word is treated as a name, so typing a surname still works as before. The same loose matching works in the filter box above lists, so you can paste an ID to narrow a table.

Who can find what:

- **Agency admins** search the whole agency.
- **Centre directors** search their own centres only.
- **Platform admins** must pick an agency first.
- **Educators** can type a child's ID into the search on their own children list.

> **Tip:** An ID printed with your agency's old letters still finds the record, so last year's forms keep working after a prefix change.

## Changing your agency's letters

Only an **agency admin** (or a platform admin) can change the prefix.

1. Go to **Agency overview → ✏️ Edit agency → Open Branding →**.
2. Open the **🌎 Region & IDs** tab.
3. In **Reference ID prefix**, type 2 to 6 letters or digits, starting with a letter.
4. Press **Save prefix**. The examples under the box update to show the new IDs.

[[open: #admin-branding?tab=region | Open Branding → Region & IDs]]

The change applies to every ID at once. Two agencies cannot use the same letters. The change is recorded in the audit log.

## IDs on documents

IDs are printed on the documents you produce, so a page can always be matched to its record:

- **Emergency card:** the child's ID and the family's ID.
- **Incident report:** the child's ID. The staff copy also shows the ID of the person who recorded it.
- **Invoices:** the family's ID beside the bill-to name, in every invoice style.
- **Receipts, account statements and the family ledger:** the family's or account holder's ID.
- **CWELCC monthly report:** each family's ID.
- **Portfolio and report card:** the child's ID.
- **Payslips and payroll documents:** the staff member's ID. Payroll document templates can use the **{{payee_id}}** placeholder.
- **Signed forms:** the signer's ID beside the date signed, when the form was signed in the portal. Forms signed through a no-login link do not have one.

## IDs in exports

CSV exports and reports now include an ID column, so you can match rows between spreadsheets. This includes the children, families and staff exports, payroll, subsidies, CWELCC, sleep checks, background checks, policy acknowledgements, form submissions, workforce funding, OCCMS and Ohio PFCC, the built-in reports and timesheets. In the children export, the ID column shows the reference ID.

??? Will a new invoice number format use these IDs? | No. Invoice numbers have their own format under Branding → Invoices. See [Invoice style and numbering](invoice-numbering-and-style).
??? Can I give a child a particular number? | No. Numbers are given in order and never change, so an ID always points to the same record.
??? A director searched for an ID and got nothing. | Directors only find records at their own centres. An ID from another centre returns no result.

See also: [White-label branding](white-label-branding) · [Bulk actions and CSV](bulk-actions-and-csv) · [Command palette](command-palette)
