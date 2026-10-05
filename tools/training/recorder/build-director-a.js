// Director tutorials, group A (Billing, Administration, Settings, Troubleshooting), recorded as the
// Test Agency demo centre director Rebecca Bright (user 100, centre 18) on a desktop.
//
// Only topics a centre director can actually reach are scripted. Agency-admin screens fall back to
// the dashboard for a director, so those topics need an agency-admin demo account (see SKIPPED).
// Anything that moves money or contacts people is highlighted, never pressed. The two lessons that
// create something (a supplier, a fee plan) remove exactly what they made in teardown.
const fs = require('fs');
const path = require('path');
const dir = path.join(__dirname, 'scripts', 'director');
fs.mkdirSync(dir, { recursive: true });

// Rebecca has unread chat, so the desktop chat window pops open over every screen. Hidden first.
const HIDE_DOCK = { eval: "(function(){var s=document.createElement('style');s.textContent='#kt-chat-dock{display:none!important}';document.head.appendChild(s)})()", settle: 100 };
const S = (slug, title, start, steps, extra) => {
  steps[0].do.unshift(HIDE_DOCK);
  return { audience: 'director', slug, title, account: 'director', device: 'desktop', start, steps, ...(extra || {}) };
};
const step = (chapter, say, ...actions) => ({ chapter, say, do: actions });
const SINCE = ['echo json_encode(["since" => now()->format("Y-m-d H:i:s")]);'];

