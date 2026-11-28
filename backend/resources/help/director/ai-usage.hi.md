---
title: AI usage, credits और Claude connection
---
# AI usage, credits और Claude connection

**Platform › AI usage** KiddieTrac की हर AI call और उसकी लागत, बचे हुए AI credit का अनुमान, Claude connection (key और model), और जिन agencies का AI बंद है, उन्हें दिखाता है। इसे ख़र्च देखने के लिए, यह जानने के लिए कि कौन-सा feature, agency या व्यक्ति सबसे ज़्यादा AI उपयोग करता है, credit top-up दर्ज करने के लिए, या key या model बदलने के लिए खोलें।

[[open: #ai-usage | AI usage खोलें]]

screen में पाँच tabs हैं: **📊 Overview**, **🧩 Breakdown**, **💳 Credits**, **🤖 Connection** और **🚫 Switched off**। यह उसी tab पर खुलती है जो आपने पिछली बार उपयोग किया था।

## एक नज़र में स्थिति देखना

1. **Platform › 🤖 AI usage** खोलें और **📊 Overview** दबाएँ।
2. status pill पढ़ें: **✅ Connected**, **⛔ No key set**, **⛔ Out of credit**, आख़िरी error के साथ **⚠️**, या **Not used yet**। उसके पास: model, लगभग कितना credit बचा है (और लगभग कितने दिन), और कितनी agencies बंद हैं।
3. चार tiles **Today**, **Last 7 days**, **Last 30 days** और **This month** पढ़ें: अनुमानित लागत, requests और आने-जाने वाले tokens।
4. पिछले 30 दिनों का **📈 Estimated cost per day** देखें। उस दिन की लागत और requests देखने के लिए किसी bar पर cursor रखें।

## कौन और क्या AI उपयोग करता है, पता करना

1. **🧩 Breakdown** दबाएँ।
2. कोई **Period** चुनें (**Last 7 days**, **Last 30 days**, **This month**, **Last month**, **Last 90 days**) या **Custom dates** के लिए **From** और **To** तारीख़ें लिखें।
3. tiles पढ़ें: **Estimated cost**, **AI requests** (कितनी अपने आप हुईं), **Tokens** और **People using AI**।
4. एक view चुनें: **🧩 By feature**, **🏢 By agency**, **🧠 By model** या **👤 By user**। हर एक सामान्य table है: उसमें खोजें, किसी भी column से sort करें, और CSV, Excel या PDF में export करें।
5. **👤 By user** में किसी व्यक्ति के नाम पर दबाएँ और उसकी **Recent calls** देखें: किसने माँगा, कब, model, tokens और लागत। काम होने पर **Close** दबाएँ।

> ℹ️ जिन calls में कोई signed-in व्यक्ति नहीं है (रात के digests और summaries, website chatbot), वे **Automatic** ("System") दिखती हैं। background job उसी व्यक्ति के नाम गिना जाता है जिसने उसे शुरू किया। prompts और answers कभी नहीं रखे जाते, सिर्फ़ किसने माँगा, कब, model, आकार और लागत।

## Credit top-up दर्ज करना

Anthropic prepaid balance API key को नहीं बताता, इसलिए बचा हुआ credit एक **अनुमान** है: आपके दर्ज किए गए top-ups, उसके बाद KiddieTrac के ख़र्च को घटाकर।

1. **💳 Credits** दबाएँ।
2. **Credits added ($)** में राशि लिखें।
3. **On** में तारीख़ जाँचें और चाहें तो **Note** जोड़ें।
4. **+ Record top-up** दबाएँ। **Top-up recorded** toast दिखता है और अनुमान अपडेट होता है।
5. ग़लत entry हटाने के लिए उसके पास **Remove** दबाएँ।

> 💡 समय-समय पर Anthropic console में असली balance जाँचें (link tab पर है), और हर top-up उसी दिन दर्ज करें जिस दिन ख़रीदें।

## Key या model बदलना

1. **🤖 Connection** दबाएँ। आपको key के आख़िरी 4 अक्षर, वह कहाँ से set है, model और आख़िरी जवाब या error दिखते हैं।
2. एक **Model** चुनें और, सिर्फ़ key बदलनी हो तो, उसे **New API key** में paste करें (मौजूदा रखने के लिए ख़ाली छोड़ें)।
3. **Save** दबाएँ। button पर **Testing…** दिखता है: नई key या model पहले Anthropic के साथ आज़माया जाता है और काम करने पर ही रखा जाता है (**Saved and tested**)।
4. मौजूदा key अब भी जवाब दे रही है या नहीं, यह जाँचने के लिए कभी भी **Test connection** दबाएँ।

## किसी agency के लिए AI बंद करना

1. **🚫 Switched off** दबाएँ।
2. हर उस agency पर tick करें जिसे कोई AI credit उपयोग नहीं करना चाहिए (जैसे test और demo agencies)।
3. **Save** दबाएँ।

tick की गई agencies के AI Bundle features बंद हो जाते हैं, और Help का "Ask AI", chatbot और card scanning ऐसे चलते हैं जैसे AI उपलब्ध न हो।

## कौन क्या कर सकता है

| भूमिका | पहुँच |
|---|---|
| Platform admin | इस screen पर सब कुछ। |
| Agency admins और बाकी सभी | कोई पहुँच नहीं। agencies सिर्फ़ अपने AI features देखती हैं, लागत या keys कभी नहीं। |

## अक्सर पूछे जाने वाले सवाल और समस्याएँ

??? pill पर "Out of credit" लिखा है। | आख़िरी call इसलिए विफल हुई क्योंकि Anthropic account में credit नहीं है। Anthropic console में credit ख़रीदें, फिर Credits पर top-up दर्ज करें।
??? Save दबाने पर "Nothing to change"। | key box ख़ाली है और model वही है। दूसरा model चुनें या नई key paste करें।
??? Save के बाद "Not saved" या कोई और लाल संदेश। | Anthropic ने नई key या model ठुकरा दिया, इसलिए पुराना ही रखा गया। देखें कि key पूरी copy हुई थी।
??? "Enter the amount of credits added"। | + Record top-up दबाने से पहले Credits added ($) में शून्य से बड़ी संख्या लिखें।
??? किसी model पर "flat rate: not on the price list" लिखा है। | उस model की कीमत अभी सूची में नहीं है, इसलिए उसकी लागत Usage & spend की AI rates से निकलती है।
??? "No AI calls in this period." | चुनी गई तारीख़ों में किसी ने AI उपयोग नहीं किया। Period बड़ा करें।
??? क्या किसी को सूचना जाती है? | नहीं। यहाँ के बदलाव कोई email या notification नहीं भेजते।

## संबंधित screens

[[open: #usage-spend | Usage & spend]] [[open: #admin-features | Feature flags]] [[open: #server-health | Server health]]
