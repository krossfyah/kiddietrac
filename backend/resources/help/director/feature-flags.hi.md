---
title: Feature flags और plans
---
# Feature flags और plans

**Platform › Feature flags** वह जगह है जहाँ platform admin किसी agency का plan तय करता है और सिर्फ़ उसी agency के लिए अलग-अलग features चालू या बंद करता है। इसका उपयोग तब करें जब किसी agency को ऐसा feature देना हो जो उसके plan में नहीं है, कोई feature अभी छिपाना हो, या यह देखना हो कि कोई feature कौन-सी screens नियंत्रित करता है।

[[open: #admin-features | Feature flags खोलें]]

## Agency चुनना

1. **Platform › 🚩 Feature flags** खोलें। screen उसी agency पर खुलती है जिसमें आप अभी हैं (agency switcher वाली), सूची की पहली agency पर नहीं।
2. ऊपर **Agency** सूची देखें। अगर कोई और agency दिख रही है, तो कुछ भी बदलने से पहले सही agency चुनें: **Save changes** हमेशा यहाँ चुनी गई agency में ही सहेजता है।
3. **Loading agency settings…** पूरा होने दें। उस agency का plan, region banner और सभी feature cards दिखते हैं।

> ⚠️ हर बार सहेजने से पहले **Agency** का नाम देखें। Flags और plans हर agency के अलग होते हैं, और बदलाव उस agency के सभी लोगों पर तुरंत लागू होता है।

## Page को समझना

- **Plan & billing**: **Plan** सूची (हर plan अपनी मासिक कीमत के साथ) और **Billing status**। Billing status केवल पढ़ने के लिए है और **set by the licence** लिखा होता है: यह agency के licence के अनुसार चलता है, इसलिए इसे यहाँ नहीं, **Licences** screen पर बदलें।
- **Platform** banner: agency का region (देश और प्रांत या राज्य) और उसकी जानकारी। अगर *Estimated from the time zone* लिखा है, तो agency और उसके centres में अभी कोई प्रांत या राज्य सहेजा नहीं गया है।
- शामिल चीज़ों की tiles: हर licence में क्या शामिल है (जैसे support, Message Centre और One-Tap), हर एक पर **Included** या **Off**।
- **Feature overrides**: हर feature का एक card, क्षेत्र के अनुसार समूहों और रंगों में। हर card पर नाम, उसका code, उसे शामिल करने वाला सबसे निचला plan (**plan ≥ …** या **add-on only**) और छोटे chips में वे menu screens जिन्हें वह नियंत्रित करता है। *No menu item — gates the API only* का मतलब है कि feature की अपनी कोई screen नहीं है।
- ऊपर दाईं ओर के counters बताते हैं कि कितने features **forced on**, **forced off** और **auto** हैं।

## एक agency के लिए feature बदलना

1. **Feature overrides** में उस feature का card ढूँढें।
2. उसके तीन buttons में से एक दबाएँ:
   - **Auto**: plan के अनुसार चलें (सामान्य setting)।
   - **✓ On**: ज़बरदस्ती चालू करें, भले ही plan में न हो।
   - **✗ Off**: इस agency में सबसे इसकी screens छिपाएँ और इसकी API calls ठुकराएँ।
3. **✓ On** पर card हरा, **✗ Off** पर गुलाबी, और **Auto** पर अपने समूह के रंग का हो जाता है। अभी कुछ सहेजा नहीं गया है।
4. बाकी features के लिए यही दोहराएँ।
5. **Save changes** दबाएँ। **Saving…** और फिर **✓ Saved** दिखता है, और page नई settings के साथ फिर से लोड होता है।

[[show: #admin-features @ Save changes | मुझे दिखाएँ]]

> 💡 सहेजने से पहले मन बदल गया? agency की सहेजी हुई settings फिर से लोड करने के लिए **Cancel** दबाएँ।

## Agency का plan बदलना

1. **Plan** सूची में नया plan चुनें।
2. **Save changes** दबाएँ।
3. **Auto** वाले सभी features अब नए plan के अनुसार चलते हैं। जिन्हें आपने **✓ On** या **✗ Off** पर रखा था, वे वैसे ही रहते हैं।

> ℹ️ किसी agency के paid add-ons और billing उसके licence से आते हैं। भुगतान करने वाली agency के लिए plan या add-ons **Licences** पर बदलें ताकि invoice मेल खाए; यह screen अपवादों के लिए है।

## कौन क्या कर सकता है

| भूमिका | क्या कर सकते हैं |
|---|---|
| Platform admin | Platform menu में **Feature flags** देखता है, किसी भी agency के plans और overrides बदलता है। |
| Agency admin, centre director | यह screen नहीं देखते। वे वही देखते हैं जो उनका plan देता है; ज़्यादा के लिए upgrade prompts का उपयोग करते हैं या KiddieTrac से संपर्क करते हैं। |
| Agency में सभी | अगली बार page लोड होने पर किसी feature की screens बस दिखने या गायब होने लगती हैं। |

## अक्सर पूछे जाने वाले सवाल और समस्याएँ

??? मैंने flag बदला, पर agency के लोग अब भी screen देख रहे हैं। | menu page लोड होने पर पढ़ा जाता है। उनसे page refresh करने या फिर से sign in करने को कहें। देखें कि आपने Agency सूची में सही agency सहेजी थी।
??? Billing status नहीं बदल रहा। | यहाँ यह केवल पढ़ने के लिए है और licence के अनुसार चलता है। इसे Licences screen पर बदलें।
??? cards की जगह लाल संदेश दिखता है। | feature सूची या agency लोड नहीं हो सकी (जैसे "Agency not found" या "Forbidden")। refresh करें; यह screen केवल platform admins खोल सकते हैं।
??? Agency सूची में "No agencies found" लिखा है। | आपके लिए कोई agency सूचीबद्ध नहीं हो सकी। refresh करें और जाँचें कि आप platform admin के रूप में signed in हैं।
??? Off किया गया feature क्या करता है? | उसकी screens उस agency में सबके menu से हट जाती हैं और server उसके requests ठुकराता है। कोई data नहीं मिटता: Auto या On पर वापस करें और सब लौट आता है।
??? क्या किसी को email या notification जाता है? | नहीं। यहाँ plan या flag बदलने पर agency को कुछ नहीं भेजा जाता।

## संबंधित screens

[[open: #licences | Licences]] [[open: #platform-agencies | All agencies]] [[open: #admin-mrr | MRR dashboard]] [[open: #sales-plans | Plans & pricing]]
