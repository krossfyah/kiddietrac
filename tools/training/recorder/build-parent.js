// Parent tutorials (resources/help/parent) — every one DOES the function it teaches, step by
// step, on the Test Agency demo family (Harjit Singh, user 94; Arjun #59, Simran; family 55,
// centre 16). Anything a lesson creates is removed by its teardown / cleanup.
const fs = require('fs');
const path = require('path');
const dir = path.join(__dirname, 'scripts', 'parent');
fs.mkdirSync(dir, { recursive: true });

const S = (slug, title, start, steps, extra) => ({ audience: 'parent', slug, title, account: 'parent', device: 'phone', start, steps, ...(extra || {}) });
const step = (chapter, say, ...actions) => ({ chapter, say, do: actions });
const SINCE = 'echo json_encode(["since" => now()->format("Y-m-d H:i:s")]);';

const scripts = [
  S('your-daily-view', 'Your daily view', '#today', [
    step('Your home screen', 'When you open KiddieTrac, you land on your child\'s day. Here\'s how to read it.', { wait: 500 }),
    step('At a glance', 'The cards at the top show where your child is today, your balance this month, new photos, and anything waiting for you.', { ring: 'text:TODAY\'S STATUS', ms: 1300 }, { ring: 'text:BALANCE THIS MONTH', ms: 1300 }, { ring: 'text:OPEN TASKS', ms: 1200 }),
    step('Switch children', 'If you have more than one child, tap a name to switch. Here we\'ll switch to Simran, and back to Arjun.', { tap: 'text:Simran', settle: 1700 }, { tap: 'text:Arjun', settle: 1500 }),
    step('Where they are', 'Below the name: whether they\'re at the centre or at home, their room, and their educator.', { scroll: 380, ms: 1400 }, { ring: 'text:YOUR EDUCATOR', ms: 1600 }),
    step('Another day', 'Tap the left arrow beside the date to go back a day.', { tap: 'text:◀', settle: 2300 }),
    step('The day\'s story', 'Now scroll down. The day\'s story sums up everything the educators logged.', { scroll: 380, ms: 1700 }, { wait: 900 }),
    step('Filter the day', 'Tap a count, like Meals, to see only those moments in the timeline. Tap it again to see everything.', { scroll: 220, ms: 1200 }, { tap: 'text:MEALS', settle: 1800 }, { scroll: 520, ms: 1800 }, { wait: 800 }),
    step('Back to everything', 'Scroll back up and tap Meals again to clear the filter.', { scroll: -520, ms: 1500 }, { tap: 'text:MEALS', settle: 1600 }),
    step('Getting around', 'And the bar at the bottom takes you to Photos, Messenger and Billing whenever you need them.', { ring: 'text:Photos', ms: 1000 }, { ring: 'text:Messenger', ms: 1000 }, { ring: 'text:Billing', ms: 1000 }),
  ]),

  S('getting-started-first-login', 'Getting started — your first login', '#home', [
    step('Your home screen', 'Welcome to KiddieTrac. After you sign in, you land on your family\'s home screen. Let\'s take a quick tour.', { wait: 600 }),
    step('Your child\'s day', 'Tap Today to see your child\'s day as it happens.', { tap: 'text:Today', settle: 2600 }),
    step('Back home', 'The Home button at the bottom always brings you back.', { tap: 'text:Home', settle: 2200 }),
    step('Things to sign', 'Forms to sign shows anything the centre needs from you. The number tells you how many are waiting.', { tap: 'text:Forms to sign', settle: 2400 }),
    step('Help and training', 'And whenever you\'re unsure, open Help. Every part of KiddieTrac has a step-by-step guide, and a video like this one under Training.', { tap: 'text:Home', settle: 2000 }, { scroll: 360, ms: 1300 }, { tap: 'text:Help', settle: 2600 }, { tap: 'text:Training', settle: 2400 }),
    step('Install the app', 'Finally, install KiddieTrac on your phone, so you get alerts for messages, photos and invoices, even when it\'s closed.', { wait: 700 }),
  ]),

  S('checking-your-child-in-and-out', 'Checking your child in and out', '#home', [
    step('The Check in button', 'When you arrive at the centre, tap Check in, in the middle of the bottom bar.', { tap: 'text:Check in', settle: 2600 }),
    step('Scan the poster', 'Point your camera at the centre\'s check-in poster by the door. If the camera can\'t read it, type the code printed under the QR instead.', { ring: 'input', ms: 1800 }),
    step('Type the code', 'Here, we\'ll type the code, and tap Go.', { type: ['input', '{{code}}'], settle: 600 }, { tap: 'text:Go', settle: 2600 }),
    step('Who are you here for?', 'With more than one child, KiddieTrac asks who you\'re dropping off. Everyone is ticked; tap a name to leave them out. Then tap Confirm.', { ring: 'text:Who are you here for?', ms: 1600 }, { tap: 'text:Confirm', settle: 2800 }),
    step('Checked in', 'Done. Each child is checked in, with the time, and the room team can see them straight away.', { wait: 1600 }, { go: '#today', settle: 2600 }, { ring: 'text:TODAY\'S STATUS', ms: 1800 }),
    step('Checking out', 'At pickup, do the same: tap Check in, scan or type the code, confirm, and your children are checked out.', { tap: 'text:Check in', settle: 2400 }, { type: ['input', '{{code}}'], settle: 500 }, { tap: 'text:Go', settle: 2600 }, { tap: 'text:Confirm', settle: 2800 }),
    step('If your hands are full', 'If your phone isn\'t handy, an educator can check your child in or out for you. Either way, you get a notification with the time.', { go: '#today', settle: 2400 }),
  ], {
    setup: ['$code = "TR" . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));',
      'DB::table("checkin_short_codes")->insert(["code" => $code, "centre_id" => 16, "created_by" => 85, "used_count" => 0, "expires_at" => now()->endOfDay(), "created_at" => now()]);',
      'echo json_encode(["code" => $code, "since" => now()->format("Y-m-d H:i:s")]);'],
    teardown: ['$n = DB::table("check_events")->where("by_user_id", 94)->where("created_at", ">=", $ctx["since"])->delete();',
      '$c = DB::table("checkin_short_codes")->where("code", $ctx["code"])->delete();',
      'echo "check_events:$n codes:$c";'],
  }),

  S('photos-of-your-child', 'Photos of your child', '#photos', [
    step('Your child\'s photos', 'Photos shows every picture and video your child\'s educators have shared, newest first.', { wait: 800 }),
    step('Each child', 'If you have more than one child, tap a name to see their photos.', { tap: 'text:Simran', settle: 1700 }, { tap: 'text:Arjun', settle: 1500 }),
    step('Open a photo', 'Tap a photo to open it full size.', { tap: 'img[loading=lazy]', settle: 1900 }),
    step('Next and back', 'Tap the arrows to move to the next photo, or back. On a phone you can also swipe.', { tap: '[aria-label=Next]', settle: 1500 }, { tap: '[aria-label=Previous]', settle: 1500 }),
    step('Save a copy', 'To keep one, tap Download. Please remember other children may be in the picture before sharing it outside KiddieTrac.', { tap: 'text:Download', settle: 1800 }),
    step('Close', 'Tap the cross to go back to the gallery.', { tap: '[aria-label=Close]', settle: 1200 }),
  ]),

  S('messaging-your-educators', 'Messaging your educators', '#messages', [
    step('Messenger', 'Messenger is where you talk with your child\'s educators: questions, reminders, and quick updates.', { wait: 700 }),
    step('Start a conversation', 'Tap New to start a conversation.', { tap: 'text:New', settle: 1800 }),
    step('Pick your child', 'Choose which child it\'s about, so it reaches the right room team.', { tap: 'text:Arjun', settle: 1000 }),
    step('Write it', 'Type your message.', { type: ['textarea', 'Hi! Arjun has a dentist appointment on Friday, so I\'ll pick him up at 2.'], settle: 500 }),
    step('Send', 'Then tap Send message. It\'s delivered straight away, and you\'ll get a notification when they reply.', { tap: 'text:Send message', settle: 2600 }),
    step('Your conversation', 'The conversation stays here, so you can open it any time to read replies or add more.', { tap: 'text:About Arjun', settle: 2200 }),
    step('Urgent things', 'For anything urgent, like an accident or a late pickup, please call the centre instead.', { wait: 700 }),
  ], { cleanup: [{ table: 'messages', by: 'sender_id' }, { table: 'conversations', by: 'family_id', value: 55 }] }),

  S('paying-invoices-and-autopay', 'Paying invoices and autopay', '#billing', [
    step('Billing', 'Billing shows what\'s due and how to pay it.', { wait: 900 }),
    step('The amount', 'The amount due is filled in for you. You can change it to pay part of it.', { ring: 'input[inputmode], input:not([type=hidden]):not([type=search])', ms: 1800 }),
    step('Choose how to pay', 'Then tap how you want to pay: card, Interac e-Transfer, or direct debit from your bank. You confirm the amount before anything is sent.', { ring: 'text:Interac', ms: 1500 }, { ring: 'text:Direct debit', ms: 1500 }, { ring: 'text:Card', ms: 1500 }),
    step('Save a card or bank', 'To pay faster, tap Add a card or bank account. Your details go straight to the payment processor, so the centre never sees them.', { ring: 'text:Add a card or bank account', ms: 2400 }),
    step('Autopay', 'With a payment method saved, you can turn on autopay, and each invoice is paid on its due date automatically.', { scroll: 420, ms: 1700 }),
    step('Receipts', 'Every payment gives you a receipt, and your history stays here in Billing.', { scroll: 420, ms: 1700 }),
  ]),

  S('understanding-your-billing', 'Understanding your billing', '#billing', [
    step('Your balance', 'The top of Billing shows what\'s due now, for all your children.', { wait: 900 }),
    step('Your invoices', 'Scroll down to your invoices. Each one covers a billing period.', { scroll: 450, ms: 1800 }, { wait: 700 }),
    step('What\'s on an invoice', 'An invoice lists your child\'s care, any extras, and any credits. If your centre is in CWELCC, the reduction is already taken off.', { scroll: 300, ms: 1500 }),
    step('Paying', 'You can pay right from here.', { scroll: -750, ms: 1500 }, { tap: 'text:Card', settle: 1500 }),
    step('Questions', 'If anything on a bill looks wrong, message your director, and they can explain or correct it.', { wait: 700 }),
  ]),

  S('forms-documents-and-signatures', 'Forms, documents and signatures', '#my-forms', [
    step('Forms to sign', 'Forms to sign holds everything your centre needs you to fill in or sign.', { wait: 800 }),
    step('Three tabs', 'What\'s waiting is under To sign. Drafts you started are under Drafts, and everything you\'ve sent is under Submitted.', { ring: 'text:To sign', ms: 1000 }, { ring: 'text:Drafts', ms: 1000 }, { ring: 'text:Submitted', ms: 1000 }),
    step('Open the form', 'Tap Fill and sign to open the form.', { tap: 'text:Fill & sign', settle: 2600 }),
    step('Fill it in', 'Tap each highlighted box and type. Here\'s the child\'s name, the medication, and the dose.', { typeAt: ['input', 0, 'Arjun Singh'] }, { typeAt: ['input', 1, 'Children\'s Tylenol'] }, { typeAt: ['input', 2, '5 mL'] }),
    step('Keep going', 'Fill in the rest the same way.', { typeAt: ['input', 3, 'Every 6 hours if needed'] }, { typeAt: ['input', 4, 'Oct 2'] }, { typeAt: ['input', 5, 'Dr. Patel'] }, { typeAt: ['input', 6, 'None'] }),
    step('Sign', 'When you\'re done, tap Sign and submit, and sign with your finger.', { tap: 'text:Sign & submit', settle: 1800 }, { draw: 'canvas:last', settle: 900 }),
    step('Submit', 'Then tap Submit form. A copy is saved to your Documents, and the centre is told.', { tap: 'text:Submit form', settle: 3200 }),
    step('Your copy', 'You\'ll find it under Submitted, any time you need it.', { tap: 'text:Submitted', settle: 2000 }),
  ], {
    setup: [SINCE],
    teardown: ['$rows = DB::table("managed_form_signoffs")->where("user_id", 94)->where("created_at", ">=", $ctx["since"])->get();',
      'foreach ($rows as $r) { if ($r->filled_file_url) { \\Illuminate\\Support\\Facades\\Storage::disk("public")->delete(preg_replace("#^.*/storage/#", "", $r->filled_file_url)); } }',
      'echo "signoffs:", DB::table("managed_form_signoffs")->whereIn("id", $rows->pluck("id"))->delete();',
      'echo " documents:", DB::table("documents")->where("scope_type", "user")->where("scope_id", 94)->where("category", "signed_form")->where("created_at", ">=", $ctx["since"])->delete();'],
  }),

  S('my-tasks', 'My tasks', '#my-tasks', [
    step('Your to-do list', 'My tasks is your to-do list from the centre. Here, the centre has asked for a spare set of clothes.', { wait: 900 }),
    step('Starting it', 'Tap Start to show you\'re working on it.', { tap: 'text:Start', settle: 1800 }),
    step('Done', 'When it\'s done, tap Mark done. The centre sees it\'s finished, and it leaves your list.', { tap: 'text:Mark done', settle: 2400 }),
    step('On your home screen', 'The open tasks card on your home screen shows how many are waiting, so nothing slips through.', { go: '#home', settle: 2400 }, { ring: 'text:OPEN TASKS', ms: 1700 }),
  ], {
    setup: ['$id = DB::table("tasks")->insertGetId(["agency_id" => 6, "centre_id" => 16, "assigned_to" => 94, "assigned_by" => 85, "title" => "Bring a spare set of clothes for Arjun", "description" => "Two of everything, labelled with his name, for his cubby.", "priority" => "normal", "due_date" => now()->addDays(2)->toDateString(), "status" => "open", "created_at" => now(), "updated_at" => now()]);',
      'echo json_encode(["task" => $id]);'],
    teardown: ['echo "tasks:", DB::table("tasks")->where("id", $ctx["task"])->where("assigned_to", 94)->delete();'],
  }),

  S('notifications-and-alerts', 'Notifications and alerts', '#notifications', [
    step('Your inbox', 'Notifications is the full list of everything KiddieTrac has told you, newest first.', { wait: 900 }),
    step('Only what\'s new', 'Use the filter to show only what you haven\'t read.', { choose: ['select', 'Unread only'], settle: 1800 }),
    step('Mark all read', 'Tap Mark all read to clear the list in one go.', { tap: 'text:Mark all read', settle: 1500 }, { tap: 'text:Confirm', settle: 2200 }),
    step('Everything again', 'Switch the filter back to see everything.', { choose: ['select', 'All'], settle: 1600 }),
    step('Red badges', 'Little red counters on the bottom bar also show what\'s new, and clear when you open that section.', { ring: 'text:Messenger', ms: 1500 }),
    step('Phone alerts', 'For alerts on your phone, install KiddieTrac and tap Allow when it asks to send notifications.', { wait: 600 }),
  ], {
    setup: ['echo json_encode(["ids" => DB::table("notifications")->where("user_id", 94)->whereNull("read_at")->pluck("id")->all()]);'],
    teardown: ['echo "restored unread:", DB::table("notifications")->whereIn("id", $ctx["ids"] ?: [0])->update(["read_at" => null]);'],
  }),

  S('awards-and-report-cards', 'Awards, certificates and report cards', '#awards', [
    step('Awards', 'When your child earns an award at the centre, it appears here. Arjun has just been named Super Helper.', { wait: 1000 }),
    step('The details', 'Each award shows who gave it, when, and why, in the educator\'s own words.', { ring: 'text:Super Helper', ms: 1800 }),
    step('Keep a copy', 'Tap the info button for the certificate, which you can print or save.', { tap: 'text:ℹ️', settle: 2400 }, { key: 'Escape' }),
    step('From home', 'You can always find Awards from your home screen.', { go: '#home', settle: 2400 }, { ring: 'text:Awards', ms: 1600 }),
  ], {
    setup: ['$id = DB::table("child_awards")->insertGetId(["child_id" => 59, "centre_id" => 16, "awarded_by_id" => 85, "title" => "Super Helper", "badge" => "🌟", "period" => "weekly", "note" => "Arjun helped tidy the block corner all week.", "awarded_on" => now()->toDateString(), "created_at" => now(), "updated_at" => now()]);',
      'echo json_encode(["award" => $id]);'],
    teardown: ['echo "awards:", DB::table("child_awards")->where("id", $ctx["award"])->where("child_id", 59)->delete();'],
  }),

  S('sending-an-immunization-record', 'Sending in an immunization record', '#immunizations', [
    step('Immunization', 'Immunization shows each child\'s vaccine schedule, and what the centre still needs from you.', { wait: 900 }),
    step('Records you have sent', 'Open Records you have sent.', { tap: 'text:Records you have sent', settle: 1800 }),
    step('Send one in', 'Tap Send in a record.', { tap: 'text:Send in a record', settle: 2000 }),
    step('Add the photo', 'Take a photo of your child\'s immunization card, or choose a PDF.', { upload: ['input[type=file]', 'assets/immunization-card.png'], settle: 2200 }),
    step('What it shows', 'If you can, tick each vaccine you can see on the card. It helps the centre update the schedule faster.', { tap: 'input[type=checkbox]', settle: 1200 }),
    step('File it', 'Then tap File record. It\'s sent to the centre, and listed here with the date you sent it.', { tap: 'text:File record', settle: 3000 }),
  ], {
    setup: [SINCE],
    teardown: ['$docs = DB::table("documents")->where("uploaded_by_id", 94)->where("created_at", ">=", $ctx["since"])->get();',
      'foreach ($docs as $d) { if ($d->file_url) { \\Illuminate\\Support\\Facades\\Storage::disk("public")->delete(preg_replace("#^.*/storage/#", "", $d->file_url)); } }',
      '$c = DB::table("immunization_record_claims")->where("claimed_by_id", 94)->where("created_at", ">=", $ctx["since"])->delete();',
      'echo "documents:", DB::table("documents")->whereIn("id", $docs->pluck("id"))->delete(), " claims:$c";'],
  }),

  S('vacation-holds-and-absences', 'Vacation holds and absences', '#today', [
    step('Telling the centre', 'If your child won\'t be coming in, tell the centre from your child\'s day.', { wait: 800 }),
    step('Not attending', 'Tap the button that says your child isn\'t attending today.', { tap: 'text:isn\'t attending today', settle: 1800 }),
    step('A reason', 'Choose a reason. Here, a holiday.', { tap: 'text:Holiday', settle: 900 }),
    step('A note', 'Add a note if you like.', { type: ['textarea', 'Away visiting grandparents, back Monday.'], settle: 500 }),
    step('Tell the centre', 'Then tap Tell the centre. The room team sees it right away, and Safe Arrival won\'t ask where your child is.', { tap: 'text:Tell the centre', settle: 2600 }),
    step('Longer breaks', 'For a vacation hold or a longer break, talk to your director so your billing is right.', { wait: 600 }),
  ], { cleanup: [{ table: 'child_absences', by: 'reported_by_id' }] }),

  S('updating-your-profile-and-contacts', 'Updating your profile and contacts', '#settings', [
    step('Your profile', 'Your profile is in Settings, under Profile.', { wait: 900 }),
    step('Change a detail', 'Tap a field to change it. Here, we\'ll update the phone number.', { eval: 'var i=document.querySelector("input[type=tel]"); i.focus(); i.select();', settle: 300 }, { type: ['input[type=tel]', '4165550199'], settle: 800 }),
    step('Save', 'Then tap Save profile.', { tap: 'text:Save profile', settle: 2200 }),
    step('Your language', 'You can also choose the language KiddieTrac speaks to you in.', { ring: 'select', ms: 1700 }),
    step('Emergency contacts', 'Emergency contacts and pickup people are kept by the centre, so to change those, message your director.', { wait: 600 }),
  ], {
    setup: ['echo json_encode(["phone" => DB::table("users")->where("id", 94)->value("phone")]);'],
    teardown: ['echo "phone restored:", DB::table("users")->where("id", 94)->update(["phone" => $ctx["phone"]]);'],
  }),

  S('quick-unlock-and-app-security', 'Quick unlock and app security', '#settings', [
    step('Security', 'Your security settings are in Settings, under Security.', { tap: 'text:Security', settle: 2200 }),
    step('Change your password', 'To change your password, type your current one, then the new one twice.', { typeAt0: true }, { type: ['input[type=password]', 'Demo1234!'], settle: 400 }),
    step('A strong password', 'The checklist shows when your new password is strong enough.', { eval: 'var p=document.querySelectorAll("input[type=password]"); p[1].focus();', settle: 300 }, { type: ['input[autocomplete=new-password], input[type=password]:nth-of-type(1)', ''], settle: 100 }),
    step('Save it', 'Then tap Change password.', { wait: 300 }),
    step('Quick unlock', 'In the KiddieTrac app, you can also unlock with your fingerprint or face, or a PIN, instead of typing your password.', { scroll: 650, ms: 2000 }),
    step('Shared devices', 'On a shared device, always sign out when you\'re finished.', { wait: 600 }),
  ], { skip: true }),

  S('understanding-the-ai-daily-digest', 'Understanding the AI daily digest', '#today', [
    step('The day\'s story', 'Each day KiddieTrac writes a short story of your child\'s day. Let\'s look at yesterday\'s.', { tap: 'text:◀', settle: 2300 }, { scroll: 380, ms: 1600 }),
    step('When it appears', 'It builds through the day and is ready by the evening, so it\'s normal for it to be short in the morning.', { wait: 800 }),
    step('Where it comes from', 'It only describes what was logged. Scroll down, and the timeline shows each moment it\'s based on.', { scroll: 650, ms: 2400 }),
    step('If it looks wrong', 'If something doesn\'t match your child\'s day, check the timeline first, then message the room team.', { wait: 600 }),
  ]),

  S('i-dont-see-my-childs-data', "I don't see my child's data", '#today', [
    step('The right child', 'If something\'s missing, first check you\'re on the right child.', { tap: 'text:Simran', settle: 1600 }, { tap: 'text:Arjun', settle: 1500 }),
    step('The right day', 'Then check the date. Today only fills in once your child arrives, so look back a day with the arrow.', { scroll: 380, ms: 1300 }, { tap: 'text:◀', settle: 2300 }),
    step('Refresh', 'Entries appear within seconds. If something still seems missing, pull down to refresh.', { scroll: -380, ms: 1200 }),
    step('Still missing?', 'If it\'s still missing, or you ever see another family\'s information, message your director straight away.', { wait: 600 }),
  ]),
];

