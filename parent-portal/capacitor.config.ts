// v22p51 — Capacitor native wrapper config.
// To build native apps locally (cannot be done from server):
//   1. cd parent-portal && npm install
//   2. npx cap init "KiddieTrac" "com.kiddietrac.app" --web-dir=.
//   3. npx cap add ios && npx cap add android
//   4. Open in Xcode / Android Studio: npx cap open ios
//   5. Configure code-signing, screenshots, upload to App Store / Play Console.
import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'com.kiddietrac.app',
  appName: 'KiddieTrac',
  webDir: '.',
  server: {
    url: 'https://app.kiddietrac.com',
    cleartext: false,
  },
  ios: {
    contentInset: 'always',
    backgroundColor: '#1F6080',
  },
  android: {
    backgroundColor: '#1F6080',
    allowMixedContent: false,
  },
  plugins: {
    PushNotifications: { presentationOptions: ['badge', 'sound', 'alert'] },
    /* Background location, for noticing that an outing has started.
       ────────────────────────────────────────────────────────────
       Consumed by js/kt-walk-autodetect.js, which looks for
       Capacitor.Plugins.BackgroundGeolocation and silently does nothing when the
       plugin is absent — so a build WITHOUT the npm package still works, just
       without background detection.

       backgroundMessage is the text Android shows in the permanent notification
       while the watcher runs. It is on the educator's own phone all day, so it
       says plainly what is being watched for and why; a vague string is what gets
       the permission revoked.

       See BACKGROUND-LOCATION.md for the install and the store justification. */
    BackgroundGeolocation: {
      backgroundMessage: 'Watching for the start of an outing so parents can be told.',
      backgroundTitle: 'KiddieTrac',
      requestPermissions: true,
      stale: false,
      distanceFilter: 60,
    },
    SplashScreen: {
      launchShowDuration: 1500,
      backgroundColor: '#1F6080',
      androidSplashResourceName: 'splash',
      showSpinner: false,
    },
  },
};

export default config;
