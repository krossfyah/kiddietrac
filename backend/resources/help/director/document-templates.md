---
roles: agency_admin, platform_admin
title: Document templates
category: Settings
order: 40
screens: document-templates
---
# Document templates

**Settings → Document templates** controls how payslips and invoices look when they are
printed or emailed. Bring in a layout you already use, preview it with sample figures, and
switch it on when it looks right. Until you do, documents use the built-in layout.

[[open: #document-templates | Open Document templates]]

## Bringing in a layout you already use

1. Open **Document templates** and press **⤵ Import a template**.
2. Under **Document**, choose **Payslip** or **Invoice**.
3. In **Call it**, give it a name your team will recognise, for example "Our payslip 2026".
4. Paste the layout into **Template**. A Blade file or plain HTML both work.
5. Press **Translate**. The layout is translated, never run.
6. Read the result. A green box says **✓ Translated. Nothing executable was carried across.** An orange box lists anything that could not be carried over, so you know what to finish by hand.
7. Press **Preview it** to see it filled in with sample figures, then **Save template**.

The template joins the list as **Not in use**. Saving never changes a document by itself.

## Editing a template

1. Press **✏️ Edit** on its row.
2. Change the **Name** or the **Layout**.
3. Under **Fields you can use — click to insert:**, click a field name to put it into the layout where your cursor is. Only these fields exist; anything else stays blank.
4. Press **Preview** to see it filled in with sample figures and your agency's name.
5. Press **Save**.

## Switching a template on

1. Press **👁 Preview** on its row one last time.
2. Press **✅ Use this one**.
3. Confirm "Use this template for every document of that kind from now on?".

A **Now using that template** message appears and its status changes to **In use**. Only one
template per document is in use at a time, so the one before it goes back to **Not in use**.

## How the layout language works

A template is HTML with placeholders:

- `{{ name }}` puts in a value, made safe for the page.
- `{{{ name }}}` puts in a value as-is, for a block of markup the system supplies.
- `{{#if x}} … {{#else}} … {{/if}}` shows a section only when there is something to show.
- `{{#each rows}} … {{/each}}` repeats a section for every line, such as each pay line.

The template runs no code and does no sums. Every total, date and amount is worked out and
formatted before it reaches the template, so a template can never break a payslip's
numbers or show anything it was not given.

## Reading the list

| Column | Meaning |
|---|---|
| **Name** | What you called it. "imported with notes" means some parts could not be carried over. |
| **Document** | Payslip or Invoice. |
| **Scope** | **This agency** for your own, **Platform default** for the shared starting layout. |
| **Source** | Where it came from. |
| **Status** | **In use** or **Not in use**. |
| **Updated** | When it was last saved. |

## Who can do what

Agency admins (and platform admins) use this screen. Centre directors, educators and parents
never see it; they only see the payslips and invoices it produces.

## FAQs and when something goes wrong

??? The list says "No templates yet." | Payslips and invoices use the built-in layout until you add one. Press ⤵ Import a template.
??? There is no Delete button on a row. | A template that is in use, or a platform default, cannot be deleted. Switch another one on first.
??? Does deleting a template change payslips already sent? | No. Documents already issued keep their own record.
??? How do I go back to the built-in layout? | If your list has a Platform default row, switch it on with ✅ Use this one. Otherwise contact KiddieTrac support to switch your template off.
??? "Could not translate". | The pasted text could not be read as a layout. Check you pasted the whole file, then press Translate again.
??? A field shows blank in the preview. | The name is not one of the fields listed under Fields you can use. Click the field there to insert the right name.

## Related screens

[[open: #payroll | Payroll]] [[open: #email-templates | Email templates]]

- [Email templates — changing the wording](email-templates)