const scripts = [
  /* ── Billing ─────────────────────────────────────────────────────────── */
  S('accounting', 'Accounting', '#external-billing', [
    step('Every invoice', 'Accounting, under Finance, lists every invoice in one place, with what has been collected and what is still outstanding.', { wait: 900 }),
    step('Filter by status', 'Use the tabs to switch between outstanding, paid, overdue and voided invoices.', { tap: 'text:Paid', settle: 1600 }, { tap: 'text:Overdue', settle: 1600 }, { tap: 'text:Outstanding', settle: 1600 }),
    step('Row actions', 'Each invoice has its own menu: view it, record a payment, resend it, edit it, download it, or void it.', { tap: 'text:⋮', settle: 1400 }, { ring: 'text:Record payment', ms: 1600 }),
    step('Recording a payment', 'Tap Record payment when a family pays by cheque or e-Transfer. Enter the amount, how it was paid, the date and a reference number.', { tap: 'text:Record payment', settle: 2000 }, { ring: 'text:How it was paid', ms: 1600 }, { ring: 'text:Reference number', ms: 1400 }),
    step('Save or cancel', 'Record payment marks it against the invoice. We\'ll cancel here, since this is a real family\'s invoice.', { ring: 'text:Record payment', ms: 1400 }, { tap: 'text:Cancel', settle: 1400 }),
  ]),

  S('invoice-statuses', 'Invoice statuses', '#external-billing', [
    step('The status column', 'Every invoice carries a status, so you can tell at a glance where it stands.', { ring: 'text:STATUS', ms: 1800 }),
    step('Issued and partly paid', 'Issued means it has gone to the family and nothing is paid yet. Partly paid means some money has come in, and the balance shows what is left.', { ring: 'text:BALANCE', ms: 1800 }),
    step('Overdue', 'Overdue means the due date has passed with money still owing. The Overdue tab gathers them in one list.', { tap: 'text:Overdue', settle: 2000 }),
    step('Paid and voided', 'Paid invoices are settled in full. A voided invoice was cancelled, and it no longer counts towards anything the family owes.', { tap: 'text:Paid', settle: 1600 }, { tap: 'text:Voided', settle: 1800 }),
  ]),

  S('expenses', 'Expenses', '#expenses', [
    step('Your spending', 'Expenses, under Finance, tracks what the centre spends: bills, purchase orders and suppliers. The summary shows what is outstanding and where the money goes.', { wait: 1200 }),
    step('Bills and orders', 'Bills are what suppliers ask you to pay. Purchase orders are what you have ordered from them.', { tap: 'text:🧾 Bills', settle: 1600 }, { tap: 'text:📦 Purchase orders', settle: 1600 }),
    step('Add a supplier', 'Start with your suppliers. Open the Suppliers tab and tap New supplier.', { tap: 'text:🏢 Suppliers', settle: 1600 }, { tap: 'text:+ New supplier', settle: 1800 }),
    step('Their details', 'Give it a name, and a contact and default category if you like.', { typeAt: ['input[type=text]', 0, 'Maple Art Supplies'] }, { typeAt: ['input[type=text]', 1, 'Jordan Lee'] }),
    step('Save', 'Tap Create supplier. It is now ready to use on bills and purchase orders.', { tap: 'text:Create supplier', settle: 2400 }),
  ], { setup: SINCE, teardown: ['echo "suppliers:", DB::table("suppliers")->where("agency_id", 6)->where("created_by_id", 100)->where("created_at", ">=", $ctx["since"])->delete();'] }),

  S('payment-plans', 'Payment schedules', '#payment-plans', [
    step('Spreading a cost', 'A payment schedule splits one amount into a series of invoices, for example a term\'s fees paid monthly.', { wait: 900 }),
    step('A new schedule', 'Tap New payment schedule.', { tap: 'text:+ New payment schedule', settle: 1800 }),
    step('The details', 'Choose the family, the total amount, how often, and the first and last due dates.', { ring: 'text:FAMILY', ms: 1300 }, { ring: 'text:TOTAL AMOUNT ($)', ms: 1300 }, { ring: 'text:EVERY', ms: 1300 }, { ring: 'text:FIRST DUE', ms: 1300 }),
    step('What it is for', 'Say what it covers, and whether each invoice goes out a few days early or is due straight away.', { ring: 'text:WHAT IS THIS FOR?', ms: 1600 }),
    step('Build it', 'Build the schedule creates the invoices for that family. We\'ll cancel here.', { ring: 'text:Build the schedule', ms: 1600 }, { tap: 'text:Cancel', settle: 1400 }),
  ]),

  S('tuition-plans', 'Tuition and fee plans', '#tuition-plans', [
    step('Your fee plans', 'Tuition and fee plans are the rates you charge, set up once and applied to families.', { wait: 900 }),
    step('A new plan', 'Tap New plan.', { tap: 'text:+ New plan', settle: 1800 }),
    step('Name and amount', 'Give it a clear name and the amount.', { type: ['input[placeholder^="e.g. Toddler"]', 'Toddler full-time (demo)'], settle: 400 }, { type: ['input[placeholder="0.00"]', '1250'], settle: 400 }),
    step('How often', 'Choose how often it is billed: weekly, every two weeks, monthly, or one time.', { choose: ['select', 'Monthly'], settle: 900 }),
    step('Create it', 'Tap Create plan. It is now ready to apply to a family.', { tap: 'text:Create plan', settle: 2400 }),
  ], { setup: SINCE, teardown: ['echo "fee_plans:", DB::table("fee_plans")->where("agency_id", 6)->where("name", "Toddler full-time (demo)")->where("created_at", ">=", $ctx["since"])->delete();'] }),

  S('tuition-increases', 'Tuition increases', '#tuition-increases', [
    step('Planning a raise', 'Tuition increases lets you schedule a fee change ahead of time, so it applies on the right date without anyone having to remember.', { wait: 900 }),
    step('What is scheduled', 'Each row shows when it takes effect, whether it is a percentage or a flat amount, and its status.', { ring: 'text:EFFECTIVE', ms: 1400 }, { ring: 'text:AMOUNT', ms: 1400 }, { ring: 'text:STATUS', ms: 1400 }),
    step('A new increase', 'To add one, tap Schedule increase, then enter the effective date, the amount, and whether it is a percentage or flat.', { ring: 'text:+ Schedule increase', ms: 2200 }),
    step('Notice', 'Give families plenty of notice. The Notify column shows whether they have been told.', { ring: 'text:NOTIFY', ms: 1800 }),
  ]),

  S('vacation-holds', 'Vacation holds', '#vacation-holds', [
    step('Families away', 'A vacation hold pauses tuition while a family is away. Requests from parents arrive here.', { wait: 900 }),
    step('Each request', 'Each one shows the family, the dates, the reason, and its status.', { ring: 'text:DATES', ms: 1400 }, { ring: 'text:STATUS', ms: 1400 }),
    step('Approving', 'Approve or deny a request from its row. When you approve, you can set a credit amount, or leave it to be worked out automatically.', { ring: 'text:CREDIT', ms: 1800 }),
    step('Adding one yourself', 'If a family tells you in person, tap Add hold and enter it for them.', { ring: 'text:+ Add hold', ms: 2000 }),
  ]),

  S('late-pickup-fees', 'Late pick-up fees', '#late-events', [
    step('Late pick-ups', 'Late arrivals and pick-ups lists every time a child was collected after closing, so a fee can be reviewed before it is charged.', { wait: 1000 }),
    step('Review', 'New ones wait under Awaiting review. Approve the fee, waive it, or decline it.', { ring: 'text:Awaiting review', ms: 1600 }),
    step('What happened before', 'The other tabs keep the history: approved, waived and declined.', { tap: 'text:Approved', settle: 1400 }, { tap: 'text:Waived', settle: 1400 }, { tap: 'text:All', settle: 1600 }),
  ]),

  S('subsidies', 'Subsidies', '#cwelcc', [
    step('Subsidies', 'Subsidies, under Compliance, keeps both kinds of fee support in one place.', { wait: 900 }),
    step('CWELCC', 'The CWELCC tab shows each family\'s gross fee, the reduction, and what the parent pays, ready for your monthly report.', { ring: 'text:GROSS', ms: 1400 }, { ring: 'text:SUBSIDY', ms: 1400 }),
    step('Provincial subsidy', 'The Provincial subsidy tab tracks fee subsidies from your municipality, with each child\'s case number and dates.', { tap: 'text:Provincial subsidy', settle: 2000 }, { ring: 'text:CASE NUMBER', ms: 1600 }),
    step('Add one', 'To record a new one, tap Add subsidy.', { ring: 'text:＋ Add subsidy', ms: 2000 }),
  ]),

  S('qbo-sync', 'QuickBooks Online', '#quickbooks', [
    step('Connecting QuickBooks', 'KiddieTrac can send your invoices and payments to QuickBooks Online, so your books stay in step without retyping.', { wait: 1000 }),
    step('Your Intuit app', 'First, paste the Client ID and Client Secret from your Intuit developer app.', { ring: 'text:Client ID', ms: 1500 }, { ring: 'text:Client Secret', ms: 1500 }),
    step('Production or sandbox', 'Choose Sandbox to test first, or Production for your real books.', { ring: 'text:Environment', ms: 1800 }),
    step('Save and connect', 'Then save the credentials and connect. We won\'t save here.', { ring: 'text:Save credentials', ms: 2000 }),
  ]),

  S('automated-billing-reminders', 'Automated billing reminders', '#email-settings', [
    step('Reminders', 'KiddieTrac can remind families by email about invoices coming due, and invoices that are overdue. They live under Email settings, on the Billing tab.', { tap: 'text:💳 Billing', settle: 2000 }),
    step('Before it is due', 'Switch on upcoming-invoice reminders, and choose how many days before the due date they go out.', { ring: 'text:Send upcoming-invoice reminders', ms: 2000 }),
    step('After it is due', 'Overdue reminders work the same way, counted in days after the due date.', { ring: 'text:Send overdue-payment reminders', ms: 2000 }),
    step('When and how', 'Pick the daily send time, which is in your agency\'s time zone, and add a short note if you like. Then tap Save reminders.', { ring: 'text:Daily send time', ms: 1500 }, { ring: 'text:Save reminders', ms: 1600 }),
  ]),

  /* ── Administration ───────────────────────────────────────────────────── */
  S('audit-log', 'The audit log', '#audit-logs', [
    step('Who did what', 'The audit log records every important change: who made it, what changed, and when.', { wait: 1000 }),
    step('The columns', 'Each row shows when, the action, what it was done to, who did it, and where they signed in from.', { ring: 'text:ACTION', ms: 1300 }, { ring: 'text:ACTOR', ms: 1300 }, { ring: 'text:LOCATION', ms: 1300 }),
    step('Narrow it down', 'Filter by action or by what was changed, search for a name, or pick a date range.', { ring: 'text:ENTITY', ms: 1400 }, { ring: 'text:SEARCH', ms: 1400 }, { ring: 'text:FROM', ms: 1200 }),
    step('Start again', 'Reset clears every filter.', { tap: 'text:Reset', settle: 1600 }),
  ]),

  S('bulk-actions-and-csv', 'Bulk actions and exporting', '#tickets', [
    step('Working on many at once', 'Most lists in KiddieTrac let you pick several rows and act on them together.', { wait: 900 }),
    step('Pick rows', 'Tick the box at the top to select every row, or tick rows one by one.', { tap: 'input[type=checkbox]', settle: 1600 }),
    step('Export', 'Under the list, export what you see to a spreadsheet, a CSV file, or a PDF.', { ring: 'text:📊', ms: 1300 }, { ring: 'text:📄', ms: 1300 }, { ring: 'text:📕', ms: 1300 }),
    step('Clear it', 'Untick the box to clear your selection.', { tap: 'input[type=checkbox]', settle: 1400 }),
  ]),

  S('forms-manager', 'Forms Manager', '#forms-manager', [
    step('Your forms', 'Forms Manager is where you upload forms, send them to families and staff, and keep the signed copies.', { wait: 1000 }),
    step('The library', 'The Library holds every form you have uploaded, who it is assigned to, and how many have signed.', { ring: 'text:SIGNED', ms: 1600 }),
    step('Upload a form', 'To add one, tap Upload a form and choose the PDF.', { ring: 'text:+ Upload a form', ms: 1800 }),
    step('The other tabs', 'Multiple forms sends a bundle in one go. Request files asks someone to send you a document. Completed holds everything that has been signed.', { tap: 'text:📦 Multiple forms', settle: 1500 }, { tap: 'text:📥 Request files', settle: 1500 }, { tap: 'text:✅ Completed', settle: 1800 }),
  ]),

  S('reviewing-and-counter-signing-forms', 'Reviewing and counter-signing forms', '#forms-manager', [
    step('Signed forms', 'When a family signs a form, it lands under Completed.', { tap: 'text:✅ Completed', settle: 2000 }),
    step('What you see', 'Each row shows who signed and when, in your agency\'s time, and whether a copy was emailed to them.', { ring: 'text:SIGNED BY', ms: 1500 }, { ring: 'text:COPY EMAILED', ms: 1500 }),
    step('Your signature', 'Some forms need your signature too. The Counter-signed column shows which ones you have done.', { ring: 'text:COUNTER-SIGNED', ms: 1800 }),
    step('Open it', 'Open the row\'s menu to view the form, sign it, or send it back if something needs fixing.', { ring: 'text:⋮', ms: 1800 }),
  ]),

  S('doc-workflows', 'Document workflows', '#doc-workflows', [
    step('Signing in order', 'A document workflow collects signatures in order: for example, the parent signs, then you review, then the director counter-signs.', { wait: 1000 }),
    step('What is in progress', 'The list shows each workflow, the step it is on, and its status.', { ring: 'text:CURRENT STEP', ms: 1800 }),
    step('A new one', 'Tap New workflow and give it a title.', { tap: 'text:+ New workflow', settle: 1800 }, { type: ['input[placeholder="Field-trip consent"]', 'Field-trip consent'], settle: 500 }),
    step('The signers', 'Continue to add the text and up to six signers. The first signer is notified as soon as you save, so we\'ll cancel here.', { ring: 'text:Continue', ms: 1600 }, { tap: 'text:Cancel', settle: 1400 }),
  ]),

  S('support-tickets', 'Support tickets', '#tickets', [
    step('Getting help', 'Support tickets are for problems that need tracking to a fix. Each one keeps its history.', { wait: 1000 }),
    step('Raise one', 'Tap Raise ticket. Choose a category and how urgent it is.', { tap: 'text:+ Raise ticket', settle: 1800 }, { ring: 'text:Category', ms: 1300 }, { ring: 'text:Severity', ms: 1300 }),
    step('Describe it', 'Say what you were doing, what you expected, and what happened instead. A screenshot helps most of all.', { typeAt: ['textarea', 0, 'The daily overview did not show today\'s photos for the Infant room.'] }, { ring: 'text:Screenshots or logs', ms: 1400 }),
    step('Send it', 'Raise ticket sends it to support. We\'ll cancel this example.', { ring: 'text:Raise ticket', ms: 1400 }, { tap: 'text:Cancel', settle: 1400 }),
    step('Follow it', 'Tap any ticket to see where it is, and reopen or close it.', { tap: 'text:#108', settle: 2000 }, { ring: 'text:Reopen', ms: 1400 }),
  ]),

  S('custom-reports', 'Reports', '#reports', [
    step('Reports', 'Reports gives you ready-made reports, branded and ready to print.', { wait: 1000 }),
    step('Choose the range', 'Pick your centre and a date range first.', { choose: ['select', 'Sunny Meadows'], settle: 1200 }, { ring: 'text:From', ms: 1200 }),
    step('Run one', 'Then tap a report. Here, the enrolment roster.', { tap: 'text:Enrollment roster', settle: 3200 }),
    step('Use it', 'Filter it, sort it, export it, or print it.', { scroll: 300, ms: 1500 }, { ring: 'text:🖨️', ms: 1600 }),
  ]),

  /* ── Settings ─────────────────────────────────────────────────────────── */
  S('mfa-two-factor', 'Two-factor sign-in', '#settings', [
    step('Security', 'Two-factor sign-in adds a six-digit code from your phone to your password. It is in Settings, under Security.', { tap: 'text:Security', settle: 2200 }),
    step('Set it up', 'Tap Set up MFA, scan the code with an authenticator app, and type the code it shows to finish.', { scroll: 500, ms: 1500 }, { ring: 'text:🛡 Set up MFA', ms: 2200 }),
    step('Or a passkey', 'A passkey is even simpler: your face, fingerprint or device PIN, with no code to type.', { ring: 'text:Add a passkey', ms: 1800 }),
    step('Why it matters', 'Directors can see children\'s records and billing, so two-factor is strongly recommended.', { wait: 800 }),
  ]),

  S('session-timeout', 'Why KiddieTrac signs you out', '#dashboard', [
    step('Staying safe', 'KiddieTrac holds sensitive information, so it signs you out after 30 minutes without any activity, and after 12 hours in any case.', { wait: 800 }),
    step('The warning', 'Five minutes before an idle sign-out, you get a warning. Let\'s let the clock run down.', { eval: "sessionStorage.setItem('kt_last_activity', String(Date.now() - 26 * 60000))", settle: 200 }, { wait: 9000 }),
    step('Your work', 'Changes are kept when you tap Save, not while a screen sits open, so save before you step away.', { wait: 9000 }),
    step('Shared computers', 'On a front-desk computer or a tablet, this stops the next person from finding you still signed in.', { wait: 9000 }),
    step('Stay signed in', 'When the warning appears, tap Stay signed in to carry on, or Sign out now if you are finished.', { wait: 6000 }, { tap: 'text:Stay signed in', settle: 1800 }),
  ]),

  S('notifications-inbox', 'Your notifications inbox', '#notifications', [
    step('Your inbox', 'Notifications collects everything KiddieTrac has told you about: sign-ins, incidents, forms, messages and more.', { wait: 1000 }),
    step('Unread first', 'Show only unread ones with the filter at the top.', { choose: ['select', 'Unread only'], settle: 1600 }, { choose: ['select', 'All'], settle: 1200 }),
    step('Tidy up', 'Tap Select to pick several at once, or Mark all read to clear the lot.', { ring: 'text:Select', ms: 1400 }, { ring: 'text:Mark all read', ms: 1600 }),
    step('One at a time', 'Each one has its own menu, to open it, mark it read, or remove it.', { ring: 'text:⋮', ms: 1800 }),
  ]),

  S('language', 'Changing the language', '#dashboard', [
    step('Your language', 'KiddieTrac works in English, French, Spanish and Hindi. Pick yours from the menu at the top.', { ring: 'select', ms: 1800 }),
    step('Switch', 'Choose Français, and the portal changes straight away.', { choose: ['select', 'Français'], settle: 2600 }),
    step('Just for you', 'It only changes what you see. Families and staff each choose their own.', { wait: 1200 }),
    step('Back again', 'Switch back the same way.', { choose: ['select', 'English'], settle: 2400 }),
  ]),

  /* ── Troubleshooting ──────────────────────────────────────────────────── */
  S('when-something-looks-wrong', 'When something looks wrong', '#dashboard', [
    step('Start here', 'When a number looks wrong, the cause is usually something simple. Here are the first places to look.', { wait: 900 }),
    step('Staff on the floor', 'If no staff show as on the floor, your educators probably haven\'t clocked in. Check Staff to see who is on shift.', { go: '#staff', settle: 2400 }),
    step('A child is missing', 'If a child doesn\'t appear in their room, check Children: they must be enrolled, with a start date of today or earlier.', { go: '#children', settle: 2400 }),
    step('An invoice looks off', 'If a balance looks wrong, open Accounting and check the invoice and its payments.', { go: '#external-billing', settle: 2400 }),
    step('Still stuck', 'If it still doesn\'t add up, raise a support ticket with a screenshot, and we\'ll take it from there.', { go: '#tickets', settle: 2200 }, { ring: 'text:+ Raise ticket', ms: 1800 }),
  ]),
];

