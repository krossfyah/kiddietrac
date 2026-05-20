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
    SplashScreen: {
      launchShowDuration: 1500,
      backgroundColor: '#1F6080',
      androidSplashResourceName: 'splash',
      showSpinner: false,
    },
  },
};

export default config;
