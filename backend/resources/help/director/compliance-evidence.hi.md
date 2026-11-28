---
title: Compliance evidence (SOC 2 की तैयारी)
---
# Compliance evidence (SOC 2 की तैयारी)

**Platform › Compliance evidence** में वह सब है जो KiddieTrac के SOC 2 report की तैयारी के समय auditor सबसे पहले माँगता है: लिखित policies और control matrix, और live सबूत कि controls मौजूद हैं और चलते हैं (किसके पास admin access है, security events, backups, scheduled jobs और code changes)। तिमाही access review पर sign-off भी यहीं होता है। SOC 2 report ख़ुद एक स्वतंत्र CPA firm जारी करती है।

[[open: #compliance-evidence | Compliance evidence खोलें]]

## Control tiles पढ़ना

1. **Platform › 🛡️ Compliance evidence** खोलें।
2. ऊपर की tiles पढ़ें। हरा ठीक है, पीला ध्यान माँगता है, लाल एक कमी है:
   - **Admins with two-factor** (और कितने passkey भी उपयोग करते हैं): 100 % होना चाहिए।
   - **All users with two-factor**।
   - **Admins never signed in**: review में हटाने लायक अनुपयोगी access।
   - **Sign-ins, last 30 days**: सफल और विफल sign-ins, और two-factor विफलताएँ।
   - **Latest backup** और कितनी copies रखी गई हैं।
   - **TLS certificate (app / api)**: बचे दिन; certificates अपने आप renew होते हैं।
   - **Audit log**: कितने events रखे गए हैं और कब से।
   - **Access review**: पिछले sign-off की तारीख़, या **Due now (quarterly)**।

## Auditor के लिए सबूत download करना

1. **Evidence exports** पर जाएँ।
2. ज़रूरी export दबाएँ: **👥 Access review (CSV)**, **🔐 Security events, 90 days (CSV)**, **🗄️ Backups (CSV)**, **⏱️ Scheduled jobs** या **🧩 Code changes, 90 days**।
3. file download होती है। यह उसी समय के live data से बनती है।

> ℹ️ हर download audit log में दर्ज होता है: किसने क्या और कब download किया।

## Policies download करना

1. **Policies and control matrix** पर जाएँ।
2. README, control matrix और remediation plan से शुरू करें।
3. हर ज़रूरी document के पास **Download** दबाएँ। **Updated** column हर document की तारीख़ दिखाता है।

## तिमाही access review पर sign off करना

यह हर तिमाही करें। sign-off उस दिन के हर admin account का snapshot रखता है।

1. **Evidence exports** में **👥 Access review (CSV)** दबाएँ और file खोलें।
2. हर admin account जाँचें: क्या यह अब भी ज़रूरी है, और क्या इसमें two-factor है?
3. जो ठीक नहीं है उसे ठीक करें (अनुपयोगी admin access हटाएँ, लोगों से two-factor चालू करने को कहें)।
4. **Quarterly access review** में **What did you find?** लिखें (जैसे कितने accounts देखे और क्या गड़बड़ थी)।
5. **What did you change?** लिखें।
6. **I reviewed every admin-level account listed in the access review.** पर tick करें।
7. **✍️ Sign off access review** दबाएँ। **Access review signed off** toast दिखता है और review form के ऊपर की सूची में जुड़ जाता है (**Date**, **Reviewer**, **Accounts**, **Findings**, **Changes**)।

[[show: #compliance-evidence @ Sign off access review | मुझे दिखाएँ]]

## कौन क्या कर सकता है

| भूमिका | पहुँच |
|---|---|
| Platform admin | इस screen पर सब कुछ। |
| Agency admins, directors, auditors | कोई पहुँच नहीं: यह KiddieTrac का अपना सबूत है। किसी agency की अपनी compliance उसकी Compliance और Data retention screens पर है। |

## अक्सर पूछे जाने वाले सवाल और समस्याएँ

??? "Write down what you found first." | What did you find? box ख़ाली है। sign off से पहले लिखें कि review में क्या मिला।
??? "Tick the box to confirm you reviewed every account." | sign-off button के ऊपर पुष्टि वाले box पर tick करें।
??? "Download failed (…)"। | export नहीं बन सका। refresh करके फिर कोशिश करें; सिर्फ़ platform admins download कर सकते हैं।
??? "No access review has been recorded yet." | अभी तक किसी ने sign off नहीं किया। पहला review अभी करें।
??? Access review tile लाल है। | पिछला review एक तिमाही से पुराना है, या कोई है ही नहीं। review करें और sign off करें।
??? क्या sign off करने पर किसी को email जाता है? | नहीं। यह यहीं सहेजा जाता है और सबूत के रूप में रखा जाता है।

## संबंधित screens

[[open: #audit-logs | Audit log]] [[open: #backups | Backups]] [[open: #security-alerts | Security alerts]] [[open: #server-health | Server health]] [[open: #data-retention | Data retention & compliance]]
