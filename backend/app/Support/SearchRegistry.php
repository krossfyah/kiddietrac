<?php

declare(strict_types=1);

namespace App\Support;

/**
 * GLOBAL SEARCH: THE ONE LIST OF WHAT IS SEARCHABLE (2026-11-02).
 *
 * Anthony: "make the search on the portal pull up invoices, receipts (global search) - keep this
 * current with any new changes as well".
 *
 * The search box (search-palette, the sidebar widget and the phone launcher, all through
 * GET /admin/search -> SearchController) found centres, rooms, families, children, staff and
 * parents, and nothing else. Invoices and payments were never added, for the same reason the
 * iLearn search lost the mailbox for a year: the sources were named one at a time inside the
 * controller, so a feature shipped afterwards stayed invisible until somebody remembered, and
 * nothing complained.
 *
 * So the sources live HERE, one entry each, and `php artisan kt:search-coverage` (nightly) fails
 * when a primary record type is neither searchable nor explicitly ruled out with a reason, or
 * when a table created after the baseline has not been classified at all.
 *
 * ADDING A SEARCHABLE TYPE = one entry in SOURCES + one SearchController::src<Key>() method that
 * applies the entry's scope. The front ends draw groups from the response's `groups` (label +
 * icon from here), so nothing needs adding there.
 *
 * Every source runs inside the ACTIVE agency (fail closed) and the caller's centres
 * (CentreScope: a director only their own). `billing` sources answer only for a role with
 * billing access in the active agency and only when the agency's plan has `feature`.
 */
final class SearchRegistry
{
    /** Roles that may see billing results (checked against AgencyRole::names, i.e. in the ACTIVE agency). */
    public const BILLING_ROLES = ['platform_admin', 'agency_admin', 'centre_director'];

