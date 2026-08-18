---
title: Native iOS / Android apps
category: Mobile
order: 90
roles: agency_admin, platform_admin
---
# Native iOS and Android apps

A Capacitor wrapper config ships in v22p51. The web app is the source of truth — the native shell loads `https://app.kiddietrac.com` and adds: native push notifications, splash screen, status-bar theming, and App Store distribution.

## Build prerequisites (one-time)

- **Apple Developer account** ($99/year) for iOS
- **Google Play Developer account** ($25 one-time) for Android
- macOS with **Xcode** for iOS builds
- Any OS with **Android Studio** for Android builds

## Building locally

```bash
cd parent-portal
npm install
npx cap init "KiddieTrac" "com.kiddietrac.app" --web-dir=.
npx cap add ios
npx cap add android
npx cap sync
npx cap open ios     # opens Xcode
npx cap open android # opens Android Studio
```

In each IDE: configure code signing, set bundle identifier, add app icons + splash screen assets, build a release bundle, and upload to App Store Connect / Play Console.

## Distribution

iOS: TestFlight first → public App Store after 1-2 week review.
Android: Internal track → closed testing → production.

## What changes in the web app

Nothing for end users. The native shell sets `window.Capacitor` so a few features can light up:
- Native push (better delivery on iOS — web push doesn't work on iOS Safari)
- Native camera for child/staff photo capture
- Native file picker for doc uploads

These integration points are stubbed in v22p51 and built out properly in v22p52.
