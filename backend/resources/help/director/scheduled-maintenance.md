---
title: Scheduled maintenance and app updates
category: Administration
order: 66
roles: platform_admin
screens: maintenance
---
# Scheduled maintenance and app updates

**Platform › Maintenance** takes the portal offline for a planned window, emails everyone a heads-up, and sets which mobile app versions are told to update. While maintenance is active, nobody can sign in except platform admins. Open it before a planned upgrade or move, and after each new app release.

[[open: #maintenance | Open Maintenance]]

## Planning a maintenance window

1. Open **Platform › 🛠️ Maintenance**.
2. Fill **Starts** and **Ends** with the date and time of the window. Leave both blank to block sign-in from the moment you save until you turn it off.
3. Type the **Message shown to users** (for example when you expect to be back). It shows on the sign-in page.
4. Switch **Maintenance mode** on.
5. Press **Save**. You see **✓ Saved.**, or **✓ Saved — maintenance is ACTIVE now.** if the window has already started.

> ⚠️ When **Maintenance mode** is on **and** the time is inside the window, sign-in is blocked for everyone except platform admins. A red banner, **● Maintenance is ACTIVE right now**, shows at the top of this screen while it lasts.

## Emailing the heads-up

1. Save the window first: the email uses its dates and message.
2. Under **📧 Heads-up email**, check the address in the box (it should be yours) and press **Send test**. Read the test email.
3. When it reads well, press **Email all users** and confirm **Email the maintenance notice to ALL users right now?**
4. The line under the buttons says how many addresses it was sent to. **All-user notice last sent …** shows the date afterwards.

> ⚠️ **Email all users** goes to every user with an email address on the platform, in every agency, and ignores agency email settings, because a downtime notice is operational. Send it once.

## Ending maintenance

1. Switch **Maintenance mode** off.
2. Press **Save**. Sign-in works again straight away.

If you set an **Ends** time, sign-in also comes back by itself when the window ends.

## Telling Android apps to update

The **📱 Mobile app updates (Google Play)** card shows the version live on Google Play.

1. After a Play release goes live, type its **Latest build** number and **Version name**.
2. Only if old apps must stop working, raise **Minimum build** (0 = none) and confirm.
3. Add an optional **Message** for the prompt.
4. Press **Save**. **✓ Saved - apps check this the next time they open.**

Apps older than the latest build see **Update available**; apps older than the minimum see a blocking **Update required**. If Google Play now lists a newer version than the one saved, an amber line tells you what to set.

## Telling iPhone apps to update

In the **📱 iPhone (App Store)** part of the same card:

1. Check the line showing what the App Store lists (with the **App Store page** link).
2. Fill **Minimum version** (empty = none) and, if you like, **Latest version** and **Message**.
3. Press **Save**.

iPhones are only prompted for a version the App Store really lists.

## Who can do what

| Role | Access |
|---|---|
| Platform admin | Everything here, and can still sign in during maintenance. |
| Everyone else | Sees the maintenance message on the sign-in page and cannot sign in while it is active. |

## FAQs and when something goes wrong

??? "Set up the maintenance window first." | Save a window (dates and message) before pressing Email all users.
??? "Enter an email." | Type an address in the box before pressing Send test.
??? "The minimum build cannot be newer than the latest build." | Lower Minimum build or raise Latest build so the minimum is not above the latest.
??? People are still blocked after the work is done. | Switch Maintenance mode off and press Save, or check the Ends time.
??? "Could not load maintenance settings". | The screen could not read its settings. Refresh; only platform admins can open it.

## Related screens

[[open: #server-health | Server health]] [[open: #mail-settings | Mail settings]] [[open: #platform-overview | Platform overview]]
