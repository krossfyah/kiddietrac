---
roles: agency_admin, platform_admin
title: Document templates
category: Settings
order: 40
---
# Document templates

**Settings → Document templates.** Control what a payslip, invoice or receipt actually
looks like, without waiting on a release.


## Quick steps

1. Go to **Settings → Document templates**.
2. Pick the document type — payslip, invoice or receipt.
3. Either start from the built-in layout, or press **Import** and choose a Blade file to
   convert (this is how iLearn's own designs were brought across).
4. Edit the HTML, using `{{ name }}` for a value and `{{#each rows}}` to repeat a row.
5. **Preview** — it renders against a real record.
6. **Activate** when it looks right. One template per type is active at a time.

If no template is active, documents render with the built-in layout, so activating one
is always reversible.

## How a template works

A template is HTML with placeholders:

- `{{ name }}` — a value, escaped
- `{{{ name }}}` — a value inserted as-is, for a block of markup you supply
- `{{#if x}} … {{#else}} … {{/if}}` — show a section only when there is something to show
- `{{#each rows}} … {{/each}}` — repeat a section for every row

That is the whole language. The renderer runs no code and does no arithmetic: every
total, date and money value is worked out and formatted before it reaches the template.
A template can therefore never break a payslip or leak anything it was not given.

## Importing an existing design

**Import** accepts a Blade file — the format iLearn's payslips and invoices are written
in. It converts the parts a template can express and reports anything it could not, so
you know what to finish by hand rather than discovering a blank line on a payslip.

## Worth knowing

- One template per document type is **active** at a time; the rest are kept.
- The built-in layout is the fallback: if no template is active, documents render as
  they always did.
- Preview before activating — the preview uses a real record.
