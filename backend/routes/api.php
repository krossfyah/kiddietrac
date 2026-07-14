<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AgencyController;
use App\Http\Controllers\Api\CentreController;
use App\Http\Controllers\Api\CheckEventController;
use App\Http\Controllers\Api\ChildController;
use App\Http\Controllers\Api\DailyEventController;
use App\Http\Controllers\Api\DigestStatusController;
use App\Http\Controllers\Api\FamilyController;
use App\Http\Controllers\Api\HelpController;
use App\Http\Controllers\Api\IncidentController;
use App\Http\Controllers\Api\AiObservationController;
use App\Http\Controllers\Api\AiLessonPlanController;
use App\Http\Controllers\Api\MedicationController;
use App\Http\Controllers\Api\ImmunizationController;
use App\Http\Controllers\Api\ChildHealthController;
use App\Http\Controllers\Api\EDocumentController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\RoomManagementController;
use App\Http\Controllers\Api\SignupController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\LessonPlanController;
use App\Http\Controllers\Api\SchedulingController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\NotificationUnreadController;
use App\Http\Controllers\Api\FeatureFlagController;
use App\Http\Controllers\Api\MrrDashboardController;
use App\Http\Controllers\Api\InvoicePreviewController;
use App\Http\Controllers\Api\WaitlistController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AutopayController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\AgencyManagementController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\StripeBillingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
        'version' => '7.0.0',
        'laravel' => app()->version(),
        'php' => PHP_VERSION,
    ]));

    // v22p84: throttle login to blunt brute-force / credential-stuffing
    // (10 attempts/min per IP+route). Legitimate sign-ins never approach this.
    // Public crash-report sink — the Android app POSTs its last captured native
    // crash here on next launch so we can review it server-side (storage/app/crash-reports.log).
    Route::post('/diag/crash', function (\Illuminate\Http\Request $r) {
        $line = json_encode([
            'at' => now()->toDateTimeString(),
            'device' => mb_substr((string) $r->input('device'), 0, 200),
            'os' => mb_substr((string) $r->input('os'), 0, 80),
            'app' => mb_substr((string) $r->input('app'), 0, 80),
            'trace' => mb_substr((string) $r->input('trace'), 0, 8000),
            'ip' => $r->ip(),
        ]);
        try { \Illuminate\Support\Facades\Storage::append('crash-reports.log', $line); } catch (\Throwable $e) {}
        // Email the crash to the team so it lands in the inbox, not just a log.
        try {
            $body = '<div style="font-family:-apple-system,Segoe UI,Roboto,sans-serif;">'
                . '<h2 style="color:#B91C1C;">KiddieTrac crash report</h2>'
                . '<p><b>When:</b> ' . e($data['at']) . '<br><b>Device:</b> ' . e($data['device'])
                . '<br><b>OS:</b> ' . e($data['os']) . '<br><b>App:</b> ' . e($data['app']) . '<br><b>IP:</b> ' . e($data['ip']) . '</p>'
                . '<pre style="white-space:pre-wrap;word-break:break-word;background:#F1F5F9;padding:12px;border-radius:8px;font-size:12px;">'
                . e($data['trace']) . '</pre></div>';
            \App\Services\AgencyMailer::forAgency(null)->mailer()->html($body, function ($m) {
                $m->to('info@kiddietrac.com')->subject('⚠️ KiddieTrac crash report');
            });
        } catch (\Throwable $e) {}
        return response()->json(['ok' => true]);
    })->middleware('throttle:30,1');

    // Device-side scroll diagnostics (log only, no email) — lets us SEE the phone's
    // real scroll timeline on a Home navigation, which the desktop browser can't reproduce.
    Route::post('/diag/scroll', function (\Illuminate\Http\Request $r) {
        try { \Illuminate\Support\Facades\Storage::append('scroll-diag.log', json_encode([
            'at' => now()->toDateTimeString(), 'ua' => mb_substr((string) $r->userAgent(), 0, 120),
            'data' => mb_substr((string) $r->input('data'), 0, 4000),
        ])); } catch (\Throwable $e) {}
        return response()->json(['ok' => true]);
    })->middleware('throttle:60,1');

    Route::post('/diag/bio', function (\Illuminate\Http\Request $r) {
        try { \Illuminate\Support\Facades\Storage::append('bio-diag.log', json_encode([
            'at' => now()->toDateTimeString(), 'ua' => mb_substr((string) $r->userAgent(), 0, 120),
            'data' => mb_substr((string) $r->input('data'), 0, 2000),
        ])); } catch (\Throwable $e) {}
        return response()->json(['ok' => true]);
    })->middleware('throttle:120,1');

    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/auth/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
    Route::post('/auth/reset', [AuthController::class, 'resetPassword']);
    Route::post('/auth/set-password', [AuthController::class, 'setPasswordFromToken'])->middleware('throttle:10,1');
    Route::get('/auth/social/providers', [\App\Http\Controllers\Api\SocialAuthController::class, 'providers']);
    Route::get('/auth/social/{provider}/redirect', [\App\Http\Controllers\Api\SocialAuthController::class, 'redirect'])->where('provider', '[a-z]+')->middleware('throttle:20,1');
    Route::get('/auth/social/{provider}/callback', [\App\Http\Controllers\Api\SocialAuthController::class, 'callback'])->where('provider', '[a-z]+')->middleware('throttle:20,1');
    // v22p93: email open-tracking pixel (public, no auth)
    Route::get('/e/o/{token}', [\App\Http\Controllers\Api\PlatformController::class, 'trackOpen']);

    // ─── Public signup (rate-limited, v7) ───
    Route::post('/signup/centre', [SignupController::class, 'signup'])->middleware('throttle:3,60');

    // v22p2.1: public invite-code parent signup
    Route::get('/signup/invitation/{code}', [InvitationController::class, 'probe'])->middleware('throttle:30,1');
    Route::post('/signup/by-code', [SignupController::class, 'byCode'])->middleware('throttle:5,60');


Route::any('/login', function () { return response()->json(['message' => 'Unauthenticated. Please sign in via the app.'], 401); })->name('login');
Route::get('/branding', [BrandingController::class, 'show']);
Route::get('/marketing-site/config', [\App\Http\Controllers\Api\MarketingSiteController::class, 'publicConfig']);
Route::post('/marketing-site/lead', [\App\Http\Controllers\Api\MarketingSiteController::class, 'submitLead']);
Route::post('/marketing-site/hit', [\App\Http\Controllers\Api\MarketingSiteController::class, 'recordHit']);
Route::post('/marketing-site/chat', [\App\Http\Controllers\Api\MarketingSiteController::class, 'logChat']);
Route::get('/marketing-site/unsubscribe', [\App\Http\Controllers\Api\MarketingSiteController::class, 'unsubscribe']);
Route::post('/stripe/webhook', [StripeBillingController::class, 'webhook']);

