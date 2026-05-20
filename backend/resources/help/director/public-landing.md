---
title: Public agency landing page
category: Marketing
order: 60
---
# Public landing page

Each agency gets a SEO-friendly public page at `api.kiddietrac.com/api/v1/public/landing/<slug>`. Anyone can open it without logging in.

## What's on the page

- Hero banner with your agency logo + brand colour
- "Our centres" — list of every centre (name, address, phone)
- Embedded tour-booking form — same backend as `/public/tours`, throttled to 8/hr/IP

## How to use it

1. Send the URL in a Google ad, Facebook post, or email campaign.
2. Parents click → land on your branded page → submit a tour request.
3. You receive a notification in the **Tours** screen + email.

## Customizing

The page uses your `agencies.brand_primary_color` and `agencies.brand_logo_url`. Update them in **Settings → Branding** and the public page reflects within seconds.

## SEO

Each page has a meta description and proper `<title>`. Submit the URL to Google Search Console for indexing.

## Next: kiddietrac.com marketing site

A separate corporate marketing site at the root domain is on the v22p52 roadmap.
