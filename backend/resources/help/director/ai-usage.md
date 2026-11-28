---
title: AI usage, credits and the Claude connection
category: Administration
order: 64
roles: platform_admin
screens: ai-usage
---
# AI usage, credits and the Claude connection

**Platform › AI usage** shows every AI call KiddieTrac makes and what it costs, an estimate of the AI credit left, the Claude connection (key and model), and the agencies whose AI is switched off. Open it to check spending, to find which feature, agency or person uses AI most, to record a credit top-up, or to change the key or model.

[[open: #ai-usage | Open AI usage]]

The screen has five tabs: **📊 Overview**, **🧩 Breakdown**, **💳 Credits**, **🤖 Connection** and **🚫 Switched off**. It reopens on the tab you used last.

## Checking the state at a glance

1. Open **Platform › 🤖 AI usage** and press **📊 Overview**.
2. Read the status pill: **✅ Connected**, **⛔ No key set**, **⛔ Out of credit**, **⚠️** with the last error, or **Not used yet**. Beside it: the model, about how much credit is left (and roughly how many days), and how many agencies are switched off.
3. Read the four tiles **Today**, **Last 7 days**, **Last 30 days** and **This month**: estimated cost, requests and tokens in and out.
4. Look at **📈 Estimated cost per day** for the last 30 days. Hover a bar for that day's cost and requests.

## Finding who and what uses AI

1. Press **🧩 Breakdown**.
2. Pick a **Period** (**Last 7 days**, **Last 30 days**, **This month**, **Last month**, **Last 90 days**) or type **From** and **To** dates for **Custom dates**.
3. Read the tiles: **Estimated cost**, **AI requests** (with how many were automatic), **Tokens** and **People using AI**.
4. Pick a view: **🧩 By feature**, **🏢 By agency**, **🧠 By model** or **👤 By user**. Each is a normal table: search it, sort by any column, and export it to CSV, Excel or PDF.
5. In **👤 By user**, press a person's name to see their **Recent calls**: what asked, when, the model, tokens and cost. Press **Close** when done.

> ℹ️ Calls with no signed-in person (nightly digests and summaries, the website chatbot) show as **Automatic** ("System"). A background job counts for the person who started it. Prompts and answers are never stored, only what asked, when, the model, the size and the cost.

## Recording a credit top-up

Anthropic does not give the prepaid balance to an API key, so the credit left is an **estimate**: the top-ups you record, minus what KiddieTrac spent since.

1. Press **💳 Credits**.
2. Type the amount in **Credits added ($)**.
3. Check the date in **On** and add a **Note** if you like.
4. Press **+ Record top-up**. A **Top-up recorded** toast appears and the estimate updates.
5. To take a wrong entry back, press **Remove** beside it.

> 💡 Check the exact balance in the Anthropic console (the link on the tab) now and then, and record each top-up the day you buy it.

## Changing the key or the model

1. Press **🤖 Connection**. You see the last 4 characters of the key, where it is set, the model and the last answer or error.
2. Pick a **Model** and, only if you are replacing the key, paste it into **New API key** (leave it blank to keep the current one).
3. Press **Save**. The button shows **Testing…**: the new key or model is tried with Anthropic first, and kept only if it works (**Saved and tested**).
4. Press **Test connection** any time to check that the current key still answers.

## Switching AI off for an agency

1. Press **🚫 Switched off**.
2. Tick each agency that should use no AI credit (test and demo agencies, for example).
3. Press **Save**.

Ticked agencies lose the AI Bundle features, and Help "Ask AI", the chatbot and card scanning behave as if AI were unavailable.

## Who can do what

| Role | Access |
|---|---|
| Platform admin | Everything on this screen. |
| Agency admins and everyone else | No access. Agencies see their AI features only, never costs or keys. |

## FAQs and when something goes wrong

??? The pill says "Out of credit". | The last call failed because the Anthropic account has no credit. Buy credit in the Anthropic console, then record the top-up on Credits.
??? "Nothing to change" when I press Save. | The key box is empty and the model is the same. Pick another model or paste a new key.
??? "Not saved" or another red message after Save. | Anthropic refused the new key or model, so the old one is kept. Check the key was copied whole.
??? "Enter the amount of credits added". | Type a number above zero in Credits added ($) before pressing + Record top-up.
??? A model shows "flat rate: not on the price list". | That model has no listed price yet, so its cost uses the AI rates on Usage & spend.
??? "No AI calls in this period." | Nothing used AI in the dates you picked. Widen the Period.
??? Does anyone get notified? | No. Changes here send no email or notification.

## Related screens

[[open: #usage-spend | Usage & spend]] [[open: #admin-features | Feature flags]] [[open: #server-health | Server health]]
