---
title: Branding (reseller)
category: Administration
order: 10
roles: platform_admin, reseller_admin
screens: reseller-brand
---
# Branding (reseller)

**Branding** sets the one brand every agency under your reseller shows: the product name people see instead of "KiddieTrac", your support email, your logos, colours and page banner. A **Live preview** on the right shows the boot screen, the app, a notification and a browser tab as you change things.

[[open: #reseller-brand | Open Branding]]

## Changing the name and support email

1. Open **Branding** from the **Reseller** section of the menu.
2. Under **Name & contact**, type the **App & product name** (up to 60 characters), for example "Little Steps".
3. Type your **Support email**. This is the help address your agencies see.
4. Watch the **Live preview** update, then press **Save brand** at the bottom.

## Uploading your logos

1. Under **Logos**, find the slot you want: **Square logo (app icon, notifications)** (at least 512×512), **Wordmark (top bar, emails, sign-in)** (wide, about 4:1), **Boot screen logo** or **Favicon (browser tab)** (square, 32×32 or larger).
2. Press **Upload** under that slot and choose a PNG, JPG, SVG or WebP file of up to 4 MB. A transparent background works best.
3. A green "Logo uploaded." toast appears and the preview changes.
4. To go back to the KiddieTrac image for a slot, press **Use default** beside it.
5. Press **Save brand**. Uploading alone does not publish the logo to your agencies.

## Choosing colours and the page banner

1. Under **Colours**, set **Primary** (buttons), **Accent** (highlights) and **Boot screen background**. Use the colour picker, or type a hex code such as `#1F6080`.
2. Under **Page banner**, set **Start colour** and **End colour** for the colour band at the top of every page. Tick **Middle colour** for a third colour, and drag **Angle** to turn the gradient. Leave a colour blank to use your primary → accent colours.
3. Press **Reset to default** to undo the banner changes.
4. Press **Save brand**. "✓ Saved. Agencies see the new brand the next time they open a page." appears.

> 💡 Look at the banner preview before saving and pick colours with enough contrast so page titles stay easy to read.

## Letting agencies use their own look

1. Under **Licence**, tick **Let my agencies use their own logo and colours** if an agency may override your brand with its own branding.
2. Leave it unticked to make every agency show your brand.
3. Press **Save brand**.

**Hide KiddieTrac everywhere** is greyed out for you: it is part of your licence and is set by KiddieTrac ("Set by KiddieTrac as part of your licence."). When it is on, your agencies see only your name and logos on screens, emails, texts, documents and notifications.

## Who can do what

| Role | What they can do |
|---|---|
| Reseller | Changes their own brand here. Cannot change **Hide KiddieTrac everywhere**. |
| Platform admin | Edits any reseller's brand, including **Hide KiddieTrac everywhere**, on **Resellers › Brand**. |
| Agency admins | Do not see this screen. They see the brand you save here. |

## FAQs and when something goes wrong

??? My agencies still show the old logo. | Each person sees the new brand the next time they open a page. Ask them to refresh. A phone app may keep the old image until it is reopened.
??? A red "Not saved" toast appears when I upload. | The file is too big (over 4 MB) or not an image type the screen accepts. Save it as PNG or WebP and try again.
??? "Could not load your reseller." appears. | Your account has no active reseller role, or your session ended. Refresh the page; if it stays, raise a ticket ([[raise-a-ticket]]).
??? Can I change the app icon and name in the app stores? | Not from here. Your own app in the stores comes with a white-label app build: ask KiddieTrac.
??? Does saving email anyone? | No. Nothing is sent; people simply see the new brand when they next open a page.

## Related screens

[[open: #reseller-agencies | Agencies]] [[open: #reseller | All my agencies]]

- [[reseller-agencies-list]]
- [[white-label-branding]]
- [[reseller-guide]]
