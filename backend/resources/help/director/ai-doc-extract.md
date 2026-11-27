---
title: AI document extraction
category: Administration
order: 51
roles: agency_admin, centre_director, platform_admin
screens: ai-docs
---
# AI document extraction

**Marketing › AI doc extract** reads a photo or scan of a document and pulls out its key fields (names, dates,
numbers) so you do not have to type them. It is useful for new staff certificates, immunization records, police
checks, ID and paper enrolment forms. You copy what it finds into the right record yourself — nothing is saved
automatically.

[[open: #ai-docs | Open AI doc extract]]

## Reading a document

1. Take a clear photo or scan of the document (PNG, JPEG or WebP), flat and in good light.
2. Open **Marketing › AI doc extract**. The heading reads **AI document extraction**.
3. Under **Upload a file (image)**, choose the picture.
4. Pick the **Document type**: **Immunization record**, **Staff certification**, **Background / VSS check**, **ID document** or **Enrollment form**.
5. Press **Extract fields**. "Extracting…" shows while it reads; then the fields appear in the dark box, one per line (for example the name, the date issued and the expiry date).
6. Check each value against the paper, then type or paste it into the child's or staff member's record.

> ⚠️ Always check the result against the original. Handwriting, glare or a cut-off edge can give a wrong date or name.

## Using a link instead of a file

1. Leave the file box empty.
2. Paste a link in **…or a document URL (publicly reachable)**.
3. Pick the **Document type** and press **Extract fields**.

> ⚠️ Only use a link to a document that is meant to be public. Never put a child's or staff member's personal document on a public link — upload the file instead.

## Who can do what

| Role | Can |
|---|---|
| Agency admin | Use the tool. |
| Centre director | Use the tool. |
| Platform admin | Use the tool in the agency chosen in the switcher. |

The tool uses AI. The document is sent for reading only to get the fields back; KiddieTrac does not attach it to any record.

## FAQs and when something goes wrong

??? I get "Error: …" in the box. | The picture could not be read. Try a sharper photo, a different file type (PNG or JPEG), or a smaller file.
??? Some fields are empty. | The tool could not find them on the document. Type them in yourself.
??? Is the document saved anywhere? | No. Upload the original to the person's record if you need to keep it.
??? The menu item is missing. | AI tools depend on your plan. Ask your agency admin.

## Related screens

[[open: #compliance | Compliance dashboard]]

See also [[immunization-report]] and [[staff-training-and-checks]].
