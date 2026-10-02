// Home-visitor tutorials (resources/help/home_visitor), as the Test Agency demo home visitor
// Dana Visitor (user 232, centre 18). Each lesson does the function; its teardown removes it.
const fs = require('fs');
const path = require('path');
const dir = path.join(__dirname, 'scripts', 'home_visitor');
fs.mkdirSync(dir, { recursive: true });

const S = (slug, title, start, steps, extra) => ({ audience: 'home_visitor', slug, title, account: 'home_visitor', device: 'phone', start, steps, ...(extra || {}) });
const step = (chapter, say, ...actions) => ({ chapter, say, do: actions });
const SINCE = ['echo json_encode(["since" => now()->format("Y-m-d H:i:s")]);'];
const delSince = (table) => [`echo "${table}:", DB::table("${table}")->where("home_visitor_id", 232)->where("created_at", ">=", $ctx["since"])->delete();`];

const REPORT = (extra) => ['$id = DB::table("home_visit_reports")->insertGetId(["agency_id" => 6, "centre_id" => 18, "home_visitor_id" => 232, "family_name" => "The Patel family", "child_name" => "Riya", "visit_date" => now()->subDays(3)->toDateString(), "visit_type" => "Routine visit", "location" => "Family home", "duration_minutes" => 45, "present" => "Mother, child", "summary" => "Routine visit. Riya is settling well and enjoying the infant room.", "strengths" => "Warm, consistent routines at home.", "next_steps" => "Share the weekly lesson plan with the family.", "status" => "submitted", "created_at" => now(), "updated_at" => now()]);',
  'echo json_encode(["report" => $id]);'];

const scripts = [
  S('getting-started', 'Getting started as a Home Visitor', '#home', [
    step('Your home screen', 'Welcome to KiddieTrac. As a home visitor, this is your home screen.', { wait: 700 }),
    step('At a glance', 'The cards show the reports you\'ve completed, follow-ups that are due, your visits this month, and open tasks.', { ring: 'text:Reports completed', ms: 1500 }, { ring: 'text:Follow-ups due', ms: 1500 }),
    step('Your tools', 'Below them: start a new visit report, see your reports, your tasks, and inspection forms.', { ring: 'text:New visit report', ms: 1200 }, { ring: 'text:My reports', ms: 1200 }, { ring: 'text:Inspection forms', ms: 1200 }),
    step('Getting help', 'Help and Training are always here, with a guide and a video for each part.', { scroll: 420, ms: 1600 }, { ring: 'text:Help', ms: 1400 }),
  ]),

  S('filing-a-home-visit-report', 'Filing a home visit report', '#new-home-visit', [
    step('A new report', 'To file a visit, tap New visit report. Three things are required: the centre, the date, and a summary.', { wait: 900 }),
    step('Centre and date', 'Choose the centre. The date is today; change it if the visit was earlier.', { choose: ['select', 'Sunny Meadows'], settle: 900 }),
    step('The visit', 'Choose the visit type and where it happened, and how long it took.', { choose: ['select', 'Routine visit'], settle: 700 }, { choose: ['select', 'Family home'], settle: 700 }, { type: ['input[placeholder*="45"]', '45'], settle: 400 }),
    step('The family', 'Add the family, and who was there.', { type: ['input[placeholder*="family"]', 'The Patel family'], settle: 400 }, { type: ['input[placeholder*="Mother"]', 'Mother, child'], settle: 400 }),
    step('Summary', 'Write the visit summary, then any strengths, concerns and next steps.', { typeAt: ['textarea', 0, 'Routine visit. Riya is settling well and enjoying the infant room.'] }, { typeAt: ['textarea', 1, 'Warm, consistent routines at home.'] }),
    step('Submit', 'Tap Submit report. You land on My reports, with a confirmation.', { tap: 'text:Submit report', settle: 2800 }),
  ], { setup: SINCE, teardown: delSince('home_visit_reports') }),

  S('my-reports', 'Viewing your reports', '#home-visits', [
    step('My reports', 'My reports lists every visit you\'ve filed, newest first.', { wait: 1000 }),
    step('Open one', 'To read one again, open its menu and tap View report.', { tap: 'text:⋮', settle: 1400 }, { tap: 'text:View report', settle: 2600 }),
    step('Follow-ups', 'Any follow-up date you set shows up on your home screen when it\'s due.', { wait: 800 }),
  ], { setup: REPORT(), teardown: ['echo "reports:", DB::table("home_visit_reports")->where("id", $ctx["report"])->where("home_visitor_id", 232)->delete();'] }),

  S('inspection-forms', 'Inspection forms', '#inspection-forms', [
    step('Inspection forms', 'Inspection forms are your monthly and quarterly checks of each home.', { wait: 900 }),
    step('Pick the form', 'Tap the form you need. Here, the monthly monitoring report.', { tap: 'text:Monthly Home Visitor Monitoring', settle: 2600 }),
    step('The visit', 'Choose the centre and fill in the provider, and your times in and out.', { choose: ['select', 'Sunny Meadows'], settle: 800 }, { typeAt: ['input[type=text]', 0, 'Maria Lopez'] }),
    step('The checks', 'Work down the form. It matches the paper version, section by section.', { tap: 'text:Yes', settle: 600 }, { scroll: 900, ms: 2400 }, { tap: 'text:Stairs safe', settle: 500 }, { tap: 'text:Gates secure', settle: 500 }),
    step('Sign and submit', 'At the bottom, type your name to sign it, and tap Submit form. The office is told straight away.', { scroll: 4000, ms: 3000 }, { type: ['#hv_sig', 'Dana Visitor'], settle: 600 }, { tap: 'text:Submit form', settle: 3000 }),
  ], { setup: SINCE, teardown: delSince('hcc_inspection_forms') }),

  S('my-tasks', 'My tasks', '#my-tasks', [
    step('Your tasks', 'My tasks lists what the office has asked you to do, with the priority and due date.', { wait: 900 }),
    step('Start', 'Tap Start when you pick one up.', { tap: 'text:Start', settle: 1600 }),
    step('Done', 'Tap Mark done when it\'s finished. It drops off your open count straight away.', { tap: 'text:Mark done', settle: 2200 }),
  ], {
    setup: ['$id = DB::table("tasks")->insertGetId(["agency_id" => 6, "centre_id" => 18, "assigned_to" => 232, "assigned_by" => 232, "title" => "Book the quarterly inspection with the Lopez home", "description" => "Call to arrange a date in the next two weeks.", "priority" => "high", "due_date" => now()->addDays(3)->toDateString(), "status" => "open", "created_at" => now(), "updated_at" => now()]);',
      'echo json_encode(["task" => $id]);'],
    teardown: ['echo "tasks:", DB::table("tasks")->where("id", $ctx["task"])->where("assigned_to", 232)->delete();'],
  }),
];

for (const s of scripts) fs.writeFileSync(path.join(dir, s.slug + '.json'), JSON.stringify(s, null, 2));
console.log('wrote', scripts.length, 'home visitor scripts');
