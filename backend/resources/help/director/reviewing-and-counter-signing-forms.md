---
title: Reviewing, counter-signing and sending back forms
category: Administration
order: 68
roles: agency_admin, platform_admin
---
# Reviewing, counter-signing and sending back forms

**Engagement → Forms Manager → Completed.** Every form someone has signed, and what the
agency has done about it. A completed form has two possible answers: **accept it** by
counter-signing, or **send it back** with a note saying what needs fixing.

[[show: #forms-manager @ Completed > Counter-signed | Show me the Counter-signed column]]

## Reading the table

Beside the form, the signer and the time it was signed (in agency time):

- **Copy emailed**: whether the completed copy reached the address set on the form.
  **Not set up** means the form has no address to send to.
- **Sent back**: **Awaiting signer** while you are waiting for a correction (with a count
  if it has been returned more than once), **Resubmitted** once they have sent a new one.
- **Counter-signed**: the date and who signed, or **Awaiting review**. "Awaiting review"
  is a prompt: it is a form nobody at the agency has looked at yet.
- **Synced**: whether the completed form is on the signer's own record. **✓ Filed**
  names the record. **Not filed** usually means the account did not exist when the form
  was signed. Press **Sync now** in the red bar above the table to fix it. **Orphaned**
  means it was filed to an account that no longer exists, and syncing will not fix it.

## Review and counter-sign

Choose **⋮ → Review & counter-sign**. It works in two steps, in this order, so a
counter-signature always means somebody actually read the form.

1. **Review.** The completed form opens full size. Under **Add information** you can type
   a note. It appears on the counter-signature page and in the email. To change the
   document itself:
   - on a fillable form, **✎ Edit the form** reopens it with the signer's answers, so you
     can correct or complete the same fields they used.
   - on any other PDF, **✎ Add information to the form** lets you write onto the page.

   Every changed field is listed by name on the counter-signature, so it is clear what
   the agency altered. The signer's original copy is never overwritten.
2. **Counter-sign.** Press **Next: counter-sign →**, check your note, then
   **✍️ Add your signature**. Your signature is shown before anything is sent. Leave
   **Email the counter-signed copy to both sides** ticked to send it to the signer and to
   the address on the form. Press **Complete counter-signature**.

Your signature goes on a new final page. Nothing the signer signed is altered. Afterwards
the menu offers **View the counter-signed PDF** instead. The confirmation tells you who
the email actually reached. If it says nothing could be emailed, check the delivery
settings for your agency.

> **Note:** A form can only be counter-signed once.

## Send back for correction

When something is missing or wrong, choose **⋮ → Send back to the signer**, or press
**↩️ Send back for correction** while reviewing.

1. Under **What needs changing**, say exactly what to fix. The note is required, and it
   is the only thing they are told, so be specific.
2. Press **Send it back**.

What happens next:

- The form becomes outstanding for them again, **with their answers still in it**. Your
  note shows on the form in their **Forms to sign** list, under "Sent back for
  correction".
- They get an email and an in-app notification. If their account has no email address,
  the dialog tells you before you send, and the result says **Recorded, not emailed**.
- The submission you sent back is kept exactly as it was. Their correction arrives as a
  new one, and the **Sent back** column changes to **Resubmitted**.

You can send a form back as many times as a correction needs.

> **Important:** Sending back and counter-signing contradict each other, so you can only do one at a time. A counter-signed form cannot be sent back, and a form waiting on the signer cannot be counter-signed until they resubmit.

## Other actions on a completed form

- **View form** and **Download completed form**.
- **Email the copy to …** sends the completed copy to the address set on the form, or
  sends it again.
- **Delete this sign-off** withdraws the signature. The completed PDF and the copy on
  the signer's record are deleted, and the form becomes outstanding for them again. Use it
  when the wrong person signed, or somebody signed the wrong thing. It is recorded in the
  audit log and cannot be undone.

## Common questions

??? Where is the corrected form? | It arrives as a new completed row. The row you sent back changes to Resubmitted.
??? Why is a completed form not in the family's Documents? | Its Synced column will say Not filed. Press Sync now above the table.
??? Why can I not counter-sign this form? | It has been sent back and is waiting on the signer. Counter-sign their resubmission instead.

See also: [Forms Manager and form packages](forms-manager), [Requesting files](requesting-files),
[Document workflows](doc-workflows).
