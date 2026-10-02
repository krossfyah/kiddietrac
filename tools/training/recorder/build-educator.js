// Educator tutorials (resources/help/educator) — each DOES the function, step by step, as the
// Test Agency demo educator Emily Chen (user 84), room Sunshine Infants (#22, centre 16), on the
// demo-only children Riya Patel (#54, family 51, parent Anika #90), Simran Singh, Zoe Tremblay.
// Everything a lesson creates is removed by its teardown / cleanup.
const fs = require('fs');
const path = require('path');
const dir = path.join(__dirname, 'scripts', 'educator');
fs.mkdirSync(dir, { recursive: true });

const S = (slug, title, start, steps, extra) => ({ audience: 'educator', slug, title, account: 'educator', device: 'phone', start, steps, ...(extra || {}) });
const step = (chapter, say, ...actions) => ({ chapter, say, do: actions });
const SINCE = ['echo json_encode(["since" => now()->format("Y-m-d H:i:s")]);'];
const CHILD = { choose: ['select', 'Riya Patel'], settle: 1400 };
const del = (table, col) => `$n["${table}"] = DB::table("${table}")->where("${col}", 84)->where("created_at", ">=", $ctx["since"])->delete();`;
const teardown = (...lines) => ['$n = [];', ...lines, 'echo json_encode($n);'];

