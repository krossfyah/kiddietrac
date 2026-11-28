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

1. Take a clear photo or scan of the document (PNG, JPEG, WebP or PDF), flat and in good light.
2. Open **Marketing › AI doc extract**. The heading reads **AI document extraction**.
3. Under **Upload a file (photo, scan or PDF)**, choose the file.
4. Pick the **Document type**: **Immunization record**, **Staff certification**, **Background / VSS check**, **ID document** or **Enrollment form**.
5. Press **Extract fields**. "Extracting…" shows while it reads; then the fields appear as a list, each with its label (for example the name, the date issued and the expiry date).
6. Check each value against the paper, then type or paste it into the child's or staff member's record.

> ⚠️ Always check the result against the original. Handwriting, glare or a cut-off edge can give a wrong date or name.

## Your file stays private

The file itself is read: it is sent straight to the AI for this one reading and is never put at a public link. A link to a document is not accepted. KiddieTrac keeps the file privately so **Auto-link** can attach a staff certificate to the person's record.

## Who can do what

| Role | Can |
|---|---|
| Agency admin | Use the tool. |
| Centre director | Use the tool. |
| Platform admin | Use the tool in the agency chosen in the switcher. |

The tool uses AI. The document is sent for reading only to get the fields back; it is not put at any public link.

## FAQs and when something goes wrong

??? I get "Error: …" in the box. | The picture could not be read. Try a sharper photo, a different file type (PNG or JPEG), or a smaller file.
??? Some fields are empty. | The tool could not find them on the document. Type them in yourself.
??? Is the document saved anywhere? | Yes, privately, for Auto-link: a staff certificate linked with **Auto-link** keeps the file on the person's record. It is never at a public link.
??? The menu item is missing. | AI tools depend on your plan. Ask your agency admin.

## Related screens

[[open: #compliance | Compliance dashboard]]

See also [[immunization-report]] and [[staff-training-and-checks]].
