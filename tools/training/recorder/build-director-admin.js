// Agency-admin tutorials for the director library (resources/help/director), recorded as the
// Test Agency demo admin "Demo Admin" (user 234, agency_admin of agency 6, "Maple Leaf Child Care").
// Settings screens are SHOWN, never saved: every Save / Generate / Send / Close / Reset is a ring.
// The only change made is to the demo admin's own dashboard widgets, which the lesson puts back.
// Platform-only topics (backups, security alerts, SSO, view-as, files, platform billing, new
// agencies, website subscribers/landing) are not here: an agency admin cannot open them.
const fs = require('fs');
const path = require('path');
const dir = path.join(__dirname, 'scripts', 'director');
fs.mkdirSync(dir, { recursive: true });

const S = (slug, title, start, steps, extra) => ({ audience: 'director', slug, title, account: 'admin', device: 'desktop', start, steps, ...(extra || {}) });
const step = (chapter, say, ...actions) => ({ chapter, say, do: actions });

// Credentials and IDs stay off the video: every field on these screens is blurred.
const BLUR = { eval: "(function(){var s=document.createElement('style');s.textContent='#appMain input,#appMain textarea{filter:blur(6px)}';document.head.appendChild(s);})()", settle: 150 };
// Click a button by its exact label, after pointing at it. The dashboard keeps growing as its
// widgets load, so a measured tap can land on whatever moved under it.
const CLICK = (label, settle) => [{ point: 'text:' + label }, { eval: "(function(){var b=Array.from(document.querySelectorAll('button')).find(function(x){return x.textContent.trim()===" + JSON.stringify(label) + "});if(b)b.click();})()", settle: settle || 1600 }];
// Filter User management to one demo person, so no real person's row is opened.
const FILTER_EMILY = { eval: "(function(){var i=Array.from(document.querySelectorAll('#appMain input')).find(function(x){return /Filter/.test(x.placeholder||'')});if(i){i.value='Emily';i.dispatchEvent(new Event('input',{bubbles:true}));}})()", settle: 1200 };
// The widget row's own + or × button, by the widget's name.
const WIDGET = (name, glyph) => ({ eval: "(function(){var rows=Array.from(document.querySelectorAll('div,li')).filter(function(e){return e.children.length<6&&e.textContent.indexOf(" + JSON.stringify(name) + ")!==-1&&e.querySelector('button')});rows.sort(function(a,b){return a.textContent.length-b.textContent.length});var r=rows[0];if(!r)return;var b=Array.from(r.querySelectorAll('button')).find(function(x){return x.textContent.trim()===" + JSON.stringify(glyph) + "})||r.querySelector('button');b.click();})()", settle: 1400 });

const ROW_KEBAB = (name) => ({ eval: "(function(){var rows=Array.from(document.querySelectorAll('#appMain div,#appMain tr')).filter(function(e){return e.textContent.indexOf(" + JSON.stringify(name) + ")!==-1&&Array.from(e.querySelectorAll('button')).some(function(b){return b.textContent.trim()==='⋮'})});rows.sort(function(a,b){return a.textContent.length-b.textContent.length});var r=rows[0];if(!r)return;var b=Array.from(r.querySelectorAll('button')).find(function(b){return b.textContent.trim()==='⋮'});b.scrollIntoView({block:'center'});b.click();})()", settle: 1500 });

