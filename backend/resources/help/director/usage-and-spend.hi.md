---
title: Usage और spend (हर agency के texts, calls और AI)
---
# Usage और spend (हर agency के texts, calls और AI)

**Platform › Usage & spend** दिखाता है कि हर agency के texts, phone calls और AI पर KiddieTrac का कितना ख़र्च होता है, साप्ताहिक ख़र्च सीमा पार करने वाली agencies को चिह्नित करता है, और मासिक **text & call allowance** (SMS Incremental add-on) चलाता है, जहाँ आप तय करते हैं कि सीमा पार करने वाली agency अतिरिक्त का भुगतान करे या नहीं। agencies यह screen कभी नहीं देखतीं।

[[open: #usage-spend | Usage & spend खोलें]]

## Text और call allowance पार करने वाली agency से शुल्क लेना

ऊपर का card, **📶 Text & call allowance**, हर calendar महीने में आने-जाने वाले हर text और call को गिनता है। allowance के आकार का हर अतिरिक्त block दिखाई गई कीमत का होता है (जैसे 1,000 की अनुमति और 2,400 उपयोग = 2 blocks)। **जब तक आप Add to next invoice नहीं दबाते, कुछ भी charge नहीं होता।**

1. महीना चुनने के लिए **‹** और **›** का उपयोग करें।
2. banner पढ़ें: या तो allowance पार करने वाली agencies और उनकी राशि, या **✅ No agency is waiting on an overage decision**।
3. table में हर agency की **Texts** और **Calls** bars (80 % पर पीली, **… blocks over** के साथ लाल) और उसका **Billing** column (**Next invoice …** या **Not billed yet**) देखें।
4. **Charge** column में **Add $… to next invoice** दबाएँ, पुष्टि पढ़ें और **Add to next invoice** दबाएँ। row पर **✓ $… on next invoice** दिखता है।
5. या उस महीने के लिए छोड़ने हेतु **Waive** दबाएँ। row पर **Waived** दिखता है।
6. ग़लती हुई? charge या waiver के पास **Undo** दबाएँ। charge तब तक वापस लिया जा सकता है जब तक उसका invoice नहीं बनता; उसके बाद **invoiced** दिखता है।

> ⚠️ अगर **Billing** में **Not billed yet** लिखा है, तो उस agency के लिए billing चालू नहीं है, इसलिए स्वीकृत line उसके चालू होने तक रुकी रहती है।

## Allowance बदलना

1. **Default texts / month** और **Default calls / month** में नई संख्याएँ लिखें (ये सभी agencies पर लागू होती हैं)।
2. किसी एक agency को उसकी अपनी allowance देने के लिए **Own allowance (texts · calls)** में उसकी संख्याएँ लिखें। default के लिए ख़ाली छोड़ें।
3. **Save allowances** दबाएँ। **Allowances saved** toast दिखता है।

## साप्ताहिक ख़र्च और सीमाएँ जाँचना

1. allowance card के नीचे **Last 7 days** या **Last 30 days** चुनें।
2. banner पढ़ें। 7 दिन के view में यह **over a threshold this week** वाली agencies बताता है, या **✅ No agency is over a threshold for the last 7 days** लिखता है। सीमाएँ साप्ताहिक हैं, इसलिए 30 दिन के view में कोई चिह्न नहीं दिखता।
3. table में हर agency की **Texts**, **Calls**, **AI** और **Total** लागत पढ़ें। सीमा से ऊपर की लागत लाल होती है।
4. **💲 Rates** में एक unit की लागत लिखें: **Text, per segment**, **Call, per minute**, और दो AI rates (ये सिर्फ़ उस AI model के लिए हैं जो built-in price list में नहीं है; सूचीबद्ध models अपनी कीमतें उपयोग करते हैं)।
5. **🚨 Default weekly thresholds** में texts, phone calls और AI के लिए प्रति सप्ताह डॉलर सीमा तय करें (0 = कभी चिह्नित न करें)।
6. किसी एक agency को अपनी सीमाएँ देने के लिए **Own weekly thresholds ($)** में उसके boxes भरें। ख़ाली = default; 0 = उस agency को कभी चिह्नित न करें।
7. **Save rates & thresholds** दबाएँ। **Rates and thresholds saved** toast दिखता है।

## साप्ताहिक summary email

हर सोमवार 7:15 बजे platform admins को **Platform weekly summary** email मिलता है, जिसमें साप्ताहिक सीमा पार करने वाली agencies सबसे ऊपर होती हैं। अभी अपने पते पर एक copy पाने के लिए **✉️ Email me this week's summary** दबाएँ।

## कौन क्या कर सकता है

| भूमिका | पहुँच |
|---|---|
| Platform admin | यहाँ सब कुछ: rates, सीमाएँ, allowances, charges और waivers। |
| Agency admins और बाकी सभी | कोई पहुँच नहीं। agency स्वीकृत overage को सिर्फ़ अपने अगले KiddieTrac invoice की एक line के रूप में देखती है। |

## अक्सर पूछे जाने वाले सवाल और समस्याएँ

??? "Your account has no email address." | test summary आपके अपने email पर जाता है। पहले अपनी profile में email जोड़ें।
??? कोई agency लाल है पर मैं उससे शुल्क नहीं लेना चाहता। | उस महीने के लिए Waive दबाएँ। जब तक आप Add to next invoice नहीं दबाते, कुछ charge नहीं होता।
??? मैं Undo नहीं दबा पा रहा। | charge पहले ही invoice पर है ("invoiced")। इसे Accounting AR/AP से ठीक करें।
??? "That did not go through"। | charge, waiver या undo विफल रहा। refresh करें और फिर कोशिश करें।
??? क्या agencies को सीमा पार होने पर बताया जाता है? | नहीं। यह screen और साप्ताहिक summary सिर्फ़ platform admins के लिए हैं।

## संबंधित screens

[[open: #ai-usage | AI usage]] [[open: #sales-invoices | Accounting AR/AP]] [[open: #sales-plans | Plans & pricing]] [[open: #admin-mrr | MRR dashboard]]
