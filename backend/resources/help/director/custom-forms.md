---
roles: agency_admin, platform_admin
title: Custom forms builder
category: Daily Operations
order: 68
---
# Custom forms

Build registration, consent, survey, and feedback forms without code. Find it under **Administration → Custom forms**.

## Building a form

Click **+ New form** and fill in:

- **Title** — what staff see in the list
- **Description** — shown to respondents above the questions
- **Audience** — all families / currently enrolled / waitlist / prospects / staff only
- **Status** — Draft (only you see it) / Published (live for audience) / Archived

Then add fields. Ten types supported:

| Type | Use it for |
|---|---|
| Short text | Names, addresses, single-line answers |
| Long text | Multi-line notes, descriptions |
| Email | Validated email format |
| Number | Numeric input |
| Date | Date picker |
| Dropdown | Pick one from a list |
| Checkbox | Yes/no |
| Multiple choice | Pick one from radio buttons |
| ✍️ Signature | A signature pad — respondents sign with finger or mouse |
| 💳 Payment | A charge (set the amount); recorded against the family's account when authorised |

Each field has: label, required toggle, placeholder, help text, options (for dropdown / radio), and — for Payment — an **amount to charge**.

## Conditional visibility

Any field after the first can be set to "Show only when another field has a specific value". Pick the dependency field and either leave the equals box blank (matches any non-empty value) or enter a specific value.

Example: ask "Why are you opting out?" only when the "Will your child attend?" radio is set to "No".

## Responses

Click the **N responses** chip on a form's row to open the responses modal. Each response shows submitter name, timestamp, and every answer mapped to its label.

The green **⤓ Download CSV** button exports all responses with one column per field, ordered to match the schema.

## Preview

Click **👁 Preview** on any form to see exactly what respondents will see —
including your white-label logo and primary colour in the header. Preview works
on Draft forms, so you can check a form before publishing.

## Emailing a form to parents

From a published form, click **✉ Email to parents** to send the form link to the
matching audience. The email goes out from your agency's own address (when email
is enabled and a sender is configured under **Settings → Billing → Settings**).

## White-label

Forms — and the form **Preview** — carry your **logo and primary colour** when
white-label branding is set up. See *White-label branding & invoices*.

## Finding forms and responses

Long lists are searchable and paged: use the **search box** above the forms list
to filter by title, and the **pager** at the bottom of the responses modal to
move through large response sets.

## Parent side

Guardians see published forms whose audience matches their family status in the **Your child → Forms** sidebar entry. Already-submitted forms show a green ✅ badge. Forms with a **signature** or **payment** field prompt for those before they can submit.
