---
title: Inviting parents and staff
category: Enrolment
order: 2
---

# Inviting parents and staff

When you invite someone to Kiddietrac, they receive an invitation email. Most invitations
carry a **Set my password** button, which works for 7 days; a few older screens still send a
temporary password instead, which they must change the first time they sign in. Either way,
nobody else ever sees their password.

## Inviting a parent

A parent must be linked to a **family**. Their child must already be enrolled in that family.

1. Click **💌 Invite parent** in QUICK ADD
2. Pick the family from the dropdown
3. Enter the parent's first name, last name, and email
4. Set their relationship (mother / father / guardian / etc.)
5. Choose their permissions:
   - **Primary contact** — main point of contact for the family. Usually one per family.
   - **Can pick up** — allowed to sign the child out. Most family members are yes; emergency contacts might be no.
   - **Receives billing emails** — gets the monthly invoice email. Often only one parent per family checks this.
6. Leave **Send welcome email** checked
7. Click **Send invitation**

The parent gets an email like this:

> Hi [Name], you've been invited by [Centre] to use Kiddietrac.
>
> Email: [their email]
> Temporary password: aBc123dEf

They follow the link, choose a password and are signed straight in. If their email shows a temporary password instead, they will be asked to change it at their first sign-in.

## Inviting a staff member (educator or director)

1. Click **👨‍🏫 Invite staff** in QUICK ADD
2. Enter their first name, last name, email
3. Choose role:
   - **Educator** — uses the tablet view, can log activities, take photos, message parents
   - **Director** — full admin access including billing and compliance
4. Click **Send invitation**

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

## Three lists, not one

**Administration → User management** separates them, with a count on each:

- **👥 Active users** — everybody signing in.
- **✉️ Invited** — invited and never claimed. If somebody says they never got an email,
  this is the tab that says whether an account is waiting for them.
- **🗄️ De-boarded / deactivated** — closed accounts. They are refused at the sign-in door,
  so resetting a password on one produces a password nobody can use. Look here before
  troubleshooting a lockout.

## Re-inviting somebody

**Resend welcome** no longer does the same thing to everybody, and the difference matters.

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
