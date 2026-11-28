---
title: Feature flags and plans
category: Administration
order: 62
roles: platform_admin
screens: admin-features
---
# Feature flags and plans

**Platform › Feature flags** is where a platform admin sets an agency's plan and switches single features on or off for that one agency. Use it to give an agency a feature its plan does not include, to hide a feature an agency should not use yet, or to check which screens a feature controls.

[[open: #admin-features | Open Feature flags]]

## Picking the agency

1. Open **Platform › 🚩 Feature flags**. The screen opens on the agency you are standing in (the one in the agency switcher), not the first one in the list.
2. Check the **Agency** list at the top. If it shows a different agency, pick the right one before you change anything: **Save changes** always writes to the agency selected here.
3. Wait for **Loading agency settings…** to finish. The plan, the region banner and every feature card for that agency appear.

> ⚠️ Look at the **Agency** name before every save. Flags and plans are per agency, and a change takes effect for everyone at that agency straight away.

## Reading the page

- **Plan & billing**: the **Plan** list (each plan with its monthly price) and **Billing status**. The billing status is read-only and says **set by the licence**: it follows the agency's licence, so change it on the **Licences** screen, not here.
- **Platform** banner: the agency's region (country and province or state) and its facts. If it says *Estimated from the time zone*, the agency and its centres have no province or state saved yet.
- **Included** tiles: what every licence includes (for example support, the Message Centre and One-Tap), each marked **Included** or **Off**.
- **Feature overrides**: one card per feature, grouped and coloured by area. Each card shows the feature name, its code, the lowest plan that includes it (**plan ≥ …** or **add-on only**) and, as small chips, the menu screens it controls. *No menu item — gates the API only* means the feature has no screen of its own.
- The counters at the top right show how many features are **forced on**, **forced off** and **auto**.

## Changing a feature for one agency

1. Find the feature's card under **Feature overrides**.
2. Press one of its three buttons:
   - **Auto**: follow the plan (the normal setting).
   - **✓ On**: force it on, even if the plan does not include it.
   - **✗ Off**: hide its screens from everyone at this agency and refuse its API calls.
3. The card turns green for **✓ On**, rose for **✗ Off**, and back to its group colour for **Auto**. Nothing is saved yet.
4. Repeat for any other features.
5. Press **Save changes**. **Saving…** appears, then **✓ Saved**, and the page reloads with the new settings.

[[show: #admin-features @ Save changes | Show me]]

> 💡 Changed your mind before saving? Press **Cancel** to reload the agency's saved settings.

## Changing the agency's plan

1. Pick the new plan in the **Plan** list.
2. Press **Save changes**.
3. Every feature on **Auto** now follows the new plan. Features you forced **✓ On** or **✗ Off** keep your override.

> ℹ️ An agency's paid add-ons and its billing come from its licence. For a paying agency, change the plan or add-ons on **Licences** so the invoice matches; use this screen for overrides.

## Who can do what

| Role | What they can do |
|---|---|
| Platform admin | Sees **Feature flags** in the Platform menu, changes plans and overrides for any agency. |
| Agency admin, centre director | Do not see this screen. They see what their plan allows; for more, they use the upgrade prompts or contact KiddieTrac. |
| Everyone at the agency | Simply sees or stops seeing the screens a feature controls, after their next page load. |

## FAQs and when something goes wrong

??? I changed a flag but people at the agency still see the screen. | The menu is read when the page loads. Ask them to refresh or sign in again. Check you saved the right agency in the **Agency** list.
??? The Billing status will not change. | It is read-only here and follows the licence. Change it on the Licences screen.
??? A red message appears instead of the cards. | The feature list or the agency could not be loaded (for example "Agency not found" or "Forbidden"). Refresh; only platform admins can open this screen.
??? The Agency list says "No agencies found". | No agency could be listed for you. Refresh, and check you are signed in as a platform admin.
??? What does a feature turned Off do? | Its screens leave the menu for everyone at that agency and the server refuses its requests. No data is deleted: turn it back to Auto or On and everything returns.
??? Does anyone get an email or notification? | No. Changing a plan or a flag here sends nothing to the agency.

## Related screens

[[open: #licences | Licences]] [[open: #platform-agencies | All agencies]] [[open: #admin-mrr | MRR dashboard]] [[open: #sales-plans | Plans & pricing]]