// v22p49 — Public tour booking. Throttled aggressively because the public
// endpoint is the most-likely target for spam — 8 requests per hour per IP.
Route::post('/public/tours', [\App\Http\Controllers\Api\CareController::class, 'publicTourBook'])
    ->middleware('throttle:8,60');

    

    // v14 fix: push/public-key must be publicly accessible (called before subscription)
    Route::get('/push/public-key', [PushController::class, 'publicKey']);


    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/agency/centre-term', [\App\Http\Controllers\Api\AgencyTermController::class, 'show']);

    // v22p7: MFA (TOTP) enrolment endpoints — Phase A
    Route::prefix('auth/mfa')->group(function () {
        Route::get('/status', [\App\Http\Controllers\Api\MfaController::class, 'status']);
        Route::post('/setup', [\App\Http\Controllers\Api\MfaController::class, 'setup']);
        Route::post('/confirm', [\App\Http\Controllers\Api\MfaController::class, 'confirm']);
        Route::post('/disable', [\App\Http\Controllers\Api\MfaController::class, 'disable']);
    });


        Route::post('/auth/logout', [AuthController::class, 'logout']);
        // v22p20: multi-agency admin switcher
        Route::get('/auth/agencies', [AuthController::class, 'myAgencies']);
        Route::post('/auth/active-agency', [AuthController::class, 'setActiveAgency']);

        // v22p22: platform_admin cross-agency routes
        Route::prefix('platform')->middleware('role:platform_admin')->group(function () {
            Route::get('/overview', [\App\Http\Controllers\Api\PlatformController::class, 'overview']);
            Route::get('/social-config', [\App\Http\Controllers\Api\SocialAuthController::class, 'configGet']);
            Route::post('/social-config', [\App\Http\Controllers\Api\SocialAuthController::class, 'configSave']);
            Route::get('/security-alerts', [\App\Http\Controllers\Api\SecurityController::class, 'alerts']);
            Route::post('/security-alerts/{id}/resolve', [\App\Http\Controllers\Api\SecurityController::class, 'resolveAlert'])->where('id', '[0-9]+');
            Route::get('/agencies', [\App\Http\Controllers\Api\PlatformController::class, 'listAgencies']);
            Route::post('/agencies', [\App\Http\Controllers\Api\PlatformController::class, 'createAgency']);
            Route::patch('/agencies/{agency}', [\App\Http\Controllers\Api\PlatformController::class, 'updateAgency']);
            Route::post('/agencies/{agency}/suspend', [\App\Http\Controllers\Api\PlatformController::class, 'suspendAgency']);
            Route::post('/agencies/{agency}/resume', [\App\Http\Controllers\Api\PlatformController::class, 'resumeAgency']);
            Route::post('/agencies/{agency}/resend-invite', [\App\Http\Controllers\Api\PlatformController::class, 'resendInvite']);
            Route::get('/email-logs', [\App\Http\Controllers\Api\PlatformController::class, 'emailLogs']);
            Route::get('/marketing-site', [\App\Http\Controllers\Api\MarketingSiteController::class, 'get']);
            Route::put('/marketing-site', [\App\Http\Controllers\Api\MarketingSiteController::class, 'save']);
            Route::get('/marketing-site/leads', [\App\Http\Controllers\Api\MarketingSiteController::class, 'leads']);
            Route::post('/marketing-site/leads/delete', [\App\Http\Controllers\Api\MarketingSiteController::class, 'deleteLead']);
            Route::post('/marketing-site/leads/update', [\App\Http\Controllers\Api\MarketingSiteController::class, 'updateLead']);
            Route::post('/marketing-site/leads', [\App\Http\Controllers\Api\MarketingSiteController::class, 'addLead']);
            Route::get('/marketing-site/analytics', [\App\Http\Controllers\Api\MarketingSiteController::class, 'analytics']);
            Route::get('/marketing-site/chats', [\App\Http\Controllers\Api\MarketingSiteController::class, 'chats']);
        });
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::patch('/auth/me', [AuthController::class, 'updateProfile']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
        // v22p3.5: onboarding wizard submission
        Route::patch('/auth/me/onboarding', [AuthController::class, 'updateOnboarding']);

        // v22p3.2: self-service avatar upload (any authenticated user)
        Route::post('/auth/me/avatar', function (\Illuminate\Http\Request $request) {
            $request->validate([
                'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            ]);
            $file = $request->file('avatar');
            $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $name = (string) \Illuminate\Support\Str::uuid() . '.' . $ext;
            // v22p43: Laravel 11 changed the local-disk root to storage/app/private,
            // so 'public/avatars' now writes to storage/app/private/public/avatars/
            // which isn't reachable via the /storage symlink. Use the public disk
            // so the file lands at storage/app/public/avatars/.
            $file->storeAs('avatars', $name, 'public');
            $publicPath = '/storage/avatars/' . $name;
            \Illuminate\Support\Facades\DB::table('users')
                ->where('id', $request->user()->id)
                ->update(['photo_url' => $publicPath, 'updated_at' => now()]);
            return response()->json(['photo_url' => $publicPath, 'message' => 'Avatar updated']);
        });

        // ─── Help (available to all authenticated users) ───
        // v22p46: notifications inbox for ALL authenticated users (was
        // previously gated to guardian-only under /parent/notifications).
        Route::get('/notifications', [NotificationController::class, 'mine']);
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

        Route::get('/help', [HelpController::class, 'index']);
        /* v22p69: must precede /help/{slug} */
        Route::get('/help/dashboard', [HelpController::class, 'dashboard']);
        Route::get('/help/analytics', [HelpController::class, 'analytics']);
        Route::post('/help/featured', [HelpController::class, 'pinFeatured']);
        Route::delete('/help/featured/{slug}', [HelpController::class, 'unpinFeatured']);
        Route::post('/help/{slug}/view', [HelpController::class, 'trackView']);
        Route::post('/help/{slug}/feedback', [HelpController::class, 'feedback']);
        Route::get('/help/{slug}', [HelpController::class, 'show']);
        Route::post('/help/ask', [HelpController::class, 'ask'])->middleware('throttle:30,1');

        // v22p33 — Per-role dashboard widget data
        Route::get('/widgets/me', [\App\Http\Controllers\Api\WidgetsController::class, 'me']);

        // A parent's own notification preferences (email / SMS / in-app), e.g.
        // being told when their child is signed in or out. Scoped to the caller.
        Route::get('/me/notification-prefs',  [\App\Http\Controllers\Api\NotificationPrefsController::class, 'index']);
        // Which rooms an educator is assigned to (director / agency-admin only —
        // the controller checks centre access on both the user and the rooms).
        Route::get('/admin/users/{user}/rooms', [\App\Http\Controllers\Api\EducatorRoomsController::class, 'show']);
        Route::put('/admin/users/{user}/rooms', [\App\Http\Controllers\Api\EducatorRoomsController::class, 'update']);
        Route::put('/me/notification-prefs',  [\App\Http\Controllers\Api\NotificationPrefsController::class, 'update']);

        // Onboarding — Privacy Policy & NDA. Every role signs once; the signature,
        // a hash of the exact wording, the IP and the timestamp are recorded, the
        // signed copy is filed against the user's record, and a copy is emailed.
        Route::get('/auth/agreement',      [\App\Http\Controllers\Api\AgreementController::class, 'status']);
        Route::post('/auth/agreement/sign', [\App\Http\Controllers\Api\AgreementController::class, 'sign']);
        // Declining means no access: the account is flagged, the session revoked,
        // and the agency (bcc info@kiddietrac.com) is told.
        Route::post('/auth/agreement/decline', [\App\Http\Controllers\Api\AgreementController::class, 'decline']);

        // v22p49 — Daily care logs, milestones, portfolio, time clock
        Route::post  ('/care/logs',                 [\App\Http\Controllers\Api\CareController::class, 'logCare']);
        Route::get   ('/care/logs/child/{child}',   [\App\Http\Controllers\Api\CareController::class, 'logsForChild']);
        Route::get   ('/care/milestones/catalog',   [\App\Http\Controllers\Api\CareController::class, 'milestoneCatalog']);
        Route::get   ('/care/milestones/child/{child}', [\App\Http\Controllers\Api\CareController::class, 'milestonesForChild']);
        Route::post  ('/care/milestones',           [\App\Http\Controllers\Api\CareController::class, 'recordMilestone']);
        Route::get   ('/care/portfolio/{child}',    [\App\Http\Controllers\Api\CareController::class, 'portfolio']);
        Route::post  ('/staff/punch',               [\App\Http\Controllers\Api\CareController::class, 'punch']);
        Route::get   ('/staff/punches/me',          [\App\Http\Controllers\Api\CareController::class, 'myPunches']);
        Route::get   ('/staff/punches/centre',      [\App\Http\Controllers\Api\CareController::class, 'centrePunches']);

        // 2026-07-13 — self-scoped staff data for the educator mobile app.
        // Own shifts only, and child records only for children the caller can
        // already access (canAccessChildScoped). The director/admin schedule +
        // payroll routes stay centre-wide and stay director-gated.
        Route::get   ('/provider/shifts/me',        [\App\Http\Controllers\Api\EducatorSelfController::class, 'myShifts']);
        Route::get   ('/provider/children/{child}',  [\App\Http\Controllers\Api\EducatorSelfController::class, 'childRecord']);
        Route::get   ('/admin/tours',               [\App\Http\Controllers\Api\CareController::class, 'listTours']);
        Route::patch ('/admin/tours/{id}',          [\App\Http\Controllers\Api\CareController::class, 'updateTour']);

        // v22p34 — Marketing campaigns (directors + agency_admin + platform_admin).
        // Route-level role gate kept lenient; controller does the agency-scope check
        // via getAgencyId() so platform_admin in a tenant context Just Works.
        Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
            Route::get   ('/marketing/campaigns',           [\App\Http\Controllers\Api\MarketingController::class, 'index']);
            Route::post  ('/marketing/campaigns',           [\App\Http\Controllers\Api\MarketingController::class, 'store']);
            Route::get   ('/marketing/campaigns/{id}',      [\App\Http\Controllers\Api\MarketingController::class, 'show']);
            Route::patch ('/marketing/campaigns/{id}',      [\App\Http\Controllers\Api\MarketingController::class, 'update']);
            Route::delete('/marketing/campaigns/{id}',      [\App\Http\Controllers\Api\MarketingController::class, 'destroy']);
            Route::post  ('/marketing/campaigns/{id}/send', [\App\Http\Controllers\Api\MarketingController::class, 'sendNow']);
            Route::post  ('/marketing/images',              [\App\Http\Controllers\Api\MarketingController::class, 'uploadImage']);
        });

        Route::prefix('parent')->middleware('role:guardian')->group(function () {
            Route::get('/dashboard', [FamilyController::class, 'parentDashboard']);
            Route::get('/children', [FamilyController::class, 'myChildren']);
            Route::get('/children/{child}', [ChildController::class, 'show']);
            Route::get('/children/{child}/timeline', [DailyEventController::class, 'timeline']);
            Route::get('/children/{child}/digest/{date}', [DailyEventController::class, 'digest']);
            Route::get('/children/{child}/invoices', [InvoiceController::class, 'forChild']);
            Route::get('/children/{child}/photos', [MediaController::class, 'forChild']);
            Route::get('/children/{child}/observations', [MediaController::class, 'observationsForChild']);
            Route::get('/messages', [MessageController::class, 'myConversations']);
            // These static paths MUST precede /messages/{conversation} or they'd bind as an id.
            Route::get('/messages/unread-count', [MessageController::class, 'unreadCount']);
            Route::post('/messages/nudge', [MessageController::class, 'nudge'])->middleware('throttle:6,1');
            Route::get('/messages/{conversation}', [MessageController::class, 'show']);
            Route::post('/messages', [MessageController::class, 'sendToRoom']);
            Route::patch('/messages/{message}', [MessageController::class, 'editMessage']);
            Route::delete('/messages/{message}', [MessageController::class, 'deleteMessage']);
            Route::get('/notifications', [NotificationController::class, 'mine']);
            Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

            // v20: incident workflow (parent side)
            Route::get('/incidents',                       [IncidentController::class, 'index']);
            Route::get('/incidents/{id}',                  [IncidentController::class, 'show']);
            Route::post('/incidents/{id}/acknowledge',     [IncidentController::class, 'acknowledge']);

            // v22p1: Parent reads of child health
            Route::get('/children/{child}/health',         [ChildHealthController::class, 'show']);
            Route::get('/children/{child}/medications',    [MedicationController::class, 'parentList']);
            Route::get('/children/{child}/immunizations',  [ImmunizationController::class, 'parentList']);

            // v22p2.2: eDocuments (parent inbox + e-sign)
            Route::get('/edocuments',                       [EDocumentController::class, 'parentIndex']);
            Route::post('/edocuments/{id}/sign',            [EDocumentController::class, 'sign']);
            Route::get('/edocuments/{id}/download',         [EDocumentController::class, 'parentDownload']);
        });

        Route::prefix('provider')->middleware('role:educator,centre_director,agency_admin')->group(function () {
            Route::get('/bootstrap', [RoomController::class, 'bootstrap']);
            Route::get('/rooms/{room}/roster', [RoomController::class, 'roster']);
            Route::get('/rooms/{room}/ratio', [RoomController::class, 'currentRatio']);

            Route::post('/check-in', [CheckEventController::class, 'checkIn']);
            Route::post('/check-out', [CheckEventController::class, 'checkOut']);
            Route::post('/check-in-batch', [CheckEventController::class, 'checkInBatch']);

            Route::post('/events', [DailyEventController::class, 'store']);
            Route::patch('/events/{event}', [DailyEventController::class, 'update']);
            Route::delete('/events/{event}', [DailyEventController::class, 'destroy']);

            Route::post('/photos', [MediaController::class, 'upload']);
            Route::post('/observations', [MediaController::class, 'createObservation']);
            Route::post('/incidents', [IncidentController::class, 'store']);

            // v22p1: Medications
            Route::get('/medications',                  [MedicationController::class, 'activeForProvider']);
            Route::post('/medications/give',            [MedicationController::class, 'give']);
            Route::get('/children/{child}/health',      [ChildHealthController::class, 'show']);

            // v20: incident workflow (provider/educator side)
            Route::get('/incidents',                       [IncidentController::class, 'index']);
            Route::get('/incidents/{id}',                  [IncidentController::class, 'show']);
            Route::patch('/incidents/{id}',                [IncidentController::class, 'update']);
            Route::post('/incidents/{id}/submit',          [IncidentController::class, 'submit']);

            // v21: AI Observation Notes
            Route::get('/observations',                    [AiObservationController::class, 'index']);
            Route::post('/observations/structure',         [AiObservationController::class, 'structure']);
            Route::post('/observations/save',              [AiObservationController::class, 'save']);

            Route::get('/conversations', [MessageController::class, 'educatorConversations']);
            Route::get('/conversations/{conversation}', [MessageController::class, 'show']);
            Route::post('/messages', [MessageController::class, 'educatorReply']);

            Route::post('/clock-in', [StaffController::class, 'clockIn']);
            Route::post('/clock-out', [StaffController::class, 'clockOut']);
        });

        Route::prefix('director')->middleware('role:centre_director,agency_admin')->group(function () {
            // v20: AI digest status board (correctly placed in director group)
            Route::get('/digest-status', [DigestStatusController::class, 'index']);
            Route::post('/digest-status/regenerate', [DigestStatusController::class, 'regenerate']);
                        // v21: centre list for current director
            Route::get('/centres', [CentreController::class, 'myCentres']);
            Route::get('/dashboard', [CentreController::class, 'directorDashboard']);
            Route::get('/centre', [CentreController::class, 'mine']);
            Route::patch('/centre', [CentreController::class, 'update']);

            // ─── Rooms CRUD (new in v5a) ───
            Route::get('/rooms', [RoomManagementController::class, 'index']);
            Route::post('/rooms', [RoomManagementController::class, 'store']);
            Route::patch('/rooms/{room}', [RoomManagementController::class, 'update']);
            Route::delete('/rooms/{room}', [RoomManagementController::class, 'destroy']);
            // v22p3.4: per-room logo
            Route::post('/rooms/{room}/logo', [RoomManagementController::class, 'uploadLogo']);

            Route::get('/enrollments', [ChildController::class, 'enrollmentList']);
            Route::post('/enrollments', [ChildController::class, 'enroll']);
            Route::patch('/enrollments/{enrollment}', [ChildController::class, 'updateEnrollment']);
            Route::get('/waitlist', [ChildController::class, 'waitlist']);

            Route::get('/children/{child}', [ChildController::class, 'show']);
            // v22p88: child-detail tabs
            Route::get('/children/{child}/daily-events', [ChildController::class, 'dailyEvents']);
            Route::get('/children/{child}/feed',         [ChildController::class, 'feed']);
            Route::get('/children/{child}/documents',    [ChildController::class, 'documents']);
            // v22p5: full CRUD on child record (separate from enrollment updates)
            Route::patch('/children/{child}', [ChildController::class, 'update']);
            Route::delete('/children/{child}', [ChildController::class, 'destroy']);

            Route::get('/families', [FamilyController::class, 'index']);
            Route::post('/families', [FamilyController::class, 'store']);
            Route::get('/families/{family}', [FamilyController::class, 'show']);
            Route::post('/families/{family}/invite', [FamilyController::class, 'invite']);
            // v22p5: kiosk management (director surface)
            Route::post('/centres/{centre}/kiosk-token', [\App\Http\Controllers\Api\KioskController::class, 'rotateToken']);
            Route::post('/centres/{centre}/kiosk-toggle', [\App\Http\Controllers\Api\KioskController::class, 'toggleEnabled']);
            Route::post('/guardians/{guardian}/kiosk-pin', [\App\Http\Controllers\Api\KioskController::class, 'setGuardianPin']);
            // v22p6: emergency cards (print-optimised HTML, browser Print/Save-PDF)
            Route::get('/children/{child}/emergency-card', [\App\Http\Controllers\Api\EmergencyCardController::class, 'forChild']);
            Route::get('/rooms/{room}/emergency-cards', [\App\Http\Controllers\Api\EmergencyCardController::class, 'forRoom']);


            Route::get('/staff', [StaffController::class, 'index']);
            Route::post('/staff/invite', [StaffController::class, 'invite']);
            Route::get('/staff/{user}/certifications', [StaffController::class, 'certifications']);
            Route::post('/staff/{user}/certifications', [StaffController::class, 'addCertification']);
            Route::get('/staff/schedule', [StaffController::class, 'schedule']);
            Route::post('/staff/shifts', [StaffController::class, 'createShift']);

            Route::get('/compliance/overview', [CentreController::class, 'compliance']);
            Route::get('/compliance/export', [CentreController::class, 'complianceExport']);
            Route::get('/reports/attendance', [CentreController::class, 'attendanceReport']);

            Route::get('/invoices', [InvoiceController::class, 'index']);
            Route::post('/invoices/generate', [InvoiceController::class, 'generateBatch']);
            Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
            Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment']);

            Route::get('/incidents', [IncidentController::class, 'index']);
            Route::patch('/incidents/{incident}/review', [IncidentController::class, 'review']);

            // v20: incident workflow (director side)
            Route::get('/incidents/{id}',                       [IncidentController::class, 'show']);
            Route::post('/incidents/{id}/notify-parent',        [IncidentController::class, 'notifyParent']);

            // v21: AI Lesson Plan Generator
            Route::get('/lesson-plans-ai',                       [AiLessonPlanController::class, 'index']);
            Route::post('/lesson-plans-ai/generate',             [AiLessonPlanController::class, 'generate']);
            Route::post('/lesson-plans-ai/save',                 [AiLessonPlanController::class, 'save']);
            Route::post('/lesson-plans-ai/{id}/publish',         [AiLessonPlanController::class, 'publish']);
            Route::post('/incidents/{id}/close',                [IncidentController::class, 'close']);

            // v22p1: Medications
            Route::get('/medications',                          [MedicationController::class, 'index']);
            Route::post('/medications',                         [MedicationController::class, 'store']);
            Route::get('/medications/{id}',                     [MedicationController::class, 'show']);
            Route::patch('/medications/{id}',                   [MedicationController::class, 'update']);
            Route::post('/medications/{id}/authorize',          [MedicationController::class, 'authorize']);
            Route::post('/medications/{id}/discontinue',        [MedicationController::class, 'discontinue']);
            Route::get('/medications/{id}/logs',                [MedicationController::class, 'logs']);

            // v22p1: Immunizations
            Route::get('/immunizations',                        [ImmunizationController::class, 'index']);
            Route::post('/immunizations',                       [ImmunizationController::class, 'store']);
            Route::patch('/immunizations/{id}',                 [ImmunizationController::class, 'update']);
            Route::delete('/immunizations/{id}',                [ImmunizationController::class, 'destroy']);

            // v22p1: Child health profile (allergies / dietary / alerts)
            Route::get('/children/{child}/health',              [ChildHealthController::class, 'show']);
            Route::patch('/children/{child}/health',            [ChildHealthController::class, 'update']);

            // v22p2.1: Invitation codes (director-managed parent self-signup)
            Route::get('/invitation-codes',                     [InvitationController::class, 'index']);
            Route::post('/invitation-codes',                    [InvitationController::class, 'store']);
            Route::post('/invitation-codes/{id}/revoke',        [InvitationController::class, 'revoke']);
            Route::delete('/invitation-codes/{id}',             [InvitationController::class, 'destroy']);

            // v22p2.2: eDocuments (PDF templates + e-signatures)
            Route::get('/edocuments',                           [EDocumentController::class, 'index']);
            Route::post('/edocuments',                          [EDocumentController::class, 'store']);
            Route::patch('/edocuments/{id}',                    [EDocumentController::class, 'update']);
            Route::post('/edocuments/{id}/archive',             [EDocumentController::class, 'archive']);
            Route::get('/edocuments/{id}/signatures',           [EDocumentController::class, 'signatures']);
            Route::get('/edocuments/{id}/download',             [EDocumentController::class, 'directorDownload']);

            // ─── Storage quota & audit (v7) ───
            Route::get('/storage/usage', function (\Illuminate\Http\Request $request) {
                // v22p98: ResolvesCentreContext is a TRAIT — cannot be `new`'d (fatal 500).
                $ctx = new class { use \App\Http\Concerns\ResolvesCentreContext; public function centre($u){ return $this->resolveCentreId($u); } };
                $centreId = $ctx->centre($request->user()) ?? 0;
                if (!$centreId) return response()->json(['message' => 'No centre access'], 403);
                $service = new \App\Services\StorageQuotaService();
                return response()->json($service->getUsage($centreId));
            });

            Route::get('/audit-log', function (\Illuminate\Http\Request $request) {
                // v22p98: ResolvesCentreContext is a TRAIT — cannot be `new`'d (fatal 500).
                $ctx = new class { use \App\Http\Concerns\ResolvesCentreContext; public function centre($u){ return $this->resolveCentreId($u); } };
                $centreId = $ctx->centre($request->user()) ?? 0;
                if (!$centreId) return response()->json(['message' => 'No centre access'], 403);
                // v22p98: audit_logs has NO centre_id column — scope by the users
                // tied to this centre (staff + guardians) instead.
                $userIds = \Illuminate\Support\Facades\DB::table('role_assignments')->where('centre_id', $centreId)->where('active', 1)->pluck('user_id')
                    ->merge(\Illuminate\Support\Facades\DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->where('f.centre_id', $centreId)->pluck('g.user_id'))
                    ->unique()->values()->all();
                $logs = \Illuminate\Support\Facades\DB::table('audit_logs')
                    ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
                    ->whereIn('audit_logs.user_id', $userIds ?: [0])
                    ->orderByDesc('audit_logs.created_at')
                    ->limit(100)
                    ->select('audit_logs.*', 'users.first_name', 'users.last_name', 'users.email')
                    ->get();
                return response()->json(['logs' => $logs]);
            });
        });

        Route::prefix('agency')->middleware('role:agency_admin')->group(function () {
            Route::get('/dashboard', [AgencyController::class, 'dashboard']);
        });
    
    // ─────────────────────────────────────────────────────────────────────
    // V10-MIN ADDITIONS — Admin CRUD, Branding, Stripe billing (reseller)
    // Does NOT include signup routes — existing /signup/centre stays as-is
    // ─────────────────────────────────────────────────────────────────────
    
    // PUBLIC — branding lookup (login page needs this BEFORE auth)
    // PUBLIC — Stripe webhook (for reseller subscriptions)
    // AGENCY-ADMIN ROUTES
    Route::prefix('admin')->middleware('role:agency_admin')->group(function () {
        // ── v23 Expense management: suppliers / purchase orders / bills ──
        Route::get('/suppliers', [\App\Http\Controllers\Api\SupplierController::class, 'index']);
        Route::post('/suppliers', [\App\Http\Controllers\Api\SupplierController::class, 'store']);
        Route::get('/suppliers/{supplier}', [\App\Http\Controllers\Api\SupplierController::class, 'show']);
        Route::patch('/suppliers/{supplier}', [\App\Http\Controllers\Api\SupplierController::class, 'update']);
        Route::delete('/suppliers/{supplier}', [\App\Http\Controllers\Api\SupplierController::class, 'destroy']);

        Route::get('/purchase-orders', [\App\Http\Controllers\Api\PurchaseOrderController::class, 'index']);
        Route::post('/purchase-orders', [\App\Http\Controllers\Api\PurchaseOrderController::class, 'store']);
        Route::get('/purchase-orders/{po}', [\App\Http\Controllers\Api\PurchaseOrderController::class, 'show']);
        Route::patch('/purchase-orders/{po}', [\App\Http\Controllers\Api\PurchaseOrderController::class, 'update']);
        Route::post('/purchase-orders/{po}/status', [\App\Http\Controllers\Api\PurchaseOrderController::class, 'updateStatus']);
        Route::post('/purchase-orders/{po}/convert-to-bill', [\App\Http\Controllers\Api\PurchaseOrderController::class, 'convertToBill']);
        Route::delete('/purchase-orders/{po}', [\App\Http\Controllers\Api\PurchaseOrderController::class, 'destroy']);

        Route::get('/expenses/summary', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'summary']);
        Route::get('/expense-invoices', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'index']);
        Route::post('/expense-invoices', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'store']);
        Route::get('/expense-invoices/{bill}', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'show']);
        Route::patch('/expense-invoices/{bill}', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'update']);
        Route::post('/expense-invoices/{bill}/approve', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'approve']);
        Route::post('/expense-invoices/{bill}/payments', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'recordPayment']);
        Route::post('/expense-invoices/{bill}/void', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'void']);
        Route::delete('/expense-invoices/{bill}', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'destroy']);

        Route::get('/centres', [AdminController::class, 'listCentres']);
        Route::post('/centres', [AdminController::class, 'createCentre']);
        Route::patch('/centres/{centre}', [AdminController::class, 'updateCentre']);
        Route::delete('/centres/{centre}', [AdminController::class, 'archiveCentre']);
        Route::delete('/centres/{centre}/permanent', [AdminController::class, 'permanentDeleteCentre']);
        Route::post('/centres/{centre}/restore', [AdminController::class, 'restoreCentre']);
        // v22p3.4: per-centre branding logo upload
        Route::post('/centres/{centre}/logo', [AdminController::class, 'uploadCentreLogo']);
    
        Route::get('/users', [AdminController::class, 'listUsers']);
        Route::post('/users', [AdminController::class, 'createUser']);
        Route::patch('/users/{user}', [AdminController::class, 'updateUser']);
        Route::get('/users/{user}/profile', [AdminController::class, 'userProfile']);
        Route::put('/users/{user}/profile', [AdminController::class, 'updateUserProfile']);
        Route::post('/users/{user}/notes', [AdminController::class, 'addUserNote']);
        Route::post('/users/{user}/role', [AdminController::class, 'setUserRole']);
        // v22p1.2: user lifecycle
        Route::delete('/users/{user}', [AdminController::class, 'destroyUser']);
        Route::post('/users/{user}/reset-password', [AdminController::class, 'resetUserPassword']);
        Route::post('/users/{user}/resend-welcome', [AdminController::class, 'resendWelcome']);
        // v22p3.2: avatars
        Route::post('/users/{user}/avatar', [AdminController::class, 'uploadAvatar']);
        // v22p3.5: reopen onboarding wizard for a user
        Route::post('/users/{user}/reopen-onboarding', [AdminController::class, 'reopenOnboarding']);
    
        Route::get('/families', [AdminController::class, 'listFamilies']);
        // v22p9: sibling discount tiers (per-agency)
        Route::get('/sibling-discounts', [\App\Http\Controllers\Api\SiblingDiscountController::class, 'show']);
        // v22p8 Phase A: custom roles CRUD
        Route::get('/permission-keys', [\App\Http\Controllers\Api\RoleController::class, 'permissionCatalog']);
        Route::get('/roles', [\App\Http\Controllers\Api\RoleController::class, 'index']);
        Route::post('/roles', [\App\Http\Controllers\Api\RoleController::class, 'store']);
        Route::get('/roles/{role}', [\App\Http\Controllers\Api\RoleController::class, 'show']);
        Route::patch('/roles/{role}', [\App\Http\Controllers\Api\RoleController::class, 'update']);
        Route::delete('/roles/{role}', [\App\Http\Controllers\Api\RoleController::class, 'destroy']);
        Route::patch('/sibling-discounts', [\App\Http\Controllers\Api\SiblingDiscountController::class, 'update']);
        // v22p4.6: global portal search — moved out of this group in v22p42 so
        // centre_director can use the Cmd-K palette too. See director-search
        // group near end of this file.
        // v22p42: presence (online users via personal_access_tokens.last_used_at)
        Route::get('/presence', [AdminController::class, 'presence']);
        // v22p47: compliance dashboard + agency-wide children list (+ CSV)
        Route::get('/compliance', [AdminController::class, 'compliance']);
        Route::get('/children', [AdminController::class, 'listAgencyChildren']);
        // v22p42: bulk invoice generation by centre (extends director endpoint with centre_id arg)
        Route::post('/invoices/generate-batch', [\App\Http\Controllers\Api\InvoiceController::class, 'generateBatchByCentre']);

        // v22p43: custom forms builder — admin side (CRUD + responses)
        Route::get('/forms', [\App\Http\Controllers\Api\FormsController::class, 'index']);
        Route::post('/forms', [\App\Http\Controllers\Api\FormsController::class, 'store']);
        Route::get('/forms/{form}', [\App\Http\Controllers\Api\FormsController::class, 'show']);
        Route::patch('/forms/{form}', [\App\Http\Controllers\Api\FormsController::class, 'update']);
        Route::delete('/forms/{form}', [\App\Http\Controllers\Api\FormsController::class, 'destroy']);
        Route::get('/forms/{form}/responses', [\App\Http\Controllers\Api\FormsController::class, 'listResponses']);
        Route::post('/forms/{form}/email', [\App\Http\Controllers\Api\FormsController::class, 'emailToParents']);
        Route::get('/families/{family}', [AdminController::class, 'showFamily']);
        // v22p11: agency_admin family CRUD
        Route::post('/families', [AdminController::class, 'createFamily']);
        Route::patch('/families/{family}', [AdminController::class, 'updateFamily']);
        // v22p46: family delete (soft) — keeps children + audit history intact
        Route::delete('/families/{family}', [AdminController::class, 'destroyFamily']);
    
        Route::get('/analytics', [AdminController::class, 'analytics']);
    
        Route::put('/branding', [BrandingController::class, 'update']);
        Route::post('/branding/logo', [BrandingController::class, 'uploadLogo']);
    
        Route::get('/billing/status', [StripeBillingController::class, 'status']);
        Route::post('/billing/connect', [StripeBillingController::class, 'startConnect']);
        Route::post('/billing/subscribe', [StripeBillingController::class, 'subscribe']);
        Route::post('/billing/cancel', [StripeBillingController::class, 'cancel']);
    });

    // ─────────────────────────────────────────────────────────────────────
    // V12-BIG ADDITIONS — Chat, Agency Management, Tenant Detection
    // ─────────────────────────────────────────────────────────────────────
    
    // PARENT chat routes
    Route::middleware('role:guardian')->prefix('parent')->group(function () {
        Route::get('/chats',                       [ChatController::class, 'parentList']);
        Route::post('/chats/start',                [ChatController::class, 'parentStart']);
        Route::get('/chats/{conversation}',        [ChatController::class, 'parentShow']);
        Route::post('/chats/{conversation}/send',  [ChatController::class, 'parentSend']);
    });

    // v22p43: parent-facing form endpoints (any authenticated user can submit;
    // the controller filters by guardian->family->centre->agency).
    Route::get('/forms', [\App\Http\Controllers\Api\FormsController::class, 'publishedForUser']);
    Route::post('/forms/{form}/submit', [\App\Http\Controllers\Api\FormsController::class, 'submit']);
    
    // v22p42: global search for both agency_admin AND centre_director.
    // SearchController::resolveAgencyId now walks director.centre_id ->
    // centres.agency_id so directors get correctly scoped results.
    Route::middleware('role:agency_admin,centre_director,platform_admin')->prefix('admin')->group(function () {
        Route::get('/search', [\App\Http\Controllers\Api\SearchController::class, 'query']);
        // v22p47: audit trail — agency admins (whole agency) + directors (their centre)
        Route::get('/audit-logs', [AdminController::class, 'auditLogs']);
    });

    // PROVIDER chat routes (educator + centre_director + agency_admin)
    Route::middleware('role:educator,centre_director,agency_admin')->prefix('provider')->group(function () {
        Route::get('/chats',                       [ChatController::class, 'providerList']);
        Route::get('/chats/{conversation}',        [ChatController::class, 'providerShow']);
        Route::post('/chats/{conversation}/send',  [ChatController::class, 'providerSend']);
        Route::post('/chats/start',                [ChatController::class, 'providerStart']);
    });
    
    // Shared: unread badge (any authenticated user)
    Route::get('/chats/unread-count',          [ChatController::class, 'unreadCount']);
    
    // AGENCY management (agency_admin)
    Route::middleware('role:agency_admin')->prefix('admin')->group(function () {
        Route::get('/agencies',                    [AgencyManagementController::class, 'index']);
        Route::post('/agencies',                   [AgencyManagementController::class, 'store']);
        Route::patch('/agencies/{agency}',         [AgencyManagementController::class, 'update']);
        Route::delete('/agencies/{agency}',        [AgencyManagementController::class, 'destroy']);
    });

    // ─────────────────────────────────────────────────────────────────────
    // V13 — Waitlist, Announcements, Autopay
    // ─────────────────────────────────────────────────────────────────────
    
    // DIRECTOR — waitlist
    Route::middleware('role:centre_director,agency_admin')->prefix('director')->group(function () {
        Route::get('/waitlist',                       [WaitlistController::class, 'index']);
        Route::post('/waitlist',                      [WaitlistController::class, 'store']);
        Route::post('/waitlist/{child}/promote',      [WaitlistController::class, 'promote']);
        Route::post('/waitlist/{child}/decline',      [WaitlistController::class, 'decline']);
        Route::post('/waitlist/{child}/move',         [WaitlistController::class, 'move']);
    });
    
    // PROVIDER — announcements compose + list
    Route::middleware('role:educator,centre_director,agency_admin')->prefix('provider')->group(function () {
        Route::get('/announcements',                  [AnnouncementController::class, 'indexProvider']);
        Route::post('/announcements',                 [AnnouncementController::class, 'store']);
        // Staff can delete announcements their own centre/agency owns (single or
        // bulk). Deleting also purges the inbox notifications it generated.
        Route::post('/announcements/delete',          [AnnouncementController::class, 'destroyMany']);
        Route::delete('/announcements/{id}',          [AnnouncementController::class, 'destroy']);
    });
    
    // PARENT — announcements inbox + waitlist status + autopay
    Route::middleware('role:guardian')->prefix('parent')->group(function () {
        Route::get('/announcements',                  [AnnouncementController::class, 'indexParent']);
        Route::get('/waitlist-status',                [WaitlistController::class, 'parentStatus']);
        Route::get('/billing/autopay-status',         [AutopayController::class, 'status']);
        Route::post('/billing/setup-intent',          [AutopayController::class, 'setupIntent']);
        Route::post('/billing/confirm-autopay',       [AutopayController::class, 'confirmAutopay']);
        Route::post('/billing/disable-autopay',       [AutopayController::class, 'disableAutopay']);
    });

    // ─────────────────────────────────────────────────────────────────────
    // V14 — Lesson Plans, Scheduling, Push, Announcement badge
    // ─────────────────────────────────────────────────────────────────────
    
    // Public (no auth) — push public key

    // PROVIDER — lesson plans + scheduling
    Route::middleware('role:educator,centre_director,agency_admin')->prefix('provider')->group(function () {
        Route::get('/lesson-plans',        [LessonPlanController::class, 'show']);
        Route::put('/lesson-plans',        [LessonPlanController::class, 'upsert']);
        Route::get('/lesson-plans/list',   [LessonPlanController::class, 'listForRoom']);
    });
    
    Route::middleware('role:centre_director,agency_admin')->prefix('director')->group(function () {
        Route::get('/schedule',                  [SchedulingController::class, 'week']);
        Route::get('/schedule/range',            [SchedulingController::class, 'range']);
        Route::get('/schedule/staff',            [SchedulingController::class, 'staffList']);
        Route::post('/schedule/shift',           [SchedulingController::class, 'createShift']);
        Route::patch('/schedule/shift/{id}',     [SchedulingController::class, 'updateShift']);
        Route::delete('/schedule/shift/{id}',    [SchedulingController::class, 'deleteShift']);
        Route::get('/timesheets',                [SchedulingController::class, 'timesheets']);
        Route::get('/certifications',            [SchedulingController::class, 'certifications']);
    });
    
    // PARENT — lesson plan view
    Route::middleware('role:guardian')->prefix('parent')->group(function () {
        Route::get('/lesson-plan/{child}',       [LessonPlanController::class, 'parentShow']);
    });
    
    // PUSH — any authenticated user
    Route::post('/push/subscribe',           [PushController::class, 'subscribe']);
    Route::post('/push/unsubscribe',         [PushController::class, 'unsubscribe']);
        Route::post('/push/device',              [PushController::class, 'registerDevice']);
        Route::post('/push/test-fcm',            [PushController::class, 'testFcm']);
        Route::get ('/admin/upcoming-birthdays', ['App\Http\Controllers\Api\DashboardExtrasController','upcomingBirthdays']);
        Route::get ('/admin/floor-staff',        ['App\Http\Controllers\Api\DashboardExtrasController','floorStaff']);
        Route::post('/admin/quick-notify',       ['App\Http\Controllers\Api\DashboardExtrasController','quickNotify']);
        Route::get ('/checkin/centre-code/{centreId}', [\App\Http\Controllers\Api\CheckinScanController::class, 'centreCode']);
        Route::post('/parent/checkin/scan',            [\App\Http\Controllers\Api\CheckinScanController::class, 'scan']);
    Route::post('/push/test',                [PushController::class, 'test']);
    
    // NOTIFICATIONS — unread badge support
    Route::get('/notifications/unread-count', [NotificationUnreadController::class, 'unreadCount']);
    Route::post('/notifications/mark-read',   [NotificationUnreadController::class, 'markRead']);
    // Inbox housekeeping — always scoped to the caller's own notifications.
    Route::post('/notifications/delete',      [NotificationUnreadController::class, 'destroyMany']);
    Route::delete('/notifications/{id}',      [NotificationUnreadController::class, 'destroy']);

    // ───────── v15 ROUTES — Reseller features ─────────
    // These routes are spliced INTO the auth:sanctum group by deploy.sh.
    // Authorization (platform_admin vs agency_admin) is enforced inside each controller.
    
    // Feature flag management
    Route::get   ('/admin/features/catalog',           [FeatureFlagController::class, 'catalog']);
    Route::get   ('/admin/agencies/{id}/features',     [FeatureFlagController::class, 'show']);
    Route::patch ('/admin/agencies/{id}/features',     [FeatureFlagController::class, 'update']);
    
    // MRR dashboard (platform-admin only)
    Route::get   ('/admin/mrr/overview',               [MrrDashboardController::class, 'overview']);
    Route::get   ('/admin/mrr/agencies',               [MrrDashboardController::class, 'agencyList']);
    
    // White-label invoice preview (returns text/html)
    Route::get   ('/invoices/{id}/preview',            [InvoicePreviewController::class, 'previewExisting']);
    Route::get   ('/invoices/preview-sample',          [InvoicePreviewController::class, 'previewSample']);

    // ---- PDF exports ----
    Route::get('/families/{family}/t4a/{year}', [\App\Http\Controllers\Api\PdfController::class, 't4a']);
    Route::get('/care/portfolio/{child}/pdf',   [\App\Http\Controllers\Api\PdfController::class, 'portfolio']);

    // ---- Time-off ----
    Route::get   ('/time-off/mine',            [\App\Http\Controllers\Api\TimeOffController::class, 'mine']);
    Route::post  ('/time-off',                 [\App\Http\Controllers\Api\TimeOffController::class, 'create']);
    Route::post  ('/time-off/{id}/cancel',     [\App\Http\Controllers\Api\TimeOffController::class, 'cancel']);
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::get  ('/admin/time-off',        [\App\Http\Controllers\Api\TimeOffController::class, 'listAgency']);
        Route::patch('/admin/time-off/{id}',   [\App\Http\Controllers\Api\TimeOffController::class, 'decide']);
    });

    // ---- Background checks ----
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::get   ('/admin/background-checks',          [\App\Http\Controllers\Api\BackgroundCheckController::class, 'listAgency']);
        Route::post  ('/admin/background-checks',          [\App\Http\Controllers\Api\BackgroundCheckController::class, 'upsert']);
        Route::delete('/admin/background-checks/{id}',     [\App\Http\Controllers\Api\BackgroundCheckController::class, 'destroy']);
    });

    // ---- Payroll ----
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::get('/admin/payroll', [\App\Http\Controllers\Api\PayrollController::class, 'summary']);
    });

    // ---- Agency billing config (late-fee + SMS + locale) ----
    Route::middleware('role:agency_admin,platform_admin')->group(function () {
        Route::get  ('/admin/billing-config', [\App\Http\Controllers\Api\AgencyBillingConfigController::class, 'show']);
        // v22p87: combined guided billing setup (defaults + payment methods + autopay)
        Route::get  ('/admin/billing-setup', [\App\Http\Controllers\Api\AgencyBillingConfigController::class, 'showSetup']);
        Route::patch('/admin/billing-setup', [\App\Http\Controllers\Api\AgencyBillingConfigController::class, 'updateSetup']);
        // v22p86: reusable tuition / fee plans
        Route::get   ('/admin/fee-plans',        [\App\Http\Controllers\Api\FeePlanController::class, 'index']);
        Route::post  ('/admin/fee-plans',        [\App\Http\Controllers\Api\FeePlanController::class, 'store']);
        Route::patch ('/admin/fee-plans/{id}',   [\App\Http\Controllers\Api\FeePlanController::class, 'update']);
        Route::delete('/admin/fee-plans/{id}',   [\App\Http\Controllers\Api\FeePlanController::class, 'destroy']);
        Route::post  ('/admin/fee-plans/{id}/generate-invoices', [\App\Http\Controllers\Api\FeePlanController::class, 'generateInvoices']);
        Route::get  ('/admin/currency', [\App\Http\Controllers\Api\CurrencyController::class, 'show']);
        Route::patch('/admin/currency', [\App\Http\Controllers\Api\CurrencyController::class, 'update']);
        Route::get  ('/admin/country', [\App\Http\Controllers\Api\CurrencyController::class, 'showCountry']);
        Route::patch('/admin/country', [\App\Http\Controllers\Api\CurrencyController::class, 'updateCountry']);
        Route::get('/admin/email-settings', [\App\Http\Controllers\Api\EmailSettingsController::class, 'show']);
        Route::patch('/admin/email-settings', [\App\Http\Controllers\Api\EmailSettingsController::class, 'update']);
        Route::post('/admin/email-settings/test', [\App\Http\Controllers\Api\EmailSettingsController::class, 'sendTest']);
        Route::get('/admin/compliance-settings', [\App\Http\Controllers\Api\DataRetentionController::class, 'show']);
        Route::post('/admin/compliance-settings', [\App\Http\Controllers\Api\DataRetentionController::class, 'update']);
        Route::post('/admin/centre-term', [\App\Http\Controllers\Api\AgencyTermController::class, 'set']);
        Route::patch('/admin/billing-config', [\App\Http\Controllers\Api\AgencyBillingConfigController::class, 'update']);
    });

    // Billing reminders — agency admins AND centre directors.
    Route::middleware('role:agency_admin,platform_admin,centre_director')->group(function () {
        Route::get('/admin/billing-reminders', [\App\Http\Controllers\Api\BillingRemindersController::class, 'show']);
        Route::post('/admin/billing-reminders', [\App\Http\Controllers\Api\BillingRemindersController::class, 'update']);
    });

    // ---- Stripe parent-pay (family-facing) ----
    Route::get  ('/parent/billing/status',         [\App\Http\Controllers\Api\StripeParentPayController::class, 'status']);
    Route::post ('/parent/billing/setup-intent',   [\App\Http\Controllers\Api\StripeParentPayController::class, 'setupIntent']);
    Route::post ('/parent/billing/save-card',      [\App\Http\Controllers\Api\StripeParentPayController::class, 'saveCard']);
    Route::post ('/parent/billing/autopay',        [\App\Http\Controllers\Api\StripeParentPayController::class, 'toggleAutopay']);
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::post('/invoices/{id}/charge',       [\App\Http\Controllers\Api\StripeParentPayController::class, 'chargeInvoice']);
    });

    // ---- SMS ----
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::post('/admin/sms/broadcast', [\App\Http\Controllers\Api\SmsController::class, 'broadcast']);
        Route::get ('/admin/sms/messages',  [\App\Http\Controllers\Api\SmsController::class, 'listMessages']);
    });

    // ---- AI features ----
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::get ('/ai/churn-risk',                [\App\Http\Controllers\Api\AiController::class, 'churnRisk']);
        Route::post('/ai/doc-extract',               [\App\Http\Controllers\Api\AiController::class, 'docExtract']);
    });
    Route::get('/ai/weekly-recap/{child}', [\App\Http\Controllers\Api\AiController::class, 'weeklyRecap']);

    // ---- QBO ----
    Route::middleware('role:agency_admin,platform_admin')->group(function () {
        Route::get ('/qbo/connect',                [\App\Http\Controllers\Api\QboController::class, 'connect']);
        Route::get   ('/admin/qbo-config', [\App\Http\Controllers\Api\QboController::class, 'showConfig']);
        Route::patch ('/admin/qbo-config', [\App\Http\Controllers\Api\QboController::class, 'saveConfig']);
        Route::delete('/admin/qbo-config', [\App\Http\Controllers\Api\QboController::class, 'clearConfig']);
        Route::get ('/qbo/status',                 [\App\Http\Controllers\Api\QboController::class, 'status']);
        Route::post('/qbo/disconnect',             [\App\Http\Controllers\Api\QboController::class, 'disconnect']);
        Route::post('/qbo/sync/invoice/{id}',      [\App\Http\Controllers\Api\QboController::class, 'syncInvoice']);
    });

    // ---- Locale ----
    Route::get ('/locale',                  [\App\Http\Controllers\Api\LocaleController::class, 'current']);
    Route::post('/locale',                  [\App\Http\Controllers\Api\LocaleController::class, 'set']);
    Route::get ('/locale/strings/{lang}',   [\App\Http\Controllers\Api\LocaleController::class, 'strings']);

    // Operations: meals, allergies, field trips, substitutes, inspection
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::get  ('/operations/menu',              [\App\Http\Controllers\Api\OperationsController::class, 'getMenuWeek']);
        Route::post ('/operations/menu',              [\App\Http\Controllers\Api\OperationsController::class, 'saveMenuWeek']);
        Route::get  ('/operations/allergy-alerts',    [\App\Http\Controllers\Api\OperationsController::class, 'allergyAlerts']);
        Route::get  ('/operations/field-trips',       [\App\Http\Controllers\Api\OperationsController::class, 'listFieldTrips']);
        Route::post ('/operations/field-trips',       [\App\Http\Controllers\Api\OperationsController::class, 'createFieldTrip']);
        Route::get  ('/operations/field-trips/{id}/permissions', [\App\Http\Controllers\Api\OperationsController::class, 'listPermissionsForTrip']);
        Route::get  ('/operations/substitutes',       [\App\Http\Controllers\Api\OperationsController::class, 'listSubstitutes']);
        Route::post ('/operations/substitutes',       [\App\Http\Controllers\Api\OperationsController::class, 'upsertSubstitute']);
        Route::get  ('/operations/sub-requests',      [\App\Http\Controllers\Api\OperationsController::class, 'listSubRequests']);
        Route::post ('/operations/sub-requests',      [\App\Http\Controllers\Api\OperationsController::class, 'createSubRequest']);
        Route::get  ('/operations/inspection',        [\App\Http\Controllers\Api\OperationsController::class, 'getInspectionChecklist']);
        Route::patch('/operations/inspection/{id}',   [\App\Http\Controllers\Api\OperationsController::class, 'updateChecklistItem']);
        Route::get  ('/operations/signatures/{child}', [\App\Http\Controllers\Api\OperationsController::class, 'listSignatures']);
    });
    // parent claims open sub request
    Route::post('/operations/sub-requests/{id}/claim', [\App\Http\Controllers\Api\OperationsController::class, 'claimSubRequest']);

    // v22p55 photo feed + feedback + doc extractions list
    Route::get ('/photos/feed',        [\App\Http\Controllers\Api\PhotoFeedController::class, 'feed']);
    Route::post('/photos',             [\App\Http\Controllers\Api\PhotoFeedController::class, 'upload']);
    Route::get ('/feedback',           [\App\Http\Controllers\Api\FeedbackController::class, 'index']);
    Route::get ('/feedback/mine',      [\App\Http\Controllers\Api\FeedbackController::class, 'mine']);
    Route::post('/feedback',           [\App\Http\Controllers\Api\FeedbackController::class, 'submit']);
    Route::get ('/ai/doc-extractions', function (\Illuminate\Http\Request $r) {
        // SECURITY: staff-only + agency-membership check (was open to any authenticated
        // user trusting X-Active-Agency-Id → cross-tenant document-data read).
        $uid = $r->user()->id;
        $ra = \Illuminate\Support\Facades\DB::table('role_assignments')->where('user_id', $uid)->where('active', 1);
        $isStaff = (clone $ra)->whereIn('role', ['centre_director', 'agency_admin', 'platform_admin'])->exists();
        if (!$isStaff) abort(403);
        $isPlatform = (clone $ra)->where('role', 'platform_admin')->exists();
        $hdr = (int) $r->header('X-Active-Agency-Id');
        $allowed = $hdr && ($isPlatform || (clone $ra)->where('agency_id', $hdr)->exists());
        $agency = $allowed ? $hdr : (int) (clone $ra)->value('agency_id');
        return ['data' => \Illuminate\Support\Facades\DB::table('ai_doc_extractions')->where('agency_id', $agency)->orderByDesc('created_at')->limit(50)->get()];
    });

    // v22p56 — Procare-parity routes
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::get   ('/operations/closures',          [\App\Http\Controllers\Api\OperationsV2Controller::class, 'closures']);
        Route::post  ('/operations/closures',          [\App\Http\Controllers\Api\OperationsV2Controller::class, 'addClosure']);
        Route::delete('/operations/closures/{id}',     [\App\Http\Controllers\Api\OperationsV2Controller::class, 'removeClosure']);
        Route::post  ('/operations/late-pickup',       [\App\Http\Controllers\Api\OperationsV2Controller::class, 'logLatePickup']);
        Route::get   ('/operations/late-pickups',      [\App\Http\Controllers\Api\OperationsV2Controller::class, 'lateHistory']);
        Route::get   ('/operations/ratio-status',      [\App\Http\Controllers\Api\OperationsV2Controller::class, 'ratioStatus']);
        Route::get   ('/operations/bus-routes',        [\App\Http\Controllers\Api\OperationsV2Controller::class, 'busRoutes']);
        Route::post  ('/operations/bus-routes',        [\App\Http\Controllers\Api\OperationsV2Controller::class, 'createBusRoute']);
        Route::get   ('/operations/bus-routes/{id}/children', [\App\Http\Controllers\Api\OperationsV2Controller::class, 'routeChildren']);
        Route::post  ('/operations/bus-routes/{id}/children', [\App\Http\Controllers\Api\OperationsV2Controller::class, 'addChildToRoute']);
        Route::get   ('/operations/rotation-candidates', [\App\Http\Controllers\Api\OperationsV2Controller::class, 'rotationCandidates']);
        Route::post  ('/operations/room-rotations',    [\App\Http\Controllers\Api\OperationsV2Controller::class, 'planRotation']);
        Route::get   ('/billing/vacation-holds',       [\App\Http\Controllers\Api\BillingV2Controller::class, 'listHolds']);
        Route::patch ('/billing/vacation-holds/{id}',  [\App\Http\Controllers\Api\BillingV2Controller::class, 'decideHold']);
        Route::get   ('/billing/tuition-increases',    [\App\Http\Controllers\Api\BillingV2Controller::class, 'listIncreases']);
        Route::post  ('/billing/tuition-increases',    [\App\Http\Controllers\Api\BillingV2Controller::class, 'scheduleIncrease']);
        Route::post  ('/billing/prorate-preview',      [\App\Http\Controllers\Api\BillingV2Controller::class, 'proratePreview']);
        Route::get   ('/engagement/reenrollment',      [\App\Http\Controllers\Api\EngagementController::class, 'listCampaigns']);
        Route::post  ('/engagement/reenrollment',      [\App\Http\Controllers\Api\EngagementController::class, 'createCampaign']);
        Route::get   ('/engagement/scores',            [\App\Http\Controllers\Api\EngagementController::class, 'engagementScores']);
        Route::get   ('/engagement/nps',               [\App\Http\Controllers\Api\EngagementController::class, 'npsSummary']);
    });
    // Parent-side
    Route::post('/billing/vacation-holds',           [\App\Http\Controllers\Api\BillingV2Controller::class, 'requestHold']);
    Route::post('/engagement/reenrollment/{id}/respond', [\App\Http\Controllers\Api\EngagementController::class, 'respondCampaign']);
    Route::post('/engagement/nps',                   [\App\Http\Controllers\Api\EngagementController::class, 'submitNps']);
    Route::post('/engagement/sign-document',         [\App\Http\Controllers\Api\EngagementController::class, 'signDocument']);
    Route::get ('/engagement/signed-documents',      [\App\Http\Controllers\Api\EngagementController::class, 'listSignedDocs']);

    // v22p57 — combined features routes
    // Chat enhancements
    Route::post('/chat/conversations/{id}/read',   [\App\Http\Controllers\Api\ChatV2Controller::class, 'markRead']);
    Route::get ('/chat/conversations/{id}/state',  [\App\Http\Controllers\Api\ChatV2Controller::class, 'state']);
    Route::post('/chat/conversations/{id}/typing', [\App\Http\Controllers\Api\ChatV2Controller::class, 'ping']);
    // ACH
    Route::get ('/parent/billing/ach-status',        [\App\Http\Controllers\Api\BillingV3Controller::class, 'achStatus']);
    Route::post('/parent/billing/ach-setup-intent',  [\App\Http\Controllers\Api\BillingV3Controller::class, 'achSetupIntent']);
    Route::post('/parent/billing/ach-save',          [\App\Http\Controllers\Api\BillingV3Controller::class, 'saveAch']);
    // Pickup auth
    Route::get   ('/family/pickup-auth/{childId}',   [\App\Http\Controllers\Api\FamilyOperationsController::class, 'listAuth']);
    Route::post  ('/family/pickup-auth',             [\App\Http\Controllers\Api\FamilyOperationsController::class, 'addAuth']);
    Route::delete('/family/pickup-auth/{id}',        [\App\Http\Controllers\Api\FamilyOperationsController::class, 'removeAuth']);
    // Daily check-in
    Route::get ('/family/checkin/today/{childId}',   [\App\Http\Controllers\Api\FamilyOperationsController::class, 'todayCheckin']);
    Route::get ('/family/checkin/recent/{childId}',  [\App\Http\Controllers\Api\FamilyOperationsController::class, 'recentCheckins']);
    Route::post('/family/checkin',                   [\App\Http\Controllers\Api\FamilyOperationsController::class, 'submitCheckin']);
    // Referrals
    Route::get ('/family/referrals',                 [\App\Http\Controllers\Api\FamilyOperationsController::class, 'listReferrals']);
    Route::post('/family/referrals',                 [\App\Http\Controllers\Api\FamilyOperationsController::class, 'submitReferral']);
    // Analytics
    Route::get('/analytics/trends/{childId}',        [\App\Http\Controllers\Api\AnalyticsController::class, 'childTrends']);
    Route::get('/analytics/hdlh-gaps/{childId}',     [\App\Http\Controllers\Api\AnalyticsController::class, 'hdlhGaps']);
    // Drip + curriculum (admin)
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::get ('/marketing/drip',         [\App\Http\Controllers\Api\GrowthController::class, 'listDrip']);
        Route::post('/marketing/drip',         [\App\Http\Controllers\Api\GrowthController::class, 'createDrip']);
        Route::get ('/marketing/drip/stats',   [\App\Http\Controllers\Api\GrowthController::class, 'dripStats']);
        Route::get ('/curriculum',             [\App\Http\Controllers\Api\GrowthController::class, 'listCurriculum']);
        Route::post('/curriculum',             [\App\Http\Controllers\Api\GrowthController::class, 'createCurriculum']);
        Route::post('/curriculum/{id}/use',    [\App\Http\Controllers\Api\GrowthController::class, 'useCurriculum']);
    });

    // v22p58 — Procare-gap features
    // Wallet
    Route::get   ('/parent/wallet',                 [\App\Http\Controllers\Api\PaymentMethodsController::class, 'list']);
    Route::post  ('/parent/wallet',                 [\App\Http\Controllers\Api\PaymentMethodsController::class, 'add']);
    Route::post  ('/parent/wallet/{id}/default',    [\App\Http\Controllers\Api\PaymentMethodsController::class, 'setDefault']);
    Route::delete('/parent/wallet/{id}',            [\App\Http\Controllers\Api\PaymentMethodsController::class, 'remove']);
    Route::post  ('/parent/wallet/setup-intent',    [\App\Http\Controllers\Api\PaymentMethodsController::class, 'setupIntent']);
    // Refunds (admin)
    Route::middleware('role:agency_admin,centre_director,platform_admin')->group(function () {
        Route::get ('/refunds/recent-payments',     [\App\Http\Controllers\Api\RefundsController::class, 'recentPayments']);
        Route::get ('/refunds/payment/{paymentId}', [\App\Http\Controllers\Api\RefundsController::class, 'listForPayment']);
        Route::post('/refunds',                     [\App\Http\Controllers\Api\RefundsController::class, 'create']);
    });
    // Ledger
    Route::get('/parent/ledger',                    [\App\Http\Controllers\Api\LedgerController::class, 'myLedger']);
    Route::get('/parent/ledger/pdf',                [\App\Http\Controllers\Api\LedgerController::class, 'familyLedgerPdf']);
    Route::middleware('role:agency_admin,centre_director,platform_admin')->group(function () {
        Route::get('/families/{familyId}/ledger',        [\App\Http\Controllers\Api\LedgerController::class, 'familyLedger']);
        Route::get('/families/{familyId}/ledger/pdf',    [\App\Http\Controllers\Api\LedgerController::class, 'familyLedgerPdf']);
    });
    // Reports
    Route::get   ('/reports/canned',     [\App\Http\Controllers\Api\ReportsController::class, 'cannedList']);
    Route::get   ('/reports/canned/run', [\App\Http\Controllers\Api\ReportsController::class, 'canned']);
    Route::get   ('/reports/canned/pdf', [\App\Http\Controllers\Api\ReportsController::class, 'cannedPdf']);
    Route::get   ('/reports/canned/xlsx', [\App\Http\Controllers\Api\ReportsController::class, 'cannedXlsx']);
    Route::get   ('/reports',                       [\App\Http\Controllers\Api\ReportsController::class, 'listMine']);
    Route::post  ('/reports',                       [\App\Http\Controllers\Api\ReportsController::class, 'create']);
    Route::patch ('/reports/{id}',                  [\App\Http\Controllers\Api\ReportsController::class, 'update']);
    Route::delete('/reports/{id}',                  [\App\Http\Controllers\Api\ReportsController::class, 'destroy']);
    Route::get   ('/reports/{id}/run',              [\App\Http\Controllers\Api\ReportsController::class, 'run']);
    Route::post  ('/reports/preview',               [\App\Http\Controllers\Api\ReportsController::class, 'preview']);
    // Reactions + video
    Route::get   ('/reactions/{type}/{id}',         [\App\Http\Controllers\Api\MediaV2Controller::class, 'listReactions']);
    Route::post  ('/reactions',                     [\App\Http\Controllers\Api\MediaV2Controller::class, 'addReaction']);
    Route::delete('/reactions',                     [\App\Http\Controllers\Api\MediaV2Controller::class, 'removeReaction']);
    Route::get   ('/videos/feed',                   [\App\Http\Controllers\Api\MediaV2Controller::class, 'videoFeed']);
    Route::post  ('/videos',                        [\App\Http\Controllers\Api\MediaV2Controller::class, 'uploadVideo']);
    // Immunization schedule
    Route::middleware('role:agency_admin,centre_director,platform_admin')->group(function () {
        Route::get   ('/immunization/schedule',           [\App\Http\Controllers\Api\ImmunizationScheduleController::class, 'listSchedule']);
        Route::post  ('/immunization/schedule',           [\App\Http\Controllers\Api\ImmunizationScheduleController::class, 'upsertSchedule']);
        Route::delete('/immunization/schedule/{id}',      [\App\Http\Controllers\Api\ImmunizationScheduleController::class, 'removeSchedule']);
        Route::get   ('/immunization/due-report',         [\App\Http\Controllers\Api\ImmunizationScheduleController::class, 'agencyDueReport']);
    });
    Route::get('/immunization/child/{childId}/status',    [\App\Http\Controllers\Api\ImmunizationScheduleController::class, 'childStatus']);
    // CACFP — educators log meals + view roster/monthly counts...
    Route::middleware('role:agency_admin,centre_director,educator,platform_admin')->group(function () {
        Route::get ('/cacfp/roster',           [\App\Http\Controllers\Api\CacfpController::class, 'dailyRoster']);
        Route::post('/cacfp/meal',             [\App\Http\Controllers\Api\CacfpController::class, 'setMeal']);
        Route::get ('/cacfp/monthly',          [\App\Http\Controllers\Api\CacfpController::class, 'monthlyReport']);
    });
    // ...but setting a family's subsidy tier is financial — directors/admins only (v22p95).
    Route::middleware('role:agency_admin,centre_director,platform_admin')->group(function () {
        Route::patch('/cacfp/family/{familyId}', [\App\Http\Controllers\Api\CacfpController::class, 'setFamilyTier']);
    });
    // Billing schedule
    Route::middleware('role:agency_admin,centre_director,platform_admin')->group(function () {
        Route::get   ('/billing/schedule/{familyId}',   [\App\Http\Controllers\Api\BillingScheduleController::class, 'get']);
        Route::post  ('/billing/schedule',              [\App\Http\Controllers\Api\BillingScheduleController::class, 'set']);
        Route::delete('/billing/schedule/{familyId}',   [\App\Http\Controllers\Api\BillingScheduleController::class, 'disable']);
    });

    // v22p59 — long-tail features
    // Photo tagging (AI)
    Route::middleware('role:educator,centre_director,agency_admin,platform_admin')->group(function () {
        Route::get ('/photos/{id}/tags',          [\App\Http\Controllers\Api\PhotoTagController::class, 'listTags']);
        Route::post('/photos/{id}/classify',      [\App\Http\Controllers\Api\PhotoTagController::class, 'classifyPhoto']);
        Route::post('/photos/tag-confirm',        [\App\Http\Controllers\Api\PhotoTagController::class, 'confirmTag']);
    });
    // Family directory
    Route::get ('/directory',                     [\App\Http\Controllers\Api\DirectoryController::class, 'listDirectory']);
    Route::get ('/directory/me',                  [\App\Http\Controllers\Api\DirectoryController::class, 'myOptin']);
    Route::post('/directory/me',                  [\App\Http\Controllers\Api\DirectoryController::class, 'setOptin']);
    // Conferences
    Route::get ('/conferences/slots',             [\App\Http\Controllers\Api\ConferenceController::class, 'listOpenSlots']);
    Route::post('/conferences/slots',             [\App\Http\Controllers\Api\ConferenceController::class, 'createSlots']);
    Route::post('/conferences/slots/{id}/book',   [\App\Http\Controllers\Api\ConferenceController::class, 'book']);
    Route::post('/conferences/slots/{id}/cancel', [\App\Http\Controllers\Api\ConferenceController::class, 'cancel']);
    // Field-trip GPS
    Route::post('/field-trips/{id}/ping',         [\App\Http\Controllers\Api\FieldTripGpsController::class, 'ping']);
    Route::get ('/field-trips/{id}/location',     [\App\Http\Controllers\Api\FieldTripGpsController::class, 'location']);
    // Attendance pattern
    Route::get ('/attendance/pattern/{childId}',  [\App\Http\Controllers\Api\AttendancePatternController::class, 'get']);
    Route::post('/attendance/pattern/{childId}',  [\App\Http\Controllers\Api\AttendancePatternController::class, 'set']);
    Route::middleware('role:educator,centre_director,agency_admin,platform_admin')->group(function () {
        Route::get('/attendance/weekly-overview', [\App\Http\Controllers\Api\AttendancePatternController::class, 'weeklyOverview']);
    });
    // Report cards
    Route::middleware('role:educator,centre_director,agency_admin,platform_admin')->group(function () {
        Route::get ('/report-cards/child/{childId}', [\App\Http\Controllers\Api\ReportCardController::class, 'listForChild']);
        Route::post('/report-cards/generate',        [\App\Http\Controllers\Api\ReportCardController::class, 'generate']);
        Route::patch('/report-cards/{id}',           [\App\Http\Controllers\Api\ReportCardController::class, 'update']);
        Route::post('/report-cards/{id}/send',       [\App\Http\Controllers\Api\ReportCardController::class, 'send']);
    });
    Route::get('/report-cards/{id}/pdf',          [\App\Http\Controllers\Api\ReportCardController::class, 'pdf']);
    // Activity zones
    Route::get ('/zones',                         [\App\Http\Controllers\Api\ZonesController::class, 'listZones']);
    Route::middleware('role:educator,centre_director,agency_admin,platform_admin')->group(function () {
        Route::post('/zones',                     [\App\Http\Controllers\Api\ZonesController::class, 'createZone']);
        Route::post('/zones/visit',               [\App\Http\Controllers\Api\ZonesController::class, 'logVisit']);
        Route::get ('/zones/daily-report',        [\App\Http\Controllers\Api\ZonesController::class, 'dailyReport']);
    });
    // Support tickets
    Route::get ('/tickets',                       [\App\Http\Controllers\Api\SupportTicketController::class, 'listMine']);
    Route::post('/tickets',                       [\App\Http\Controllers\Api\SupportTicketController::class, 'create']);
    Route::get ('/tickets/{id}',                  [\App\Http\Controllers\Api\SupportTicketController::class, 'show']);
    Route::post('/tickets/{id}/reply',            [\App\Http\Controllers\Api\SupportTicketController::class, 'reply']);
    Route::patch('/tickets/{id}/status',          [\App\Http\Controllers\Api\SupportTicketController::class, 'updateStatus']);



    // parent responds to permission slip
    Route::patch('/operations/permissions/{id}',   [\App\Http\Controllers\Api\OperationsController::class, 'respondToPermission']);

    // Compliance: CWELCC, retention, cert/check renewal calendar
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::get ('/compliance/cwelcc/families',         [\App\Http\Controllers\Api\ComplianceController::class, 'cwelccFamilies']);
        Route::patch('/compliance/cwelcc/families/{id}',   [\App\Http\Controllers\Api\ComplianceController::class, 'setCwelccEnrolment']);
        Route::get ('/compliance/cwelcc/monthly',          [\App\Http\Controllers\Api\ComplianceController::class, 'cwelccMonthlyReport']);
        Route::get ('/compliance/cwelcc/monthly/pdf',      [\App\Http\Controllers\Api\ComplianceController::class, 'cwelccMonthlyPdf']);
        Route::get ('/compliance/cwelcc/monthly/csv',      [\App\Http\Controllers\Api\ComplianceController::class, 'cwelccMonthlyCsv']);
        Route::get ('/compliance/retention',               [\App\Http\Controllers\Api\ComplianceController::class, 'retentionReport']);
        Route::get ('/compliance/expiry-calendar',         [\App\Http\Controllers\Api\ComplianceController::class, 'expiryCalendar']);
    });
    // v22p63
    Route::post('/chatbot/ask',                  [\App\Http\Controllers\Api\ChatbotController::class, 'ask']);
    Route::get ('/chatbot/history',              [\App\Http\Controllers\Api\ChatbotController::class, 'history']);
    Route::post('/chat/voice',                   [\App\Http\Controllers\Api\VoiceMessageController::class, 'send']);
    Route::get ('/wellness/today/{childId}',     [\App\Http\Controllers\Api\WellnessController::class, 'todayForChild']);
    Route::post('/wellness/screening',           [\App\Http\Controllers\Api\WellnessController::class, 'submit']);
    Route::middleware('role:educator,centre_director,agency_admin,platform_admin')->group(function () {
        Route::get ('/wellness/digest',          [\App\Http\Controllers\Api\WellnessController::class, 'todayDigest']);
    });
    // v22p94: payment plans are financial — NOT educators.
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::get ('/payment-plans/family/{id}',[\App\Http\Controllers\Api\PaymentPlanController::class, 'listForFamily']);
        Route::post('/payment-plans',            [\App\Http\Controllers\Api\PaymentPlanController::class, 'create']);
        Route::post('/payment-plans/{id}/cancel',[\App\Http\Controllers\Api\PaymentPlanController::class, 'cancel']);
    });
    Route::get ('/payment-plans/mine',           [\App\Http\Controllers\Api\PaymentPlanController::class, 'myPlans']);
    Route::get ('/doc-workflows',                [\App\Http\Controllers\Api\DocumentWorkflowController::class, 'listMine']);
    Route::post('/doc-workflows',                [\App\Http\Controllers\Api\DocumentWorkflowController::class, 'create']);
    Route::get ('/doc-workflows/{id}',           [\App\Http\Controllers\Api\DocumentWorkflowController::class, 'show']);
    Route::post('/doc-workflows/{id}/sign',      [\App\Http\Controllers\Api\DocumentWorkflowController::class, 'sign']);


    // AI v2: tagging, translation, auto-link, forecast, anomalies
    Route::middleware('role:centre_director,agency_admin,platform_admin,educator')->group(function () {
        Route::post('/ai/tag-observation',                 [\App\Http\Controllers\Api\AiV2Controller::class, 'tagObservation']);
        Route::post('/ai/translate-message',               [\App\Http\Controllers\Api\AiV2Controller::class, 'translateMessage']);
    });
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::post('/ai/doc-extract/{id}/auto-link',      [\App\Http\Controllers\Api\AiV2Controller::class, 'autoLinkExtraction']);
        Route::get ('/ai/enrollment-forecast',             [\App\Http\Controllers\Api\AiV2Controller::class, 'enrollmentForecast']);
        Route::get ('/ai/attendance-anomalies',            [\App\Http\Controllers\Api\AiV2Controller::class, 'attendanceAnomalies']);
    });

    // QBO bulk push
    Route::middleware('role:agency_admin,platform_admin')->group(function () {
        Route::post('/qbo/sync/invoices/bulk',             [\App\Http\Controllers\Api\QboController::class, 'bulkSyncInvoices']);
    });

    // Time-off calendar block read endpoint
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::get('/director/schedule/time-off-blocks',   [\App\Http\Controllers\Api\SchedulingController::class, 'timeOffBlocks']);
    });


    // Branded XLSX exports (replaces CSV)
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::post('/exports/table',                  [\App\Http\Controllers\Api\ReportsController::class, 'tableExport']);
        Route::get('/exports/users',                   [\App\Http\Controllers\Api\ExportsController::class, 'users']);
        Route::get('/exports/families',                [\App\Http\Controllers\Api\ExportsController::class, 'families']);
        Route::get('/exports/audit-logs',              [\App\Http\Controllers\Api\ExportsController::class, 'auditLogs']);
        Route::get('/exports/children',                [\App\Http\Controllers\Api\ExportsController::class, 'children']);
        Route::get('/exports/payroll',                 [\App\Http\Controllers\Api\ExportsController::class, 'payroll']);
        Route::get('/exports/background-checks',       [\App\Http\Controllers\Api\ExportsController::class, 'backgroundChecks']);
        Route::get('/exports/cwelcc-monthly',     [\App\Http\Controllers\Api\ExportsController::class, 'cwelccMonthly']);
        Route::get('/exports/forms/{id}/responses',    [\App\Http\Controllers\Api\ExportsController::class, 'formResponses']);
    });

    // Bulk import: templates + file upload
    Route::middleware('role:agency_admin,platform_admin')->group(function () {
        Route::get ('/import/templates/{type}',           [\App\Http\Controllers\Api\ImportController::class, 'template']);
        Route::post('/import/users',                      [\App\Http\Controllers\Api\ImportController::class, 'importUsers']);
        Route::post('/import/children',                   [\App\Http\Controllers\Api\ImportController::class, 'importChildren']);
        Route::post('/import/families',                   [\App\Http\Controllers\Api\ImportController::class, 'importFamilies']);
        Route::post('/import/menu',                       [\App\Http\Controllers\Api\ImportController::class, 'importMenu']);
        Route::post('/import/inspection',                 [\App\Http\Controllers\Api\ImportController::class, 'importInspection']);
    });

    // Generic agency-integration API (v1) — idempotent JSON upserts keyed by the
    // caller's own external_id, scoped to the token's agency. Lets any external
    // agency platform feed centres/families/children into KiddieTrac under its
    // own agency. First consumer: iLearn. See docs/INTEGRATION_API.md.
    Route::middleware('role:agency_admin,platform_admin')->prefix('integration')->group(function () {
        Route::get ('/ping',     [\App\Http\Controllers\Api\IntegrationController::class, 'ping']);
        Route::post('/centres',  [\App\Http\Controllers\Api\IntegrationController::class, 'upsertCentre']);
        Route::post('/families', [\App\Http\Controllers\Api\IntegrationController::class, 'upsertFamily']);
        Route::post('/guardians', [\App\Http\Controllers\Api\IntegrationController::class, 'upsertGuardian']);
        Route::post('/children', [\App\Http\Controllers\Api\IntegrationController::class, 'upsertChild']);
        Route::post('/children/deactivate', [\App\Http\Controllers\Api\IntegrationController::class, 'deactivateChild']);
        Route::post('/sync',     [\App\Http\Controllers\Api\IntegrationController::class, 'sync']);
    });







});
});