/* The security lesson really changes the password, then puts the demo password back. */
const sec = scripts.find(s => s.slug === 'quick-unlock-and-app-security');
sec.skip = false;
sec.steps = [
  step('Security', 'Your security settings are in Settings, under Security.', { tap: 'text:Security', settle: 2200 }),
  step('Current password', 'To change your password, first type your current one.', { typeAt: ['input[type=password]', 0, 'Demo1234!'] }),
  step('A new password', 'Then your new password, twice. The checklist ticks off each rule as you type.', { typeAt: ['input[type=password]', 1, 'Sunflower#2026'] }, { typeAt: ['input[type=password]', 2, 'Sunflower#2026'] }),
  step('Save it', 'Tap Change password. Any other device signed in to your account is signed out, so only you stay in.', { tap: 'text:Change password', settle: 2600 }),
  step('Quick unlock', 'In the KiddieTrac app, you can also unlock with your fingerprint or face, or a PIN, instead of typing your password.', { scroll: 650, ms: 2000 }),
  step('Shared devices', 'On a shared device, always sign out when you\'re finished.', { wait: 600 }),
];
sec.setup = ['$u = DB::table("users")->where("id", 94)->first(["password", "password_changed_at"]);',
  'echo json_encode(["hash" => $u->password, "changed" => $u->password_changed_at, "since" => now()->format("Y-m-d H:i:s")]);'];
sec.teardown = ['DB::table("users")->where("id", 94)->update(["password" => $ctx["hash"], "password_changed_at" => $ctx["changed"], "must_change_password" => 0]);',
  '$h = \\Illuminate\\Support\\Facades\\Schema::hasTable("password_history") ? DB::table("password_history")->where("user_id", 94)->where("created_at", ">=", $ctx["since"])->delete() : 0;',
  'echo "password restored, history:$h";'];

// Titles that do not read after "a quick guide to": what Clara says instead.
const INTRO_AS = { 'i-dont-see-my-childs-data': "what to do when you don't see your child's data" };
scripts.forEach((s) => { if (INTRO_AS[s.slug]) s.introAs = INTRO_AS[s.slug]; });
for (const s of scripts) { delete s.skip; fs.writeFileSync(path.join(dir, s.slug + '.json'), JSON.stringify(s, null, 2)); }
console.log('wrote', scripts.length, 'parent scripts');
