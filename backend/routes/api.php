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
            $file->storeAs('public/avatars', $name);
            $publicPath = '/storage/avatars/' . $name;
            \Illuminate\Support\Facades\DB::table('users')
                ->where('id', $request->user()->id)
                ->update(['photo_url' => $publicPath, 'updated_at' => now()]);
            return response()->json(['photo_url' => $publicPath, 'message' => 'Avatar updated']);
        });

        // ─── Help (available to all authenticated users) ───
        Route::get('/help', [HelpController::class, 'index']);
        Route::get('/help/{slug}', [HelpController::class, 'show']);
        Route::post('/help/ask', [HelpController::class, 'ask'])->middleware('throttle:30,1');

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
        // v22p4.6: global portal search (FQN inline, no import needed)
        Route::get('/search', [\App\Http\Controllers\Api\SearchController::class, 'query']);
        Route::get('/families/{family}', [AdminController::class, 'showFamily']);
        // v22p11: agency_admin family CRUD
        Route::post('/families', [AdminController::class, 'createFamily']);
        Route::patch('/families/{family}', [AdminController::class, 'updateFamily']);
    
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
});
});

// v22p5: kiosk mode — public endpoints (no auth; centre token in URL)
Route::prefix('kiosk')->group(function () {
    Route::get('/{token}', [\App\Http\Controllers\Api\KioskController::class, 'lookup']);
    Route::post('/{token}/check-event', [\App\Http\Controllers\Api\KioskController::class, 'checkEvent']);
});
