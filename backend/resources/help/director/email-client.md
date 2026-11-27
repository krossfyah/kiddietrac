---
title: Email client — reading and sending mail in KiddieTrac
category: Communications
order: 36
roles: agency_admin, platform_admin, centre_director
screens: email
---
# Email client — reading and sending mail in KiddieTrac

**Operations → Email Client** lets you read and answer your own mailbox (Microsoft 365, Outlook.com, Gmail or another provider) without leaving the portal. It looks like Outlook: mailboxes and folders on the left, the message list in the middle and the open email on the right. On a phone you see one pane at a time, with **Back** to return to the list.

[[open: #email | Open the Email client]]

## Connecting your mailbox

1. Press **＋ Add account** at the bottom of the left pane. The **✉️ Add an email account** window opens.
2. Type your email address and press **Next →**. KiddieTrac works out who runs the mailbox. You can also pick your provider from the tiles under **or choose your provider**.
3. For Microsoft 365, Outlook or Hotmail, press **Sign in with Microsoft**. For Gmail or Google Workspace, press **Sign in with Google**. You sign in on the provider's own page: KiddieTrac never sees your password.
4. Allow the permissions the provider asks for. You come back to the Email client and the mailbox appears in the left pane with its folders and unread counts.

> 💡 For a shared mailbox (for example office@), sign in as **yourself** if the mailbox has no password of its own; sign in as the mailbox only if it has its own sign-in.

> ℹ️ Other providers (Yahoo, IMAP servers) can be connected with **Other mail provider**, but they are read-only here: you answer them from their own mail app. Sending works from mailboxes signed in with Microsoft or Google.

## Reading and sorting your mail

1. Click a folder (for example **Inbox**) in the left pane.
2. Use **Search this folder** to find a message, and **All** / **Unread** to narrow the list. **Load older messages** at the bottom fetches more.
3. Click a message to open it in the reading pane.
4. Use the toolbar above it: **Mark unread**, **Flag**, **Move**, **Archive** (or **Not junk** in the Junk folder) and **Delete**. In Deleted Items the button becomes **Delete permanently**.
5. To act on several messages, tick them (or **Select all**) and use the bar that appears above the list.

## Writing, replying and forwarding

1. Press **✏️ New mail**, or open a message and press **Reply**, **Reply all** or **Forward**.
2. Check **From** if you have more than one mailbox. A reply starts from the mailbox the message came to.
3. Add people in **To**. Press **Cc** or **Bcc** to show those lines.
4. Write your message. Your signature is already in place and you can still change it.
5. Press **📎 Attach** to add files, or drag them onto the message (up to 10 files and 25 MB per email).
6. Press **👁 Preview** to see exactly what will be sent, then press **Send** (or **Ctrl+Enter**).

> ⚠️ **Discard this message?** appears if you close a message you have started. Choose **Discard** only if you do not want it.

## Signature, out-of-office and alerts

1. Press **⚙️ Manage accounts** at the bottom of the left pane.
2. Press **Settings** beside the mailbox.
3. Paste or write your **Signature**. Pasting from Outlook or Word keeps your formatting and logo.
4. Turn on **Automatic replies (out of office)**, choose who gets a reply and, if you like, the start and end times.
5. Tick **Alert me when new mail arrives in this inbox (bell, phone and sound)** to get a bell notification with a soft chime, and pick the **Sound for this inbox**.
6. Press **Save settings**.

## Filing an email on a family or staff record

When your agency has switched on **Link emails to people** (**Settings → Email settings → Mailbox**), an agency admin's mailbox shows **🔗 Link to person** on each open message.

1. Open the email and press **🔗 Link to person**.
2. Search for the parent, staff member or contact by name or email and pick them.
3. The email now shows under **✉️ Emails** on that person's record. Every link and unlink is in the audit log.

[[show: #email @ ＋ Add account | Show me Add account]]

## Who can do what

| Role | What they can do |
|---|---|
| Agency admin | Connect their mailboxes (the agency's mailboxes), send, and link emails to people when it is switched on |
| Platform admin | The same, for the agencies they manage |
| Centre director | Connect their **own** mailbox only. It stays personal: other people never see it and its emails are not filed on records |

Each mailbox belongs to the person who connected it. Nobody else in the agency can read it.

## FAQs and when something goes wrong

??? A red banner says my mailbox needs to sign in again. | Microsoft or Google stopped trusting the old sign-in (a password change, or an administrator removed access). Press **🔌 Reconnect** and sign in again. Your mail and settings are kept.
??? I can read mail but Reply is greyed out or sending fails. | The mailbox is connected as read-only (another provider), or it was connected before sending was added. Press **🔌 Reconnect** (or **Sign in with Microsoft** once more) to allow sending, or answer from the mailbox's own app.
??? I don't get new-mail alerts. | Alerts work for mailboxes connected with Sign in with Microsoft and only when the alert box is ticked in that mailbox's **Settings**. Check your phone's notification sound in **My settings** too.
??? I can't see 🔗 Link to person. | It only shows on an agency admin's mailbox, once an agency admin turns on **Link emails to people** under **Settings → Email settings → Mailbox**. A centre director's mailbox is personal and never links.
??? The list is empty. | No mailbox is connected yet, or the folder is empty. Press **＋ Add account**, or pick another folder.
??? Who sees my emails? | Only you. Linked emails are seen on the person's record by agency admins (and anyone your agency allows); the rest of your mailbox stays private.

## Related screens

[[open: #email-settings | Email settings]] [[open: #settings | My settings]]

See also: [Email settings](email-settings), [Missed-message email notifications](missed-chat-emails), [Message Centre](message-centre-rules).
