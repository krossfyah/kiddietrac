---
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

Then add fields. Eight types supported:

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

Each field has: label, required toggle, placeholder, help text, options (for dropdown / radio).

## Conditional visibility

Any field after the first can be set to "Show only when another field has a specific value". Pick the dependency field and either leave the equals box blank (matches any non-empty value) or enter a specific value.

Example: ask "Why are you opting out?" only when the "Will your child attend?" radio is set to "No".

## Responses

Click the **N responses** chip on a form's row to open the responses modal. Each response shows submitter name, timestamp, and every answer mapped to its label.

The green **⤓ Download CSV** button exports all responses with one column per field, ordered to match the schema.

## Parent side

Guardians see published forms whose audience matches their family status in the **Your child → Forms** sidebar entry. Already-submitted forms show a green ✅ badge.
