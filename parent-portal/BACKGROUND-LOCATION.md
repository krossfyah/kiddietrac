# Background location — walk auto-detection

Everything on the web side is already built and live. What remains is native, and has
to be done on the machine that has Node, the Android SDK and Xcode — this server has
none of them (cPanel PHP host; the APKs in `dl/` were uploaded, not built here).

Without these steps the feature still works: it polls while the app is open and checks
the moment the app returns to the foreground. These steps add the case where the phone
is in a pocket and the app is closed.

---

## 1. Install the plugin

```bash
cd parent-portal
npm install @capacitor-community/background-geolocation
npx cap sync
```

Nothing in the web code changes. `js/kt-walk-autodetect.js` already looks for
`Capacitor.Plugins.BackgroundGeolocation` and uses it when present; the plugin's
settings are already declared in `capacitor.config.ts`.

---

## 2. Android

`android/app/src/main/AndroidManifest.xml`, inside `<manifest>`:

```xml
<uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION" />
<uses-permission android:name="android.permission.ACCESS_FINE_LOCATION" />
<uses-permission android:name="android.permission.ACCESS_BACKGROUND_LOCATION" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE_LOCATION" />
```

And inside `<application>`:

```xml
<service
    android:name="com.equimaps.capacitor_background_geolocation.BackgroundGeolocationService"
    android:foregroundServiceType="location"
    android:enabled="true"
    android:exported="false" />
```

`ACCESS_BACKGROUND_LOCATION` cannot be requested at the same time as foreground
location on Android 11+. The plugin handles the two-step prompt, but the user must
choose **Allow all the time** at the second step or nothing runs in the background.

---

## 3. iOS

`ios/App/App/Info.plist`:

```xml
<key>NSLocationWhenInUseUsageDescription</key>
<string>Used to notice when you leave with the children so a walk can be started and parents can see who is out with you.</string>

<key>NSLocationAlwaysAndWhenInUseUsageDescription</key>
<string>Used to notice when you leave with the children even if the app is closed, so a walk can be started and parents can see who is out with you.</string>

<key>UIBackgroundModes</key>
<array>
  <string>location</string>
</array>
```

---

## 4. What the stores will ask

Both stores treat background location as a high-risk permission and will reject a
vague answer. The honest version, which is also the accurate one:

> Educators take groups of children on walks from a home childcare property. The app
> detects that the educator's device has left the registered property while children
> are signed in, and prompts them to start a walk so that parents can see which
> children are out and where. Location is compared against the property's coordinates
> **on the device**; no location is transmitted unless the educator confirms and starts
> a walk, at which point it is shared with those children's parents only.
>
> Without background access the prompt is missed in the common case where the phone is
> in a pocket during the walk, which is precisely when the record matters.

Google Play additionally wants a short demo video showing the prompt appearing.

---

## 5. Before shipping it

- **Two centres have no coordinates** (`centres.latitude/longitude` is null). The
  prompt cannot fire for them at all. Fix that first — it is a data edit, not a build.
- Test with the app killed, not just backgrounded.
- Watch the battery over a full day on one device before rolling out. `distanceFilter`
  is set to 60 m; raise it if the drain is noticeable.
- Confirm with your team that a permanent "KiddieTrac is watching for the start of an
  outing" notification on personal phones is acceptable. That is a staff-privacy
  conversation, and it is easier to have before the permission prompt than after.

---

## Design note

The server never receives a position for this feature. `GET /provider/walks/geofence`
returns the property's coordinates, the radius, whether children are signed in and
whether a walk is already running; the device does the arithmetic itself. A feature
whose whole job is to notice one moment must not leave behind a continuous record of
where staff are — and that constraint is what keeps the store justification above
truthful.
