---
title: Two-factor sign-in (MFA)
category: Settings
order: 10
---
# Two-factor sign-in (MFA)

Two-factor authentication (MFA) adds a second proof of identity at sign-in: alongside your password, you enter a 6-digit code from an authenticator app on your phone. Even if your password is leaked, an attacker can't get in without your phone.

Strongly recommended for every agency admin and centre director.

## What you need

Any TOTP authenticator app — Google Authenticator, Authy, 1Password, Bitwarden, Microsoft Authenticator, and most password managers all work. Pick whichever you already use.

## Setting it up

1. Open the sidebar **Settings → Two-factor (MFA)** entry.
2. Click **Set up MFA**. Kiddietrac shows you a secret string + a list of 10 recovery codes.
3. In your authenticator app, choose "Add account" → "Enter setup key" and paste the secret. Save.
4. Save the 10 recovery codes — somewhere safe like a password manager. You will not see them again.
5. Back in Kiddietrac, type the current 6-digit code from your authenticator app and click **Confirm and turn on**.

You'll see "MFA enabled" — done. Next time you sign in, after your password, you'll be asked for a code.

## Signing in with MFA enabled

After typing your password, Kiddietrac shows a new field: **Authenticator code**. Type the 6-digit code your app currently shows and submit.

If you can't access your phone, type one of your 10-character recovery codes instead. Each recovery code works once — once used, it's consumed.

## Turning MFA off

Open Settings → Two-factor → enter your current code (or a recovery code) → confirm. You're back to password-only sign-in. Re-enabling later starts fresh.

## If you lose access to your phone

Use a recovery code at sign-in. Then once you're in, go to Settings → Two-factor → Disable, then set it up again with the new phone.

If you also lost your recovery codes, contact your platform admin — they can clear MFA on your account from their User management screen.
