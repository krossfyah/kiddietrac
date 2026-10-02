// Shared tutorials (resources/help/shared) — for every role. Recorded as the Test Agency demo
// parent (Harjit Singh, user 94) on a phone, except messaging, which is the educator's desktop
// chat window (Emily Chen, user 84). Anything a lesson creates is removed by its teardown.
// Not done for real, only shown: agreeing to texts (it sends a real SMS) and adding a passkey
// (it needs a real device authenticator).
const fs = require('fs');
const path = require('path');
const dir = path.join(__dirname, 'scripts', 'shared');
fs.mkdirSync(dir, { recursive: true });

const S = (slug, title, start, steps, extra) => ({ audience: 'shared', slug, title, account: 'parent', device: 'phone', start, steps, ...(extra || {}) });
const step = (chapter, say, ...actions) => ({ chapter, say, do: actions });

const PW_SETUP = ['$u = DB::table("users")->where("id", 94)->first(["password", "password_changed_at"]);',
  'echo json_encode(["hash" => $u->password, "changed" => $u->password_changed_at, "since" => now()->format("Y-m-d H:i:s")]);'];
const PW_TEARDOWN = ['DB::table("users")->where("id", 94)->update(["password" => $ctx["hash"], "password_changed_at" => $ctx["changed"], "must_change_password" => 0]);',
  '$h = \\Illuminate\\Support\\Facades\\Schema::hasTable("password_history") ? DB::table("password_history")->where("user_id", 94)->where("created_at", ">=", $ctx["since"])->delete() : 0;',
  'echo "password restored, history:$h";'];

const scripts = [
  S('welcome', 'Welcome to KiddieTrac', '#home', [
    step('Welcome', 'Welcome to KiddieTrac. It brings your centre, your educators and your family together in one app.', { wait: 900 }),
    step('Made for your role', 'What you see depends on who you are. A parent sees their own children. Educators see their room, and directors run the centre.', { ring: 'text:YOUR CHILDREN', ms: 1600 }),
    step('Live', 'Everything is live. When an educator logs a meal or a nap, it shows up here within seconds.', { ring: 'text:Today', ms: 1500 }, { ring: 'text:Photos & video', ms: 1500 }),
    step('Help is here', 'If you get stuck, open Help. Every guide has a short video like this one.', { scroll: 520, ms: 1600 }, { tap: 'text:Help', settle: 2600 }),
    step('Ask', 'You can also search, or ask a question in your own words.', { wait: 1200 }),
  ]),

  S('changing-your-password', 'Changing your password', '#settings', [
    step('Settings, Security', 'To change your password, open Settings, then the Security tab.', { tap: 'text:Security', settle: 2200 }),
    step('Current password', 'First, type your current password.', { typeAt: ['input[type=password]', 0, 'Demo1234!'] }),
    step('The rules', 'Then the new one. It needs eight characters, upper and lower case, a number and a symbol. The checklist ticks each one off as you type.', { typeAt: ['input[type=password]', 1, 'Sunflower#2026'] }, { ring: 'text:A symbol', ms: 1400 }),
    step('Confirm it', 'Type it again to confirm.', { typeAt: ['input[type=password]', 2, 'Sunflower#2026'] }),
    step('Save', 'Tap Change password. Your other devices are signed out, so only you stay in.', { tap: 'text:Change password', settle: 2800 }),
    step('Every 90 days', 'KiddieTrac asks everyone for a new password every 90 days, and you can\'t reuse one from the last six months.', { wait: 900 }),
  ], { setup: PW_SETUP, teardown: PW_TEARDOWN }),

  S('passkeys', 'Signing in with a passkey', '#settings', [
    step('What a passkey is', 'A passkey lets you sign in with your face, your fingerprint or your device PIN, instead of typing a password.', { tap: 'text:Security', settle: 2200 }),
    step('Add one', 'In Settings, under Security, tap Add a passkey, then confirm with your face, fingerprint or PIN when your device asks.', { scroll: 900, ms: 1800 }, { ring: 'text:Add a passkey', ms: 2600 }),
    step('Signing in', 'Next time, on the sign-in page, tap Sign in with a passkey. No password, and no code to type.', { wait: 900 }),
    step('Your devices', 'Each passkey is listed here with the device it was made on. Remove one you don\'t use any more. Your password still works alongside it.', { wait: 900 }),
  ]),

  S('privacy-and-data-security', 'Privacy and data security', '#settings', [
    step('Your data', 'Childcare data is sensitive, so here\'s how KiddieTrac looks after it.', { wait: 800 }),
    step('Who sees what', 'Parents see only their own children. Educators see the children at their centre, and every centre is kept separate from every other.', { wait: 900 }),
    step('What you share', 'In Settings, under Profile, you choose what other families can see about you: your email, your phone, your city, and your children\'s first names.', { scroll: 700, ms: 1800 }, { ring: 'text:Share my email', ms: 1400 }, { ring: 'text:Show my children\'s first names', ms: 1600 }),
    step('Payments', 'Card and bank details go straight to the payment processor. KiddieTrac never sees or stores the full number.', { wait: 800 }),
    step('Your records', 'Every form you sign is kept under Documents, and your director can export your child\'s records for you at any time.', { tap: 'text:Documents', settle: 2200 }),
  ]),

  S('texts-and-phone-calls', 'Texts and phone calls', '#settings', [
    step('Where to find it', 'Your text and phone call settings are in Settings, under Profile.', { wait: 800 }),
    step('Your number', 'First, make sure your mobile number is on your profile.', { ring: 'input[type=tel], input[inputmode=tel]', ms: 1800 }),
    step('Say yes to texts', 'Texts need your yes. Scroll down to Texts and phone calls, and tap Agree to receive texts. It shows exactly what you\'re agreeing to, and a confirmation text arrives on your phone.', { scroll: 1300, ms: 2200 }, { ring: 'text:Agree to receive texts', ms: 2400 }),
    step('Choose the kinds', 'Then each kind has its own switch: sign in and sign out, reminders, announcements and replies. Turn off any you don\'t want.', { wait: 900 }),
    step('Calls', 'For urgent news, like a closure or an emergency, your agency can also call and read a short message. Each reason has its own switch too.', { wait: 900 }),
  ]),

  S('opening-and-printing-documents', 'Opening and printing documents', '#settings', [
    step('Your documents', 'Anything you open in KiddieTrac opens inside the app, in a panel over the screen you were on. Let\'s open one from Documents.', { tap: 'text:Documents', settle: 2200 }),
    step('Open it', 'Tap the button beside a document to open it.', { tap: 'text:ℹ️', settle: 3200 }),
    step('Print', 'To print, tap Print. In the phone app, it hands the document to your browser, which is where the print dialog lives.', { ring: 'text:Print', ms: 2000 }),
    step('Back where you were', 'Tap the cross to close it. You\'re straight back where you were, and nothing reloads.', { tap: 'text:✕', settle: 1800 }),
  ]),
];