    /**
     * key => meta. Array order is the default group order (ties after ranking).
     *   tables  the record tables this source makes searchable (coverage check)
     *   scope   the rule SearchController applies, in words
     *   link    where a result opens (hash; the screen loader runs on it)
     *   billing true = billing roles only;  feature = plan feature that must be on
     */
    public const SOURCES = [
        'centres' => [
            'label' => 'Centres', 'icon' => '🏫', 'limit' => 5, 'tables' => ['centres'],
            'billing' => false, 'feature' => null,
            'matches' => 'name, city',
            'scope' => 'live centres of the active agency; a director only their own (CentreScope)',
            'link' => '#admin-centres',
        ],
        'rooms' => [
            'label' => 'Rooms', 'icon' => '🚪', 'limit' => 5, 'tables' => ['rooms'],
            'billing' => false, 'feature' => null,
            'matches' => 'name',
            'scope' => 'rooms of the caller\'s live centres',
            'link' => '#admin-centres',
        ],
        'families' => [
            'label' => 'Families', 'icon' => '👪', 'limit' => 6, 'tables' => ['families'],
            'billing' => false, 'feature' => null,
            'matches' => 'family name, family ID (archived families by ID only)',
            'scope' => 'families.centre_id in the caller\'s live centres',
            'link' => '#admin-families?family={id} (directors: the first child\'s record)',
        ],
        'children' => [
            'label' => 'Children', 'icon' => '🧒', 'limit' => 8, 'tables' => ['children', 'enrollments'],
            'billing' => false, 'feature' => null,
            'matches' => 'first / last / preferred / full name, child ID (archived by ID only)',
            'scope' => 'child\'s family in the caller\'s live centres',
            'link' => '#child-detail?id={id}',
        ],
        'staff' => [
            'label' => 'Staff', 'icon' => '👤', 'limit' => 6, 'tables' => ['users', 'role_assignments'],
            'billing' => false, 'feature' => null,
            'matches' => 'name, email, staff ID',
            'scope' => 'active non-guardian role at the agency (admins) or the caller\'s centres',
            'link' => '#admin-users?user={id}',
        ],
        'play_spark_ideas' => [
            'label' => 'Play Spark ideas', 'icon' => '✨', 'limit' => 5, 'tables' => ['play_spark_ideas'],
            'billing' => false, 'feature' => null,   // the AI Bundle LICENCE is checked in srcPlaySparkIdeas (AiBundle::licensed: saved ideas need no AI call), fail closed
            'matches' => 'idea title',
            'scope' => 'active agency with the AI Bundle licence (AI may be switched off); the caller\'s own ideas, plus ideas shared to the agency library from a centre the caller sees (CentreScope via Visibility::centreIds; agency-wide ideas for agency admins only). Search is admins and directors only; never parents',
            'link' => '#play-spark?tab=library&lib=mine|shared&idea={id}',
        ],
        'parents' => [
            'label' => 'Parents', 'icon' => '👨‍👩‍👧', 'limit' => 6, 'tables' => ['users', 'guardians'],
            'billing' => false, 'feature' => null,
            'matches' => 'name, email, parent ID',
            'scope' => 'guardians of a family in the caller\'s live centres',
            'link' => '#admin-users?user={id}',
        ],
        'invoices' => [
            'label' => 'Invoices', 'icon' => '🧾', 'limit' => 8, 'tables' => ['invoices'],
            'billing' => true, 'feature' => 'billing',
            'matches' => 'invoice number, family / child / parent name, family/child/parent ID, amount (total or balance), status, month or date',
            'scope' => 'invoices.centre_id in the caller\'s live centres; billing roles only',
            'link' => '#external-billing?view={id} -> KT.openInvoiceById',
        ],
        'provider_invoices' => [
            'label' => 'Synced invoices', 'icon' => '📄', 'limit' => 6, 'tables' => ['external_invoices'],
            'billing' => true, 'feature' => 'accounting_sync',
            'matches' => 'invoice number, family / child / parent name, IDs, amount, status, month or date',
            'scope' => 'external_invoices.agency_id = active agency; a director only families of their centres (as Accounting)',
            'link' => '#external-billing?q={number}&family={family_id}',
        ],
        'payee_invoices' => [
            'label' => 'Payee invoices', 'icon' => '📑', 'limit' => 4, 'tables' => ['payee_invoices'],
            'billing' => true, 'feature' => 'accounting_sync',
            'matches' => 'reference, payee name, family, amount, status, month',
            'scope' => 'payee_invoices.agency_id = active agency; a director only those of a family at their centres (as Accounting)',
            'link' => '#external-billing?q={reference}',
        ],
        'payments' => [
            'label' => 'Payments & receipts', 'icon' => '💵', 'limit' => 6, 'tables' => ['payments', 'payment_refunds'],
            'billing' => true, 'feature' => 'billing',
            'matches' => 'payment reference (Interac / cheque / processor ref), invoice number it paid, family / child / parent name, IDs, amount, method, status, month or date',
            'scope' => 'payments.family_id in a family of the caller\'s live centres; billing roles only',
            'link' => 'the invoice it paid (#external-billing?view={invoice_id}), or Accounting filtered to it',
        ],
        'expense_bills' => [
            'label' => 'Supplier bills', 'icon' => '🧮', 'limit' => 4, 'tables' => ['expense_invoices'],
            'billing' => true, 'feature' => 'expenses',
            'matches' => 'bill number, reference, supplier name, amount, status, month',
            'scope' => 'expense_invoices.agency_id = active agency; a director only their own centres\' bills (CentreScope)',
            'link' => '#expenses',
        ],
    ];

