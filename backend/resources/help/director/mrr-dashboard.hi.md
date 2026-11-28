---
title: MRR dashboard
---
# MRR dashboard

**Platform › MRR dashboard** KiddieTrac की अपनी आवर्ती आय दिखाता है: agencies हर महीने क्या चुकाती हैं, मुफ़्त और trial licences की कीमत कितनी है, कितना invoice हुआ और कितना वसूला गया, किस पर पैसा बकाया है और कौन-से licences जल्द renew होंगे। इसे मासिक आय की जाँच के लिए, renewal call से पहले, या बकाया invoices का पीछा करते समय खोलें। यह screen सिर्फ़ आँकड़े पढ़ती है; यहाँ से कोई licence या invoice नहीं बदलता।

[[open: #admin-mrr | MRR dashboard खोलें]]

## इस महीने की आय देखना

1. **Platform › 💰 MRR dashboard** खोलें। banner बताता है कि आँकड़े कब निकाले गए (**as of …**)।
2. ऊपर की चार रंगीन tiles पढ़ें:
   - **MRR**: paid licences से मासिक आवर्ती आय, इस महीने के शुद्ध बदलाव के साथ (▲ या ▼), या *No change this month*।
   - **ARR**: MRR × 12।
   - **Paying agencies**: आपके सभी ग्राहकों में से कितनी agencies भुगतान करती हैं, और कितनी मुफ़्त या trial पर हैं।
   - **ARPA**: हर भुगतान करने वाली agency से औसत मासिक आय।
3. दूसरी पंक्ति पढ़ें: **Complimentary value** (मुफ़्त licences की list price पर कीमत, महीने और साल की), **Trial pipeline** (trial वाली agencies list price पर), **Collected this month** (invoice की गई राशि के साथ) और **Outstanding** (कुछ overdue होने पर लाल, overdue राशि और invoices की संख्या के साथ)।
4. **This month** में MRR का बदलाव देखें: **New**, **Churned** और **Net**।

> ℹ️ अभी कोई भुगतान करने वाला ग्राहक नहीं? एक note बताता है कि कितनी agencies मुफ़्त licences पर हैं और list price पर उनकी कीमत क्या है। यह राशि कभी charge नहीं होती।

## रुझान और plan mix देखना

1. **MRR, last 12 months** देखें। ठोस रेखा paid MRR है; बिंदुदार रेखा list price पर मुफ़्त licences की कीमत है।
2. **Plan mix** में हर plan का MRR में हिस्सा देखें (या मुफ़्त कीमत का, जब अभी कोई भुगतान नहीं करता)। हर plan के साथ उसकी agencies की संख्या दिखती है।
3. **Invoiced vs collected** में हर महीने जारी हुए KiddieTrac invoices और मिले भुगतान देखें। refunds collected से घटते हैं।

## बकाया और renewals का पीछा करना

1. **Receivables** देखें: agencies पर कितना बकाया है, **Current**, **1–30 days**, **31–60**, **61–90** और **90+** में बँटा हुआ। राशि देखने के लिए किसी रंग पर cursor रखें।
2. **Agencies by value** देखें: हर agency का plan, type, **Boarded** तारीख़ और **Per month** मूल्य; पहले paid MRR, फिर मुफ़्त कीमत।
3. **Renewals, next 90 days** देखें: हर licence की अवधि कब खत्म होती है। **Auto** में **✓** का मतलब अपने आप renew; **Ends** का मतलब कोई renew न करे तो बंद।
4. **By part number** में हर product की live lines, paid lines, **MRR** और **Comp / mo** देखें।
5. किसी agency पर कार्रवाई (renew, बदलाव, invoice) के लिए **Licences** या **Accounting AR/AP** पर जाएँ।

[[show: #admin-mrr @ Renewals, next 90 days | मुझे दिखाएँ]]

## कौन क्या कर सकता है

| भूमिका | क्या देखते हैं |
|---|---|
| Platform admin | पूरा dashboard, सभी agencies के लिए। |
| Agency admin, centre director, बाकी सभी | कुछ नहीं: screen उनके menu में नहीं है और server आँकड़े नहीं देता। agencies अपना bill अपनी Billing screen पर देखती हैं। |

## अक्सर पूछे जाने वाले सवाल और समस्याएँ

??? किसी tile पर $0 दिखता है। | उस तरह का कुछ अभी है ही नहीं, जैसे कोई paid licence या इस महीने कोई invoice नहीं। tables भी यही बताती हैं ("No agencies on a licence yet.", "Nothing owing.")।
??? "No licence renews in the next 90 days." | अगले 90 दिनों में कोई licence खत्म नहीं होता। बाद की तारीख़ों के लिए Licences देखें।
??? आँकड़े किसी invoice से मेल नहीं खाते। | MRR हर paid licence को एक महीने के हिसाब में बदलता है (सालाना licence का बारहवाँ हिस्सा)। Invoiced और collected असली invoices और भुगतान हैं, refunds घटाकर।
??? page की जगह लाल संदेश दिखता है। | आँकड़े लोड नहीं हो सके। refresh करें; यह screen केवल platform admins खोल सकते हैं।
??? क्या dashboard किसी को email भेजता है? | नहीं। यह सिर्फ़ पढ़ता है। renewal notices और invoices Licences और Accounting AR/AP से जाते हैं।

## संबंधित screens

[[open: #licences | Licences]] [[open: #sales-invoices | Accounting AR/AP]] [[open: #platform-sales | Sales reports]] [[open: #usage-spend | Usage & spend]] [[open: #admin-features | Feature flags]]
