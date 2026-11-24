---
title: नेटिव iOS / Android ऐप
category: Mobile
order: 90
roles: agency_admin, platform_admin
---
# नेटिव iOS और Android ऐप

v22p51 में Capacitor रैपर कॉन्फ़िगरेशन शामिल है। वेब ऐप ही मूल स्रोत है — नेटिव शेल `https://app.kiddietrac.com` लोड करता है और इनमें जोड़ता है: नेटिव पुश नोटिफ़िकेशन, स्प्लैश स्क्रीन, स्टेटस-बार थीमिंग और ऐप स्टोर वितरण।

## बिल्ड की पूर्व-आवश्यकताएँ (एक बार)

- iOS के लिए **Apple Developer खाता** ($99/वर्ष)
- Android के लिए **Google Play Developer खाता** ($25 एकमुश्त)
- iOS बिल्ड के लिए **Xcode** वाला macOS
- Android बिल्ड के लिए **Android Studio** वाला कोई भी ऑपरेटिंग सिस्टम

## स्थानीय रूप से बिल्ड करना

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

हर IDE में: कोड साइनिंग कॉन्फ़िगर करें, बंडल आइडेंटिफ़ायर सेट करें, ऐप आइकन और स्प्लैश स्क्रीन की संपत्तियाँ जोड़ें, रिलीज़ बंडल बनाएँ और उसे App Store Connect / Play Console पर अपलोड करें।

## वितरण

iOS: पहले TestFlight → 1-2 सप्ताह की समीक्षा के बाद सार्वजनिक App Store।
Android: आंतरिक ट्रैक → बंद परीक्षण → प्रोडक्शन।

## वेब ऐप में क्या बदलता है

अंतिम उपयोगकर्ताओं के लिए कुछ नहीं। नेटिव शेल `window.Capacitor` सेट करता है ताकि कुछ सुविधाएँ चालू हो सकें:
- नेटिव पुश (iOS पर बेहतर डिलीवरी — वेब पुश iOS Safari पर काम नहीं करता)
- बच्चों / स्टाफ की फ़ोटो लेने के लिए नेटिव कैमरा
- दस्तावेज़ अपलोड के लिए नेटिव फ़ाइल चयनकर्ता

ये एकीकरण बिंदु v22p51 में प्रारंभिक रूप में हैं और v22p52 में पूरी तरह विकसित किए जाएँगे।
