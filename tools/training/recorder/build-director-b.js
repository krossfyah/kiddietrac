// Director tutorials, group B (resources/help/director: everything except Billing, Administration,
// Settings and Troubleshooting). Recorded as the Test Agency demo director Rebecca Bright (user 100,
// centre 18) on a desktop, except the few family-side lessons, which are the demo parent (Harjit
// Singh, user 94) on a phone, because the director's guide describes what families do.
//
// Nothing here moves money, messages anyone, or changes settings: those controls are only ringed.
// Every selector and label below was seen in `node tut.js explore` on 2026-10-02.
//
// Topics whose screen does not work for a director today are written to scripts/director-blocked/
// instead, so they are ready to record once the screen is fixed (see BLOCKED at the bottom).
const fs = require('fs');
const path = require('path');
const dir = path.join(__dirname, 'scripts', 'director');
const blockedDir = path.join(__dirname, 'scripts', 'director-blocked');
fs.mkdirSync(dir, { recursive: true });
fs.mkdirSync(blockedDir, { recursive: true });

const S = (slug, title, start, steps, extra) => ({ audience: 'director', slug, title, account: 'director', device: 'desktop', start, steps, ...(extra || {}) });
const P = (slug, title, start, steps, extra) => ({ audience: 'director', slug, title, account: 'parent', device: 'phone', start, steps, ...(extra || {}) });
const step = (chapter, say, ...actions) => ({ chapter, say, do: actions });

// The director has a floating team-chat window open on every screen (old test messages in it).
// It is not part of any lesson, so each director video hides it first.
const DOCK = { eval: "(function(){var s=document.createElement('style');s.textContent='#kt-chat-dock{display:none!important}';document.head.appendChild(s);})()", settle: 150 };
// Ctrl+K, the same event the portal listens for.
const CTRL_K = { eval: "document.dispatchEvent(new KeyboardEvent('keydown',{key:'k',ctrlKey:true,bubbles:true}))", settle: 900 };
const JACK = '#child-detail?id=78';   // Jack Lopez, Lopez Family, Discovery Preschool: a demo child