    /**
     * The main record types people look up. Each must be covered by a SOURCE, ruled out in
     * NOT_SEARCHABLE, or listed in KNOWN_GAPS, or kt:search-coverage fails.
     */
    public const PRIMARY = [
        'centres' => 'Centres / providers', 'rooms' => 'Rooms', 'families' => 'Families',
        'children' => 'Children', 'users' => 'Staff and parents', 'guardians' => 'Parents / guardians',
        'invoices' => 'Invoices (KiddieTrac)', 'external_invoices' => 'Invoices (synced from a provider system)',
        'payee_invoices' => 'Payee invoices (educator / contractor / other)', 'payments' => 'Payments and receipts',
        'payment_refunds' => 'Refunds', 'expense_invoices' => 'Supplier bills (expenses)',
        'invoice_lines' => 'Invoice lines', 'payment_plans' => 'Payment plans', 'payment_plan_installments' => 'Instalments',
        'billing_schedules' => 'Billing schedules', 'tax_receipts' => 'Annual childcare receipts',
        'etransfer_requests' => 'e-Transfer requests', 'zum_transactions' => 'Zum transactions',
        'late_pickup_charges' => 'Late pick-up charges', 'platform_invoices' => 'KiddieTrac licence invoices',
        'donations' => 'Donations', 'donation_receipts' => 'Donation receipts', 'suppliers' => 'Suppliers',
        'purchase_orders' => 'Purchase orders', 'support_tickets' => 'Support tickets',
        'custom_form_responses' => 'Form responses', 'managed_forms' => 'Managed forms', 'signed_documents' => 'Signed documents',
        'documents' => 'Documents', 'sales_leads' => 'Sales leads', 'sales_prospects' => 'Sales prospects',
        'sales_quotes' => 'Sales quotes / proposals', 'incidents' => 'Incident reports', 'tasks' => 'Tasks',
        'announcements' => 'Announcements', 'tour_bookings' => 'Tour bookings', 'external_waitlist' => 'Waitlist',
        'staff_certifications' => 'Staff certifications', 'background_checks' => 'Background checks',
        'observations' => 'Observations', 'lesson_plans' => 'Lesson plans', 'medications' => 'Medications',
        'field_trips' => 'Field trips', 'report_cards' => 'Report cards', 'payroll_documents' => 'Payslips / payroll documents',
        'withdrawal_requests' => 'Withdrawal requests', 'career_applications' => 'Career applications',
        'enrollments' => 'Enrolments', 'role_assignments' => 'Staff role assignments', 'immunizations' => 'Immunization records',
        'conversations' => 'Parent conversations', 'messages' => 'Messages', 'staff_messages' => 'Team messages',
        'email_logs' => 'Email log', 'shifts' => 'Shifts', 'time_off_requests' => 'Time off requests',
    ];

