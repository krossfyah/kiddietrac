---
title: Server health और leak guard
---
# Server health और leak guard

**Platform › Server health** एक ही page पर बताता है कि portal ठीक है या नहीं: machine (processor, memory, disk), database, background jobs, scheduler, backups, पिछले 24 घंटों में लोगों ने क्या महसूस किया (errors, धीमे और विफल requests), लोगों के devices पर portal कितना तेज़ था, search words, **Leak guard** जाँचें और web certificates। इसे तब खोलें जब लोग कहें कि portal धीमा है, किसी deploy के बाद, या रोज़ की जाँच के रूप में।

[[open: #server-health | Server health खोलें]]

खुला रहने पर page हर 30 सेकंड में अपने आप refresh होता है। इसमें दो tabs हैं: **🖥️ This server** (portal के अपने आँकड़े) और **☁️ Cloudways · new server** (hosting account के graphs)।

## रोज़ की जाँच करना

1. **Platform › 🖥️ Server health** खोलें और **🖥️ This server** दबाएँ।
2. ऊपर की alert lines पढ़ें। **✅ Everything looks healthy.** का मतलब कोई सीमा पार नहीं हुई; वरना हर समस्या सरल शब्दों में 🟠 (चेतावनी) या 🔴 (ज़रूरी) line पर दिखती है।
3. cards पढ़ें: **Processor**, **Memory**, **Disk** (uploads, backups और logs के साथ), **Database** (जवाब का समय और connections), **Background jobs** (प्रतीक्षा में, सबसे लंबी प्रतीक्षा, आज विफल), **Scheduled jobs** (**Running** या **Not running**, आख़िरी check-in), **Newest backup** (कितना पुराना, encrypted है या नहीं, रखी गई copies) और **Last 24 hours** (errors, धीमे requests, कभी न पहुँचे requests, rate-limited)। हरा ठीक है, पीला देखने लायक, लाल पर कार्रवाई ज़रूरी।
4. पीछे देखने के लिए अवधि चुनें (**Last 6 hours** से **Last 14 days**) और **History** graphs देखें, जो हर 5 मिनट में भरते हैं।
5. सब कुछ तुरंत फिर से पढ़ने के लिए **↻ Refresh** दबाएँ।

## Leak guard जाँचना

**Leak guard** card वे स्वचालित जाँचें दिखाता है जो सुनिश्चित करती हैं कि कोई agency किसी दूसरी agency का data न देख सके।

1. **Leak guard** तक scroll करें।
2. हर row का **Result** पढ़ें:
   - **Route probe (latest)**: हर घंटे हर role के रूप में portal के requests के एक हिस्से की जाँच।
   - **Route probe (full, nightly)**: वही जाँच हर रात सभी routes पर।
   - **Data check**: सहेजे गए data की ख़ुद की जाँच।
   - **Live sentinel**: **Last 7 days** के असली responses का एक नमूना।
3. **Clean** (हरा) का मतलब कुछ नहीं मिला। **… finding(s)** या **… hit(s)** (लाल) का मतलब संभावित leak: विवरण table के नीचे लाल में दिखता है। **Blind: … role(s)** (पीला) का मतलब जाँच कुछ roles के रूप में sign in नहीं कर पाई, इसलिए कम जाँच हुई। **Not run yet** का मतलब setup के बाद जाँच अभी चली नहीं है।

> ⚠️ Leak guard के किसी भी लाल परिणाम को ज़रूरी मानें: table के नीचे की lines पढ़ें और उसी दिन कारण ठीक करें।

## Portal धीमा क्यों लगता है, पता करना

1. **Connections (devices, last …)** पढ़ें: **Sessions measured**, **On a slow connection** (2G/3G पर या 2 सेकंड से ज़्यादा प्रतीक्षा वाले प्रतिशत), **Typical wait per request** और **Requests that failed**। एक table सबसे धीमे sessions वाली agencies दिखाती है।
2. **Where problems happened (24 h)** पढ़ें: हर **Server error**, **Slow** या **Never arrived** request, किस screen या request पर हुआ, और कितनी **Times**।
3. धीमी queries और सबसे बड़ी tables के लिए **Database** section देखें।
4. **☁️ Cloudways · new server** दबाएँ, अवधि चुनें और hosting graphs देखें (processor, memory, disk, bandwidth, हर app का disk उपयोग)। फिर से पढ़ने के लिए **↻ Cloudways** दबाएँ।

## अन्य sections

- **Search words**: portal की search हर role के लिए कितने शब्द जानती है, आख़िरी बार कोई screen कब report हुई, इस सप्ताह क्या सीखा और क्या ठुकराया गया। लाल **Last screen reported** का मतलब search ने सीखना बंद कर दिया है।
- **Certificates**: हर web address, उसका certificate कब **Expires**, **Days left** और **Issuer**। certificates अपने आप renew होते हैं; कम **Days left** का मतलब renewal विफल रहा।

## Hosting account जोड़ना

1. **☁️ Cloudways · new server** दबाएँ।
2. hosting account में access token बनाने के लिए tab पर दिए निर्देशों का पालन करें।
3. उसे box में paste करें और **Connect Cloudways** दबाएँ।
4. हटाने के लिए **Disconnect** दबाएँ और पुष्टि करें। token KiddieTrac से मिट जाता है; आप कभी भी फिर से जोड़ सकते हैं।

## कौन क्या कर सकता है

| भूमिका | पहुँच |
|---|---|
| Platform admin | पूरा page। |
| बाकी सभी | page बताता है कि यह सिर्फ़ **platform administrators** के लिए है। |

## अक्सर पूछे जाने वाले सवाल और समस्याएँ

??? "Scheduled jobs: Not running"। | हर समयबद्ध job (backups, reminders, digests) चलाने वाला scheduler रुक गया है। यह ज़रूरी है: दोबारा शुरू होने तक कुछ भी scheduled नहीं चलेगा।
??? "Newest backup" लाल है या NOT encrypted लिखा है। | पिछले 30 घंटों में कोई अच्छा backup नहीं, या copy encrypted नहीं है। आख़िरी error देखने के लिए Backups खोलें।
??? "The server could not be read just now." | आँकड़े लोड नहीं हो सके। एक मिनट बाद ↻ Refresh दबाएँ।
??? "Collecting — the history fills in every 5 minutes." | history नई है या साफ़ हुई है। कुछ मिनट रुकें।
??? Leak guard पर "Blind" लिखा है। | probe एक या ज़्यादा roles के रूप में काम नहीं कर पाया, इसलिए जाँच अधूरी है। इसे अभी साफ़ न मानें।
??? क्या यह page किसी को सूचना देता है? | page ख़ुद कुछ नहीं भेजता; सिर्फ़ दिखाता है। विफल backup Security alerts पर भी एक alert बनाता है।

## संबंधित screens

[[open: #backups | Backups]] [[open: #security-alerts | Security alerts]] [[open: #ai-usage | AI usage]] [[open: #maintenance | Maintenance]] [[open: #compliance-evidence | Compliance evidence]]
