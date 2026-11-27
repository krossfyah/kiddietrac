---
roles: agency_admin, platform_admin
title: Multi-step document workflows
category: Administration
order: 65
screens: doc-workflows
---
# Multi-step document workflows

**Administration → Doc workflows** is for a short agreement that several people must sign
**in order**: a parent signs, then the director counter-signs, for example. You type the
text once, list who signs and in what order, and each person signs on screen when it is
their turn. Every signature is stamped and the finished document is locked.

[[open: #doc-workflows | Open Doc workflows]]

> 💡 To send an uploaded **PDF** for people to sign, track who has signed, and review or send it back, use **Forms Manager** instead. Doc workflows is for typed text that passes from one signer to the next.

## Starting a workflow

1. Open **Doc workflows** and press **+ New workflow**.
2. In **New document workflow**, type the **Title** (for example "Field-trip consent").
3. Type the **Document type**, a short word to group it by such as `consent` or `contract`.
4. Type or paste the **Document text (what signers agree to)** in full.
5. Press **Continue**.
6. In **Signing order**, list the signers' roles in order, separated by commas, in **Signers in order**: `guardian`, `educator`, `centre_director` or `agency_admin`. For example `guardian, centre_director`. Up to six.
7. Confirm. A **Workflow created** message appears and the workflow joins the list at **Step 1**.

## Signing your step

1. Open **Doc workflows** and press **Open** on the workflow.
2. Read the text at the top, and the **Signers** list under it: **✓ signed** with a date, **… awaiting** for the current step, **· pending** for the steps still to come.
3. When it is your turn, a **Sign now** section appears. Type your full name in **Your full name**.
4. Draw your signature in the box. **Clear signature** starts again.
5. Press **Sign + advance**. A **Signed** message appears and the workflow moves to the next step.

When the last person signs, the status changes to **complete** and the person who started
it gets a notification: "Document fully signed".

## Reading the list

| Column | Meaning |
|---|---|
| **Title** | The name you gave it. |
| **Type** | The document type you typed. |
| **Current step** | Which signer it is waiting for: **Step 1**, **Step 2** … |
| **Status** | **in_progress** while signatures are outstanding, **complete** when all are in. |
| **Created** | When it was started. |

## What each signature records

Under every signed step the screen shows the signer's name and the signing stamp: the date
and time in your agency's time zone, the IP address and the approximate location from that
address. The server also keeps a fingerprint (a hash) of the text with each signature, so
anyone can later show the text was not changed after it was signed.

## Who can do what

- **Agency admins** start workflows and see every workflow in their agency.
- **Anyone holding the role of the current step** in your agency can sign that step: a parent for a `guardian` step, a director for a `centre_director` step.
- Nobody can sign a step that is not yet theirs, and a completed workflow cannot be changed.

## FAQs and when something goes wrong

??? Does the next signer get told it is their turn? | Not for steps set up by role, which is how this screen creates them. Tell the next signer to open Doc workflows, or use Forms Manager, which emails people.
??? I do not see Sign now. | It is not your turn yet, or your role is not the one this step needs. Check the Signers list to see whose turn it is.
??? "Name required" or "Please sign first". | Type your full name and draw your signature before pressing Sign + advance.
??? I typed the roles wrong. | A workflow cannot be edited. Start a new one with the right order and leave the old one unsigned.
??? Can I attach a PDF? | No. A workflow holds typed text. For a PDF, use Forms Manager or eDocuments.
??? The list says "No workflows yet." | Nobody in your agency has started one. Press + New workflow.

## Related screens

[[open: #forms-manager | Forms Manager]] [[open: #edocuments | eDocuments]]

- [Forms Manager and form packages](forms-manager)
- [Reviewing, counter-signing and sending back forms](reviewing-and-counter-signing-forms)