    /** Deliberately NOT in the global search, with the reason. */
    public const NOT_SEARCHABLE = [
        'report_card_events' => 'a report card\'s history (drafted, submitted, approved, emailed...); shown under History & versions on the card, which is searchable',
        // KiddieTrac HR (2026-11-27)
        'hr_leave_types' => 'an agency\'s own leave types (HR > Leave types); configuration, found on that screen',
        'hr_leave_ledger' => 'leave balance history; shown on the person\'s HR profile and time-off requests: search the staff member',
        'hr_employee_files' => 'employee file fields; search the staff member, whose HR profile shows them',
        'hr_staff_sin' => 'encrypted SIN / SSN (HR); never searched, listed or exported, and every reveal is audited',
        // KiddieTrac HR phase 2 (2026-11-28): each lives on the person's HR profile / My HR; search the staff member
        'hr_job_posts' => 'an agency\'s job posts (HR > Hiring) and its public jobs page; few per agency, listed on that screen',
        'hr_onboardings' => 'a person\'s onboarding checklist; shown on their HR profile, HR > Onboarding and My HR: search the staff member',
        'hr_onboarding_tasks' => 'the tasks of an onboarding checklist, shown with it',
        'hr_reviews' => 'performance reviews: private to the employee, their reviewer, the agency admins and their centre\'s directors; never in a global box',
        'hr_goals' => 'performance goals, as private as reviews; shown on the review, the HR profile and My HR',
        'hr_one_on_ones' => 'one-to-one notes, private to the two people, the agency admins and their centre\'s directors; never in a global box',
        'hr_courses' => 'an agency\'s own courses (HR > Courses); configuration, found on that screen',
        'hr_course_assignments' => 'who has a course and when they finished it; shown on HR > Courses, the HR profile and My HR',
        'hr_injuries' => 'staff injury records (HR): health details sealed; never searched, listed with health, or exported; reveal is audited',
        'hr_injury_checkins' => 'return-to-work check-ins on a staff injury: sealed notes, shown inside the record only',
        'hr_claims' => 'staff expense / mileage claims (HR): private to the claimant, their centre\'s directors and the agency admins; shown in HR > Claims and My HR, receipts never public',
        'hr_availability' => 'a staff member\'s weekly work availability (HR): configuration read by the rota and autofill, shown in My HR only',
        'hr_unavailability' => 'dates a staff member can\'t work (HR): read by the rota and autofill, shown in My HR and as a conflict on HR > Rota',
        'hr_shift_swaps' => 'shift giveaways and swaps waiting for a colleague or a director (HR): shown in My HR and HR > Rota, never a record people look up',
        'hr_overtime_notices' => 'which overtime warnings already went out this week (HR): a send ledger, never shown or searched',
        'hr_overtime_requests' => 'overtime a staff member logged (HR): private to them, their centre\'s directors and the agency admins; shown in HR > Overtime and My HR, never in a global box',
        'hr_timesheet_locks' => 'which centre timesheets were approved and locked for a pay period (HR): shown in HR > Timesheets, never a record people look up',
        'hr_shift_changes' => 'a send queue of shift changes waiting to be told to the person (HR shift notices): never shown or searched',
        'hr_open_shifts' => 'open shifts staff can ask for (HR): shown in HR > Open shifts and My HR, never a record people look up',
        'hr_open_shift_claims' => 'who asked for an open shift (HR): shown with the open shift in HR > Open shifts and in My HR',
        // KiddieTrac HR Phase 4, batch 4 (2026-11-27): the document vault, pay + role history, leaving. Private: search the staff member.
        'hr_doc_folders' => 'company HR document folders: shown in HR > Documents and My HR, never a record people look up',
        'hr_documents' => 'HR documents and sick notes (private files): on the person\'s HR file and My HR; search the staff member',
        'hr_document_receipts' => 'who opened / accepted a company HR document: shown with the document in HR > Documents',
        'hr_document_alerts' => 'a log of document reminders already sent (HR): never shown or searched',
        'hr_pay_changes' => 'pay + role history with effective dates (pay = pay viewers only): on the person\'s HR file; search the staff member',
        'hr_leavings' => 'leaving records (checklist, ROE prep, payout): HR > Leavers and the person\'s HR file; search the staff member',
        'hr_letter_templates' => 'the agency\'s HR letter templates (configuration): edited in HR > Letters, never a record people look up',
        'hr_letters' => 'which HR letters were sent to whom (HR): the letter itself is the Managed Forms record; listed in HR > Letters only',
        'payroll_tax_profiles' => 'each employee\'s payroll tax setup (province of employment, pay frequency, CPP / EI / QPIP exemptions): agency admins only, in the HR profile; never searched',
        'payroll_tax_forms' => 'each employee\'s TD1 / TP-1015.3 claim amounts per tax year (the signed PDF is the Managed Forms record): the person and agency admins only; never searched',
        // KiddieTrac HR Phase 4, batch 6 (2026-11-27): quizzes, directory shares, recognition, approver grants.
        'hr_course_questions' => 'a course\'s quiz questions (configuration): edited in the course, never a record people look up',
        'hr_course_attempts' => 'quiz tries on a course (HR): shown with the course assignment; search the staff member',
        'hr_directory_shares' => 'what a staff member chose to show in the staff directory (a preference): never searched',
        // 📻 Walkie-talkie (2026-11-27, App\Support\Walkie): sealed audio + delivery bookkeeping. Played from the Walkie-talkie screen's history.
        'walkie_transmissions' => 'walkie-talkie talks (sealed audio, who/when/how long): Walkie-talkie > History; audio is never searched',
        'walkie_chunks' => 'walkie-talkie audio while it is being spoken (sealed, deleted when the talk ends): never searched',
        'walkie_recipients' => 'who a walkie-talkie talk went to and whether they heard it: shown in the talk\'s history row; never searched',
        'walkie_floor' => 'which channel someone is talking on right now (machine state): never searched',
        'walkie_prefs' => 'a person\'s walkie-talkie Do not disturb (a preference): never searched',
        'walkie_devices' => 'phones listening for the walkie-talkie (hashed device secret): never searched',
        'hr_kudos' => 'peer recognition messages (HR): shown on the team feed under Educator awards > Recognition and My HR; never searched',
        'hr_approver_grants' => 'who else approves time off for named people, and cover while a director is away (HR): HR settings > Approvers; never searched',
        // KiddieTrac HR Phase 4, batch 7 (2026-11-27): health & safety. Centre records, found on their own screens.
        'hr_risk_assessments' => 'centre risk assessments (HR): Human Resources > Health & safety > Risk assessments; few per centre, never a record people look up',
        'hr_chemicals' => 'the WHMIS chemical inventory per centre (HR): Health & safety > Chemicals and My HR; the safety data sheets are private files',
        'hr_safety_duties' => 'named health & safety duties (first aider, fire warden) (HR): Health & safety > H&S duties and My HR; search the staff member',
        // KiddieTrac HR Phase 4, batch 8 (2026-11-27): rota drafts, presets, budgets, acceptance, clock-in fixes.
        'hr_shift_drafts' => 'rota changes not published yet (HR): shown only on the director\'s Schedule grid and Rota plan, marked Draft; never searched',
        'hr_shift_presets' => 'named shift presets with a colour (configuration): Rota & shifts > Shift tools, never a record people look up',
        'hr_labour_budgets' => 'a centre\'s weekly labour budget (configuration, pay viewers only): Rota & shifts > Shift tools; never searched',
        'hr_shift_acceptances' => 'whether staff accepted a published shift (HR): shown on the Schedule grid and My HR > Shifts to accept; never searched',
        // KiddieTrac HR Phase 4, batch 9 (2026-11-27): closures taken from leave, booked leave to re-check.
        'hr_shutdowns' => 'whether a centre closure is taken from staff leave (HR): Time off > Closures & checks; a few per closure, never searched',
        'hr_shutdown_lines' => 'per person days and amount of a closure taken from leave (HR): shown in the closure popup and the balance history; never searched',
        'hr_leave_rechecks' => 'booked time off whose amount may be wrong after a closure change (HR): Time off > Closures & checks and the Requests list; never searched',
        'hr_punch_corrections' => 'staff requests to fix a clock-in (HR): Clock-ins & timesheets > Clock-in fixes and My HR; search the staff member',
        'hr_course_role_rules' => 'configuration: "this course goes to everyone in this role" on a course, shown in its Assigned popup',
        'quick_notes' => 'quick notes: private ones are only ever their owner\'s, shared ones are found with the Notes screen\'s own search and on the record they are linked to; never in the global box',
        'attendance_reply_links' => 'hashes of the one-day Mark absent / Running late links in arrival emails (AttendanceReply); secrets, never searched or shown as a record',
        'calendar_links' => 'encrypted Outlook/Google calendar permissions per person or agency (Settings > Calendar); secrets, never searched or shown as a record',
        'invoice_lines' => 'part of an invoice; the invoice is searchable and shows its lines',
        'payment_refunds' => 'shown on the payment it reverses (status Refunded) and on the invoice sheet',
        'payment_plans' => 'a plan; each instalment is issued as an invoice, which is searchable',
        'payment_plan_installments' => 'issued as invoices, which are searchable',
        'billing_schedules' => 'a schedule; the invoices it raises are searchable',
        'tax_receipts' => 'annual receipts generated per family and year from the payments (which are searchable); opened from the family\'s billing',
        'etransfer_requests' => 'a request against an invoice; the invoice is searchable and shows it',
        'zum_transactions' => 'processor plumbing; settled money is written as a payment, searchable by its reference',
        'late_pickup_charges' => 'billed as invoice lines; the invoice is searchable',
        'platform_invoices' => 'KiddieTrac\'s own licence billing of agencies (Platform > Sales invoices); tenant search never crosses agencies',
        'enrollments' => 'part of the child\'s record; search the child',
        'role_assignments' => 'how staff are found (Staff group), not a record of its own',
        'immunizations' => 'part of the child\'s health record; search the child',
        'conversations' => 'private conversations; searched inside Messages, never surfaced in a global box',
        'messages' => 'private conversations; searched inside Messages, never surfaced in a global box',
        'staff_messages' => 'private team messages; searched inside Team chat',
        'email_logs' => 'a log with its own search and filters (Email log)',
        'shifts' => 'schedule entries; search the staff member, then their schedule',
        'time_off_requests' => 'schedule entries; search the staff member',
        // 2026-11-05 (ticket #221: the nightly check failed on these)
        'time_punch_breaks' => 'breaks inside a shift (StaffBreaks); shown on the staff member time punch and timesheet - search the staff member',
        'chat_calls' => 'calls inside a private conversation; shown in that conversation, never surfaced in a global box',
        'report_card_requests' => 'a family\'s request for a child\'s report card, worked on the Report cards screen; report cards themselves are a known gap',
        'sales_campaign_templates' => 'a team\'s handful of email templates, picked from the template list on Sales > Campaigns',
        // 2026-11-06 (privacy breach register)
        'privacy_breaches' => 'the confidential privacy breach register (PIPEDA s. 10.3), opened from Privacy breaches by agency admins only; never surfaced in a global box',
        // 2026-11-10 (privacy requests)
        'privacy_acknowledgments' => 'a record that a parent read the privacy notice; counted on Data retention, never surfaced in a global box',
        // 2026-11-10 (ticket #244: tables added 2026-11-09/10 without a classification)
        'drill_maps' => 'each centre or educator map kept with the Drill log, opened from Drill log; never a record people look up from the global box',
        // 2026-11-28 (Fire plan tab of the Drill log)
        'fire_plans' => 'each centre or room\'s drawn fire plan and decision tree, versioned, opened from Drill log > Fire plan; never a record people look up from the global box',
        'drill_staff_dates' => 'each educator\'s fire drill renewal and expiry dates and schedule, shown on the Drill log Educators tab; never surfaced in a global box',
        'pickup_alarms' => 'a parent\'s own pickup countdown and alarm for their child, private to that parent; never surfaced in a global box',
        'privacy_request_events' => 'the timeline of a privacy request, shown inside that request on Privacy requests; never surfaced in a global box',
        'sales_customers' => 'KiddieTrac\'s own customers, listed and searched on Sales > Customers (platform/sales roles only)',
        'sign_in_lock_revoked_tokens' => 'hashes of sessions a sign-in lock ended, so an open screen is told why; internal, never searched',
        'sign_in_lock_exceptions' => 'one person let in past a centre sign-in lock, shown on that lock and on their page; never surfaced in a global box',
        'sign_in_locks' => 'a pause on sign-in for a person, a centre or an agency, worked on Locked sign-ins by agency admins only; never surfaced in a global box',
        'privacy_requests' => 'a person\'s request to see, correct or delete their information (PIPEDA s. 8), worked on Privacy requests by agency admins only; never surfaced in a global box',
        // 2026-11-25 (ticket #267)
        'temp_pickups' => 'a temporary pickup person (name, phone, photo, pass code) for one family, shown on that child\'s record and emergency card and checked at sign-out; never surfaced in a global box',
        // 2026-11-26: referral credits (ReferralCredits)
        'referral_credits' => 'a one-time credit off an invoice of a KiddieTrac customer for referring a lead, listed on Platform invoices > Referral credits and on the Sales customer record (platform admins only); search the customer',
        'phone_reports' => 'a parent\'s phone message about an absence or late arrival (recording and transcript), approved in Safe Arrival by directors and admins only; never surfaced in a global box',
    ];

