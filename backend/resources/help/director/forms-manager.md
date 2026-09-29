---
title: Forms Manager and form packages
category: Administration
order: 66
roles: agency_admin, platform_admin
---
# Forms Manager and form packages

**Engagement → Forms Manager.** Upload a PDF form, send it to the people who have to
sign it, and see what has come back. It has four tabs: **Library**, **Multiple forms**,
**Request files** and **Completed**.

[[open: #forms-manager | Open Forms Manager]]

## Library: the forms themselves

The Library is where a form is uploaded and kept. When you upload one you give it:

- A **title** and a **description**. The description is what the form is *for*, and it
  is shown beside the title everywhere the form appears.
- **Who must sign it?** Parents, educators, home visitors or directors. You can instead
  pick specific people under **Or send to specific people**, and then it goes only to
  them.
- **Let recipients fill this form in**: tick this when the PDF has fillable fields.
  People then type into the form on screen and sign it in place. Leave it off for a
  read-and-sign notice.
- **Allow this form to be reused**: keeps the form open after it is submitted, so it
  can be completed again, once per child or week after week. Each submission is kept
  as its own record.
- **Email completed forms to** (optional): every time someone signs, the completed PDF
  is emailed to this address.

> **Note:** Assigning a form from the Library does not email anybody. To tell people, send it from **Multiple forms**.

## Multiple forms: sending a package

Onboarding a family usually means several forms at once. A package assigns every form
you tick to every person you choose, and sends each person **one** email that lists
them all.

[[show: #forms-manager @ Multiple forms > + Send multiple forms | Show me the Send multiple forms button]]

1. Press **+ Send multiple forms**.
2. Tick the forms. Each is marked **📝 Fill & sign** or **✍️ Sign only**, so you can
   see what the recipient will actually be asked to do. Only active forms are listed.
   **+ Upload a form** takes you to the Library to add one, then brings you back.
3. Choose people from the list, or type email addresses separated by commas.
4. Add a note if you want one. It goes in the email.
5. Leave **Email them now** on to send the email. Untick it to assign quietly: they
   still see the forms in the portal.
6. Press **Send package**.

**A parent who is not on the system yet can still be sent forms.** If a typed address
has no account at your agency, a placeholder account is made for it and the forms go
out. The email link lets them fill in and sign without a password. It does not invite
them to the portal: that stays a separate step.

> **Note:** A typed address shared by more than one account at your agency goes to one of them, and the result says so. To choose exactly who, pick the person from the list.

### The send history

The tab lists every package that has gone out, with who sent it and when. Use it to
answer "did this already go out?". For each send you can see:

- **Signed** is how many of the signatures have come back (forms × people).
- **Emailed** is **Not sent** if you assigned quietly, **None went** if nobody could be
  emailed, and otherwise how many were emailed.
- **Read** is **Not delivered** if the email never left, **Unopened** if it was delivered
  but nobody has opened it, and **✓ Read** once it has been opened.

> **Tip:** "Unopened" is a weak signal. Some mail apps never report an open, so check **Not delivered** first: that one is a real problem.

The **⋮** menu on each send offers:

- **View forms**: who got what, and when each person signed. Click a signed date to open
  that completed form. Use **Send again** in an unsigned cell to resend one form to one
  person.
- **Print summary** and **Download PDF**. The download merges every completed form into
  one PDF. If nothing has been signed yet, it gives you the blank forms instead.
- **Send reminder** emails the package again. Anyone who has already signed everything
  in it is skipped.
- **Resend completed copies** sends each finished form to your agency's admins and
  directors, with a copy back to the person who signed it.
- **Delete from history** removes the record that it was sent. The forms, assignments
  and signatures stay exactly as they are.

## Request files and Completed

- **Request files** asks someone for documents they already have, like an ID or an
  immunization card. See [Requesting files](requesting-files).
- **Completed** lists every signed form. From here you review and counter-sign a form, or
  send it back for correction. See
  [Reviewing, counter-signing and sending back forms](reviewing-and-counter-signing-forms).

## Common questions

??? Why does a package show "0 of 7 signed"? | Check the Read column first: if it says Not delivered, the family never got the email.
??? Does deleting a send un-assign the forms? | No. Delete from history only removes the history row; assignments and signatures are untouched.
??? Can I send a form to a new parent with no login? | Yes. Type their address; a placeholder account is made and they sign from the emailed link.

See also: [Requesting files](requesting-files),
[Reviewing, counter-signing and sending back forms](reviewing-and-counter-signing-forms),
[Custom forms builder](custom-forms), [Document workflows](doc-workflows).