/* Not scripted, and why. Kept here so the gap is visible next to the scripts. */
const SKIPPED = {
  'needs an agency-admin demo account (screen falls back to the dashboard for a centre director)': [
    'adding-an-agency', 'closing-a-provider-or-educator', 'custom-roles-and-permissions', 'files-repository',
    'multi-agency-switching', 'platform-admin-overview', 'platform-overview-widgets', 'provider-map', 'view-as',
    'account-ledgers', 'billing-setup', 'bulk-invoicing', 'donations-and-fundraising', 'generating-monthly-invoices',
    'invoice-numbering-and-style', 'late-fee-config', 'late-fees', 'payment-providers', 'payment-surcharges',
    'platform-invoices', 'sibling-discounts', 'centres-or-rooms', 'database-backups', 'document-templates',
    'email-templates', 'learning-framework', 'single-sign-on', 'white-label-branding', 'resetting-a-users-password',
  ],
  'forbidden for a director by the API (Required role: agency_admin)': ['carrier-settings', 'email-and-digests', 'compliance-dashboard'],
  'broken for a director (product bug)': ['requesting-files', 'weekly-billing', 'refunds'],
  'parent-side or no director path found': ['autopay', 'parent-wallet', 'family-ledger', 't4a-receipts', 'missed-chat-emails'],
};

// Lives in Email settings, which is agency configuration: recorded as the demo agency admin (2026-10-05).
scripts.forEach((s) => { if (s.slug === "automated-billing-reminders") s.account = "admin"; });
for (const s of scripts) fs.writeFileSync(path.join(dir, s.slug + '.json'), JSON.stringify(s, null, 2));
console.log('wrote', scripts.length, 'director scripts (group A);', Object.values(SKIPPED).flat().length, 'skipped');
