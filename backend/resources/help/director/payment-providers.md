---
title: Payment providers (Helcim, Zum Rails, Stripe)
category: Billing
order: 12
roles: agency_admin, platform_admin
---
# Payment providers

**Settings → Payment providers.** This is where you connect the payment accounts your agency is paid through: **Zum Rails**, **Stripe** and **Helcim**.

[[open: #payment-providers | Open Payment providers]]

Each agency banks its own money, so each agency enters its own keys. Nothing falls back to a KiddieTrac account or to another agency's. Until your keys are entered and the provider is switched on, that provider cannot take or return any money for you.

## Reading the screen

There is one tab per provider. The dot beside each name shows whether it is ready: green means a working credential is stored, and grey means **Not configured yet**. The same status is repeated at the bottom of each tab as **● Ready** or **○ Not configured yet**.

Every tab has the same two controls at the top:

- **Enabled**: when this is off, no payment can be taken or sent through the provider, whatever is stored below.
- **Mode**: **Sandbox** or **Production**. Stay on sandbox until you have tested a real payment from start to finish.

**Secrets are never shown again once saved.** A stored key appears only as its last four characters, for example **••••a1b2**. Leaving a secret box empty when you save keeps the key that is already stored, so you can change a URL without re-entering every key.

## Helcim (credit and debit cards)

[[show: #payment-providers @ Helcim | Show me the Helcim tab]]

Helcim takes **credit and debit cards**, and handles **refunds and voids**. Families enter the card inside Helcim's own window, so a card number never reaches KiddieTrac.

- [ ] In Helcim, go to **All Tools → Integrations → API Access** and create an API token with the **Payment API** permissions enabled.
- [ ] Paste it into **API token**.
- [ ] Optionally, fill in **Terminal ID (optional)**.
- [ ] Enter the **Currency (CAD or USD)**.
- [ ] Tick **Enabled**, set **Mode**, and click **Save Helcim**.
- [ ] Click **Test connection**.

**Test connection** asks Helcim whether it accepts the stored token. You will see **✓ Helcim accepted this token** followed by either **LIVE, real cards will be charged** or **labelled as sandbox**, and the currency. If the token is wrong or lacks permissions, the reason is shown in red.

[[show: #payment-providers @ Helcim > Test connection | Show me Test connection]]

> **Important:** Helcim has no separate sandbox address. A test token and a live token both reach the same Helcim service, and **the token decides whether real money moves**. The Mode setting only labels what staff and families see, so set it to match the kind of token you pasted.

> **Tip:** Always use **Test connection** after saving. A token that is wrong, or missing permissions, still saves and still shows a green dot. The test is the only way to confirm it works before a family tries to pay.

### How a Helcim payment is recorded

A payment is recorded **only once KiddieTrac has verified Helcim's result**. The amount recorded is the amount Helcim reports as settled, not the amount that was asked for. The invoice balance and status then follow what has actually been paid. An invoice that is already void or refunded is left as it is for a person to look at, even if a payment arrives against it.

### Refunding a Helcim payment

Refund it from **Refunds** or from the family's account ledger, in the same way as any other payment. The money goes back to the card automatically:

- **Same day, full amount**: KiddieTrac first tries to **void** the charge, so it disappears from the cardholder's statement instead of showing as a charge and a credit.
- **Otherwise**, or if the void is refused: it is **refunded** through Helcim. Only a refund can be partial.

If Helcim is no longer set up for your agency, the refund is refused with a message explaining why, and no refund is recorded. See [Refunding a payment](refunds).

## Zum Rails (bank payments and cards)

Zum Rails handles **bank payments (EFT) and card payments** from families, and refunds. Your credentials come from your Zum portal, under **Settings → Webhook & API**. The tab asks for the **API base URL**, **Zum wallet ID**, **API username**, **API password**, **Webhook secret** and **Refund endpoint path**.

Under the boxes, the tab shows a **Callback URL for their portal**. Paste it into Zum together with the same webhook secret you entered here. Payments are confirmed only when Zum calls that address, so a wrong URL fails quietly when the payment settles.

## Stripe

Stripe takes **card payments and saved payment methods**. Your keys come from your Stripe dashboard, under **Developers → API keys**: the **Publishable key**, **Secret key** and **Webhook signing secret**.

## Who can use this

**Agency admins** and **platform admins** only. Centre directors cannot open this screen, because these keys move the agency's money and belong with whoever owns the bank account.

See also: [Payment surcharges](payment-surcharges) · [Refunding a payment](refunds) · [Billing setup & settings](billing-setup)
