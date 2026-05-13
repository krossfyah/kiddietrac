# Kiddietrac Android — Educator Tablet App

The provider-facing Android app. Designed for **Samsung Galaxy Tab Active 5** (rugged 10" tablet) in landscape mode, mounted in the classroom.

## Why a native app (not a PWA)?

- **Offline-first** — classroom Wi-Fi is unreliable. Educators must be able to log meals, naps, and diapers without internet, with automatic background sync when connected.
- **Push notifications** — pickup alerts, medication reminders.
- **Camera + audio** — for photos and voice logging.
- **Keyboard avoidance** when entering long notes on a tablet keyboard.
- **Locked-down mode** — single-app kiosk mode prevents kids/visitors from leaving the app.

## Stack

| | |
|---|---|
| Language | **Kotlin 2.0** |
| UI | **Jetpack Compose** |
| Architecture | MVI with Repository + UseCase layers |
| Networking | **Retrofit 2** + OkHttp |
| Local DB | **Room** (SQLite wrapper) — for offline event queue |
| Async | Kotlin Coroutines + Flow |
| Dependency Injection | **Hilt** |
| Push | **Firebase Cloud Messaging** |
| Image loading | **Coil** |
| Min SDK | API 26 (Android 8.0) |
| Target SDK | API 35 (Android 15) |

## Project structure

```
android/app/src/main/
├── java/com/kiddietrac/
│   ├── KiddietracApp.kt              # Application class, Hilt setup
│   ├── MainActivity.kt               # Single activity host
│   ├── di/                           # Hilt modules
│   ├── data/
│   │   ├── api/                      # Retrofit services
│   │   ├── db/                       # Room database, DAOs
│   │   ├── model/                    # DTOs and entities
│   │   ├── repository/               # Data layer (combines api + db)
│   │   └── sync/                     # Background sync worker
│   ├── domain/
│   │   └── usecase/                  # Business logic
│   ├── ui/
│   │   ├── theme/                    # Brand colors, typography
│   │   ├── auth/                     # Login screen
│   │   ├── classroom/                # Main classroom roster view
│   │   ├── child/                    # Child detail / event logging
│   │   ├── ratio/                    # Live ratio banner
│   │   └── common/                   # Shared composables
│   └── util/
└── res/
    ├── values/
    │   ├── colors.xml                # Brand tokens
    │   ├── strings.xml               # All copy (i18n-ready)
    │   └── themes.xml
    └── drawable/                     # Logo, icons
```

## Setup

1. Open the `android/` folder in **Android Studio Hedgehog** or newer.
2. In `local.properties`, set:
   ```
   API_BASE_URL=https://api.kiddietrac.ca/api/v1
   ```
3. Place your `google-services.json` from Firebase Console at `app/google-services.json`.
4. Sync Gradle. First build will take ~3 minutes.
5. Connect a tablet via USB with developer mode enabled, or use the emulator (Pixel Tablet API 35).
6. Run.

## Distribution

For internal centre use, publish via **Google Play Console** internal track (free, instant) or as a sideloadable APK signed with your release keystore. Public Play Store release requires going through Google's review (~3 days).

## Locked-down kiosk mode (recommended for production)

Configure the tablet as a fully-managed Android device via **Android Enterprise**. Use **Google Workspace** or **Esper** (free for <1,000 devices) to:

- Force the Kiddietrac app to launch on boot
- Disable navigation buttons
- Prevent app uninstall
- Push configuration (API URL, centre ID) remotely

This stops the inevitable "an educator accidentally opened YouTube" problem.
