---
title: Sign in with Google
category: Getting Started
order: 8
---
# Sign in with Google

Skip the password. Sign in to KiddieTrac with your Google account.

## How to use it

On the login screen:
1. Tap **Continue with Google**.
2. Pick your Google account or sign in with your usual Google credentials.
3. Grant KiddieTrac permission to read your email + name.
4. You're redirected back to KiddieTrac signed in.

## What happens behind the scenes

- KiddieTrac receives your Google email + name from the OAuth flow.
- We check whether your email already has a KiddieTrac account:
  - **Yes** → linked, you're logged in
  - **No** → an account is auto-created with your Google name + email
- Going forward, you can sign in via Google or password — both work.

## Privacy

- We only ask for email + basic profile (name, avatar). We never ask for your Google contacts or calendar.
- KiddieTrac doesn't see your Google password.
- You can unlink Google sign-in any time from your profile settings.

## Other providers

Apple Sign In and Microsoft Sign In follow the same pattern. They light up when the platform configures the corresponding client IDs.

## For platform admin (you)

Set up the OAuth credentials:
1. Go to https://console.cloud.google.com/apis/credentials
2. Create an OAuth 2.0 Client ID
3. Add `https://api.kiddietrac.com/api/v1/sso/google/callback` as a redirect URI
4. Copy the Client ID + Client Secret into your `.env`:
   ```
   GOOGLE_CLIENT_ID=…
   GOOGLE_CLIENT_SECRET=…
   ```
5. The "Continue with Google" button auto-lights up.
