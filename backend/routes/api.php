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

    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/auth/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1');
    Route::post('/auth/reset', [AuthController::class, 'resetPassword']);

    // ─── Public signup (rate-limited, v7) ───
    Route::post('/signup/centre', [SignupController::class, 'signup'])->middleware('throttle:3,60');

    // v22p2.1: public invite-code parent signup
    Route::get('/signup/invitation/{code}', [InvitationController::class, 'probe'])->middleware('throttle:30,1');
    Route::post('/signup/by-code', [SignupController::class, 'byCode'])->middleware('throttle:5,60');


Route::any('/login', function () { return response()->json(['message' => 'Unauthenticated. Please sign in via the app.'], 401); })->name('login');
Route::get('/branding', [BrandingController::class, 'show']);
Route::post('/stripe/webhook', [StripeBillingController::class, 'webhook']);

// v22p49 — Public tour booking. Throttled aggressively because the public
// endpoint is the most-likely target for spam — 8 requests per hour per IP.
Route::post('/public/tours', [\App\Http\Controllers\Api\CareController::class, 'publicTourBook'])
    ->middleware('throttle:8,60');

    

    // v14 fix: push/public-key must be publicly accessible (called before subscription)
    Route::get('/push/public-key', [PushController::class, 'publicKey']);


    Route::middleware('auth:sanctum')->group(function () {

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
            Route::get('/agencies', [\App\Http\Controllers\Api\PlatformController::class, 'listAgencies']);
            Route::post('/agencies', [\App\Http\Controllers\Api\PlatformController::class, 'createAgency']);
            Route::patch('/agencies/{agency}', [\App\Http\Controllers\Api\PlatformController::class, 'updateAgency']);
            Route::post('/agencies/{agency}/suspend', [\App\Http\Controllers\Api\PlatformController::class, 'suspendAgency']);
            Route::post('/agencies/{agency}/resume', [\App\Http\Controllers\Api\PlatformController::class, 'resumeAgency']);
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
        Route::get('/help/{slug}', [HelpController::class, 'show']);
        Route::post('/help/ask', [HelpController::class, 'ask'])->middleware('throttle:30,1');

        // v22p33 — Per-role dashboard widget data
        Route::get('/widgets/me', [\App\Http\Controllers\Api\WidgetsController::class, 'me']);

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

        Route::prefix('parent')->group(function () {
            Route::get('/dashboard', [FamilyController::class, 'parentDashboard']);
            Route::get('/children', [FamilyController::class, 'myChildren']);
            Route::get('/children/{child}', [ChildController::class, 'show']);
            Route::get('/children/{child}/timeline', [DailyEventController::class, 'timeline']);
            Route::get('/children/{child}/digest/{date}', [DailyEventController::class, 'digest']);
            Route::get('/children/{child}/invoices', [InvoiceController::class, 'forChild']);
            Route::get('/children/{child}/photos', [MediaController::class, 'forChild']);
            Route::get('/children/{child}/observations', [MediaController::class, 'observationsForChild']);
            Route::get('/messages', [MessageController::class, 'myConversations']);
            Route::get('/messages/{conversation}', [MessageController::class, 'show']);
            Route::post('/messages', [MessageController::class, 'sendToRoom']);
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
                $centreId = (new \App\Http\Concerns\ResolvesCentreContext)->resolveCentreId($request->user()) ?? 0;
                if (!$centreId) return response()->json(['message' => 'No centre access'], 403);
                $service = new \App\Services\StorageQuotaService();
                return response()->json($service->getUsage($centreId));
            });

            Route::get('/audit-log', function (\Illuminate\Http\Request $request) {
                $centreId = (new \App\Http\Concerns\ResolvesCentreContext)->resolveCentreId($request->user()) ?? 0;
                if (!$centreId) return response()->json(['message' => 'No centre access'], 403);
                $logs = \Illuminate\Support\Facades\DB::table('audit_logs')
                    ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
                    ->where('audit_logs.centre_id', $centreId)
                    ->orWhereNull('audit_logs.centre_id') // global events (login, signup) without centre
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
        Route::get('/centres', [AdminController::class, 'listCentres']);
        Route::post('/centres', [AdminController::class, 'createCentre']);
        Route::patch('/centres/{centre}', [AdminController::class, 'updateCentre']);
        Route::delete('/centres/{centre}', [AdminController::class, 'archiveCentre']);
        // v22p3.4: per-centre branding logo upload
        Route::post('/centres/{centre}/logo', [AdminController::class, 'uploadCentreLogo']);
    
        Route::get('/users', [AdminController::class, 'listUsers']);
        Route::post('/users', [AdminController::class, 'createUser']);
        // v22p39: audit-log viewer for agency admins (+ platform_admin sees all)
        Route::get('/audit-logs', [AdminController::class, 'auditLogs']);
        Route::patch('/users/{user}', [AdminController::class, 'updateUser']);
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
    Route::post('/push/test',                [PushController::class, 'test']);
    
    // NOTIFICATIONS — unread badge support
    Route::get('/notifications/unread-count', [NotificationUnreadController::class, 'unreadCount']);
    Route::post('/notifications/mark-read',   [NotificationUnreadController::class, 'markRead']);

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
        Route::patch('/admin/billing-config', [\App\Http\Controllers\Api\AgencyBillingConfigController::class, 'update']);
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
        Route::get ('/qbo/status',                 [\App\Http\Controllers\Api\QboController::class, 'status']);
        Route::post('/qbo/disconnect',             [\App\Http\Controllers\Api\QboController::class, 'disconnect']);
        Route::post('/qbo/sync/invoice/{id}',      [\App\Http\Controllers\Api\QboController::class, 'syncInvoice']);
    });

    // ---- Locale ----
    Route::get ('/locale',                  [\App\Http\Controllers\Api\LocaleController::class, 'current']);
    Route::post('/locale',                  [\App\Http\Controllers\Api\LocaleController::class, 'set']);
    Route::get ('/locale/strings/{lang}',   [\App\Http\Controllers\Api\LocaleController::class, 'strings']);



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

