---
title: Setting up your centre
category: Getting Started
order: 1
roles: agency_admin, platform_admin, centre_director
screens: admin-centres
---
# Setting up your centre

**Centres** is where an agency admin adds and looks after every location in the agency: a centre, a home childcare provider or a room, depending on what your agency calls them. Each centre holds its address, provider (owner), capacity, opening hours, branding, kiosk and email settings. Open it from the sidebar: **Overview > Centres**.

[[open: #admin-centres | Open Centres]]

## Adding a centre

1. Open **Centres** and press **+ Add centre** (top right). The **New centre** form opens.
2. Type the **Centre name**, for example "Starlight Learning Centre".
3. Fill in **Provider (owner) first name** and **Provider (owner) last name**. This is the person who runs the home or centre, not their home visitor.
4. Optionally add the **License number**, **Maximum children enrolled (capacity)** and **Maximum children at one time**. Enrolment may go above the second number; attendance may not.
5. Start typing in **Find the address** and pick the match. It fills **Address**, **City**, **Province / State** and **Postal / ZIP code**; check them, or type them yourself.
6. Tick the **Open days** and set **Opens** and **Closes**. The closing time is what a late pickup is measured against; leave it blank only if the provider has no fixed closing time.
7. Press **Create centre**. The new centre appears in the list.

> 💡 The address is what places the centre on the **Provider map**, so a complete address saves you a trip back here.

## Editing a centre

1. Find the centre in the list and open its **⋮** menu.
2. Press **Edit**. The same form opens with the centre's details filled in.
3. Change what you need. Under **Branding** you can **Upload logo** (max 2 MB), pick a **Primary colour** and **Accent colour**, and write a **Tagline (shown on the centre card)**.
4. Update the **Provider bio** if families should see a new introduction. It cannot be emptied once set, because families are sent it when they join.
5. Use the email switch to turn emails for this centre on or off.
6. Press **Save changes**. The row updates straight away.

To read a centre without risking a change, use **⋮ > View** instead.

## Turning on the kiosk

1. Open **⋮ > Edit** on the centre and scroll to **🛡 Kiosk mode**.
2. Tick **Enable kiosk for this centre**. Parents can then sign children in or out on a tablet at the door with their 4-6 digit PIN.
3. Press **Generate token** if there is no link yet, then **Copy URL** and open it on the tablet.
4. If a kiosk tablet is lost or stolen, press **Sign out all kiosks**. The old link stops working on every tablet at once; open the new link on the tablets you still use.

## Naming an acting supervisor

1. Open **⋮ > Acting supervisor** on the centre.
2. Choose who acts for the supervisor when they are off-site, and save. See [[headcounts-and-acting-supervisor]].

## Closing a provider

1. Open **⋮ > 🚪 Close this provider…**.
2. Pick the **Last operating day**.
3. For every family, choose where they go: a room at another centre, **Withdraw — leaving care**, or **Waitlist — place with a new educator later**.
4. Press **Preview** to check the plan, then **Close provider**. Transfers and withdrawals are applied, parents are emailed and the provider moves to **Archived**.

Agency admins can switch between **Active** and **Archived** under the banner to see or restore closed centres.

## Who can do what

| Role | What they can do |
|---|---|
| Agency admin | Everything on this screen: add, edit, kiosk, sign-in lock, close and restore centres |
| Platform admin | The same, inside the agency they are viewing |
| Centre director | Does not have the **Centres** screen. Directors see their own centres on the **Dashboard** and ask an agency admin for changes |

## FAQs and troubleshooting

??? I pressed Create centre and nothing was saved. | The form shows the reason in red. "Name is required." means the **Centre name** is empty. When adding a centre, the provider's name and the address are required too.
??? The screen says "Could not load centres". | The list could not be fetched. Refresh the page; if it keeps happening, check your connection or contact KiddieTrac support.
??? Why can't I clear the provider bio? | "The provider bio cannot be removed — families are sent it when they join." Edit the text instead.
??? Late pickups are never flagged for a centre. | It has no **Closes** time. Without a closing time nothing can be counted as late. Add one under **Open days**.
??? A centre shows Email off. | Emails for that centre are switched off, so its families get no email from KiddieTrac. Turn the switch back on in **⋮ > Edit**.
??? I don't see Centres in my menu. | Only agency admins have it. A centre director asks their agency admin.

## Related

[[open: #dashboard | Agency overview]] [[open: #provider-map | Provider map]] [[open: #provider-day | Daily Overview]]

- [[kiosk-mode]]
- [[closing-a-provider-or-educator]]
- [[centres-or-rooms]]
- [[archived-records]]
- [[first-time-setup]]
