---
title: White-label branding for customer agencies
category: Administration
order: 40
---
# White-label branding for customer agencies

White-label lets a customer agency replace the default Kiddietrac branding with their own — logo, primary colour, support email, and the "Powered by Kiddietrac" footer is hidden. This is useful when you resell Kiddietrac to childcare operators who want their staff and parents to see only their own brand.

White-label is a chargeable add-on. The price is set by you when configuring the tenant's plan amount — there's no fixed surcharge enforced by the system.

## Enabling white-label for a new agency

Go to **Platform → All agencies → + Create agency**. Fill in the name, contact email, plan code, and monthly amount. In the **White-label branding** section:

1. Toggle **Enable** to ON.
2. Paste the agency's logo URL. Ideal: PNG or SVG, 200x60 pixels or smaller. Must be publicly hosted (a CDN or the agency's own website).
3. Pick the **primary colour** with the colour picker. This becomes the agency's accent color throughout their dashboard.
4. Set the **support email** so their staff see "Email support" pointing at the right inbox.

Save. The new agency now has their branding stored. As they invite their own staff and parents, those users will see the customised look on next sign-in.

## Enabling white-label for an existing agency

Go to **Platform → All agencies** → click **Edit** on the agency's row. Same form, same fields — toggle Enable and fill in the branding details. Save.

## What white-label does NOT change

- Email templates still come from Kiddietrac's mail infrastructure unless a separate domain handover is arranged.
- The kiosk page styling uses the centre's brand_color (set at the centre level), not the agency's.
- The login page logo defaults to Kiddietrac's; agency-specific login domains are a separate feature.

## Turning white-label off

Edit the agency, toggle **Enable** to OFF, save. The brand fields remain stored — the only change is that "Powered by Kiddietrac" reappears. Re-enable any time without re-entering the logo/colour.
