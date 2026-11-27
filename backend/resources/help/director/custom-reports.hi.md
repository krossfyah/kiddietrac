---
title: रिपोर्ट — चलाना, डाउनलोड करना और शेड्यूल करना
---
# रिपोर्ट — चलाना, डाउनलोड करना और शेड्यूल करना

**Administration › Reports** में आपके सेंटर और एजेंसी की तैयार रिपोर्ट हैं: उपस्थिति और घंटे, इनवॉइस और बकाया, मासिक
उपस्थिति और फ़ीस, उपस्थिति रिकॉर्ड, क्षमता और खाली सीटें, और भी। एक रिपोर्ट चुनें, तारीखें और सेंटर सेट करें, और उसे
PDF या स्प्रेडशीट में चलाएँ — या उसे हर दिन, हफ़्ते या महीने अपने-आप किसी को ईमेल करवाएँ।

[[open: #reports | रिपोर्ट खोलें]]

## रिपोर्ट चलाना

1. **Administration › Reports** खोलें।
2. टैब चुनें: **Centre reports** या **Agency reports**।
3. ऊपर तारीख-सीमा सेट करें (**Last 7 days**, **Last 30 days**, **This month to date**, **Last calendar month**, या अपनी **From** और **To**)।
4. **Centre** चुनें (या **All centres**)।
5. रिपोर्ट के कार्ड पर **Run report** दबाएँ। "Generating report…" दिखता है, फिर फ़ाइल डाउनलोड होती है।

कुछ रिपोर्ट पहले एक और सवाल पूछती हैं:

- **Invoices & balances** — शामिल करने के लिए **Status** मान टिक करें (जैसे **Overdue**, **Voided**), **Period** चुनें (**The date range on the screen**, **Specific dates** या **Every invoice, all dates**) और **Balance** (**Any balance**, **Only invoices with money still owing** या **Only invoices settled in full**), फिर **Run report** दबाएँ।
- **Monthly attendance & fees**, **Monthly attendance & fees by family**, **Record of Attendance** और **Capacity & vacancies** — महीना चुनें (और by-family शीट के लिए परिवार)।

> 💡 PDF में स्थितियों के रंग स्क्रीन जैसे ही होते हैं (जैसे paid, partially paid, absence recorded)।

## रिपोर्ट अपने-आप ईमेल करवाना

1. **Scheduled reports** टैब खोलें और **Schedule a report** दबाएँ।
2. **Report** और **Format** चुनें: **PDF**, **Excel** या दोनों।
3. वह अवधि चुनें जो रिपोर्ट में आए (जैसे **Last 7 days** या **Last calendar month**)।
4. **Frequency** सेट करें — **Daily**, **Weekly** (**Day of week** चुनें) या **Monthly** (**Day of month** चुनें) — और भेजने का समय।
5. **Send to (email)** लिखें।
6. **Save schedule** दबाएँ। **Schedule created** संदेश दिखता है और शेड्यूल सूची में **Active** के रूप में दिखता है, **Last sent** के साथ।

बदलने के लिए उसकी पंक्ति की कार्रवाइयाँ खोलें, संपादन चुनें, फिर **Save changes**। **Send now** तुरंत भेजता है, स्विच
उसे रोकता है (**Schedule paused**), और **Delete** हटाता है।

## कौन क्या कर सकता है

| भूमिका | क्या कर सकती है |
|---|---|
| एजेंसी एडमिन | हर सेंटर की हर रिपोर्ट चलाना और शेड्यूल करना। |
| सेंटर डायरेक्टर | अपने सेंटर की रिपोर्ट चलाना। |
| एजुकेटर | अपने सौंपे गए कमरों की उपस्थिति और घंटे। |
| प्लेटफ़ॉर्म एडमिन | एजेंसी स्विचर में चुनी गई एजेंसी। |

शेड्यूल किए ईमेल केवल उसी पते पर जाते हैं जो आप लिखते हैं। पक्का करें कि व्यक्ति वह रिपोर्ट देखने का हक़दार है।

## सामान्य प्रश्न और समस्याएँ

??? "Pick at least one status, or the report has nothing to show." | Invoices & balances के सवालों में कम से कम एक स्थिति टिक करें।
??? "The start date is after the end date." | तारीखें उलटें, या कोई तैयार सीमा चुनें।
??? एजुकेटर को "No rooms are assigned to you yet." दिखता है। | उनके स्टाफ़ रिकॉर्ड पर उन्हें कमरा सौंपें; उनकी रिपोर्ट उनके कमरों के अनुसार चलती हैं।
??? शेड्यूल की गई रिपोर्ट नहीं पहुँची। | शेड्यूल का पता जाँचें, फिर **Send now** दबाएँ। "Could not send" दिखे तो Help से टिकट बनाएँ।
??? मुझे अपने कॉलम और फ़िल्टर चाहिए। | हर सूची (Families, Children, Invoices …) के बिल्ट-इन एक्सपोर्ट स्क्रीन पर दिख रही चीज़ों की स्प्रेडशीट देते हैं।

## संबंधित स्क्रीन

[[open: #forecast | नामांकन पूर्वानुमान]] [[open: #retention | कोहोर्ट रिटेंशन]] [[open: #audit-logs | ऑडिट लॉग]]

यह भी देखें: [[enrolment-forecast]] और [[cohort-retention]]।
