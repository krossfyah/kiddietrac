---
title: The waitlist (leads and enquiries)
category: Enrolment
order: 12
roles: agency_admin, centre_director, platform_admin
screens: synced-waitlist
---
# The waitlist (leads and enquiries)

**Waitlist** is where you keep families who have asked about a space but are not in KiddieTrac yet: a parent's name, their child, how to reach them, when they would like to start and how urgent it is. When a space opens you email them an update, approve or decline them, and turn them into a real family with one press. Some entries may be **Synced** from a connected enrolment system; the ones you add here are marked **Added here** and flow back to that system automatically.

[[open: #synced-waitlist | Open Waitlist]]

> ℹ️ This is the agency's list of *leads*. A child who is already in KiddieTrac and waiting for a room shows under **Children** with the status **Waitlist** instead.

## Adding someone to the waitlist

1. Open **Enrollment › Waitlist**.
2. Press **＋ Add to waitlist** at the top right.
3. Fill in **Parent name** (required), and as much as you know of **Child name**, **Email**, **Phone** and **Desired start**.
4. Choose a **Priority**: **High**, **Normal** or **Low**.
5. Add any **Notes** — siblings, schedule wanted, how they heard about you.
6. Press **Add**. A **Saved** toast appears and the entry joins the list as **Waiting**, marked **Added here**.

The tiles at the top count **On waitlist**, **Approved**, **Declined** and **Added here**. When families from a closed centre are waiting to be placed, a **Need a new home** tile appears too.

## Emailing a family an update

1. Find the entry and open its **⋮** menu.
2. Choose **📣 Reach out**.
3. Type a **Subject** (or leave it — the default is "An update on your waitlist enquiry") and your **Message**.
4. Press **Send update**.

The family receives a branded email from your agency, a **Sent** toast says "Update emailed to the parent.", and the entry's status changes to **Contacted**. The entry needs an email address — without one you see "This lead has no email address on file."

## Approving, declining or editing an entry

1. Open the entry's **⋮** menu.
2. Choose **✅ Approve** or **🚫 Decline**. The status badge changes and a toast confirms it. Nothing is sent to the family.
3. To change details, choose **✏️ Edit**, update the fields in **Edit waitlist entry** and press **Save**.
4. To remove an entry for good, choose **🗑 Delete** and confirm **Delete**. It cannot be recovered.

## Turning a lead into a family

1. Open the entry's **⋮** menu and choose **🎓 Enrol parent**.
2. Read the confirmation: it creates a family (and the child, if a child name was given) in KiddieTrac and starts the enrolment process.
3. Press **Enrol**. An **Enrolled** toast says "Family created — enrolment started." and the entry's status becomes **Enrolled**.
4. In the **Family created** box, choose **Stay here** to carry on with the list, or **Open Families**.
5. In **Administration › Families**, open the new family to add the room, start date, fee and the rest of the child's details, and invite the parent.

The family is created at a centre you manage: an agency admin's goes to the agency's first centre, a director's to their own first centre. The parent's email becomes the family's primary parent.

## Placing a family from a closed centre

When a centre closes, an agency admin can move its families (with no child still enrolled) to this waitlist. Their rows say **Previously at** the old centre and show **🏠 Assign** instead of **🎓 Enrol parent**.

1. Open the row's **⋮** menu and choose **🏠 Assign**.
2. Pick the new provider in **Educator / centre**. Brothers and sisters move together.
3. Press **Assign**. The row now says **Placed with** the new centre and its status is **Assigned**.
4. Open that centre's own waitlist and press **Enrol** to choose the room, start date and fee.

## Who can do what

| Role | Can |
|---|---|
| Agency admin | Everything on this screen, for the whole agency; move a closed centre's families here |
| Centre director | Add, edit, reach out, approve, decline, delete and enrol — families land in their own centres |
| Platform admin | The same as an agency admin, in the agency being viewed |

Educators and parents do not see this screen.

## FAQs and when something goes wrong

??? "Parent name is required." | Every entry needs at least the parent's name. Type it in **Parent name** and press **Add** again.
??? "Please write a message." | **Reach out** needs a message body. The subject is optional.
??? "This lead has no email address on file." | Choose **✏️ Edit**, add their **Email**, save, then **📣 Reach out** again.
??? "This family is already in KiddieTrac. Use Assign to place them with a centre." | The row is a family that is already on file. Use **🏠 Assign**, not **🎓 Enrol parent**, so no duplicate family is created.
??? "This agency has no centre to enrol into yet — add a centre first." | Add a centre in **Administration › Centres**, then try **🎓 Enrol parent** again.
??? The list says "No waitlist entries yet. Add one to get started." | Nobody has been added and nothing has synced yet. Press **＋ Add to waitlist**.
??? What does Synced mean? | The entry came from an enrolment system connected to your agency. You can still reach out, approve, decline or enrol it here.
??? Does approving or declining email the family? | No. Only **📣 Reach out** sends an email. Approve and Decline only change the status.

## Related screens

[[open: #admin-families | Families]]
[[open: #admin-children | Children]]
[[open: #invitation-codes | Invitation codes]]

See also [[adding-a-family]] and [[inviting-parents-and-staff]].