const scripts = [
  S('closing-a-provider-or-educator', 'Closing a provider, off-boarding an educator', '#admin-centres', [
    step('A guided close', 'When a provider stops working with you, close it from Centres. Open the provider\'s menu and choose Close this provider.', { tap: 'text:⋮', settle: 1300 }, { tap: 'text:🚪 Close this provider…', settle: 1800 }),
    step('The last day', 'Set the last operating day, and whether to archive the centre once no child is enrolled there.', { ring: 'text:Last operating day', ms: 1500 }, { ring: 'text:Archive the centre afterwards', ms: 1600 }),
    step('See the plan first', 'Preview shows the plan: the children, families and staff it affects. Nothing changes until you confirm with Close provider.', { ring: 'text:Preview', ms: 1500 }, { ring: 'text:Close provider', ms: 1600 }),
    step('An educator leaving', 'For an educator or home visitor, open User management, find the person, and open Manage.', { tap: 'text:×', settle: 900 }, { go: '#admin-users', settle: 2600 }, FILTER_EMILY, { tap: 'text:⋮', settle: 1200 }, { tap: 'text:⚙️ Manage', settle: 2200 }),
    step('Off-board', 'Off-board counts their rooms, open shifts, tasks and documents before you decide, and tells the office what is left to hand over.', { ring: 'text:👋 Off-board…', ms: 2000 }, { tap: 'text:×', settle: 900 }),
  ]),

  S('provider-map', 'Provider map', '#provider-map', [
    step('Every provider, on a map', 'The provider map plots every centre in your agency by its address.', { wait: 900 }),
    step('Located', 'The count at the top tells you how many were found on the map.', { ring: 'text:3 of 3 providers located', ms: 1700 }),
    step('Pick one', 'Tap a provider in the list, or its pin, to see its name, address, phone and capacity.', { tap: 'text:Sunny Meadows Childcare', settle: 2200 }),
    step('Fixing a pin', 'If a provider says it could not be located, correct its street address, city and postal code under Centres, and the map finds it next time.', { ring: 'text:Located', ms: 1700 }),
  ]),

  S('account-ledgers', 'Account ledgers', '#account-ledgers', [
    step('One account, the whole picture', 'An account ledger answers four questions for one person: what they were billed, what they paid, what is still owed, and what is coming.', { ring: 'text:BALANCE OUTSTANDING', ms: 1800 }),
    step('The four figures', 'Billed, less what was collected, less refunds, is the balance. The tiles always tie together.', { ring: 'text:INVOICED', ms: 1300 }, { ring: 'text:RECEIVED', ms: 1300 }, { ring: 'text:OVERDUE', ms: 1300 }),
    step('How old it is', 'Ageing shows how long the unpaid money has been owed, so the oldest is chased first.', { ring: 'text:How old the unpaid money is', ms: 2000 }),
    step('The history', 'At the bottom, every invoice and payment in order. Use the tabs to narrow it down.', { ring: 'text:Account history', ms: 1500 }, { tap: 'text:Invoices 1', settle: 1500 }),
  ]),

  S('billing-setup', 'Billing setup and settings', '#billing-settings', [
    step('One screen, in tabs', 'Settings, Billing, holds everything about what your agency charges and how. Each tab saves on its own.', { wait: 800 }),
    step('Tax', 'Tax is your rate and what it is called on the invoice, like HST. Tick Apply tax by default only if you are registered to charge it.', { ring: 'text:Rate', ms: 1400 }, { ring: 'text:Called', ms: 1300 }),
    step('Plans and siblings', 'Tuition plans are your reusable fees. Sibling discounts take a percentage off the second child and beyond.', { tap: 'text:💵 Tuition plans', settle: 1700 }, { tap: 'text:👨‍👩‍👧 Sibling discounts', settle: 1700 }),
    step('Defaults and fees', 'Defaults and fees sets your billing frequency, deposit and registration fee, late fees, the payment methods you accept, autopay and surcharges.', { tap: 'text:⚙️ Defaults & fees', settle: 1800 }, { ring: 'text:Billing defaults', ms: 1400 }, { ring: 'text:Accepted payment methods', ms: 1600 }),
    step('Comms and reminders', 'Billing comms and Reminders control the emails and texts families get about their invoices.', { tap: 'text:🛠 Billing comms', settle: 1700 }, { ring: 'text:Reminders', ms: 1500 }),
  ]),

  S('late-fee-config', 'Late-fee configuration', '#billing-settings', [
    step('Your rule', 'Each agency sets its own late-fee rule, under Billing, Defaults and fees.', { tap: 'text:⚙️ Defaults & fees', settle: 1900 }),
    step('The percentage', 'The late fee is a percentage of the overdue balance, up to 25 percent. Set it to zero to turn late fees off.', { ring: 'text:Late fee (%)', ms: 1800 }),
    step('Cap and grace', 'The cap is the most any single fee can be, and grace is how many days after the due date pass before a fee applies.', { ring: 'text:Cap (CAD)', ms: 1500 }, { ring: 'text:Grace (days)', ms: 1500 }),
    step('How it adds up', 'At 1.5 percent with a 25 dollar cap, a 900 dollar overdue balance gets a 13 dollar 50 fee. Review the starting values, then tap Save billing setup.', { ring: 'text:Save billing setup', ms: 1800 }),
  ]),

  S('late-fees', 'Late-fee automation', '#billing-settings', [
    step('Where the rule lives', 'Late fees are set under Billing, Defaults and fees. This lesson is about how the automatic run uses them.', { tap: 'text:⚙️ Defaults & fees', settle: 1900 }, { ring: 'text:Late fees', ms: 1500 }),
    step('Switched off for now', 'The automatic run is currently switched off for the whole platform, so no late fees are being added. Your settings are kept, ready for when it is turned on.', { wait: 900 }),
    step('Which invoices', 'Once on, it runs nightly. An invoice gets a fee only if it still has a balance, is past its due date plus your grace days, and has not had a fee this month.', { ring: 'text:Grace (days)', ms: 1800 }),
    step('Never twice', 'A missed night is caught up the next, and no invoice is charged twice.', { ring: 'text:Late fee (%)', ms: 1500 }),
  ]),

  S('payment-surcharges', 'Payment surcharges', '#billing-settings', [
    step('Passing on the cost', 'You can pass the cost of card and bank payments on to families as a visible service fee. It is under Defaults and fees.', { tap: 'text:⚙️ Defaults & fees', settle: 1900 }),
    step('Two rates', 'There are two rates, because the two methods cost you different amounts: one for cards, one for e-Transfer and bank payments. Each is between 0 and 10 percent.', { ring: 'text:Credit / debit card (%)', ms: 1700 }, { ring: 'text:EFT / Interac e-Transfer (%)', ms: 1700 }),
    step('Never automatic', 'A surcharge is never added on its own. When someone records a payment in Accounting, a box shows the fee, and they tick it to add it. Cash and cheque are never surcharged.', { wait: 900 }),
    step('Save', 'Set your rates, then tap Save billing setup.', { ring: 'text:Save billing setup', ms: 1700 }),
  ]),

  S('sibling-discounts', 'Sibling discount tiers', '#billing-settings', [
    step('Siblings', 'When a family has more than one child enrolled, you can take a percentage off for each extra child. Open the Sibling discounts tab.', { tap: 'text:👨‍👩‍👧 Sibling discounts', settle: 1800 }),
    step('Tiers', 'The oldest enrolled child is rank one and pays full tuition. Each tier sets the discount for a rank: say 10 percent for the second child, 15 for the third.', { ring: 'text:Sibling discount tiers', ms: 1800 }),
    step('Add a tier', 'Tap Add tier for each rank, and the highest tier carries on to any further children.', { ring: 'text:+ Add tier', ms: 1700 }),
    step('Applied for you', 'Save, and the discount appears as its own line on the monthly invoices.', { ring: 'text:Save', ms: 1500 }),
  ]),

  S('bulk-invoicing', 'Bulk invoice run', '#bulk-invoices', [
    step('All of a month at once', 'The bulk invoice run creates the month\'s invoices for every enrolled family at a centre, in one go.', { wait: 900 }),
    step('The period', 'Pick the billing period at the top: the month and the year.', { ring: 'text:Billing period', ms: 1800 }),
    step('A centre', 'Each centre shows how many children and families it has. Its menu holds the run.', { ring: 'text:Sunny Meadows Childcare', ms: 1500 }),
    step('Generate', 'Generate invoices creates one invoice per family, with sibling discounts and subsidies applied. You can then view them, and email them to families.', { point: 'text:Sunny Meadows Childcare' }, ROW_KEBAB('Sunny Meadows Childcare'), { ring: 'text:🧾 Generate invoices', ms: 1600 }, { ring: 'text:👁️ View invoices', ms: 1300 }, { ring: 'text:✉️ Email invoices to families', ms: 1500 }, { key: 'Escape' }),
    step('The invoices', 'The list below shows every invoice for the period, filtered by status.', { ring: 'text:All statuses', ms: 1600 }),
  ]),

  S('generating-monthly-invoices', 'Generating monthly invoices', '#bulk-invoices', [
    step('Once a month', 'At the start of each month, generate the invoices for every enrolled family in one step.', { ring: 'text:Billing period', ms: 1600 }),
    step('What it does', 'For each family, it creates one invoice with a line per child, takes off any subsidy, and sets it as sent.', { point: 'text:Sunny Meadows Childcare' }, ROW_KEBAB('Sunny Meadows Childcare'), { ring: 'text:🧾 Generate invoices', ms: 1800 }, { key: 'Escape' }),
    step('Check the result', 'Filter the list to see what was created and what is still upcoming.', { choose: ['select', 'Upcoming'], settle: 1600 }),
    step('One-offs', 'For a single extra charge, use Generate an invoice instead.', { ring: 'text:🧾 Generate an invoice', ms: 1700 }),
  ]),

  S('donations-and-fundraising', 'Donations and fundraising', '#donations', [
    step('Fundraising', 'Donations lets you run campaigns with a page to share, record every gift, and send donors a receipt.', { wait: 900 }),
    step('Switch it on', 'Start in settings: turn on Take donations, and if you are a registered charity, enter your registration number and legal name.', { tap: 'text:⚙️', settle: 1800 }, { ring: 'text:Take donations (show campaigns and giving pages)', ms: 1800 }, { ring: 'text:CRA charity registration number', ms: 1500 }),
    step('How to give', 'Write how donors complete a pledge, for example your e-Transfer address. Supporters see it on the page and in their thank-you email.', { ring: 'text:How donors complete a pledge (shown on the page and in the thank-you email)', ms: 1800 }),
    step('Campaigns and gifts', 'Campaigns holds your giving pages. Gifts is where you mark a pledge as received, which sends the receipt.', { tap: 'text:Campaigns', settle: 1600 }, { tap: 'text:Gifts', settle: 1500 }),
  ]),

  S('payment-providers', 'Payment providers', '#payment-providers', [
    step('Your own accounts', 'Payment providers is where you connect the accounts your agency is paid through. Each agency enters its own keys.', BLUR, { wait: 800 }),
    step('One tab each', 'There is a tab for each provider. A green dot means a working credential is stored.', { ring: 'text:● 🏦 Zum Rails', ms: 1300 }, { tap: 'text:● 💳 Stripe', settle: 1500 }, { tap: 'text:● 🍁 Helcim', settle: 1500 }),
    step('Enabled and mode', 'Every tab has the same two controls: Enabled, and Mode. Stay on Sandbox until you have tested a payment from start to finish.', { ring: 'select', ms: 1800 }),
    step('Secrets stay secret', 'A saved key is never shown again, only its last four characters. Leave a secret box empty to keep the one you have.', { tap: 'text:● 💳 Stripe', settle: 1400 }, { ring: 'text:Secret key (kept private)', ms: 1700 }),
    step('Save', 'Each provider saves on its own.', { ring: 'text:Save Stripe', ms: 1500 }),
  ]),

  S('email-templates', 'Email templates', '#email-templates', [
    step('Your own words', 'Email templates lets you rewrite the emails sent on your agency\'s behalf. Your logo, colours and layout are added for you.', { wait: 800 }),
    step('Pick an email', 'Choose the email to edit from Template to edit.', { ring: 'text:Template to edit', ms: 1600 }),
    step('Placeholders', 'Placeholders like the parent\'s first name or the child\'s name are filled in for each person when the email is sent.', { ring: 'text:{{child_name}}', ms: 1800 }),
    step('The parts', 'Edit the subject and each section of the message.', { ring: 'text:Subject', ms: 1300 }, { ring: 'text:Introduction', ms: 1300 }),
    step('Test and save', 'Send yourself a test to see it as a family will, then save. Reset to default puts the original wording back.', { ring: 'text:📤 Send me a test', ms: 1400 }, { ring: 'text:Save template', ms: 1300 }, { ring: 'text:↺ Reset to default', ms: 1400 }),
  ]),

  S('document-templates', 'Document templates', '#document-templates', [
    step('How documents look', 'Document templates control how payslips, invoices and receipts look when printed or emailed.', { wait: 900 }),
    step('Built-in until you add one', 'Until you add a template, documents use the built-in layout, so activating one is always reversible.', { wait: 1900 }),
    step('Import', 'Use Import to bring in an existing design, then preview it against a real record before you activate it.', { ring: 'text:⬆️', ms: 1800 }),
  ]),

  S('learning-framework', 'Choosing your learning framework', '#learning-framework', [
    step('Your framework', 'Your learning framework is what your agency plans and reports against: observations link to it, report cards follow it, and the gaps report counts by it.', { wait: 900 }),
    step('Until you choose', 'Until you choose one, KiddieTrac uses the framework for your province.', { ring: 'text:How Does Learning Happen?', ms: 2000 }),
    step('The choices', 'Each card shows a framework and its areas. In Ontario that is How Does Learning Happen, with Belonging, Well-being, Engagement and Expression.', { ring: 'text:How Does Learning Happen?', ms: 1600 }, { ring: 'text:Early Learning for Every Child Today', ms: 1400 }),
    step('Your own', 'Or name your own areas, for a programme like Reggio Emilia or Montessori.', { ring: 'text:Our own framework', ms: 1600 }),
    step('Make it official', 'Pick one, then tap Save framework.', { ring: 'text:Save framework', ms: 1600 }),
  ]),

  S('centres-or-rooms', 'Centres or rooms', '#dashboard', [
    step('Your word', 'Some agencies run several centres; others run one site with rooms. You choose the word the portal uses.', { wait: 900 }),
    step('Edit agency', 'On Agency overview, tap the edit button to open Edit agency.', ...CLICK('✏️', 2000)),
    step('Facilities are called', 'Under Facilities are called, choose Centres, Rooms or Providers. Menus, headings and reports switch to that word straight away.', { ring: 'text:Facilities are called', ms: 2000 }),
    step('Only the labels', 'Only the words on screen change. Your names and addresses stay exactly as they are.', { tap: 'text:Cancel', settle: 1000 }),
  ]),

  S('resetting-a-users-password', 'Resetting a user\'s password', '#admin-users', [
    step('Let them do it first', 'For almost everyone, Forgot password on the sign-in page is the right route. It sends a reset link and needs nothing from you.', { wait: 900 }),
    step('Find the person', 'If you need to do it for them, open User management and find the person.', FILTER_EMILY),
    step('Their menu', 'Open their menu. Reset password is right there, along with a manual sign-in link and Unlock sign-in if they are locked out.', { tap: 'text:⋮', settle: 1300 }, { ring: 'text:🔑 Reset password', ms: 1700 }, { ring: 'text:🔓 Unlock sign-in', ms: 1500 }),
    step('What happens', 'A reset sends them a link to choose a new password. You never see or choose it for them.', { key: 'Escape' }),
  ]),

  S('first-time-setup', 'First-time setup', '#admin-users', [
    step('The setup wizard', 'The first time a new agency admin signs in, a short setup wizard runs: your profile, your address, your organization, and your team.', { wait: 900 }),
    step('Running it again', 'You can run it again for anyone from User management. Find the person and open Manage.', FILTER_EMILY, { tap: 'text:⋮', settle: 1300 }, { tap: 'text:⚙️ Manage', settle: 2200 }),
    step('Reopen onboarding', 'Reopen onboarding brings the wizard back the next time they sign in.', { ring: 'text:🪄 Reopen onboarding', ms: 2000 }),
    step('Inviting your team', 'Directors and educators get an email invite to set their own password. Parents are added per family, under Families.', { tap: 'text:×', settle: 900 }, { ring: 'text:+ Invite user', ms: 1700 }),
  ]),

  S('customizing-your-dashboard', 'Customizing your dashboard', '#dashboard', [
    step('Business insights', 'Your Agency overview shows Business insights widgets that you choose and arrange.', { scroll: 700, ms: 1800 }),
    step('Add widget', 'Tap Add widget to open Customize widgets.', ...CLICK('+ Add widget', 1800)),
    step('Drag to arrange', 'On your dashboard lists what you see now. Drag the tiles to set their order.', { ring: 'text:ON YOUR DASHBOARD — DRAG TO ARRANGE', ms: 1800 }),
    step('Add one', 'Tap plus on any available widget to add it. Here, the compliance snapshot.', { point: 'text:Compliance snapshot' }, WIDGET('Compliance snapshot', '+')),
    step('Remove one', 'Tap the cross to take it off again. Changes apply immediately.', WIDGET('Compliance snapshot', '×'), { tap: 'text:Done', settle: 1500 }),
  ]),

  S('closure-reminders', 'Closure reminder emails', '#email-settings', [
    step('Nobody at a locked door', 'Families and educators are told when a closure is added, and again as it gets close.', { tap: 'text:Closures', settle: 1800 }),
    step('Lead times', 'Choose the lead times: straight away, and a number of days before.', { ring: 'text:7 days', ms: 1400 }, { ring: 'text:Day before', ms: 1400 }),
    step('When they go', 'Set the time of day the reminders are sent, in your agency\'s local time.', { ring: 'text:Send each day at', ms: 1600 }),
    step('Changes', 'Moving or deleting a closure sends a change notice, not a fresh closure notice. Save to keep your choices.', { ring: 'text:Save settings', ms: 1600 }),
  ]),

  S('immunization-reminders', 'Immunization reminders', '#email-settings', [
    step('Off until you say so', 'Immunization reminders email your team a list of children whose records are missing or out of date. They are off until someone turns them on.', { tap: 'text:🩹 Immunization', settle: 1900 }),
    step('How often', 'Choose weekly or monthly, the day, and the time of day.', { ring: 'text:How often', ms: 1400 }, { ring: 'text:Time of day', ms: 1300 }),
    step('Who gets it', 'Choose who gets the list: agency admins, centre directors, and if you want, educators.', { ring: 'text:Who gets it', ms: 1700 }),
    step('What counts', 'Decide what counts as outstanding: no record at all, and records older than a set age.', { ring: 'text:What counts as outstanding', ms: 1800 }),
    step('Save', 'Then save.', { ring: 'text:Save', ms: 1400 }),
  ]),

  S('email-and-digests', 'Email settings and digests', '#email-settings', [
    step('Your own email', 'Your agency can send digests, invoices and welcome emails from its own address.', { tap: 'text:📤 Outbound', settle: 1800 }),
    step('Sending method', 'Leave it on the platform default, or switch to your own mail server and enter its details.', { ring: 'text:Sending method', ms: 1800 }),
    step('From name and address', 'Under Delivery, set the name and address families see the emails come from.', { tap: 'text:🎛️ Delivery', settle: 1700 }, { ring: 'text:From name', ms: 1400 }, { ring: 'text:From address', ms: 1400 }),
    step('Test it', 'Save, then send a test email to yourself to check it arrives.', { ring: 'text:Save settings', ms: 1300 }, { ring: 'text:Send test email to me', ms: 1500 }),
  ]),

  S('carrier-settings', 'Carrier settings', '#sms-settings', [
    step('Texts and calls', 'Carrier settings holds the accounts your agency texts and phones through. Only your own agency\'s admins can see or change them.', BLUR, { wait: 900 }),
    step('Which carrier sends', 'Choose which carrier sends your texts: Twilio, or Telnyx, which also carries voice calls.', { ring: 'text:Twilio The original carrier', ms: 1500 }, { ring: 'text:Telnyx Also carries voice calls', ms: 1500 }),
    step('What you send', 'Tick the kinds of texts this agency sends.', { ring: 'text:KINDS OF TEXTS THIS AGENCY SENDS', ms: 1800 }),
    step('Test it', 'Save your credentials, then Test connection. It checks them and sends nothing.', { ring: 'text:Test Twilio', ms: 1700 }),
    step('Voice calls', 'Voice calls has its own tab, for the announcement calls that read a short message out loud.', { tap: 'text:Voice calls', settle: 1700 }),
  ]),

  S('data-retention-compliance', 'Data retention and compliance', '#data-retention', [
    step('How long records are kept', 'Data retention sets how long each kind of record is kept. Check your province\'s minimums first.', { wait: 900 }),
    step('Policy only, or enforced', 'Some periods are your written policy. Others, marked Enforced, are actually cleared when they run out, like chat messages.', { ring: 'text:Child & enrolment records (after departure)', ms: 1500 }, { ring: 'text:Parent–educator messages (chat)', ms: 1600 }),
    step('What happens to them', 'Choose whether expired records are anonymised or permanently deleted.', { ring: 'text:When enforcing, records are', ms: 1800 }),
    step('Your privacy details', 'Add your privacy policy link and data-protection contact, then save.', { ring: 'text:Privacy policy URL', ms: 1400 }, { ring: 'text:Save changes', ms: 1400 }),
  ]),

  S('kiosk-mode', 'Kiosk mode for tablet check-in', '#admin-centres', [
    step('A tablet at the door', 'Kiosk mode turns a tablet by your door into a check-in screen: parents tap their child and type their PIN.', { wait: 900 }),
    step('Edit the centre', 'In Centres, open the centre\'s menu and choose Edit.', { tap: 'text:⋮', settle: 1300 }, { tap: 'text:✏️ Edit', settle: 2000 }),
    step('Enable kiosk', 'Further down, tick Enable kiosk for this centre. You get a unique kiosk link to open on the tablet and add to its home screen.', { ring: 'text:Enable kiosk for this centre', ms: 2200 }),
    step('Parent PINs', 'Each parent who picks up needs their own four to six digit PIN, set under Families.', { tap: 'text:×', settle: 1000 }),
  ]),

  S('qr-checkin', 'QR code check-in', '#admin-centres', [
    step('No tablet needed', 'A QR poster on your door lets families sign children in and out with their own phone.', { wait: 900 }),
    step('Turn it on', 'Open the centre\'s menu, choose Edit, and tick Enable kiosk for this centre.', { tap: 'text:⋮', settle: 1300 }, { tap: 'text:✏️ Edit', settle: 2000 }, { ring: 'text:Enable kiosk for this centre', ms: 2000 }),
    step('Print the poster', 'A QR check-in card appears. Print the poster and put it up at your entrance.', { wait: 900 }),
    step('At the door', 'Families scan it with their KiddieTrac app, and the sign-in is recorded with who did it.', { tap: 'text:×', settle: 1000 }),
  ]),

  S('archived-records', 'Archived records', '#admin-centres', [
    step('Kept, not deleted', 'When a family leaves, a staff account is closed or a provider is archived, the record is kept. Childcare records have to outlive the enrolment.', { wait: 900 }),
    step('Active and archived', 'Each list has an Active and Archived switch, with a count, so you can see at a glance if anything is there.', { ring: 'text:Archived (0)', ms: 1800 }),
    step('Families', 'Families who have de-enrolled are under Families, Archived.', { go: '#admin-families', settle: 2400 }, { ring: 'text:Archived (0)', ms: 1600 }),
    step('Staff', 'Closed staff accounts are in User management, under De-boarded and deactivated.', { go: '#admin-users', settle: 2400 }, { tap: 'text:🗄️ De-boarded / deactivated', settle: 1800 }),
  ]),

  S('compliance-dashboard', 'Compliance dashboard', '#compliance', [
    step('What needs attention', 'Compliance puts the three things that need attention on one screen.', { wait: 900 }),
    step('At a glance', 'The tiles count expired and expiring certificates, people without two-factor sign-in, and centres at or near capacity.', { ring: 'text:EXPIRED CERTS', ms: 1300 }, { ring: 'text:MFA NOT ENROLLED', ms: 1500 }),
    step('Certificates', 'Staff certifications lists anything expired or expiring within 30 days.', { ring: 'text:STAFF CERTIFICATIONS', ms: 1700 }),
    step('Capacity', 'Centre capacity shows enrolled against licensed seats for each centre.', { ring: 'text:CENTRE CAPACITY', ms: 1700 }),
  ]),
];

for (const s of scripts) fs.writeFileSync(path.join(dir, s.slug + '.json'), JSON.stringify(s, null, 2));
console.log('wrote', scripts.length, 'agency-admin director scripts');
