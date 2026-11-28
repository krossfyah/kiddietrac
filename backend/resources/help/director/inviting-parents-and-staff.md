---
title: Inviting parents and staff
category: Enrolment
order: 2
screens: invitation-codes, admin-users
---

# Inviting parents and staff

When you invite someone to Kiddietrac, they receive an invitation email. Most invitations
carry a **Set my password** button, which works for 7 days; a few older screens still send a
temporary password instead, which they must change the first time they sign in. Either way,
nobody else ever sees their password.

## Inviting a parent

A parent must be linked to a **family**. Their child must already be enrolled in that family.

1. On a phone, tap **⚡ Quick add** → **💌 Invite parent**
2. Under **Select family**, choose the family and press **Continue**
3. Enter the parent's first name, last name, and email
4. Set their relationship (mother / father / guardian / etc.)
5. Choose their permissions:
   - **Primary contact** — main point of contact for the family. Usually one per family.
   - **Can pick up the child** — allowed to sign the child out. Most family members are yes; emergency contacts might be no.
   - **Receives billing emails** — gets the monthly invoice email. Often only one parent per family checks this.
6. Leave **Send welcome email with login details** ticked
7. Click **Send invitation**

The parent gets an email like this:

> Hi [Name], you've been invited by [Centre] to use Kiddietrac.
>
> Email: [their email]
> Temporary password: aBc123dEf

They follow the link, choose a password and are signed straight in. If their email shows a temporary password instead, they will be asked to change it at their first sign-in.

## Inviting a staff member

1. Open **Administration › User management** and press **+ Invite user**.
2. Enter their **First name**, **Last name** and **Email** (a **Phone** is optional).
3. Choose their **Role**: **Educator**, **Centre director**, **Home visitor**, **Agency admin** or **Auditor (read-only)**. A role that belongs to one centre also needs a **Centre**.
4. Press **Create user**. They get an invite email with a secure "set your password" link.

On a phone you can also tap **⚡ Quick add** → **👨‍🏫 Invite staff**, enter the name and email, choose **Educator (tablet view)** or **Director (full admin)**, keep **Send welcome email with login details** ticked and press **Send invitation**.

> 💡 Choosing **Parent / guardian** as the role does not create a login on its own: the button becomes **Continue to Add Family →**, because a parent is always added together with their family and child. See [[adding-a-family]].

## Parent invitation codes

A code lets parents enrol their own child: you share a link, they fill in the form, and each new child lands on your waitlist for you to review.

1. Open **Enrollment › Invitation codes** and press **+ New code**.
2. In **New invitation code**, give it a **Label** if you like (for example the room and year), set **How many parents can use this code?** and an optional expiry date.
3. Press **Generate code**. The **Code generated** box shows the link and the code itself.
4. Press **Copy link** and send it to the parent (email, text or a printed note). The button reads **Copied!** for a moment.
5. Press **Done**.

The list shows each code's **Status** — **ACTIVE**, **AT CAP** (every use taken), expired or revoked — with **Uses**, **Expires** and **Created by**. **Revoke** stops a code at once: a parent part-way through the form is told it is no longer valid. **Delete** removes a code nobody has used yet.

[[show: #invitation-codes @ + New code | Show me where to make a code]]

## When a new staff member finishes onboarding

Once an **educator, centre director or home visitor** completes their onboarding, they
get a **staff welcome email**, once. Their centre's director(s) and your agency admins are
copied, so management knows the new person is set up.

It contains:

- a warm welcome naming their role and centre;
- **how your agency cares for children** — the "Our care" text from your
  **Provider welcome** template, so you write your values once and parents and staff
  read the same words;
- a short tour of the portal features they will use every day;
- a link to **Help & guides**, and who to ask (their director's name and contact).

You can change the heading, welcome, first steps and sign-off under
**Settings → Email templates → Staff welcome (educators & providers)**, and send yourself
a test from there.

[[show: #email-templates @ Staff welcome | Show me the staff welcome template]]

![Editing the staff welcome email](https://api.kiddietrac.com/help-img/email-template-staff-welcome.png)

??? Does the staff welcome go to parents? | No. Parents get the separate Provider welcome email that introduces their provider. The staff welcome only goes to the new staff member, with directors and admins copied.
??? Can I resend it? | It is sent once, automatically. Send a test from the template editor to preview it.

## Giving someone a custom role

If your agency has made its own roles in [[custom-roles-and-permissions]], give one to a staff member from their record:

1. Open **User management** and open the person.
2. Beside **Add / set role**, pick the custom role (marked **★**, under **Your custom roles**) and, for a centre role, the **Centre**.
3. Press **Apply**. A **★** pill with the role's name appears beside their built-in role.
4. To take it away, pick the built-in role again and press **Apply**.

> ℹ️ A custom role only narrows its base role: the person still sees the same centres and children, with the screens the role turns off removed from their menu.

## Three lists, not one

**Administration → User management** separates them, with a count on each:

- **👥 Active users** — everybody signing in.
- **✉️ Invited** — invited and never claimed. If somebody says they never got an email,
  this is the tab that says whether an account is waiting for them.
- **🗄️ De-boarded / deactivated** — closed accounts. They are refused at the sign-in door,
  so resetting a password on one produces a password nobody can use. Look here before
  troubleshooting a lockout.

## Re-inviting somebody

**✉️ Resend welcome invite** no longer does the same thing to everybody, and the difference matters.

- **An account never claimed** gets a fresh temporary password. That is exactly what
  "resend the welcome" means, and nothing is taken away.
- **An account already active** gets a **reset link**, and nothing is changed. Their
  current password keeps working.

It used to replace the password and end every session for anybody, which is how pressing
it to help a parent who "can't get in" became the reason she could not get in. See
[Resetting a user's password](resetting-a-users-password).

## What if the email doesn't arrive?

- Check the recipient's **spam folder**
- Verify the email address is spelled correctly
- Ask the recipient to check that "kiddietrac.com" emails aren't blocked at their email provider

If you're not sure if it went through, you can re-invite them — the system will detect they're already an invited user and just update the password.

## Inviting both parents from a two-parent family

Invite them separately. Each will have their own login but see the same child(ren). Only ONE should be set as "Primary contact" to avoid duplicate billing emails. Both can typically have "Can pick up" enabled.

## What if a parent shares custody and uses a different email?

Add them as a separate guardian to the same family. They'll see the child but have their own login.

## Permissions cheat sheet

| Permission | What it allows |
|---|---|
| Primary contact | They're the default contact for centre-wide notices |
| Can pick up | They can sign the child in and out, their face/name is on the educator tablet |
| Receives billing | They get the monthly invoice email |
| Billing share % | If billing is split between two parents, this controls how much each owes |
