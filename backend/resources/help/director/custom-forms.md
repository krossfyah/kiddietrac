---
roles: agency_admin, platform_admin
title: Custom forms builder
category: Daily Operations
order: 68
screens: admin-forms
---
# Custom forms builder

**Administration → Custom forms** lets you build your own online forms, question by
question: registration, consent, surveys, feedback, even a form that takes a payment.
Families fill them in from their parent portal and every answer comes back here, ready to
read or download.

[[open: #admin-forms | Open Custom forms]]

> 💡 Custom forms are forms you **build** on screen. To send a ready-made PDF for people to sign, use **Forms Manager** instead.

## Building a new form

1. Open **Custom forms** and press **+ New form**.
2. Type the **Form title**, for example "Field-trip permission".
3. Write the **Description (shown to parents above the form)**: what the form is for and when it is due.
4. Choose the **Audience**: **All families**, **Currently enrolled**, **Waitlist**, **Prospects** or **Staff only**.
5. Leave **Status** on **Draft (only you see it)** while you build.
6. Under **Fields**, a first question is already there. Type its label in **Field label (visible to respondent)** and pick its type.
7. Add more questions with the buttons under the list: **+ Short text**, **+ Long text**, **+ Email**, **+ Number**, **+ Date**, **+ Dropdown**, **+ Checkbox**, **+ Multiple choice**, **+ ✍️ Signature** and **+ 💳 Payment**.
8. Press **Create form**. The form appears in the list with a **DRAFT** label.

### Setting up each question

- **Required**: tick it when the question must be answered.
- **Placeholder (optional)** and **Help text (optional)**: hint text inside and under the box.
- **Options (one per line)**: for **Dropdown** and **Multiple choice**. A question needs at least two options; the form will not save with fewer.
- **Amount to charge ($)**: for **💳 Payment**. When the family submits, the charge is recorded against their account.
- **✍️ Signature** gives the respondent a pad to sign with a finger or the mouse. The signature is saved with their answers.
- The small arrows move a question up or down; the red **×** removes it.

### Showing a question only when it matters

From the second question on, each question has **🔀 Show only when another field has a specific value**.

1. Tick it.
2. In **— pick a field —**, choose the earlier question it depends on.
3. Type the answer that should reveal it, or leave **equals (leave blank = any non-empty)** empty to show it whenever the earlier question has any answer.

For example, ask "What is the reason?" only when "Will your child attend?" is answered "No".

## Publishing a form and telling families

1. Click the form's row (or **⋮ > Edit form**).
2. Change **Status** to **Published (live for audience)**.
3. Press **Save changes**. The row now shows **LIVE**, and families in the audience see the form in their portal.
4. To email them the link, press **⋮ > Email to parents** and confirm. A green message says how many parents it went to.

> ⚠️ Only a published form can be emailed. A draft says "Publish this form first — only published forms can be emailed to parents."

Press **⋮ > Preview** at any time, even on a draft, to see the form exactly as families
will, with your logo and colours when white-label branding is on.

## Reading the answers

1. Press the **responses** pill on the form's row (for example **3 responses**), or **⋮ > View responses**.
2. Each response shows who sent it and when, then every answer beside its question. Signatures show as a picture; a payment shows as an amount **authorised**.
3. Press **⤓ Download CSV** to save every response as a spreadsheet, one column per question.

Use the **🔍 Search forms…** box above the list to find a form by its title. Long lists are
split into pages of eight.

## Retiring or deleting a form

- To stop taking answers but keep the form: edit it, set **Status** to **Archived (hidden, kept for records)**, and press **Save changes**.
- To remove it: **⋮ > Delete form**, then confirm. The responses already received are kept.

## Who can do what

| Role | What they can do |
|---|---|
| Agency admin | Build, publish, email, preview and delete forms; read and download every response. |
| Auditor | Has a read-only **Forms** entry in their menu for the same forms. See [Custom forms for auditors](forms-auditor-view). |
| Parent | Fills in published forms whose audience matches their family, from their portal. |
| Centre director, educator | No access to the builder. |

## FAQs and when something goes wrong

??? A family cannot see my form. | Check it says LIVE, and that the Audience matches them: a family on the waitlist does not see a form for Currently enrolled.
??? "Field ... needs at least 2 options." | A Dropdown or Multiple choice question has fewer than two options. Add another line under Options (one per line).
??? "Title is required." | Type a Form title before pressing Create form.
??? Can I change a form after people answered it? | Yes. Answers already given are kept with the labels they had. Avoid removing questions people have answered if you still need them in the CSV.
??? Who sees a payment? | The amount is recorded on the family's account when they submit. Check it under the family's billing.
??? Does publishing send an email? | No. Publishing makes the form visible in the portal. Only ⋮ > Email to parents sends an email.

## Related screens

[[open: #forms-manager | Forms Manager]] [[open: #edocuments | eDocuments]]

- [Forms Manager and form packages](forms-manager)
- [eDocuments — PDFs every family signs](edocuments)
