---
title: Medications
category: Health & Safety
order: 31
roles: agency_admin, platform_admin, centre_director
screens: medications
---
# Medications

The Medications screen holds every standing medication order for the children in your centres: what the medicine is, how much and how often, whether a parent has authorized it, and every dose given. A dose can only be logged once parent authorization is recorded. Use it when a family brings in a medicine, when a parent sends a request from their portal, and to check the dose history.

[[open: #medications | Open Medications]]

## Adding a new medication

1. Open **Operations > Medications** and press **+ New medication**.
2. Choose the **Child**. Only currently enrolled children are listed.
3. Type the **Medication name** (for example "Children's Tylenol") and its **Strength** (for example "160mg/5mL").
4. Choose the **Route**: **Oral**, **Topical**, **Inhaled**, **Injection** or **Other**.
5. Fill in the **Dosage** (for example "5 mL") and the **Frequency** (for example "every 4 hours as needed").
6. Add the **Reason** (for example "fever / pain").
7. Check **Starts on** (today is filled in) and, if the order ends, set **Expires on (optional)**.
8. Add any **Special instructions (optional)**, such as "Give with food".
9. For a prescription, tick **Is prescription medication** and type the prescribing physician.
10. Press **Save (status: pending parent auth)**. The medication appears in the list with the amber **PENDING PARENT AUTH** badge.

## Recording parent authorization

A parent must give written consent before any dose is given.

1. Find the medication with the **PENDING PARENT AUTH** badge.
2. Press **Record parent authorization**.
3. Fill in **Authorizing parent user ID** as the popup asks.
4. Press **Mark authorized**. The badge turns green (**ACTIVE**) and the **+ Log dose** and **Discontinue** buttons appear.

> ℹ️ When a parent asks for a medication from their portal (**💊 Ask the centre to administer a medication**), the request lands in this same list as **PENDING PARENT AUTH**. Check the details with the family, then authorize it.

## Logging a dose

1. On an **ACTIVE** medication, press **+ Log dose**.
2. Type the **Dose given** (for example "5 mL").
3. Choose the **Outcome**: **Given as prescribed**, **Partial dose**, **Refused by child** or **Missed (not given)**.
4. Add any **Notes**, and a witness if your centre uses one (recommended for narcotics).
5. Press **Record dose**. The card now shows one more dose logged and the time of the last dose.

Educators log doses the same way from their own **Medications to administer** screen.

## Viewing the dose history

1. Press **View dose log** on the medication's card (the number shows how many doses there are).
2. The **Dose history** popup lists **When**, **Dose**, **Outcome**, **By** and **Notes** for every dose.
3. Press **Close** when you are done.

## Stopping a medication

1. Press **Discontinue** on an active medication.
2. Confirm in the **Discontinue medication** popup. No new doses can be logged, and the dose history is kept.

The badge then shows **DISCONTINUED**. An order past its end date shows **EXPIRED**.

## Who can do what

- Agency admins and centre directors add medications, record authorization, log doses, view the history and discontinue.
- Educators see the active medications for their centre, log doses and view the history. They cannot add, authorize or discontinue.
- Parents see their child's medications read-only and can send a request for the centre to give one.
- The screen needs the Medications feature in your agency's plan.

## FAQs and troubleshooting

??? Why is there no + Log dose button? | The medication is not active yet. Record the parent's authorization first. Discontinued and expired medications cannot take new doses.
??? I pressed Mark authorized and saw "Enter a user ID". | The authorization field was empty. Fill it in and press **Mark authorized** again. If you are not sure what to enter, ask your agency admin or KiddieTrac support.
??? A popup shows "Error:" and a message. | The save did not go through and the popup stays open. Read the message, fix the field it names, and try again.
??? I see "No medications view for this role." | This screen opens for agency admins, centre directors, educators and parents. As a platform admin, use [View as](view-as) to open it as an agency admin.
??? The page says "No medications recorded yet." | Nobody has added a medication in your centres. Press **+ New medication** to add the first one.
??? Does the parent get told when a dose is logged? | Logging a dose records it on the medication's history. Parents can see the order and how many doses were logged on their own Medications screen.

## Related

[[open: #allergy-alerts | Allergy alerts]] [[open: #immunizations | Immunizations]]

- [Allergy and dietary alerts](allergy-alerts)
- [Child record tabs](child-record-tabs)