// v22p5: kiosk mode — public endpoints (no auth; centre token in URL)
Route::prefix('kiosk')->group(function () {
    Route::get('/{token}', [\App\Http\Controllers\Api\KioskController::class, 'lookup']);
    Route::post('/{token}/check-event', [\App\Http\Controllers\Api\KioskController::class, 'checkEvent']);
});

// ===== v22p51 PUBLIC ROUTES =====


// v22p51 — Stripe parent-pay webhook (no auth; signature-verified inside)
Route::post('/stripe/parent-webhook', [\App\Http\Controllers\Api\StripeParentPayController::class, 'webhook']);

// v22p51 — Public agency landing pages (HTML)
Route::get('/public/landing/{slug}', [\App\Http\Controllers\Api\LandingController::class, 'landing']);

// v22p51 — QBO OAuth callback (no auth — state cookie identifies agency)
Route::get('/qbo/callback', [\App\Http\Controllers\Api\QboController::class, 'callback']);

// v22p51 — public locale bundles (no auth required so the login page can localize too)
Route::get('/locale/public/{lang}', [\App\Http\Controllers\Api\LocaleController::class, 'strings']);


// ===== v22p53 PUBLIC =====

Route::post('/kiosk/{token}/signature', [\App\Http\Controllers\Api\OperationsController::class, 'captureSignature']);