const scripts = [
  /* ── Getting started ─────────────────────────────────────────── */
  S('understanding-the-dashboard', 'Understanding the dashboard', '#dashboard', [
    step('Your dashboard', 'Your dashboard is the first thing you see each morning. Here\'s how to read it.', DOCK, { wait: 600 }),
    step('Enrolled', 'Enrolled is every child on your roll right now.', { ring: 'text:ENROLLED', ms: 1600 }),
    step('Here right now', 'Here right now counts the children who are signed in and not yet signed out.', { ring: 'text:HERE RIGHT NOW', ms: 1600 }),
    step('Staff on floor', 'Staff on floor is everyone clocked in. If it says zero while children are here, your rooms will show a breach.', { ring: 'text:STAFF ON FLOOR', ms: 1800 }),
    step('Receivables', 'Receivables is the total still owed on invoices.', { ring: 'text:RECEIVABLES', ms: 1500 }),
    step('Your rooms', 'Below, each room shows its ratio status, who\'s present, and how many educators it needs.', { scroll: 520, ms: 1800 }, { ring: 'text:Rooms', ms: 1400 }),
  ]),

  S('role-widgets', 'Role widgets on your dashboard', '#today', [
    step('Your cards', 'As a director, four cards at the top of Today give you the morning at a glance.', DOCK, { wait: 600 }),
    step('Capacity', 'Capacity today: how full your centre is.', { ring: 'text:CAPACITY TODAY', ms: 1500 }),
    step('Open invoices', 'Open invoices: what families still owe.', { ring: 'text:OPEN INVOICES', ms: 1500 }),
    step('Not checked in', 'Not checked in: children expected today who haven\'t arrived yet.', { ring: 'text:NOT CHECKED IN', ms: 1600 }),
    step('Enrolled', 'And enrolled: the children on your roll. Each card opens the screen behind it.', { ring: 'text:Children on roll', ms: 1600 }),
  ]),

  S('roles-and-responsibilities', 'Roles and responsibilities', '#dashboard', [
    step('Who does what', 'Everyone in KiddieTrac has a role, and the role decides what they see and do.', DOCK, { wait: 600 }),
    step('Your menu', 'As a centre director, your menu covers your centre: children, families, staff, daily operations and compliance.', { ring: 'text:Children', ms: 1200 }, { ring: 'text:Families', ms: 1200 }, { ring: 'text:Staff', ms: 1200 }),
    step('Your team', 'Educators see their rooms and log the day. Home visitors file visit reports. Parents see only their own children.', { ring: 'text:Home visit reports', ms: 1600 }),
    step('The same menu everywhere', 'Every list shares the same three-dot menu at the end of each row, so actions are always in the same place.', { go: '#timesheets', settle: 2600 }, { ring: 'text:⋮', ms: 1800 }),
  ]),

  S('card-vs-table-view', 'Lists, row menus and exports', '#background-checks', [
    step('Every list works the same', 'Every list in KiddieTrac works the same way. Here\'s the background checks list.', DOCK, { wait: 700 }),
    step('Filter', 'Type in the filter box to narrow the list as you type.', { ring: 'input.kt-table-filter-input', ms: 1500 }),
    step('Row actions', 'Each row has a three-dot menu with its actions: view, edit, or delete.', { tap: 'text:⋮', settle: 1400 }, { ring: 'text:View', ms: 1600 }, { key: 'Escape' }),
    step('Export', 'At the bottom, export the list to Excel, CSV or PDF, and see how many records it holds.', { ring: 'text:📊', ms: 1400 }, { ring: 'text:📕', ms: 1200 }),
  ]),

  S('command-palette', 'The command palette', '#dashboard', [
    step('Jump anywhere', 'Press Control and K, from any screen, to open the command palette.', DOCK, CTRL_K),
    step('Jump to a screen', 'It lists the places you go most, each with a shortcut.', { ring: 'text:JUMP TO', ms: 1600 }),
    step('Search', 'Or start typing. It searches children, families, rooms and staff.', { type: ['.kt-cmdk-input', 'Jack'], settle: 1800 }),
    step('Open it', 'Use the arrow keys to pick a result, and Enter to open it. Escape closes the palette.', { key: 'Escape' }, { wait: 600 }),
  ]),

  /* ── Daily operations ────────────────────────────────────────── */
  S('understanding-ratios', 'Understanding ratios', '#dashboard', [
    step('Ratios', 'KiddieTrac checks educator-to-child ratios in every room, all day.', DOCK, { scroll: 520, ms: 1600 }),
    step('Compliant', 'Compliant, in green, means there are enough educators for the children present.', { ring: 'text:COMPLIANT', ms: 1800 }),
    step('Tight and breach', 'Tight means you\'re at the legal minimum: one more child puts you over. Breach, in red, means you need another educator now.', { wait: 900 }),
    step('How it\'s counted', 'It counts the children signed in and the educators clocked in, against each room\'s ratio. Ontario\'s is one to three for infants and one to five for toddlers.', { ring: 'text:Ratio 1:3', ms: 1800 }),
    step('Fixing a breach', 'Most breaches are an educator who forgot to clock in, or a child who wasn\'t signed out. Fix that, and the room turns green.', { wait: 800 }),
  ]),

  S('safe-arrival', 'Safe Arrival', '#safe-arrival', [
    step('Every child accounted for', 'Safe Arrival makes sure every child who is expected today is accounted for.', DOCK, { wait: 700 }),
    step('Needs attention', 'If a child hasn\'t arrived by their usual time, plus a grace period, and no absence was reported, they appear under Needs attention.', { ring: 'text:Needs attention', ms: 2000 }),
    step('Everyone else', 'Everyone else today shows the children who are in or accounted for.', { ring: 'text:Everyone else today', ms: 1800 }),
    step('Closing a check', 'The moment the child is signed in, or a parent reports an absence, the check closes by itself.', { tap: 'text:↻ Refresh', settle: 1800 }),
  ]),

  S('all-contacts', 'All contacts', '#contacts', [
    step('One address book', 'All contacts is one address book: every parent and staff member, plus contacts you add.', DOCK, { wait: 700 }),
    step('Search', 'Search by name, company or number.', { type: ['input[placeholder^="Search name"]', 'Emily'], settle: 1600 }),
    step('By category', 'Or narrow it by category.', { choose: ['select', 'Educator'], settle: 1500 }),
    step('Add your own', 'To add a plumber, an inspector or a supplier, tap Add contact.', { ring: 'text:+ Add contact', ms: 1800 }),
  ]),

  S('staff-calendar', 'The agency calendar', '#staff-calendar', [
    step('One calendar', 'The calendar shows everything happening at your centres: closures, holds, trips, conferences, and who\'s away.', DOCK, { wait: 800 }),
    step('Day, week, month', 'Switch between day, week and month.', { tap: 'text:month', settle: 1600 }, { tap: 'text:week', settle: 1500 }),
    step('Move around', 'Use the arrows to move, and Today to jump back.', { tap: 'text:▶', settle: 1300 }, { tap: 'text:Today', settle: 1300 }),
    step('Add', 'Add a closure, a vacation hold, a trip or a conference from the Add menu.', { ring: 'text:+ Add ▾', ms: 1800 }),
  ]),

  S('time-clock', 'Staff time clock', '#time-clock', [
    step('Punch in and out', 'Each staff member punches in at the start of their shift and out at the end.', DOCK, { wait: 700 }),
    step('The button', 'The big button reads Clock in when you\'re off shift, and Clock out when you\'re on.', { ring: 'text:Clock out', ms: 1800 }),
    step('Recent punches', 'Below it are your recent punches, with the hours for each.', { ring: 'text:RECENT PUNCHES', ms: 1600 }),
    step('For managers', 'You see everyone\'s hours in Timesheets, where a wrong punch can be corrected.', { go: '#timesheets', settle: 2800 }, { ring: 'text:⋮', ms: 1500 }),
  ]),

  S('timesheets', 'Timesheets and correcting hours', '#timesheets', [
    step('Timesheets', 'Timesheets shows every shift worked in a date range, per person.', DOCK, { wait: 700 }),
    step('Choose what you see', 'Pick a centre, and the from and to dates.', { ring: 'text:All centres', ms: 1300 }, { ring: 'input[type=date]', ms: 1300 }),
    step('Correct a shift', 'Each row\'s menu lets you correct the hours, remove a shift, or add one that was never clocked.', { tap: 'text:⋮', settle: 1400 }, { ring: 'text:Edit hours', ms: 1400 }, { ring: 'text:+ Add a shift', ms: 1400 }, { key: 'Escape' }),
    step('Download', 'The download button gives you a CSV for your payroll provider. Payroll reads these same hours.', { ring: 'text:⬇️', ms: 1600 }),
  ]),

  S('tour-bookings', 'Tour bookings', '#tours', [
    step('Tours', 'Families can book a centre tour from your public link.', DOCK, { wait: 700 }),
    step('Your link', 'This is your booking link. Copy it into your website, an ad, or an email.', { ring: 'input[readonly], input[value^="https://"]', ms: 1800 }, { ring: 'text:📋', ms: 1200 }),
    step('Requests', 'Requests arrive here. Filter by where they are: requested, confirmed, completed, or no-show.', { tap: 'text:Requested', settle: 1400 }, { tap: 'text:Confirmed', settle: 1400 }, { tap: 'text:All', settle: 1300 }),
    step('On the calendar', 'Confirmed tours also appear on your calendar, so everyone knows who\'s coming.', { wait: 700 }),
  ]),

  S('field-trip-gps', 'Walks and outings', '#trip-gps', [
    step('Walks and outings', 'Walks and outings shows each trip out of the centre, day by day.', DOCK, { wait: 700 }),
    step('Pick a day', 'Move between days with the arrows, or jump back to today.', { tap: 'text:‹ Previous', settle: 1500 }, { tap: 'text:Today', settle: 1500 }),
    step('Live location', 'On the day, the lead educator starts location sharing on their phone, and approved families can follow the trip.', { wait: 800 }),
    step('Planning trips', 'Trips themselves are planned under Field trips.', { go: '#field-trips', settle: 2600 }, { ring: 'text:+ Plan trip', ms: 1600 }),
  ]),

  S('attendance-pattern', 'Attendance patterns', '#attendance-pattern', [
    step('Who comes when', 'The attendance pattern says which days each child attends. Today\'s roster and Safe Arrival both use it.', DOCK, { wait: 800 }),
    step('Find a child', 'Search for a child, or show only those missing a pattern.', { ring: 'input[placeholder="Name, centre or room"]', ms: 1400 }, { choose: ['select', 'Missing a pattern'], settle: 1600 }, { choose: ['select', 'Enrolled only'], settle: 1400 }),
    step('Quick patterns', 'Use a quick pattern, like Monday to Friday full day, or set each day.', { ring: 'text:Mon–Fri, full day', ms: 1600 }),
    step('When it starts', 'Choose when the change applies from, then Save changes.', { ring: 'text:Changes apply from', ms: 1400 }, { ring: 'text:Save changes', ms: 1600 }),
  ]),

  S('room-assignments', 'Assigning educators to rooms', '#educator-rooms', [
    step('Room assignments', 'Room assignments decide which rooms a person works in, and so whose children they see.', DOCK, { wait: 800 }),
    step('Their rooms', 'Choose the person, and their current rooms are shown straight away.', { ring: '#appMain select', ms: 1400 }, { ring: 'text:ASSIGNED ROOMS', ms: 1500 }),
    step('Edit', 'The screen is read-only until you tap Edit assignments, then you tick the rooms they work in.', { tap: 'text:✏️ Edit assignments', settle: 1600 }),
    step('Save or cancel', 'Save to keep it, or Cancel to put it back exactly as it was.', { ring: 'text:Save', ms: 1400 }, { tap: 'text:Cancel', settle: 1400 }),
  ]),

  S('child-portfolios', 'Child portfolios', JACK, [
    step('A child\'s journey', 'A portfolio gathers every observation, photo and milestone for one child, in date order.', DOCK, { go: '#portfolio?id=78', settle: 2800 }),
    step('The timeline', 'The observation timeline lists each one, newest first, with the framework and who recorded it.', { ring: 'text:OBSERVATION TIMELINE', ms: 1800 }, { scroll: 420, ms: 1600 }),
    step('Milestones', 'Milestones are tracked alongside, for the same child.', { go: '#milestones?id=78', settle: 2600 }),
    step('When to use it', 'Use it for parent meetings, transitions, and year-end summaries.', { wait: 700 }),
  ]),

  S('developmental-milestones', 'Developmental milestones', '#milestones?id=78', [
    step('Milestones', 'The milestones tracker is a checklist of skills for each age group.', DOCK, { wait: 800 }),
    step('Age groups', 'It runs from infant through toddler and preschool to kindergarten.', { ring: 'text:Infant', ms: 1200 }, { ring: 'text:Toddler', ms: 1200 }),
    step('Three stages', 'Mark each skill as emerging, in progress, or achieved.', { ring: 'text:Emerging', ms: 1300 }, { ring: 'text:In Progress', ms: 1300 }, { ring: 'text:Achieved', ms: 1300 }),
    step('Families see it', 'Parents see the same checklist for their own child, and changes show up in their Today feed.', { wait: 700 }),
  ]),

  S('daily-care-logs', 'Daily care logs', '#care-log', [
    step('Daily log', 'The daily log is where the day is recorded: diapers, naps, meals, bottles and more.', DOCK, { wait: 800 }),
    step('Pick, then tap', 'Pick the child, then tap what you\'re logging.', { ring: 'text:😴 Nap', ms: 1300 }, { ring: 'text:🍽️ Meal', ms: 1300 }, { ring: 'text:🍼 Bottle', ms: 1300 }),
    step('Photos too', 'Photos and videos are logged the same way, and go straight to the family.', { ring: 'text:📸 Photo', ms: 1500 }),
    step('Today\'s log', 'Everything logged appears in Today\'s log below, and on the parent\'s app within seconds.', { ring: 'text:TODAY\'S LOG', ms: 1600 }),
  ]),

  S('incident-reports', 'Incident reports', '#incidents', [
    step('Incidents', 'Incident reports record injuries, illnesses and serious occurrences, and route them for review.', DOCK, { wait: 800 }),
    step('The workflow', 'Each report moves from draft, to awaiting review, to reviewed, to awaiting the parent, then acknowledged and closed.', { ring: '#appMain select', ms: 2000 }),
    step('A new report', 'Tap New report. Fill in when it happened, the type, and what happened, factually.', { tap: 'text:+ New report', settle: 1800 }, { choose: ['select[name=incident_type]', 'Injury'], settle: 700 }, { type: ['textarea[name=description]', 'Tripped on the mat in the playroom and bumped a knee. No mark.'], settle: 500 }),
    step('Action taken', 'Then the action you took, and any follow-up.', { type: ['textarea[name=action_taken]', 'Comforted, ice pack for five minutes.'], settle: 500 }),
    step('Submit', 'Save it as a draft, or Save and submit it for review.', { ring: 'text:Save as draft', ms: 1300 }, { ring: 'text:Save & submit', ms: 1500 }, { tap: 'text:Cancel', settle: 1200 }),
  ]),

  /* ── Children and enrolment ─────────────────────────────────── */
  S('child-record-tabs', 'The child record', '#children?search=Jack', [
    step('Open a child', 'Open any child from Children to see their full record.', DOCK, { tap: 'text:Jack Lopez', settle: 2600 }),
    step('The header', 'The header has the main actions: edit, emergency card, archive, and the compliance report.', { ring: 'text:🚨 Emergency card', ms: 1400 }, { ring: 'text:📋 Compliance report', ms: 1400 }),
    step('Tabs', 'The record is organised into tabs.', { tap: 'text:🏫 Enrollment', settle: 1800 }, { tap: 'text:💉 Immunization', settle: 1800 }, { tap: 'text:📎 Attachments', settle: 1800 }),
    step('Emergency card', 'Emergency card opens the child\'s card in a panel, ready to print.', { tap: 'text:🚨 Emergency card', settle: 2600 }, { ring: 'text:🖨 Print', ms: 1500 }),
    step('Back', 'Close it, and you\'re right back on the record.', { tap: 'text:✕', settle: 1500 }),
  ]),

  S('adding-a-new-child', 'Adding a new child', '#children', [
    step('Enrol a child', 'To add a child, open Children and tap Enroll a child.', DOCK, { tap: 'text:+ Enroll a child', settle: 1800 }),
    step('Their name', 'Enter their legal first and last name, and the name they go by.', { typeAt: ['input[type=text]', 0, 'Sam'] }, { typeAt: ['input[type=text]', 1, 'Demo'] }),
    step('Date of birth', 'Their date of birth sets their age and which rooms they fit.', { ring: 'input[type=date]', ms: 1500 }),
    step('Room and fee', 'Choose their room, the monthly fee, and when enrolment starts.', { choose: ['select', 'Discovery Preschool'], settle: 900 }, { ring: 'input[type=number]', ms: 1300 }),
    step('Enrol', 'Then tap Enroll. Here we\'ll cancel, since this is only a demonstration.', { ring: 'text:Enroll', ms: 1500 }, { tap: 'text:Cancel', settle: 1300 }),
  ]),

  S('inviting-parents-and-staff', 'Inviting parents and staff', '#families', [
    step('Invite a parent', 'A parent is invited from their family. Open the family\'s menu and choose Invite parent.', DOCK, { type: ['input.kt-table-filter-input', 'Lopez'], settle: 1500 }, { tap: 'text:⋮', settle: 1400 }, { ring: 'text:Invite parent', ms: 1800 }, { key: 'Escape' }),
    step('Their own password', 'The invitation email has a Set my password button. The parent chooses their own password; nobody else ever sees it.', { wait: 800 }),
    step('Invite staff', 'Staff are invited from Staff, with Invite staff.', { go: '#staff', settle: 2600 }, { ring: 'text:+ Invite staff', ms: 1800 }),
    step('The link lasts a week', 'The link works for seven days. If it expires, just send the invitation again.', { wait: 700 }),
  ]),

  S('re-enrollment', 'Re-enrolment campaigns', '#reenrollment', [
    step('Next term', 'Re-enrolment campaigns ask every family whether they\'re staying for the next term.', DOCK, { wait: 800 }),
    step('Launch one', 'Tap Launch campaign, name it, say which term it\'s for, and set the deadline for answers.', { ring: 'text:+ Launch campaign', ms: 2000 }),
    step('Families answer', 'Each family gets a notice and answers yes, no, or not sure.', { wait: 800 }),
    step('Track it', 'The answers collect here, so you can plan your rooms and your waitlist early.', { wait: 700 }),
  ]),

  /* ── Communications ──────────────────────────────────────────── */
  S('announcements', 'Announcements', '#announcements', [
    step('Announcements', 'Announcements post a notice to families, staff, or both.', DOCK, { wait: 700 }),
    step('New announcement', 'Tap New announcement.', { tap: 'text:+ New announcement', settle: 1900 }),
    step('Who', 'Choose where it goes, and who receives it: all parents, all educators, or everyone.', { choose: ['select[name=audience]', 'All parents'], settle: 800 }),
    step('Write it', 'Give it a title and write the message. You can add a picture too.', { type: ['input[name=title]', 'Pizza lunch on Friday'], settle: 600 }),
    step('How it goes out', 'Choose how it goes out: email, text, in-app, and for urgent news, a phone call. You can also schedule it for later.', { ring: 'text:📧 Send email', ms: 1200 }, { ring: 'text:🔔 In-app notification', ms: 1200 }),
    step('Send', 'Then tap Send. We\'ll cancel this one.', { ring: 'text:Send', ms: 1500 }, { tap: 'text:Cancel', settle: 1300 }),
  ]),

  S('sms-broadcast', 'SMS broadcast', '#sms', [
    step('Text everyone', 'SMS broadcast sends a text to a group of people at once.', DOCK, { wait: 700 }),
    step('Who', 'Choose the audience: by role, by centre, by room, or the whole agency.', { ring: 'text:Audience', ms: 1300 }, { choose: ['select', 'By role'], settle: 800 }),
    step('Write it', 'Write your message.', { type: ['textarea', 'Reminder: the centre closes at 3 pm on Friday for a staff training afternoon.'], settle: 600 }),
    step('Who gets it', 'Only people with a mobile number who\'ve agreed to texts receive it. Anyone else is skipped, and the result shows sent and skipped.', { ring: 'text:Send broadcast', ms: 2000 }),
    step('The history', 'Sent and received keeps a record of every text.', { ring: 'text:Sent & received', ms: 1500 }),
  ]),

  S('voice-announcements', 'Announcement calls', '#sms', [
    step('Calls for urgent news', 'For a closure, an evacuation or a lockdown, KiddieTrac can phone families and read a short message aloud.', DOCK, { wait: 800 }),
    step('Same screen', 'It\'s the same screen as text broadcasts. Switch Send by to Voice call.', { ring: 'text:📞 Voice call', ms: 2000 }),
    step('Reason and audience', 'Pick the reason and the audience, then write a message of up to 800 characters.', { ring: 'text:Audience', ms: 1500 }),
    step('Before you rely on it', 'Your agency admin has to turn calls on first. Place a test call to your own phone, so you know how it sounds.', { wait: 800 }),
  ]),

  S('ai-chatbot', 'Ask a question', '#dashboard', [
    step('Ask in plain English', 'Stuck on something? Tap the robot button at the top to ask a question in plain English.', DOCK, { tap: 'text:🤖', settle: 1600 }),
    step('Type it', 'Type your question the way you\'d ask a colleague.', { type: ['textarea', 'How do I correct a staff member\'s hours?'], settle: 600 }),
    step('The answer', 'Tap Ask. It reads every help guide for your role, and answers with the steps.', { ring: 'text:✨ Ask', ms: 1800 }),
    step('Close', 'Close it any time with the cross.', { tap: 'text:×', settle: 1200 }),
  ]),

  S('marketing-campaigns', 'Marketing campaigns', '#marketing-campaigns', [
    step('Campaigns', 'Campaigns send a designed message, like a newsletter or an open house invitation, to the families you choose.', DOCK, { wait: 700 }),
    step('New campaign', 'Tap New campaign, and give it a title and an email subject.', { tap: 'text:+ New campaign', settle: 1900 }, { type: ['input[placeholder^="Spring open house"]', 'Fall open house'], settle: 500 }),
    step('Who and how', 'Choose the audience, like everyone on the waitlist, and the channel: in the portal, email, or both.', { choose: ['select', 'Waitlist'], settle: 800 }),
    step('Design it', 'Add a hero image, and write the body with the editor.', { ring: 'text:＋ Upload a hero image', ms: 1600 }),
    step('Send or save', 'Preview it, save a draft, or send it now. You can also schedule it.', { ring: 'text:👁 Preview', ms: 1200 }, { ring: 'text:Save draft', ms: 1200 }, { tap: 'text:×', settle: 1200 }),
  ]),

  S('drip-campaigns', 'Drip campaigns', '#drip-campaigns', [
    step('Automatic emails', 'A drip campaign sends an email automatically, a set number of days after something happens.', DOCK, { wait: 700 }),
    step('New drip', 'Tap New drip campaign.', { tap: 'text:+ New drip campaign', settle: 1800 }, { type: ['#dp-title', 'Thanks for visiting'], settle: 500 }),
    step('The trigger', 'Choose the trigger: after a tour, after enrolment, on a birthday, or after 30 days without activity.', { choose: ['#dp-trig', 'After tour booked'], settle: 800 }),
    step('The email', 'Set the delay in days, then write the subject and the email.', { type: ['#dp-subj', 'Thanks for visiting Sunny Meadows'], settle: 500 }),
    step('Create', 'Tap Create, and it runs on its own from then on. We\'ll cancel this one.', { ring: 'text:Create', ms: 1500 }, { tap: 'text:Cancel', settle: 1200 }),
  ]),

  S('video-feed', 'Video feed', '#videos', [
    step('Video feed', 'The video feed shares short videos with families.', DOCK, { wait: 800 }),
    step('Upload', 'Tap Upload video, pick the file, add a caption, and tag the children in it.', { ring: 'text:+ Upload video', ms: 2000 }),
    step('Who sees it', 'Families see the videos their children are tagged in.', { wait: 800 }),
  ]),

  /* ── Compliance and reporting ───────────────────────────────── */
  S('background-checks', 'Background checks', '#background-checks', [
    step('Background checks', 'Background checks tracks every staff member\'s vulnerable sector check and when it expires.', DOCK, { wait: 700 }),
    step('Status', 'Each check is valid, expiring within 60 days, or expired, and you\'re warned before it lapses.', { scroll: 200, ms: 1500 }),
    step('Add one', 'Add a check with Record: the staff member, the type, the reference number, and the dates.', { ring: 'text:+ Record', ms: 1800 }),
    step('Edit or view', 'Each row\'s menu lets you view or edit it.', { tap: 'text:⋮', settle: 1400 }, { ring: 'text:Edit', ms: 1400 }, { key: 'Escape' }),
    step('Export', 'Export the list for an inspection.', { ring: 'text:📊', ms: 1500 }),
  ]),

  S('immunization-due-age', 'Immunization due-at-age schedule', '#immunizations', [
    step('Immunizations', 'Immunizations measures each child\'s recorded doses against your schedule.', DOCK, { wait: 800 }),
    step('The tabs', 'Due at age shows every child. Overdue only narrows it to those behind.', { tap: 'text:Overdue only', settle: 1600 }, { tap: 'text:Due at age', settle: 1500 }),
    step('Records received', 'Records received lists every record filed, and who sent it.', { tap: 'text:Records received', settle: 1700 }),
    step('The schedule', 'Schedule defaults is the schedule itself. The Canadian NACI schedule is filled in for you, and you can add to it.', { tap: 'text:Schedule defaults', settle: 1800 }),
  ]),

  S('filing-an-immunization-record', 'Filing an immunization record', JACK, [
    step('Two ways in', 'You can file a record from Immunizations, or from a child\'s own record.', DOCK, { wait: 700 }),
    step('The child\'s record', 'On the child\'s record, open the Immunization tab.', { tap: 'text:💉 Immunization', settle: 2000 }),
    step('What\'s due', 'What is due lists each dose on the schedule, and Records on file holds the cards already sent in.', { ring: 'text:💉 What is due', ms: 1400 }, { ring: 'text:Records on file', ms: 1400 }),
    step('Upload', 'To file a new card, use Upload a record. Attach the photo or PDF, tick each dose the card shows, and file it.', { go: '#immunizations', settle: 2600 }, { ring: 'text:↑ Upload a record', ms: 2000 }),
    step('No duplicates', 'A dose already on the record is skipped, not duplicated.', { wait: 700 }),
  ]),

  S('immunization-exemptions', 'Immunization exemptions', JACK, [
    step('Exemptions', 'When a child is exempt from a dose, record it on their immunizations.', DOCK, { tap: 'text:💉 Immunization', settle: 2000 }),
    step('Find the dose', 'Under What is due, find the dose. Anything not due yet is under Show the rest of the schedule.', { tap: 'text:Show the rest of the schedule', settle: 1500 }),
    step('Exempt', 'Open the dose\'s menu and choose Exempt, then pick the reason, and record it.', { ring: 'text:⋮', ms: 1800 }),
    step('It stays visible', 'The dose then reads Exempt, with the reason underneath, so the next person knows why.', { wait: 800 }),
  ]),

  S('immunization-report', 'Immunization report', '#reports', [
    step('Reports', 'Reports holds every report, branded and ready to print.', DOCK, { wait: 700 }),
    step('Choose the centre', 'Pick the centre and dates.', { ring: 'text:Centre', ms: 1300 }),
    step('Immunisations', 'Open the immunisations report: every child, what they\'ve had, and what\'s due.', { tap: 'text:Immunisations', settle: 2800 }),
    step('Reading it', 'Overdue means a dose is missing for their age. Due soon is within three months. Exempt shows the reason.', { scroll: 420, ms: 1800 }),
  ]),

  /* ── Staff management ────────────────────────────────────────── */
  S('time-off', 'Time-off requests', '#time-off', [
    step('Time off', 'Staff ask for time off here, and you approve or decline it.', DOCK, { wait: 700 }),
    step('Ask for time off', 'Tap Request time off, pick the type and the dates, and add a reason.', { tap: 'text:🌴 Request time off', settle: 1700 }, { choose: ['#tor-type', 'Vacation'], settle: 700 }, { type: ['#tor-reason', 'Family wedding'], settle: 500 }),
    step('Submit', 'Then Submit. We\'ll cancel this one.', { ring: 'text:Submit', ms: 1400 }, { tap: 'text:Cancel', settle: 1300 }),
    step('Your team\'s requests', 'Your team\'s requests wait under Awaiting decision. Decided ones move to Approved and declined.', { tap: 'text:Approved & declined', settle: 1600 }),
  ]),

  S('payroll-csv', 'Payroll export', '#payroll', [
    step('Payroll', 'Payroll turns your team\'s clocked hours into a report for your payroll provider.', DOCK, { wait: 700 }),
    step('The period', 'Pick the date range. It starts at the last 30 days.', { ring: 'input[type=date]', ms: 1500 }),
    step('Run it', 'Tap Run to see the summary for each person: punches and hours.', { tap: 'text:Run', settle: 2600 }),
    step('Correct first', 'If a shift looks wrong, correct it in Timesheets first. Payroll reads the same punches.', { wait: 800 }),
  ]),

  S('payroll-documents', 'Payroll runs, hours and payslips', '#payroll', [
    step('Three groups', 'Payroll splits your people into educators, other staff, and contractors.', DOCK, { ring: 'text:🧑‍🏫 Educators 13', ms: 1500 }),
    step('Other staff', 'Other staff are paid on different terms, so they\'re kept apart.', { tap: 'text:👥 Other staff', settle: 1700 }),
    step('Contractors', 'Contractors show what they were paid in the period, rather than hours.', { tap: 'text:📄 Contractors', settle: 1700 }),
    step('Payslips', 'Each row carries that person\'s payslips for the period.', { tap: 'text:🧑‍🏫 Educators', settle: 1700 }, { ring: 'text:PAYSLIPS', ms: 1500 }),
  ]),

  /* ── AI and programs ─────────────────────────────────────────── */
  S('ai-tools', 'AI tools', '#ai-churn', [
    step('Churn risk', 'Churn risk gives each family a score from 0 to 100, from attendance, engagement and overdue balances.', DOCK, { ring: 'text:All families by risk', ms: 1800 }),
    step('What to do', 'High means talk to them this week. Medium, keep an eye on it. Low, they\'re engaged.', { scroll: 300, ms: 1500 }),
    step('Document extraction', 'AI doc extract reads a document, like an immunization card, and pulls out the fields for you.', { go: '#ai-docs', settle: 2500 }),
    step('Extract', 'Upload the image or paste its link, then tap Extract fields. Check the result before you copy it into a record.', { ring: 'input[placeholder^="https://"]', ms: 1500 }, { ring: 'text:Extract fields', ms: 1600 }),
  ]),

  S('curriculum-library', 'Curriculum library', '#curriculum', [
    step('Ready-made activities', 'The curriculum library holds ready-made activities, with materials and learning domains.', DOCK, { wait: 700 }),
    step('Find one', 'Filter to find an activity.', { type: ['input[placeholder^="🔍"]', 'Tower'], settle: 1500 }),
    step('Use it', 'Use template copies it into a new lesson plan draft, ready to edit and publish.', { ring: 'text:Use template', ms: 1800 }),
    step('Your own', 'You can add your own with New template: give it a title, an age band, the materials and the steps, then save it.', { ring: 'text:+ New template', ms: 2200 }),
  ]),

  /* ── Family side (what the director's guide describes families doing) ── */
  P('daily-checkin', 'Daily check-in', '#checkin', [
    step('Before drop-off', 'Each morning, a parent can tell the room how their child is doing, right from their phone.', { wait: 700 }),
    step('Mood and sleep', 'They pick today\'s mood and how the child slept.', { tap: 'text:happy 😊', settle: 700 }, { tap: 'text:great 🌙', settle: 700 }),
    step('Breakfast', 'Whether they ate breakfast, any medication, and a note for staff.', { tap: 'text:Yes', settle: 600 }),
    step('Submit', 'Then Submit check-in. Educators see it on arrival.', { ring: 'text:Submit check-in', ms: 1800 }),
  ]),

  P('wellness-screening', 'Wellness pre-arrival screening', '#wellness', [
    step('Before they come in', 'The wellness check asks parents about symptoms before drop-off.', { wait: 700 }),
    step('Symptoms', 'They tick anything present today: fever, cough, a runny nose, and so on.', { ring: 'text:Fever 🌡', ms: 1300 }, { ring: 'text:Cough 😷', ms: 1300 }),
    step('The result', 'Fever, vomiting, diarrhea or a sick contact means stay home. A cough, runny nose or rash means staff review on arrival. Nothing ticked means cleared.', { wait: 900 }),
    step('Submit', 'Then they submit the screening.', { ring: 'text:Submit screening', ms: 1700 }),
  ]),

  P('pickup-authorizations', 'Pickup authorizations', '#pickup-auth', [
    step('Who can pick up', 'Parents list who else may pick up their child.', { wait: 700 }),
    step('Per child', 'Each child has their own list.', { ring: 'text:Arjun Singh', ms: 1400 }),
    step('Add someone', 'Tap Add authorised person, and enter their name, relationship and phone, with an optional expiry date.', { ring: 'text:+ Add authorised person', ms: 2000 }),
    step('At pickup', 'Staff check the list, and the photo ID, before releasing the child.', { wait: 700 }),
  ]),

  P('family-directory', 'Family directory', '#directory', [
    step('Families at your centre', 'The family directory helps families at the same centre find each other. It\'s opt-in.', { wait: 700 }),
    step('Their choice', 'Each family decides what to share, from their settings.', { ring: 'text:My privacy', ms: 1600 }),
    step('Other families', 'Families who opted in are listed here.', { ring: 'text:Other families at your centre', ms: 1700 }),
  ]),
];

