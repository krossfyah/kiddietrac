---
title: Scheduled maintenance और app updates
---
# Scheduled maintenance और app updates

**Platform › Maintenance** एक तय अवधि के लिए portal को offline करता है, सबको पहले से email भेजता है, और तय करता है कि mobile app के कौन-से versions को update के लिए कहा जाए। maintenance चालू रहने पर platform admins के अलावा कोई sign in नहीं कर सकता। इसे किसी planned upgrade या move से पहले, और हर नए app release के बाद खोलें।

[[open: #maintenance | Maintenance खोलें]]

## Maintenance की अवधि तय करना

1. **Platform › 🛠️ Maintenance** खोलें।
2. **Starts** और **Ends** में अवधि की तारीख़ और समय भरें। सहेजते ही sign-in रोकने और बंद करने तक रोके रखने के लिए दोनों ख़ाली छोड़ें।
3. **Message shown to users** लिखें (जैसे आप कब तक वापस आने की उम्मीद करते हैं)। यह sign-in page पर दिखता है।
4. **Maintenance mode** चालू करें।
5. **Save** दबाएँ। **✓ Saved.** दिखता है, या अगर अवधि शुरू हो चुकी है तो **✓ Saved — maintenance is ACTIVE now.**।

> ⚠️ जब **Maintenance mode** चालू हो **और** समय अवधि के भीतर हो, तो platform admins के अलावा सबका sign-in रुक जाता है। उस दौरान इस screen के ऊपर लाल banner **● Maintenance is ACTIVE right now** दिखता है।

## चेतावनी ईमेल भेजना

1. पहले अवधि सहेजें: ईमेल में उसी की तारीख़ें और संदेश जाते हैं।
2. **📧 Heads-up email** में box में अपना पता लिखें (box ख़ाली रहता है) और **Send test** दबाएँ। [TEST] लिखी एक copy आती है; उसे पढ़ें। अगर line कहे कि mail gate ने उसे रोका, तो Email log में कारण दिखता है।
3. ठीक लगे तो **Email all users** दबाएँ। एक popup गिनता है कि अभी यह agency के हिसाब से किन लोगों तक जाएगा, और कौन छूटेंगे।
4. दिखाया गया वाक्य लिखें (जैसे **EMAIL 34**) और **Send to 34** दबाएँ। संख्या बताती है कि कितने लोगों को ईमेल जाएगा; ग़लत या पुरानी संख्या स्वीकार नहीं होती।
5. buttons के नीचे की line बताती है कि कितने भेजे गए और mail gate ने कितने रोके। बाद में **All-user notice last sent …** तारीख़ दिखाता है।

> ⚠️ **Email all users** सिर्फ़ चालू agencies के सक्रिय लोगों तक जाता है: खुले केंद्र में सक्रिय भूमिका वाला स्टाफ़, खुले केंद्र में अब भी नामांकित परिवार के माता-पिता, और platform admins। Test agencies, बंद homes, और deactivated, suspended या सिर्फ़ invited accounts छोड़ दिए जाते हैं। हर व्यक्ति को सामान्य mail gate से अपना अलग संदेश मिलता है, इसलिए जिस agency या केंद्र का ईमेल बंद है वह रुक जाता है। किसने भेजा और कितने गए, यह audit log में दर्ज होता है। इसे एक ही बार भेजें।

## Maintenance ख़त्म करना

1. **Maintenance mode** बंद करें।
2. **Save** दबाएँ। sign-in तुरंत फिर से काम करने लगता है।

अगर आपने **Ends** समय दिया था, तो अवधि ख़त्म होने पर sign-in अपने आप भी लौट आता है।

## Android apps को update के लिए कहना

**📱 Mobile app updates (Google Play)** card Google Play पर live version दिखाता है।

1. Play release live होने के बाद उसका **Latest build** number और **Version name** लिखें।
2. सिर्फ़ तभी जब पुराने apps को काम करना बंद करना हो, **Minimum build** बढ़ाएँ (0 = कोई नहीं) और पुष्टि करें।
3. prompt के लिए वैकल्पिक **Message** जोड़ें।
4. **Save** दबाएँ। **✓ Saved - apps check this the next time they open.**

latest build से पुराने apps **Update available** देखते हैं; minimum से पुराने apps रोकने वाला **Update required** देखते हैं। अगर Google Play पर सहेजे गए से नया version दिख रहा है, तो एक पीली line बताती है कि क्या set करना है।

## iPhone apps को update के लिए कहना

उसी card के **📱 iPhone (App Store)** हिस्से में:

1. वह line देखें जो बताती है कि App Store पर क्या सूचीबद्ध है (**App Store page** link के साथ)।
2. **Minimum version** भरें (ख़ाली = कोई नहीं) और चाहें तो **Latest version** और **Message** भी।
3. **Save** दबाएँ।

iPhones को सिर्फ़ उसी version के लिए कहा जाता है जो App Store पर सच में सूचीबद्ध है।

## कौन क्या कर सकता है

| भूमिका | पहुँच |
|---|---|
| Platform admin | यहाँ सब कुछ, और maintenance के दौरान भी sign in कर सकता है। |
| बाकी सभी | sign-in page पर maintenance संदेश देखते हैं और चालू रहने तक sign in नहीं कर सकते। |

## अक्सर पूछे जाने वाले सवाल और समस्याएँ

??? "Set up the maintenance window first." | Email all users दबाने से पहले एक अवधि (तारीख़ें और संदेश) सहेजें।
??? "Type EMAIL … to confirm." | popup के गिनने के बाद संख्या बदल गई, या वाक्य ठीक वैसा नहीं लिखा गया। Email all users फिर से दबाएँ और नया वाक्य लिखें।
??? "Enter an email." | Send test दबाने से पहले box में पता लिखें।
??? "The minimum build cannot be newer than the latest build." | Minimum build घटाएँ या Latest build बढ़ाएँ ताकि minimum, latest से ऊपर न हो।
??? काम ख़त्म होने के बाद भी लोग रुके हुए हैं। | Maintenance mode बंद करके Save दबाएँ, या Ends समय जाँचें।
??? "Could not load maintenance settings"। | screen अपनी settings नहीं पढ़ सकी। refresh करें; इसे सिर्फ़ platform admins खोल सकते हैं।

## संबंधित screens

[[open: #server-health | Server health]] [[open: #mail-settings | Mail settings]] [[open: #platform-overview | Platform overview]]