    /** Primary types that SHOULD become searchable. Reported every night so they are not forgotten. */
    public const KNOWN_GAPS = [
        'support_tickets' => 'Support tickets: by ticket number / subject',
        'sales_leads' => 'Sales leads (platform/sales roles only)',
        'sales_prospects' => 'Sales prospects (platform/sales roles only)',
        'sales_quotes' => 'Sales quotes / proposals (platform/sales roles only)',
        'custom_form_responses' => 'Form responses: by form name / submitter',
        'managed_forms' => 'Managed forms: by form name',
        'signed_documents' => 'Signed documents: by document / signer',
        'documents' => 'Documents: by file name',
        'incidents' => 'Incident reports: by child / date (person-scoped like the Incidents screen)',
        'tasks' => 'Tasks: by title',
        'announcements' => 'Announcements: by title',
        'tour_bookings' => 'Tour bookings: by family name',
        'external_waitlist' => 'Waitlist entries: by child / parent name',
        'staff_certifications' => 'Staff certifications: by staff / certificate',
        'background_checks' => 'Background checks: by staff',
        'observations' => 'Observations: by child',
        'lesson_plans' => 'Lesson plans: by title',
        'medications' => 'Medications: by child / medication',
        'field_trips' => 'Field trips: by name / date',
        'report_cards' => 'Report cards: by child',
        'payroll_documents' => 'Payslips: admins only, by staff / period',
        'withdrawal_requests' => 'Withdrawal requests: by child / family',
        'career_applications' => 'Career applications: KiddieTrac\'s (Platform > Careers) and agencies\' applicants (HR > Hiring), by name / role',
        'suppliers' => 'Suppliers: by name (their bills are already searchable by supplier name)',
        'purchase_orders' => 'Purchase orders: by PO number',
        'donations' => 'Donations: by donor',
        'donation_receipts' => 'Donation receipts: by receipt number',
    ];

