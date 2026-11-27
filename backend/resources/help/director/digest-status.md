---
title: AI digest status — checking each child's daily digest
category: AI
order: 52
roles: agency_admin, platform_admin, centre_director
screens: digest-status
---
# AI digest status — checking each child's daily digest

Every evening KiddieTrac writes a short **daily digest** for each child from what staff recorded that day (meals, naps, moods, activities, observations). Parents see it on their home screen as **✨ YOUR DAILY DIGEST**. **Operations → AI digest status** shows, child by child, whether today's digest has been written, and lets you write a missing one now or write one again after records were corrected.

[[open: #digest-status | Open AI digest status]]

## Checking today's digests

1. Open **AI digest status**. The page opens on today's date.
2. Read the four tiles at the top: **Children** (children in your centres), **Generated** (with the coverage percentage), **Pending** (records exist, digest not written yet) and **No events** (nothing recorded, so nothing to summarize).
3. The line under the title says **AI enabled** or **AI disabled (templated fallback)**. When AI is disabled, families get a simpler digest built from a template.
4. Look down the table. Each row shows the **Child**, the number of **Events** recorded, the **Status** badge (**GENERATED**, **PENDING** or **NO EVENTS**), when it was **Generated at**, and a **Preview** of the text.

> ℹ️ Digests are written once in the evening. During the day most children show **PENDING**: that is normal.

## Looking at another day

1. Click the date box in the top-right corner of the page.
2. Pick the day. The page reloads with that day's digests.
3. Press **Refresh** to read the list again, for example after a staff member added missing records.

## Writing a digest now, or again

1. Find the child in the table.
2. Press **Generate** (no digest yet) or **Regenerate** (a digest already exists). The button shows **Working...** while the AI writes.
3. A window opens titled **Digest for** the child, with the new text. Read it.
4. Press **Close**. The table refreshes and the row shows **GENERATED**.

> ⚠️ **Regenerate** replaces the digest the family sees. If a parent has already read the old one, they will see the new wording next time they open the app.

[[show: #digest-status @ Refresh | Show me the Refresh button]]

## Who can do what

| Role | What they see |
|---|---|
| Centre director | Children in their own centres |
| Agency admin | Children in every open centre of the agency |
| Platform admin | The same, for the agency they are working in |
| Educators and parents | No access. Parents read the digest itself on their home screen |

Closed centres and children who have left are not listed.

## FAQs and when something goes wrong

??? The Generate button is greyed out. | The child has no events recorded that day (**No events to summarize**). Add the day's records first, then press **Generate**.
??? A child shows PENDING late in the evening. | The evening run may not have reached them yet, or the AI did not answer. Press **Generate** for that child.
??? An error appears after pressing Generate. | The AI service may be busy or not set up. Wait a few minutes and try again. If it keeps failing, raise a support ticket; families still receive the templated digest.
??? "No children in scope". | No enrolled children were found for that date in your centres. Pick another date, or check enrolments.
??? "Could not load digest status". | Reload the page. If it keeps happening, raise a support ticket.
??? Does regenerating send the parent a new notification? | No. The digest is updated in place; the parent sees it the next time they open their home screen.

## Related screens

[[open: #care-log | Daily log]]

See also: [AI tools](ai-tools), [Agency notifications](agency-notifications).
