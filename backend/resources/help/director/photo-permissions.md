---
title: Photo permissions
category: Children
order: 30
roles: agency_admin, centre_director, platform_admin
screens: photo-permissions
---
# Photo permissions

**Photo permissions** shows, for every child you can see, what their family allows for photos and videos: **🚫 Do not photograph**, **👪 Family only** or **✅ Group photos OK**, and whether the centre may also use photos in its marketing. Open it before a photo day or a field trip, to record a parent's signed paper form, or to show photos again that were hidden because a child had no permission.

[[open: #photo-permissions | Open Photo permissions]]

## How the permission is decided

- **The primary parent chooses.** Only the answer of the family's primary parent counts. Another parent's earlier answer is still listed, greyed out and marked **(not counted)**. If a family has two primary parents, the more careful answer applies.
- **Silence is not consent.** Until the primary parent answers, the child is **👪 Family only** — their own family still gets photos, but the child is kept out of photos other families see. The row says **until the primary parent answers**.
- **Parents answer in the portal.** A parent who has not answered is asked in a popup when they sign in (it cannot be skipped), on the sign-up wizard, and can change it any time in **My settings › Photos**.
- **Do not photograph means hidden.** A child at **🚫 Do not photograph** cannot be tagged, and photos already tagging them are hidden from every family — kept on file, never deleted.

Marketing is a separate yes/no and is never implied by any photo level.

## Checking a child's permission

1. Open **Administration › Photo permissions**.
2. Read the tiles at the top: **All children**, **Do not photograph**, **Family only**, **Group photos OK** and **Primary parent not answered**.
3. Click a tile to show only those children — for example **Do not photograph** before a group photo, or **Primary parent not answered** to see who to chase.
4. Type in **Find a child…** to search by name or centre.
5. Read the row: **Photo permission** (what applies now), **Parents' answers** (who answered what; **(paper)** means a signed form, **(sign-up)** means from the sign-up wizard), **Marketing** and **Photos** (how many are hidden).

> 💡 Educators see this same list, read-only — they are the ones holding the camera. When a child's permission changes, the educators of the child's room and the centre's directors get a bell notification and a push.

## Recording a signed paper form

Use this when the primary parent filled in a paper consent form instead of answering in the portal.

1. Find the child and press **✍ Paper form** on their row.
2. In **Record a paper form**, choose the parent under **Primary parent**.
3. Under **Their answer**, pick **Photos, including group photos other families see**, **Photos for my family only** or **Do not photograph my child**.
4. Tick **They also agreed to marketing use** only if the form says so (it is not available with **Do not photograph my child**).
5. Under **Where the form is**, say where the signed paper is kept — for example "signed form dated Nov 6, in the child's file".
6. Press **Save**. A **Recorded** toast says "The paper form is on record." and the row updates.

The paper answer counts exactly like an answer in the portal, and the change is written to the audit log.

## Showing hidden photos again

When a family changes from **Do not photograph** to a level that allows photos, the photos that were hidden stay hidden until you release them.

1. Find the child. The **Photos** column shows how many are hidden, and the row has a **Show N hidden** button.
2. Press **Show N hidden**.
3. A **Done** toast says how many photos are showing again. A photo that also tags another child with no permission stays hidden — the toast then reads "Nothing to show: another child on those photos still has no permission."

## Who can do what

| Role | Can |
|---|---|
| Agency admin | See every child in the agency, record paper forms, show hidden photos |
| Centre director | The same, for children in their own centres |
| Educator | See the list for their rooms, read-only |
| Parent | Answer for their child (primary parent only) in **My settings › Photos** |

The same rows also appear inside a family's record and a parent's profile under **Families**.

## FAQs and when something goes wrong

??? There is no ✍ Paper form button on a row. | The family has no parent who can decide — usually no guardian with a portal account is linked yet. Link the parent to the family first; only the primary parent's form can be recorded.
??? "Only the primary parent's answer counts. Record the form the primary parent signed." | You chose a parent who is not the primary parent. Pick the primary parent, or change who is primary in the family's record.
??? "Choose their answer." or "Say where the signed form is kept." | Both are required. Pick one of the three answers and type where the paper is filed.
??? The Show hidden button is missing. | It only appears when photos are hidden and the child's permission now allows photos. A child at **Do not photograph** keeps their photos hidden.
??? A parent says they answered, but the row says "has not answered". | Their answer may not count because they are not the primary parent — it is listed greyed with **(not counted)**. Ask the primary parent to answer, or record their paper form.
??? The list says "No children match." | Click **All children** and clear **Find a child…**.
??? Does saving a paper form email the family? | No. Families are not emailed. The child's educators and the centre's directors get a bell notification when the permission changes.

## Related screens

[[open: #admin-families | Families]]
[[open: #admin-children | Children]]

See also [[privacy-and-data-security]] and [[child-record-tabs]].
