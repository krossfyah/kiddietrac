---
title: Payment providers (Helcim, Zum Rails, Stripe, Interac, gift cards)
category: Billing
order: 12
roles: agency_admin, platform_admin
screens: payment-providers
---
# Payment providers (Helcim, Zum Rails, Stripe, Interac, gift cards)

**Settings → Payment providers** is where you connect the payment accounts your agency is paid through: **Helcim**, **Zum Rails** and **Stripe** for cards and bank payments, **Interac e-Transfer** instructions for families, and the **🎁 Gift cards** services used for educator rewards.

[[open: #payment-providers | Open Payment providers]]

Each agency banks its own money, so each agency enters its own keys. Nothing falls back to a KiddieTrac account or to another agency's. Until your keys are entered and the provider is switched on, that provider cannot take or return any money for you.

## Reading the screen

There is one tab per provider. The dot beside each name shows whether it is ready: green means a working credential is stored, grey means **Not configured yet**. The same status is repeated at the bottom right of each tab as **● Ready** or **○ Not configured yet**.

Every card and bank provider tab has the same two controls at the top:

- **Enabled**: when this is off, no payment can be taken or sent through the provider, whatever is stored below.
- **Mode**: **Sandbox** or **Production**. Stay on sandbox until you have tested a real payment from start to finish.

> ℹ️ Secrets are never shown again once saved. A stored key appears only as its last four characters (for example ••••a1b2). Leaving a secret box empty when you save keeps the key already stored, so you can change a URL without re-entering every key.

## Connecting Helcim (credit and debit cards)

Helcim takes credit and debit cards, and handles refunds and voids. Families enter the card inside Helcim's own window, so a card number never reaches KiddieTrac.

[[show: #payment-providers @ Helcim | Show me the Helcim tab]]

1. In Helcim, go to **All Tools → Integrations → API Access** and create an API token with the **Payment API** permissions enabled.
2. In KiddieTrac, open the **🍁 Helcim** tab.
3. Paste the token into **API token**.
4. Optionally, fill in **Terminal ID (optional)**.
5. Enter the **Currency (CAD or USD)**.
6. Tick **Enabled** and set **Mode** to match the token you pasted.
7. Press **Save Helcim**. You see **✓ Saved** and the dot turns green.
8. Press **Test connection**.

The test asks Helcim whether it accepts the stored token. You see **✓ Helcim accepted this token** followed by "LIVE, real cards will be charged" or "labelled as sandbox", and the currency. If the token is wrong or lacks permissions, the reason is shown in red.

> ⚠️ Helcim has no separate sandbox address. A test token and a live token both reach the same Helcim service, and **the token decides whether real money moves**. The **Mode** setting only labels what staff and families see.

> 💡 Always press **Test connection** after saving. A wrong token still saves and still shows a green dot; the test is the only way to know it works before a family tries to pay.

A Helcim payment is recorded only once KiddieTrac has verified Helcim's result, for the amount Helcim reports as settled. Refunds go back to the card automatically: a same-day, full refund is first tried as a **void** (it disappears from the statement); otherwise it is refunded through Helcim. Only a refund can be partial.

## Connecting Stripe (cards, saved cards, Apple Pay and Google Pay)

1. In your Stripe dashboard, open **Developers → API keys**.
2. In KiddieTrac, open the **💳 Stripe** tab.
3. Paste the **Publishable key** and the **Secret key**. Leave **Webhook signing secret** empty: KiddieTrac fills it in when it connects.
4. Tick **Enabled**, choose the **Mode**, and press **Save Stripe**.
5. Under **Payment updates from Stripe**, check the line reads **● Connected**. If it reads **○ Not connected yet**, press **Connect** (or **Reconnect**).
6. To offer Apple Pay and Google Pay on invoices, press **Turn on Apple Pay & Google Pay**. You see **✓ Apple Pay and Google Pay are on for** followed by your portal's addresses.

"Connected" means a payment a parent finishes after closing the app is still recorded. While it is not connected, a payment is recorded only while the parent waits on the screen.

## Connecting Zum Rails (bank payments and cards)

1. In your Zum portal, open **Settings → Webhook & API**.
2. In KiddieTrac, open the **🏦 Zum Rails** tab.
3. Fill in **API base URL**, **Zum wallet ID**, **API username**, **API password**, **Webhook secret** and **Refund endpoint path**.
4. Tick **Enabled**, choose the **Mode**, and press **Save Zum Rails**.
5. Copy the **Callback URL for their portal** shown under the boxes and paste it into Zum, with the same webhook secret.

> ⚠️ Zum payments are confirmed only when Zum calls that address, so a wrong URL fails quietly when the payment settles.

## Offering Interac e-Transfer

There is nothing to connect: families send money from their own bank to your e-Transfer email, and you record the payment when it lands.

1. Open the **Interac e-Transfer** tab.
2. Tick **Accept Interac e-Transfer**. Families then get a "Pay by Interac e-Transfer" button on Billing, and invoices list it under How to pay.
3. Enter the **e-Transfer email (where families send money)** and, if you like, the **Recipient name (as their bank will show it)**.
4. Under **Autodeposit**, say whether Autodeposit is on. If it is not, fill in the **Security question** and **Answer**.
5. Optionally, write **Your instructions for families (optional)** and tick **Families can ask for an e-Transfer request**.
6. Press **👁 Preview what families see** to check the sheet a family will get.
7. Press **Save Interac e-Transfer**.

## Connecting a gift card service

The **🎁 Gift cards** tab holds your agency's own **Giftbit** and **Tango Card** accounts, used to buy gift cards for educator rewards. Cards are paid from your own account there.

1. Open the **🎁 Gift cards** tab.
2. For Giftbit: choose the **Environment**, paste the **API token**, and tick **Use Giftbit for educator rewards**. For Tango Card: fill in **Platform name**, **API key**, **Customer identifier** and **Account identifier**, and tick **Use Tango Card for educator rewards**.
3. Press **Save**.
4. Press **Test connection** to check the account answers.

## Who can do what

| Role | Can do |
|---|---|
| Agency admin | Everything on this screen for their own agency. |
| Platform admin | The same, for the agency they are viewing. |
| Centre director | Does not see this screen: these keys move the agency's money and belong with whoever owns the bank account. |

## FAQs and when something goes wrong

??? The card and bank tabs are missing; only Interac and Gift cards show. | Card payments are part of your plan's payments feature. Without it a grey note explains why (for example "Payment accounts are not available.") and only the Interac e-Transfer and gift card tabs work. Ask your KiddieTrac contact about adding payments.
??? The dot is green but families can't pay. | Green only means a value is stored. Check **Enabled** is ticked, press **Test connection** (Helcim), check **Payment updates from Stripe** says Connected (Stripe), or check the callback URL in your Zum portal.
??? I see "Could not save" in red. | The message after it says why (for example a missing value). Fix it and press Save again. Nothing was changed.
??? "Add the e-Transfer email families send money to." | You ticked Accept Interac e-Transfer without an email address. Add the address, then save.
??? "Add the security question and its answer, or choose Autodeposit." | You chose "No Autodeposit". Families need a question and answer to accept the transfer, so fill both in.
??? Apple Pay shows a warning after I press the button. | The warning names the address and what Stripe said. Check Stripe is in the right mode and press the button again; Google Pay needs nothing more.
??? Do families get told when I change providers? | No. Nothing is sent to families from this screen. They simply see the payment options that are switched on.

## Related screens

[[open: #payment-run | Payment run]] [[open: #refunds | Refunds]] [[open: #billing-settings | Billing settings]] [[open: #educator-rewards | Educator rewards]]

See also: [Payment surcharges](payment-surcharges) · [Refunding a payment](refunds) · [Billing setup & settings](billing-setup) · [Payment run](autopay)
