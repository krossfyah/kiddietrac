---
title: Invoice style and numbering
category: Billing
order: 16
roles: agency_admin, platform_admin
---
# Invoice style and numbering

**Branding → Invoice style / Invoice numbering.** This is where you choose how your invoices look and how they are numbered. It sits beside your logo and brand colour, because the invoice is your brand as far as a family is concerned.

[[open: #admin-branding | Open Branding]]

**To get there:** agency admins go to **Agency overview → ✏️ Edit agency → Open Branding →**. Platform admins can also use **Reseller → Branding**. Either way, the screen works on the agency you are currently viewing.

## Invoice style

[[show: #admin-branding @ Invoice style | Show me the Invoice style list]]

Pick a style from the **Invoice style** list. A short description of the style appears under the list, and the **Live invoice preview** on the right switches to that style straight away, so you can see it **before** you save.

There are twelve styles:

- **KiddieTrac (default)**: the standard KiddieTrac invoice.
- **iLearn**: the iLearn layout, for agencies whose families already receive that document.
- **Classic Navy**, **Minimal Mono**, **Modern Teal**, **Bold Slate**, **Warm Sand**, **Sunrise**, **Forest**, **Plum Rail**, **Compact Receipt** and **Corporate Grey**.

These are different layouts, not one layout in different colours. Every style still carries **your own logo, business details and support address**: the style decides the shape of the document, and the brand inside it stays yours.

Click **Save changes** to apply it.

> **Important:** The style is applied when an invoice is **drawn**, not when it is issued. After you change it, every invoice, past and future, uses the new style the next time it is viewed, downloaded or emailed. Copies already sitting in a family's inbox do not change. So a copy of an old invoice that you send again will not match the one the family first received.

## Invoice numbering

[[show: #admin-branding @ Invoice numbering | Show me Invoice numbering]]

You can set the format of your invoice numbers, and where a running number starts. Either choose one of the presets from **Start from a preset…**, or type your own format in the box.

A format is ordinary text with tokens in curly brackets. Anything that is not a token is printed exactly as typed, so `ILH-{YYYY}-{SEQ}` is a valid format.

| Token | Becomes |
|---|---|
| `{YYYY}` | the year, e.g. 2026 |
| `{YY}` | the short year, e.g. 26 |
| `{MM}` | the month, e.g. 10 |
| `{DD}` | the day |
| `{FAMILY}` | the family number, four digits |
| `{N}` | the instalment number within a schedule or plan, two digits |
| `{SEQ}` | a running number for your agency |

**Next invoice will look like** shows a sample built from your format as you type. It is produced by the same code that numbers real invoices, so it matches what you will get.

**The dates are the billed period, not the day the invoice goes out.** An instalment due in December is a December invoice, even if it is issued in late November.

### The running number, {SEQ}

- **Start numbering at** sets the first `{SEQ}` value. The default is **1001**.
- The number of digits you start with is kept, with no extra zeros added in front. If you start at 1001, the next is 1002. If you start at 5000, the first is 5000.
- You can **raise** the start later to jump the counter forward. You can **never lower** it. That would reissue numbers that real invoices already carry.
- The counter is only used when your format includes `{SEQ}`, so a date-and-family format does not use up numbers.

### Good to know

- The default format is `INV-{YYYY}{MM}-{FAMILY}-{N}`, for example **INV-202610-0081-01**. If you never change the setting, your numbers look exactly as they always have.
- A new format applies to **new** invoices only. Existing invoices keep the numbers they were issued with.
- No two invoices can share a number. If a format produces a number that already exists, a suffix such as **-2** is added.
- A token that is misspelled, such as `{MONTH}`, is dropped instead of being printed on a family's invoice. Check the sample before you save.
- Invoices that are synced in from an outside billing system keep that system's own numbers.

## Common questions

??? Which invoices use my numbering? | Invoices raised in KiddieTrac: monthly batches, payment-schedule instalments and fee-plan invoices.
??? Why is my new start number being ignored? | The counter never moves backwards. If it has already passed the number you typed, it carries on from where it is.
??? Can I preview a style without changing it for everyone? | Yes. Choosing a style updates the preview immediately. Nothing changes until you click Save changes.

See also: [White-label branding & invoices](white-label-branding) · [Billing setup & settings](billing-setup) · [Payment schedules](payment-plans)
