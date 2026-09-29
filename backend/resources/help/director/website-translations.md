---
title: Website translations (French and Spanish)
category: Marketing
order: 31
roles: agency_admin, platform_admin
---
# Website translations (French and Spanish)

> **Note:** This is for the KiddieTrac platform team. Agency admins do not have this screen.

**Website → Translations.** Here you review the French and Spanish text that
www.kiddietrac.com translated for itself.

[[show: #marketing-site @ Translations | Show me the Translations tab]]

## How the site translates itself

The public site ships with a hand-checked French and Spanish dictionary. Text added
after that, such as a new blog article or an edited heading, has no translation yet.

- The first time a French or Spanish visitor sees that text, the page reports it.
- The server checks that the text really is on the published site, translates it
  **once**, and stores it.
- The translation **goes live straight away** for every later visitor. It does not
  wait for review. This tab is where you check it afterwards.

French is written as Canadian French (fr-CA). Spanish is written as neutral
Latin-American Spanish.

> **Note:** This tab does not list the hand-checked dictionary that shipped with the site. It lists only text that was translated automatically after that.

## The banner at the top

The coloured banner says whether automatic translation is working right now. Read it
first.

- **Green, "Automatic translation is on."** New text is being translated as visitors
  arrive.
- **Red.** Automatic translation is not working. The banner says **why**, such as no
  API key set on the server or the last translation failing, and when that happened.
  Until it is fixed, **new** text shows in **English** to French and Spanish visitors.
  Translations you already have keep working.

The check behind the banner is a real test against the translation service, not a
memory of the last attempt. It is refreshed about every 10 minutes, so after a fix the
banner can stay red for a few minutes.

> **Tip:** If French or Spanish visitors are seeing English, open this tab first. The banner tells you whether translation is running, and if not, why.

## Finding a translation

Three filters sit above the list:

- **To review — automatic** (the default), **Edited**, or **All**. The number beside each
  one is its count.
- **All languages**, **Français** or **Español**.
- **Search English or translation** looks in both columns.

Each entry shows the language, what kind of text it is (**Page text**, **Label** or
**Blog article**), whether it is **Automatic** or **Edited**, and when it last changed.
The English sits above, and the translation is in an editable box below it.

The list shows the 500 most recent entries. Search to narrow it down.

## Approving or correcting one

- **Approve** (on an automatic entry): press it to accept the translation as it is, or
  correct the box first and then press it. Either way the entry becomes **Edited**
  and leaves the review queue.
- **Save** (on an edited entry): saves further changes.

A saved change is **live within a few minutes**. An **Edited** translation is never
overwritten by the automatic translator. Your correction is final.

> **Important:** Keep any `{0}…{/0}` markers and HTML tags exactly as they appear in the English. They are the links, bold words and icons. A save that changes them is refused and tells you why.

## Deleting one

**Delete** removes the translation. The site then asks for a **fresh automatic
translation** the next time a visitor sees that text. Use it when a translation is so wrong
that starting again is quicker than correcting it.

While automatic translation is paused (red banner), deleting an entry means that text
shows in English until translation works again.

## Common questions

??? Why is a new blog post still in English for French visitors? | Either nobody has viewed it in French yet, or the banner is red and translation is paused.
??? Will my correction be replaced later? | No. An edited translation is never overwritten.
??? Can I translate text that isn't on the site yet? | No. Only text that appears on the published site is translated.

See also: [Website subscribers](website-subscribers).