    /**
     * Tables created AFTER the baseline (resources/kt/search-table-baseline.txt) that are not
     * records a person looks up: logs, joins, settings, queues. A new table must be in a SOURCE,
     * NOT_SEARCHABLE, KNOWN_GAPS or here, or the check fails. Add a reason each.
     */
    public const PLUMBING = [
        'announcement_recipients' => 'who a marketing campaign\'s News post is for (App\\Support\\CampaignNews); the post itself is the record',
        'marketing_campaign_sends' => 'one row per person a campaign email went to, shown on Marketing > Campaigns > Recipients & stats; search the campaign',
        'hr_leave_rules' => 'entitlement rules of a leave type (KiddieTrac HR), edited and shown with that type',
        'hr_onboarding_templates' => 'onboarding checklist templates (KiddieTrac HR), edited on HR > Onboarding',
        'hr_onboarding_template_tasks' => 'the tasks of an onboarding checklist template, edited with it',
        'hr_review_templates' => 'review templates (sections + rating scale), edited on HR > Performance',
        'hr_goal_checkins' => 'the check-ins of a goal, shown with the goal',
        'sign_in_lock_people' => 'who a sign-in lock held when it was made, for its unlock report (App\\Support\\SignInLocks)',
        // 'example_log' => 'telemetry',
        // 2026-11-10 (ticket #244)
        'ai_content_log' => 'what each AI writer said recently, so it never repeats itself (AiFresh); read only by the writers, never a record',
        'drill_schedule_sends' => 'drill reminder send log: one per person + occurrence + kind, so a reminder never goes twice',
        'search_keywords' => 'the global search\'s own vocabulary of screen words (App\\Support\\SearchKeywords); it powers search, it is not a record',
        'search_keyword_screens' => 'when each role\'s screen last reported its words, for retiring stale ones (App\\Support\\SearchKeywords)',
        'search_keyword_days' => 'daily counts of search vocabulary use, per role; counts only',
        'calendar_feeds' => 'one private calendar link per person (salt + token hash, chosen options); never searched or shown as a record',
        'email_mail_alerts' => 'mail watcher log: which new messages already rang the bell (no duplicates)',
        'email_account_alerts' => 'each person alert switch and tone for a shared mailbox, set in the mailbox settings; never a record people look up',
        'child_media_consents' => 'each parent photo permission answer per child (MediaConsent); shown on Photo permissions and the parent own screen, never a record people look up',
        'user_signatures' => 'a person own saved signature and initials (sealed); private to them, shown only in their My settings, never searchable',
        'user_signature_uses' => 'evidence log of each time a saved signature was applied to a document; never a record people look up',
        'privacy_breach_events' => 'the timeline inside one privacy breach record (changes and steps taken); shown on that record only',
        'external_payment_receipts' => 'ledger of iLearn payment receipts already sent, so each is sent once',
        'lesson_images' => 'shared picture library cache for lesson plans, one row per search term',
        'menu_templates' => 'an agency\'s saved menu weeks, listed and applied on the Weekly menu screen; not a record people look up',
        // 2026-11-04 (ticket #215: the nightly check failed on these four)
        'cert_alert_log' => 'certificate-expiry alert ledger: which reminder step already went to whom (no repeats)',
        'headcount_strikes' => 'missed-headcount ledger behind the escalation alerts; shown on the room, not looked up',
        'content_reports' => 'private moderation queue (reported messages), reviewed in Audit log > Chat conversations > Reported messages; never in a global box',
        'user_blocks' => 'who has blocked whom in messages - private to the person who blocked; never searched or shown as a record',
        // 2026-11-05 (ticket #221)
        'report_card_reminders' => 'report-card reminder ledger: which reminder already went to whom (no repeats)',
        'sales_calendar_meetings' => 'sales calendar sync ledger: which meeting already became a lead activity and reminder; the meeting shows on the lead',
        'sales_lead_filed' => 'lead history ledger: which text or email was already filed onto a lead (no duplicates); the entry shows on the lead',
        // 2026-11-08 (ticket #239)
        'announcement_user_state' => 'each person read / archived / deleted state for News items; the announcement itself is the record',
        'email_person_links' => 'which mailbox email is linked to which person (PersonMail; subject and preview sealed); shown on the person or family record Emails tab, never in a global box',
        'safe_arrival_late' => 'Safe Arrival running-late notices (SafeArrival::logLate); shown on the room and the child that day, not a record people look up',
        // 2026-11-25 (ticket #267)
        'temp_pickup_uses' => 'each time a temporary pickup pass was used at sign-out (TempPickups); shown on that pass, never a record people look up',
        'play_spark_materials' => 'the materials a room has on hand, offered as choices on Play Spark; a list, not a record',
        'play_spark_runs' => 'Play Spark "We did it" ledger: which children did an idea and the day entries it wrote; the entries show on each child\'s day',
        'play_spark_pictures' => 'Play Spark end-result drawings (PlaySparkPicture), shown with their idea or lesson activity; never a record people look up',
        'calendar_event_links' => 'which Outlook/Google event a KiddieTrac meeting was written to, so it can be updated or cancelled; the meeting is the record',
        'quick_note_revisions' => 'who changed a quick note and which fields (QuickNotesController::history); shown as the note\'s History',
        'quick_note_pictures' => 'pictures on a quick note (App\\Support\\QuickNotePictures); shown only inside their note, to whoever may see it; never searched',
        'reminder_states' => 'which reminder toasts a person dismissed or snoozed (App\Support\Reminders); their own interface state, never a record people look up',
        'sms_attendance_prompts' => 'today\'s "Reply 1 / 2" arrival texts awaiting an answer (AttendanceReply); expire at the agency\'s midnight, never a record people look up',
    ];

    public const BASELINE_FILE = 'resources/kt/search-table-baseline.txt';

    /** Studly method suffix for a source key: provider_invoices -> ProviderInvoices. */
    public static function method(string $key): string
    {
        return 'src' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
    }

    /** @return array<string, string[]> table => source keys that cover it */
    public static function coveredTables(): array
    {
        $out = [];
        foreach (self::SOURCES as $key => $meta) {
            foreach ($meta['tables'] as $t) {
                $out[$t][] = $key;
            }
        }
        return $out;
    }

    /** The response's group list: key, label, icon. */
    public static function groupMeta(string $key): array
    {
        $m = self::SOURCES[$key];
        return ['key' => $key, 'label' => $m['label'], 'icon' => $m['icon']];
    }
}
