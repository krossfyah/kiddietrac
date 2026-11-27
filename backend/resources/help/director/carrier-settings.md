---
title: Carrier settings — Twilio and Telnyx
category: Settings
order: 41
roles: agency_admin, platform_admin
screens: sms-settings
---
# Carrier settings — Twilio and Telnyx

**Settings → Carrier settings** holds the accounts your agency texts and telephones through. Two carriers are supported (Twilio and Telnyx) and two channels run over them: text messages, and voice announcement calls. Open it once when you set up texting, and again when a text or call did not arrive.

[[open: #sms-settings | Open Carrier settings]]

Every agency sets up its own carrier here. One agency's numbers, keys, balance and choices are never visible to another, and only an **agency admin of that agency** can save them. The screen has two tabs, **Text messages** and **Voice calls**, and remembers the one you were on.

## Setting up texting, start to finish

1. Open **Carrier settings** on the **Text messages** tab.
2. Under **Set up carrier**, choose the **Twilio** or **Telnyx** page.
3. For Twilio, enter the **Account SID** (starts with AC) and the **Auth token**, or better an **API key SID** and **API key secret**. For Telnyx, enter the **API key** (starts with KEY).
4. Enter **Send from**: the number in full international form (for example +1 and ten digits).
5. For Telnyx, paste the **Webhook public key** (Mission Control → Keys & Credentials → **Public Key**), so replies and delivery reports are trusted.
6. Copy the **Replies and STOP webhook** (Twilio) or **Inbound message webhook** (Telnyx) into the carrier's portal.
7. Press **Save**. Save stores both carriers at once, whichever page is open.
8. Press **Test Twilio** or **Test Telnyx**. It checks the credentials with the carrier, costs nothing and sends nothing. The result stays in the test log below.

> ℹ️ Credentials are **write-only**. Once saved, the screen only says whether a secret is stored. To change one, type the new value; to keep it, leave the box empty. Take the field names literally: a Telnyx API key is not a Twilio Account SID, and an identifier starting `OQ…` is not an Account SID either.

## Choosing which carrier sends

1. Find the **Which carrier sends** card above the carrier pages. **Both can be set up at once. Only the one chosen here sends.**
2. Pick **Twilio** or **Telnyx**. The page of the carrier now sending is marked **sending**.
3. Tick **If that carrier refuses, try the other one.** if both are set up. The other carrier is only used when the first refuses a message, and a message already accepted is never sent twice.
4. Turn on **Send text messages for this agency**.
5. Press **Save**.

[[show: #sms-settings @ Which carrier sends | Show me the carrier choice]]

## Choosing the kinds of texts this agency sends

1. On the **Text messages** tab, find **Kinds of texts this agency sends**.
2. Tick the kinds you want:

| Kind | What it is |
|---|---|
| Sign in and sign out | When a child is signed in or out, and who by |
| Sign-in reminders | A child was not signed in, or was never signed out |
| Announcements | Announcements, when they are also sent by text |
| Text broadcasts | Messages sent to a group, a room or everyone from SMS broadcast |
| Replies from staff | A staff reply to a text somebody sent to the agency number |

3. Press **Save**.

Untick a kind and nothing of that kind is texted by this agency, whatever anybody has chosen for themselves. Only people who agreed to texts receive any of these, and each person can switch kinds off in their own profile — see [Texts and phone calls](texts-and-phone-calls). Consent confirmations and STOP replies always go.

![Kinds of texts this agency sends, on the Text messages tab](https://api.kiddietrac.com/help-img/carrier-kinds-of-texts.png)

## Sending a real test text or call

1. Under **Send a real test**, type a number you control.
2. Tick **I control this number, or its owner is expecting this.**
3. Press **💬 Send text** or **📞 Place call**.

> ⚠️ This really sends. It is billed by your carrier, says it is a test, names your agency and carries STOP. Limited to agency admins and to five an hour per agency.

## Setting up voice announcement calls

1. Open the **Voice calls** tab. Voice uses the Telnyx API key from the Text messages tab.
2. Enter the **Call Control connection id** (Mission Control → Voice → **Call Control** → your application) and **Call from**, the number parents will see. **Caller name** is optional.
3. Pick a voice from **Choose a voice…** and set the **Language** (for example `en-US`, `en-GB`, `fr-CA`). Neural voices sound far more natural and are billed at a slightly higher rate.
4. Under **Reasons that may place calls**, tick the reasons an announcement call may be sent for: *Closure, Evacuation, Lockdown, Illness at the centre, Other emergency* and *Not an emergency*. The five emergencies start on; *Not an emergency* starts off.
5. Turn on **Place announcement calls for this agency**. It is off until you turn it on.
6. Press **Save**.

[[show: #sms-settings @ Voice calls > Reasons that may place calls | Show me the call reasons]]

![Reasons that may place calls — the five emergencies are on, "Not an emergency" is off](https://api.kiddietrac.com/help-img/carrier-voice-reasons.png)

The SMS broadcast screen only offers the reasons ticked here.

## Testing a call

1. On the **Voice calls** tab, type the **Test message** (up to 600 characters).
2. Leave **Number to call** blank to ring the number on your own profile, or type another number and tick **I control this number**.
3. Press **Call my own number**. The test plays the voice currently picked, saved or not, so you can audition voices.
4. Watch the **Test log**: it shows each test as it moves (dialled, answered, completed, no answer) and, when one was refused, why.

The test works **while voice calls are switched off**, and counts towards **five an hour** per agency.

[[show: #sms-settings @ Voice calls > Call my own number | Show me the test call button]]

![The test call: your own message, an optional number, and the test log below](https://api.kiddietrac.com/help-img/carrier-voice-test.png)

## Watching the balance (Telnyx)

With Telnyx set up, the **Telnyx balance & usage** card shows your **Balance**, **Texts (30 days)** and **Calls (30 days)**, what they cost, and roughly how long the balance will last. It turns red when the balance is low. Press **Refresh** for the latest figures. Top up, or turn on auto-recharge in Telnyx, so an emergency call-out cannot stop part-way.

[[show: #sms-settings @ Balance | Show me the balance card]]

## Two switches, not one

Nothing sends until both are on, and they are in different places:

- **A carrier is configured and selected here.**
- **Notifications are on for the agency**, under **Settings → Email settings** (**📬 Mail & notifications**). With it off, nothing sends or rings even with a carrier fully set up. This screen says so in red when it is off.

## Who can do what

| Role | Access |
|---|---|
| Agency admin | Everything on this screen, for their agency, including real tests |
| Platform admin | The same; the screen is titled **Phone & carriers** and adds a **☎️ Toll-free line** tab for KiddieTrac's own line |
| Centre director, staff | No access. Directors send texts and calls from **SMS broadcast** |

## FAQs and when something goes wrong

??? A broadcast said "sent" but nobody got it. | Check the two switches first: a carrier selected here, and notifications on under Settings → Email settings. Then check the kind of text is ticked, and that the people had agreed to receive texts.
??? A red warning says the webhook public key is blank. | Telnyx signs every message it sends back (replies, STOPs, delivery reports). Until the key is saved, replies and STOPs from families are not recorded. Paste the **Public Key** and press **Save**.
??? My test call says "Not placed". | Read the Test log line — it says why. Most often the number belongs to a suppressed or archived account, or the person chose "Don't phone me at all".
??? "Tick "I control this number" first." | Tick the box under the number before pressing the button.
??? Can another agency see our balance or keys? | No. Each agency has its own carrier settings, and only that agency's admins can open or change them.
??? Why is a reason missing from the SMS broadcast screen? | It is unticked under Reasons that may place calls. Tick it here and save.
??? Something looks wrong on this screen. | Read the **Screen build** line under the title first: an old cached copy of the app looks just like a bug. Reload the page to get the latest.

## Related screens

[[open: #sms | SMS broadcast]] [[open: #email-settings | Email settings]]

See also: [SMS broadcast](sms-broadcast), [Announcement calls](voice-announcements), [Texts and phone calls](texts-and-phone-calls), [Email settings](email-settings).
