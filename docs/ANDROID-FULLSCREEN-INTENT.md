# Lock-screen takeover for urgent messages (Android)

What it takes to make a new chat message light up a **locked** phone, rather than only
landing in the notification drawer.

Everything on the server and in the web layer is already done and deployed. What remains is
one file in the Android project, which is generated locally (`npx cap add android`) and is
not on the web server — so it has to be applied on the build machine and shipped as a new
APK.

---

## What already works, with no APK change

| | |
|---|---|
| Urgent pushes are **data-only** | so `onMessageReceived` runs even when backgrounded |
| `kt_urgent` in the payload | the existing insistent notification (repeating sound, long vibration) |
| `kt_fullscreen` in the payload | **new** — the flag this document is about |
| Deep link `#chat?c=<id>` / `#chat?c=staff:<id>` | tapping opens the actual thread, not the list |
| In-app takeover while the app is OPEN | `kt-urgent-alert.js`, now reaching every staff role |

`kt_fullscreen` is deliberately separate from `kt_urgent`, so the behaviour can be
**withdrawn from the server without a new APK** — drop the key and handsets stop taking
over the screen.

---

## The one change: `KtMessagingService`

Merge `KtMessagingService.kt` (beside this file) into the app's existing service. The only
genuinely new part is:

```kotlin
if (wantsFullScreen && screenIsLocked() && canUseFullScreenIntent()) {
    n.setFullScreenIntent(contentIntent, true)
}
```

with the three guards that make it behave:

- **`wantsFullScreen`** — the server's `kt_fullscreen` flag, so this is controllable remotely.
- **`screenIsLocked()`** — firing a full-screen intent at somebody already using their phone
  is obnoxious, and Android shows a heads-up in that case anyway.
- **`canUseFullScreenIntent()`** — the API 34+ gate, below.

### Manifest

```xml
<uses-permission android:name="android.permission.USE_FULL_SCREEN_INTENT" />

<service
    android:name=".KtMessagingService"
    android:exported="false">
    <intent-filter>
        <action android:name="com.google.firebase.MESSAGING_EVENT" />
    </intent-filter>
</service>
```

`MainActivity` must also read the link the notification carries:

```kotlin
override fun onNewIntent(intent: Intent) {
    super.onNewIntent(intent)
    intent.getStringExtra("kt_link")?.takeIf { it.isNotEmpty() }?.let { link ->
        bridge?.webView?.post {
            bridge.webView.evaluateJavascript(
                "window.location.hash = ${org.json.JSONObject.quote(link)}", null
            )
        }
    }
}
```

Do the same from `onCreate` for a cold start, and pass it through `JSONObject.quote` —
never string-concatenate a value from a push into evaluated JavaScript.

---

## Read this before promising the behaviour

**Android 14 (API 34) stopped granting `USE_FULL_SCREEN_INTENT` freely.** It is auto-granted
only to apps whose core function is calling or alarms. A childcare app is neither, so:

1. On a fresh install on Android 14+, the permission is **not** granted.
2. The user grants it in Settings → Apps → KiddieTrac → *Manage full screen intents*, or via
   `Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT`.
3. Without it the notification is **not lost** — Android downgrades it to a heads-up banner,
   which together with the insistent sound is still hard to miss.

So the honest promise is: *"a locked phone rings insistently and shows a banner; it takes
over the whole screen on handsets where that permission has been granted."*

Ask for it **once, in context**, from Settings → Notifications, next to the existing urgent
alert toggle — never on launch. An app that demands a call-grade permission on first run is
one Play Store review away from a problem, and this is the kind of permission a reviewer
asks about.

---

## Test plan

1. **App open** — send a chat message from another account. The screen should be taken over
   in-app immediately (this already works today; no APK needed).
2. **App backgrounded, screen on** — heads-up banner, repeating sound. Tapping opens the
   thread.
3. **Screen locked, permission granted** — full-screen takeover on the lock screen.
4. **Screen locked, permission refused** — heads-up + sound, no takeover. Confirm nothing is
   lost.
5. **Two messages from different threads** — each notification must open its OWN thread.
   This is what the `Uri` on the intent is for; without it `FLAG_UPDATE_CURRENT` makes the
   second reuse the first one's link.
6. **Server withdrawal** — drop `kt_fullscreen` in `FcmService` and confirm handsets stop
   taking over without a rebuild.

## Test on Test Agency only

Agency 6. Urgent pushes ring phones — do not exercise this against a live agency.