const scripts = [
  S('getting-started-your-first-day', 'Getting started — your first day', '#home', [
    step('Your home screen', 'Welcome to KiddieTrac. This is your classroom home screen. Let\'s walk through your first day.', { wait: 600 }),
    step('Clock in first', 'Start every shift by clocking in, using the strip at the top. Here, Emily is already clocked in, and it shows how long for.', { ring: 'text:Clocked in', ms: 2000 }),
    step('Your room at a glance', 'These cards show who\'s signed in now, medications given, observations this week, and your hours.', { ring: 'text:SIGNED IN NOW', ms: 1400 }, { ring: 'text:MY HOURS THIS WEEK', ms: 1400 }),
    step('Today', 'Tap Today to see your room\'s roster, where you check children in and out.', { tap: 'text:Today', settle: 2600 }),
    step('Logging moments', 'Through the day, log meals, naps and activities from Daily log, at the bottom.', { tap: 'text:Daily log', settle: 2400 }),
    step('Everything else', 'Back on Home, every other tool is one tap away: observations, awards, lesson plans, incidents, medications, and more.', { tap: 'text:Home', settle: 2200 }, { scroll: 420, ms: 1800 }),
  ]),

  S('clocking-in-and-out', 'Clocking in and out', '#time-clock', [
    step('The time clock', 'The time clock shows whether you\'re on shift, and your recent punches.', { wait: 900 }),
    step('Clocking out', 'At the end of your shift, tap Clock out. It records the time straight away.', { tap: 'text:Clock out', settle: 2600 }),
    step('Clocking in', 'At the start of your next shift, tap Clock in. You need to be clocked in before you can check children in or log their care.', { tap: 'text:Clock in', settle: 2400 }),
    step('Your hours', 'Every punch is listed here, and your weekly total is on your home screen.', { scroll: 360, ms: 1600 }),
  ], {
    setup: ['$open = DB::table("time_punches")->where("user_id", 84)->whereNull("punched_out_at")->orderByDesc("id")->value("id");',
      'echo json_encode(["since" => now()->format("Y-m-d H:i:s"), "open" => $open]);'],
    teardown: teardown(
      'if ($ctx["open"]) { $n["reopened"] = DB::table("time_punches")->where("id", $ctx["open"])->update(["punched_out_at" => null]); }',
      del('time_punches', 'user_id')),
  }),

  S('checking-children-in-and-out', 'Checking children in and out', '#today', [
    step('Your roster', 'Today shows every child expected in your room. Grey means not in yet.', { scroll: 520, ms: 1800 }),
    step('Check a child in', 'When a child arrives, tap Check in on their card.', { tap: 'text:Check in', settle: 2000 }, { tap: 'text:Yes, check in', settle: 2200 }),
    step('Checked in', 'Their card turns green with the time, and the parents are told automatically.', { wait: 1400 }),
    step('Check a child out', 'At pickup, tap Check out on their card, and confirm.', { tap: 'text:Check out', settle: 2000 }, { tap: 'text:Yes, check out', settle: 2200 }),
    step('Absent', 'If a child won\'t be coming, tap Absent instead, so Safe Arrival doesn\'t raise an alert.', { ring: 'text:Absent', ms: 1800 }),
  ], { setup: SINCE, teardown: teardown(del('check_events', 'by_user_id')) }),

  S('quick-logging-events', 'Quick logging events', '#care-log', [
    step('Daily log', 'Daily log is where you record a child\'s day: meals, naps, diapers, bottles and more.', { wait: 900 }),
    step('Choose the child', 'First choose the child.', CHILD),
    step('Pick what happened', 'Then tap what happened. Here, a meal.', { tap: 'text:Meal', settle: 1800 }),
    step('Add the detail', 'Add the detail: how much they ate. The time is filled in; change it if it was earlier.', { tap: 'text:Ate most', settle: 900 }),
    step('Log it', 'Tap Log it. It appears in today\'s log, and on the parent\'s app straight away.', { tap: 'text:Log it', settle: 2400 }),
    step('Today\'s log', 'Everything you\'ve logged today is listed below, newest first.', { scroll: 700, ms: 2200 }),
  ], { setup: SINCE, teardown: teardown(del('daily_events', 'recorded_by_id')) }),

  S('outdoor-play', 'Logging outdoor play', '#care-log', [
    step('Outdoor play', 'Logging outdoor time shows parents their child got fresh air, and keeps your daily record complete.', { wait: 800 }),
    step('Choose the child', 'Choose the child.', CHILD),
    step('Outdoor play', 'Tap Outdoor play.', { tap: 'text:Outdoor play', settle: 1800 }),
    step('Log it', 'Add a note if you like, then tap Log it.', { tap: 'text:Log it', settle: 2400 }),
    step('Done', 'It\'s on the child\'s timeline now, and the parent can see it.', { scroll: 700, ms: 2000 }),
  ], { setup: SINCE, teardown: teardown(del('daily_events', 'recorded_by_id')) }),

  S('sharing-photos', 'Sharing photos', '#care-log', [
    step('Sharing a photo', 'Photos are one of the things parents love most. Here\'s how to share one.', { wait: 800 }),
    step('Choose the child', 'Choose the child in the photo.', CHILD),
    step('Photo', 'Tap Photo, then take a picture or choose one from your gallery.', { tap: 'text:Photo', settle: 1800 }, { upload: ['input[type=file]', 'assets/block-tower.jpg'], settle: 2200 }),
    step('Share it', 'Then share it. It goes to the child\'s parents, and into their photo gallery.', { type: ['input[placeholder^="What is happening"]', 'Building a tall block tower'], settle: 500 }, { tap: 'text:Share it', settle: 3600 }),
    step('Good photos', 'The best photos show the child busy and happy. Check who else is in the picture before you share.', { wait: 700 }),
  ], {
    setup: SINCE,
    teardown: teardown(
      '$ph = DB::table("photos")->where("uploaded_by_id", 84)->where("created_at", ">=", $ctx["since"])->get();',
      'foreach ($ph as $p) { foreach ([$p->url, $p->thumbnail_url] as $u) { if ($u && str_contains($u, "/storage/")) \\Illuminate\\Support\\Facades\\Storage::disk("public")->delete(preg_replace("#^.*/storage/#", "", $u)); } }',
      '$n["photos"] = DB::table("photos")->whereIn("id", $ph->pluck("id"))->delete();',
      del('daily_events', 'recorded_by_id')),
  }),

  S('recording-observations', 'Recording observations', '#observation-new', [
    step('Observations', 'An observation records a moment of learning, like a new skill, or a kind thing a child did.', { wait: 800 }),
    step('Choose the child', 'Choose the child.', CHILD),
    step('What you saw', 'Then describe what you saw, in a sentence or two.', { type: ['textarea', 'Riya stacked four blocks, then clapped and pointed at the tower.'], settle: 600 }),
    step('Write it up', 'Tap Write manually, or let AI structure it for you.', { tap: 'text:Write manually (no AI)', settle: 2200 }),
    step('Review', 'Choose the learning domain, check the summary parents will see, and decide whether to share it now.', { scroll: 300, ms: 1500 }),
    step('Save', 'Tap Save observation. It builds into the child\'s portfolio over the year.', { tap: 'text:Save observation', settle: 2600 }),
  ], { setup: SINCE, teardown: teardown(del('observations', 'recorded_by_id')) }),

  S('issuing-awards', 'Issuing awards and certificates', '#awards', [
    step('Awards', 'Awards celebrate the little wins, and parents can print them as certificates.', { wait: 800 }),
    step('New award', 'Tap New award.', { tap: 'text:New award', settle: 1800 }),
    step('Who and what', 'Choose the child, name the award, and pick a badge.', CHILD, { type: ['input[placeholder*="Star"]', 'Super Stacker'], settle: 500 }, { tap: 'text:🌟', settle: 800 }),
    step('Say why', 'Add a short, specific message. That\'s what makes it mean something to the family.', { type: ['textarea', 'Riya built her tallest tower yet this week!'], settle: 500 }),
    step('Give it', 'Tap Give award. The family gets it straight away.', { tap: 'text:Give award', settle: 2400 }),
  ], { setup: SINCE, teardown: teardown(del('child_awards', 'awarded_by_id')) }),

  S('giving-medications', 'Giving medications safely', '#medications', [
    step('Medications', 'Medications lists every medicine a parent has authorised for children in your care.', { wait: 900 }),
    step('Check first', 'Before giving anything, check the five rights: the right child, medication, dose, time and route.', { wait: 600 }),
    step('Log the dose', 'Open the menu for the medication, and tap Log dose.', { tap: 'text:⋮', settle: 1500 }, { tap: 'text:Log dose', settle: 2000 }),
    step('Record it', 'Record the dose you gave, the outcome and any note, then tap Record dose. It\'s timed and signed with your name.', { type: ['input[placeholder="5 mL"]', '2 puffs'], settle: 500 }, { typeAt: ['textarea', 0, 'Wheezing after outdoor play. Settled within 10 minutes.'] }, { tap: 'text:Record dose', settle: 2400 }),
    step('The dose log', 'Every dose given is in the log, for the parents and the director.', { wait: 800 }),
  ], { setup: SINCE, teardown: teardown(del('medication_logs', 'administered_by_id'), del('medication_administrations', 'administered_by_id')) }),

  S('when-something-happens', 'When something happens', '#incidents', [
    step('Incidents', 'If a child is hurt or something happens, write it up as an incident report.', { wait: 800 }),
    step('New report', 'Tap New report.', { tap: 'text:New report', settle: 2200 }),
    step('The facts', 'Choose the child, when it happened, and what type of incident it was.', CHILD, { choose: ['select', 'Injury'], settle: 900 }),
    step('What happened', 'Describe what happened, plainly: where, how, and what you did.', { type: ['textarea', 'Riya tripped on the mat and bumped her knee. No mark. Comforted, ice pack for 5 minutes.'], settle: 600 }),
    step('Save', 'Save it as a draft to finish later, or submit it for the director to review. The parent is told once it\'s reviewed.', { tap: 'text:Save as draft', settle: 2400 }),
  ], { setup: SINCE, teardown: teardown(del('incidents', 'recorded_by_id')) }),

  S('messaging-parents', 'Messaging parents', '#chat', [
    step('Messenger', 'Messenger holds your conversations with families. Here, Riya\'s mum has asked a question.', { wait: 900 }),
    step('Open it', 'Tap the conversation to open it.', { tap: 'text:How long did Riya nap', settle: 2200 }),
    step('Reply', 'Type your reply.', { type: ['input[placeholder^="Type a message"]', 'Hi Anika! She napped for about an hour and a half, from 12:30. Slept well.'], settle: 600 }),
    step('Send', 'Then send it. The parent gets a notification straight away.', { key: 'Enter' }, { wait: 4000 }),
    step('Keep it friendly', 'Keep messages warm and short. For anything serious, call the parent or involve your director.', { wait: 600 }),
  ], {
    setup: ['$c = DB::table("conversations")->insertGetId(["centre_id" => 16, "family_id" => 51, "child_id" => 54, "subject" => "About Riya", "channel" => null, "last_message_at" => now(), "created_at" => now()]);',
      'DB::table("messages")->insert(["conversation_id" => $c, "sender_id" => 90, "body" => "Hi! How long did Riya nap today?", "is_system" => 0, "created_at" => now()]);',
      'echo json_encode(["conv" => $c]);'],
    teardown: teardown('$n["messages"] = DB::table("messages")->where("conversation_id", $ctx["conv"])->delete();', '$n["conv"] = DB::table("conversations")->where("id", $ctx["conv"])->delete();'),
  }),

  S('child-records-allergies-and-safety', 'Child records, allergies and safety', '#children', [
    step('Child records', 'Child records holds everything you need to keep each child safe.', { wait: 900 }),
    step('Open a record', 'Tap a child to open their record.', { tap: 'text:Riya Patel', settle: 2600 }),
    step('Safety first', 'Allergies, medical notes and health alerts are at the top. Read them before every meal and outing.', { scroll: 300, ms: 1600 }),
    step('Who can pick up', 'Further down: who\'s allowed to pick the child up. Never release a child to anyone not on this list.', { scroll: 600, ms: 2200 }),
  ]),

  S('lesson-plans-and-curriculum', 'Lesson plans and curriculum', '#lesson-plans', [
    step('Lesson plans', 'Lesson plans is your room\'s weekly plan, and parents can see it too.', { wait: 900 }),
    step('The week', 'Use the arrows to pick the week you\'re planning.', { ring: 'input[type=date]', ms: 1600 }),
    step('A theme', 'Give the week a theme.', { type: ['input[placeholder*="Spring"]', 'Building and stacking'], settle: 600 }),
    step('Templates', 'Templates give you a ready-made week to start from, and AI can draft one for you.', { ring: 'text:Templates', ms: 1300 }, { ring: 'text:Draft with AI', ms: 1300 }),
    step('Save', 'Tap save, and the plan is shared with your room and the families.', { tap: 'text:💾', settle: 2400 }),
  ], { setup: SINCE, teardown: teardown(del('lesson_plans', 'created_by_id')) }),

  S('requesting-time-off', 'Requesting time off', '#time-off', [
    step('Time off', 'Time off is where you ask for vacation or sick days, and see your requests.', { wait: 900 }),
    step('New request', 'Tap Request time off.', { tap: 'text:Request time off', settle: 2000 }),
    step('The details', 'Choose the type, the dates, and add a reason.', { choose: ['select', 'Vacation'], settle: 700 }, { typeAt: ['input[type=date]', 0, '2026-11-12'] }, { typeAt: ['input[type=date]', 1, '2026-11-13'] }, { type: ['textarea', 'Family wedding'], settle: 500 }),
    step('Submit', 'Tap Submit. Your director gets it to approve, and you\'ll see the answer here.', { tap: 'text:Submit', settle: 2400 }),
    step('Changing your mind', 'Until it\'s decided, you can withdraw it.', { ring: 'text:Withdraw', ms: 1600 }),
  ], { setup: SINCE, teardown: teardown(del('time_off_requests', 'user_id')) }),

  S('my-tasks', 'My tasks', '#my-tasks', [
    step('Your tasks', 'My tasks lists what your director has asked you to do. Here, a first-aid kit check.', { wait: 900 }),
    step('Start', 'Tap Start when you begin.', { tap: 'text:Start', settle: 1600 }),
    step('Done', 'When it\'s finished, tap Mark done. Your director sees it\'s complete.', { tap: 'text:Mark done', settle: 2200 }),
  ], {
    setup: ['$id = DB::table("tasks")->insertGetId(["agency_id" => 6, "centre_id" => 16, "assigned_to" => 84, "assigned_by" => 85, "title" => "Check the first-aid kit in Sunshine Infants", "description" => "Restock plasters and check expiry dates.", "priority" => "normal", "due_date" => now()->addDays(1)->toDateString(), "status" => "open", "created_at" => now(), "updated_at" => now()]);',
      'echo json_encode(["task" => $id]);'],
    teardown: ['echo "tasks:", DB::table("tasks")->where("id", $ctx["task"])->where("assigned_to", 84)->delete();'],
  }),

  S('my-hours-and-pay', 'My hours and my pay', '#my-hours', [
    step('My hours', 'My hours shows every shift from the time clock, and your total for the week.', { wait: 1000 }),
    step('Each shift', 'Each day lists when you clocked in and out.', { scroll: 420, ms: 1800 }),
    step('Your pay', 'Your pay itself is worked out by the office. If your hours look wrong, tell your director.', { wait: 700 }),
  ]),

  S('safe-arrival', 'Safe Arrival — when a child hasn\'t arrived', '#safe-arrival', [
    step('Safe Arrival', 'Safe Arrival watches for children who were expected but haven\'t arrived.', { wait: 1000 }),
    step('Who\'s expected', 'It shows who\'s arrived, who\'s absent, and who isn\'t due yet.', { scroll: 300, ms: 1600 }),
    step('An alert', 'If a child is late and the parent hasn\'t answered, you\'ll get an alert. Call the parent, then record what you found: an absence, or that you\'ve reached them.', { wait: 900 }),
  ]),

  S('today-at-a-glance', 'Today at a glance — your day brief', '#today', [
    step('Your day brief', 'Today opens with your day brief: the cards across the top.', { wait: 900 }),
    step('Attendance', 'Attendance shows who\'s in, and who\'s still to arrive.', { ring: 'text:ATTENDANCE', ms: 1800 }),
    step('Plans and meals', 'Lesson plan and meal plan tell you if this week\'s are set up.', { ring: 'text:LESSON PLAN', ms: 1400 }, { ring: 'text:MEAL PLAN', ms: 1400 }),
    step('What needs you', 'Incidents and news show anything new today. Tap a card to go straight to it.', { ring: 'text:INCIDENTS TODAY', ms: 1600 }),
    step('Your room', 'Below the cards is your room\'s roster.', { scroll: 520, ms: 1800 }),
  ]),

  S('using-the-tablet-view', 'Using the tablet view', '#today', [
    step('On a tablet', 'On the room tablet, KiddieTrac shows more at once: the roster and your tools side by side.', { wait: 1000 }),
    step('Clock in', 'Start by clocking in, from the strip at the top.', { ring: 'text:Clocked in', ms: 1800 }),
    step('Through the day', 'Check children in as they arrive, and log moments from each card.', { scroll: 420, ms: 1800 }),
    step('End of the day', 'At the end of the day, check everyone out, then clock out.', { wait: 700 }),
  ], { device: 'desktop' }),
];

for (const s of scripts) fs.writeFileSync(path.join(dir, s.slug + '.json'), JSON.stringify(s, null, 2));
console.log('wrote', scripts.length, 'educator scripts');
