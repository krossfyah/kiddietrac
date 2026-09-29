---
title: Files repository
category: Administration
order: 37
roles: agency_admin, platform_admin
---
# Files repository

**Sales → Files repository.** A reference shelf for the sales side: price sheets, decks,
contract templates and licensing paperwork. It belongs to KiddieTrac itself, not to any
agency.

[[open: #sales-library | Open the Files repository]]

## Who can use it

**Platform admins (superadmins) only.** It sits in the **💼 Sales** section of the sidebar,
which only a platform admin has, and it is also a tile on the Sales home screen for them.

Sales reps do **not** see it, even though they work in Sales. The shelf holds pricing and
contract material, and the server refuses anyone who is not a platform admin. An agency
admin never sees it either.

## The layout

- **Folders** run down the left. **All files** at the top shows everything, whatever folder
  it is in; each folder shows how many files it holds.
- **The shelf** in the middle lists the files: **File**, **Type**, **Folder**, **Category**,
  **Size** and **Added** (date, time and who added it).
- The search box and sortable column headers are the same ones every other table in the
  portal has. Size and Added sort by the real number and time, not alphabetically.

The **File** column shows the real file name with its extension, with the title underneath
if it differs, and any notes below that. The **Type** column leads with the extension
(PDF, DOCX, PPTX…) and names the format in words under it.

## Adding a file

1. Pick the folder you want first if you like, so the dialog starts there.
2. **＋ Add a file**.
3. Choose the file. Anything up to **25 MB**: a PDF, deck, spreadsheet or image.
4. Fill in the rest:
   - **Folder**: where it lives. *All files (no folder)* is allowed.
   - **Title**: filled in from the file name for you (without the extension).
   - **Category**: pick one already in use, or **＋ New category…** to type a new one.
   - **Notes**: what it is for, and who it is aimed at.
5. **Add to repository**.

[[show: #sales-library @ Add a file | Show me the Add a file button]]

> **Tip:** Name the file to your convention before you upload it, e.g. `KT-2026-Q4-RateCard-v3.pdf`. The title picks that name up, so you never retype it.

The title is only filled in while you have not typed in it yourself. Once you edit the
title, choosing a different file will not overwrite it.

Category is a dropdown on purpose. A free-text box is how one label ends up spelled
three ways ("Pricing", "pricing", "Price sheets"), and the category column stops
grouping anything.

A few file types that a web server could run (for example `.php` or `.sh`) are refused
with *"That file type cannot be stored here."*

## The upload bar

While a file uploads, a dark bar slides down from the top of the screen showing
**Uploading** and the file name, the percentage and how much has been sent. It ends with
**Upload complete** or **Upload failed**.

The bar is not specific to this screen. Every upload in the portal draws it, including
chat attachments, photos, incident files and e-documents. Where the browser cannot report a
size, the bar shows a moving stripe instead of a percentage.

## Folders

- **📁 New folder** creates one. Names are unique regardless of capitals, so "Pricing" and
  "pricing" are the same folder. Asking for one that exists just tells you so.
- **Deleting a folder** (the **✕** beside its name) does **not** delete its files. They move
  back to **All files**, and the confirmation says how many.

[[show: #sales-library @ New folder | Show me the New folder button]]

## Moving, opening and removing a file

Each row has the standard **⋮** menu:

- **View** opens the file.
- **📁 Move to folder** lets you pick another folder, or *All files (no folder)*. You need
  at least one folder first.
- **↩ Remove from folder** only appears on a file that is in a folder. It puts the file back
  at the top level.
- **🗑 Remove** takes the file off the shelf. The stored file itself is kept on the server,
  but there is no button to bring it back, so be sure before you use it.

## What is recorded

Uploading, moving and removing a file, and creating or deleting a folder, each
write an audit row naming the file or folder. These are platform-level events, not tied to
any agency.

## Common questions

??? Can a sales rep see these files? | No. The nav entry, the tile and the server all stop at platform admins.
??? Does deleting a folder lose the files in it? | No. They move back to All files.
??? What is the largest file I can add? | 25 MB.

See also: [Platform admin (overview)](platform-admin-overview),
[Opening and printing documents](opening-and-printing-documents).
