---
title: डेटाबेस बैकअप
---
# डेटाबेस बैकअप

**Platform › Backups** दिखाता है कि platform database की रात की copy सच में बन रही है या नहीं, आख़िरी अच्छी copy कब बनी, और कितनी रखी गई हैं। यहीं आप backup का समय और copies कितने दिन रखनी हैं, बदलते हैं, और किसी जोखिम भरे काम से पहले हाथ से copy बनाते हैं। इसे रोज़ की जाँच में, किसी बड़े बदलाव से पहले, या जब Server health पर newest backup लाल दिखे, तब खोलें।

[[open: #backups | Backups खोलें]]

## आप सुरक्षित हैं या नहीं, जाँचना

1. **Platform › 🗄️ Backups** खोलें।
2. ऊपर के card की रंगीन line पढ़ें:
   - **Healthy**: पिछले लगभग एक दिन में अच्छी copy बनी।
   - **Older than a day**: कल रात के run से copy नहीं बनी।
   - **Stale — more than 2 days old**: इसे विफलता मानें।
   - **Last run failed**: error नीचे लाल में दिखता है।
   - **No backup recorded yet**।
3. **Last good copy** (कितनी देर पहले और सही समय), **copies kept** की संख्या, **MB on disk** और आख़िरी copy का आकार पढ़ें।
4. **Scheduler last seen** जाँचें। platform का हर scheduled job, सिर्फ़ backups नहीं, एक ही scheduler पर चलता है जो हर मिनट चलता है। अगर लिखा है **never — the cron may not be running**, तो कुछ भी scheduled नहीं हो रहा। लाल **backup folder is not writable** का मतलब copies सहेजी नहीं जा सकतीं।

## Schedule बदलना

1. दूसरे card पर **Nightly backup** चालू रहने दें। बंद का मतलब database की कोई copy नहीं बनती।
2. **Time** server समय में तय करें। शांत समय चुनें: copy कुछ lock नहीं करती, पर हर table पढ़ती है। default 03:30 है।
3. **Keep** तय करें, यानी कितनी रोज़ की copies रखनी हैं (1 से 90 दिन; default 14)। पुरानी copies हर run के बाद मिटा दी जाती हैं।
4. **Save** दबाएँ। **✓ Saved** दिखता है, या अगर आपने backups बंद किए, तो tick की जगह पीला **⚠ Saved — nightly backups are now OFF**।

> ⚠️ backups बंद होने पर database की कोई रात की copy नहीं बनती। बंद करना audit log में दर्ज होता है।

## अभी backup लेना

यह किसी जोखिम भरे बदलाव से पहले करें, जैसे बड़ा import या upgrade।

1. **Back up now** दबाएँ।
2. **Taking a backup… this reads every table, give it a moment.** दिखने तक रुकें। button परिणाम का इंतज़ार करता है।
3. **✓ Backup taken** दिखता है और नई copy **Copies on disk** में आ जाती है, या लाल error दिखता है और status card पर **Last run failed** आता है।

[[show: #backups @ Back up now | मुझे दिखाएँ]]

## Disk पर copies

नीचे का card सबसे हाल की copies दिखाता है, हर एक के समय, file नाम और आकार के साथ। हर copy लिखे जाने के बाद जाँची जाती है; विफल या ख़राब copy रखी नहीं, फेंक दी जाती है। copies encrypted होती हैं ताकि सिर्फ़ KiddieTrac की offline key ही उन्हें खोल सके; जो copy encrypt नहीं हो पाई, वह फिर भी रखी जाती है और एक alert बनाती है, क्योंकि कोई backup न होने से backup होना बेहतर है।

> ℹ️ **Copies portal से download नहीं की जा सकतीं, जानबूझकर।** एक ही file में platform के हर बच्चे, अभिभावक, पते और medical note होते हैं। ये website के बाहर रखी जाती हैं और server access और offline key वाला कोई व्यक्ति ही इन्हें restore करता है, browser से कभी नहीं।

## कौन क्या कर सकता है

| भूमिका | पहुँच |
|---|---|
| Platform admin | इस screen पर सब कुछ। |
| Agency admins और बाकी सभी | कोई पहुँच नहीं। एक ही database सभी agencies के लिए है, इसलिए backup में सबका data होता है, किसी एक agency का नहीं। server किसी और को अनुमति नहीं देता। |

## अक्सर पूछे जाने वाले सवाल और समस्याएँ

??? status पर "Last run failed" लिखा है। | नीचे लाल error पढ़ें। कारण ठीक करें (अक्सर disk space), फिर Back up now दबाकर देखें कि काम करता है। विफल run Security alerts पर एक high-severity alert भी बनाता है।
??? "Scheduler last seen: never — the cron may not be running"। | scheduler रुक गया है, इसलिए कोई scheduled job नहीं चलता। यह ज़रूरी है: server पर इसे फिर से शुरू करवाएँ।
??? "Backups are managed by platform administrators only." | आप platform admin के रूप में signed in नहीं हैं।
??? मैंने backups जानबूझकर बंद किए। क्या हर रात alerts आएँगे? | नहीं। बंद करने को विफलता नहीं माना जाता।
??? क्या कोई agency अपना data वापस माँग सकती है? | इस screen के backup से नहीं। agency की अपनी export और privacy request screens का उपयोग करें।
??? backup चलने पर क्या किसी को सूचना जाती है? | नहीं। सिर्फ़ विफलताओं पर alert बनता है; settings के बदलाव और हाथ से लिए गए backups पहले और बाद के मानों के साथ audit log में जाते हैं।

## संबंधित screens

[[open: #server-health | Server health]] [[open: #security-alerts | Security alerts]] [[open: #compliance-evidence | Compliance evidence]] [[open: #maintenance | Maintenance]]

यह भी देखें: [Security alerts और monitoring](security-alerts), [Data retention और compliance](data-retention-compliance).