// v22p63 SSO (public, /api/v1 prefix)
Route::prefix('v1')->group(function () {
    Route::get ('/sso/providers',                [\App\Http\Controllers\Api\SsoController::class, 'providers']);
    Route::get ('/sso/{provider}',               [\App\Http\Controllers\Api\SsoController::class, 'redirect']);
    Route::get ('/sso/{provider}/callback',      [\App\Http\Controllers\Api\SsoController::class, 'callback']);
    Route::post('/kiosk/{token}/temperature',    [\App\Http\Controllers\Api\KioskTemperatureController::class, 'record']);
});

/* v22p68 help routes */
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('help/dashboard', [App\Http\Controllers\Api\HelpController::class, 'dashboard']);
    Route::get('help/analytics', [App\Http\Controllers\Api\HelpController::class, 'analytics']);
    Route::post('help/featured', [App\Http\Controllers\Api\HelpController::class, 'pinFeatured']);
    Route::delete('help/featured/{slug}', [App\Http\Controllers\Api\HelpController::class, 'unpinFeatured']);
    Route::post('help/{slug}/view', [App\Http\Controllers\Api\HelpController::class, 'trackView']);
    Route::post('help/{slug}/feedback', [App\Http\Controllers\Api\HelpController::class, 'feedback']);
});