/* Topics that cannot be shown correctly as the demo director today. Written to
   scripts/director-blocked/ (not recorded) when a script exists; reasons are in the report. */
const BLOCKED = {
  closures: 'New closure: Centre dropdown is empty (GET /admin/centres refused for a director)',
  conferences: 'Create slots: Centre dropdown is empty (same /admin/centres)',
  'assigning-tasks': '+ Assign task wipes the Tasks screen content for a director',
  'compliance-and-reporting': '#compliance: "Could not load: Forbidden. Required role: agency_admin"',
  'auto-sign-off': '#clock-settings: "Forbidden. Required role: agency_admin or platform_admin"',
  'de-enrolling-a-family': 'Family row menu only offers Invite parent; De-enrol is admin-only',
  cacfp: '#cacfp shows "No centres" for a director',
  'activity-zones': '#zones renders only its title',
  'bus-routes': '#bus-routes has no "+ New route" and an empty list',
  'year-end-report-cards': '#report-cards renders only its header (no child/term form)',
  'portfolio-pdf': 'no "Download PDF" control on #portfolio?id=78',
};
const blockedScripts = [
  S('closures', 'Closures and holidays', '#closures', [
    step('Closures', 'Closures lists the days your centre is closed. Families are told automatically, and billing can pause.', DOCK, { wait: 700 }),
    step('New closure', 'Tap New closure, and pick the centre, the type and the dates.', { tap: 'text:+ New closure', settle: 1700 }, { choose: ['#cl-type', 'PD day'], settle: 700 }),
    step('The reason', 'Add a reason families will understand.', { type: ['#cl-reason', 'Staff training day'], settle: 500 }),
    step('Billing', 'Leave Pause billing on if fees shouldn\'t run those days. Then Save. We\'ll cancel this one.', { ring: '#cl-bill', ms: 1500 }, { tap: 'text:Cancel', settle: 1200 }),
  ]),
  S('conferences', 'Parent-teacher conferences', '#conferences', [
    step('Conferences', 'Conferences lets families book a time to meet their child\'s educator.', DOCK, { wait: 700 }),
    step('Create slots', 'Tap Create slots, and pick the centre, the date, the start and end, and the length of each meeting.', { tap: 'text:+ Create slots', settle: 1700 }, { ring: '#cf-dur', ms: 1500 }),
    step('Generate', 'Generate slots makes one slot for each window. Families then book from their app. We\'ll cancel this one.', { ring: 'text:Generate slots', ms: 1500 }, { tap: 'text:Cancel', settle: 1200 }),
  ]),
];

let n = 0;
for (const s of scripts) {
  if (BLOCKED[s.slug]) continue;
  fs.writeFileSync(path.join(dir, s.slug + '.json'), JSON.stringify(s, null, 2)); n++;
}
for (const s of blockedScripts) fs.writeFileSync(path.join(blockedDir, s.slug + '.json'), JSON.stringify(s, null, 2));
console.log('wrote', n, 'director scripts;', blockedScripts.length, 'blocked scripts held in scripts/director-blocked');
