---
title: Single sign-on (Google & Microsoft)
category: Settings
order: 40
roles: agency_admin, platform_admin
---

# Single sign-on (Google & Microsoft)

Let your staff and families sign in with their existing Google or Microsoft accounts instead of a separate password. *This is set up by a platform administrator under **Settings → Sign-in methods**.*

## How it works

- Sign-in buttons appear on the login page **only** for providers you have configured.
- Social sign-in **links to an existing invited account** by verified email — it never creates new accounts. If someone signs in with a Google/Microsoft address that doesn't match an invited user, they are politely turned away.
- Nothing changes for people who prefer email + password; that option always remains.

## Turning it on

1. Go to **Settings → Sign-in methods** (platform admins only).
2. For each provider, copy the **redirect URL** shown on the page into the provider's developer console (Google Cloud Console or Microsoft Entra), then paste the **Client ID** and **Client secret** back into KiddieTrac.
3. Save. The provider's button appears on the login page immediately.

To disable a provider, clear its credentials with the **Disable** button.

## Security notes

- Secrets are **write-only** — once saved they are never shown again.
- Only the OAuth keys are ever written to the server configuration; no other settings are touched.