/* Messaging is the desktop chat window, so it is the educator's, on a computer. */
scripts.push({
  audience: 'shared', slug: 'messaging-upgrades', title: 'Messaging: voice notes, screenshots and deleting', account: 'educator', device: 'desktop', start: '#chat',
  steps: [
    step('Messenger', 'On a computer, Messenger works like a proper messenger. Tap a conversation to open it.', { tap: 'text:How long did Riya nap', settle: 2400 }),
    step('A chat window', 'It pops out as a window in the corner. Minimise it with the dash, and it stays put while you move around the app.', { ring: 'text:–', ms: 1800 }),
    step('Voice notes and pictures', 'Tap the microphone to record a voice note, or paste a screenshot straight into the message box.', { ring: 'text:🎤', ms: 1600 }, { ring: 'input[placeholder^="Type a message"]', ms: 1600 }),
    step('Send', 'Type a message and press Enter.', { type: ['input[placeholder^="Type a message"]', 'Hi Anika! About an hour and a half.'], settle: 500 }, { key: 'Enter' }, { wait: 4000 }),
    step('Delete a message', 'To take back a message you sent, tap the bin beside it, and confirm. It leaves a note that a message was deleted.', { tap: 'button.kt-msg-del', settle: 1500 }, { tap: 'text:Delete', settle: 2200 }),
  ],
  setup: ['$c = DB::table("conversations")->insertGetId(["centre_id" => 16, "family_id" => 51, "child_id" => 54, "subject" => "About Riya", "channel" => null, "last_message_at" => now(), "created_at" => now()]);',
    'DB::table("messages")->insert(["conversation_id" => $c, "sender_id" => 90, "body" => "Hi! How long did Riya nap today?", "is_system" => 0, "created_at" => now()]);',
    'echo json_encode(["conv" => $c]);'],
  teardown: ['echo "messages:", DB::table("messages")->where("conversation_id", $ctx["conv"])->delete();',
    'echo " conv:", DB::table("conversations")->where("id", $ctx["conv"])->where("family_id", 51)->delete();'],
});

for (const s of scripts) fs.writeFileSync(path.join(dir, s.slug + '.json'), JSON.stringify(s, null, 2));
console.log('wrote', scripts.length, 'shared scripts');
