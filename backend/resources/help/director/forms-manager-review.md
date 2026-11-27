---
title: Forms Manager for directors and auditors
category: Compliance
order: 60
roles: centre_director, auditor
screens: forms-manager
---
# Forms Manager for directors and auditors

**Forms Manager** is where the agency's PDF forms live: who has to sign them, what has gone
out, and every completed copy. A **centre director** uses it for the people at their own
centres: uploading forms, sending them, reviewing and counter-signing what comes back. An
**auditor** sees the same records read-only, to check that forms were sent, signed and
reviewed.

[[open: #forms-manager | Open Forms Manager]]

The screen has four tabs: **📚 Library**, **📦 Multiple forms**, **📥 Request files** and
**✅ Completed**.

## Finding a signed form (directors and auditors)

1. Open **Forms Manager** and press the **✅ Completed** tab.
2. Type a name or a form title in the search box above the table to narrow the list. Click a column heading to sort by it.
3. Read the row: **Form**, **Description**, **Signed by**, **Signed (agency time)**, then what happened next.
4. Press the row's **⋮** and choose **View form** to open the completed copy, or **Download completed form** to save it.

The status columns answer the questions an inspection usually asks:

| Column | What it tells you |
|---|---|
| **Copy emailed** | A tick and a time when the completed copy reached the address set on the form. **Not sent** means it has an address but nothing went yet; **Not set up** means the form has none. |
| **Sent back** | **Awaiting signer** while the agency is waiting for a correction, **Resubmitted** once a new copy came in. |
| **Counter-signed** | The date and who counter-signed, **Awaiting review** if nobody has, or **Not required** for a form that needs no counter-signature. |
| **Synced** | **✓ Filed** with the name of the record it is on. **Not filed** means it is not on the person's record yet; **Orphaned** means the account it was filed to no longer exists. |

[[show: #forms-manager @ Completed > Counter-signed | Show me the Counter-signed column]]

## Checking what was sent, and to whom

1. Press the **📦 Multiple forms** tab.
2. The history lists every package that went out, who sent it and when.
3. Read **Signed** (how many signatures are back), **Emailed** and **Read**. **Not delivered** under Read is the one to worry about: the email never arrived.
4. Press **⋮ > View forms** to see who got which form and when each person signed. **⋮ > Download PDF** merges every completed form in the package into one PDF for your file. Directors also have **⋮ > Print summary**.

## Reviewing and counter-signing (directors)

1. On **✅ Completed**, find a row that says **Awaiting review**.
2. Press **⋮ > Review & counter-sign**. The completed form opens full size.
3. Read it. Add a note under **Add information** if something needs recording.
4. Press **Next: counter-sign →**, then **✍️ Add your signature**.
5. Leave **Email the counter-signed copy to both sides** ticked, and press **Complete counter-signature**.

If something is missing or wrong, press **⋮ > Send back to the signer** instead, say under
**What needs changing** exactly what to fix, and press **Send it back**. The full steps are
in [Reviewing, counter-signing and sending back forms](reviewing-and-counter-signing-forms).

## Uploading and sending forms (directors)

1. On **📚 Library**, press **+ Upload a form**.
2. If you direct more than one centre, choose the centre in **🏫 Centre for new forms and new parents** first.
3. Fill in the title and description, choose **Who must sign it?** or pick people under **Or send to specific people**, choose the PDF, and press **Upload form**.
4. To email people several forms at once, go to **📦 Multiple forms** and press **+ Send multiple forms**.

The complete upload and package steps are in [Forms Manager and form packages](forms-manager).

## Who can do what

| | Centre director | Auditor |
|---|---|---|
| See forms, sends and completed copies | Their own centres' people | Read-only, the agency they audit |
| View and download forms | Yes | Yes |
| Upload, edit, switch a form on or off, delete | Forms for their centres only | No |
| Send packages, reminders and file requests | Yes | No |
| Counter-sign, send back, delete a sign-off | Yes | No |
| **Sync now** and agency-wide forms | No (agency admins only) | No |

An auditor sees a grey **🔒 Read-only (auditor) — you can view and download, not change**
label at the top. Every button that would change something is hidden, and the **⋮** menus
only offer view, download and open.

## FAQs and when something goes wrong

??? I am a director and a form has no edit or delete buttons. | It is an agency-wide form, or one made for another centre. Only an agency admin can change it. You can still send it and review what comes back.
??? Why is there no Sync now button for me? | Syncing runs across the whole agency, so it is kept for agency admins. Ask one to press Sync now on the Completed tab.
??? Completed says "No forms have been signed yet." | Nothing has been signed by anyone you can see. A director only sees people at their own centres.
??? As an auditor, can I export everything? | Use ⋮ > Download PDF on a package in Multiple forms to get every completed form in it as one PDF, or download forms one at a time from Completed.
??? I clicked something and got "Auditor accounts are read-only." | Auditor accounts can look but never change anything. Ask an agency admin if a change is needed.
??? Does viewing a form notify anybody? | No. Viewing and downloading send nothing. Counter-signing and sending back do email the signer.

## Related screens

[[open: #forms-manager | Forms Manager]] [[open: #compliance | Compliance]]

- [Reviewing, counter-signing and sending back forms](reviewing-and-counter-signing-forms)
- [Forms Manager and form packages](forms-manager)
- [Requesting files](requesting-files)
