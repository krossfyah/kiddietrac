<?php

declare(strict_types=1);

/* ONE role list for every `provider` prefix.

   There were three provider groups carrying three DIFFERENT lists: one omitted
   home_visitor, another omitted platform_admin — which is how a super admin ended up
   403'd on /provider/lesson-plans, and a home visitor on half the endpoints they were
   meant to reach. Divergent copies of a guard is exactly how the duplicate tenant-guard
   leak hid, so this is the widest of the three, named once and referenced everywhere.

   Widening WHO MAY ASK is safe here because it is not what scopes the answer: every one
   of these endpoints still resolves rooms, centres and children through the tenant
   guards, so a director still sees only their own centre's rooms. (Anthony, 2026-09-08) */
if (! defined('KT_PROVIDER_ROLES')) {
    define('KT_PROVIDER_ROLES', 'role:educator,home_visitor,centre_director,agency_admin,platform_admin');
}

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
use App\Http\Controllers\Api\DayBriefController;
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
    /* WHAT THE SERVER NEVER SEES (2026-09-18).

       A 508 "Resource Limit Is Reached" and a 421 "Misdirected Request" are produced by
       the web server before PHP is started, so nothing in Laravel can log them - and
       between them they account for 59,845 responses this month against 71 PHP-level
       500s. They are also the ones that reach the user as "Network error - check your
       connection", because an error page emitted before PHP runs carries no CORS header,
       so the browser refuses the response to JavaScript and fetch() rejects outright.

       The browser is the only witness. This is where it testifies.

       Deliberately forgiving about auth: a portal that has just been handed a 508 may be
       mid-session, between tokens, or on a page that never had one, and a report refused
       for want of a bearer token is a report we do not get. Rate-limited instead, and it
       records only what it is told plus what the request itself reveals. */
    Route::post('/diag/client-error', function (\Illuminate\Http\Request $r) {
        $d = $r->validate([
            'status' => 'required|integer|min:0|max:599',
            'path'   => 'required|string|max:200',
            'method' => 'nullable|string|max:10',
            'kind'   => 'nullable|string|max:40',
            'ms'     => 'nullable|integer|min:0|max:86400000',
            // kind raw_error_shown: the error text the portal caught on screen (2026-10-22).
            'text'   => 'nullable|string|max:300',
        ]);

        $userId = null;
        $agencyId = null;
        try {
            $u = $r->user();
            if ($u) { $userId = (int) $u->id; }
        } catch (\Throwable $e) {}
        // Outside auth:sanctum, so read the bearer token here (2026-11-04: every push.tapped row had no user).
        if (! $userId && $r->bearerToken()) {
            try {
                $pat = \Laravel\Sanctum\PersonalAccessToken::findToken($r->bearerToken());
                if ($pat && (! $pat->expires_at || $pat->expires_at->isFuture())) { $userId = (int) $pat->tokenable_id; }
            } catch (\Throwable $e) {}
        }
        /* The client sends the agency it was scoped to. Unstamped rows are invisible in
           every agency, which for an error log means written and never read. */
        $hdr = (int) $r->header('X-Active-Agency-Id');
        if ($hdr > 0) { $agencyId = $hdr; }
        if (! $agencyId && $userId) {
            try { $agencyId = \App\Support\AuditScope::resolve($userId, $r); } catch (\Throwable $e) {}
        }

        $status = (int) $d['status'];

        /* A NOTIFICATION TAP (2026-10-22). Anthony tapped a website-chat push on his phone
           and did not land in the chat; nothing recorded where a tap went, so it could not
           be traced. The app now reports the link it was given and the screen it reached. */
        if (($d['kind'] ?? null) === 'push_tap') {
            try {
                \App\Support\Audit::write([
                    'user_id' => $userId, 'agency_id' => $agencyId,
                    'action' => 'push.tapped', 'entity_type' => 'notification', 'entity_id' => null,
                    'payload' => json_encode(['summary' => (string) ($d['text'] ?? ''), 'link' => $d['path'], 'ms' => $d['ms'] ?? null]),
                    'ip_address' => $r->ip(), 'user_agent' => mb_substr((string) $r->userAgent(), 0, 500), 'created_at' => now(),
                ]);
            } catch (\Throwable $e) {}
            return response()->json(['ok' => true]);
        }
        /* WALK WATCH GPS (2026-10-30). Walks #47-#49 recorded no route and nothing said why: the Play app's GPS
           watcher could not start and only the phone knew. kt-walk-tracker.js now reports GPS trouble (and each
           walk's first fix) here; it is filed on the walk, so the walk's audit trail shows what the phone saw. */
        if (($d['kind'] ?? null) === 'walk_gps') {
            $tripId = preg_match('#^/field-trips/(\d+)$#', (string) $d['path'], $m) ? (int) $m[1] : null;
            try {
                \App\Support\Audit::write([
                    'user_id' => $userId, 'agency_id' => $agencyId,
                    'action' => 'walk.gps', 'entity_type' => 'field_trip', 'entity_id' => $tripId,
                    'payload' => json_encode(['summary' => 'Walk Watch GPS on the phone: ' . (string) ($d['text'] ?? ''), 'ms' => $d['ms'] ?? null]),
                    'ip_address' => $r->ip(), 'user_agent' => mb_substr((string) $r->userAgent(), 0, 500), 'created_at' => now(),
                ]);
            } catch (\Throwable $e) {}
            return response()->json(['ok' => true]);
        }
        $raw = ($d['kind'] ?? null) === 'raw_error_shown';
        $what = $raw
            ? 'raw error text reached the screen and was replaced with a plain sentence: "' . ($d['text'] ?? '') . '"'
            : ($status === 0
            ? 'The request never completed (no response reached the browser)'
            : 'HTTP ' . $status
                . ($status === 508 ? ' Resource Limit Is Reached - the hosting account was at its concurrency limit' : '')
                . ($status === 421 ? ' Misdirected Request' : ''));

        try {
            \App\Support\Audit::write([
                'user_id'     => $userId,
                'agency_id'   => $agencyId,
                'action'      => 'error.client_request',
                'entity_type' => 'request',
                'entity_id'   => null,
                'payload'     => json_encode([
                    'summary' => $raw
                        ? 'On ' . $d['path'] . ', ' . $what . '.'
                        : 'The portal could not load ' . ($d['method'] ?? 'GET') . ' /'
                        . ltrim($d['path'], '/') . ': ' . $what . '.',
                    'text'    => $d['text'] ?? null,
                    'status'  => $status,
                    'path'    => $d['path'],
                    'method'  => $d['method'] ?? 'GET',
                    'kind'    => $d['kind'] ?? null,
                    'ms'      => $d['ms'] ?? null,
                ]),
                'ip_address'  => $r->ip(),
                'user_agent'  => mb_substr((string) $r->userAgent(), 0, 500),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) { /* never break the error sink */ }

        return response()->json(['ok' => true]);
    })->middleware('throttle:60,1,r-grp');

    // Public crash-report sink — the Android app POSTs its last captured native
    // crash here on next launch so we can review it server-side (storage/app/crash-reports.log).
    // Body in DiagCrashController (2026-11-04): the cached closure crashed PHP on every report.
    Route::post('/diag/crash', [\App\Http\Controllers\Api\DiagCrashController::class, 'store'])->middleware('throttle:30,1,r-grp-512');

    // Device-side scroll diagnostics (log only, no email) — lets us SEE the phone's
    // real scroll timeline on a Home navigation, which the desktop browser can't reproduce.
    Route::post('/diag/scroll', function (\Illuminate\Http\Request $r) {
        try { \Illuminate\Support\Facades\Storage::append('scroll-diag.log', json_encode([
            'at' => now()->toDateTimeString(), 'ua' => mb_substr((string) $r->userAgent(), 0, 120),
            'data' => mb_substr((string) $r->input('data'), 0, 4000),
        ])); } catch (\Throwable $e) {}
        return response()->json(['ok' => true]);
    })->middleware('throttle:60,1,r-grp-522');

    // Connection speed as the device saw it (App\Support\NetQuality) - one summary per session.
    Route::post('/diag/net', function (\Illuminate\Http\Request $r) {
        try { \App\Support\NetQuality::record($r); } catch (\Throwable $e) {}
        return response()->json(['ok' => true]);
    })->middleware('throttle:20,1,r-grp-528');

    Route::post('/diag/bio', function (\Illuminate\Http\Request $r) {
        try { \Illuminate\Support\Facades\Storage::append('bio-diag.log', json_encode([
            'at' => now()->toDateTimeString(), 'ua' => mb_substr((string) $r->userAgent(), 0, 120),
            'data' => mb_substr((string) $r->input('data'), 0, 2000),
        ])); } catch (\Throwable $e) {}
        return response()->json(['ok' => true]);
    })->middleware('throttle:120,1,r-grp-536');

    // Native app cold-boot timing (2026-11-01), log only: the inline beacon at the top of
    // dashboard.html / index.html. iPhone has no Safari inspector here, so the app reports itself.
    Route::post('/diag/boot', function (\Illuminate\Http\Request $r) {
        try { \Illuminate\Support\Facades\Storage::append('boot-diag.log', json_encode([
            'at' => now()->toDateTimeString(), 'ua' => mb_substr((string) $r->userAgent(), 0, 200),
            'data' => mb_substr((string) $r->input('data'), 0, 4000),
        ])); } catch (\Throwable $e) {}
        return response()->json(['ok' => true]);
    })->middleware('throttle:60,1,r-grp-boot');

    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    // Public: lets the login page show a scheduled-maintenance notice.
    Route::get('/maintenance/status', [\App\Http\Controllers\Api\MaintenanceController::class, 'show']);
    // Public: the Google Play build numbers the app compares itself with (update prompts, 2026-10-30).
    Route::get('/app/versions', [\App\Http\Controllers\Api\AppVersionController::class, 'show'])->middleware('throttle:120,1,r-app-versions');
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1,r-auth-register');
/* PASSKEYS — public half. Sign-in names no account: the credential does, which is what
   eight accounts sharing three email addresses actually needs. Throttled like the other
   unauthenticated auth routes, because an open verify endpoint is a guessing endpoint. */
Route::post('/auth/passkey/options', [\App\Http\Controllers\Api\PasskeyController::class, 'loginOptions'])->middleware('throttle:120,1,r-auth-passkey-options');
Route::post('/auth/passkey/verify',  [\App\Http\Controllers\Api\PasskeyController::class, 'loginVerify'])->middleware('throttle:30,1,r-auth-passkey-verify');

    Route::post('/auth/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1,r-auth-forgot');
    // The 6-digit code from email / text / call (2026-10-15). 10 tries a minute per IP on
    // top of the 5-wrong-guesses limit each code carries.
    Route::post('/auth/forgot/verify', [AuthController::class, 'verifyResetCode'])->middleware('throttle:10,1,r-auth-forgot-verify');
    Route::post('/auth/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1,r-auth-reset');
    // The reset page checks its link on open, and can email a fresh one (2026-10-23).
    Route::post('/auth/reset/check', [AuthController::class, 'resetCheck'])->middleware('throttle:20,1,r-auth-reset-check');
    Route::post('/auth/reset/resend', [AuthController::class, 'resetResend'])->middleware('throttle:3,1,r-auth-reset-resend');
    Route::post('/auth/set-password', [AuthController::class, 'setPasswordFromToken'])->middleware('throttle:10,1,r-auth-set-password');
    Route::get('/auth/social/providers', [\App\Http\Controllers\Api\SocialAuthController::class, 'providers']);
    // An announcement's attached PDF (2026-10-20). No sign-in: the signed, expiring link
    // is the permission, handed only to the announcement's own recipients.
    Route::get('/announcements/{id}/pdf', [\App\Http\Controllers\Api\AnnouncementController::class, 'pdf'])
        ->whereNumber('id')->name('announcements.pdf')->middleware(['signed', 'throttle:60,1,r-grp-563']);
    // The birthday sing-along cartoon (2026-10-21): what the player shows, by signed link only.
    Route::get('/birthday-video/demo', [\App\Http\Controllers\Api\BirthdayVideoController::class, 'demo'])->middleware('throttle:60,1,r-birthday-video-demo');
    Route::get('/birthday-video/staff/{user}', [\App\Http\Controllers\Api\BirthdayVideoController::class, 'staff'])
        ->whereNumber('user')->name('birthday.video.staff')->middleware(['signed', 'throttle:60,1,r-grp-567']);
    Route::get('/birthday-video/{child}', [\App\Http\Controllers\Api\BirthdayVideoController::class, 'show'])
        ->whereNumber('child')->name('birthday.video')->middleware(['signed', 'throttle:60,1,r-grp-569']);
    Route::get('/auth/social/{provider}/redirect', [\App\Http\Controllers\Api\SocialAuthController::class, 'redirect'])->where('provider', '[a-z]+')->middleware('throttle:20,1,r-auth-social-x-redirect');
    Route::get('/auth/social/{provider}/callback', [\App\Http\Controllers\Api\SocialAuthController::class, 'callback'])->where('provider', '[a-z]+')->middleware('throttle:20,1,r-auth-social-x-callback');
    // The phone app trades its one-time code for a token (SocialAuthController::appExchange).
    Route::post('/auth/social/app-exchange', [\App\Http\Controllers\Api\SocialAuthController::class, 'appExchange'])->middleware('throttle:20,1,r-auth-social-app-exchange');
    // v22p93: email open-tracking pixel (public, no auth)
    Route::get('/e/o/{token}', [\App\Http\Controllers\Api\PlatformController::class, 'trackOpen']);

    // ─── Public signup (rate-limited, v7) ───
    Route::post('/signup/centre', [SignupController::class, 'signup'])->middleware('throttle:3,60,r-signup-centre');

    // v22p2.1: public invite-code parent signup
    Route::get('/signup/invitation/{code}', [InvitationController::class, 'probe'])->middleware('throttle:30,1,r-signup-invitation-x');
    Route::post('/signup/by-code', [SignupController::class, 'byCode'])->middleware('throttle:5,60,r-signup-by-code');


Route::any('/login', function () { return response()->json(['message' => 'Unauthenticated. Please sign in via the app.'], 401); })->name('login');
// Throttled (2026-11-05 leak sweep): public, and it used to name any agency by number.
Route::get('/branding', [BrandingController::class, 'show'])->middleware('throttle:240,1,branding');
// A white-label app's brand before its first paint (index.html <head>, 2026-10-30).
Route::get('/branding/boot.js', [BrandingController::class, 'bootJs'])->middleware('throttle:120,1,brandboot');
Route::get('/marketing-site/config', [\App\Http\Controllers\Api\MarketingSiteController::class, 'publicConfig']);
Route::post('/marketing-site/lead', [\App\Http\Controllers\Api\MarketingSiteController::class, 'submitLead'])->middleware([\App\Http\Middleware\RejectTestVisitors::class, 'throttle:5,1,mklead']);
// Website booking wizard (2026-09-29): month availability + book a meeting
Route::get('/marketing-site/booking/availability', [\App\Http\Controllers\Api\MarketingBookingController::class, 'availability'])->middleware('throttle:60,1,mkbooka');
Route::post('/marketing-site/booking', [\App\Http\Controllers\Api\MarketingBookingController::class, 'store'])->middleware([\App\Http\Middleware\RejectTestVisitors::class, 'throttle:6,60,mkbook']);
Route::get('/marketing-site/booking/{id}/calendar.ics', [\App\Http\Controllers\Api\MarketingBookingController::class, 'calendar'])->whereNumber('id')->middleware('throttle:60,1,mkbookics');
// Calendar sync (2026-11-03, cal1103): a person's private subscription feed. No sign-in (calendar apps cannot);
// the 256-bit token is the key. CalendarFeedController also rate-limits per link and per IP.
Route::match(['GET', 'HEAD'], '/cal/{token}.ics', [\App\Http\Controllers\Api\CalendarFeedController::class, 'feed'])->where('token', '[A-Za-z0-9_-]{40,64}')->middleware('throttle:240,1,calfeed');
// The prospect intake form, signed per booking (2026-10-23).
Route::get('/marketing-site/intake/{id}', [\App\Http\Controllers\Api\ProspectIntakeController::class, 'show'])->whereNumber('id')->middleware('throttle:60,1,mkintake');
Route::post('/marketing-site/intake/{id}', [\App\Http\Controllers\Api\ProspectIntakeController::class, 'submit'])->whereNumber('id')->middleware('throttle:10,1,mkintakep');
// Sales campaign email links, no sign-in: a 40-character token per recipient (2026-10-26). See SalesCampaignPublicController.
// Cold reach emails: the open pixel and unsubscribe (2026-10-30).
Route::get('/c/po/{token}.gif', [\App\Http\Controllers\Api\SalesProspectController::class, 'pixel'])->where('token', '[a-z0-9]{40}')->middleware('throttle:240,1,kcpopen');
Route::match(['get', 'post'], '/c/pu/{token}', [\App\Http\Controllers\Api\SalesProspectController::class, 'unsubscribe'])->where('token', '[a-z0-9]{40}')->middleware('throttle:60,1,kcpunsub');
Route::get('/c/o/{token}.gif', [\App\Http\Controllers\Api\SalesCampaignPublicController::class, 'open'])->where('token', '[a-z0-9]{40}')->middleware('throttle:240,1,kccopen');
Route::get('/c/l/{token}/{key}', [\App\Http\Controllers\Api\SalesCampaignPublicController::class, 'click'])->where('token', '[a-z0-9]{40}')->where('key', '[a-z]{2,12}')->middleware('throttle:120,1,kcclick');
Route::match(['get', 'post'], '/c/i/{token}', [\App\Http\Controllers\Api\SalesCampaignPublicController::class, 'interested'])->where('token', '[a-z0-9]{40}')->middleware('throttle:60,1,kccint');
Route::match(['get', 'post'], '/c/u/{token}', [\App\Http\Controllers\Api\SalesCampaignPublicController::class, 'unsubscribe'])->where('token', '[a-z0-9]{40}')->middleware('throttle:60,1,kccunsub');
// Decide a website meeting request from the sales email, no sign-in (signed per booking + action, 2026-10-23).
Route::get('/marketing-site/booking-action/{id}', [\App\Http\Controllers\Api\BookingEmailActionController::class, 'show'])->whereNumber('id')->middleware('throttle:60,1,mkbkact');
Route::post('/marketing-site/booking-action/{id}', [\App\Http\Controllers\Api\BookingEmailActionController::class, 'submit'])->whereNumber('id')->middleware('throttle:10,1,mkbkactp');
Route::post('/marketing-site/hit', [\App\Http\Controllers\Api\MarketingSiteController::class, 'recordHit'])->middleware('throttle:60,1,mkhit');
Route::post('/marketing-site/chat', [\App\Http\Controllers\Api\MarketingSiteController::class, 'logChat'])->middleware([\App\Http\Middleware\RejectTestVisitors::class, 'throttle:45,1,mkchat']);
// A finished conversation goes to sales as a transcript. Throttled hard: one chat
// ends once, and this one sends mail.
// The website chat answered by Claude (2026-10-30, MarketingAiChatController): Maya asks this first, falls back to her scripted answers.
// Job applications from www.kiddietrac.com/careers: CV + cover letter (2026-10-30, CareersController).
Route::post('/marketing-site/careers/apply', [\App\Http\Controllers\Api\CareersController::class, 'apply'])->middleware('throttle:5,10,careers');
Route::post('/marketing-site/chat/ai', [\App\Http\Controllers\Api\MarketingAiChatController::class, 'reply'])->middleware([\App\Http\Middleware\RejectTestVisitors::class, 'throttle:20,1,mkai']);
// The website's live phone demo: a 2-hour login to a fictional Brightwood account (2026-10-31, App\Support\PublicDemo).
Route::post('/marketing-site/demo-session', [\App\Http\Controllers\Api\WebDemoController::class, 'start'])->middleware('throttle:10,1,webdemo');
Route::post('/marketing-site/chat/end', [\App\Http\Controllers\Api\MarketingSiteController::class, 'endChat'])->middleware('throttle:6,1,mkchatend');
// Each public marketing route has its OWN throttle prefix. Without one, Laravel keys every
// throttle by IP alone, so page hits + chat polls used up the lead form's 5/min and the
// transcript's 6/min, and those were refused with 429 mid-visit.
// Live website chat: the page polls for the team's replies + typing, and can ask for a person.
Route::get('/marketing-site/chat/poll', [\App\Http\Controllers\Api\WebChatController::class, 'poll'])->middleware('throttle:90,1,mkpoll');
Route::post('/marketing-site/chat/human', [\App\Http\Controllers\Api\WebChatController::class, 'wantHuman'])->middleware([\App\Http\Middleware\RejectTestVisitors::class, 'throttle:6,1,mkhuman']);
// Marketing-site auto-translation: the page reports text it has no FR/ES for; translated once, served after.
Route::get('/marketing-site/i18n/{lang}/extra', [\App\Http\Controllers\Api\MarketingTranslationController::class, 'extra'])->where('lang', 'fr|es|hi')->middleware('throttle:120,1,mki18nx');
Route::post('/marketing-site/i18n/missing', [\App\Http\Controllers\Api\MarketingTranslationController::class, 'missing'])->middleware('throttle:20,1,mki18n');
Route::get('/marketing-site/unsubscribe', [\App\Http\Controllers\Api\MarketingSiteController::class, 'unsubscribe']);
Route::post('/marketing-site/subscribe', [\App\Http\Controllers\Api\MarketingSiteController::class, 'subscribeNewsletter'])->middleware('throttle:5,10,mknews');
Route::post('/marketing-site/contact', [\App\Http\Controllers\Api\MarketingSiteController::class, 'submitContact'])->middleware([\App\Http\Middleware\RejectTestVisitors::class, 'throttle:5,10,mkcontact']);
// Website support tickets + toll-free voicemail (2026-09-29)
Route::post('/marketing-site/support', [\App\Http\Controllers\Api\WebsiteSupportController::class, 'submitTicket'])->middleware('throttle:5,10,mksupport');
/* GET as well as POST (2026-10-22): Telnyx fetched the call-menu step with GET although the
   script asked for POST, and a POST-only route answered 405 — callers heard "an application
   error has occurred". Parameters arrive in the query string either way ($request->input). */
Route::match(['get', 'post'], '/voice/voicemail/{token}', [\App\Http\Controllers\Api\WebsiteSupportController::class, 'voicemail'])->where('token', '[A-Za-z0-9]{24,64}')->middleware('throttle:60,1,voicemail');
// Toll-free call menu (2026-10-22): Telnyx asks these during a call. App\Support\PhoneMenu.
Route::match(['get', 'post'], '/voice/ivr/{token}/choose',    [\App\Http\Controllers\Api\PhoneMenuController::class, 'choose'])->where('token', '[A-Za-z0-9]{24,64}')->middleware('throttle:120,1,ivr');
Route::match(['get', 'post'], '/voice/ivr/{token}/screen',    [\App\Http\Controllers\Api\PhoneMenuController::class, 'screen'])->where('token', '[A-Za-z0-9]{24,64}')->middleware('throttle:120,1,ivr');
Route::match(['get', 'post'], '/voice/ivr/{token}/screen-ok', [\App\Http\Controllers\Api\PhoneMenuController::class, 'screenOk'])->where('token', '[A-Za-z0-9]{24,64}')->middleware('throttle:120,1,ivr');
// The dialer's calls (Telnyx TeXML → us), each protected by its own token (2026-10-23).
Route::match(['get', 'post'], '/voice/dialer/hook/{id}/{token?}/{step?}', [\App\Http\Controllers\Api\DialerController::class, 'hook'])
    ->where(['id' => '[0-9]+|fallback', 'token' => '[A-Za-z0-9]{0,64}', 'step' => '[a-z]{0,12}'])->middleware('throttle:240,1,dialer');
Route::match(['get', 'post'], '/voice/ivr/{token}/dialed',    [\App\Http\Controllers\Api\PhoneMenuController::class, 'dialed'])->where('token', '[A-Za-z0-9]{24,64}')->middleware('throttle:120,1,ivr');
// A held caller put through to the app that answered the ringing push (2026-10-23).
Route::match(['get', 'post'], '/voice/ivr/{token}/connect',   [\App\Http\Controllers\Api\PhoneMenuController::class, 'connect'])->where('token', '[A-Za-z0-9]{24,64}')->middleware('throttle:120,1,ivr');
// A temporary pickup person's own pass (2026-11-24, App\Support\TempPickups): the link is the pass, no account.
Route::post('/pickup-pass/view', [\App\Http\Controllers\Api\TempPickupController::class, 'holderView'])->middleware('throttle:30,1,r-tpass');
Route::post('/pickup-pass/sign-out', [\App\Http\Controllers\Api\TempPickupController::class, 'holderSignOut'])->middleware('throttle:10,1,r-tpass-out');
// Parents call in to report absent / late (2026-11-24, App\Support\ParentLine): every call starts here.
Route::match(['get', 'post'], '/voice/ivr/{token}/start', [\App\Http\Controllers\Api\ParentLineController::class, 'start'])->where('token', '[A-Za-z0-9]{24,64}')->middleware('throttle:120,1,ivr');
Route::match(['get', 'post'], '/voice/ivr/{token}/parent/{step}', [\App\Http\Controllers\Api\ParentLineController::class, 'step'])->where(['token' => '[A-Za-z0-9]{24,64}', 'step' => '[a-z]{2,12}'])->middleware('throttle:240,1,ivr');
// An answered incoming call's recording (2026-10-23, App\Support\CallRecords).
Route::match(['get', 'post'], '/voice/ivr/{token}/recorded',  [\App\Http\Controllers\Api\PhoneMenuController::class, 'recorded'])->where('token', '[A-Za-z0-9]{24,64}')->middleware('throttle:120,1,ivr');
Route::post('/stripe/webhook', [StripeBillingController::class, 'webhook'])->middleware('throttle:600,1,r-stripe-webhook');

// PUBLIC — Zum Rails settlement callbacks. No auth: Zum calls this, not a user. Guarded
// by a shared secret compared inside the controller. This is the ONLY thing that marks a
// Zum payment settled; the create response means "instruction accepted" and nothing more.
/* An uploaded file, served only against a signature we issued. Public in the
   sense that it carries no bearer token — these URLs go in <img src> and
   window.open, which cannot send a header — but useless without the signature,
   and dead once it expires. See App\Support\ProtectedMedia. */
Route::get('/media/f', [\App\Http\Controllers\Api\MediaFileController::class, 'show'])
    ->name('media.file')
    /* NormalizeEntityQuery runs BEFORE `signed`: it repairs `amp;p` / `amp;signature`
       back to `p` / `signature` for emails ALREADY DELIVERED, whose <img src> carried
       an HTML-escaped query string. Without it those images stay broken forever, since
       the mail cannot be reissued. New emails use the path-only /media/e route. */
    ->middleware([\App\Http\Middleware\NormalizeEntityQuery::class, 'signed']);

/* The same file, for EMAIL. No query string, so nothing an HTML escaper can break:
   Blade turns the `&` of a signed query string into `&amp;`, and a mail client that
   fetches the src without decoding it gets a 403 and draws a broken image. The token
   carries its own HMAC and expiry and is verified in the controller, so this route
   deliberately does not use the `signed` middleware. */
Route::get('/media/e/{token}', [\App\Http\Controllers\Api\MediaFileController::class, 'email'])
    ->where('token', '[A-Za-z0-9\-_\.]+')
    ->name('media.email');

Route::post('/zumrails/webhook', [\App\Http\Controllers\Api\ZumWebhookController::class, 'handle'])->middleware('throttle:600,1,r-zumrails-webhook');

// PUBLIC — Twilio inbound SMS: STOP, START and HELP. No auth (Twilio holds no session);
// the request is Twilio-signature-verified inside, and refused outright when TWILIO_TOKEN
// is unset, since an unverifiable endpoint here would let anyone opt a number in or out.
Route::post('/sms/inbound', [\App\Http\Controllers\Api\SmsConsentController::class, 'inbound'])->middleware('throttle:120,1,r-sms-inbound');

/* PUBLIC — Telnyx inbound SMS and call events (2026-09-10). No auth for the same
   reason as the Twilio route above; verified instead with the agency's own Ed25519
   public key over the RAW body, and refused outright when no key is stored.

   THE AGENCY IS IN THE PATH because a Telnyx webhook does not say whose account it
   came from, and the signing key has to be chosen before anything in the body can be
   trusted. The id is not a secret and is not what authorises anything — the
   signature is, and for a call the row must ALSO belong to the agency named here.

   Throttled higher than the SMS route: a single announcement to one room produces
   four webhooks per recipient (initiated, answered, speak.ended, hangup), so thirty
   families is a burst of a hundred and twenty in under a minute. */
Route::post('/sms/telnyx/inbound/{agency}', [\App\Http\Controllers\Api\SmsConsentController::class, 'telnyxInbound'])
    ->where('agency', '[0-9]+')->middleware('throttle:300,1,r-grp-696');
Route::post('/voice/telnyx/webhook/{agency}', [\App\Http\Controllers\Api\VoiceController::class, 'webhook'])
    ->where('agency', '[0-9]+')->middleware('throttle:1200,1,r-grp-698');
// Signed (session-less) e-document view — a mobile WebView can open this URL directly.
Route::get('/edoc/{id}/view', [\App\Http\Controllers\Api\EDocumentController::class, 'signedStream'])->name('edoc.signed')->middleware('signed');
Route::get('/report-card/{id}/view', [\App\Http\Controllers\Api\ParentReportCardController::class, 'signedPdf'])->name('rc.signed')->middleware('signed');

/* The emergency card, openable in the device's own browser so it can be printed —
   the app's web view has no window.print(). Signed and five minutes long; minted by
   the authenticated print-link endpoint, which runs the card's own centre check. */
Route::get('/print/emergency-card/{child}', [\App\Http\Controllers\Api\EmergencyCardController::class, 'signedForChild'])
    ->name('emergency.card.signed')->middleware('signed')->whereNumber('child');
// One-tap time-off decisions from the approver's email. The GET only DISPLAYS —
// mail scanners fetch every link in a message, so a GET that decided anything would
// be decided by the scanner before the director saw it. The POST does the work.
/* Parent day-feedback from the daily summary email. Signed, no login, write-only,
   14-day expiry. The GET renders a form and changes NOTHING — mail scanners fetch every
   link before delivery, so a GET that mutated would file feedback nobody wrote. */
Route::get ('/feedback/day/{child}', [\App\Http\Controllers\Api\ParentFeedbackController::class, 'form'])
    ->name('parent.feedback.form')->middleware('signed')->whereNumber('child');
Route::post('/feedback/day/{child}', [\App\Http\Controllers\Api\ParentFeedbackController::class, 'submit'])
    ->middleware('signed')->whereNumber('child');

/* FILL AND SIGN A FORM FROM A TEMPORARY LINK — no login, same shape as the two above.
   A package email carries one of these per form, so a family can complete their paperwork
   without first getting a password working. The signature covers {form} and {u}, so a link
   cannot be edited into somebody else's form. See SignedFormController. */
/* Send the files a centre asked for, with no password — the same device as the
   fill-and-sign link. Signed, expiring, and write-only: it can add a file to its own
   request and can never read one back. */
Route::get ('/files/upload/{req}/{u}', [\App\Http\Controllers\Api\SignedFileUploadController::class, 'page'])
    ->middleware('signed')->name('files.upload')->whereNumber('req')->whereNumber('u');
Route::post('/files/upload/{req}/{u}', [\App\Http\Controllers\Api\SignedFileUploadController::class, 'put'])
    ->middleware('signed')->name('files.upload.put')->whereNumber('req')->whereNumber('u');

// The Accept / Maybe / Decline buttons in a meeting invitation: signed per attendee, no sign-in (2026-10-25).
Route::get ('/meetings/rsvp/{attendee}/{response}', [\App\Http\Controllers\Api\MeetingController::class, 'rsvp'])->whereNumber('attendee')->where('response', 'accepted|tentative|declined')->middleware('throttle:60,1,r-grp-731');
Route::get ('/forms/fill/{form}/{u}',       [\App\Http\Controllers\Api\SignedFormController::class, 'page'])->name('forms.fill')->middleware('signed')->whereNumber('form')->whereNumber('u');
Route::post('/forms/fill/{form}/{u}',       [\App\Http\Controllers\Api\SignedFormController::class, 'submit'])->name('forms.fill.submit')->middleware('signed')->whereNumber('form')->whereNumber('u');
Route::post('/forms/fill/{form}/{u}/draft', [\App\Http\Controllers\Api\SignedFormController::class, 'draft'])->name('forms.fill.draft')->middleware('signed')->whereNumber('form')->whereNumber('u');

/* THE TEXT-ALERT CONSENT PAGE. No login, because the people least likely to have a
   working password are exactly the ones who have never set up their account - and this
   is the question we need to ask everybody. The signature names ONE user and expires, so
   a forwarded email cannot answer on somebody else's behalf.

   NormalizeEntityQuery runs first for the same reason as the media route above: Blade
   writes the `&` of a signed query string as `&amp;`, and a mail client that follows the
   link without decoding it would otherwise get a 403 on a link that cannot be reissued. */
Route::get ('/sms-consent/{u}', [\App\Http\Controllers\Api\SmsConsentInviteController::class, 'page'])
    ->name('sms.consent.page')
    ->middleware([\App\Http\Middleware\NormalizeEntityQuery::class, 'signed'])->whereNumber('u');
Route::post('/sms-consent/{u}', [\App\Http\Controllers\Api\SmsConsentInviteController::class, 'submit'])
    ->middleware([\App\Http\Middleware\NormalizeEntityQuery::class, 'signed'])->whereNumber('u');

Route::get ('/time-off/act/{id}', [\App\Http\Controllers\Api\TimeOffController::class, 'actPage'])->name('timeoff.act')->middleware('signed');
Route::post('/time-off/act/{id}', [\App\Http\Controllers\Api\TimeOffController::class, 'actSubmit'])->middleware('signed');

// v22p49 — Public tour booking. Throttled aggressively because the public
// endpoint is the most-likely target for spam — 8 requests per hour per IP.
// Donations: public giving page (2026-09-29). Pledges are throttled per IP.
Route::get('/public/give/{slug}', [\App\Http\Controllers\Api\DonationController::class, 'publicCampaign'])->where('slug', '[a-z0-9-]+')->middleware('throttle:60,1,give');
Route::post('/public/give/{slug}', [\App\Http\Controllers\Api\DonationController::class, 'publicPledge'])->where('slug', '[a-z0-9-]+')->middleware('throttle:6,60,givepledge');
// Event RSVPs: public pages (2026-10-05). Tokens only; each route has its own throttle prefix.
// Prospect NDA (2026-10-09): a prospect with no account reads and signs ONE NDA from a private link.
Route::get ('/public/nda/{token}',      [\App\Http\Controllers\Api\ProspectNdaController::class, 'publicShow'])->where('token', '[A-Za-z0-9]{20,64}')->middleware('throttle:60,1,ndav');
Route::post('/public/nda/{token}/sign', [\App\Http\Controllers\Api\ProspectNdaController::class, 'publicSign'])->where('token', '[A-Za-z0-9]{20,64}')->middleware('throttle:8,10,ndas');
Route::get ('/public/nda/{token}/pdf',  [\App\Http\Controllers\Api\ProspectNdaController::class, 'publicPdf'])->where('token', '[A-Za-z0-9]{20,64}')->middleware('throttle:30,1,ndap');
// Proposal e-signing (2026-10-16): the prospect's side, token-only (proposal.html?t=…).
Route::get ('/public/proposal/{token}',         [\App\Http\Controllers\Api\ProposalSignController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:60,1,propv');
Route::post('/public/proposal/{token}/sign',    [\App\Http\Controllers\Api\ProposalSignController::class, 'sign'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:8,10,props');
Route::post('/public/proposal/{token}/decline', [\App\Http\Controllers\Api\ProposalSignController::class, 'decline'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:8,10,propd');
// Proposal email opened (2026-10-23, App\Support\ProposalTracking): a 1x1 image, signed per proposal.
Route::get ('/proposal-open/{quote}/{sig}.gif', function (\Illuminate\Http\Request $r, int $quote, string $sig) {
    if (hash_equals(\App\Support\ProposalTracking::sig($quote), $sig)) {
        \App\Support\ProposalTracking::open($quote, $r->query('k') === 'f' ? 'followup' : 'email', $r);
    }
    return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, ['Content-Type' => 'image/gif',
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0', 'Pragma' => 'no-cache', 'X-Robots-Tag' => 'noindex']);
})->where(['quote' => '[0-9]+', 'sig' => '[a-f0-9]{24}'])->middleware('throttle:60,1,propo');
Route::get ('/public/proposal/{token}/pdf',     [\App\Http\Controllers\Api\ProposalSignController::class, 'pdf'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1,propp');
/* "Enter the real time" from the automatic sign-out / clock-out emails (2026-10-22). Public:
   the token is the credential (App\Support\AutoTimeFix). */
Route::get ('/public/time-fix/{token}',  [\App\Http\Controllers\Api\AutoTimeFixController::class, 'show'])->where('token', '[A-Za-z0-9]{30,64}')->middleware('throttle:60,1,timefixget');
Route::post('/public/time-fix/{token}',  [\App\Http\Controllers\Api\AutoTimeFixController::class, 'submit'])->where('token', '[A-Za-z0-9]{30,64}')->middleware('throttle:10,10,timefixpost');
Route::get ('/public/rsvp/event/{token}',  [\App\Http\Controllers\Api\RsvpController::class, 'publicEvent'])->where('token', '[A-Za-z0-9]{20,48}')->middleware('throttle:60,1,rsvpget');
Route::post('/public/rsvp/event/{token}',  [\App\Http\Controllers\Api\RsvpController::class, 'submitEvent'])->where('token', '[A-Za-z0-9]{20,48}')->middleware('throttle:10,10,rsvpsub');
Route::get ('/public/rsvp/invite/{token}', [\App\Http\Controllers\Api\RsvpController::class, 'publicInvite'])->where('token', '[A-Za-z0-9]{20,48}')->middleware('throttle:60,1,rsvpgeti');
Route::post('/public/rsvp/invite/{token}', [\App\Http\Controllers\Api\RsvpController::class, 'submitInvite'])->where('token', '[A-Za-z0-9]{20,48}')->middleware('throttle:10,10,rsvpsubi');
Route::get ('/public/rsvp/optout/{token}', [\App\Http\Controllers\Api\RsvpController::class, 'optOutInfo'])->where('token', '[A-Za-z0-9]{20,48}')->middleware('throttle:60,1,rsvpoi');
Route::post('/public/rsvp/optout/{token}', [\App\Http\Controllers\Api\RsvpController::class, 'optOut'])->where('token', '[A-Za-z0-9]{20,48}')->middleware('throttle:20,10,rsvpout');
// Google sends the browser back here after "Connect Gmail"; the signed single-use state is the auth.
Route::get ('/rsvp/google/callback', [\App\Http\Controllers\Api\RsvpController::class, 'googleCallback'])->middleware('throttle:30,1,rsvpgcb');
Route::post('/public/tours', [\App\Http\Controllers\Api\CareController::class, 'publicTourBook'])
    ->middleware('throttle:8,60,r-grp-789');

    

    // v14 fix: push/public-key must be publicly accessible (called before subscription)
    Route::get('/push/public-key', [PushController::class, 'publicKey']);


    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/agency/centre-term', [\App\Http\Controllers\Api\AgencyTermController::class, 'show']);
        Route::get('/agency/learning-framework', [\App\Http\Controllers\Api\LearningFrameworkController::class, 'show']);

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
            // Outbound email configuration (sendmail vs Microsoft Graph) — superadmin only.
            // Usage & spend per agency + thresholds for the superadmin weekly summary (2026-10-20).
            Route::get ('/usage-spend',        [\App\Http\Controllers\Api\PlatformUsageController::class, 'show']);
            Route::put ('/usage-settings',     [\App\Http\Controllers\Api\PlatformUsageController::class, 'save']);
            Route::post('/usage-summary/test', [\App\Http\Controllers\Api\PlatformUsageController::class, 'test'])->middleware('throttle:6,1,r-usage-summary-test');
            // Platform → AI usage: the Claude key + model, live state, credits estimate, full stats (2026-10-28).
            Route::get   ('/ai',             [\App\Http\Controllers\Api\PlatformAiController::class, 'show']);
            Route::put   ('/ai/settings',    [\App\Http\Controllers\Api\PlatformAiController::class, 'save'])->middleware('throttle:10,1,r-grp-825');
            Route::post  ('/ai/test',        [\App\Http\Controllers\Api\PlatformAiController::class, 'test'])->middleware('throttle:10,1,r-grp-826');
            Route::put   ('/ai/off',         [\App\Http\Controllers\Api\PlatformAiController::class, 'saveOff']);
            Route::post  ('/ai/topups',      [\App\Http\Controllers\Api\PlatformAiController::class, 'addTopup']);
            Route::delete('/ai/topups/{id}', [\App\Http\Controllers\Api\PlatformAiController::class, 'removeTopup'])->where('id', '[a-f0-9]{8}');
            // SMS Incremental: monthly text & call allowance, approved overage onto the next invoice (2026-10-28).
            Route::get   ('/messaging-overage',          [\App\Http\Controllers\Api\PlatformUsageController::class, 'overage']);
            Route::put   ('/messaging-overage/settings', [\App\Http\Controllers\Api\PlatformUsageController::class, 'overageSettings']);
            Route::post  ('/messaging-overage/charge',   [\App\Http\Controllers\Api\PlatformUsageController::class, 'overageCharge'])->middleware('throttle:30,1,r-grp-833');
            Route::post  ('/messaging-overage/waive',    [\App\Http\Controllers\Api\PlatformUsageController::class, 'overageWaive'])->middleware('throttle:30,1,r-grp-834');
            Route::delete('/messaging-overage/{id}',     [\App\Http\Controllers\Api\PlatformUsageController::class, 'overageUndo'])->whereNumber('id');
            Route::get ('/mail-settings',      [\App\Http\Controllers\Api\MailSettingsController::class, 'show']);
            Route::put ('/mail-settings',      [\App\Http\Controllers\Api\MailSettingsController::class, 'save']);
            Route::post('/mail-settings/test', [\App\Http\Controllers\Api\MailSettingsController::class, 'sendTest']);
            // The Google client behind RSVP "Connect Gmail" (saved here, not in .env).
            Route::put ('/mail-settings/google',       [\App\Http\Controllers\Api\MailSettingsController::class, 'saveGoogle']);
            Route::post('/mail-settings/google/check', [\App\Http\Controllers\Api\MailSettingsController::class, 'checkGoogle'])->middleware('throttle:10,1,ggchk');
            // The Microsoft 365 app behind mailbox sign-in and "Sign in with Microsoft" (saved here, not in .env; 2026-10-26).
            Route::put ('/mail-settings/microsoft',       [\App\Http\Controllers\Api\MailSettingsController::class, 'saveMicrosoft']);
            Route::post('/mail-settings/microsoft/check', [\App\Http\Controllers\Api\MailSettingsController::class, 'checkMicrosoft'])->middleware('throttle:10,1,mschk');
            // Scheduled maintenance / downtime (super-admin).
            Route::get ('/maintenance',            [\App\Http\Controllers\Api\MaintenanceController::class, 'show']);
            Route::post('/maintenance',            [\App\Http\Controllers\Api\MaintenanceController::class, 'save']);
            Route::post('/maintenance/test',       [\App\Http\Controllers\Api\MaintenanceController::class, 'sendTest']);
            // Mobile app updates: the latest / minimum Google Play build (2026-10-30).
            Route::get ('/app-versions',           [\App\Http\Controllers\Api\AppVersionController::class, 'settings']);
            Route::put ('/app-versions',           [\App\Http\Controllers\Api\AppVersionController::class, 'save']);
            Route::post('/maintenance/notify-all', [\App\Http\Controllers\Api\MaintenanceController::class, 'notifyAll']);
            Route::get('/directory', [\App\Http\Controllers\Api\ImpersonationController::class, 'directory']);
            Route::post('/impersonate/{user}', [\App\Http\Controllers\Api\ImpersonationController::class, 'start'])->where('user', '[0-9]+');
            Route::get('/social-config', [\App\Http\Controllers\Api\SocialAuthController::class, 'configGet']);
            Route::post('/social-config', [\App\Http\Controllers\Api\SocialAuthController::class, 'configSave']);
            Route::get('/security-alerts', [\App\Http\Controllers\Api\SecurityController::class, 'alerts']);
            // Server health: CPU, memory, disk, database, jobs, errors, certificates, backups (2026-10-22).
            Route::get('/server-health', [\App\Http\Controllers\Api\ServerHealthController::class, 'show']);
            // Platform -> Careers: website job applications (2026-10-30).
            Route::get('/careers', [\App\Http\Controllers\Api\CareersController::class, 'index']);
            Route::get('/careers/stats', [\App\Http\Controllers\Api\CareersController::class, 'stats']); // Insights tab (2026-11-02)
            Route::get('/careers/{id}/file/{kind}', [\App\Http\Controllers\Api\CareersController::class, 'file'])->whereNumber('id')->where('kind', 'resume|cover');
            // Read a resume / cover letter in the portal: a PDF or picture as itself, a Word file as a page (2026-11-04).
            Route::get('/careers/{id}/view/{kind}', [\App\Http\Controllers\Api\CareersController::class, 'view'])->whereNumber('id')->where('kind', 'resume|cover');
            Route::patch('/careers/{id}', [\App\Http\Controllers\Api\CareersController::class, 'update'])->whereNumber('id');
            // Outcomes (2026-11-24): book a meeting / keep on file 24 months / decline politely - each emails the applicant.
            Route::post('/careers/{id}/preview', [\App\Http\Controllers\Api\CareersController::class, 'preview'])->whereNumber('id')->middleware('throttle:120,1,careers-prev');
            Route::post('/careers/{id}/meeting', [\App\Http\Controllers\Api\CareersController::class, 'meeting'])->whereNumber('id')->middleware('throttle:20,1,careers-out');
            Route::post('/careers/{id}/keep', [\App\Http\Controllers\Api\CareersController::class, 'keep'])->whereNumber('id')->middleware('throttle:20,1,careers-out');
            Route::post('/careers/{id}/decline', [\App\Http\Controllers\Api\CareersController::class, 'decline'])->whereNumber('id')->middleware('throttle:20,1,careers-out');
            // The Cloudways server through Cloudways' API v2 (read-only access token).
            Route::get   ('/server-health/cloudways', [\App\Http\Controllers\Api\ServerHealthController::class, 'cloudways']);
            Route::put   ('/server-health/cloudways', [\App\Http\Controllers\Api\ServerHealthController::class, 'saveCloudways'])->middleware('throttle:10,1,r-grp-873');
            Route::delete('/server-health/cloudways', [\App\Http\Controllers\Api\ServerHealthController::class, 'disconnectCloudways']);
            Route::post('/security-alerts/{id}/resolve', [\App\Http\Controllers\Api\SecurityController::class, 'resolveAlert'])->where('id', '[0-9]+');
            /* Acknowledge everything open, then clear what has been acknowledged. Two
               separate acts on purpose - see clearAlerts(): an unread security warning
               must never be one click from gone. */
            Route::post('/security-alerts/resolve-all', [\App\Http\Controllers\Api\SecurityController::class, 'resolveAllAlerts']);
            Route::post('/security-alerts/clear',       [\App\Http\Controllers\Api\SecurityController::class, 'clearAlerts']);
            // SOC 2 readiness: Settings -> Compliance evidence (2026-09-29)
            Route::get ('/compliance-evidence', [\App\Http\Controllers\Api\ComplianceEvidenceController::class, 'summary']);
            Route::get ('/compliance-evidence/access-review.csv', [\App\Http\Controllers\Api\ComplianceEvidenceController::class, 'accessReviewCsv']);
            Route::get ('/compliance-evidence/security-events.csv', [\App\Http\Controllers\Api\ComplianceEvidenceController::class, 'authEventsCsv']);
            Route::get ('/compliance-evidence/backups.csv', [\App\Http\Controllers\Api\ComplianceEvidenceController::class, 'backupsCsv']);
            Route::get ('/compliance-evidence/schedule.txt', [\App\Http\Controllers\Api\ComplianceEvidenceController::class, 'scheduleTxt']);
            Route::get ('/compliance-evidence/changes.txt', [\App\Http\Controllers\Api\ComplianceEvidenceController::class, 'changesTxt']);
            Route::get ('/compliance-evidence/documents/{file}', [\App\Http\Controllers\Api\ComplianceEvidenceController::class, 'document'])->where('file', '[0-9a-z-]+\.md');
            Route::get ('/compliance-evidence/access-reviews', [\App\Http\Controllers\Api\ComplianceEvidenceController::class, 'reviews']);
            Route::post('/compliance-evidence/access-reviews', [\App\Http\Controllers\Api\ComplianceEvidenceController::class, 'recordReview']);
            Route::get('/agencies', [\App\Http\Controllers\Api\PlatformController::class, 'listAgencies']);
            Route::post('/agencies', [\App\Http\Controllers\Api\PlatformController::class, 'createAgency']);
            Route::patch('/agencies/{agency}', [\App\Http\Controllers\Api\PlatformController::class, 'updateAgency']);
            Route::post('/agencies/{agency}/suspend', [\App\Http\Controllers\Api\PlatformController::class, 'suspendAgency']);
            Route::post('/agencies/{agency}/resume', [\App\Http\Controllers\Api\PlatformController::class, 'resumeAgency']);
            Route::post('/agencies/{agency}/resend-invite', [\App\Http\Controllers\Api\PlatformController::class, 'resendInvite']);
            Route::get('/email-logs', [\App\Http\Controllers\Api\PlatformController::class, 'emailLogs']);
            Route::get('/email-logs/{id}/preview', [\App\Http\Controllers\Api\PlatformController::class, 'emailPreview']);
            Route::get('/email-logs/{id}/attachments/{aid}', [\App\Http\Controllers\Api\PlatformController::class, 'emailAttachment'])->whereNumber('id')->whereNumber('aid');
            Route::post('/email-logs/{id}/resend', [\App\Http\Controllers\Api\PlatformController::class, 'emailResend']);
            Route::get('/crash-reports', [\App\Http\Controllers\Api\PlatformController::class, 'crashReports']);
            Route::get('/marketing-site', [\App\Http\Controllers\Api\MarketingSiteController::class, 'get']);
            Route::put('/marketing-site', [\App\Http\Controllers\Api\MarketingSiteController::class, 'save']);
            Route::get('/marketing-site/leads', [\App\Http\Controllers\Api\MarketingSiteController::class, 'leads']);
            // Website subscribers. Platform-level, like the site itself.
            Route::get   ('/site-subscribers',                  [\App\Http\Controllers\Api\SiteSubscriberController::class, 'index']);
            Route::post  ('/site-subscribers',                  [\App\Http\Controllers\Api\SiteSubscriberController::class, 'store']);
            Route::post  ('/site-subscribers/suppress',          [\App\Http\Controllers\Api\SiteSubscriberController::class, 'suppress']);
            Route::get   ('/site-subscribers/status',            [\App\Http\Controllers\Api\SiteSubscriberController::class, 'status']);   // email client pill (2026-11-09)
            Route::post  ('/site-subscribers/{id}/unsubscribe',  [\App\Http\Controllers\Api\SiteSubscriberController::class, 'unsubscribe'])->where('id','[0-9]+');
            Route::post  ('/site-subscribers/{id}/resubscribe',  [\App\Http\Controllers\Api\SiteSubscriberController::class, 'resubscribe'])->where('id','[0-9]+');
            Route::delete('/site-subscribers/{id}',              [\App\Http\Controllers\Api\SiteSubscriberController::class, 'destroy'])->where('id','[0-9]+');
            Route::post('/marketing-site/leads/delete', [\App\Http\Controllers\Api\MarketingSiteController::class, 'deleteLead']);
            Route::post('/marketing-site/leads/update', [\App\Http\Controllers\Api\MarketingSiteController::class, 'updateLead']);
            Route::post('/marketing-site/leads', [\App\Http\Controllers\Api\MarketingSiteController::class, 'addLead']);
            Route::get('/marketing-site/analytics', [\App\Http\Controllers\Api\MarketingSiteController::class, 'analytics']);
            Route::get('/marketing-site/chats', [\App\Http\Controllers\Api\MarketingSiteController::class, 'chats']);
            Route::post('/marketing-site/chats/archive', [\App\Http\Controllers\Api\MarketingSiteController::class, 'chatArchive']);
            Route::post('/marketing-site/chats/delete', [\App\Http\Controllers\Api\MarketingSiteController::class, 'chatDelete']);
            Route::get('/marketing-site/translations', [\App\Http\Controllers\Api\MarketingTranslationController::class, 'index']);
            Route::put('/marketing-site/translations/{id}', [\App\Http\Controllers\Api\MarketingTranslationController::class, 'update'])->where('id','[0-9]+');
            Route::delete('/marketing-site/translations/{id}', [\App\Http\Controllers\Api\MarketingTranslationController::class, 'destroy'])->where('id','[0-9]+');
        });
        Route::get('/auth/me', [AuthController::class, 'me']);
        // Visible reference IDs, ILH-C-0042 (2026-10-14, App\Support\RefCode).
        Route::get('/refs/lookup', [\App\Http\Controllers\Api\RefController::class, 'lookup'])->middleware('throttle:60,1,r-refs-lookup');
        Route::get('/refs/mine', [\App\Http\Controllers\Api\RefController::class, 'mine']);
        Route::get('/admin/ref-prefix', [\App\Http\Controllers\Api\RefController::class, 'prefix']);
        Route::put('/admin/ref-prefix', [\App\Http\Controllers\Api\RefController::class, 'setPrefix'])->middleware('throttle:10,1,r-admin-ref-prefix');
        // Tasks — admins/directors assign to educators; educators mark progress/done.
        Route::middleware('role:educator,home_visitor,guardian,centre_director,agency_admin,platform_admin')->prefix('tasks')->group(function () {
            Route::get('/mine', [\App\Http\Controllers\Api\TaskController::class, 'mine']);
            Route::patch('/{task}/status', [\App\Http\Controllers\Api\TaskController::class, 'updateStatus'])->where('task','[0-9]+');
            Route::get('/assignees', [\App\Http\Controllers\Api\TaskController::class, 'assignees']);
            Route::get('/', [\App\Http\Controllers\Api\TaskController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\TaskController::class, 'store']);
            Route::patch('/{task}', [\App\Http\Controllers\Api\TaskController::class, 'update'])->where('task','[0-9]+');
            Route::delete('/{task}', [\App\Http\Controllers\Api\TaskController::class, 'destroy'])->where('task','[0-9]+');
        });

        Route::middleware('role:home_visitor,agency_admin,centre_director,platform_admin')->prefix('home-visits')->group(function () {
            Route::get('/centres', [\App\Http\Controllers\Api\HomeVisitReportController::class, 'centres']);
            Route::get('/', [\App\Http\Controllers\Api\HomeVisitReportController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\HomeVisitReportController::class, 'store']);
            Route::get('/{report}', [\App\Http\Controllers\Api\HomeVisitReportController::class, 'show'])->where('report','[0-9]+');
            Route::get('/{report}/pdf', [\App\Http\Controllers\Api\HomeVisitReportController::class, 'pdf'])->where('report','[0-9]+');
            Route::patch('/{report}', [\App\Http\Controllers\Api\HomeVisitReportController::class, 'update'])->where('report','[0-9]+');
        });
        // Home-visitor inspection forms (Monthly Monitoring + quarterly Standard Checklist).
        Route::middleware('role:home_visitor,agency_admin,centre_director,platform_admin')->prefix('inspection-forms')->group(function () {
            Route::get('/schemas', [\App\Http\Controllers\Api\HccFormController::class, 'schemas']);
            Route::get('/schema/{key}', [\App\Http\Controllers\Api\HccFormController::class, 'schema']);
            Route::get('/centres', [\App\Http\Controllers\Api\HccFormController::class, 'centres']);
            Route::get('/', [\App\Http\Controllers\Api\HccFormController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\HccFormController::class, 'store']);
            Route::patch('/{id}', [\App\Http\Controllers\Api\HccFormController::class, 'update'])->where('id','[0-9]+');
            Route::get('/{id}', [\App\Http\Controllers\Api\HccFormController::class, 'show'])->where('id','[0-9]+');
            Route::get('/{id}/pdf', [\App\Http\Controllers\Api\HccFormController::class, 'pdf'])->where('id','[0-9]+');
        });

        /* Platform sales CRM (leads pipeline, activities/follow-ups, quotes). sales_rep +
           superadmin — and now the sales_crm feature flag, which is the first route group
           in this file to actually use one. `feature:` was documented in the middleware
           since v15 and registered nowhere; a flag that hides a menu while its API keeps
           answering is not a flag. Absent flag = allowed, so this changes nothing for an
           agency that has not switched it off. (2026-09-17) */
        Route::middleware(['role:sales_rep,platform_admin', 'feature:sales_crm'])->prefix('sales')->group(function () {
            Route::get('/leads', [\App\Http\Controllers\Api\SalesController::class, 'index']);
            // Leads → Cold reach (2026-10-30): prospects who are not leads yet, calls / emails / follow-ups, convert to a lead.
            Route::get('/prospects', [\App\Http\Controllers\Api\SalesProspectController::class, 'index']);
            Route::post('/prospects', [\App\Http\Controllers\Api\SalesProspectController::class, 'store']);
            Route::post('/prospects/import', [\App\Http\Controllers\Api\SalesProspectController::class, 'import'])->middleware('throttle:20,1,r-prospects-import');
            Route::post('/prospects/parse', [\App\Http\Controllers\Api\SalesProspectController::class, 'parse'])->middleware('throttle:30,1,r-prospects-parse');
            Route::post('/prospects/parse-file', [\App\Http\Controllers\Api\SalesProspectController::class, 'parseFile'])->middleware('throttle:30,1,r-prospects-parse-file');
            Route::post('/prospects/email-bulk', [\App\Http\Controllers\Api\SalesProspectController::class, 'emailBulk'])->middleware('throttle:10,1,r-prospects-email-bulk');
            Route::get('/prospects-stats', [\App\Http\Controllers\Api\SalesProspectController::class, 'stats']);
            Route::get('/prospect-batches', [\App\Http\Controllers\Api\SalesProspectController::class, 'batches']);
            Route::patch('/prospect-batches/{id}', [\App\Http\Controllers\Api\SalesProspectController::class, 'renameBatch'])->whereNumber('id');
            Route::post('/prospect-batches/{id}/recheck', [\App\Http\Controllers\Api\SalesProspectController::class, 'recheckBatch'])->whereNumber('id')->middleware('throttle:10,1,r-prospect-batches-x-recheck');
            Route::delete('/prospect-batches/{id}', [\App\Http\Controllers\Api\SalesProspectController::class, 'deleteBatch'])->whereNumber('id');
            Route::post('/prospects/email-preview', [\App\Http\Controllers\Api\SalesProspectController::class, 'emailPreview']);
            Route::get('/prospects-settings', [\App\Http\Controllers\Api\SalesProspectController::class, 'settings']);
            Route::put('/prospects-settings', [\App\Http\Controllers\Api\SalesProspectController::class, 'saveSettings']);
            Route::post('/prospects-settings/test', [\App\Http\Controllers\Api\SalesProspectController::class, 'testEmail'])->middleware('throttle:20,1,r-prospects-settings-test');
            Route::get('/prospects/{id}', [\App\Http\Controllers\Api\SalesProspectController::class, 'show'])->whereNumber('id');
            Route::patch('/prospects/{id}', [\App\Http\Controllers\Api\SalesProspectController::class, 'update'])->whereNumber('id');
            Route::delete('/prospects/{id}', [\App\Http\Controllers\Api\SalesProspectController::class, 'destroy'])->whereNumber('id');
            Route::post('/prospects/{id}/touches', [\App\Http\Controllers\Api\SalesProspectController::class, 'touch'])->whereNumber('id');
            Route::post('/prospects/{id}/email', [\App\Http\Controllers\Api\SalesProspectController::class, 'email'])->whereNumber('id')->middleware('throttle:60,1,r-prospects-x-email');
            Route::post('/prospects/{id}/convert', [\App\Http\Controllers\Api\SalesProspectController::class, 'convert'])->whereNumber('id');
            // Website meeting requests: accept / modify / decline (2026-10-23).
            Route::get('/bookings', [\App\Http\Controllers\Api\SalesBookingController::class, 'index']);
            Route::get('/bookings/calendar', [\App\Http\Controllers\Api\SalesBookingController::class, 'calendar']);   // the sales@ Outlook calendar (2026-11-04)
            /* Sales -> Email (2026-10-26): the email client on the ONE shared sales@ mailbox. kt_mailbox=sales switches
               the email controllers from "my mailboxes" to the shared one (App\Support\SalesMailbox). */
            Route::get('/email/accounts', [\App\Http\Controllers\Api\EmailAccountController::class, 'index'])->defaults('kt_mailbox', 'sales');
            Route::post('/email/accounts', [\App\Http\Controllers\Api\EmailAccountController::class, 'store'])->defaults('kt_mailbox', 'sales');
            Route::patch('/email/accounts/{id}', [\App\Http\Controllers\Api\EmailAccountController::class, 'update'])->defaults('kt_mailbox', 'sales');
            Route::delete('/email/accounts/{id}', [\App\Http\Controllers\Api\EmailAccountController::class, 'destroy'])->defaults('kt_mailbox', 'sales');
            Route::post('/email/accounts/{id}/test', [\App\Http\Controllers\Api\EmailAccountController::class, 'test'])->defaults('kt_mailbox', 'sales');
            Route::get('/email/accounts/{id}/auto-replies', [\App\Http\Controllers\Api\EmailAccountController::class, 'autoReplies'])->defaults('kt_mailbox', 'sales')->middleware('throttle:60,1,r-sales-email-auto-replies');
            Route::put('/email/accounts/{id}/auto-replies', [\App\Http\Controllers\Api\EmailAccountController::class, 'saveAutoReplies'])->defaults('kt_mailbox', 'sales')->middleware('throttle:20,1,r-sales-email-auto-replies-save');
            Route::put('/email/accounts/{id}/alerts', [\App\Http\Controllers\Api\EmailAccountController::class, 'saveAlerts'])->defaults('kt_mailbox', 'sales')->middleware('throttle:60,1,r-sales-email-alerts');
            Route::post('/email/accounts/{id}/microsoft', [\App\Http\Controllers\Api\EmailAccountController::class, 'microsoftStart'])->defaults('kt_mailbox', 'sales')->middleware('throttle:20,1,r-email-accounts-x-microsoft');
            Route::post('/email/microsoft/finish', [\App\Http\Controllers\Api\EmailAccountController::class, 'microsoftFinish'])->defaults('kt_mailbox', 'sales')->middleware('throttle:20,1,r-email-microsoft-finish');
            // Sign in with Google + "which provider is this address?" (2026-11-04).
            Route::post('/email/accounts/{id}/google', [\App\Http\Controllers\Api\EmailAccountController::class, 'googleStart'])->defaults('kt_mailbox', 'sales')->middleware('throttle:20,1,r-email-accounts-x-google');
            Route::post('/email/discover', [\App\Http\Controllers\Api\EmailAccountController::class, 'discover'])->defaults('kt_mailbox', 'sales')->middleware('throttle:30,1,r-email-discover');
            Route::get('/email/folders', [\App\Http\Controllers\Api\EmailMailController::class, 'folders'])->defaults('kt_mailbox', 'sales');
            Route::get('/email/messages', [\App\Http\Controllers\Api\EmailMailController::class, 'messages'])->defaults('kt_mailbox', 'sales');
            Route::get('/email/messages/{uid}', [\App\Http\Controllers\Api\EmailMailController::class, 'message'])->defaults('kt_mailbox', 'sales');
            Route::get('/email/messages/{uid}/attachment', [\App\Http\Controllers\Api\EmailMailController::class, 'attachment'])->defaults('kt_mailbox', 'sales');
            Route::post('/email/messages/{uid}/action', [\App\Http\Controllers\Api\EmailMailController::class, 'action'])->defaults('kt_mailbox', 'sales');
            Route::post('/email/messages/bulk', [\App\Http\Controllers\Api\EmailMailController::class, 'bulk'])->defaults('kt_mailbox', 'sales')->middleware('throttle:60,1,r-sales-email-bulk');
            Route::post('/email/send', [\App\Http\Controllers\Api\EmailMailController::class, 'send'])->defaults('kt_mailbox', 'sales')->middleware('throttle:30,1,r-email-send');
            Route::post('/email/ai', [\App\Http\Controllers\Api\EmailAiController::class, 'assist'])->middleware('throttle:20,1,r-email-ai');   // suggest / rewrite (2026-11-09)
            // Sales -> Campaigns: introduction emails to prospects, tracked per person, responses to Leads (2026-10-26).
            Route::get('/campaigns', [\App\Http\Controllers\Api\SalesCampaignController::class, 'index']);
            Route::post('/campaigns', [\App\Http\Controllers\Api\SalesCampaignController::class, 'store']);
            Route::get('/campaigns/{id}', [\App\Http\Controllers\Api\SalesCampaignController::class, 'show'])->whereNumber('id');
            Route::patch('/campaigns/{id}', [\App\Http\Controllers\Api\SalesCampaignController::class, 'update'])->whereNumber('id');
            Route::delete('/campaigns/{id}', [\App\Http\Controllers\Api\SalesCampaignController::class, 'destroy'])->whereNumber('id');
            Route::post('/campaigns/{id}/copy', [\App\Http\Controllers\Api\SalesCampaignController::class, 'copy'])->whereNumber('id');   // copy to a new draft (2026-11-11)
            Route::post('/campaigns/{id}/recipients', [\App\Http\Controllers\Api\SalesCampaignController::class, 'addRecipients'])->whereNumber('id');
            Route::delete('/campaigns/{id}/recipients/{rid}', [\App\Http\Controllers\Api\SalesCampaignController::class, 'removeRecipient'])->whereNumber('id')->whereNumber('rid');
            Route::post('/campaigns/{id}/recipients/{rid}/action', [\App\Http\Controllers\Api\SalesCampaignController::class, 'recipientAction'])->whereNumber('id')->whereNumber('rid');
            Route::get('/campaigns/{id}/preview', [\App\Http\Controllers\Api\SalesCampaignController::class, 'preview'])->whereNumber('id');
            Route::post('/campaigns/{id}/test', [\App\Http\Controllers\Api\SalesCampaignController::class, 'test'])->whereNumber('id')->middleware('throttle:10,1,r-campaigns-x-test');
            Route::post('/campaigns/{id}/send', [\App\Http\Controllers\Api\SalesCampaignController::class, 'send'])->whereNumber('id');
            Route::post('/campaigns/{id}/pause', [\App\Http\Controllers\Api\SalesCampaignController::class, 'pause'])->whereNumber('id');
            Route::post('/campaigns/{id}/schedule', [\App\Http\Controllers\Api\SalesCampaignController::class, 'schedule'])->whereNumber('id');
            Route::post('/campaigns/{id}/approve', [\App\Http\Controllers\Api\SalesCampaignController::class, 'approve'])->whereNumber('id');
            // Email templates: KiddieTrac presets + the team's own (2026-11-04).
            Route::get('/campaign-templates', [\App\Http\Controllers\Api\SalesCampaignTemplateController::class, 'index']);
            Route::post('/campaign-templates', [\App\Http\Controllers\Api\SalesCampaignTemplateController::class, 'store']);
            Route::post('/campaign-templates/preview', [\App\Http\Controllers\Api\SalesCampaignTemplateController::class, 'preview'])->middleware('throttle:60,1,r-camp-tpl-preview');
            Route::post('/campaign-images', [\App\Http\Controllers\Api\SalesCampaignTemplateController::class, 'image'])->middleware('throttle:30,1,r-camp-img');   // rich editor pictures (2026-11-05)
            Route::patch('/campaign-templates/{id}', [\App\Http\Controllers\Api\SalesCampaignTemplateController::class, 'update'])->whereNumber('id');
            Route::delete('/campaign-templates/{id}', [\App\Http\Controllers\Api\SalesCampaignTemplateController::class, 'destroy'])->whereNumber('id');
            Route::post('/campaigns/{id}/apply-template', [\App\Http\Controllers\Api\SalesCampaignTemplateController::class, 'apply'])->whereNumber('id');
            // Sales calendar: bookings, hours, time off, whereabouts (2026-10-23).
            Route::get('/calendar', [\App\Http\Controllers\Api\SalesCalendarController::class, 'index']);
            Route::post('/calendar/events', [\App\Http\Controllers\Api\SalesCalendarController::class, 'store']);
            Route::patch('/calendar/events/{id}', [\App\Http\Controllers\Api\SalesCalendarController::class, 'update'])->whereNumber('id');
            Route::delete('/calendar/events/{id}', [\App\Http\Controllers\Api\SalesCalendarController::class, 'destroy'])->whereNumber('id');
            Route::put('/calendar/availability', [\App\Http\Controllers\Api\SalesCalendarController::class, 'saveAvailability']);
            Route::put('/bookings/settings', [\App\Http\Controllers\Api\SalesBookingController::class, 'saveSettings']);
            Route::post('/bookings/{id}/accept', [\App\Http\Controllers\Api\SalesBookingController::class, 'accept'])->whereNumber('id');
            Route::post('/bookings/{id}/modify', [\App\Http\Controllers\Api\SalesBookingController::class, 'modify'])->whereNumber('id');
            Route::post('/bookings/{id}/decline', [\App\Http\Controllers\Api\SalesBookingController::class, 'decline'])->whereNumber('id');
            Route::post('/leads', [\App\Http\Controllers\Api\SalesController::class, 'store']);
            // Duplicate leads: find + advise, merge, not-the-same (2026-10-23).
            Route::get('/leads/duplicates', [\App\Http\Controllers\Api\SalesLeadMergeController::class, 'index']);
            // Follow-ups numbers + open tasks (2026-10-23).
            Route::get('/followups/stats', [\App\Http\Controllers\Api\SalesFollowupStatsController::class, 'index']);
            Route::post('/leads/not-duplicates', [\App\Http\Controllers\Api\SalesLeadMergeController::class, 'dismiss']);
            Route::post('/leads/{lead}/merge', [\App\Http\Controllers\Api\SalesLeadMergeController::class, 'merge'])->where('lead','[0-9]+');
            Route::get('/leads/{lead}', [\App\Http\Controllers\Api\SalesController::class, 'show'])->where('lead','[0-9]+');
            Route::patch('/leads/{lead}', [\App\Http\Controllers\Api\SalesController::class, 'update'])->where('lead','[0-9]+');
            Route::delete('/leads/{lead}', [\App\Http\Controllers\Api\SalesController::class, 'destroy'])->where('lead','[0-9]+');
            Route::post('/leads/{lead}/activities', [\App\Http\Controllers\Api\SalesController::class, 'addActivity'])->where('lead','[0-9]+');
            Route::patch('/activities/{activity}', [\App\Http\Controllers\Api\SalesController::class, 'updateActivity'])->where('activity','[0-9]+');
            // Teams transcripts / recordings on an activity: chunked raw-body upload, remove (2026-10-09).
            Route::post('/activities/{activity}/upload', [\App\Http\Controllers\Api\SalesActivityFileController::class, 'upload'])->where('activity','[0-9]+');
            Route::post('/activities/{activity}/notify', [\App\Http\Controllers\Api\SalesController::class, 'notifyNow'])->where('activity','[0-9]+');
            Route::get('/activities/{activity}/email', [\App\Http\Controllers\Api\SalesController::class, 'activityEmail'])->where('activity','[0-9]+')->middleware('throttle:60,1,sales-act-email');
            Route::delete('/activity-files/{file}', [\App\Http\Controllers\Api\SalesActivityFileController::class, 'destroy'])->where('file','[0-9]+');
            Route::post('/leads/{lead}/quotes', [\App\Http\Controllers\Api\SalesController::class, 'quoteStore'])->where('lead','[0-9]+');
            Route::get('/quotes/{quote}', [\App\Http\Controllers\Api\SalesController::class, 'quoteShow'])->where('quote','[0-9]+');
            Route::patch('/quotes/{quote}', [\App\Http\Controllers\Api\SalesController::class, 'quoteUpdate'])->where('quote','[0-9]+');
            Route::get('/products', [\App\Http\Controllers\Api\SalesController::class, 'products']);
            Route::post('/products', [\App\Http\Controllers\Api\SalesController::class, 'productSave']);
            Route::patch('/products/{product}', [\App\Http\Controllers\Api\SalesController::class, 'productSave'])->where('product','[0-9]+');
            Route::delete('/products/{product}', [\App\Http\Controllers\Api\SalesController::class, 'productDelete'])->where('product','[0-9]+');
            Route::post('/demo-token', [\App\Http\Controllers\Api\SalesController::class, 'demoToken']);
            Route::get('/quotes/{quote}/pdf', [\App\Http\Controllers\Api\SalesController::class, 'quotePdf'])->where('quote','[0-9]+');
            // Everything about one proposal, all revisions, and where it stands (2026-11-10).
            Route::get('/quotes/{quote}/history', [\App\Http\Controllers\Api\SalesController::class, 'quoteHistory'])->where('quote','[0-9]+');
            Route::post('/quotes/{quote}/send', [\App\Http\Controllers\Api\SalesController::class, 'quoteSend'])->where('quote','[0-9]+');
            // Automatic follow-up: change or stop (2026-10-23).
            Route::patch('/quotes/{quote}/followup', [\App\Http\Controllers\Api\SalesController::class, 'quoteFollowup'])->where('quote','[0-9]+');
            // Proposal view / delete + proposal defaults (terms, valid-for days, taxes) — 2026-10-16.
            Route::get('/quotes/{quote}/preview', [\App\Http\Controllers\Api\SalesController::class, 'quotePreview'])->where('quote','[0-9]+');
            // Online signing (2026-10-16): the private link, and KiddieTrac's countersignature.
            Route::post('/quotes/{quote}/sign-link', [\App\Http\Controllers\Api\SalesController::class, 'quoteSignLink'])->where('quote','[0-9]+');
            Route::post('/quotes/{quote}/countersign', [\App\Http\Controllers\Api\SalesController::class, 'quoteCountersign'])->where('quote','[0-9]+');
            // KiddieTrac's own expenses (2026-10-24, Sales -> Expenses): reps their own, superadmins all.
            Route::get   ('/expenses',                    [\App\Http\Controllers\Api\PlatformExpenseController::class, 'index']);
            Route::post  ('/expenses',                    [\App\Http\Controllers\Api\PlatformExpenseController::class, 'store']);
            Route::post  ('/expenses/{id}',               [\App\Http\Controllers\Api\PlatformExpenseController::class, 'update'])->whereNumber('id');
            Route::delete('/expenses/{id}',               [\App\Http\Controllers\Api\PlatformExpenseController::class, 'destroy'])->whereNumber('id');
            Route::post  ('/expenses/{id}/approve',       [\App\Http\Controllers\Api\PlatformExpenseController::class, 'approve'])->whereNumber('id');
            Route::post  ('/expenses/{id}/reject',        [\App\Http\Controllers\Api\PlatformExpenseController::class, 'reject'])->whereNumber('id');
            Route::post  ('/expenses/{id}/reimburse',     [\App\Http\Controllers\Api\PlatformExpenseController::class, 'reimburse'])->whereNumber('id');
            Route::get   ('/expenses/{id}/receipt',       [\App\Http\Controllers\Api\PlatformExpenseController::class, 'receipt'])->whereNumber('id');
            Route::post  ('/expense-recurring',           [\App\Http\Controllers\Api\PlatformExpenseController::class, 'recurringStore']);
            Route::patch ('/expense-recurring/{id}',      [\App\Http\Controllers\Api\PlatformExpenseController::class, 'recurringUpdate'])->whereNumber('id');
            Route::delete('/expense-recurring/{id}',      [\App\Http\Controllers\Api\PlatformExpenseController::class, 'recurringDestroy'])->whereNumber('id');
            // KiddieTrac's own suppliers for the expense Vendor dropdown (2026-11-02). Reps add; superadmins change/archive.
            Route::get   ('/suppliers',                   [\App\Http\Controllers\Api\SalesSupplierController::class, 'index']);
            Route::post  ('/suppliers',                   [\App\Http\Controllers\Api\SalesSupplierController::class, 'store']);
            Route::patch ('/suppliers/{id}',              [\App\Http\Controllers\Api\SalesSupplierController::class, 'update'])->whereNumber('id');
            Route::delete('/suppliers/{id}',              [\App\Http\Controllers\Api\SalesSupplierController::class, 'destroy'])->whereNumber('id');
            // Every proposal with its stats and trends (2026-10-24, Sales -> Proposals).
            Route::get('/quotes-overview', [\App\Http\Controllers\Api\SalesReportsController::class, 'quotes']);
            // Customers (2026-11-10): every won lead, what they bought, and (superadmins) their AR.
            Route::get  ('/customers',      [\App\Http\Controllers\Api\SalesCustomerController::class, 'index']);
            Route::get  ('/customers/{id}', [\App\Http\Controllers\Api\SalesCustomerController::class, 'show'])->whereNumber('id');
            Route::patch('/customers/{id}', [\App\Http\Controllers\Api\SalesCustomerController::class, 'update'])->whereNumber('id');
            // The signed contract (2026-10-23, SalesConversionController): required before approval.
            Route::post('/quotes/{quote}/contract', [\App\Http\Controllers\Api\SalesConversionController::class, 'uploadContract'])->where('quote','[0-9]+');
            Route::get('/quotes/{quote}/contract', [\App\Http\Controllers\Api\SalesConversionController::class, 'downloadContract'])->where('quote','[0-9]+');
            Route::delete('/quotes/{quote}/contract', [\App\Http\Controllers\Api\SalesConversionController::class, 'removeContract'])->where('quote','[0-9]+');
            Route::delete('/quotes/{quote}', [\App\Http\Controllers\Api\SalesController::class, 'quoteDestroy'])->where('quote','[0-9]+');
            Route::get('/proposal-defaults', [\App\Http\Controllers\Api\SalesController::class, 'proposalDefaults']);
            Route::put('/proposal-defaults', [\App\Http\Controllers\Api\SalesController::class, 'proposalDefaultsSave']);
            // Live website chat inbox (WebChatController).
            Route::post('/web-chats/presence', [\App\Http\Controllers\Api\WebChatController::class, 'presence']);
            Route::get('/web-chats', [\App\Http\Controllers\Api\WebChatController::class, 'index']);
            Route::get('/web-chats/{id}', [\App\Http\Controllers\Api\WebChatController::class, 'show'])->where('id','[0-9]+');
            Route::post('/web-chats/{id}/reply', [\App\Http\Controllers\Api\WebChatController::class, 'reply'])->where('id','[0-9]+');
            Route::post('/web-chats/{id}/typing', [\App\Http\Controllers\Api\WebChatController::class, 'typing'])->where('id','[0-9]+');
            Route::post('/web-chats/{id}/nudge', [\App\Http\Controllers\Api\WebChatController::class, 'nudge'])->where('id','[0-9]+');
            Route::post('/web-chats/{id}/join', [\App\Http\Controllers\Api\WebChatController::class, 'join'])->where('id','[0-9]+');
            Route::post('/web-chats/{id}/release', [\App\Http\Controllers\Api\WebChatController::class, 'release'])->where('id','[0-9]+');
            Route::post('/web-chats/{id}/close', [\App\Http\Controllers\Api\WebChatController::class, 'close'])->where('id','[0-9]+');
            Route::post('/web-chats/{id}/lead', [\App\Http\Controllers\Api\WebChatController::class, 'lead'])->where('id','[0-9]+');
            Route::get('/messages', [\App\Http\Controllers\Api\SalesController::class, 'messagesIndex']);
            Route::post('/messages', [\App\Http\Controllers\Api\SalesController::class, 'messagesStore']);
            Route::get('/announcements', [\App\Http\Controllers\Api\SalesController::class, 'announcementsIndex']);
            Route::post('/announcements', [\App\Http\Controllers\Api\SalesController::class, 'announcementsStore']);
            Route::delete('/announcements/{announcement}', [\App\Http\Controllers\Api\SalesController::class, 'announcementsDestroy'])->where('announcement','[0-9]+');
        });

        /* THE SALES REFERENCE LIBRARY - SUPERADMIN ONLY.
           A SEPARATE group from the CRM above on purpose. That one is
           role:sales_rep,platform_admin; this is commercial material for the platform
           - price sheets, contract templates, licensing paperwork - and a sales rep
           must not reach it. Sharing the group would have handed it to them with one
           word of middleware and no other sign. The controller asks again. */
        Route::middleware('role:platform_admin')->prefix('sales')->group(function () {
            // Approve / reject a proposal; approval sets the prospect up as a customer (2026-10-23).
            Route::get('/quotes/{quote}/conversion', [\App\Http\Controllers\Api\SalesConversionController::class, 'preview'])->where('quote','[0-9]+');
            Route::post('/quotes/{quote}/approve', [\App\Http\Controllers\Api\SalesConversionController::class, 'approve'])->where('quote','[0-9]+');
            Route::post('/quotes/{quote}/reject', [\App\Http\Controllers\Api\SalesConversionController::class, 'reject'])->where('quote','[0-9]+');
            Route::post('/quotes/{quote}/send-invite', [\App\Http\Controllers\Api\SalesConversionController::class, 'sendInvite'])->where('quote','[0-9]+');
            Route::get   ('/library',                 [\App\Http\Controllers\Api\SalesLibraryController::class, 'index']);
            Route::post  ('/library',                 [\App\Http\Controllers\Api\SalesLibraryController::class, 'store']);
            Route::get   ('/library/{doc}/download',  [\App\Http\Controllers\Api\SalesLibraryController::class, 'download'])->whereNumber('doc');
            Route::delete('/library/{doc}',           [\App\Http\Controllers\Api\SalesLibraryController::class, 'destroy'])->whereNumber('doc');
            Route::patch ('/library/{doc}',           [\App\Http\Controllers\Api\SalesLibraryController::class, 'move'])->whereNumber('doc');
            Route::post  ('/library/folders',         [\App\Http\Controllers\Api\SalesLibraryController::class, 'folderStore']);
            Route::delete('/library/folders/{folder}',[\App\Http\Controllers\Api\SalesLibraryController::class, 'folderDestroy'])->whereNumber('folder');
        });
        Route::patch('/auth/me', [AuthController::class, 'updateProfile']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
        // The website's demo phone: a few fictional notifications in a demo person's first minutes (2026-10-31).
        Route::post('/demo/pulse', [\App\Http\Controllers\Api\WebDemoController::class, 'pulse'])->middleware('throttle:12,1,dpulse');
        // The parent's phone beside a demo staff phone on the website (2026-10-31): a fenced sign-in for the same demo agency's parent.
        Route::post('/demo/companion', [\App\Http\Controllers\Api\WebDemoController::class, 'companion'])->middleware('throttle:10,1,dcompanion');
        // The demo phone's Mail app (2026-10-31, App\Support\WebDemoMail): a demo person's own captured, never-delivered mail.
        Route::get('/demo/mail', [\App\Http\Controllers\Api\WebDemoMailController::class, 'index'])->middleware('throttle:60,1,dmail');
        Route::get('/demo/mail/{id}', [\App\Http\Controllers\Api\WebDemoMailController::class, 'show'])->whereNumber('id')->middleware('throttle:60,1,dmailshow');
        Route::get('/demo/mail/{id}/files/{n}', [\App\Http\Controllers\Api\WebDemoMailController::class, 'file'])->whereNumber('id')->whereNumber('n')->middleware('throttle:60,1,dmailfile');
        // The demo phone rings and texts (2026-10-30, App\Support\WebDemoPhone): demo-only people, their own calls/texts.
        Route::get ('/demo/phone/events', [\App\Http\Controllers\Api\WebDemoPhoneController::class, 'events'])->middleware('throttle:40,1,dphev');
        Route::post('/demo/phone/calls/{id}', [\App\Http\Controllers\Api\WebDemoPhoneController::class, 'call'])->whereNumber('id')->middleware('throttle:30,1,dphcall');
        Route::get ('/demo/phone/texts', [\App\Http\Controllers\Api\WebDemoPhoneController::class, 'texts'])->middleware('throttle:30,1,dphtx');
        Route::post('/demo/phone/texts', [\App\Http\Controllers\Api\WebDemoPhoneController::class, 'reply'])->middleware('throttle:10,1,dphrp');
        Route::post('/demo/phone/try', [\App\Http\Controllers\Api\WebDemoPhoneController::class, 'tryIt'])->middleware('throttle:6,1,dphtry');
        // v22p3.5: onboarding wizard submission
        Route::patch('/auth/me/onboarding', [AuthController::class, 'updateOnboarding']);
    Route::get('/auth/me/payee-invoices', [\App\Http\Controllers\Api\PayeeInvoiceController::class, 'mine']);
    // Payroll documents: an educator or staff member sees their own; the PDF route is
    // shared with admins and decides for itself whose document may be opened.
    Route::get('/auth/me/payroll-documents', [\App\Http\Controllers\Api\PayrollDocumentController::class, 'mine']);
    Route::get('/payroll-documents/{id}/pdf', [\App\Http\Controllers\Api\PayrollDocumentController::class, 'pdf'])->where('id', '[0-9]+');
    // My own payslip, emailed to my own address (2026-10-28).
    Route::post('/me/payroll-documents/{id}/email', [\App\Http\Controllers\Api\PayrollDocumentController::class, 'emailMine'])->where('id', '[0-9]+');
    /* The invoice the source system actually issued, fetched by us rather than linked
       to. Same shape as the payslip route above and for the same reason: the signed
       URL is self-authenticating and permanent, so it must not reach a browser. Shared
       between a parent and agency staff, and the method decides which of them is
       asking -- a client can ask for any id it likes. */
    Route::get('/invoices/external/{id}/document', [\App\Http\Controllers\Api\InvoiceController::class, 'externalDocument'])->where('id', '[0-9]+');
    // The same invoice as a PDF, and a copy emailed to the guardian themselves (2026-10-28).
    Route::get('/invoices/external/{id}/pdf', [\App\Http\Controllers\Api\InvoiceController::class, 'externalPdf'])->where('id', '[0-9]+');
    Route::post('/parent/external-invoices/{id}/email', [\App\Http\Controllers\Api\InvoiceController::class, 'emailExternalInvoice'])->where('id', '[0-9]+');
    Route::get ('/auth/me/auto-signoff-notice',     [\App\Http\Controllers\Api\AutoSignOffNoticeController::class, 'show']);
    Route::post('/auth/me/auto-signoff-notice/ack', [\App\Http\Controllers\Api\AutoSignOffNoticeController::class, 'ack']);
    Route::get('/auth/me/ui-prefs', [\App\Http\Controllers\Api\UiPrefsController::class, 'show']);
    Route::put('/auth/me/ui-prefs', [\App\Http\Controllers\Api\UiPrefsController::class, 'update']);
        Route::patch('/auth/me/provider-bio', [AuthController::class, 'updateProviderBio']);

        // v22p3.2: self-service avatar upload (any authenticated user)
        Route::post('/auth/me/avatar', function (\Illuminate\Http\Request $request) {
            $request->validate([
                'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            ]);
            $file = $request->file('avatar');
            if (\App\Support\AvatarCheck::looksBlank($file->getRealPath())) {
                return response()->json(['message' => \App\Support\AvatarCheck::MESSAGE, 'errors' => ['avatar' => [\App\Support\AvatarCheck::MESSAGE]]], 422);
            }
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

        /* How far this person has read — the bell, the what's-new dot, the sales-chat
           badge. Their own read position and nobody else's, so there is no route that
           takes a user id. See UiMarkerController. */
        /* WHERE THIS PERSON'S PAY GOES. Their own only — there is no route here
           that takes a user id. Write-only: nothing returns an account number, only
           a hint like "•••• 4821" so they can confirm the right account is on file. */
        Route::get('/me/payout-method', [\App\Http\Controllers\Api\StaffPayoutMethodController::class, 'mine']);
        Route::put('/me/payout-method', [\App\Http\Controllers\Api\StaffPayoutMethodController::class, 'update']);
        Route::get('/me/markers', [\App\Http\Controllers\Api\UiMarkerController::class, 'index']);
        Route::put('/me/markers', [\App\Http\Controllers\Api\UiMarkerController::class, 'update']);

        // v23: a user's own filed documents — their signed NDA and any files an
        // admin attached to their record. Surfaced under "Forms" on mobile/APK.
        Route::get('/auth/me/documents', function (\Illuminate\Http\Request $request) {
            $docs = \Illuminate\Support\Facades\DB::table('documents')
                ->where('scope_type', 'user')->where('scope_id', $request->user()->id)
                ->orderByDesc('created_at')
                ->get(['id', 'title', 'category', 'file_url', 'file_type', 'file_size', 'signed_at', 'expires_at', 'created_at', 'notes', 'source_type', 'source_id']);
            // Each signed form says whether the office has counter-signed it, and who.
            return response()->json(['documents' => \App\Support\SignedFormFiler::withReview($docs)]);
        });
        // Stream one of the caller's own documents through the API so the mobile
        // WebView can open it (a cross-origin /storage link + target=_blank fails there).
        Route::get('/auth/me/documents/{doc}/download', function (\Illuminate\Http\Request $request, int $doc) {
            $row = \Illuminate\Support\Facades\DB::table('documents')->where('id', $doc)
                ->where('scope_type', 'user')->where('scope_id', $request->user()->id)->first();
            if (!$row) abort(404);
            $rel = ltrim(str_replace('/storage/', '', (string) $row->file_url), '/');
            $disk = \Illuminate\Support\Facades\Storage::disk('public');
            if ($rel === '' || !$disk->exists($rel)) abort(404);
            return response()->file($disk->path($rel));
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
        Route::post('/help/ask', [HelpController::class, 'ask'])->middleware('throttle:30,1,r-help-ask');

        // Training: a narrated tutorial per help article, gated exactly as the article is.
        Route::get('/training', [\App\Http\Controllers\Api\TrainingController::class, 'index']);
        Route::post('/training/{id}/progress', [\App\Http\Controllers\Api\TrainingController::class, 'progress'])->where('id', '[0-9]+');
        Route::post('/training/{id}/publish', [\App\Http\Controllers\Api\TrainingController::class, 'publish'])->where('id', '[0-9]+');
        // Assigning training to people (admins → their agency, directors → their centres; checked in the controller).
        Route::get('/training/assignable', [\App\Http\Controllers\Api\TrainingController::class, 'assignable']);
        Route::get('/training/assignments', [\App\Http\Controllers\Api\TrainingController::class, 'report']);
        Route::post('/training/assignments', [\App\Http\Controllers\Api\TrainingController::class, 'assign']);
        Route::delete('/training/assignments/{id}', [\App\Http\Controllers\Api\TrainingController::class, 'cancel'])->where('id', '[0-9]+');

        // v22p33 — Per-role dashboard widget data
        Route::get('/widgets/me', [\App\Http\Controllers\Api\WidgetsController::class, 'me']);
        Route::post('/me/account-deletion', [\App\Http\Controllers\Api\AccountController::class, 'requestDeletion']);

        // A parent's own notification preferences (email / SMS / in-app), e.g.
        // being told when their child is signed in or out. Scoped to the caller.
        Route::get('/me/notification-prefs',  [\App\Http\Controllers\Api\NotificationPrefsController::class, 'index']);
        Route::get('/me/notify-requirement', [\App\Http\Controllers\Api\StaffNotifyController::class, 'me'])->middleware('throttle:60,1,r-notify-requirement');
        // Which kinds of texts and calls I want (My profile, 2026-09-29).
        Route::get('/me/contact-prefs', [\App\Http\Controllers\Api\ContactPrefsController::class, 'index']);
        Route::put('/me/contact-prefs', [\App\Http\Controllers\Api\ContactPrefsController::class, 'update']);
        // Calendar sync (2026-11-03, cal1103): Settings > Calendar - the person's own link, choices and reset.
        Route::get('/me/calendar-feed', [\App\Http\Controllers\Api\CalendarFeedController::class, 'show']);
        Route::patch('/me/calendar-feed', [\App\Http\Controllers\Api\CalendarFeedController::class, 'update']);
        Route::post('/me/calendar-feed/reset', [\App\Http\Controllers\Api\CalendarFeedController::class, 'reset'])->middleware('throttle:10,1,calreset');
        Route::put('/me/voice-opt-out', [\App\Http\Controllers\Api\ContactPrefsController::class, 'voiceOptOut']);
        // Consent to be texted at all — a separate question from which events you want,
        // and the one a carrier wants evidence of. See kiddietrac.com/privacy#sms.
        // Biometric unlock, reported by the device that enrolled. Enrolment has been a
        // localStorage flag with no server record since it shipped, so nobody was told an
        // unlock method had been added to their account and nobody could report on it.
        // How today is going for the educator asking — same numbers as the evening email.
        Route::get('/educator/day-score', [\App\Http\Controllers\Api\EducatorSelfController::class, 'dayScore']);
        /* Which of today's assigned forms this educator has actually submitted. Sits in
           the same educator-facing group as day-score; it also serves a director looking
           at one of their own educators, which the controller authorises. */
        Route::get('/educator/forms-today', [\App\Http\Controllers\Api\EducatorSelfController::class, 'formsToday']);
        /* The supervisor's view of the same data: every educator in scope. Authorised and
           agency-scoped inside the controller, which fails closed without agency context. */
        Route::get('/admin/forms-today', [\App\Http\Controllers\Api\EducatorSelfController::class, 'formsTodayRollup']);

        /* Which provider has a child on which day. Admin/director configuration for a
           family that splits its week across providers. */
        Route::get ('/admin/children/{child}/care-schedule', [\App\Http\Controllers\Api\CareScheduleController::class, 'show']);
        Route::put ('/admin/children/{child}/care-schedule', [\App\Http\Controllers\Api\CareScheduleController::class, 'update']);
        Route::post('/admin/children/{child}/schedule-change', [\App\Http\Controllers\Api\CareScheduleController::class, 'notice']);
        /* PASSKEYS — signed-in half: enrol, list, remove. Beside the biometric routes
           on purpose; the two are neighbours in the UI and in what they are for. */
        Route::get   ('/passkeys',                  [\App\Http\Controllers\Api\PasskeyController::class, 'index']);
        Route::post  ('/passkeys/register/options', [\App\Http\Controllers\Api\PasskeyController::class, 'registerOptions']);
        Route::post  ('/passkeys/register/verify',  [\App\Http\Controllers\Api\PasskeyController::class, 'registerVerify']);
        Route::delete('/passkeys/{id}',             [\App\Http\Controllers\Api\PasskeyController::class, 'destroy'])->whereNumber('id');
        Route::post('/me/biometric-enrolled', [\App\Http\Controllers\Api\BiometricController::class, 'enrolled']);
        Route::post('/me/device-permissions', [\App\Http\Controllers\Api\DevicePermissionsController::class, 'store'])->middleware('throttle:20,1,r-device-permissions');
        Route::post('/me/biometric-revoked',  [\App\Http\Controllers\Api\BiometricController::class, 'revoked']);
        // Signed-in devices: see them, sign a lost one out remotely (2026-11-09, DeviceSessionController).
        Route::get ('/me/devices',          [\App\Http\Controllers\Api\DeviceSessionController::class, 'mine']);
        Route::post('/me/devices/sign-out', [\App\Http\Controllers\Api\DeviceSessionController::class, 'signOutMine'])->middleware('throttle:30,1,r-me-devices-out');
        Route::get ('/admin/users/{user}/devices',          [\App\Http\Controllers\Api\DeviceSessionController::class, 'forUser'])->whereNumber('user')->middleware('role:agency_admin,platform_admin,centre_director');   // + assertMay: own agency; directors only their centre's non-admin staff
        Route::post('/admin/users/{user}/devices/sign-out', [\App\Http\Controllers\Api\DeviceSessionController::class, 'signOutUser'])->whereNumber('user')->middleware(['role:agency_admin,platform_admin,centre_director', 'throttle:30,1,r-user-devices-out']);
        /* Report a message / block a person in messages (2026-11-03, App Store Guideline 1.2) - ContentReportController.
           The admin pair checks the agency-admin / superadmin rule itself (the same as Chat conversations). */
        Route::post  ('/me/content-reports', [\App\Http\Controllers\Api\ContentReportController::class, 'report'])->middleware('throttle:20,1,r-content-report');
        Route::get   ('/me/blocks',          [\App\Http\Controllers\Api\ContentReportController::class, 'myBlocks']);
        Route::post  ('/me/blocks',          [\App\Http\Controllers\Api\ContentReportController::class, 'block'])->middleware('throttle:20,1,r-user-block');
        Route::delete('/me/blocks/{user}',   [\App\Http\Controllers\Api\ContentReportController::class, 'unblock'])->whereNumber('user');
        Route::get   ('/admin/content-reports',      [\App\Http\Controllers\Api\ContentReportController::class, 'adminIndex']);
        Route::patch ('/admin/content-reports/{id}', [\App\Http\Controllers\Api\ContentReportController::class, 'adminUpdate'])->whereNumber('id');
        Route::get ('/admin/biometric-report', [\App\Http\Controllers\Api\BiometricController::class, 'report']);
        Route::get('/me/sms-consent',  [\App\Http\Controllers\Api\SmsConsentController::class, 'show']);
        Route::post('/me/sms-consent', [\App\Http\Controllers\Api\SmsConsentController::class, 'store']);
        // 'My child isn't coming in today' — reported by a parent, tells the centre.
        // Temporary pickup people (2026-11-24, App\Support\TempPickups).
        Route::get ('/parent/temp-pickups',              [\App\Http\Controllers\Api\TempPickupController::class, 'parentIndex']);
        Route::post('/parent/temp-pickups',              [\App\Http\Controllers\Api\TempPickupController::class, 'parentStore'])->middleware('throttle:20,1,r-tp-add');
        Route::post('/parent/temp-pickups/{id}/cancel',  [\App\Http\Controllers\Api\TempPickupController::class, 'parentCancel'])->whereNumber('id');
        Route::post('/parent/temp-pickups/{id}/resend',  [\App\Http\Controllers\Api\TempPickupController::class, 'parentResend'])->whereNumber('id')->middleware('throttle:6,1,r-tp-resend');
        Route::get ('/parent/temp-pickups/{id}/photo',   [\App\Http\Controllers\Api\TempPickupController::class, 'parentPhoto'])->whereNumber('id');
        // Call in instead: the KiddieTrac number and my phone PIN (2026-11-24, App\Support\ParentLine).
        Route::get   ('/parent/phone-line',     [\App\Http\Controllers\Api\ParentLineController::class, 'mineParent']);
        Route::put   ('/parent/phone-line/pin', [\App\Http\Controllers\Api\ParentLineController::class, 'setPin'])->middleware('throttle:10,1,r-ppin');
        Route::delete('/parent/phone-line/pin', [\App\Http\Controllers\Api\ParentLineController::class, 'deletePin'])->middleware('throttle:10,1,r-ppin');
        Route::get('/parent/absences',    [\App\Http\Controllers\Api\AbsenceController::class, 'index']);
        Route::post('/parent/absences',   [\App\Http\Controllers\Api\AbsenceController::class, 'store']);
        Route::delete('/parent/absences/{child}/{date}', [\App\Http\Controllers\Api\AbsenceController::class, 'destroy']);
        // 'We're running late' (drop-off or pickup) - holds Safe Arrival back and tells the staff (2026-11-06).
        Route::get   ('/parent/running-late', [\App\Http\Controllers\Api\ParentRunningLateController::class, 'index']);
        Route::post  ('/parent/running-late', [\App\Http\Controllers\Api\ParentRunningLateController::class, 'store'])->middleware('throttle:30,1,r-parent-late');
        Route::delete('/parent/running-late/{child}/{kind}', [\App\Http\Controllers\Api\ParentRunningLateController::class, 'destroy'])->whereNumber('child')->whereIn('kind', ['arrival', 'departure']);
        // Pickup countdown + alarm: the parent's own reminder, from check-in to pickup (2026-11-09).
        Route::get ('/parent/pickup-plan', [\App\Http\Controllers\Api\ParentPickupPlanController::class, 'index']);
        Route::post('/parent/pickup-plan', [\App\Http\Controllers\Api\ParentPickupPlanController::class, 'store'])->middleware('throttle:60,1,r-parent-pickup');
        // Global search words, learned from use (App\Support\SearchKeywords).
        Route::get ('/search-keys', [\App\Http\Controllers\Api\SearchKeywordController::class, 'index']);
        Route::post('/diag/search-words', [\App\Http\Controllers\Api\SearchKeywordController::class, 'report'])->middleware('throttle:30,1,r-search-words');
        // Which rooms an educator is assigned to (director / agency-admin only —
        // the controller checks centre access on both the user and the rooms).
        // 2026-08-30 — these five carried NO role middleware. The controller's own
        // guard is authorizeCentreAccess(), which passes for ANY active role at the
        // centre — an EDUCATOR included — so a colleague could read, correct and
        // invent another educator's payroll punches, and reassign their rooms.
        // Room assignment is a director/agency-admin decision (the controller says so
        // in its own docblock); punch correction is payroll. Both are admin-only.
        Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
            // Linked emails on a record, for whoever the agency lets see them (2026-11-06, PersonMail::viewScope).
            Route::get('/email-links/access', [\App\Http\Controllers\Api\EmailLinkController::class, 'access'])->middleware('throttle:120,1,r-email-links-access');
            Route::get('/email-links/person', [\App\Http\Controllers\Api\EmailLinkController::class, 'person'])->middleware('throttle:120,1,r-email-links-person');
            Route::get('/email-links/family/{id}', [\App\Http\Controllers\Api\EmailLinkController::class, 'family'])->whereNumber('id')->middleware('throttle:120,1,r-email-links-family');
            // Open, download, print and forward one linked email (2026-11-09) - gated by the record, see EmailLinkController::linked().
            Route::get('/email-links/{id}/message', [\App\Http\Controllers\Api\EmailLinkController::class, 'message'])->whereNumber('id')->middleware('throttle:60,1,r-email-link-open');
            Route::get('/email-links/{id}/attachment', [\App\Http\Controllers\Api\EmailLinkController::class, 'attachment'])->whereNumber('id')->middleware('throttle:60,1,r-email-link-open');
            Route::get('/email-links/{id}/download', [\App\Http\Controllers\Api\EmailLinkController::class, 'download'])->whereNumber('id')->middleware('throttle:30,1,r-email-link-open');
            Route::post('/email-links/{id}/forward', [\App\Http\Controllers\Api\EmailLinkController::class, 'forward'])->whereNumber('id')->middleware('throttle:10,1,r-email-link-forward');
            Route::get('/admin/users/{user}/rooms', [\App\Http\Controllers\Api\EducatorRoomsController::class, 'show']);
            Route::put('/admin/users/{user}/rooms', [\App\Http\Controllers\Api\EducatorRoomsController::class, 'update']);
            // A staff member's clock in/out history, on their own record.
            Route::get('/admin/users/{user}/punches', [\App\Http\Controllers\Api\EducatorRoomsController::class, 'punches']);
            // Correcting a punch. Nothing could do this before — the educator's clock only
            // toggles, and there was no admin route — which is why punches sat open for weeks.
            Route::patch('/admin/users/{user}/punches/{punch}', [\App\Http\Controllers\Api\EducatorRoomsController::class, 'updatePunch']);
            // Enter a shift by hand — there was no create, so a shift that was never clocked
            // could not be added at all and payroll had no way to be made whole.
            Route::post('/admin/users/{user}/punches', [\App\Http\Controllers\Api\EducatorRoomsController::class, 'storePunch']);
            // A correction answers "these times are wrong"; it has no answer for "this
            // shift is not real". Shipped with the timesheet's row menu so a shift that
            // can be entered by hand can also be taken back. Audited with the whole row.
            Route::delete('/admin/users/{user}/punches/{punch}', [\App\Http\Controllers\Api\EducatorRoomsController::class, 'destroyPunch']);
        });
        Route::put('/me/notification-prefs',  [\App\Http\Controllers\Api\NotificationPrefsController::class, 'update']);
        // Tone + vibration per notification category (App\Support\NotificationSounds).
        Route::get('/me/notification-sounds', [\App\Http\Controllers\Api\NotificationSoundsController::class, 'index']);
        Route::put('/me/notification-sounds', [\App\Http\Controllers\Api\NotificationSoundsController::class, 'update']);

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
        /* One request for a whole roster. The per-child route below is fine; calling
           it once per child is what saturates the box at drop-off — the 508s cluster
           at 08:00–09:00 and name that exact path. */
        Route::get   ('/care/logs/recent',          [\App\Http\Controllers\Api\CareController::class, 'recentLogsBatch']);
        Route::get   ('/care/logs/child/{child}',   [\App\Http\Controllers\Api\CareController::class, 'logsForChild']);
        Route::get   ('/care/milestones/catalog',   [\App\Http\Controllers\Api\CareController::class, 'milestoneCatalog']);
        Route::get   ('/care/milestones/child/{child}', [\App\Http\Controllers\Api\CareController::class, 'milestonesForChild']);
        Route::post  ('/care/milestones',           [\App\Http\Controllers\Api\CareController::class, 'recordMilestone']);
        Route::get   ('/care/portfolio/{child}',    [\App\Http\Controllers\Api\CareController::class, 'portfolio']);
        Route::post  ('/staff/punch',               [\App\Http\Controllers\Api\CareController::class, 'punch']);
        Route::get   ('/staff/punches/me',          [\App\Http\Controllers\Api\CareController::class, 'myPunches']);
        // Lunch and breaks: off the floor without ending the day (2026-11-05, App\Support\StaffBreaks).
        Route::post  ('/staff/break/start',         [\App\Http\Controllers\Api\CareController::class, 'breakStart']);
        Route::post  ('/staff/break/end',           [\App\Http\Controllers\Api\CareController::class, 'breakEnd']);
        // A supervisor view — every staff member's punches at the centre. Gated like
        // one (2026-08-30); it previously answered to any authenticated role there.
        Route::get   ('/staff/punches/centre',      [\App\Http\Controllers\Api\CareController::class, 'centrePunches'])->middleware('role:centre_director,agency_admin,platform_admin');

        // 2026-07-13 — self-scoped staff data for the educator mobile app.
        // Own shifts only, and child records only for children the caller can
        // already access (canAccessChildScoped). The director/admin schedule +
        // payroll routes stay centre-wide and stay director-gated.
        /* PAYOUT METHODS, for issuing payroll. Director-gated like every other
           payroll READ on this group; manual payroll WRITING stays agency_admin-only
           further down, unchanged — running a site is not the same as moving money.

           index() returns hints only, which is enough to answer "can this run go
           out". reveal() is the single door to the real numbers, and audits every
           use. */
        Route::get   ('/admin/payroll/payout-methods',               [\App\Http\Controllers\Api\StaffPayoutMethodController::class, 'index'])->middleware('role:agency_admin,centre_director,platform_admin');
        Route::get   ('/admin/payroll/payout-methods/{user}/reveal', [\App\Http\Controllers\Api\StaffPayoutMethodController::class, 'reveal'])->where('user', '[0-9]+')->middleware('role:agency_admin,centre_director,platform_admin');
        // Split family billing (2026-09-29)
        Route::get ('/admin/families/{id}/billing-split', [\App\Http\Controllers\Api\BillingSplitController::class, 'show'])->where('id', '[0-9]+')->middleware('role:agency_admin,centre_director,platform_admin');
        Route::put ('/admin/families/{id}/billing-split', [\App\Http\Controllers\Api\BillingSplitController::class, 'update'])->where('id', '[0-9]+')->middleware('role:agency_admin,centre_director,platform_admin');
        Route::post('/admin/families/{id}/billing-split/preview', [\App\Http\Controllers\Api\BillingSplitController::class, 'previewUnsaved'])->where('id', '[0-9]+')->middleware(['role:agency_admin,centre_director,platform_admin', 'throttle:60,1,splitprev']);
        // Grant tracking & reconciliation (2026-09-29)
        // Batch payment run (2026-09-29): agency admins only.
        Route::get ('/admin/payment-runs/preview', [\App\Http\Controllers\Api\PaymentRunController::class, 'preview'])->middleware('role:agency_admin,platform_admin');
        Route::get ('/admin/payment-runs', [\App\Http\Controllers\Api\PaymentRunController::class, 'history'])->middleware('role:agency_admin,platform_admin');
        Route::post('/admin/payment-runs', [\App\Http\Controllers\Api\PaymentRunController::class, 'run'])->middleware('role:agency_admin,platform_admin');
        Route::get ('/admin/payment-runs/{id}', [\App\Http\Controllers\Api\PaymentRunController::class, 'show'])->where('id', '[0-9]+')->middleware('role:agency_admin,platform_admin');
        Route::get ('/admin/grants', [\App\Http\Controllers\Api\GrantController::class, 'index'])->middleware('role:agency_admin,centre_director,platform_admin');
        Route::post('/admin/grants', [\App\Http\Controllers\Api\GrantController::class, 'save'])->middleware('role:agency_admin,centre_director,platform_admin');
        Route::get ('/admin/grants/{id}', [\App\Http\Controllers\Api\GrantController::class, 'show'])->where('id', '[0-9]+')->middleware('role:agency_admin,centre_director,platform_admin');
        Route::post('/admin/grants/{id}', [\App\Http\Controllers\Api\GrantController::class, 'save'])->where('id', '[0-9]+')->middleware('role:agency_admin,centre_director,platform_admin');
        Route::post('/admin/grants/{id}/transactions', [\App\Http\Controllers\Api\GrantController::class, 'addTransaction'])->where('id', '[0-9]+')->middleware('role:agency_admin,centre_director,platform_admin');
        Route::delete('/admin/grants/{id}/transactions/{txnId}', [\App\Http\Controllers\Api\GrantController::class, 'deleteTransaction'])->where(['id' => '[0-9]+', 'txnId' => '[0-9]+'])->middleware('role:agency_admin,centre_director,platform_admin');
        Route::get ('/admin/grants/{id}/export.csv', [\App\Http\Controllers\Api\GrantController::class, 'csv'])->where('id', '[0-9]+')->middleware('role:agency_admin,centre_director,platform_admin');

        Route::get   ('/provider/shifts/me',        [\App\Http\Controllers\Api\EducatorSelfController::class, 'myShifts'])->middleware(KT_PROVIDER_ROLES);   // staff self-service: no parent (2026-11-04 roles sweep)
        // The educator's own calendar: shifts, own time off, closures + birthdays for their centres (2026-10-28).
        Route::get   ('/provider/calendar/me',      [\App\Http\Controllers\Api\CalendarOverlayController::class, 'mine'])->middleware(KT_PROVIDER_ROLES);
        Route::get   ('/provider/children',          [\App\Http\Controllers\Api\EducatorSelfController::class, 'children'])->middleware(KT_PROVIDER_ROLES);
        // ── Forms Manager: upload PDF → assign to roles → e-sign → track sign-offs ──
        Route::get   ('/admin/managed-forms/signoffs',           [\App\Http\Controllers\Api\ManagedFormController::class, 'signoffs']);
        // Send a completed copy to the address configured on its form (see emailSignoff).
        Route::post  ('/admin/managed-forms/signoffs/{id}/email', [\App\Http\Controllers\Api\ManagedFormController::class, 'emailSignoff'])->where('id', '[0-9]+');
        /* Review + counter-sign a form the parent already completed, and return the
           finished document to BOTH sides. Admin-only; the controller re-checks and
           scopes to the active agency. */
        Route::post  ('/admin/managed-forms/signoffs/{id}/countersign', [\App\Http\Controllers\Api\ManagedFormController::class, 'countersign'])->where('id', '[0-9]+');
        // Share a counter-signed form with staff (filed to their Documents) or any address (2026-10-20).
        Route::get   ('/admin/managed-forms/share-recipients',      [\App\Http\Controllers\Api\ManagedFormController::class, 'shareRecipients']);
        Route::post  ('/admin/managed-forms/signoffs/{id}/share',   [\App\Http\Controllers\Api\ManagedFormController::class, 'share'])->where('id', '[0-9]+')->middleware('throttle:20,1,fmshare');
        Route::post  ('/admin/managed-forms/signoffs/sync-documents',   [\App\Http\Controllers\Api\ManagedFormController::class, 'syncDocuments']);
        Route::post  ('/admin/managed-forms/signoffs/{id}/return',      [\App\Http\Controllers\Api\ManagedFormController::class, 'returnToSigner'])->where('id', '[0-9]+');
        Route::delete('/admin/managed-forms/signoffs/{id}',       [\App\Http\Controllers\Api\ManagedFormController::class, 'deleteSignoff'])->where('id', '[0-9]+');
        Route::get   ('/admin/managed-forms/{id}/signoff/{sid}',  [\App\Http\Controllers\Api\ManagedFormController::class, 'signoffDetail']);
        // What has been sent, for the history table.
        /* Request files — the mirror of a form package: the agency asks for documents the
           family already has, and they arrive on that person's own record. */
        Route::get   ('/admin/file-requests',                     [\App\Http\Controllers\Api\FileRequestController::class, 'index']);
        Route::post  ('/admin/file-requests',                     [\App\Http\Controllers\Api\FileRequestController::class, 'store']);
        Route::get   ('/admin/file-requests/{id}',                [\App\Http\Controllers\Api\FileRequestController::class, 'show'])->whereNumber('id');
        /* The signed upload link that was emailed, so staff can reopen or re-share it
           when a family says they never got it. */
        Route::get   ('/admin/file-requests/{id}/link',            [\App\Http\Controllers\Api\FileRequestController::class, 'link'])->whereNumber('id');
        Route::post  ('/admin/file-requests/{id}/remind',         [\App\Http\Controllers\Api\FileRequestController::class, 'remind'])->whereNumber('id');
        Route::delete('/admin/file-requests/{id}',                [\App\Http\Controllers\Api\FileRequestController::class, 'destroy'])->whereNumber('id');
        Route::get   ('/admin/file-requests/{id}/files/{doc}/download', [\App\Http\Controllers\Api\FileRequestController::class, 'download'])->whereNumber('id')->whereNumber('doc');

        Route::get   ('/admin/managed-forms/packages',            [\App\Http\Controllers\Api\ManagedFormController::class, 'packages']);
        Route::get   ('/admin/managed-forms/packages/{id}',       [\App\Http\Controllers\Api\ManagedFormController::class, 'packageDetail'])->whereNumber('id');
        Route::post  ('/admin/managed-forms/packages/{id}/resend', [\App\Http\Controllers\Api\ManagedFormController::class, 'packageResend'])->whereNumber('id');
        Route::post  ('/admin/managed-forms/packages/{id}/redeliver', [\App\Http\Controllers\Api\ManagedFormController::class, 'packageRedeliver'])->whereNumber('id');
        Route::post  ('/admin/managed-forms/packages/{id}/resend-one', [\App\Http\Controllers\Api\ManagedFormController::class, 'packageResendOne'])->whereNumber('id');
        Route::delete('/admin/managed-forms/packages/{id}',       [\App\Http\Controllers\Api\ManagedFormController::class, 'packageDelete'])->whereNumber('id');
        // Several forms to several people in one action, with one email listing them.
        Route::post  ('/admin/managed-forms/bulk-assign',         [\App\Http\Controllers\Api\ManagedFormController::class, 'bulkAssign']);
        Route::get   ('/admin/managed-forms',                     [\App\Http\Controllers\Api\ManagedFormController::class, 'index']);
        Route::post  ('/admin/managed-forms',                     [\App\Http\Controllers\Api\ManagedFormController::class, 'store']);
        Route::patch ('/admin/managed-forms/{id}',                [\App\Http\Controllers\Api\ManagedFormController::class, 'update']);
        Route::delete('/admin/managed-forms/{id}',                [\App\Http\Controllers\Api\ManagedFormController::class, 'destroy']);
        Route::get   ('/managed-forms/mine',                      [\App\Http\Controllers\Api\ManagedFormController::class, 'mine']);
        Route::get   ('/managed-forms/assigned',                  [\App\Http\Controllers\Api\ManagedFormController::class, 'assigned']);
        Route::post  ('/managed-forms/{id}/sign',                 [\App\Http\Controllers\Api\ManagedFormController::class, 'sign']);
        Route::post  ('/managed-forms/{id}/draft',                [\App\Http\Controllers\Api\ManagedFormController::class, 'draft']);
        Route::get   ('/provider/children/{child}',  [\App\Http\Controllers\Api\EducatorSelfController::class, 'childRecord'])->middleware(KT_PROVIDER_ROLES);
        Route::get   ('/admin/tours',               [\App\Http\Controllers\Api\CareController::class, 'listTours']);
        Route::patch ('/admin/tours/{id}',          [\App\Http\Controllers\Api\CareController::class, 'updateTour']);

        // v22p34 — Marketing campaigns (directors + agency_admin + platform_admin).
        // Route-level role gate kept lenient; controller does the agency-scope check
        // via getAgencyId() so platform_admin in a tenant context Just Works.
        Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
            // Reusable headers and footers — uploaded once, picked per campaign.
            Route::get   ('/marketing/assets',              [\App\Http\Controllers\Api\MarketingController::class, 'assetsIndex']);
            Route::post  ('/marketing/assets',              [\App\Http\Controllers\Api\MarketingController::class, 'assetsStore']);
            Route::delete('/marketing/assets/{id}',         [\App\Http\Controllers\Api\MarketingController::class, 'assetsDestroy']);
            Route::get   ('/marketing/campaigns',           [\App\Http\Controllers\Api\MarketingController::class, 'index']);
            // Reports → Campaign report (2026-10-31): the agency's campaigns + announcements with recorded delivery/opens.
            Route::get   ('/marketing/campaigns-report',    [\App\Http\Controllers\Api\MarketingController::class, 'report'])->middleware('throttle:60,1,r-campaign-report');
            Route::post  ('/marketing/campaigns',           [\App\Http\Controllers\Api\MarketingController::class, 'store']);
            Route::get   ('/marketing/campaigns/{id}',      [\App\Http\Controllers\Api\MarketingController::class, 'show']);
            Route::patch ('/marketing/campaigns/{id}',      [\App\Http\Controllers\Api\MarketingController::class, 'update']);
            Route::delete('/marketing/campaigns/{id}',      [\App\Http\Controllers\Api\MarketingController::class, 'destroy']);
            Route::post  ('/marketing/campaigns/{id}/send', [\App\Http\Controllers\Api\MarketingController::class, 'sendNow']);
            Route::get   ('/marketing/senders',             [\App\Http\Controllers\Api\MarketingController::class, 'senders']);
            Route::post  ('/marketing/images',              [\App\Http\Controllers\Api\MarketingController::class, 'uploadImage']);
            // Agency-wide accounting view of externally-synced (iLearn) invoices —
            // admins + directors (+ platform_admin in tenant context). Parents use
            // /parent/external-invoices, scoped to their own family.
            /* MANUAL PAYROLL — pay several people for one period, in one pass.

               Payroll has only ever arrived from outside (iLearn pushes documents in
               through the integration); this is how an agency pays somebody the
               platform itself knows about. It writes payslips and therefore moves
               money, so agency_admin / platform_admin only — a centre director runs a
               site, not the payroll. */
            Route::middleware('role:agency_admin,platform_admin')->group(function () {
                Route::get ('/admin/payroll/manual/prepare', [\App\Http\Controllers\Api\ManualPayrollController::class, 'prepare']);
                Route::post('/admin/payroll/manual',         [\App\Http\Controllers\Api\ManualPayrollController::class, 'commit']);
            });

            /* THE PRIVACY BREACH REGISTER (2026-11-06) — PIPEDA s. 10.3: every breach of
               security safeguards involving personal information is recorded and kept 24
               months, reported or not. The agency is the accountable organization, so its
               admins only; no delete route on purpose. Not plan-gated: a legal duty. */
            // Centre directors READ their centres' records (they get the alert emails, PrivacyBreachAlerts);
            // the controller scopes them and refuses every write.
            Route::middleware('role:agency_admin,platform_admin,centre_director')->group(function () {
                Route::get  ('/compliance/privacy-breaches',            [\App\Http\Controllers\Api\PrivacyBreachController::class, 'index']);
                Route::get  ('/compliance/privacy-breaches/{id}',       [\App\Http\Controllers\Api\PrivacyBreachController::class, 'show'])->where('id', '[0-9]+');
            });
            Route::middleware('role:agency_admin,platform_admin')->group(function () {
                Route::get  ('/compliance/privacy-breaches/export',     [\App\Http\Controllers\Api\PrivacyBreachController::class, 'export']);
                Route::get  ('/compliance/privacy-breaches/alert-settings', [\App\Http\Controllers\Api\PrivacyBreachController::class, 'alertSettings']);
                Route::post ('/compliance/privacy-breaches/alert-settings', [\App\Http\Controllers\Api\PrivacyBreachController::class, 'saveAlertSettings']);
                Route::post ('/compliance/privacy-breaches',            [\App\Http\Controllers\Api\PrivacyBreachController::class, 'store']);
                Route::patch('/compliance/privacy-breaches/{id}',       [\App\Http\Controllers\Api\PrivacyBreachController::class, 'update'])->where('id', '[0-9]+');
                Route::post ('/compliance/privacy-breaches/{id}/notes', [\App\Http\Controllers\Api\PrivacyBreachController::class, 'note'])->where('id', '[0-9]+');
            });

            /* PRIVACY REQUESTS (2026-11-10) - PIPEDA Principle 4.9 / s. 8: a person asks to see, correct or delete what
               is held about them or their child; 30 days to answer. Agency admins only; no delete route on purpose.
               Not plan-gated: a legal duty. */
            Route::middleware('role:agency_admin,platform_admin')->group(function () {
                Route::get  ('/compliance/privacy-requests',              [\App\Http\Controllers\Api\PrivacyRequestController::class, 'index']);
                Route::get  ('/compliance/privacy-requests/subjects',     [\App\Http\Controllers\Api\PrivacyRequestController::class, 'subjects']);
                Route::get  ('/compliance/privacy-requests/{id}',         [\App\Http\Controllers\Api\PrivacyRequestController::class, 'show'])->where('id', '[0-9]+');
                Route::get  ('/compliance/privacy-requests/{id}/export',  [\App\Http\Controllers\Api\PrivacyRequestController::class, 'export'])->where('id', '[0-9]+');
                Route::post ('/compliance/privacy-requests',              [\App\Http\Controllers\Api\PrivacyRequestController::class, 'store']);
                Route::patch('/compliance/privacy-requests/{id}',         [\App\Http\Controllers\Api\PrivacyRequestController::class, 'update'])->where('id', '[0-9]+');
                Route::post ('/compliance/privacy-requests/{id}/notes',   [\App\Http\Controllers\Api\PrivacyRequestController::class, 'note'])->where('id', '[0-9]+');
            });

            /* THE AGENCY CONTACT BOOK — the drawer of business cards, digitised.

               Not emergency_contacts, which belongs to a CHILD ("who to call about
               Aria"). This is the agency's own directory: the plumber, the food
               inspector, the insurance broker, the landlord — people who currently
               live in one person's phone and leave with them.

               Centre directors are IN this group on purpose, and that is why it is not
               folded into the payroll-bearing one below: a director is the person who
               actually deals with the plumber, and a directory only they cannot edit is
               a directory that goes stale. Nothing here exposes pay or family money. */
            Route::middleware('role:agency_admin,centre_director,platform_admin')->group(function () {
                Route::get   ('/contacts',           [\App\Http\Controllers\Api\ContactController::class, 'index']);
                Route::post  ('/contacts',           [\App\Http\Controllers\Api\ContactController::class, 'store']);
                Route::patch ('/contacts/{id}',      [\App\Http\Controllers\Api\ContactController::class, 'update'])->where('id', '[0-9]+');
                Route::delete('/contacts/{id}',      [\App\Http\Controllers\Api\ContactController::class, 'destroy'])->where('id', '[0-9]+');
                Route::post  ('/contacts/scan-card', [\App\Http\Controllers\Api\ContactController::class, 'scanCard']);
            });

            /* One ledger per ACCOUNT, both directions — what a person owes the agency
               (through their family) and what the agency paid them (through their user
               id). Nested tighter than the group it sits in: this exposes payroll, so
               agency_admin / platform_admin only, never centre_director. */
            Route::middleware('role:agency_admin,platform_admin')->group(function () {
                Route::get('/admin/account-ledgers',        [\App\Http\Controllers\Api\AccountLedgerController::class, 'index']);
                Route::get('/admin/account-ledgers/{user}', [\App\Http\Controllers\Api\AccountLedgerController::class, 'show'])->where('user', '[0-9]+');
                // The same statement, in the agency's own branding, to the account
                // holder or an address the admin names. Audited with the address.
                Route::get('/admin/account-ledgers/{user}/statement.pdf', [\App\Http\Controllers\Api\AccountLedgerController::class, 'statementPdf'])->where('user', '[0-9]+');
                Route::post('/admin/account-ledgers/{user}/email', [\App\Http\Controllers\Api\AccountLedgerController::class, 'emailStatement'])->where('user', '[0-9]+');
                /* One invoice, to one address. The kind travels because native and
                   external invoice ids OVERLAP (native 9..64, external 2..464), so
                   guessing would fetch a different family's invoice. */
                Route::post('/admin/invoices/{kind}/{id}/email', [\App\Http\Controllers\Api\AccountLedgerController::class, 'emailInvoice'])
                    ->where('kind', 'native|external')->where('id', '[0-9]+');
                // The resend popup's guardians and staff to copy (2026-10-31).
                Route::get('/admin/invoices/{kind}/{id}/email-recipients', [\App\Http\Controllers\Api\AccountLedgerController::class, 'emailRecipients'])
                    ->where('kind', 'native|external')->where('id', '[0-9]+');
            });

            Route::get   ('/agency/external-invoices',       [\App\Http\Controllers\Api\InvoiceController::class, 'externalForAgency']);
            Route::patch ('/agency/external-invoices/{id}', [\App\Http\Controllers\Api\InvoiceController::class, 'updateExternalInvoice'])->where('id','[0-9]+');
            /* Cancel a synced invoice in KiddieTrac's copy. The next sync may restore it;
               the dialog says so. */
            Route::post  ('/agency/external-invoices/{id}/void', [\App\Http\Controllers\Api\InvoiceController::class, 'voidExternalInvoice'])->where('id','[0-9]+');
            /* Cash / EFT that arrived outside the payment rails, against a synced
               invoice. Partial is normal; the totals are derived from the rows. */
            Route::post  ('/agency/external-invoices/{id}/payments', [\App\Http\Controllers\Api\InvoiceController::class, 'recordExternalPayment'])->where('id','[0-9]+');
            /* Who a resend would go to. Shown and editable, never assumed. */
            Route::get   ('/agency/families/{family}/billing-contacts', [\App\Http\Controllers\Api\InvoiceController::class, 'billingContacts'])->where('family','[0-9]+');
            /* Service-fee rates, so a dialog can show the fee before charging it. */
            Route::get   ('/agency/payment-surcharges', [\App\Http\Controllers\Api\InvoiceController::class, 'surchargeRates']);
            // Open ONE synced invoice as STAFF. The /parent/ twin resolves the caller's
            // families through `guardians`, so it 403s for every admin and director —
            // which is what "could not open that invoice" was.
            Route::get   ('/agency/external-invoices/{id}/link', [\App\Http\Controllers\Api\InvoiceController::class, 'externalInvoiceLinkForAgency'])->where('id','[0-9]+');
            Route::get   ('/agency/waitlist',      [\App\Http\Controllers\Api\ExternalWaitlistController::class, 'index']);
            Route::post  ('/agency/waitlist',      [\App\Http\Controllers\Api\ExternalWaitlistController::class, 'store']);
            Route::patch ('/agency/waitlist/{id}', [\App\Http\Controllers\Api\ExternalWaitlistController::class, 'update'])->where('id','[0-9]+');
            Route::patch ('/agency/waitlist/{id}/status', [\App\Http\Controllers\Api\ExternalWaitlistController::class, 'setStatus'])->where('id','[0-9]+');
            Route::post  ('/agency/waitlist/{id}/reach-out', [\App\Http\Controllers\Api\ExternalWaitlistController::class, 'reachOut'])->where('id','[0-9]+');
            Route::post  ('/agency/waitlist/{id}/enroll', [\App\Http\Controllers\Api\ExternalWaitlistController::class, 'enroll'])->where('id','[0-9]+');
            /* Families from a closed provider (#251): wait on the agency list, then get placed. */
            Route::post  ('/agency/waitlist/{id}/assign', [\App\Http\Controllers\Api\ExternalWaitlistController::class, 'assign'])->where('id','[0-9]+');
            Route::post  ('/agency/waitlist/from-families', [\App\Http\Controllers\Api\ExternalWaitlistController::class, 'fromFamilies']);
            Route::delete('/agency/waitlist/{id}', [\App\Http\Controllers\Api\ExternalWaitlistController::class, 'destroy'])->where('id','[0-9]+');
        });

        Route::prefix('parent')->middleware('role:guardian')->group(function () {
            Route::get('/play-spark', [\App\Http\Controllers\Api\PlaySparkController::class, 'parent']);   // Play Spark at home (2026-11-24, AI Bundle)
            Route::get('/dashboard', [FamilyController::class, 'parentDashboard']);
            // Report an issue to the centre (2026-10-13).
            Route::get('/issues/options', [\App\Http\Controllers\Api\ParentIssueController::class, 'parentOptions']);
            Route::get('/issues', [\App\Http\Controllers\Api\ParentIssueController::class, 'parentIndex']);
            Route::post('/issues', [\App\Http\Controllers\Api\ParentIssueController::class, 'parentStore'])->middleware('throttle:10,1,r-issues');
            Route::post('/issues/{id}/messages', [\App\Http\Controllers\Api\ParentIssueController::class, 'parentMessage'])->whereNumber('id')->middleware('throttle:20,1,r-issues-x-messages');
            // Family & pick-ups: a parent asks the centre for a contact's pick-up login (2026-10-13).
            Route::get('/family-contacts', [\App\Http\Controllers\Api\PickupLoginController::class, 'parentContacts']);
            Route::post('/family-contacts/{ec}/login-request', [\App\Http\Controllers\Api\PickupLoginController::class, 'parentRequest'])->whereNumber('ec')->middleware('throttle:10,1,r-family-contacts-x-login-request');
            // Parents keep their own pick-up list (2026-10-13): add / edit / remove, staff told, logged.
            Route::post('/family-contacts', [\App\Http\Controllers\Api\PickupLoginController::class, 'parentAdd'])->middleware('throttle:20,1,r-family-contacts');
            Route::patch('/family-contacts/{ec}', [\App\Http\Controllers\Api\PickupLoginController::class, 'parentUpdate'])->whereNumber('ec')->middleware('throttle:30,1,r-family-contacts-x');
            Route::delete('/family-contacts/{ec}', [\App\Http\Controllers\Api\PickupLoginController::class, 'parentRemove'])->whereNumber('ec')->middleware('throttle:20,1,r-family-contacts-x-1533');
            Route::delete('/pickup-login-requests/{id}', [\App\Http\Controllers\Api\PickupLoginController::class, 'parentCancel'])->whereNumber('id');
            Route::get('/children', [FamilyController::class, 'myChildren']);
            Route::post('/withdrawals', [\App\Http\Controllers\Api\WithdrawalController::class, 'submit']);
            Route::get('/withdrawals', [\App\Http\Controllers\Api\WithdrawalController::class, 'mine']);
            Route::get('/children/{child}', [ChildController::class, 'show']);
            Route::get('/children/{child}/timeline', [DailyEventController::class, 'timeline']);
            Route::get('/children/{child}/digest/{date}', [DailyEventController::class, 'digest']);
            Route::get('/children/{child}/invoices', [InvoiceController::class, 'forChild']);
            // Account ledger + billing stats for the parent's Billing screen (2026-10-28).
            Route::get('/children/{child}/ledger', [InvoiceController::class, 'ledgerForChild']);
            Route::get('/external-invoices', [InvoiceController::class, 'externalForParent']);
            // Open ONE externally-issued invoice. Returns the provider's link after
            // checking the guardian belongs to the family it was raised against.
            Route::get('/external-invoices/{id}/link', [InvoiceController::class, 'externalInvoiceLinkForParent'])->where('id', '[0-9]+');
            Route::get('/children/{child}/photos', [MediaController::class, 'forChild']);
            Route::get('/children/{child}/observations', [MediaController::class, 'observationsForChild']);
            Route::get('/messages', [MessageController::class, 'myConversations']);
            // These static paths MUST precede /messages/{conversation} or they'd bind as an id.
            Route::get('/messages/unread-count', [MessageController::class, 'unreadCount']);
            Route::post('/messages/nudge', [MessageController::class, 'nudge'])->middleware('throttle:6,1,r-messages-nudge');
            Route::post('/messages/call', [MessageController::class, 'call'])->middleware('throttle:10,1,r-chat-call');
            Route::get('/messages/{conversation}', [MessageController::class, 'show']);
            Route::post('/messages/{conversation}/typing', [MessageController::class, 'typing'])->where('conversation','[0-9]+');
            Route::post('/messages/{conversation}/react/{message}', [MessageController::class, 'reactMessage'])->where(['conversation'=>'[0-9]+','message'=>'[0-9]+']);
            Route::post('/messages', [MessageController::class, 'sendToRoom']);
            Route::patch('/messages/{message}', [MessageController::class, 'editMessage']);
            Route::delete('/messages/{message}', [MessageController::class, 'deleteMessage']);
            Route::get('/notifications', [NotificationController::class, 'mine']);
            Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

            // v20: incident workflow (parent side)
            Route::get('/incidents',                       [IncidentController::class, 'index']);
            Route::get('/incidents/{id}',                  [IncidentController::class, 'show']);
            Route::post('/incidents/{id}/acknowledge',     [IncidentController::class, 'acknowledge']);
            // The family's own words, and a request to meet — both on the record.
            Route::post('/incidents/{id}/feedback',        [IncidentController::class, 'parentFeedback']);

            // Child awards (parent view)
            Route::get('/awards',                          [\App\Http\Controllers\Api\AwardController::class, 'forParent']);
            // Report cards (parent view) + asking for this quarter's card (2026-11-04)
            Route::get ('/report-cards',                   [\App\Http\Controllers\Api\ParentReportCardController::class, 'index']);
            Route::post('/report-cards/request',           [\App\Http\Controllers\Api\ParentReportCardController::class, 'request'])->middleware('throttle:20,1');
            Route::get ('/report-cards/{id}/link',         [\App\Http\Controllers\Api\ParentReportCardController::class, 'link']);

            // v22p1: Parent reads of child health
            Route::get('/children/{child}/health',         [ChildHealthController::class, 'show']);
            Route::get('/children/{child}/medications',    [MedicationController::class, 'parentList']);
            /* The parent nav has always offered "Your child's attendance history";
               it used to call the STAFF /attendance/weekly-overview, which is
               agency-wide and role-gated, so every guardian got a 403. Same shape,
               their own children only. */
            Route::get('/attendance/weekly-overview',      [\App\Http\Controllers\Api\AttendancePatternController::class, 'parentWeeklyOverview']);
            /* A parent ASKS; a director authorises. Files as pending_auth into the
               same queue staff already work through — see parentStore(). */
            Route::post('/children/{child}/medications',   [MedicationController::class, 'parentStore']);
            Route::get('/children/{child}/immunizations',  [ImmunizationController::class, 'parentList']);

            /* A parent handing over their child's immunization RECORD — the card, the
               clinic printout, the health-unit PDF. Distinct from the line above, which
               reads the structured per-vaccine rows staff enter. The upload files itself
               on the child's record, so it shows on the Documents tab that directors and
               admins already use; no parent DELETE, because a submitted health record is
               something the centre relies on for compliance. */
            Route::get ('/children/{child}/immunization-records',
                [\App\Http\Controllers\Api\ParentImmunizationRecordController::class, 'index'])->whereNumber('child');
            Route::post('/children/{child}/immunization-records',
                [\App\Http\Controllers\Api\ParentImmunizationRecordController::class, 'store'])->whereNumber('child');
            Route::get ('/children/{child}/immunization-records/{doc}/download',
                [\App\Http\Controllers\Api\ParentImmunizationRecordController::class, 'download'])
                ->whereNumber('child')->whereNumber('doc');

            // v22p2.2: eDocuments (parent inbox + e-sign)
            /* Documents published to this family — currently incident reports.
               An ALLOWLIST lives in the controller: a child's record holds plenty
               that is not the family's to read. */
            Route::get('/documents',                        [\App\Http\Controllers\Api\ParentDocumentController::class, 'index']);
            Route::get('/documents/{id}/download',          [\App\Http\Controllers\Api\ParentDocumentController::class, 'download'])->where('id', '[0-9]+');

            Route::get('/edocuments',                       [EDocumentController::class, 'parentIndex']);
            Route::get('/edocuments/{id}/link',             [EDocumentController::class, 'parentLink']);
            Route::post('/edocuments/{id}/sign',            [EDocumentController::class, 'sign']);
            Route::get('/edocuments/{id}/download',         [EDocumentController::class, 'parentDownload']);
        });

        /* WHAT A HOME VISITOR MAY SEE IN A ROOM SHE IS ASSIGNED TO.

           Putting somebody in a room is how this platform says WHERE they work; their
           role says WHAT they do there. A home visitor assigned to rooms could previously
           open nothing at all — not a roster, not a ratio, not a child's health record —
           because the whole provider API was gated on the educator role. Nine rooms, and
           the assignment opened nothing.

           These are the READS: the room's picture, children's files, and reporting. The
           educator's job stays in the group below — signing children in and out, the
           daily care log, giving medications, authoring observations and incidents,
           issuing awards, the parent conversation, and their own timesheet.

           Anthony, 2026-09-06: "home visitor role is not an educator role so should not
           allow to sign in and out children only access children files and educator files
           and reporting etc."

           Room scope is enforced independently of this gate and is unchanged:
           RoomController::assignedRoomIds() returns this user's own educator_rooms rows
           (null — the whole centre — only for director/admin/platform), and
           ResolvesCentreContext::canAccessChildId() demands an explicit room assignment
           with the child in that room today. Neither asks what role anybody holds, so a
           home visitor reads exactly the rooms somebody put her in. */
        Route::prefix('provider')->middleware(KT_PROVIDER_ROLES)->group(function () {
            Route::get('/bootstrap', [RoomController::class, 'bootstrap']);
            // Play Spark - on-the-spot play ideas (2026-11-24). AI Bundle only (EnforcePlanFeatures ai_tools).
            Route::get('/play-spark/context',             [\App\Http\Controllers\Api\PlaySparkController::class, 'context']);
            Route::post('/play-spark/generate',           [\App\Http\Controllers\Api\PlaySparkController::class, 'generate']);
            Route::post('/play-spark/scan',               [\App\Http\Controllers\Api\PlaySparkController::class, 'scan']);
            Route::post('/play-spark/{id}/done',          [\App\Http\Controllers\Api\PlaySparkController::class, 'done'])->whereNumber('id');
            Route::get('/play-spark/ideas',               [\App\Http\Controllers\Api\PlaySparkController::class, 'ideas']);
            Route::patch('/play-spark/ideas/{id}',        [\App\Http\Controllers\Api\PlaySparkController::class, 'update'])->whereNumber('id');
            Route::delete('/play-spark/ideas/{id}',       [\App\Http\Controllers\Api\PlaySparkController::class, 'destroy'])->whereNumber('id');
            Route::post('/play-spark/materials',          [\App\Http\Controllers\Api\PlaySparkController::class, 'addMaterials']);
            Route::delete('/play-spark/materials/{id}',   [\App\Http\Controllers\Api\PlaySparkController::class, 'removeMaterial'])->whereNumber('id');
            Route::get('/play-spark/report',              [\App\Http\Controllers\Api\PlaySparkController::class, 'report']);
            Route::get('/day-brief', [DayBriefController::class, 'brief']);
            Route::get('/day-activity', [DayBriefController::class, 'dayActivity']);

            // The room itself: who is in it, and whether it is within ratio.
            Route::get('/rooms/{room}/roster', [RoomController::class, 'roster']);
            Route::get('/rooms/{room}/ratio', [RoomController::class, 'currentRatio']);
            Route::get('/present-count', [RoomController::class, 'presentCount']);

            // Children's files. Reading a medication schedule is not giving one —
            // /medications/give stays educator-only below.
            Route::get('/medications', [MedicationController::class, 'activeForProvider']);
            Route::get('/children/{child}/health', [ChildHealthController::class, 'show']);

            // Reporting: reading and printing an incident, never writing one.
            Route::get('/incidents', [IncidentController::class, 'index']);
            Route::get('/incidents/{id}', [IncidentController::class, 'show'])->where('id', '[0-9]+');
            Route::get('/incidents/{id}/report.pdf', [IncidentController::class, 'reportPdf'])->where('id', '[0-9]+');
            Route::get('/awards', [\App\Http\Controllers\Api\AwardController::class, 'index']);
            Route::get('/awards/roster', [\App\Http\Controllers\Api\AwardController::class, 'roster']);
            Route::get('/observations', [AiObservationController::class, 'index']);
        });

        Route::prefix('provider')->middleware(KT_PROVIDER_ROLES)->group(function () {

            Route::post('/check-in', [CheckEventController::class, 'checkIn']);
            Route::post('/check-out', [CheckEventController::class, 'checkOut']);
            // Temporary pickup passes at sign-out (2026-11-24, App\Support\TempPickups).
            Route::get ('/temp-pickups',                 [\App\Http\Controllers\Api\TempPickupController::class, 'staffIndex']);
            Route::get ('/temp-pickups/child/{child}',   [\App\Http\Controllers\Api\TempPickupController::class, 'staffForChild'])->whereNumber('child');
            Route::get ('/temp-pickups/child/{child}/all', [\App\Http\Controllers\Api\TempPickupController::class, 'staffChildAll'])->whereNumber('child');
            Route::post('/temp-pickups/verify',          [\App\Http\Controllers\Api\TempPickupController::class, 'staffVerify'])->middleware('throttle:30,1,r-tp-verify');
            Route::post('/temp-pickups/alert',           [\App\Http\Controllers\Api\TempPickupController::class, 'staffAlert'])->middleware('throttle:10,1,r-tp-alert');
            Route::post('/temp-pickups/lookup',          [\App\Http\Controllers\Api\TempPickupController::class, 'staffLookup'])->middleware('throttle:15,1,r-tp-lookup');
            Route::post('/temp-pickups/{id}/cancel',     [\App\Http\Controllers\Api\TempPickupController::class, 'staffCancel'])->whereNumber('id');
            Route::get ('/temp-pickups/{id}/photo',      [\App\Http\Controllers\Api\TempPickupController::class, 'staffPhoto'])->whereNumber('id');
            Route::post('/check-in-batch', [CheckEventController::class, 'checkInBatch']);

            Route::post('/events', [DailyEventController::class, 'store']);
            // Log one thing for a whole room at once (sunscreen, snack, nap).
            Route::post('/events/bulk', [DailyEventController::class, 'storeBulk']);
            Route::patch('/events/{event}', [DailyEventController::class, 'update']);
            Route::delete('/events/{event}', [DailyEventController::class, 'destroy']);

            Route::post('/photos', [MediaController::class, 'upload']);
            Route::post('/observations', [MediaController::class, 'createObservation']);
            Route::post('/incidents', [IncidentController::class, 'store']);

            // v22p1: Medications
            Route::post('/medications/give',            [MedicationController::class, 'give']);

            // v20: incident workflow (provider/educator side) — the reads moved to the
            // group above, which home visitors may also reach.
            Route::patch('/incidents/{id}',                [IncidentController::class, 'update']);
            Route::post('/incidents/{id}/submit',          [IncidentController::class, 'submit']);
            Route::post('/incidents/{id}/notes',           [IncidentController::class, 'addNote']);
            Route::post('/incidents/{id}/email',           [IncidentController::class, 'emailReport']);
            // Move an incident deliberately; every move records why as a note.
            Route::patch('/incidents/{id}/status',         [IncidentController::class, 'setStatus']);
            // The incident as a document, on the agency's own branding.

            // Child awards (educator/director: issue + list + delete)
            Route::post('/awards',                         [\App\Http\Controllers\Api\AwardController::class, 'store']);
            Route::delete('/awards/{id}',                  [\App\Http\Controllers\Api\AwardController::class, 'destroy']);

            // v21: AI Observation Notes
            Route::post('/observations/structure',         [AiObservationController::class, 'structure']);
            Route::post('/observations/save',              [AiObservationController::class, 'save']);

            Route::get('/conversations', [MessageController::class, 'educatorConversations']);
            Route::get('/conversations/{conversation}', [MessageController::class, 'show']);
            Route::post('/conversations/{conversation}/typing', [MessageController::class, 'typing'])->where('conversation','[0-9]+');
            Route::post('/messages', [MessageController::class, 'educatorReply']);

            Route::post('/clock-in', [StaffController::class, 'clockIn']);
            Route::post('/clock-out', [StaffController::class, 'clockOut']);
        });

        Route::prefix('director')->middleware('role:centre_director,agency_admin')->group(function () {
            // v20: AI digest status board (correctly placed in director group)
            Route::get('/digest-status', [DigestStatusController::class, 'index']);
            Route::get('/withdrawals', [\App\Http\Controllers\Api\WithdrawalController::class, 'adminIndex']);
            Route::post('/withdrawals/{id}/decide', [\App\Http\Controllers\Api\WithdrawalController::class, 'decide'])->where('id','[0-9]+');
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
            /* Move a child (and, deliberately, their siblings) from one provider to
               another — one transaction instead of two manual edits with a cross-tenant
               trap between them. GET lists valid destinations with places left. */
            Route::get ('/children/{child}/transfer-targets', [ChildController::class, 'transferTargets'])->where('child', '[0-9]+');
            Route::post('/children/{child}/transfer',         [ChildController::class, 'transferChild'])->where('child', '[0-9]+');
            Route::get('/waitlist', [ChildController::class, 'waitlist']);

            Route::get('/children/{child}', [ChildController::class, 'show']);
            // Logins next to the family's people on the child record (2026-10-13), centre-scoped.
            Route::post('/emergency-contacts/{ec}/pickup-login', [\App\Http\Controllers\Api\PickupLoginController::class, 'staffGrant'])->whereNumber('ec')->middleware('throttle:20,1,r-emergency-contacts-x-pickup-login');
            Route::delete('/emergency-contacts/{ec}/pickup-login', [\App\Http\Controllers\Api\PickupLoginController::class, 'staffRevoke'])->whereNumber('ec');
            Route::post('/pickup-login-requests/{id}/decline', [\App\Http\Controllers\Api\PickupLoginController::class, 'staffDecline'])->whereNumber('id');
            Route::post('/guardians/{user}/login-invite', [\App\Http\Controllers\Api\PickupLoginController::class, 'staffInviteGuardian'])->whereNumber('user')->middleware('throttle:20,1,r-guardians-x-login-invite');
            // v22p88: child-detail tabs
            Route::get('/children/{child}/daily-events', [ChildController::class, 'dailyEvents']);
            Route::get('/children/{child}/feed',         [ChildController::class, 'feed']);
            Route::get('/children/{child}/documents',    [ChildController::class, 'documents']);
            /* Full retained history. Registered here as well as under /admin because
               this screen serves centre directors too, and the record must open for
               them the same way. */
            Route::get('/children/{child}/history', [\App\Http\Controllers\Api\RecordHistoryController::class, 'child'])->whereNumber('child');
            /* Attachments on a child record were read-only until 2026-08-25 - the tab
               listed files that nobody had any way to put there. Mirrors the per-user set. */
            /* Immunization records for staff. The same three endpoints the parent
               group has, and deliberately the same controller: its guard is
               assertChild(), which a director and an educator at the child's centre
               already satisfy, and the alert it fires excludes whoever uploaded. A
               second staff-only copy would have been two implementations of one rule.
               Directors and admins asked to be able to file these themselves — a
               record still arrives at the door on paper. */
            Route::get ('/children/{child}/immunization-records',
                [\App\Http\Controllers\Api\ParentImmunizationRecordController::class, 'index'])->whereNumber('child');
            Route::post('/children/{child}/immunization-records',
                [\App\Http\Controllers\Api\ParentImmunizationRecordController::class, 'store'])->whereNumber('child');
            Route::get ('/children/{child}/immunization-records/{doc}/download',
                [\App\Http\Controllers\Api\ParentImmunizationRecordController::class, 'download'])
                ->whereNumber('child')->whereNumber('doc');

            /* Filling in a record that arrived bare - the parent sent the photograph and
               ticked nothing. Staff-gated inside the controller, same test as doses on
               upload. Deliberately NOT mirrored on the parent routes above. */
            Route::post('/children/{child}/immunization-records/{doc}/details',
                [\App\Http\Controllers\Api\ParentImmunizationRecordController::class, 'details'])
                ->whereNumber('child')->whereNumber('doc');

            Route::post  ('/children/{child}/documents',                [ChildController::class, 'uploadDocument'])->where('child', '[0-9]+');
            Route::get   ('/children/{child}/documents/{doc}/download', [ChildController::class, 'downloadDocument'])->where(['child' => '[0-9]+', 'doc' => '[0-9]+']);
            Route::delete('/children/{child}/documents/{doc}',          [ChildController::class, 'deleteDocument'])->where(['child' => '[0-9]+', 'doc' => '[0-9]+']);
            // v22p5: full CRUD on child record (separate from enrollment updates)
            Route::patch('/children/{child}', [ChildController::class, 'update']);
            Route::delete('/children/{child}', [ChildController::class, 'destroy']);
            // A child's photo from the record's Edit form — directors (own centre) as well as admins (2026-10-09).
            Route::post('/children/{child}/photo', [ChildController::class, 'uploadPhoto'])->where('child', '[0-9]+');

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
            // Mints the five-minute signed link the phone app hands to the real browser
            // to print with. Same authorisation as the card itself — see printLink().
            Route::get('/children/{child}/emergency-card/print-link', [\App\Http\Controllers\Api\EmergencyCardController::class, 'printLink'])->whereNumber('child');
            Route::get('/rooms/{room}/emergency-cards', [\App\Http\Controllers\Api\EmergencyCardController::class, 'forRoom']);


            Route::get('/staff', [StaffController::class, 'index']);
            Route::post('/staff/invite', [StaffController::class, 'invite']);
            // Scoped like every certificate route (2026-10-19): these took any user id before.
            Route::get('/staff/{user}/certifications', [\App\Http\Controllers\Api\StaffComplianceController::class, 'certIndexFor'])->whereNumber('user');
            Route::post('/staff/{user}/certifications', [\App\Http\Controllers\Api\StaffComplianceController::class, 'certStoreFor'])->whereNumber('user');
            // The contractor flag — the announcement audience needs it and nothing
            // else in the portal could set it.
            Route::patch('/staff/{user}/contractor', [StaffController::class, 'setContractor']);
            Route::get('/staff/schedule', [StaffController::class, 'schedule']);
            Route::post('/staff/shifts', [StaffController::class, 'createShift']);

            Route::get('/compliance/overview', [CentreController::class, 'compliance']);
            Route::get('/compliance/export', [CentreController::class, 'complianceExport']);
            Route::get('/reports/attendance', [CentreController::class, 'attendanceReport']);

            Route::get('/invoices', [InvoiceController::class, 'index']);
            Route::post('/invoices/generate', [InvoiceController::class, 'generateBatch']);
            Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
            Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment']);
            // Cancel an invoice raised in error. Refuses while money is held against it.
            Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void']);
            /* Issue a schedule's draft without waiting for the 06:00 cron to reach its
               month. Draft -> sent only; the same write the cron makes. */
            Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->where('invoice', '[0-9]+');
            /* Add or deduct a charge on an existing invoice, with an optional tax rate.
               Totals are rebuilt from the lines on every change. */
            Route::post  ('/invoices/{invoice}/lines', [InvoiceController::class, 'addLine'])->where('invoice', '[0-9]+');
            Route::patch ('/invoices/{invoice}/lines/{line}', [InvoiceController::class, 'updateLine'])
                ->where('invoice', '[0-9]+')->where('line', '[0-9]+');
            /* The invoice's own description (Accounting's Description column). Notes
               only - money moves by editing a LINE, never by a free-hand total. */
            Route::patch ('/invoices/{invoice}/notes', [InvoiceController::class, 'updateNotes'])->where('invoice', '[0-9]+');
            /* Email a receipt for a payment on this invoice. */
            Route::post  ('/invoices/{invoice}/receipt', [InvoiceController::class, 'emailReceipt'])->where('invoice', '[0-9]+');
            Route::delete('/invoices/{invoice}/lines/{line}', [InvoiceController::class, 'deleteLine'])
                ->where('invoice', '[0-9]+')->where('line', '[0-9]+');

            Route::get('/incidents', [IncidentController::class, 'index']);
            Route::patch('/incidents/{incident}/review', [IncidentController::class, 'review']);

            // v20: incident workflow (director side)
            Route::get('/incidents/{id}',                       [IncidentController::class, 'show']);
            Route::post('/incidents/{id}/notify-parent',        [IncidentController::class, 'notifyParent']);
            Route::post('/incidents/{id}/email',                [IncidentController::class, 'emailReport']);
            Route::post('/incidents/{id}/notes',                [IncidentController::class, 'addNote']);
            /* The printable report. Registered here as well as under `provider`
               because a director's screen base is `/director` -- the button 404'd
               for every director and admin. */
            Route::get('/incidents/{id}/report.pdf',            [IncidentController::class, 'reportPdf']);

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
            /* Exempt a dose on the SCHEDULE, rather than only on a record somebody
               typed. Directors and agency admins: an exemption is a licensing
               position, not a note taken at the door. */
            Route::post('/children/{child}/immunization-exemption',
                [ImmunizationController::class, 'setExemption'])->whereNumber('child');

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
                // Narrated at read time so the ~10,600 rows already in the table
                // become readable too, not just the ones written from now on.
                $logs = \App\Support\AuditNarrator::narrate($logs);
                return response()->json(['logs' => $logs]);
            });
        });

        // Directors too (2026-11-13): the same overview, narrowed to their own centres in AgencyController (CentreScope).
        Route::prefix('agency')->middleware('role:agency_admin,centre_director')->group(function () {
            Route::get('/dashboard', [AgencyController::class, 'dashboard']);
        });
    
    // ─────────────────────────────────────────────────────────────────────
    // V10-MIN ADDITIONS — Admin CRUD, Branding, Stripe billing (reseller)
    // Does NOT include signup routes — existing /signup/centre stays as-is
    // ─────────────────────────────────────────────────────────────────────
    
    // PUBLIC — branding lookup (login page needs this BEFORE auth)
    // PUBLIC — Stripe webhook (for reseller subscriptions)
    // AGENCY-ADMIN ROUTES
    /* EXPENSES, OWN CENTRE (2026-10-05): suppliers, purchase orders and bills admit a centre
       director as well. App\Support\CentreScope confines them to their own centres inside
       each controller; agency admins are unchanged. */
    Route::prefix('admin')->middleware('role:agency_admin,centre_director')->group(function () {
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

        // Expense module behind its own flag — see the note on the sales group above.
        Route::get('/expenses/summary', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'summary'])
            ->middleware('feature:expenses');
        Route::get('/expense-invoices', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'index']);
        Route::post('/expense-invoices', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'store']);
        Route::get('/expense-invoices/{bill}', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'show']);
        Route::patch('/expense-invoices/{bill}', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'update']);
        Route::post('/expense-invoices/{bill}/approve', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'approve']);
        Route::post('/expense-invoices/{bill}/payments', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'recordPayment']);
        Route::post('/expense-invoices/{bill}/void', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'void']);
        Route::delete('/expense-invoices/{bill}', [\App\Http\Controllers\Api\ExpenseInvoiceController::class, 'destroy']);
    });

    Route::prefix('admin')->middleware('role:agency_admin')->group(function () {
        // White-label email: agency's own Microsoft 365 / Google outbound.
        Route::get ('/agency-mail',      [AdminController::class, 'agencyMailConfig']);
        Route::put ('/agency-mail',      [AdminController::class, 'saveAgencyMailConfig']);
        Route::post('/agency-mail/test', [AdminController::class, 'testAgencyMail']);

        // Route::get('/centres', -> moved to the director-capable admin group (2026-10-05)
        Route::post('/centres/{centre}/coords', [AdminController::class, 'saveCentreCoords']);
        // Rooms of one centre (with educators + headcount) — used by the family wizard.
        Route::get('/centres/{centre}/rooms', [AdminController::class, 'centreRooms']);
        Route::post('/centres', [AdminController::class, 'createCentre']);
        Route::patch('/centres/{centre}', [AdminController::class, 'updateCentre']);
        Route::delete('/centres/{centre}', [AdminController::class, 'archiveCentre']);
        // What closing this provider would involve. Read-only; changes nothing.
        Route::get('/centres/{centre}/offboard-plan', [\App\Http\Controllers\Api\CentreOffboardController::class, 'plan'])->whereNumber('centre');
        // Carry the plan out. Without confirm=true it previews and writes nothing.
        Route::post('/centres/{centre}/offboard', [\App\Http\Controllers\Api\CentreOffboardController::class, 'execute'])->whereNumber('centre');
        Route::delete('/centres/{centre}/permanent', [AdminController::class, 'permanentDeleteCentre']);
        /* Everything a provider left behind - children with dates, staff, payroll,
           billing, documents. Read-only, admin-gated inside the controller, and it works
           for a LIVE provider as well as an archived one: "what has happened here" is the
           same question either way. (2026-09-21) */
        Route::get('/centres/{centre}/history', [\App\Http\Controllers\Api\ProviderHistoryController::class, 'show'])->whereNumber('centre');
        Route::post('/centres/{centre}/restore', [AdminController::class, 'restoreCentre']);
        // v22p3.4: per-centre branding logo upload
        Route::post('/centres/{centre}/logo', [AdminController::class, 'uploadCentreLogo']);
    
        // Route::get('/users', -> moved to the director-capable admin group (2026-10-05)
        Route::get('/username-available', [AdminController::class, 'usernameAvailable']);
        Route::post('/users', [AdminController::class, 'createUser']);
        // PATCH /users/{user}, GET /users/{user}/access-summary and GET|PUT /users/{user}/profile
        // -> moved to the director-capable admin group (2026-10-07)
        Route::post('/users/{user}/notes', [AdminController::class, 'addUserNote']);
        Route::post('/users/{user}/role', [AdminController::class, 'setUserRole']);
        // v22p1.2: user lifecycle
        /* Off-boarding a member of staff: what it would involve, then do it.
           destroyUser below closes the ACCOUNT; this wraps it with the handover —
           rooms, forgotten punches, outstanding tasks. */
        Route::get ('/users/{user}/offboard-plan', [\App\Http\Controllers\Api\StaffOffboardController::class, 'plan'])->whereNumber('user');
        Route::post('/users/{user}/offboard',      [\App\Http\Controllers\Api\StaffOffboardController::class, 'execute'])->whereNumber('user');
        Route::delete('/users/{user}', [AdminController::class, 'destroyUser']);
        Route::post('/users/{user}/reactivate', [AdminController::class, 'reactivateUser']);
        // The pay and security half of a person's record, for the shared account panes.
        // Masked by design — see the controller.
        Route::get('/users/{user}/account-profile', [AdminController::class, 'accountProfile'])->whereNumber('user');
        Route::post('/users/{user}/reset-password', [AdminController::class, 'resetUserPassword']);
        Route::post('/users/{user}/resend-welcome', [AdminController::class, 'resendWelcome']);
        // v22p3.2: avatars
        Route::post('/users/{user}/avatar', [AdminController::class, 'uploadAvatar']);
        Route::post('/child-photo', [AdminController::class, 'uploadChildPhoto']);
        // v23: per-user files / documents (NDA auto-filed here; admins can attach more)
        Route::get('/users/{user}/documents', [AdminController::class, 'userDocuments']);
        Route::post('/users/{user}/documents', [AdminController::class, 'uploadUserDocument']);
        Route::get('/users/{user}/documents/{doc}/download', [AdminController::class, 'downloadUserDocument']);
        Route::delete('/users/{user}/documents/{doc}', [AdminController::class, 'deleteUserDocument']);
        // v22p3.5: reopen onboarding wizard for a user
        Route::post('/users/{user}/reopen-onboarding', [AdminController::class, 'reopenOnboarding']);
    
        // Route::get('/families', -> moved to the director-capable admin group (2026-10-05)
        // v22p9: sibling discount tiers (per-agency)
        Route::get('/sibling-discounts', [\App\Http\Controllers\Api\SiblingDiscountController::class, 'show']);
        // v22p8 Phase A: custom roles CRUD
        Route::get('/permission-keys', [\App\Http\Controllers\Api\RoleController::class, 'permissionCatalog']);
        Route::get('/roles', [\App\Http\Controllers\Api\RoleController::class, 'index']);
        Route::post('/roles', [\App\Http\Controllers\Api\RoleController::class, 'store']);
        Route::get('/roles/{role}', [\App\Http\Controllers\Api\RoleController::class, 'show']);
        Route::patch('/roles/{role}', [\App\Http\Controllers\Api\RoleController::class, 'update']);
        Route::delete('/roles/{role}', [\App\Http\Controllers\Api\RoleController::class, 'destroy']);
        // Permissions step 1 (2026-10-06): platform-only watch-only check + kill switch (controller checks platform_admin).
        Route::get('/permission-shadow', [\App\Http\Controllers\Api\RoleController::class, 'shadowSummary']);
        Route::post('/permission-shadow/mode', [\App\Http\Controllers\Api\RoleController::class, 'shadowMode']);
        Route::patch('/sibling-discounts', [\App\Http\Controllers\Api\SiblingDiscountController::class, 'update']);
        // v22p4.6: global portal search — moved out of this group in v22p42 so
        // centre_director can use the Cmd-K palette too. See director-search
        // group near end of this file.
        // v22p42: presence (online users via personal_access_tokens.last_used_at)
        Route::get('/presence', [AdminController::class, 'presence']);
        // v22p47: compliance dashboard + agency-wide children list (+ CSV)
        // GET /compliance -> moved to the director-capable admin group (2026-10-05)
        // Route::get('/children', -> moved to the director-capable admin group (2026-10-05)
        // v22p42: bulk invoice generation by centre (extends director endpoint with centre_id arg)
        Route::post('/invoices/generate-batch', [\App\Http\Controllers\Api\InvoiceController::class, 'generateBatchByCentre']);
        // #12: bulk-invoice kebab — list a centre's invoices for a period + email them to families.
        Route::get ('/invoices/by-centre',  [\App\Http\Controllers\Api\InvoiceController::class, 'listByCentre']);
        Route::post('/invoices/email-batch', [\App\Http\Controllers\Api\InvoiceController::class, 'emailBatch']);

        // v22p43: custom forms builder — admin side (CRUD + responses)
        Route::get('/forms', [\App\Http\Controllers\Api\FormsController::class, 'index']);
        Route::post('/forms', [\App\Http\Controllers\Api\FormsController::class, 'store']);
        Route::get('/forms/{form}', [\App\Http\Controllers\Api\FormsController::class, 'show']);
        Route::patch('/forms/{form}', [\App\Http\Controllers\Api\FormsController::class, 'update']);
        Route::delete('/forms/{form}', [\App\Http\Controllers\Api\FormsController::class, 'destroy']);
        Route::get('/forms/{form}/responses', [\App\Http\Controllers\Api\FormsController::class, 'listResponses']);
        Route::post('/forms/{form}/email', [\App\Http\Controllers\Api\FormsController::class, 'emailToParents']);
        Route::patch('/guardians/{guardian}', [AdminController::class, 'updateGuardian']);
        /* The family record's own editing surface: notes with an author and a date, and
           unlinking a guardian. Adding a child and inviting a guardian already had
           endpoints (enrollments / families.invite) that nothing on screen called. */
        Route::post  ('/families/{family}/notes',                [\App\Http\Controllers\Api\FamilyRecordController::class, 'addNote']);
        Route::delete('/families/{family}/notes/{note}',         [\App\Http\Controllers\Api\FamilyRecordController::class, 'deleteNote']);
        Route::post  ('/families/{family}/notes/{note}/pin',     [\App\Http\Controllers\Api\FamilyRecordController::class, 'pinNote']);
        Route::delete('/families/{family}/guardians/{guardian}', [\App\Http\Controllers\Api\FamilyRecordController::class, 'removeGuardian']);

        Route::post('/families/{family}/emergency-contacts', [AdminController::class, 'addEmergencyContact']);
        Route::patch('/emergency-contacts/{id}', [AdminController::class, 'updateEmergencyContact']);
        Route::delete('/emergency-contacts/{id}', [AdminController::class, 'deleteEmergencyContact']);
        // v22p11: agency_admin family CRUD
        Route::post('/families', [AdminController::class, 'createFamily']);
        Route::patch('/families/{family}', [AdminController::class, 'updateFamily']);
        // v22p46: family delete (soft) — keeps children + audit history intact
        /* What a family still owes — the de-enrolment dialog reads this first, so the
           admin sees the figure before the accounts (and the invoices) close. */
        /* Everything that has LEFT the agency, with the retention clock on each —
           de-enrolling keeps records rather than deleting them, and nothing in the
           portal could see them until now. */
        Route::get('/archived', [AdminController::class, 'archivedIndex']);
        /* EVERYTHING held about one child or family — 57 tables carry a child_id or
           family_id, and a retained record you cannot read is not really retained. */
        Route::get('/children/{child}/history', [\App\Http\Controllers\Api\RecordHistoryController::class, 'child'])->whereNumber('child');
        Route::get('/families/{family}/history', [\App\Http\Controllers\Api\RecordHistoryController::class, 'family'])->whereNumber('family');
        Route::get('/families/{family}/outstanding', [AdminController::class, 'familyBalance'])->whereNumber('family');
        Route::delete('/families/{family}', [AdminController::class, 'destroyFamily']);
        Route::post('/families/{family}/provider-welcome', [AdminController::class, 'resendProviderWelcome']);
        // Who would receive what, so the confirm can show it before anything is sent.
        Route::get('/families/{family}/welcome-preflight', [AdminController::class, 'welcomePreflight']);
        Route::get('/email-template/provider-welcome', [AdminController::class, 'getProviderWelcomeTemplate']);
        Route::put('/email-template/provider-welcome', [AdminController::class, 'saveProviderWelcomeTemplate']);
        Route::post('/email-template/provider-welcome/test', [AdminController::class, 'testProviderWelcomeTemplate'])->middleware('throttle:10,1,ettest');
        Route::post('/email-template/provider-welcome/preview', [AdminController::class, 'previewProviderWelcomeTemplate']);
        // Generic multi-template editor (#77). provider-welcome above is a static
        // route so it wins over these {key} wildcards; the rest hit the registry.
        Route::get('/email-templates', [AdminController::class, 'emailTemplateList']);
        // The full inventory — everything the system sends, not just the four that
        // are editable. Read-only; the editor routes below still own changes.
        Route::get('/email-catalogue', [AdminController::class, 'emailCatalogue']);
        // Every email, previewable and (nearly all) editable — the popups on Settings →
        // Email templates (2026-10-06). The registry templates keep /email-template/{key}.
        Route::get('/email-library', [\App\Http\Controllers\Api\EmailLibraryController::class, 'index']);
        Route::get('/email-library/{key}', [\App\Http\Controllers\Api\EmailLibraryController::class, 'show'])->where('key', '[a-z0-9-]+');
        Route::put('/email-library/{key}', [\App\Http\Controllers\Api\EmailLibraryController::class, 'save'])->where('key', '[a-z0-9-]+');
        Route::post('/email-library/{key}/preview', [\App\Http\Controllers\Api\EmailLibraryController::class, 'preview'])->where('key', '[a-z0-9-]+')->middleware('throttle:60,1,etpreview');
        Route::post('/email-library/{key}/test', [\App\Http\Controllers\Api\EmailLibraryController::class, 'test'])->where('key', '[a-z0-9-]+')->middleware('throttle:10,1,ettest');
        Route::get('/email-template/{key}', [AdminController::class, 'getEmailTemplate']);
        Route::put('/email-template/{key}', [AdminController::class, 'saveEmailTemplate']);
        Route::post('/email-template/{key}/test', [AdminController::class, 'testEmailTemplate'])->middleware('throttle:10,1,ettest');
        Route::post('/email-template/{key}/preview', [AdminController::class, 'previewEmailTemplate']);
        Route::post('/families/{family}/suspend', [AdminController::class, 'suspendFamily']);
        Route::post('/families/{family}/reactivate', [AdminController::class, 'reactivateFamily']);
        // Undo a de-enrolment, so a returning family is not re-created from scratch.
        Route::post('/families/{family}/restore', [AdminController::class, 'restoreFamily']);
        // Move a whole family to another provider (siblings travel together).
        Route::get ('/families/{family}/transfer-targets', [ChildController::class, 'familyTransferTargets'])->whereNumber('family');
        Route::post('/families/{family}/transfer',         [ChildController::class, 'familyTransfer'])->whereNumber('family');
    
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
        /* Private 1:1 threads for parents — the other end of a broadcast.
           Reuses the colleague-thread controller because those methods authorise on
           PARTICIPATION alone, not on being staff: show()/send() call isParticipant(),
           and threads() lists only threads you are in. contacts()/start() carry the
           staff-only gate and are deliberately NOT exposed here. */
        Route::get ('/threads',                    [\App\Http\Controllers\Api\TeamChatController::class, 'threads']);
        Route::get ('/threads/unread-count',       [\App\Http\Controllers\Api\TeamChatController::class, 'unreadCount']);
        Route::get ('/threads/{thread}',           [\App\Http\Controllers\Api\TeamChatController::class, 'show'])->where('thread', '[0-9]+');
        Route::post('/threads/{thread}/send',      [\App\Http\Controllers\Api\TeamChatController::class, 'send'])->where('thread', '[0-9]+');
        Route::post('/threads/{thread}/messages/{message}/react', [\App\Http\Controllers\Api\TeamChatController::class, 'react'])->where(['thread' => '[0-9]+', 'message' => '[0-9]+']);
        Route::post('/chats/start',                [ChatController::class, 'parentStart']);
        Route::get('/chats/{conversation}',        [ChatController::class, 'parentShow']);
        Route::post('/chats/{conversation}/send',  [ChatController::class, 'parentSend']);
        // 👋 Nudges (App\Support\ChatNudge): the cooldown is in the class, per conversation and sender.
        Route::post('/chats/{conversation}/nudge', [ChatController::class, 'parentNudge'])->whereNumber('conversation');
        Route::post('/threads/{thread}/nudge',     [\App\Http\Controllers\Api\TeamChatController::class, 'nudge'])->where('thread', '[0-9]+');
        // 📞 Calls (App\Support\ChatCall), beside the nudge.
        Route::post('/chats/{conversation}/call',  [ChatController::class, 'parentCall'])->whereNumber('conversation')->middleware('throttle:10,1,r-chat-call');
        Route::post('/threads/{thread}/call',      [\App\Http\Controllers\Api\TeamChatController::class, 'call'])->where('thread', '[0-9]+')->middleware('throttle:10,1,r-chat-call');
        Route::delete('/chats/{conversation}/messages/{message}', [ChatController::class, 'parentDeleteMessage'])->where(['conversation'=>'[0-9]+','message'=>'[0-9]+']);
        Route::post('/chats/{conversation}/messages/{message}/react', [ChatController::class, 'parentReactMessage'])->where(['conversation'=>'[0-9]+','message'=>'[0-9]+']);
        Route::delete('/chats/{conversation}', [ChatController::class, 'parentDeleteConversation'])->where('conversation','[0-9]+');
        Route::post('/chats/{conversation}/typing', [ChatController::class, 'parentTyping'])->where('conversation','[0-9]+');
    });

    // v22p43: parent-facing form endpoints (any authenticated user can submit;
    // the controller filters by guardian->family->centre->agency).
    Route::get('/forms', [\App\Http\Controllers\Api\FormsController::class, 'publishedForUser']);
    Route::post('/forms/{form}/submit', [\App\Http\Controllers\Api\FormsController::class, 'submit']);
    
    // v22p42: global search for both agency_admin AND centre_director.
    // SearchController::resolveAgencyId now walks director.centre_id ->
    // centres.agency_id so directors get correctly scoped results.
    Route::middleware('role:agency_admin,centre_director,platform_admin')->prefix('admin')->group(function () {
        /* READ-ONLY LISTS a centre director may use (2026-10-05). AdminController::listScope()
           confines a director to their own centres; agency admins see what they always did.
           Writes on these resources stay in the agency_admin group. */
        Route::get('/centres', [AdminController::class, 'listCentres']);
        Route::get('/users', [AdminController::class, 'listUsers']);
        Route::get('/families', [AdminController::class, 'listFamilies']);
        /* A family's record, READ ONLY, for the shared Families screen (2026-11-13). showFamily uses listScope;
           FamilyRecordController::assertFamily lets staff reach only families of a centre they are placed at.
           Notes can be read here; adding one stays in the agency_admin group. */
        Route::get('/families/{family}', [AdminController::class, 'showFamily']);
        Route::get   ('/families/{family}/provider-history',     [\App\Http\Controllers\Api\FamilyRecordController::class, 'providerHistory']);
        Route::get   ('/families/{family}/notes',                [\App\Http\Controllers\Api\FamilyRecordController::class, 'listNotes']);
        Route::get   ('/families/{family}/documents',            [\App\Http\Controllers\Api\FamilyRecordController::class, 'documents'])->whereNumber('family');
        Route::get('/children', [AdminController::class, 'listAgencyChildren']);
        Route::get('/compliance', [AdminController::class, 'compliance']);
        Route::get('/search', [\App\Http\Controllers\Api\SearchController::class, 'query']);
        // v22p47: audit trail — agency admins (whole agency) + directors (their centre)
        Route::get('/audit-logs', [AdminController::class, 'auditLogs']);
        // Chat conversations subtab (2026-10-09): agency/platform admins only, enforced in the controller.
        Route::get('/chat-audit', [\App\Http\Controllers\Api\ChatAuditController::class, 'index']);
        Route::get('/chat-audit/{kind}/{id}', [\App\Http\Controllers\Api\ChatAuditController::class, 'show'])->where(['kind' => 'family|staff', 'id' => '[0-9]+']);
        // Top-bar notification / activity feed (agency-isolated; platform_admin sees all).
        Route::get('/activity-feed', [AgencyController::class, 'activityFeed']);
        // Race/ethnicity + device-type breakdown for the agency (onboarding analytics).
        Route::get('/analytics/demographics', [AgencyController::class, 'demographics']);
        // Duplicate-record detection for entry forms (users / children / families).
        Route::get('/duplicate-users', [AdminController::class, 'duplicateUsers']);
        Route::get('/duplicate-check', [AdminController::class, 'duplicateCheck']);
        /* MANAGE A PERSON'S DETAILS (2026-10-07). A centre director, for their own
           centre's staff and parents only: AdminController::manageGate() checks every
           centre and family the person belongs to, and updateUser() refuses a director
           any change to email, username or status. Agency admins: unchanged. */
        Route::patch('/users/{user}', [AdminController::class, 'updateUser'])->whereNumber('user');
        Route::get('/users/{user}/access-summary', [AdminController::class, 'accessSummary'])->whereNumber('user');
        Route::get('/users/{user}/profile', [AdminController::class, 'userProfile'])->whereNumber('user');
        Route::put('/users/{user}/profile', [AdminController::class, 'updateUserProfile'])->whereNumber('user');
    });

    // PROVIDER chat routes (educator + centre_director + agency_admin + home_visitor)
    // home_visitor is agency-scoped in providerCentreIds so they message families
    // across their agency. This group is chat-only — NOT the provider toolset.
    Route::middleware(KT_PROVIDER_ROLES)->prefix('provider')->group(function () {
        // One message to a whole audience. POST without confirm=1 returns a COUNT
        // only, so the screen can say who it reaches before it reaches them.
        Route::post('/chats/broadcast',            [ChatController::class, 'broadcast']);
        Route::get('/chats',                       [ChatController::class, 'providerList']);
        Route::get('/chats/{conversation}',        [ChatController::class, 'providerShow']);
        Route::post('/chats/{conversation}/send',  [ChatController::class, 'providerSend']);
        Route::delete('/chats/{conversation}/messages/{message}', [ChatController::class, 'providerDeleteMessage'])->where(['conversation'=>'[0-9]+','message'=>'[0-9]+']);
        Route::post('/chats/{conversation}/messages/{message}/react', [ChatController::class, 'providerReactMessage'])->where(['conversation'=>'[0-9]+','message'=>'[0-9]+']);
        Route::delete('/chats/{conversation}', [ChatController::class, 'providerDeleteConversation'])->where('conversation','[0-9]+');
        Route::post('/chats/{conversation}/nudge',  [ChatController::class, 'providerNudge']);
        Route::post('/chats/{conversation}/call',   [ChatController::class, 'providerCall'])->whereNumber('conversation')->middleware('throttle:10,1,r-chat-call');
        // Text / Email the family from a conversation — a separate function and popup (2026-10-10).
        Route::get ('/chats/{conversation}/parent-contacts', [ChatController::class, 'providerParentContacts'])->whereNumber('conversation');
        Route::post('/chats/{conversation}/text-email',      [ChatController::class, 'providerTextEmail'])->whereNumber('conversation')->middleware('throttle:20,1,r-chats-x-text-email');
        Route::post('/chats/{conversation}/typing', [ChatController::class, 'providerTyping'])->where('conversation','[0-9]+');
        Route::post('/chats/start',                [ChatController::class, 'providerStart']);
        // Families this person may start a conversation with (Message Centre rules, 2026-10-09).
        Route::get ('/chat-families',              [ChatController::class, 'providerFamilies']);
    });

    // Staff-to-staff direct messaging (#38) — colleagues within an agency. {thread}
    // is numeric-constrained so /team-threads/unread-count is never captured as an id.
    // Files between educators and families (2026-10-09). Scoped in the controller: staff reach the
    // families of the children they can access (room scope); parents only their own family.
    Route::middleware('role:educator,centre_director,agency_admin,home_visitor,platform_admin')->prefix('provider')->group(function () {
        Route::get   ('/files',                  [\App\Http\Controllers\Api\FamilyFilesController::class, 'staffList']);
        Route::get   ('/files/recipients',       [\App\Http\Controllers\Api\FamilyFilesController::class, 'recipients']);
        Route::post  ('/files',                  [\App\Http\Controllers\Api\FamilyFilesController::class, 'staffSend'])->middleware('throttle:30,1,r-grp-2237');
        Route::get   ('/files/settings',         [\App\Http\Controllers\Api\FamilyFilesController::class, 'showSettings']);
        Route::post  ('/files/settings',         [\App\Http\Controllers\Api\FamilyFilesController::class, 'saveSettings']);
        Route::get   ('/files/{id}/download',    [\App\Http\Controllers\Api\FamilyFilesController::class, 'download'])->whereNumber('id');
        Route::delete('/files/{id}',             [\App\Http\Controllers\Api\FamilyFilesController::class, 'destroy'])->whereNumber('id');
    });
    Route::middleware('role:guardian')->prefix('parent')->group(function () {
        Route::get   ('/files',                  [\App\Http\Controllers\Api\FamilyFilesController::class, 'parentList']);
        Route::post  ('/files',                  [\App\Http\Controllers\Api\FamilyFilesController::class, 'parentSend'])->middleware('throttle:20,1,r-grp-2245');
        Route::get   ('/files/{id}/download',    [\App\Http\Controllers\Api\FamilyFilesController::class, 'download'])->whereNumber('id');
        Route::delete('/files/{id}',             [\App\Http\Controllers\Api\FamilyFilesController::class, 'destroy'])->whereNumber('id');
    });

    // Staff compliance (2026-10-10): hire date + registry number, deadlines from hire date,
    // training hours, household residents. Scoped in the controller (admins: agency;
    // directors: their centres; staff: themselves; a provider: their own home).
    Route::middleware('role:educator,home_visitor,centre_director,agency_admin,platform_admin')->group(function () {
        Route::get   ('/admin/staff-compliance',               [\App\Http\Controllers\Api\StaffComplianceController::class, 'rollup']);
        Route::get   ('/admin/staff-compliance/{user}',        [\App\Http\Controllers\Api\StaffComplianceController::class, 'show'])->whereNumber('user');
        Route::patch ('/admin/staff-compliance/{user}/hr',     [\App\Http\Controllers\Api\StaffComplianceController::class, 'updateHr'])->whereNumber('user');
        Route::get   ('/my-compliance',                        [\App\Http\Controllers\Api\StaffComplianceController::class, 'mine']);
        // Staff certificates (2026-10-19): CPR, first aid, RECE … with the certificate file.
        Route::get   ('/staff-certifications',                 [\App\Http\Controllers\Api\StaffComplianceController::class, 'certIndex']);
        Route::get   ('/staff-certifications/roster',          [\App\Http\Controllers\Api\StaffComplianceController::class, 'certRoster']);
        Route::post  ('/staff-certifications',                 [\App\Http\Controllers\Api\StaffComplianceController::class, 'certStore'])->middleware('throttle:30,1,r-grp-2261');
        Route::post  ('/staff-certifications/{id}',            [\App\Http\Controllers\Api\StaffComplianceController::class, 'certUpdate'])->whereNumber('id')->middleware('throttle:30,1,r-grp-2262');
        Route::patch ('/staff-certifications/{id}',            [\App\Http\Controllers\Api\StaffComplianceController::class, 'certUpdate'])->whereNumber('id');
        Route::post  ('/staff-certifications/{id}/verify',     [\App\Http\Controllers\Api\StaffComplianceController::class, 'certVerify'])->whereNumber('id');
        Route::delete('/staff-certifications/{id}',            [\App\Http\Controllers\Api\StaffComplianceController::class, 'certDelete'])->whereNumber('id');
        Route::get   ('/staff-certifications/{id}/document',   [\App\Http\Controllers\Api\StaffComplianceController::class, 'certDocument'])->whereNumber('id');
        Route::get   ('/pd-hours',                             [\App\Http\Controllers\Api\StaffComplianceController::class, 'pdIndex']);
        Route::post  ('/pd-hours',                             [\App\Http\Controllers\Api\StaffComplianceController::class, 'pdStore'])->middleware('throttle:30,1,r-grp-2268');
        Route::post  ('/pd-hours/{id}/review',                 [\App\Http\Controllers\Api\StaffComplianceController::class, 'pdReview'])->whereNumber('id');
        Route::delete('/pd-hours/{id}',                        [\App\Http\Controllers\Api\StaffComplianceController::class, 'pdDelete'])->whereNumber('id');
        Route::get   ('/pd-hours/{id}/certificate',            [\App\Http\Controllers\Api\StaffComplianceController::class, 'pdCertificate'])->whereNumber('id');
        Route::get   ('/household',                            [\App\Http\Controllers\Api\StaffComplianceController::class, 'household']);
        Route::post  ('/household/{centre}/residents',         [\App\Http\Controllers\Api\StaffComplianceController::class, 'residentStore'])->whereNumber('centre');
        Route::patch ('/household-residents/{id}',             [\App\Http\Controllers\Api\StaffComplianceController::class, 'residentUpdate'])->whereNumber('id');
        Route::delete('/household-residents/{id}',             [\App\Http\Controllers\Api\StaffComplianceController::class, 'residentDelete'])->whereNumber('id');
        Route::post  ('/household-residents/{id}/checks',      [\App\Http\Controllers\Api\StaffComplianceController::class, 'checkStore'])->whereNumber('id');
        Route::delete('/household-resident-checks/{id}',       [\App\Http\Controllers\Api\StaffComplianceController::class, 'checkDelete'])->whereNumber('id');
    });

    // Educators: their own children's emergency cards, and texting/emailing those children's
    // parents through KiddieTrac (2026-10-09). Room-scoped in the controllers (canAccessChildId).
    Route::middleware('role:educator,centre_director,agency_admin,home_visitor,platform_admin')->prefix('provider')->group(function () {
        Route::get ('/children/{child}/emergency-card',            [\App\Http\Controllers\Api\EmergencyCardController::class, 'forCarer'])->whereNumber('child');
        Route::get ('/children/{child}/emergency-card/print-link', [\App\Http\Controllers\Api\EmergencyCardController::class, 'printLinkForCarer'])->whereNumber('child');
        Route::get ('/children/{child}/parent-contacts',           [\App\Http\Controllers\Api\ParentContactController::class, 'contacts'])->whereNumber('child');
        // Staff notes on a child's record (2026-10-09); room-scoped in the controller.
        Route::get  ('/children/{child}/notes',            [\App\Http\Controllers\Api\ChildNotesController::class, 'index'])->whereNumber('child');
        Route::post ('/children/{child}/notes',            [\App\Http\Controllers\Api\ChildNotesController::class, 'store'])->whereNumber('child')->middleware('throttle:30,1,r-grp-2288');
        Route::patch('/child-notes/{id}',                  [\App\Http\Controllers\Api\ChildNotesController::class, 'update'])->whereNumber('id')->middleware('throttle:30,1,r-child-notes-x');
        Route::get  ('/child-notes/{id}/history',          [\App\Http\Controllers\Api\ChildNotesController::class, 'history'])->whereNumber('id');
        Route::post('/children/{child}/message-parents',           [\App\Http\Controllers\Api\ParentContactController::class, 'send'])->whereNumber('child')->middleware('throttle:20,1,r-children-x-message-parents');
    });

    Route::middleware('role:educator,centre_director,agency_admin,home_visitor,platform_admin,auditor')->prefix('provider')->group(function () {
        Route::get ('/team-contacts',              [\App\Http\Controllers\Api\TeamChatController::class, 'contacts']);
        Route::get ('/team-threads',               [\App\Http\Controllers\Api\TeamChatController::class, 'threads']);
        Route::get ('/team-threads/unread-count',  [\App\Http\Controllers\Api\TeamChatController::class, 'unreadCount']);
        Route::post('/team-threads/start',         [\App\Http\Controllers\Api\TeamChatController::class, 'start']);
        // Group conversations (2026-08-30). 'group' is a literal segment and the
        // {thread} routes below are [0-9]+-constrained, so it can never be read as
        // a thread id — the same guard /unread-count relies on.
        Route::post('/team-threads/group',         [\App\Http\Controllers\Api\TeamChatController::class, 'startGroup']);
        Route::post('/team-threads/{thread}/participants', [\App\Http\Controllers\Api\TeamChatController::class, 'addParticipants'])->where('thread', '[0-9]+');
        // Managing the group once it exists: leave (yourself, always allowed), remove
        // (creator or a director) and rename (any participant). See the controller for
        // why those three permissions differ.
        Route::post('/team-threads/{thread}/leave', [\App\Http\Controllers\Api\TeamChatController::class, 'leave'])->where('thread', '[0-9]+');
        Route::delete('/team-threads/{thread}/participants/{user}', [\App\Http\Controllers\Api\TeamChatController::class, 'removeParticipant'])->where(['thread' => '[0-9]+', 'user' => '[0-9]+']);
        Route::patch('/team-threads/{thread}', [\App\Http\Controllers\Api\TeamChatController::class, 'rename'])->where('thread', '[0-9]+');
        Route::get ('/team-threads/{thread}',      [\App\Http\Controllers\Api\TeamChatController::class, 'show'])->where('thread', '[0-9]+');
        Route::post('/team-threads/{thread}/send', [\App\Http\Controllers\Api\TeamChatController::class, 'send'])->where('thread', '[0-9]+');
        Route::post('/team-threads/{thread}/nudge', [\App\Http\Controllers\Api\TeamChatController::class, 'nudge'])->where('thread', '[0-9]+');
        Route::post('/team-threads/{thread}/call',  [\App\Http\Controllers\Api\TeamChatController::class, 'call'])->where('thread', '[0-9]+')->middleware('throttle:10,1,r-chat-call');
        Route::post('/team-threads/{thread}/messages/{message}/react', [\App\Http\Controllers\Api\TeamChatController::class, 'react'])->where(['thread' => '[0-9]+', 'message' => '[0-9]+']);
        // Archiving is per person and covers both kinds of thread, so it lives on its
        // own endpoint rather than being duplicated into each chat controller.
        // Interface state that follows the person rather than the browser.
        // Late arrivals and departures: educators record, directors and admins decide.
        // Invoices raised against a payee: an educator contracting, a family, a supplier.
        // Printable document templates (payslips, invoices). Bodies are stored in a
        // restricted syntax and never evaluated - see App\Support\DocumentTemplate.
        Route::get   ('/document-templates',              [\App\Http\Controllers\Api\DocumentTemplateController::class, 'index']);
        Route::post  ('/document-templates',              [\App\Http\Controllers\Api\DocumentTemplateController::class, 'store']);
        Route::post  ('/document-templates/preview',      [\App\Http\Controllers\Api\DocumentTemplateController::class, 'preview']);
        Route::post  ('/document-templates/import',       [\App\Http\Controllers\Api\DocumentTemplateController::class, 'import']);
        Route::get   ('/document-templates/{id}',         [\App\Http\Controllers\Api\DocumentTemplateController::class, 'show'])->where('id', '[0-9]+');
        Route::post  ('/document-templates/{id}/activate',[\App\Http\Controllers\Api\DocumentTemplateController::class, 'activate'])->where('id', '[0-9]+');
        Route::delete('/document-templates/{id}',         [\App\Http\Controllers\Api\DocumentTemplateController::class, 'destroy'])->where('id', '[0-9]+');
        // Who works in which room. Empty across the platform until now, which is why
        // room-scoped screens fall back to the centre.
        Route::get ('/educator-rooms', [\App\Http\Controllers\Api\EducatorRoomController::class, 'index']);
        Route::post('/educator-rooms', [\App\Http\Controllers\Api\EducatorRoomController::class, 'save']);
        Route::get ('/payroll-documents',             [\App\Http\Controllers\Api\PayrollDocumentController::class, 'index']);
        Route::post('/payroll-documents/backfill',    [\App\Http\Controllers\Api\PayrollDocumentController::class, 'backfill']);
        Route::post('/payroll-documents/{id}/status', [\App\Http\Controllers\Api\PayrollDocumentController::class, 'setStatus'])->where('id', '[0-9]+');
        Route::post('/payroll-documents/{id}/email',  [\App\Http\Controllers\Api\PayrollDocumentController::class, 'email'])->where('id', '[0-9]+');
        Route::get ('/payee-invoices',              [\App\Http\Controllers\Api\PayeeInvoiceController::class, 'index']);
        Route::get ('/payee-invoices/hours',        [\App\Http\Controllers\Api\PayeeInvoiceController::class, 'hours']);
        Route::post('/payee-invoices',              [\App\Http\Controllers\Api\PayeeInvoiceController::class, 'store']);
        Route::post('/payee-invoices/{id}/status',  [\App\Http\Controllers\Api\PayeeInvoiceController::class, 'setStatus'])->where('id', '[0-9]+');
        Route::get ('/payee-invoices/{id}',         [\App\Http\Controllers\Api\PayeeInvoiceController::class, 'show'])->where('id', '[0-9]+');
        Route::post('/payee-invoices/{id}/send',    [\App\Http\Controllers\Api\PayeeInvoiceController::class, 'send'])->where('id', '[0-9]+');
        Route::get ('/payee-invoices/{id}/pdf',     [\App\Http\Controllers\Api\PayeeInvoiceController::class, 'pdf'])->where('id', '[0-9]+');
        Route::patch ('/payee-invoices/{id}',              [\App\Http\Controllers\Api\PayeeInvoiceController::class, 'update'])->where('id', '[0-9]+');
        Route::get   ('/payee-invoices/{id}/lines',        [\App\Http\Controllers\Api\PayeeInvoiceController::class, 'lines'])->where('id', '[0-9]+');
        Route::post  ('/payee-invoices/{id}/lines',        [\App\Http\Controllers\Api\PayeeInvoiceController::class, 'addLine'])->where('id', '[0-9]+');
        Route::delete('/payee-invoices/{id}/lines/{line}', [\App\Http\Controllers\Api\PayeeInvoiceController::class, 'removeLine'])->where('id', '[0-9]+')->where('line', '[0-9]+');
        Route::get ('/late-events',            [\App\Http\Controllers\Api\LateEventController::class, 'index']);
        Route::post('/late-events',            [\App\Http\Controllers\Api\LateEventController::class, 'store']);
        Route::post('/late-events/{id}/decide', [\App\Http\Controllers\Api\LateEventController::class, 'decide'])->where('id', '[0-9]+');
        Route::get ('/late-events/options',    [\App\Http\Controllers\Api\LateEventController::class, 'options']);
        Route::get ('/chat-archive', [\App\Http\Controllers\Api\ChatArchiveController::class, 'index']);
        Route::post('/chat-archive', [\App\Http\Controllers\Api\ChatArchiveController::class, 'store']);
    });
    
    // Shared: unread badge (any authenticated user)
    Route::get('/chats/unread-count',          [ChatController::class, 'unreadCount']);

    /* Archiving a conversation — ANY authenticated user, including a guardian.
       This lived only under /provider, whose group excludes guardians, so a parent
       pressing the x on a conversation got a 403 and was told it could not be removed.
       The controller has always handled guardians (inFamilyConversation checks the
       guardians table) and scopes every read and write to the caller's own user id, so
       it needs no role gate of its own: membership of the thread IS the gate, and it
       returns 404 for anything the caller is not part of.

       Deliberately NOT fixed by adding guardian to the provider group — that group also
       carries payee invoices, late events and payroll, none of which a parent should be
       able to reach. The /provider paths below stay registered so a client still running
       cached JS keeps working. */
    Route::get ('/chat-archive', [\App\Http\Controllers\Api\ChatArchiveController::class, 'index']);
    Route::post('/chat-archive', [\App\Http\Controllers\Api\ChatArchiveController::class, 'store']);
    
    /* 📞 A call from a chat once it has started (App\Support\ChatCall, browser to browser - free) - any signed-in
       person, parents too: being the caller or one of the people rung is the permission (anything else is 404). */
    Route::get ('/chat-calls/{id}',         [\App\Http\Controllers\Api\ChatCallController::class, 'show'])->whereNumber('id')->middleware('throttle:240,1,r-chat-calls-g');
    Route::post('/chat-calls/{id}/offer',   [\App\Http\Controllers\Api\ChatCallController::class, 'offer'])->whereNumber('id')->middleware('throttle:30,1,r-chat-calls-p');
    Route::post('/chat-calls/{id}/answer',  [\App\Http\Controllers\Api\ChatCallController::class, 'answer'])->whereNumber('id')->middleware('throttle:30,1,r-chat-calls-p');
    Route::post('/chat-calls/{id}/decline', [\App\Http\Controllers\Api\ChatCallController::class, 'decline'])->whereNumber('id')->middleware('throttle:30,1,r-chat-calls-p');
    Route::post('/chat-calls/{id}/hangup',  [\App\Http\Controllers\Api\ChatCallController::class, 'hangup'])->whereNumber('id')->middleware('throttle:30,1,r-chat-calls-p');
    Route::post('/chat-calls/{id}/talking', [\App\Http\Controllers\Api\ChatCallController::class, 'talking'])->whereNumber('id')->middleware('throttle:30,1,r-chat-calls-p');

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
        Route::post('/waitlist/{child}/remind',       [WaitlistController::class, 'remind']);
        Route::post('/waitlist/{child}/move',         [WaitlistController::class, 'move']);
    });
    
    // PROVIDER — announcements compose + list (home_visitor may READ only; the
    // controller guards store/destroy against home_visitor, and the UI hides
    // compose for them — they see agency announcements but can't broadcast.)
    Route::middleware(KT_PROVIDER_ROLES)->prefix('provider')->group(function () {
        Route::get('/announcements',                  [AnnouncementController::class, 'indexProvider']);
        Route::post('/announcements',                 [AnnouncementController::class, 'store']);
        // Staff can delete announcements their own centre/agency owns (single or
        // bulk). Deleting also purges the inbox notifications it generated.
        Route::post('/announcements/delete',          [AnnouncementController::class, 'destroyMany']);
        Route::delete('/announcements/{id}',          [AnnouncementController::class, 'destroy']);
        // Due dates + reminders (2026-10-22): staff it was sent to tap Done; the sender sees who has.
        Route::post('/announcements/{id}/done',       [AnnouncementController::class, 'markDone'])->whereNumber('id');
        Route::get('/announcements/{id}/responses',   [AnnouncementController::class, 'responses'])->whereNumber('id');
        // Details (who it reached, read / done) and Resend, for whoever could have sent it (2026-11-09).
        Route::get('/announcements/{id}/details',     [AnnouncementController::class, 'details'])->whereNumber('id');
        Route::post('/announcements/{id}/resend',     [AnnouncementController::class, 'resend'])->whereNumber('id')->middleware('throttle:6,1,r-ann-resend');
        // Read / unread / archive in MY list (2026-11-07): the caller's own rows only (announcement_user_state).
        Route::post('/announcements/state',           [AnnouncementController::class, 'parentState'])->middleware('throttle:60,1,r-annstate-staff');
        // Supplies from home (2026-10-23, App\Support\Supplies).
        Route::get('/supplies/catalogue',             [\App\Http\Controllers\Api\SupplyController::class, 'catalogue']);
        Route::put('/supplies/catalogue',             [\App\Http\Controllers\Api\SupplyController::class, 'saveCatalogue']);
        Route::get('/supplies',                       [\App\Http\Controllers\Api\SupplyController::class, 'index']);
        Route::post('/supplies',                      [\App\Http\Controllers\Api\SupplyController::class, 'store'])->middleware('throttle:30,1,r-supplies');
        Route::post('/supplies/{id}/received',        [\App\Http\Controllers\Api\SupplyController::class, 'received'])->whereNumber('id');
        Route::post('/supplies/{id}/cancel',          [\App\Http\Controllers\Api\SupplyController::class, 'cancel'])->whereNumber('id');
    });
    
    // PARENT — announcements inbox + waitlist status + autopay
    Route::middleware('role:guardian')->prefix('parent')->group(function () {
        Route::get('/announcements',                  [AnnouncementController::class, 'indexParent']);
        Route::post('/announcements/{id}/done',       [AnnouncementController::class, 'markDone'])->whereNumber('id');
        // Read / unread / remove from MY News (2026-11-07): the parent's own rows only (announcement_user_state).
        Route::post('/announcements/state',           [AnnouncementController::class, 'parentState'])->middleware('throttle:60,1,r-annstate');
        Route::get('/supplies',                       [\App\Http\Controllers\Api\SupplyController::class, 'parentIndex']);
        Route::post('/supplies/respond',              [\App\Http\Controllers\Api\SupplyController::class, 'respond']);
        Route::get('/waitlist-status',                [WaitlistController::class, 'parentStatus']);
        // The agency's own Stripe (2026-11-05): AutopayController's Connect design was never switched on.
        Route::get('/billing/autopay-status',         [\App\Http\Controllers\Api\StripeParentPayController::class, 'autopayStatus']);
        Route::post('/billing/setup-intent',          [\App\Http\Controllers\Api\StripeParentPayController::class, 'setupIntent']);
        Route::post('/billing/confirm-autopay',       [\App\Http\Controllers\Api\StripeParentPayController::class, 'confirmAutopay']);
        Route::post('/billing/disable-autopay',       [\App\Http\Controllers\Api\StripeParentPayController::class, 'disableAutopay']);
    });

    // ─────────────────────────────────────────────────────────────────────
    // V14 — Lesson Plans, Scheduling, Push, Announcement badge
    // ─────────────────────────────────────────────────────────────────────
    
    // Public (no auth) — push public key

    // PROVIDER — lesson plans + scheduling
    Route::middleware(KT_PROVIDER_ROLES)->prefix('provider')->group(function () {
        Route::get('/lesson-plans',        [LessonPlanController::class, 'show']);
        Route::put('/lesson-plans',        [LessonPlanController::class, 'upsert']);
        Route::get('/lesson-plans/list',   [LessonPlanController::class, 'listForRoom']);
        // weekly templates (2026-09-29): browse + preview, use, save a week as one
        Route::get('/lesson-plan-templates',              [\App\Http\Controllers\Api\LessonPlanTemplateController::class, 'index']);
        Route::post('/lesson-plan-templates',             [\App\Http\Controllers\Api\LessonPlanTemplateController::class, 'store']);
        Route::delete('/lesson-plan-templates/{id}',      [\App\Http\Controllers\Api\LessonPlanTemplateController::class, 'destroy'])->where('id', '[0-9]+');
        Route::post('/lesson-plan-templates/{id}/used',   [\App\Http\Controllers\Api\LessonPlanTemplateController::class, 'used'])->where('id', '[0-9]+');
        // Pictures on lesson plans (2026-11-02, App\Support\LessonImages): look up, choose, upload, the auto setting, fill a saved week.
        Route::post('/lesson-plans/image/search',   [LessonPlanController::class, 'imageSearch'])->middleware('throttle:40,1,lpimgsearch');
        Route::post('/lesson-plans/image/pick',     [LessonPlanController::class, 'imagePick'])->middleware('throttle:30,1,lpimgpick');
        Route::post('/lesson-plans/image/upload',   [LessonPlanController::class, 'imageUpload'])->middleware('throttle:30,1,lpimgup');
        Route::put ('/lesson-plans/image-settings', [LessonPlanController::class, 'imageSettings']);
        Route::post('/lesson-plans/fill-images',    [LessonPlanController::class, 'fillImages'])->middleware('throttle:20,1,lpimgfill');
    });
    
    Route::middleware('role:centre_director,agency_admin')->prefix('director')->group(function () {
        Route::get('/schedule',                  [SchedulingController::class, 'week']);
        Route::get('/schedule/range',            [SchedulingController::class, 'range']);
        Route::get('/schedule/staff',            [SchedulingController::class, 'staffList']);
        Route::post('/schedule/shift',           [SchedulingController::class, 'createShift']);
        // Fill a range from the centre's own opening hours, skipping closures.
        Route::post('/schedule/autofill',        [SchedulingController::class, 'autofill']);
        // The nightly-autofill switch. Agency-wide, so agency admins only to change it.
        Route::get  ('/schedule/settings',       [SchedulingController::class, 'scheduleSettings']);
        Route::patch('/schedule/settings',       [SchedulingController::class, 'saveScheduleSettings']);
        // Standing hours per person on a home's team (App\Support\StandingShifts).
        Route::get  ('/schedule/standing',       [SchedulingController::class, 'standing']);
        Route::put  ('/schedule/standing',       [SchedulingController::class, 'saveStanding']);
        Route::patch('/schedule/shift/{id}',     [SchedulingController::class, 'updateShift']);
        Route::delete('/schedule/shift/{id}',    [SchedulingController::class, 'deleteShift']);
        Route::get('/timesheets',                [SchedulingController::class, 'timesheets']);
        Route::get('/certifications',            [SchedulingController::class, 'certifications']);
        Route::patch('/certifications/{id}',     [\App\Http\Controllers\Api\StaffComplianceController::class, 'certUpdate'])->whereNumber('id');
        Route::delete('/certifications/{id}',    [\App\Http\Controllers\Api\StaffComplianceController::class, 'certDelete'])->whereNumber('id');
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
        Route::get ('/checkin/poster-info/{centreId}', [\App\Http\Controllers\Api\CheckinScanController::class, 'posterInfo'])->whereNumber('centreId');
        Route::post('/parent/checkin/scan',            [\App\Http\Controllers\Api\CheckinScanController::class, 'scan']);
    Route::post('/push/test',                [PushController::class, 'test']);
    
    // NOTIFICATIONS — unread badge support
    Route::get('/notifications/unread-count', [NotificationUnreadController::class, 'unreadCount']);
    Route::post('/notifications/mark-read',   [NotificationUnreadController::class, 'markRead']);
    // Every badge in one answer; opening the inbox marks it seen; iPhone icon sync (App\Support\Badges, 2026-10-30).
    Route::get('/announcements/popup',        [\App\Http\Controllers\Api\AnnouncementController::class, 'popup'])->middleware('throttle:30,1,annpopup');
    Route::get('/badges',                     [\App\Http\Controllers\Api\BadgeController::class, 'index'])->middleware('throttle:120,1,badges');
    Route::post('/notifications/seen',        [\App\Http\Controllers\Api\BadgeController::class, 'seen'])->middleware('throttle:60,1,badgeseen');
    Route::post('/badges/sync',               [\App\Http\Controllers\Api\BadgeController::class, 'sync'])->middleware('throttle:30,1,badgesync');
    // Inbox housekeeping — always scoped to the caller's own notifications.
    Route::post('/notifications/delete',      [NotificationUnreadController::class, 'destroyMany']);
    Route::delete('/notifications/{id}',      [NotificationUnreadController::class, 'destroy']);
    Route::patch('/notifications/{id}/unread', [NotificationUnreadController::class, 'markUnread']);

    // ───────── v15 ROUTES — Reseller features ─────────
    // These routes are spliced INTO the auth:sanctum group by deploy.sh.
    // Authorization (platform_admin vs agency_admin) is enforced inside each controller.
    
    // Feature flag management
    Route::get   ('/admin/features/catalog',           [FeatureFlagController::class, 'catalog']);
    Route::get   ('/admin/agencies/{id}/features',     [FeatureFlagController::class, 'show']);
    /* Live sample for the Branding screen's numbering format. Read-only: it takes
       no sequence number. */
    Route::get   ('/admin/agencies/{id}/invoice-number-preview', [FeatureFlagController::class, 'invoiceNumberPreview'])->where('id', '[0-9]+');
    Route::patch ('/admin/agencies/{id}/features',     [FeatureFlagController::class, 'update']);
    
    /* MRR dashboard. The role guard is ON EACH ROUTE, not on an enclosing group —
       this comment previously claimed platform-admin only while the routes carried
       no role middleware at all, and any authenticated user could read them. */
    Route::get   ('/admin/mrr/overview',               [MrrDashboardController::class, 'overview'])->middleware('role:platform_admin');
    Route::get   ('/admin/mrr/agencies',               [MrrDashboardController::class, 'agencyList'])->middleware('role:platform_admin');
    Route::get   ('/admin/mrr/insights',               [MrrDashboardController::class, 'insights'])->middleware('role:platform_admin');

    /* Platform invoices — what agencies owe KiddieTrac. Same platform-admin group
       as the MRR dashboard above; nothing agency-scoped may reach these. */
    Route::get   ('/platform/invoices',                [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'index'])->middleware('role:platform_admin');
    Route::get   ('/platform/billing-automation',      [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'automation'])->middleware('role:platform_admin');
    Route::put   ('/platform/billing-automation',      [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'saveAutomation'])->middleware('role:platform_admin');
    Route::get   ('/platform/billing-recurring',       [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'recurring'])->middleware('role:platform_admin');
    Route::put   ('/platform/billing-recurring',       [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'saveRecurring'])->middleware('role:platform_admin');
    Route::get   ('/platform/billing-plans',           [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'plans'])->middleware('role:platform_admin');
    Route::put   ('/platform/billing-plans/{agencyId}',[\App\Http\Controllers\Api\PlatformInvoiceController::class, 'savePlan'])->middleware('role:platform_admin');

    /* RESELLERS (2026-10-21). Platform admins manage resellers; a reseller admin edits only
       its own brand and sees only its own agencies' names (ResellerController). */
    /* KIDDIETRAC SALES REPORT (2026-10-24): platform revenue by part number, kind, month, customer. Never an agency's. */
    Route::get('/platform/sales-report', [\App\Http\Controllers\Api\SalesReportsController::class, 'platform'])->middleware('role:platform_admin');
    /* KIDDIETRAC REPORTS (2026-10-31): superadmin-only sales, leads, cold reach and campaign reports (Reports → KiddieTrac). */
    Route::get('/platform/reports/sales',     [\App\Http\Controllers\Api\PlatformReportsController::class, 'sales'])->middleware(['role:platform_admin', 'throttle:60,1,r-pr-sales']);
    Route::get('/platform/reports/leads',     [\App\Http\Controllers\Api\PlatformReportsController::class, 'leads'])->middleware(['role:platform_admin', 'throttle:60,1,r-pr-leads']);
    Route::get('/platform/reports/coldreach', [\App\Http\Controllers\Api\PlatformReportsController::class, 'coldreach'])->middleware(['role:platform_admin', 'throttle:60,1,r-pr-cold']);
    Route::get('/platform/reports/campaigns', [\App\Http\Controllers\Api\PlatformReportsController::class, 'campaigns'])->middleware(['role:platform_admin', 'throttle:60,1,r-pr-camp']);
    /* LICENCES (2026-10-24, App\Support\Licences): plans and add-ons per agency, with expiry and renewal. */
    Route::get   ('/platform/licences',                     [\App\Http\Controllers\Api\LicenceController::class, 'index'])->middleware('role:platform_admin');
    Route::post  ('/platform/licences',                     [\App\Http\Controllers\Api\LicenceController::class, 'store'])->middleware('role:platform_admin');
    Route::patch ('/platform/licences/{id}',                [\App\Http\Controllers\Api\LicenceController::class, 'update'])->whereNumber('id')->middleware('role:platform_admin');
    Route::post  ('/platform/licences/{id}/convert',        [\App\Http\Controllers\Api\LicenceController::class, 'convert'])->whereNumber('id')->middleware('role:platform_admin');
    Route::post  ('/platform/licences/{id}/renew',          [\App\Http\Controllers\Api\LicenceController::class, 'renew'])->whereNumber('id')->middleware('role:platform_admin');
    Route::get   ('/admin/licence',                         [\App\Http\Controllers\Api\LicenceController::class, 'mine'])->middleware('role:agency_admin,platform_admin');
    Route::get   ('/platform/resellers',                    [\App\Http\Controllers\Api\ResellerController::class, 'index'])->middleware('role:platform_admin');
    Route::post  ('/platform/resellers',                    [\App\Http\Controllers\Api\ResellerController::class, 'store'])->middleware('role:platform_admin');
    Route::patch ('/platform/resellers/{id}',               [\App\Http\Controllers\Api\ResellerController::class, 'update'])->whereNumber('id')->middleware('role:platform_admin');
    Route::put   ('/platform/resellers/{id}/agencies',      [\App\Http\Controllers\Api\ResellerController::class, 'setAgencies'])->whereNumber('id')->middleware('role:platform_admin');
    Route::post  ('/platform/resellers/{id}/admins',        [\App\Http\Controllers\Api\ResellerController::class, 'addAdmin'])->whereNumber('id')->middleware('role:platform_admin');
    Route::delete('/platform/resellers/{id}/admins/{userId}', [\App\Http\Controllers\Api\ResellerController::class, 'removeAdmin'])->whereNumber('id')->whereNumber('userId')->middleware('role:platform_admin');
    Route::get   ('/platform/resellers/{id}/app',           [\App\Http\Controllers\Api\ResellerController::class, 'app'])->whereNumber('id')->middleware('role:platform_admin');
    Route::put   ('/platform/resellers/{id}/app',           [\App\Http\Controllers\Api\ResellerController::class, 'saveApp'])->whereNumber('id')->middleware('role:platform_admin');
    Route::post  ('/platform/resellers/{id}/app/kit',       [\App\Http\Controllers\Api\ResellerController::class, 'kit'])->whereNumber('id')->middleware('role:platform_admin');
    Route::post  ('/reseller/agencies',                     [\App\Http\Controllers\Api\ResellerController::class, 'createAgency'])->middleware('role:reseller_admin,platform_admin');
    Route::get   ('/reseller/agencies/{agencyId}/admins',   [\App\Http\Controllers\Api\ResellerController::class, 'agencyAdmins'])->whereNumber('agencyId')->middleware('role:reseller_admin,platform_admin');
    Route::post  ('/reseller/agencies/{agencyId}/admins',   [\App\Http\Controllers\Api\ResellerController::class, 'inviteAgencyAdmin'])->whereNumber('agencyId')->middleware('role:reseller_admin,platform_admin');
    Route::post  ('/reseller/agencies/{agencyId}/admins/{userId}/{action}', [\App\Http\Controllers\Api\ResellerController::class, 'agencyAdminAction'])->whereNumber('agencyId')->whereNumber('userId')->whereIn('action', ['deactivate', 'reactivate', 'reset'])->middleware('role:reseller_admin,platform_admin');
    Route::get   ('/reseller/tickets',                      [\App\Http\Controllers\Api\ResellerController::class, 'tickets'])->middleware('role:reseller_admin,platform_admin');
    Route::get   ('/reseller/statement',                    [\App\Http\Controllers\Api\ResellerController::class, 'statement'])->middleware('role:reseller_admin,platform_admin');
    Route::get   ('/reseller/overview',                     [\App\Http\Controllers\Api\ResellerController::class, 'overview'])->middleware('role:reseller_admin,platform_admin');
    Route::get   ('/reseller/brand',                        [\App\Http\Controllers\Api\ResellerController::class, 'brand'])->middleware('role:reseller_admin,platform_admin');
    Route::put   ('/reseller/brand',                        [\App\Http\Controllers\Api\ResellerController::class, 'saveBrand'])->middleware('role:reseller_admin,platform_admin');
    Route::post  ('/reseller/brand/upload',                 [\App\Http\Controllers\Api\ResellerController::class, 'upload'])->middleware('role:reseller_admin,platform_admin');
    Route::post  ('/platform/invoices/{id}/email',     [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'email'])->middleware('role:platform_admin');
    Route::patch ('/platform/invoices/{id}',           [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'update'])->middleware('role:platform_admin');
    Route::delete('/platform/invoices/{id}',           [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'destroy'])->middleware('role:platform_admin');
    Route::post  ('/platform/invoices/raise',          [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'raise'])->middleware('role:platform_admin');
    Route::post  ('/platform/invoices',                [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'store'])->middleware('role:platform_admin');
    Route::get   ('/platform/invoices/{id}/pdf',       [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'pdf'])->where('id', '[0-9]+')->middleware('role:platform_admin');
    Route::post  ('/platform/invoices/{id}/issue',     [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'issue'])->where('id', '[0-9]+')->middleware('role:platform_admin');
    // KiddieTrac accounts (2026-10-25): payments ledger, reported payments, billing emails.
    Route::post  ('/platform/invoices/{id}/payments', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'recordPayment'])->where('id', '[0-9]+')->middleware('role:platform_admin');
    Route::get   ('/platform/invoices/payments', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'pendingPayments'])->middleware('role:platform_admin');
    Route::post  ('/platform/invoices/payments/{payment}/confirm', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'confirmPayment'])->whereNumber('payment')->middleware('role:platform_admin');
    Route::post  ('/platform/invoices/payments/{payment}/reject', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'rejectPayment'])->whereNumber('payment')->middleware('role:platform_admin');
    Route::get   ('/platform/billing-emails', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'billingEmails'])->middleware('role:platform_admin');
    // Lines, extras, NSF (2026-10-25)
    Route::get   ('/platform/invoices/{id}/detail', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'detail'])->whereNumber('id')->middleware('role:platform_admin');
    Route::post  ('/platform/invoices/{id}/lines', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'addLine'])->whereNumber('id')->middleware('role:platform_admin');
    Route::delete('/platform/invoices/{id}/lines/{line}', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'removeLine'])->whereNumber('id')->whereNumber('line')->middleware('role:platform_admin');
    Route::put   ('/platform/invoices/{id}/edit', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'editInvoice'])->whereNumber('id')->middleware('role:platform_admin');
    Route::post  ('/platform/invoices/{id}/refund', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'refundInvoice'])->whereNumber('id')->middleware('role:platform_admin');
    Route::post  ('/platform/invoices/{id}/reissue', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'reissue'])->whereNumber('id')->middleware('role:platform_admin');
    Route::post  ('/platform/invoices/{id}/waive-late-fees', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'waiveLateFees'])->whereNumber('id')->middleware('role:platform_admin');
    Route::post  ('/platform/invoices/payments/{payment}/return', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'returnPayment'])->whereNumber('payment')->middleware('role:platform_admin');
    Route::post  ('/platform/invoices/payments/{payment}/refund', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'refundPayment'])->whereNumber('payment')->middleware('role:platform_admin');
    Route::get   ('/platform/billing-extras', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'extras'])->middleware('role:platform_admin');
    Route::post  ('/platform/billing-extras', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'saveExtra'])->middleware('role:platform_admin');
    Route::patch ('/platform/billing-extras/{extra}', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'saveExtra'])->whereNumber('extra')->middleware('role:platform_admin');
    Route::delete('/platform/billing-extras/{extra}', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'deleteExtra'])->whereNumber('extra')->middleware('role:platform_admin');
    Route::put   ('/platform/billing-emails', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'saveBillingEmails'])->middleware('role:platform_admin');
    // An agency's own KiddieTrac invoices: admins only, the active agency.
    Route::get   ('/admin/kt-invoices', [\App\Http\Controllers\Api\AgencyBillingController::class, 'index'])->middleware('role:agency_admin,platform_admin');
    Route::get   ('/admin/kt-invoices/{id}/pdf', [\App\Http\Controllers\Api\AgencyBillingController::class, 'pdf'])->whereNumber('id')->middleware('role:agency_admin,platform_admin');
    Route::get   ('/admin/kt-invoices/receipts/{payment}', [\App\Http\Controllers\Api\AgencyBillingController::class, 'receipt'])->whereNumber('payment')->middleware('role:agency_admin,platform_admin');
    Route::post  ('/admin/kt-invoices/{id}/report-payment', [\App\Http\Controllers\Api\AgencyBillingController::class, 'reportPayment'])->whereNumber('id')->middleware(['role:agency_admin,platform_admin', 'throttle:20,1,r-grp-2589']);
    Route::post  ('/admin/kt-invoices/prepay-year', [\App\Http\Controllers\Api\AgencyBillingController::class, 'prepayYear'])->middleware(['role:agency_admin,platform_admin', 'throttle:5,1,r-grp-2590']);
    Route::post  ('/platform/invoices/{id}/mark-paid', [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'markPaid'])->where('id', '[0-9]+')->middleware('role:platform_admin');
    Route::post  ('/platform/invoices/{id}/void',      [\App\Http\Controllers\Api\PlatformInvoiceController::class, 'void'])->where('id', '[0-9]+')->middleware('role:platform_admin');
    // Phone & voicemail (2026-09-29): greeting voice/wording + voicemail counts, platform admins.
    Route::get   ('/platform/phone', [\App\Http\Controllers\Api\PhoneLineController::class, 'show'])->middleware('role:platform_admin');
    Route::put   ('/platform/phone', [\App\Http\Controllers\Api\PhoneLineController::class, 'save'])->middleware('role:platform_admin');
    // Text the caller back after a voicemail (2026-10-23).
    Route::get   ('/platform/phone/voicemail-reply', [\App\Http\Controllers\Api\PhoneLineController::class, 'replyShow'])->middleware('role:platform_admin');
    Route::put   ('/platform/phone/voicemail-reply', [\App\Http\Controllers\Api\PhoneLineController::class, 'replySave'])->middleware('role:platform_admin');
    Route::get   ('/platform/phone/voicemails', [\App\Http\Controllers\Api\PhoneLineController::class, 'voicemails'])->middleware('role:platform_admin');
    // Call menu: departments, numbers to ring, hours, mailboxes (2026-10-22).
    Route::get   ('/platform/phone/menu', [\App\Http\Controllers\Api\PhoneMenuController::class, 'show'])->middleware('role:platform_admin');
    Route::put   ('/platform/phone/menu', [\App\Http\Controllers\Api\PhoneMenuController::class, 'save'])->middleware('role:platform_admin');
    // Portal ringing (call menu stage 2): a 24-hour WebRTC token for staff a department rings in the portal.
    Route::get   ('/platform/phone/webrtc', [\App\Http\Controllers\Api\PhoneMenuController::class, 'webrtc'])->middleware('throttle:30,1,r-grp-2604');
    Route::get   ('/platform/phone/webrtc/caller', [\App\Http\Controllers\Api\PhoneMenuController::class, 'lastCaller'])->middleware('throttle:60,1,r-grp-2605');
    // The outbound dialer (2026-10-23, App\Support\Dialer): the phone session (menu ringing +
    // dialling), placing and ending calls, the call log, recordings and who may dial.
    Route::get   ('/voice/phone/session', [\App\Http\Controllers\Api\DialerController::class, 'session'])->middleware('throttle:30,1,r-grp-2608');
    Route::post  ('/voice/phone/answer', [\App\Http\Controllers\Api\PhoneMenuController::class, 'answer'])->middleware('throttle:20,1,r-grp-2609');
    Route::post  ('/voice/dialer/call', [\App\Http\Controllers\Api\DialerController::class, 'call'])->middleware('throttle:20,1,r-grp-2610');
    Route::post  ('/voice/dialer/calls/{id}/hangup', [\App\Http\Controllers\Api\DialerController::class, 'hangup'])->whereNumber('id')->middleware('throttle:60,1,r-grp-2611');
    Route::get   ('/voice/dialer/calls', [\App\Http\Controllers\Api\DialerController::class, 'calls'])->middleware('throttle:60,1,r-grp-2612');
    Route::get   ('/voice/dialer/calls/{id}', [\App\Http\Controllers\Api\DialerController::class, 'show'])->whereNumber('id')->middleware('throttle:120,1,r-grp-2613');
    Route::get   ('/voice/dialer/calls/{id}/recording', [\App\Http\Controllers\Api\DialerController::class, 'recording'])->whereNumber('id')->middleware('throttle:30,1,r-grp-2614');
    Route::get   ('/voice/dialer/settings', [\App\Http\Controllers\Api\DialerController::class, 'settings']);
    Route::put   ('/voice/dialer/settings', [\App\Http\Controllers\Api\DialerController::class, 'saveSettings'])->middleware('throttle:20,1,r-grp-2616');
    // Missed calls + voicemail on the toll-free line, scoped by App\Support\InboundCalls (2026-10-23).
    Route::get   ('/voice/dialer/inbox', [\App\Http\Controllers\Api\DialerController::class, 'inbox'])->middleware('throttle:60,1,r-grp-2618');
    Route::get   ('/voice/dialer/inbox/count', [\App\Http\Controllers\Api\DialerController::class, 'inboxCount'])->middleware('throttle:120,1,r-grp-2619');
    Route::post  ('/voice/dialer/inbox/seen', [\App\Http\Controllers\Api\DialerController::class, 'inboxSeen'])->middleware('throttle:60,1,r-grp-2620');
    // Texts on the KiddieTrac line (2026-10-23, App\Support\LineTexts).
    Route::get   ('/voice/dialer/texts', [\App\Http\Controllers\Api\DialerController::class, 'texts'])->middleware('throttle:60,1,r-grp-2622');
    Route::get   ('/voice/dialer/texts/{digits}', [\App\Http\Controllers\Api\DialerController::class, 'textThread'])->where('digits', '[0-9+]{10,12}')->middleware('throttle:120,1,r-grp-2623');
    Route::post  ('/voice/dialer/texts/{digits}', [\App\Http\Controllers\Api\DialerController::class, 'textSend'])->where('digits', '[0-9+]{10,12}')->middleware('throttle:20,1,r-grp-2624');
    // Meetings with attendees, like Outlook (2026-10-25): Sales Calendar, Bookings, agency Calendar.
    Route::get   ('/meetings', [\App\Http\Controllers\Api\MeetingController::class, 'index']);
    Route::get   ('/meetings/people', [\App\Http\Controllers\Api\MeetingController::class, 'people'])->middleware('throttle:90,1,r-grp-2627');
    Route::get   ('/meetings/{id}', [\App\Http\Controllers\Api\MeetingController::class, 'show'])->whereNumber('id');
    Route::post  ('/meetings', [\App\Http\Controllers\Api\MeetingController::class, 'store'])->middleware('throttle:30,1,r-grp-2629');
    Route::put   ('/meetings/{id}', [\App\Http\Controllers\Api\MeetingController::class, 'update'])->whereNumber('id')->middleware('throttle:30,1,r-grp-2630');
    Route::post  ('/meetings/{id}/cancel', [\App\Http\Controllers\Api\MeetingController::class, 'cancel'])->whereNumber('id')->middleware('throttle:30,1,r-grp-2631');
    Route::post  ('/meetings/{id}/respond', [\App\Http\Controllers\Api\MeetingController::class, 'respond'])->whereNumber('id')->middleware('throttle:60,1,r-grp-2632');
    Route::get   ('/voice/dialer/text-contacts', [\App\Http\Controllers\Api\DialerController::class, 'textContacts'])->middleware('throttle:60,1,r-grp-2633');   // new-text picker (2026-10-24)
    Route::get   ('/voice/dialer/voicemails/{id}/audio', [\App\Http\Controllers\Api\DialerController::class, 'voicemailAudio'])->whereNumber('id')->middleware('throttle:60,1,r-grp-2634');
    Route::post  ('/voice/dialer/voicemails/{id}/lead', [\App\Http\Controllers\Api\DialerController::class, 'voicemailLead'])->whereNumber('id')->middleware('throttle:30,1,r-grp-2635');
    Route::delete('/voice/dialer/voicemails/{id}', [\App\Http\Controllers\Api\DialerController::class, 'voicemailDelete'])->whereNumber('id')->middleware('throttle:30,1,r-voice-dialer-voicemails-x');   // 2026-10-25
    Route::get   ('/voice/dialer/alerts', [\App\Http\Controllers\Api\DialerController::class, 'alerts']);
    Route::put   ('/voice/dialer/alerts', [\App\Http\Controllers\Api\DialerController::class, 'saveAlerts'])->middleware('throttle:20,1,r-grp-2638');
    Route::get   ('/platform/phone/voicemails/{id}/audio', [\App\Http\Controllers\Api\PhoneLineController::class, 'audio'])->where('id', '[0-9]+')->middleware('role:platform_admin');
    Route::delete('/platform/phone/voicemails/{id}', [\App\Http\Controllers\Api\PhoneLineController::class, 'destroy'])->where('id', '[0-9]+')->middleware('role:platform_admin');
    
    // White-label invoice preview (returns text/html)
    Route::get   ('/invoices/{id}/preview',            [InvoicePreviewController::class, 'previewExisting']);
    Route::get   ('/invoices/preview-sample',          [InvoicePreviewController::class, 'previewSample']);
    /* The real invoice, drawn in the agency's chosen template — what "View invoice"
       opens from Accounting. The controller method existed and had no route, so staff
       had no way to see the actual document at all. Guarded by authorizeCentreAccess
       inside. (2026-09-17) */
    Route::get   ('/invoices/{id}/document',           [InvoicePreviewController::class, 'previewExisting'])->where('id', '[0-9]+');
    /* The same document as a downloadable file, for the schedule kebab. */
    Route::get   ('/invoices/{id}/pdf',                [InvoicePreviewController::class, 'pdfExisting'])->where('id', '[0-9]+');
    // Split billing: each guardian's own copy of a split invoice (INV-1234-A), 2026-10-30.
    Route::get   ('/invoices/{id}/split-copies',                 [\App\Http\Controllers\Api\SplitInvoiceController::class, 'index'])->whereNumber('id');
    Route::get   ('/invoices/{id}/split-copies/{guardian}/pdf',  [\App\Http\Controllers\Api\SplitInvoiceController::class, 'pdf'])->whereNumber('id')->whereNumber('guardian');
    Route::post  ('/invoices/{id}/split-copies/{guardian}/send', [\App\Http\Controllers\Api\SplitInvoiceController::class, 'send'])->whereNumber('id')->whereNumber('guardian')->middleware('throttle:30,1,splitsend');
    Route::post  ('/invoices/{id}/split-copies/send-all',        [\App\Http\Controllers\Api\SplitInvoiceController::class, 'sendAll'])->whereNumber('id')->middleware('throttle:10,1,splitsendall');

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
        // Close the centre for a request: what it would mean, and doing it after approval (2026-10-13).
        Route::get  ('/admin/time-off/{id}/closure-options', [\App\Http\Controllers\Api\TimeOffController::class, 'closureOptions'])->whereNumber('id');
        Route::post ('/admin/time-off/{id}/close',           [\App\Http\Controllers\Api\TimeOffController::class, 'closeForRequest'])->whereNumber('id');
    });

    // ---- Staff pay / payslips (educators = hourly, home visitors = per visit) ----
    Route::middleware('role:educator,home_visitor,centre_director,agency_admin,platform_admin')->group(function () {
        Route::get('/me/payslips',              [\App\Http\Controllers\Api\PayController::class, 'myPayslips']);
        Route::get('/me/payslips/{start}/pdf',  [\App\Http\Controllers\Api\PayController::class, 'payslipPdf']);
    });
    // Admins/directors set a staff member's pay rate + type.
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::patch('/admin/users/{user}/pay', [\App\Http\Controllers\Api\PayController::class, 'adminSetPay']);
        // Configurable payslip wording (custom body note + confidential text).
        Route::get ('/agency/payslip-settings', [\App\Http\Controllers\Api\PayController::class, 'payslipSettings']);
        Route::post('/agency/payslip-settings', [\App\Http\Controllers\Api\PayController::class, 'savePayslipSettings']);
    });

    // ---- Background checks ----
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::get   ('/admin/background-checks',          [\App\Http\Controllers\Api\BackgroundCheckController::class, 'listAgency']);
        Route::post  ('/admin/background-checks',          [\App\Http\Controllers\Api\BackgroundCheckController::class, 'upsert']);
        Route::post  ('/admin/background-checks/{id}/document', [\App\Http\Controllers\Api\BackgroundCheckController::class, 'uploadDocument']);
        Route::delete('/admin/background-checks/{id}',     [\App\Http\Controllers\Api\BackgroundCheckController::class, 'destroy']);
    });

    // ---- Payroll ----
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::get('/admin/payroll', [\App\Http\Controllers\Api\PayrollController::class, 'summary']);
        // Pay periods (2026-10-31): one row per period the payslips carry, and one period's payslips with clock hours beside them.
        Route::get('/admin/payroll/periods', [\App\Http\Controllers\Api\PayrollController::class, 'periods']);
        Route::get('/admin/payroll/periods/detail', [\App\Http\Controllers\Api\PayrollController::class, 'periodDetail']);
        // Required app notifications for staff (2026-10-31): readiness list for admins + directors, settings/instructions for admins (checked in the controller).
        Route::get ('/admin/notify-readiness', [\App\Http\Controllers\Api\StaffNotifyController::class, 'readiness'])->middleware('throttle:60,1,r-notify-readiness');
        Route::post('/admin/notify-readiness/instructions', [\App\Http\Controllers\Api\StaffNotifyController::class, 'instructions'])->middleware('throttle:10,1,r-notify-instructions');
        Route::get ('/admin/staff-notify-settings', [\App\Http\Controllers\Api\StaffNotifyController::class, 'show']);
        Route::post('/admin/staff-notify-settings', [\App\Http\Controllers\Api\StaffNotifyController::class, 'save'])->middleware('throttle:30,1,r-staff-notify-settings');
        // Immunization + drill notices per role (2026-11-09, App\Support\StaffTopicNotify): admins only, checked in the controller.
        Route::get ('/admin/topic-notifications', [\App\Http\Controllers\Api\StaffNotifyController::class, 'topicsShow']);
        Route::post('/admin/topic-notifications', [\App\Http\Controllers\Api\StaffNotifyController::class, 'topicsSave'])->middleware('throttle:30,1,r-topic-notifications');
        // Settings -> Notifications -> Staff certifications (2026-11-03): expiry alert steps, who is told, email.
        Route::get ('/admin/cert-alerts', [\App\Http\Controllers\Api\CertAlertSettingsController::class, 'show']);
        Route::post('/admin/cert-alerts', [\App\Http\Controllers\Api\CertAlertSettingsController::class, 'save'])->middleware('throttle:30,1,r-cert-alerts');
        Route::post('/admin/cert-alerts/preview', [\App\Http\Controllers\Api\CertAlertSettingsController::class, 'preview'])->middleware('throttle:30,1,r-cert-alerts-preview');
        // Reports → Payroll report (2026-10-31): the pay-period payslips, filtered and cut five ways.
        Route::get('/admin/payroll/report', [\App\Http\Controllers\Api\PayrollController::class, 'report'])->middleware('throttle:60,1,r-payroll-report');
    });

    /* CURRENCY AND COUNTRY ARE READ BY EVERYONE.

       kt-money.js formats money on every screen and asks for the agency's currency on
       boot, for whoever is signed in. Behind an admin role that meant an educator or a
       parent 403'd on every page load — and the caller falls back to CAD, so an agency
       billing in USD quietly showed its staff and families Canadian dollars.

       A currency code, its symbol and the supported list are not privileged, and
       resolveAgencyId() already confines the answer to the caller's own agency. The
       PATCH counterparts stay in the admin group below, and both write methods call
       assertAdmin() themselves as well. */
    Route::get('/admin/currency', [\App\Http\Controllers\Api\CurrencyController::class, 'show']);
    Route::get('/admin/country',  [\App\Http\Controllers\Api\CurrencyController::class, 'showCountry']);
    // Province / state profile (2026-10-09, App\Support\Jurisdictions) — read by screens for wording and rules.
    Route::get('/admin/jurisdiction', [\App\Http\Controllers\Api\CurrencyController::class, 'showJurisdiction']);
    /* HEADCOUNTS + ACTING SUPERVISOR (2026-10-13; Ontario O. Reg. 137/15 s.11 and s.6.1(2) as amended by
       O. Reg. 197/26). Admins, directors (their centres) and educators (their centres and rooms); scoped
       in the controller. Acting supervisor edits: agency admins and the centre's directors. */
    Route::middleware('role:agency_admin,platform_admin,centre_director,educator')->group(function () {
        Route::get ('/headcounts',                 [\App\Http\Controllers\Api\HeadcountController::class, 'index']);
        Route::post('/headcounts',                 [\App\Http\Controllers\Api\HeadcountController::class, 'store']);
        Route::get ('/headcounts/start',           [\App\Http\Controllers\Api\HeadcountController::class, 'start']);
        Route::get ('/headcounts/overview',        [\App\Http\Controllers\Api\HeadcountController::class, 'overview']);
        Route::post('/headcounts/settings',        [\App\Http\Controllers\Api\HeadcountController::class, 'settings']);
        Route::post('/headcounts/{id}/alert',      [\App\Http\Controllers\Api\HeadcountController::class, 'alert'])->whereNumber('id');
        Route::post('/headcounts/{id}/resolve',    [\App\Http\Controllers\Api\HeadcountController::class, 'resolve'])->whereNumber('id');
        Route::get ('/headcounts/acting/{centre}', [\App\Http\Controllers\Api\HeadcountController::class, 'actingShow'])->whereNumber('centre');
        Route::post('/headcounts/acting/{centre}', [\App\Http\Controllers\Api\HeadcountController::class, 'actingSave'])->whereNumber('centre');
    });
    /* OCCMS MONTHLY REPORTING (2026-10-13): fee subsidy Record of Attendance + CWELCC monthly summary.
       Ontario agencies only; admins (agency) and directors (their centres) — scoped in OccmsController. */
    Route::middleware('role:agency_admin,platform_admin,centre_director')->group(function () {
        Route::get  ('/occms/status',       [\App\Http\Controllers\Api\OccmsController::class, 'status']);
        Route::get  ('/occms/roa',          [\App\Http\Controllers\Api\OccmsController::class, 'show']);
        Route::patch('/occms/roa/cell',     [\App\Http\Controllers\Api\OccmsController::class, 'cell']);
        Route::post ('/occms/roa/capacity', [\App\Http\Controllers\Api\OccmsController::class, 'capacity']);
        Route::post ('/occms/roa/status',   [\App\Http\Controllers\Api\OccmsController::class, 'transition']);
        Route::get  ('/occms/roa/csv',      [\App\Http\Controllers\Api\OccmsController::class, 'csv']);
        Route::get  ('/occms/roa/pdf',      [\App\Http\Controllers\Api\OccmsController::class, 'pdf']);
        Route::get  ('/occms/cwelcc',       [\App\Http\Controllers\Api\OccmsController::class, 'cwelcc']);
        Route::get  ('/occms/cwelcc/csv',   [\App\Http\Controllers\Api\OccmsController::class, 'cwelccCsv']);
        Route::post ('/occms/cwelcc/fees',  [\App\Http\Controllers\Api\OccmsController::class, 'cwelccFees']);
        // Monthly attendance & fees (2026-11-13): every enrolled child, hours per day, fees, space type (App\Support\MonthlyAttendance).
        Route::get  ('/occms/attendance',      [\App\Http\Controllers\Api\OccmsController::class, 'attendance']);
        Route::get  ('/occms/attendance/xlsx', [\App\Http\Controllers\Api\OccmsController::class, 'attendanceXlsx']);
        Route::get  ('/occms/attendance/pdf',  [\App\Http\Controllers\Api\OccmsController::class, 'attendancePdf']);
        Route::post ('/occms/settings',     [\App\Http\Controllers\Api\OccmsController::class, 'settings']);
    });
    // Drill log (2026-10-09): admins, directors and educators; scoped to their centres in the controller.
    Route::middleware('role:agency_admin,platform_admin,centre_director,educator')->group(function () {
        Route::get   ('/drills',           [\App\Http\Controllers\Api\DrillController::class, 'index']);
        Route::post  ('/drills',           [\App\Http\Controllers\Api\DrillController::class, 'store']);
        Route::delete('/drills/{id}',      [\App\Http\Controllers\Api\DrillController::class, 'destroy'])->whereNumber('id');
        Route::post  ('/drills/reminders', [\App\Http\Controllers\Api\DrillController::class, 'reminders']);
        // Ontario s.68 / s.68.2 (2026-10-13): fire cadence per centre, cadence reminders switch.
        Route::post  ('/drills/cadence',           [\App\Http\Controllers\Api\DrillController::class, 'cadence']);
        Route::post  ('/drills/cadence-reminders', [\App\Http\Controllers\Api\DrillController::class, 'cadenceReminders']);
        Route::post  ('/drills/requirement',      [\App\Http\Controllers\Api\DrillController::class, 'requirement']);
        // Centre map (2026-11-09): upload / replace (anyone with the centre), remove (directors/admins).
        Route::post  ('/drills/staff-dates', [\App\Http\Controllers\Api\DrillController::class, 'staffDatesSave']);
        Route::post  ('/drills/staff-schedule', [\App\Http\Controllers\Api\DrillController::class, 'staffScheduleSave']);
        Route::post  ('/drills/notify-settings', [\App\Http\Controllers\Api\DrillController::class, 'notifySettingsSave']);
        Route::post  ('/drills/maps',      [\App\Http\Controllers\Api\DrillController::class, 'uploadMap']);
        Route::delete('/drills/maps/{id}', [\App\Http\Controllers\Api\DrillController::class, 'removeMap'])->whereNumber('id');
    });
    /* HAZARD REGISTER (2026-10-13, Ontario s.24.3): anyone on staff reports; directors/admins
       manage and notify parents — checked per centre in HazardController. Its own group since
       2026-10-15 so home visitors can report without also getting the drill log. */
    Route::middleware('role:agency_admin,platform_admin,centre_director,educator,home_visitor')->group(function () {
        Route::get   ('/hazards',                 [\App\Http\Controllers\Api\HazardController::class, 'index']);
        Route::post  ('/hazards',                 [\App\Http\Controllers\Api\HazardController::class, 'store']);
        Route::patch ('/hazards/{id}',            [\App\Http\Controllers\Api\HazardController::class, 'update'])->whereNumber('id');
        Route::post  ('/hazards/{id}/resolve',    [\App\Http\Controllers\Api\HazardController::class, 'resolve'])->whereNumber('id');
        Route::post  ('/hazards/{id}/reopen',     [\App\Http\Controllers\Api\HazardController::class, 'reopen'])->whereNumber('id');
        Route::get   ('/hazards/{id}/audience',   [\App\Http\Controllers\Api\HazardController::class, 'audienceCount'])->whereNumber('id');
        Route::post  ('/hazards/{id}/notify',     [\App\Http\Controllers\Api\HazardController::class, 'notify'])->whereNumber('id');
    });
    /* DAILY RECORDS REMINDERS (2026-10-15): Settings > Room Controls > Daily records. Admins edit,
       directors read (their centres' reminders) - enforced in RecordReminderController. */
    Route::middleware('role:agency_admin,platform_admin,centre_director')->group(function () {
        Route::get ('/admin/record-reminders', [\App\Http\Controllers\Api\RecordReminderController::class, 'show']);
        Route::post('/admin/record-reminders', [\App\Http\Controllers\Api\RecordReminderController::class, 'update']);
    });
    /* WALKS (2026-10-21): Settings > Room Controls > Walks. Admins edit, directors read. */
    Route::middleware('role:agency_admin,platform_admin,centre_director')->group(function () {
        Route::get ('/admin/walk-settings', [\App\Http\Controllers\Api\WalkController::class, 'walkSettings']);
        Route::post('/admin/walk-settings', [\App\Http\Controllers\Api\WalkController::class, 'updateWalkSettings']);
    });
    /* POLICY LIBRARY + STAFF SIGN-OFFS + TRAINING MATRIX (2026-10-13; O. Reg. 137/15 s.6.1, s.46, s.58(1.1)).
       Managing: admins (agency) and directors (their centres) — scoped again in the controller.
       Reading and signing: every staff member the policy applies to. */
    Route::middleware('role:agency_admin,platform_admin,centre_director')->group(function () {
        Route::get   ('/policies',                       [\App\Http\Controllers\Api\PolicyController::class, 'index']);
        Route::post  ('/policies',                       [\App\Http\Controllers\Api\PolicyController::class, 'store']);
        Route::post  ('/policies/seed-ontario',          [\App\Http\Controllers\Api\PolicyController::class, 'seedOntario']);
        Route::post  ('/policies/reminders',             [\App\Http\Controllers\Api\PolicyController::class, 'reminders']);
        Route::get   ('/policies/matrix',                [\App\Http\Controllers\Api\PolicyController::class, 'matrix']);
        Route::get   ('/policy-contraventions',          [\App\Http\Controllers\Api\PolicyController::class, 'contraventions']);
        Route::get   ('/policies/{id}',                  [\App\Http\Controllers\Api\PolicyController::class, 'show'])->whereNumber('id');
        Route::patch ('/policies/{id}',                  [\App\Http\Controllers\Api\PolicyController::class, 'update'])->whereNumber('id');
        Route::post  ('/policies/{id}/archive',          [\App\Http\Controllers\Api\PolicyController::class, 'archive'])->whereNumber('id');
        Route::post  ('/policies/{id}/versions',         [\App\Http\Controllers\Api\PolicyController::class, 'publish'])->whereNumber('id')->middleware('throttle:30,1,r-grp-2792');
        Route::post  ('/policies/{id}/reviewed',         [\App\Http\Controllers\Api\PolicyController::class, 'reviewed'])->whereNumber('id');
        Route::post  ('/policies/{id}/contraventions',   [\App\Http\Controllers\Api\PolicyController::class, 'recordContravention'])->whereNumber('id');
        Route::post  ('/policies/{id}/training',         [\App\Http\Controllers\Api\PolicyController::class, 'recordTraining'])->whereNumber('id');
    });
    Route::middleware('role:agency_admin,platform_admin,centre_director,educator,home_visitor')->group(function () {
        Route::get   ('/my-policies',                    [\App\Http\Controllers\Api\PolicyController::class, 'mine']);
        Route::post  ('/my-policies/{id}/acknowledge',   [\App\Http\Controllers\Api\PolicyController::class, 'acknowledge'])->whereNumber('id')->middleware('throttle:60,1,r-grp-2799');
        Route::get   ('/policy-versions/{id}',           [\App\Http\Controllers\Api\PolicyController::class, 'version'])->whereNumber('id');
        Route::get   ('/policy-versions/{id}/file',      [\App\Http\Controllers\Api\PolicyController::class, 'file'])->whereNumber('id');
    });
    /* OHIO PFCC + STEP UP TO QUALITY (2026-10-10): directors (their centres) and admins. Both
       controllers 404 unless the agency is US-OH or has switched the feature on. */
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::get   ('/pfcc',                              [\App\Http\Controllers\Api\PfccController::class, 'index']);
        Route::get   ('/pfcc/children',                     [\App\Http\Controllers\Api\PfccController::class, 'children']);
        Route::post  ('/pfcc/authorizations',               [\App\Http\Controllers\Api\PfccController::class, 'store']);
        Route::patch ('/pfcc/authorizations/{id}',          [\App\Http\Controllers\Api\PfccController::class, 'update'])->whereNumber('id');
        Route::post  ('/pfcc/authorizations/{id}/end',      [\App\Http\Controllers\Api\PfccController::class, 'end'])->whereNumber('id');
        Route::post  ('/pfcc/authorizations/{id}/agreement',[\App\Http\Controllers\Api\PfccController::class, 'uploadAgreement'])->whereNumber('id');
        Route::get   ('/pfcc/authorizations/{id}/agreement',[\App\Http\Controllers\Api\PfccController::class, 'agreement'])->whereNumber('id');
        Route::get   ('/pfcc/authorizations/{id}/copays',   [\App\Http\Controllers\Api\PfccController::class, 'copays'])->whereNumber('id');
        Route::patch ('/pfcc/copays/{id}',                  [\App\Http\Controllers\Api\PfccController::class, 'payCopay'])->whereNumber('id');
        Route::get   ('/pfcc/reconciliation',               [\App\Http\Controllers\Api\PfccController::class, 'reconciliation']);
        Route::get   ('/pfcc/invoices',                     [\App\Http\Controllers\Api\PfccController::class, 'invoices']);
        Route::get   ('/pfcc/invoices/{id}/split',          [\App\Http\Controllers\Api\PfccController::class, 'splitPreview'])->whereNumber('id');
        Route::post  ('/pfcc/invoices/{id}/split',          [\App\Http\Controllers\Api\PfccController::class, 'splitApply'])->whereNumber('id');
        Route::delete('/pfcc/invoices/{id}/split',          [\App\Http\Controllers\Api\PfccController::class, 'splitUndo'])->whereNumber('id');
        Route::get   ('/pfcc/settings',                     [\App\Http\Controllers\Api\PfccController::class, 'settings']);
        Route::patch ('/pfcc/settings',                     [\App\Http\Controllers\Api\PfccController::class, 'settings']);
        Route::get   ('/sutq',                              [\App\Http\Controllers\Api\SutqController::class, 'index']);
        Route::get   ('/sutq/binder',                       [\App\Http\Controllers\Api\SutqController::class, 'binder']);
        Route::post  ('/sutq/events',                       [\App\Http\Controllers\Api\SutqController::class, 'storeEvent']);
        Route::patch ('/sutq/events/{id}',                  [\App\Http\Controllers\Api\SutqController::class, 'updateEvent'])->whereNumber('id');
        Route::get   ('/sutq/children/{id}/events',         [\App\Http\Controllers\Api\SutqController::class, 'childEvents'])->whereNumber('id');
        Route::post  ('/sutq/referrals',                    [\App\Http\Controllers\Api\SutqController::class, 'saveReferral']);
        Route::patch ('/sutq/referrals/{id}',               [\App\Http\Controllers\Api\SutqController::class, 'saveReferral'])->whereNumber('id');
        Route::post  ('/sutq/cip',                          [\App\Http\Controllers\Api\SutqController::class, 'saveCip']);
        Route::patch ('/sutq/cip/{id}',                     [\App\Http\Controllers\Api\SutqController::class, 'saveCip'])->whereNumber('id');
        Route::delete('/sutq/cip/{id}',                     [\App\Http\Controllers\Api\SutqController::class, 'saveCip'])->whereNumber('id');
    });

    // ---- Agency billing config (late-fee + SMS + locale) ----
    /* TUITION / FEE PLANS, OWN CENTRE (2026-10-05): a centre director manages their own
       centres' plans; FeePlanController keeps agency-wide plans the agency admin's. */
    Route::middleware('role:agency_admin,centre_director,platform_admin')->group(function () {
        Route::get   ('/admin/fee-plans',        [\App\Http\Controllers\Api\FeePlanController::class, 'index']);
        Route::post  ('/admin/fee-plans',        [\App\Http\Controllers\Api\FeePlanController::class, 'store']);
        Route::patch ('/admin/fee-plans/{id}',   [\App\Http\Controllers\Api\FeePlanController::class, 'update']);
        Route::delete('/admin/fee-plans/{id}',   [\App\Http\Controllers\Api\FeePlanController::class, 'destroy']);
        Route::post  ('/admin/fee-plans/{id}/generate-invoices', [\App\Http\Controllers\Api\FeePlanController::class, 'generateInvoices']);
    });

    Route::middleware('role:agency_admin,platform_admin')->group(function () {
        Route::get  ('/admin/billing-config', [\App\Http\Controllers\Api\AgencyBillingConfigController::class, 'show']);
        // v22p87: combined guided billing setup (defaults + payment methods + autopay)
        Route::get  ('/admin/billing-setup', [\App\Http\Controllers\Api\AgencyBillingConfigController::class, 'showSetup']);
        Route::patch('/admin/billing-setup', [\App\Http\Controllers\Api\AgencyBillingConfigController::class, 'updateSetup']);
        // v22p86: reusable tuition / fee plans live in their own group above (directors too).
        /* The GETs live OUTSIDE this group — see below. Only the writes are
           administrative. */
        Route::patch('/admin/currency', [\App\Http\Controllers\Api\CurrencyController::class, 'update']);
        Route::patch('/admin/country', [\App\Http\Controllers\Api\CurrencyController::class, 'updateCountry']);
        Route::patch('/admin/jurisdiction', [\App\Http\Controllers\Api\CurrencyController::class, 'updateJurisdiction']);
        // Twilio credentials per agency, set from the portal rather than .env.
        Route::get  ('/admin/sms-settings',      [\App\Http\Controllers\Api\SmsSettingsController::class, 'show']);
        Route::get  ('/admin/sms-settings/telnyx-usage', [\App\Http\Controllers\Api\SmsSettingsController::class, 'telnyxUsage']);
        Route::patch('/admin/sms-settings',      [\App\Http\Controllers\Api\SmsSettingsController::class, 'update']);
        Route::post ('/admin/sms-settings/test', [\App\Http\Controllers\Api\SmsSettingsController::class, 'test']);
        /* SENDS A REAL ONE to a number typed on the screen. Agency admins only, and
           carrying its own role guard as well as the controller's assertAdmin: this
           one spends money and can ring a stranger, so it should not be reachable by
           a director the way the rest of this group is. Five an hour, per agency,
           enforced in the controller. */
        Route::post ('/admin/sms-settings/test-send', [\App\Http\Controllers\Api\SmsSettingsController::class, 'testSend'])
            ->middleware('role:agency_admin,platform_admin');
        Route::get('/admin/email-settings', [\App\Http\Controllers\Api\EmailSettingsController::class, 'show']);
        Route::patch('/admin/email-settings', [\App\Http\Controllers\Api\EmailSettingsController::class, 'update']);
        Route::post('/admin/email-settings/test', [\App\Http\Controllers\Api\EmailSettingsController::class, 'sendTest']);
        Route::get('/email/accounts', [\App\Http\Controllers\Api\EmailAccountController::class, 'index']);
        Route::post('/email/accounts', [\App\Http\Controllers\Api\EmailAccountController::class, 'store']);
        Route::patch('/email/accounts/{id}', [\App\Http\Controllers\Api\EmailAccountController::class, 'update']);
        Route::delete('/email/accounts/{id}', [\App\Http\Controllers\Api\EmailAccountController::class, 'destroy']);
        Route::post('/email/accounts/{id}/test', [\App\Http\Controllers\Api\EmailAccountController::class, 'test']);
        Route::get('/email/accounts/{id}/auto-replies', [\App\Http\Controllers\Api\EmailAccountController::class, 'autoReplies'])->middleware('throttle:60,1,r-email-auto-replies');
        Route::put('/email/accounts/{id}/auto-replies', [\App\Http\Controllers\Api\EmailAccountController::class, 'saveAutoReplies'])->middleware('throttle:20,1,r-email-auto-replies-save');
        Route::put('/email/accounts/{id}/alerts', [\App\Http\Controllers\Api\EmailAccountController::class, 'saveAlerts'])->middleware('throttle:60,1,r-email-alerts');
        Route::post('/email/accounts/{id}/microsoft', [\App\Http\Controllers\Api\EmailAccountController::class, 'microsoftStart'])->middleware('throttle:20,1,r-email-accounts-x-microsoft-2876');
        Route::post('/email/microsoft/finish', [\App\Http\Controllers\Api\EmailAccountController::class, 'microsoftFinish'])->middleware('throttle:20,1,r-email-microsoft-finish-2877');
        // Sign in with Google + "which provider is this address?" (2026-11-04).
        Route::post('/email/accounts/{id}/google', [\App\Http\Controllers\Api\EmailAccountController::class, 'googleStart'])->middleware('throttle:20,1,r-email-accounts-x-google-2');
        Route::post('/email/discover', [\App\Http\Controllers\Api\EmailAccountController::class, 'discover'])->middleware('throttle:30,1,r-email-discover-2');
        Route::get('/email/folders', [\App\Http\Controllers\Api\EmailMailController::class, 'folders']);
        Route::get('/email/messages', [\App\Http\Controllers\Api\EmailMailController::class, 'messages']);
        Route::get('/email/messages/{uid}', [\App\Http\Controllers\Api\EmailMailController::class, 'message']);
        Route::get('/email/messages/{uid}/attachment', [\App\Http\Controllers\Api\EmailMailController::class, 'attachment']);
        Route::post('/email/messages/{uid}/action', [\App\Http\Controllers\Api\EmailMailController::class, 'action']);
        Route::post('/email/messages/bulk', [\App\Http\Controllers\Api\EmailMailController::class, 'bulk'])->middleware('throttle:60,1,r-email-bulk');
        Route::post('/email/send', [\App\Http\Controllers\Api\EmailMailController::class, 'send'])->middleware('throttle:30,1,r-email-send-own');   // own mailboxes send (2026-11-09)
        // Emails on a person's record (2026-11-07, App\Support\PersonMail).
        Route::get('/email/messages/{uid}/links', [\App\Http\Controllers\Api\EmailLinkController::class, 'show'])->middleware('throttle:60,1,r-email-links');
        Route::post('/email/messages/{uid}/links', [\App\Http\Controllers\Api\EmailLinkController::class, 'store'])->middleware('throttle:60,1,r-email-links-save');
        Route::delete('/email/links/{id}', [\App\Http\Controllers\Api\EmailLinkController::class, 'destroy'])->whereNumber('id')->middleware('throttle:60,1,r-email-links-save');
        Route::get('/email/link-people', [\App\Http\Controllers\Api\EmailLinkController::class, 'people'])->middleware('throttle:120,1,r-email-link-people');
        Route::get('/admin/email-links/person', [\App\Http\Controllers\Api\EmailLinkController::class, 'person'])->middleware('throttle:120,1,r-email-links-person');
        Route::get('/admin/email-links/family/{id}', [\App\Http\Controllers\Api\EmailLinkController::class, 'family'])->whereNumber('id')->middleware('throttle:120,1,r-email-links-family');
        Route::get('/admin/email-link-settings', [\App\Http\Controllers\Api\EmailLinkController::class, 'settings']);
        Route::put('/admin/email-link-settings', [\App\Http\Controllers\Api\EmailLinkController::class, 'saveSettings'])->middleware('throttle:30,1,r-email-link-settings');
        // Email delivery control panel — hierarchical centre/room email switches (pre-boarding).
        Route::get  ('/admin/email-delivery',                [\App\Http\Controllers\Api\EmailDeliveryController::class, 'index']);
        Route::patch('/admin/email-delivery/centre/{centre}', [\App\Http\Controllers\Api\EmailDeliveryController::class, 'setCentre']);
        Route::patch('/admin/email-delivery/room/{room}',    [\App\Http\Controllers\Api\EmailDeliveryController::class, 'setRoom']);
        Route::get  ('/admin/billing-settings', [\App\Http\Controllers\Api\BillingSettingsController::class, 'show']);
        Route::post ('/admin/billing-settings', [\App\Http\Controllers\Api\BillingSettingsController::class, 'update']);
        Route::patch('/admin/billing-settings', [\App\Http\Controllers\Api\BillingSettingsController::class, 'update']);
        Route::get  ('/admin/calendar-settings', [\App\Http\Controllers\Api\CalendarSettingsController::class, 'show']);
        Route::post ('/admin/calendar-settings', [\App\Http\Controllers\Api\CalendarSettingsController::class, 'update']);
        Route::patch('/admin/calendar-settings', [\App\Http\Controllers\Api\CalendarSettingsController::class, 'update']);
        Route::get  ('/admin/auto-signoff', [\App\Http\Controllers\Api\AutoSignOffController::class, 'show']);
        Route::post ('/admin/auto-signoff', [\App\Http\Controllers\Api\AutoSignOffController::class, 'update']);
        // Clock reminders sit beside auto sign-off, on the same screen.
        // Settings -> Payment providers. Agency admins only; the controller re-checks.
        Route::get  ('/admin/payment-providers', [\App\Http\Controllers\Api\PaymentProvidersController::class, 'index']);
        Route::post ('/admin/payment-providers/{provider}', [\App\Http\Controllers\Api\PaymentProvidersController::class, 'update']);
        /* Does the stored Helcim token actually work? A button, rather than finding out
           when a parent cannot pay. Costs nothing: a zero-dollar verify session. */
        Route::post ('/admin/payment-providers/helcim/test', [\App\Http\Controllers\Api\HelcimController::class, 'test']);
        Route::post ('/admin/payment-providers/stripe/wallet-domains', [\App\Http\Controllers\Api\WalletPayController::class, 'registerDomains']);   // Apple Pay domains (2026-11-03)
        Route::post ('/admin/payment-providers/stripe/webhook', [\App\Http\Controllers\Api\PaymentProvidersController::class, 'connectStripeWebhook']);   // family payments recorded (2026-11-05)
        /* Audit log -> Email log / Email errors for an AGENCY admin (2026-11-03): their own agency's mail only.
           The controller pins the active agency and re-checks the role; /platform/email-logs stays super-admin. */
        Route::get  ('/admin/email-logs', [\App\Http\Controllers\Api\AgencyEmailLogController::class, 'index']);
        Route::get  ('/admin/email-logs/{id}/preview', [\App\Http\Controllers\Api\AgencyEmailLogController::class, 'preview'])->whereNumber('id');
        Route::get  ('/admin/email-logs/{id}/attachments/{aid}', [\App\Http\Controllers\Api\AgencyEmailLogController::class, 'attachment'])->whereNumber('id')->whereNumber('aid');
        Route::post ('/admin/email-logs/{id}/resend', [\App\Http\Controllers\Api\AgencyEmailLogController::class, 'resend'])->whereNumber('id')->middleware('throttle:20,1,r-agency-email-resend');

        /* Settings -> Backups. The controller re-checks platform_admin; there is NO route
           that serves a dump, deliberately — see the note on BackupController. */
        Route::get  ('/admin/backups',          [\App\Http\Controllers\Api\BackupController::class, 'index']);
        Route::post ('/admin/backups/settings', [\App\Http\Controllers\Api\BackupController::class, 'update']);
        Route::post ('/admin/backups/run',      [\App\Http\Controllers\Api\BackupController::class, 'run']);

        Route::get  ('/admin/clock-reminders', [\App\Http\Controllers\Api\ClockRemindersController::class, 'show']);
        Route::post ('/admin/clock-reminders', [\App\Http\Controllers\Api\ClockRemindersController::class, 'update']);
        // Settings -> Clock settings -> Time-off approvals (reminders to approvers).
        Route::get  ('/admin/timeoff-reminders',         [\App\Http\Controllers\Api\TimeOffRemindersController::class, 'show']);
        Route::post ('/admin/timeoff-reminders',         [\App\Http\Controllers\Api\TimeOffRemindersController::class, 'update']);
        Route::post ('/admin/timeoff-reminders/preview', [\App\Http\Controllers\Api\TimeOffRemindersController::class, 'preview']);
        Route::post ('/admin/timeoff-reminders/test',    [\App\Http\Controllers\Api\TimeOffRemindersController::class, 'test'])->middleware('throttle:6,1,toremtest');
        // Settings → Notifications: reminders to counter-sign completed forms (2026-10-09).
        Route::get  ('/admin/countersign-reminders',         [\App\Http\Controllers\Api\CountersignRemindersController::class, 'show']);
        Route::post ('/admin/countersign-reminders',         [\App\Http\Controllers\Api\CountersignRemindersController::class, 'update']);
        Route::post ('/admin/countersign-reminders/preview', [\App\Http\Controllers\Api\CountersignRemindersController::class, 'preview']);
        Route::post ('/admin/countersign-reminders/test',    [\App\Http\Controllers\Api\CountersignRemindersController::class, 'test'])->middleware('throttle:6,1,csremtest');
        // Temporary (forms-only) parents not on a family yet — App\Support\TempGuardians (2026-10-13).
        // Address autocomplete for address fields (2026-10-13) — App\Http\Controllers\Api\AddressController.
        Route::get   ('/address/suggest', [\App\Http\Controllers\Api\AddressController::class, 'suggest'])->middleware('throttle:60,1,r-grp-2923');
        Route::get   ('/admin/temp-guardians/settings', [\App\Http\Controllers\Api\TempGuardianController::class, 'settings']);
        Route::post  ('/admin/temp-guardians/settings', [\App\Http\Controllers\Api\TempGuardianController::class, 'saveSettings']);
        Route::get   ('/admin/temp-guardians',          [\App\Http\Controllers\Api\TempGuardianController::class, 'index'])->middleware('throttle:120,1,r-grp-2926');
        Route::delete('/admin/temp-guardians/{user}',   [\App\Http\Controllers\Api\TempGuardianController::class, 'destroy'])->whereNumber('user');
        // Settings → Notifications: reminders for providers to write their bio (2026-10-09).
        Route::get  ('/admin/bio-reminders',         [\App\Http\Controllers\Api\BioRemindersController::class, 'show']);
        Route::post ('/admin/bio-reminders',         [\App\Http\Controllers\Api\BioRemindersController::class, 'update']);
        Route::post ('/admin/bio-reminders/preview', [\App\Http\Controllers\Api\BioRemindersController::class, 'preview']);
        Route::post ('/admin/bio-reminders/test',    [\App\Http\Controllers\Api\BioRemindersController::class, 'test'])->middleware('throttle:6,1,bioremtest');
        // Settings → Notifications → Message Centre: who educators and home visitors may message (2026-10-09).
        Route::get  ('/admin/messaging-rules',       [\App\Http\Controllers\Api\MessagingRulesController::class, 'show']);
        Route::post ('/admin/messaging-rules',       [\App\Http\Controllers\Api\MessagingRulesController::class, 'update']);
        // Settings → Notifications → Daily summaries (2026-10-29, App\Support\SummaryTiming).
        Route::get  ('/admin/summary-timing',        [\App\Http\Controllers\Api\SummaryTimingController::class, 'show']);
        Route::post ('/admin/summary-timing',        [\App\Http\Controllers\Api\SummaryTimingController::class, 'update']);
        // Payroll settings → Lunch and breaks: paid or unpaid (2026-11-05, App\Support\StaffBreaks).
        Route::get  ('/admin/staff-breaks',          [\App\Http\Controllers\Api\StaffBreaksController::class, 'show']);
        Route::post ('/admin/staff-breaks',          [\App\Http\Controllers\Api\StaffBreaksController::class, 'update']);
        // Prospect NDAs (platform admins + sales reps; checked in the controller).
        Route::get   ('/admin/prospect-ndas',              [\App\Http\Controllers\Api\ProspectNdaController::class, 'index']);
        Route::post  ('/admin/prospect-ndas',              [\App\Http\Controllers\Api\ProspectNdaController::class, 'store'])->middleware('throttle:20,1,ndasend');
        Route::post  ('/admin/prospect-ndas/{id}/resend',  [\App\Http\Controllers\Api\ProspectNdaController::class, 'resend'])->where('id', '[0-9]+');
        Route::delete('/admin/prospect-ndas/{id}',         [\App\Http\Controllers\Api\ProspectNdaController::class, 'cancel'])->where('id', '[0-9]+');
        Route::get   ('/admin/prospect-ndas/{id}/preview', [\App\Http\Controllers\Api\ProspectNdaController::class, 'preview'])->where('id', '[0-9]+');
        Route::get   ('/admin/prospect-ndas/{id}/pdf',     [\App\Http\Controllers\Api\ProspectNdaController::class, 'pdf'])->where('id', '[0-9]+');
        // Seasonal email header banners (platform admins; checked in the controller).
        Route::get   ('/admin/email-header-seasons',          [\App\Http\Controllers\Api\EmailHeaderSeasonsController::class, 'index']);
        Route::post  ('/admin/email-header-seasons',          [\App\Http\Controllers\Api\EmailHeaderSeasonsController::class, 'store']);
        Route::post  ('/admin/email-header-seasons/preview',  [\App\Http\Controllers\Api\EmailHeaderSeasonsController::class, 'preview']);
        Route::post  ('/admin/email-header-seasons/{id}',     [\App\Http\Controllers\Api\EmailHeaderSeasonsController::class, 'update'])->where('id', '[0-9]+');
        Route::delete('/admin/email-header-seasons/{id}',     [\App\Http\Controllers\Api\EmailHeaderSeasonsController::class, 'destroy'])->where('id', '[0-9]+');
        Route::patch('/admin/auto-signoff', [\App\Http\Controllers\Api\AutoSignOffController::class, 'update']);
        // Pay schedule — drives the "Next payroll" card on the overview.
        Route::get  ('/admin/payroll-settings', [\App\Http\Controllers\Api\PayrollSettingsController::class, 'show']);
        Route::patch('/admin/payroll-settings', [\App\Http\Controllers\Api\PayrollSettingsController::class, 'update']);
        Route::post ('/admin/payroll-settings', [\App\Http\Controllers\Api\PayrollSettingsController::class, 'update']);
        Route::get  ('/admin/birthday-settings', [\App\Http\Controllers\Api\BirthdaySettingsController::class, 'show']);
        Route::patch('/admin/birthday-settings', [\App\Http\Controllers\Api\BirthdaySettingsController::class, 'update']);
        Route::post ('/admin/birthday-settings', [\App\Http\Controllers\Api\BirthdaySettingsController::class, 'update']);
        Route::get  ('/admin/moment-notify-settings', [\App\Http\Controllers\Api\MomentNotifySettingsController::class, 'show']);
        Route::post ('/admin/moment-notify-settings', [\App\Http\Controllers\Api\MomentNotifySettingsController::class, 'save'])->middleware('throttle:30,1,r-moment-notify-settings');
        Route::get('/admin/compliance-settings', [\App\Http\Controllers\Api\DataRetentionController::class, 'show']);
        Route::post('/admin/compliance-settings', [\App\Http\Controllers\Api\DataRetentionController::class, 'update']);
        Route::post('/admin/centre-term', [\App\Http\Controllers\Api\AgencyTermController::class, 'set']);
        Route::post('/admin/learning-framework', [\App\Http\Controllers\Api\LearningFrameworkController::class, 'update']);
        // Event RSVPs (2026-10-05). The controller re-checks agency_admin OF the active agency.
        Route::get   ('/admin/rsvp/templates', [\App\Http\Controllers\Api\RsvpController::class, 'templates']);
        Route::post  ('/admin/rsvp/templates', [\App\Http\Controllers\Api\RsvpController::class, 'saveTemplate']);
        Route::post  ('/admin/rsvp/templates/{id}', [\App\Http\Controllers\Api\RsvpController::class, 'saveTemplate'])->where('id', '[0-9]+');
        Route::delete('/admin/rsvp/templates/{id}', [\App\Http\Controllers\Api\RsvpController::class, 'deleteTemplate'])->where('id', '[0-9]+');
        Route::post  ('/admin/rsvp/templates/{id}/copy', [\App\Http\Controllers\Api\RsvpController::class, 'copyTemplate'])->where('id', '[0-9]+');
        Route::post  ('/admin/rsvp/image', [\App\Http\Controllers\Api\RsvpController::class, 'uploadImage']);
        // Round 2 (2026-10-06): built-in templates, preview (sends nothing), per-event sender accounts.
        Route::post  ('/admin/rsvp/presets/{key}/copy', [\App\Http\Controllers\Api\RsvpController::class, 'copyPreset'])->where('key', '[a-z_]{2,40}');
        Route::post  ('/admin/rsvp/events/{id}/save-template', [\App\Http\Controllers\Api\RsvpController::class, 'saveEventAsTemplate'])->where('id', '[0-9]+');
        Route::post  ('/admin/rsvp/events/{id}/status', [\App\Http\Controllers\Api\RsvpController::class, 'setEventStatus'])->where('id', '[0-9]+');
        Route::post  ('/admin/rsvp/events/{id}/duplicate', [\App\Http\Controllers\Api\RsvpController::class, 'duplicateEvent'])->where('id', '[0-9]+');
        Route::post  ('/admin/rsvp/preview', [\App\Http\Controllers\Api\RsvpController::class, 'preview'])->middleware('throttle:60,1,rsvppv');
        Route::get   ('/admin/rsvp/senders', [\App\Http\Controllers\Api\RsvpController::class, 'senders']);
        Route::post  ('/admin/rsvp/senders', [\App\Http\Controllers\Api\RsvpController::class, 'saveSender']);
        Route::post  ('/admin/rsvp/senders/{id}', [\App\Http\Controllers\Api\RsvpController::class, 'saveSender'])->where('id', '[0-9]+');
        Route::delete('/admin/rsvp/senders/{id}', [\App\Http\Controllers\Api\RsvpController::class, 'deleteSender'])->where('id', '[0-9]+');
        Route::post  ('/admin/rsvp/senders/{id}/check', [\App\Http\Controllers\Api\RsvpController::class, 'checkSender'])->where('id', '[0-9]+')->middleware('throttle:10,1,rsvpchk');
        Route::get   ('/admin/rsvp/senders/{id}/google/start', [\App\Http\Controllers\Api\RsvpController::class, 'googleStart'])->where('id', '[0-9]+')->middleware('throttle:20,1,rsvpgs');
        Route::post  ('/admin/rsvp/senders/{id}/test', [\App\Http\Controllers\Api\RsvpController::class, 'testSender'])->where('id', '[0-9]+')->middleware('throttle:10,1,rsvpst');
        Route::get   ('/admin/rsvp/contacts', [\App\Http\Controllers\Api\RsvpController::class, 'contacts']);
        Route::get   ('/admin/rsvp/optouts', [\App\Http\Controllers\Api\RsvpController::class, 'optOuts']);
        Route::delete('/admin/rsvp/optouts/{id}', [\App\Http\Controllers\Api\RsvpController::class, 'removeOptOut'])->where('id', '[0-9]+');
        Route::get   ('/admin/rsvp/events', [\App\Http\Controllers\Api\RsvpController::class, 'events']);
        Route::post  ('/admin/rsvp/events', [\App\Http\Controllers\Api\RsvpController::class, 'saveEvent']);
        Route::get   ('/admin/rsvp/events/{id}', [\App\Http\Controllers\Api\RsvpController::class, 'event'])->where('id', '[0-9]+');
        Route::post  ('/admin/rsvp/events/{id}', [\App\Http\Controllers\Api\RsvpController::class, 'saveEvent'])->where('id', '[0-9]+');
        Route::delete('/admin/rsvp/events/{id}', [\App\Http\Controllers\Api\RsvpController::class, 'deleteEvent'])->where('id', '[0-9]+');
        Route::post  ('/admin/rsvp/events/{id}/invites', [\App\Http\Controllers\Api\RsvpController::class, 'addInvites'])->where('id', '[0-9]+');
        Route::post  ('/admin/rsvp/events/{id}/send', [\App\Http\Controllers\Api\RsvpController::class, 'sendInvites'])->where('id', '[0-9]+');
        Route::post  ('/admin/rsvp/events/{id}/test', [\App\Http\Controllers\Api\RsvpController::class, 'testInvite'])->where('id', '[0-9]+')->middleware('throttle:10,1,rsvptest');
        Route::post  ('/admin/rsvp/events/{id}/test-confirmation', [\App\Http\Controllers\Api\RsvpController::class, 'testConfirmation'])->where('id', '[0-9]+')->middleware('throttle:10,1,rsvptc');
        // Event reminders (2026-10-06): scheduled or sent now.
        Route::get   ('/admin/rsvp/events/{id}/reminders', [\App\Http\Controllers\Api\RsvpController::class, 'reminders'])->where('id', '[0-9]+');
        Route::post  ('/admin/rsvp/events/{id}/reminders', [\App\Http\Controllers\Api\RsvpController::class, 'saveReminder'])->where('id', '[0-9]+');
        Route::post  ('/admin/rsvp/events/{id}/reminders/{rid}', [\App\Http\Controllers\Api\RsvpController::class, 'saveReminder'])->where(['id' => '[0-9]+', 'rid' => '[0-9]+']);
        Route::post  ('/admin/rsvp/events/{id}/reminders/{rid}/cancel', [\App\Http\Controllers\Api\RsvpController::class, 'cancelReminder'])->where(['id' => '[0-9]+', 'rid' => '[0-9]+']);
        Route::post  ('/admin/rsvp/events/{id}/reminders-send', [\App\Http\Controllers\Api\RsvpController::class, 'sendReminderNow'])->where('id', '[0-9]+')->middleware('throttle:10,1,rsvprs');
        Route::post  ('/admin/rsvp/events/{id}/reminders-preview', [\App\Http\Controllers\Api\RsvpController::class, 'previewReminder'])->where('id', '[0-9]+');
        Route::post  ('/admin/rsvp/events/{id}/reminders-test', [\App\Http\Controllers\Api\RsvpController::class, 'testReminder'])->where('id', '[0-9]+')->middleware('throttle:10,1,rsvprt');
        Route::get   ('/admin/rsvp/events/{id}/report', [\App\Http\Controllers\Api\RsvpController::class, 'report'])->where('id', '[0-9]+');
        Route::get   ('/admin/rsvp/events/{id}/report.csv', [\App\Http\Controllers\Api\RsvpController::class, 'reportCsv'])->where('id', '[0-9]+');
        Route::delete('/admin/rsvp/invites/{id}', [\App\Http\Controllers\Api\RsvpController::class, 'removeInvite'])->where('id', '[0-9]+');
        // Donations & fundraising (2026-09-29)
        Route::get ('/admin/donations/settings', [\App\Http\Controllers\Api\DonationController::class, 'showSettings']);
        Route::post('/admin/donations/settings', [\App\Http\Controllers\Api\DonationController::class, 'saveSettings']);
        Route::get ('/admin/donations/campaigns', [\App\Http\Controllers\Api\DonationController::class, 'campaigns']);
        Route::post('/admin/donations/campaigns', [\App\Http\Controllers\Api\DonationController::class, 'saveCampaign']);
        Route::post('/admin/donations/campaigns/{id}', [\App\Http\Controllers\Api\DonationController::class, 'saveCampaign'])->where('id', '[0-9]+');
        Route::get ('/admin/donations', [\App\Http\Controllers\Api\DonationController::class, 'index']);
        Route::post('/admin/donations', [\App\Http\Controllers\Api\DonationController::class, 'store']);
        Route::post('/admin/donations/{id}', [\App\Http\Controllers\Api\DonationController::class, 'update'])->where('id', '[0-9]+');
        Route::post('/admin/donations/{id}/receipt', [\App\Http\Controllers\Api\DonationController::class, 'issueReceipt'])->where('id', '[0-9]+');
        Route::get ('/admin/donations/export.csv', [\App\Http\Controllers\Api\DonationController::class, 'export']);
        Route::post('/admin/donation-receipts/{id}/void', [\App\Http\Controllers\Api\DonationController::class, 'voidReceipt'])->where('id', '[0-9]+');
        Route::post('/admin/donation-receipts/{id}/email', [\App\Http\Controllers\Api\DonationController::class, 'emailReceipt'])->where('id', '[0-9]+');
        Route::get ('/admin/donation-receipts/{id}/pdf', [\App\Http\Controllers\Api\DonationController::class, 'receiptPdf'])->where('id', '[0-9]+');
        Route::patch('/admin/billing-config', [\App\Http\Controllers\Api\AgencyBillingConfigController::class, 'update']);
    });

    // Billing reminders — agency admins AND centre directors.
    Route::middleware('role:agency_admin,platform_admin,centre_director')->group(function () {
        /* Immunization reminders: the schedule lives in Settings -> Email, and the
           records subtab reads the agency-wide list. */
        /* Money out (a refund, or paying a home provider) and money asked for. Both
           guarded so staff can only act on somebody inside their own agency. */
        /* Correcting attendance an educator missed. Directors and admins only — the
           controller checks the role again rather than trusting the group it sits in. */
        Route::get   ('/director/attendance/day',      [\App\Http\Controllers\Api\AttendanceCorrectionController::class, 'day']);
        Route::post  ('/director/attendance/manual',   [\App\Http\Controllers\Api\AttendanceCorrectionController::class, 'store']);
        Route::delete('/director/attendance/{event}',  [\App\Http\Controllers\Api\AttendanceCorrectionController::class, 'destroy'])->whereNumber('event');

        Route::post('/director/zum/send',    [\App\Http\Controllers\Api\ZumPaymentController::class, 'sendOut']);
        Route::post('/director/zum/request', [\App\Http\Controllers\Api\ZumPaymentController::class, 'requestFromFamily']);

        Route::get ('/admin/immunization-reminders', [\App\Http\Controllers\Api\ImmunizationRemindersController::class, 'show']);
        Route::post('/admin/immunization-reminders', [\App\Http\Controllers\Api\ImmunizationRemindersController::class, 'update']);
        Route::get ('/admin/immunization-records',   [\App\Http\Controllers\Api\ImmunizationRemindersController::class, 'records']);

        Route::get('/admin/billing-reminders', [\App\Http\Controllers\Api\BillingRemindersController::class, 'show']);
        Route::get('/admin/billing-reminders/preview', [\App\Http\Controllers\Api\BillingRemindersController::class, 'preview']);
        Route::post('/admin/billing-reminders', [\App\Http\Controllers\Api\BillingRemindersController::class, 'update']);
    });

    // ---- Stripe parent-pay (family-facing) ----
    /* Zūm Rails: EFT and Interac. Cards are NOT here — they stay on Stripe, which
       tokenises in the browser, whereas Zum's Canadian card API would take a raw card
       number server-side and pull this application into PCI scope. */
    /* Zum Connect — the parent adds a card or bank profile in Zum's own SDK, so the
       card number never reaches this server. Unblocked by Zum on 2026-09-02; before
       that every attempt came back "Card Onboarding is not available". */
    Route::get ('/parent/zum/connect-token',    [\App\Http\Controllers\Api\ZumPaymentController::class, 'connectToken']);
    Route::post('/parent/zum/connect-complete', [\App\Http\Controllers\Api\ZumPaymentController::class, 'connectComplete']);
    Route::get ('/parent/zum/status',        [\App\Http\Controllers\Api\ZumPaymentController::class, 'status']);
    Route::post('/parent/zum/bank-account',  [\App\Http\Controllers\Api\ZumPaymentController::class, 'saveBankAccount']);
    Route::post('/parent/zum/pay',           [\App\Http\Controllers\Api\ZumPaymentController::class, 'pay']);
    // A family asking the centre to send them an Interac e-Transfer request (2026-10-30, EtransferRequestController).
    Route::get ('/parent/etransfer-requests',               [\App\Http\Controllers\Api\EtransferRequestController::class, 'mine']);
    Route::post('/parent/etransfer-requests',               [\App\Http\Controllers\Api\EtransferRequestController::class, 'create'])->middleware('throttle:10,1,etreq');
    Route::post('/parent/etransfer-requests/{id}/cancel',   [\App\Http\Controllers\Api\EtransferRequestController::class, 'cancelMine'])->whereNumber('id');
    Route::post('/parent/etransfer-requests/{id}/demo-pay', [\App\Http\Controllers\Api\EtransferRequestController::class, 'demoPay'])->whereNumber('id')->middleware('throttle:20,1,etreqpay');
    // "I've sent it" with the bank's reference number, for the centre to confirm (2026-11-11).
    Route::post('/parent/etransfer-reports',                [\App\Http\Controllers\Api\EtransferRequestController::class, 'report'])->middleware('throttle:10,1,etrep');
    Route::middleware('role:agency_admin,centre_director,platform_admin')->group(function () {
        Route::get ('/billing/etransfer-requests',             [\App\Http\Controllers\Api\EtransferRequestController::class, 'index']);
        Route::post('/billing/etransfer-requests/{id}/sent',   [\App\Http\Controllers\Api\EtransferRequestController::class, 'markSent'])->whereNumber('id');
        Route::post('/billing/etransfer-requests/{id}/paid',   [\App\Http\Controllers\Api\EtransferRequestController::class, 'markPaid'])->whereNumber('id');
        Route::post('/billing/etransfer-requests/{id}/cancel', [\App\Http\Controllers\Api\EtransferRequestController::class, 'cancel'])->whereNumber('id');
        // A family's reported e-Transfer: Accounting -> e-Transfers to confirm (2026-11-11).
        Route::get ('/billing/etransfer-reports',              [\App\Http\Controllers\Api\EtransferRequestController::class, 'reports']);
        Route::post('/billing/etransfer-reports/{id}/approve', [\App\Http\Controllers\Api\EtransferRequestController::class, 'approve'])->whereNumber('id');
        Route::post('/billing/etransfer-reports/{id}/reject',  [\App\Http\Controllers\Api\EtransferRequestController::class, 'reject'])->whereNumber('id');
        // Accounting's invoice row menu: "Send e-Transfer request" (2026-11-02; kind = kt | ext).
        Route::get ('/billing/etransfer/{kind}/{id}',           [\App\Http\Controllers\Api\EtransferRequestController::class, 'forInvoice'])->whereIn('kind', ['kt', 'ext'])->whereNumber('id');
        Route::post('/billing/etransfer/{kind}/{id}/requested', [\App\Http\Controllers\Api\EtransferRequestController::class, 'markInvoiceRequested'])->whereIn('kind', ['kt', 'ext'])->whereNumber('id');
    });
    // The website demo's simulated payment - public demo parents only, no money moves (App\Support\WebDemoPayments, 2026-10-30).
    Route::post('/parent/demo-pay',          [\App\Http\Controllers\Api\DemoPayController::class, 'pay'])->middleware('throttle:20,1,demopay');

    /* HELCIM - card payment on an invoice, through their hosted iframe.

       Two calls on purpose. checkout() opens the session and keeps the secretToken here;
       complete() is the ONLY place a payment row is written, and it believes the hash
       rather than the browser. Nothing the page says about an amount is trusted. */
    Route::post('/parent/helcim/checkout', [\App\Http\Controllers\Api\HelcimController::class, 'checkout']);
    Route::post('/parent/helcim/complete', [\App\Http\Controllers\Api\HelcimController::class, 'complete']);
    /* APPLE PAY + GOOGLE PAY on invoices (2026-11-03): the agency's own Stripe (browser Express Checkout or the
       app's native sheet). Amount from the invoice; recorded only once Stripe says succeeded. */
    Route::get ('/parent/wallet-pay/options',        [\App\Http\Controllers\Api\WalletPayController::class, 'options']);
    Route::post('/parent/wallet-pay/stripe-intent',  [\App\Http\Controllers\Api\WalletPayController::class, 'stripeIntent'])->middleware('throttle:20,1,r-wallet-intent');
    Route::post('/parent/wallet-pay/stripe-confirm', [\App\Http\Controllers\Api\WalletPayController::class, 'stripeConfirm'])->middleware('throttle:30,1,r-wallet-confirm');
    // Test agency wallet demo (2026-11-04): simulated, no processor, undone nightly.
    Route::post('/parent/wallet-pay/demo',           [\App\Http\Controllers\Api\WalletPayController::class, 'demoPay'])->middleware('throttle:20,1,r-wallet-demo');

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
        // SMS -> Replies: texts in to the agency's number, the thread, read, reply (2026-11-02, App\Support\SmsReplies).
        Route::get ('/admin/sms/replies',              [\App\Http\Controllers\Api\SmsRepliesController::class, 'index']);
        Route::get ('/admin/sms/replies/{id}/thread',  [\App\Http\Controllers\Api\SmsRepliesController::class, 'thread'])->whereNumber('id');
        Route::post('/admin/sms/replies/{id}/read',    [\App\Http\Controllers\Api\SmsRepliesController::class, 'markRead'])->whereNumber('id');
        Route::post('/admin/sms/replies/{id}/reply',   [\App\Http\Controllers\Api\SmsRepliesController::class, 'reply'])->whereNumber('id')->middleware('throttle:30,1,sms-reply');
        // The audit log's SMS subtab: both directions, filters, errors in plain words (2026-10-19).
        Route::get ('/admin/sms/log',       [\App\Http\Controllers\Api\SmsController::class, 'logIndex']);
        /* Who a broadcast would reach, before anyone presses send. Read-only. */
        /* LETTING SOMEBODY BACK IN (2026-09-21).

           Anthony: "add mechanism for admins to unlock a user if they locked themselves
           out (and reduce the 15 mins lock out)."

           The hold is five minutes and clears itself, so this is for the case where five
           minutes is five minutes too long - an educator at the door with children
           arriving. Lifting it is itself audited, because "who let this account back in,
           and when" is exactly what an auditor asks about a security control that can be
           switched off.

           Admin-gated: an /admin/ prefix says where a thing is EDITED, not who may use
           it, so the role check is explicit rather than inherited from the path. */
        Route::get ('/admin/security/locked-accounts', [\App\Http\Controllers\Api\SecurityLockController::class, 'index']);
        Route::post('/admin/users/{user}/unlock-login', [\App\Http\Controllers\Api\SecurityLockController::class, 'unlock'])->whereNumber('user');
        Route::get ('/admin/sms/audience', [\App\Http\Controllers\Api\SmsController::class, 'audience']);
        /* Text-alert consent: see who has it, ask the ones who do not, and record a yes
           given in person. There is deliberately no bulk "turn everyone on". */
        Route::get ('/admin/sms/consent-coverage', [\App\Http\Controllers\Api\SmsConsentInviteController::class, 'coverage']);
        Route::post('/admin/sms/consent-invites',  [\App\Http\Controllers\Api\SmsConsentInviteController::class, 'invite']);
        Route::post('/admin/sms/consent/{user}',   [\App\Http\Controllers\Api\SmsConsentInviteController::class, 'record'])->whereNumber('user');
    });
    /* TEXT REPLIES IN MESSAGE CENTRE (2026-11-02, Anthony: "Safia should see this response to her announcement in the
       message centre"). Any staff member: the replies to texts THEY sent; admins / directors: the agency's (scoped).
       No role middleware on purpose - App\Support\SmsReplies::visible() decides, and fails closed. */
    Route::get ('/sms-replies',             [\App\Http\Controllers\Api\SmsRepliesController::class, 'index']);
    Route::get ('/sms-replies/{id}/thread', [\App\Http\Controllers\Api\SmsRepliesController::class, 'thread'])->whereNumber('id');
    Route::post('/sms-replies/{id}/read',   [\App\Http\Controllers\Api\SmsRepliesController::class, 'markRead'])->whereNumber('id');
    Route::post('/sms-replies/{id}/reply',  [\App\Http\Controllers\Api\SmsRepliesController::class, 'reply'])->whereNumber('id')->middleware('throttle:30,1,sms-reply');

    /* ---- VOICE ANNOUNCEMENTS (2026-09-10) ----
       Gated exactly like the SMS broadcast beside it: a director can send one, because a
       closure or an evacuation is a site-level decision made by whoever is standing in the
       building. The CREDENTIALS behind it stay agency-admin-only on the settings screen,
       and the master switch is off until somebody turns it on — running a site is not the
       same as deciding the agency starts telephoning people.

       test-call rings the caller's OWN number and nobody else's; see the controller. */
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::post('/admin/voice/announce',  [\App\Http\Controllers\Api\VoiceController::class, 'announce']);
        Route::post('/admin/voice/test-call', [\App\Http\Controllers\Api\VoiceController::class, 'testCall'])->middleware('throttle:5,1,vxtest'); // rings a real phone
        Route::get ('/admin/voice/calls',     [\App\Http\Controllers\Api\VoiceController::class, 'calls']);
        // The audit log's Voice calls subtab (2026-10-19).
        Route::get ('/admin/voice/log',       [\App\Http\Controllers\Api\VoiceController::class, 'logIndex']);
        Route::get ('/admin/voice/voices',    [\App\Http\Controllers\Api\VoiceController::class, 'voices']);
        Route::get ('/admin/voice/categories', [\App\Http\Controllers\Api\VoiceController::class, 'categories']);
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
        // Rebuilt 2026-10-09 (see QboController): mapping options, Sync now, sync log.
        Route::get ('/qbo/options',                [\App\Http\Controllers\Api\QboController::class, 'options']);
        Route::post('/qbo/sync',                   [\App\Http\Controllers\Api\QboController::class, 'syncAll']);
        Route::get ('/qbo/log',                    [\App\Http\Controllers\Api\QboController::class, 'log']);
    });

    // ---- Xero (2026-10-09) — Settings → Xero; agency admins of the active agency (checked in the controller too) ----
    Route::middleware('role:agency_admin,platform_admin')->group(function () {
        Route::get   ('/admin/xero-config',        [\App\Http\Controllers\Api\XeroController::class, 'showConfig']);
        Route::patch ('/admin/xero-config',        [\App\Http\Controllers\Api\XeroController::class, 'saveConfig']);
        Route::delete('/admin/xero-config',        [\App\Http\Controllers\Api\XeroController::class, 'clearConfig']);
        Route::get   ('/xero/connect',             [\App\Http\Controllers\Api\XeroController::class, 'connect']);
        Route::post  ('/xero/tenant',              [\App\Http\Controllers\Api\XeroController::class, 'chooseTenant']);
        Route::post  ('/xero/disconnect',          [\App\Http\Controllers\Api\XeroController::class, 'disconnect']);
        Route::get   ('/xero/options',             [\App\Http\Controllers\Api\XeroController::class, 'options']);
        Route::post  ('/xero/sync',                [\App\Http\Controllers\Api\XeroController::class, 'syncAll']);
        Route::post  ('/xero/sync/invoice/{id}',   [\App\Http\Controllers\Api\XeroController::class, 'syncInvoice'])->where('id', '[0-9]+');
        Route::get   ('/xero/log',                 [\App\Http\Controllers\Api\XeroController::class, 'log']);
    });

    // ---- Sage (2026-10-09) — Settings → Sage: Sage Accounting (cloud) sync + Sage 50 (desktop) files ----
    Route::middleware('role:agency_admin,platform_admin')->group(function () {
        Route::get   ('/admin/sage-config',          [\App\Http\Controllers\Api\SageController::class, 'showConfig']);
        Route::patch ('/admin/sage-config',          [\App\Http\Controllers\Api\SageController::class, 'saveConfig']);
        Route::delete('/admin/sage-config',          [\App\Http\Controllers\Api\SageController::class, 'clearConfig']);
        Route::get   ('/sage/connect',               [\App\Http\Controllers\Api\SageController::class, 'connect']);
        Route::post  ('/sage/business',              [\App\Http\Controllers\Api\SageController::class, 'chooseBusiness']);
        Route::post  ('/sage/disconnect',            [\App\Http\Controllers\Api\SageController::class, 'disconnect']);
        Route::get   ('/sage/options',               [\App\Http\Controllers\Api\SageController::class, 'options']);
        Route::post  ('/sage/sync',                  [\App\Http\Controllers\Api\SageController::class, 'syncAll']);
        Route::post  ('/sage/sync/invoice/{id}',     [\App\Http\Controllers\Api\SageController::class, 'syncInvoice'])->where('id', '[0-9]+');
        Route::get   ('/sage/log',                   [\App\Http\Controllers\Api\SageController::class, 'log']);
        Route::get   ('/sage50/preview',             [\App\Http\Controllers\Api\SageController::class, 's50Preview']);
        Route::post  ('/sage50/export',              [\App\Http\Controllers\Api\SageController::class, 's50Export']);
        Route::get   ('/sage50/exports',             [\App\Http\Controllers\Api\SageController::class, 's50Exports']);
        Route::get   ('/sage50/exports/{id}',        [\App\Http\Controllers\Api\SageController::class, 's50Download'])->where('id', '[0-9]+');
        Route::get   ('/sage50/customers.csv',       [\App\Http\Controllers\Api\SageController::class, 's50Customers']);
    });

    // ---- Locale ----
    Route::get ('/locale',                  [\App\Http\Controllers\Api\LocaleController::class, 'current']);
    Route::post('/locale',                  [\App\Http\Controllers\Api\LocaleController::class, 'set']);
    Route::get ('/locale/strings/{lang}',   [\App\Http\Controllers\Api\LocaleController::class, 'strings']);

    // Weekly menu — editable by educators too (each request is tenant-scoped by
    // OperationsController::assertCentreAccess, which already allows educators for
    // a centre in their agency). The rest of operations stays director/admin-only.
    Route::middleware('role:educator,centre_director,agency_admin,platform_admin')->group(function () {
        Route::get  ('/operations/my-centres',        [\App\Http\Controllers\Api\OperationsController::class, 'myCentres']);
        Route::get  ('/operations/menu',              [\App\Http\Controllers\Api\OperationsController::class, 'getMenuWeek']);
        Route::post ('/operations/menu',              [\App\Http\Controllers\Api\OperationsController::class, 'saveMenuWeek']);
        // Food photos on the menu (2026-10-31, App\Support\FoodPhotos): the shared library, local matching,
        // an agency's own photo, and the "Add food photos automatically" setting (directors/admins).
        Route::get  ('/operations/food-images',       [\App\Http\Controllers\Api\FoodPhotoController::class, 'index']);
        Route::post ('/operations/food-images/match', [\App\Http\Controllers\Api\FoodPhotoController::class, 'match'])->middleware('throttle:60,1,foodmatch');
        Route::post ('/operations/menu/photo',        [\App\Http\Controllers\Api\FoodPhotoController::class, 'upload'])->middleware('throttle:30,1,foodupload');
        Route::put  ('/operations/menu/photo-settings', [\App\Http\Controllers\Api\FoodPhotoController::class, 'settings']);
        // Reusable menu weeks (2026-11-03, MenuTemplateController): save a week, apply it to centres in your scope (drafts).
        Route::get   ('/operations/menu/templates',            [\App\Http\Controllers\Api\MenuTemplateController::class, 'index']);
        Route::post  ('/operations/menu/templates',            [\App\Http\Controllers\Api\MenuTemplateController::class, 'store']);
        Route::post  ('/operations/menu/templates/{id}/apply', [\App\Http\Controllers\Api\MenuTemplateController::class, 'apply'])->whereNumber('id');
        Route::delete('/operations/menu/templates/{id}',       [\App\Http\Controllers\Api\MenuTemplateController::class, 'destroy'])->whereNumber('id');
        // Educators also see allergy/dietary reminders for children at their
        // centre (tenant-scoped by assertCentreAccess + active-agency filter).
        Route::get  ('/operations/allergy-alerts',    [\App\Http\Controllers\Api\OperationsController::class, 'allergyAlerts']);
    });

    // Parents see the PUBLISHED menu for their child's centre (read-only).
    Route::middleware('role:guardian')->group(function () {
        Route::get('/parent/menu', [\App\Http\Controllers\Api\OperationsController::class, 'parentMenu']);

        // What a parent tells us about their own child at onboarding: a photo, what
        // they are allergic to, and their immunizations. Every handler proves the
        // child belongs to the signed-in guardian before reading or writing.
        Route::get ('/parent/onboarding/children', [\App\Http\Controllers\Api\ParentChildOnboardingController::class, 'index']);
        // Children added after the parent onboarded and still without a photo (sign-in catch-up).
        Route::get ('/parent/onboarding/catch-up', [\App\Http\Controllers\Api\ParentChildOnboardingController::class, 'catchUp']);
        Route::post('/parent/onboarding/children/{child}', [\App\Http\Controllers\Api\ParentChildOnboardingController::class, 'save']);
        Route::post('/parent/onboarding/children/{child}/photo', [\App\Http\Controllers\Api\ParentChildOnboardingController::class, 'photo']);
    });

    // Operations: meals, allergies, field trips, substitutes, inspection
    // (allergy-alerts moved above into the educator-inclusive group.)
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::get  ('/operations/field-trips',       [\App\Http\Controllers\Api\OperationsController::class, 'listFieldTrips']);
        Route::post ('/operations/field-trips',       [\App\Http\Controllers\Api\OperationsController::class, 'createFieldTrip']);
        Route::delete('/operations/field-trips/{id}', [\App\Http\Controllers\Api\OperationsController::class, 'deleteFieldTrip']);
        Route::get  ('/operations/field-trips/{id}',  [\App\Http\Controllers\Api\OperationsController::class, 'showFieldTrip'])->where('id','[0-9]+');   // trip details view (2026-10-24)
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
    // Authenticated media proxy → lets the app download cross-origin photos (which
    // fetch() can't read for CORS reasons) as an attachment, like the payslip PDF.
    Route::get ('/media/download',     [\App\Http\Controllers\Api\MediaController::class, 'download']);
    Route::post('/photos',             [\App\Http\Controllers\Api\PhotoFeedController::class, 'upload']);
    // Change who is tagged in a shared photo (2026-10-20).
    Route::patch('/photos/{id}/children', [\App\Http\Controllers\Api\PhotoFeedController::class, 'tagChildren'])->whereNumber('id');
    /* PHOTO PERMISSIONS, PER CHILD (2026-11-06) - App\Support\MediaConsent. Every agency, no switch: a parent answers
       for their own children; staff read the answers, admins and directors record a signed paper form. */
    Route::get ('/me/photo-consent', [\App\Http\Controllers\Api\MediaConsentController::class, 'mine']);
    Route::post('/me/photo-consent', [\App\Http\Controllers\Api\MediaConsentController::class, 'saveMine'])->middleware('throttle:30,1,photoconsent');
    /* PRIVACY NOTICE, ACKNOWLEDGED (2026-11-10, PIPEDA review) - PrivacyNoticeController. A parent confirms reading each
       of their agencies' notice; admins see who has and can ask again. */
    Route::get ('/me/privacy-notice', [\App\Http\Controllers\Api\PrivacyNoticeController::class, 'mine']);
    Route::post('/me/privacy-notice', [\App\Http\Controllers\Api\PrivacyNoticeController::class, 'acknowledge'])->middleware('throttle:30,1,privacynotice');
    // Who of these people is sign-in locked - the status pills (directors too: they see these people already).
    Route::get('/admin/sign-in-locks/people', [\App\Http\Controllers\Api\SignInLockController::class, 'lockedPeople'])
        ->middleware('role:agency_admin,platform_admin,centre_director');
    /* SIGN-IN LOCKS (2026-11-10) - SignInLockController: pause sign-in for a person, a centre / home provider, or (platform
       only) a whole agency, and lift it. Not plan-gated: an account-safety control. */
    Route::middleware('role:agency_admin,platform_admin')->group(function () {
        Route::get ('/admin/sign-in-locks',               [\App\Http\Controllers\Api\SignInLockController::class, 'index']);
        Route::get ('/admin/sign-in-locks/targets',       [\App\Http\Controllers\Api\SignInLockController::class, 'targets']);
        Route::get ('/admin/sign-in-locks/preview',       [\App\Http\Controllers\Api\SignInLockController::class, 'preview']);
        Route::get ('/admin/sign-in-locks/status',        [\App\Http\Controllers\Api\SignInLockController::class, 'status']);
        Route::get ('/admin/sign-in-locks/{lock}',        [\App\Http\Controllers\Api\SignInLockController::class, 'show'])->where('lock', '[0-9]+');
        Route::post('/admin/sign-in-locks',               [\App\Http\Controllers\Api\SignInLockController::class, 'store'])->middleware('throttle:30,1,signinlock');
        Route::post('/admin/sign-in-locks/{lock}/unlock', [\App\Http\Controllers\Api\SignInLockController::class, 'unlock'])->where('lock', '[0-9]+')->middleware('throttle:30,1,signinlock');
        // One person let in past a centre lock, and ended (2026-11-10).
        Route::post('/admin/sign-in-locks/{lock}/exceptions',        [\App\Http\Controllers\Api\SignInLockController::class, 'except'])->where('lock', '[0-9]+')->middleware('throttle:30,1,signinlock');
        Route::post('/admin/sign-in-locks/exceptions/{exception}/end', [\App\Http\Controllers\Api\SignInLockController::class, 'endException'])->where('exception', '[0-9]+')->middleware('throttle:30,1,signinlock');
    });
    Route::middleware('role:agency_admin,platform_admin')->group(function () {
        Route::get ('/admin/privacy-notice',       [\App\Http\Controllers\Api\PrivacyNoticeController::class, 'status']);
        Route::post('/admin/privacy-notice/reask', [\App\Http\Controllers\Api\PrivacyNoticeController::class, 'reask']);
    });
    /* SAVED SIGNATURE + INITIALS (2026-11-06) - SavedSignatureController. Personal: a person's own, never another's;
       applied to a document only on a tap (every apply is a recorded use). Viewing-as gets none. */
    Route::get ('/me/saved-signatures', [\App\Http\Controllers\Api\SavedSignatureController::class, 'mine']);
    Route::post('/me/saved-signatures', [\App\Http\Controllers\Api\SavedSignatureController::class, 'save'])->middleware('throttle:20,1,savedsig');
    Route::delete('/me/saved-signatures/{kind}', [\App\Http\Controllers\Api\SavedSignatureController::class, 'remove'])->where('kind', 'signature|initials');
    Route::post('/me/saved-signatures/{kind}/use', [\App\Http\Controllers\Api\SavedSignatureController::class, 'apply'])->where('kind', 'signature|initials')->middleware('throttle:60,1,savedsiguse');
    Route::middleware('role:agency_admin,platform_admin,centre_director,educator')->group(function () {
        Route::get ('/admin/photo-consent', [\App\Http\Controllers\Api\MediaConsentController::class, 'index']);
        Route::post('/admin/photo-consent/{child}', [\App\Http\Controllers\Api\MediaConsentController::class, 'record'])->whereNumber('child');
        Route::post('/admin/photo-consent/{child}/show-photos', [\App\Http\Controllers\Api\MediaConsentController::class, 'showPhotos'])->whereNumber('child');
    });
    /* An educator sees only feedback RELEASED to them; a director releases the rest. */
    Route::get ('/feedback/mine-educator', [\App\Http\Controllers\Api\ParentFeedbackController::class, 'mine']);
    Route::post('/feedback/mine-educator/read', [\App\Http\Controllers\Api\ParentFeedbackController::class, 'markRead']);
    Route::post('/feedback/{id}/release', [\App\Http\Controllers\Api\ParentFeedbackController::class, 'release'])->whereNumber('id');
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
        // A holiday across several providers at once. Same rows, same announcements as
        // the single add above — a holiday IS a closure, so nothing downstream changes.
        Route::get   ('/operations/closure-targets',   [\App\Http\Controllers\Api\OperationsV2Controller::class, 'closureTargets']);
        Route::post  ('/operations/closures/bulk',     [\App\Http\Controllers\Api\OperationsV2Controller::class, 'addClosureBulk']);
        Route::patch ('/operations/closures/{id}',     [\App\Http\Controllers\Api\OperationsV2Controller::class, 'updateClosure']);
        Route::delete('/operations/closures/{id}',     [\App\Http\Controllers\Api\OperationsV2Controller::class, 'removeClosure']);
        });
        // Late pickups are also logged/viewed by EDUCATORS (on the floor when a parent runs late).
        Route::middleware('role:educator,centre_director,agency_admin,platform_admin')->group(function () {
        Route::post  ('/operations/late-pickup',       [\App\Http\Controllers\Api\OperationsV2Controller::class, 'logLatePickup']);
        Route::get   ('/operations/late-pickups',      [\App\Http\Controllers\Api\OperationsV2Controller::class, 'lateHistory']);
        Route::get   ('/operations/late-pickup-options', [\App\Http\Controllers\Api\OperationsV2Controller::class, 'latePickupOptions']);
        });
        Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::get   ('/operations/ratio-status',      [\App\Http\Controllers\Api\OperationsV2Controller::class, 'ratioStatus']);
        Route::put   ('/operations/ratio-alerts',      [\App\Http\Controllers\Api\OperationsV2Controller::class, 'saveRatioAlerts']);   // breach alerts: who is told (2026-10-30)
        Route::put   ('/operations/ratio-settings/{centre}', [\App\Http\Controllers\Api\OperationsV2Controller::class, 'saveRatioSettings'])->where('centre', '[0-9]+');   // Ontario ratio settings (2026-10-13)
        Route::get   ('/operations/bus-routes',        [\App\Http\Controllers\Api\OperationsV2Controller::class, 'busRoutes']);
        Route::post  ('/operations/bus-routes',        [\App\Http\Controllers\Api\OperationsV2Controller::class, 'createBusRoute']);
        Route::get   ('/operations/bus-routes/{id}/children', [\App\Http\Controllers\Api\OperationsV2Controller::class, 'routeChildren']);
        Route::post  ('/operations/bus-routes/{id}/children', [\App\Http\Controllers\Api\OperationsV2Controller::class, 'addChildToRoute']);
        // Edit / pause / delete a route, and take a child off one (2026-10-16).
        Route::patch ('/operations/bus-routes/{id}',   [\App\Http\Controllers\Api\OperationsV2Controller::class, 'updateBusRoute'])->whereNumber('id');
        Route::delete('/operations/bus-routes/{id}',   [\App\Http\Controllers\Api\OperationsV2Controller::class, 'deleteBusRoute'])->whereNumber('id');
        Route::delete('/operations/bus-routes/{id}/children/{child}', [\App\Http\Controllers\Api\OperationsV2Controller::class, 'removeChildFromRoute'])->whereNumber('id')->whereNumber('child');
        Route::get   ('/operations/rotation-candidates', [\App\Http\Controllers\Api\OperationsV2Controller::class, 'rotationCandidates']);
        Route::post  ('/operations/room-rotations',    [\App\Http\Controllers\Api\OperationsV2Controller::class, 'planRotation']);
        Route::get   ('/billing/vacation-holds',       [\App\Http\Controllers\Api\BillingV2Controller::class, 'listHolds']);
        Route::post  ('/billing/vacation-holds/for-family', [\App\Http\Controllers\Api\BillingV2Controller::class, 'createForFamily']);
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
    Route::get ('/engagement/signed-documents/{id}/download', [\App\Http\Controllers\Api\EngagementController::class, 'downloadSignedDoc']);

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
    // Ontario curriculum library units (2026-11-03, CurriculumUnitController). Browse, preview and use:
    // anyone who plans lessons (Use = the planner's own room/centre check, drafts only). Copy, edit and
    // delete the agency's copy: directors and admins, checked in the controller.
    Route::middleware(KT_PROVIDER_ROLES)->group(function () {
        Route::get   ('/curriculum/units',           [\App\Http\Controllers\Api\CurriculumUnitController::class, 'index']);
        Route::get   ('/curriculum/units/{id}',      [\App\Http\Controllers\Api\CurriculumUnitController::class, 'show'])->whereNumber('id');
        Route::post  ('/curriculum/units/{id}/use',  [\App\Http\Controllers\Api\CurriculumUnitController::class, 'use'])->whereNumber('id')->middleware('throttle:30,1,curunituse');
        Route::post  ('/curriculum/units/{id}/copy', [\App\Http\Controllers\Api\CurriculumUnitController::class, 'copy'])->whereNumber('id');
        Route::put   ('/curriculum/units/{id}',      [\App\Http\Controllers\Api\CurriculumUnitController::class, 'update'])->whereNumber('id');
        Route::delete('/curriculum/units/{id}',      [\App\Http\Controllers\Api\CurriculumUnitController::class, 'destroy'])->whereNumber('id');
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
        /* Refund by INVOICE rather than by receipt. Money that arrived through the
           integration has no payment row until one is refunded, so the invoice is the
           only handle a person has on it. Both are admin/director-gated inside the
           controller. */
        Route::get ('/refunds/refundable',           [\App\Http\Controllers\Api\RefundsController::class, 'refundableInvoices']);
        Route::get ('/refunds/lookup',               [\App\Http\Controllers\Api\RefundsController::class, 'lookupInvoice']);
        Route::post('/refunds/invoice',              [\App\Http\Controllers\Api\RefundsController::class, 'createForInvoice']);
    });
    // Ledger
    Route::get('/parent/ledger',                    [\App\Http\Controllers\Api\LedgerController::class, 'myLedger']);
    Route::get('/parent/ledger/pdf',                [\App\Http\Controllers\Api\LedgerController::class, 'familyLedgerPdf']);
    // #34 — per-invoice actions from the parent ledger kebab (download / email-to-self).
    Route::get ('/parent/invoices/{invoice}/pdf',   [\App\Http\Controllers\Api\LedgerController::class, 'myInvoicePdf']);
    // The same document, inline, so the app can SHOW a parent their invoice.
    Route::get ('/parent/invoices/{invoice}/document', [\App\Http\Controllers\Api\LedgerController::class, 'myInvoiceDocument']);
    Route::post('/parent/invoices/{invoice}/email', [\App\Http\Controllers\Api\LedgerController::class, 'emailMyInvoice']);
    Route::middleware('role:agency_admin,centre_director,platform_admin')->group(function () {
        Route::get('/families/{familyId}/ledger',        [\App\Http\Controllers\Api\LedgerController::class, 'familyLedger']);
        Route::get('/families/{familyId}/ledger/pdf',    [\App\Http\Controllers\Api\LedgerController::class, 'familyLedgerPdf']);
    });
    // Reports
    // ⚠ 2026-08-30 — ALL of these were auth-only: no role middleware at all, and
    // ReportsController does no role check of its own (resolveAgencyId scopes to the
    // caller's AGENCY, and stops there). Any authenticated token — an educator's, a
    // parent's — could therefore pull the agency-wide attendance log, staff hours,
    // payments, invoices and family directory. The UI hid the screen; the API did not.
    //
    // Educators are inside the canned group ON PURPOSE: the reports screen is now
    // theirs too, but ReportsController::educatorScope() cuts what they can ask for
    // down to their own rooms and their own clock-ins. Guardians are out entirely.
    Route::middleware('role:educator,centre_director,agency_admin,platform_admin,auditor')->group(function () {
        Route::get   ('/reports/canned',      [\App\Http\Controllers\Api\ReportsController::class, 'cannedList']);
        Route::get   ('/reports/canned/run',  [\App\Http\Controllers\Api\ReportsController::class, 'canned']);
        Route::get   ('/reports/canned/pdf',  [\App\Http\Controllers\Api\ReportsController::class, 'cannedPdf']);
        Route::get   ('/reports/canned/xlsx', [\App\Http\Controllers\Api\ReportsController::class, 'cannedXlsx']);
    });
    // The saved custom-report builder stays admin-only. Unlike the canned set it
    // takes caller-supplied tables, columns and filters, so there is no subset of it
    // that could be handed to an educator and still be safe.
    Route::middleware('role:centre_director,agency_admin,platform_admin,auditor')->group(function () {
        Route::get   ('/reports',                       [\App\Http\Controllers\Api\ReportsController::class, 'listMine']);
        Route::post  ('/reports',                       [\App\Http\Controllers\Api\ReportsController::class, 'create']);
        Route::patch ('/reports/{id}',                  [\App\Http\Controllers\Api\ReportsController::class, 'update']);
        Route::delete('/reports/{id}',                  [\App\Http\Controllers\Api\ReportsController::class, 'destroy']);
        Route::get   ('/reports/{id}/run',              [\App\Http\Controllers\Api\ReportsController::class, 'run']);
    });
    // Scheduled reports — email a canned report on a cadence (PDF/CSV).
    // The four write routes below were already gated; only this read was not, so the
    // list of what every centre has scheduled, and to whom it is emailed, was open.
    Route::get   ('/admin/report-schedules',            [\App\Http\Controllers\Api\ScheduledReportController::class, 'index'])->middleware('role:agency_admin,centre_director,platform_admin');
    Route::post  ('/admin/report-schedules',            [\App\Http\Controllers\Api\ScheduledReportController::class, 'store'])->middleware('role:agency_admin,centre_director,platform_admin');
    Route::patch ('/admin/report-schedules/{id}',       [\App\Http\Controllers\Api\ScheduledReportController::class, 'update'])->middleware('role:agency_admin,centre_director,platform_admin');
    Route::delete('/admin/report-schedules/{id}',       [\App\Http\Controllers\Api\ScheduledReportController::class, 'destroy'])->middleware('role:agency_admin,centre_director,platform_admin');
    Route::post  ('/admin/report-schedules/{id}/run',   [\App\Http\Controllers\Api\ScheduledReportController::class, 'runNow'])->middleware('role:agency_admin,centre_director,platform_admin');
    Route::post  ('/reports/preview',               [\App\Http\Controllers\Api\ReportsController::class, 'preview'])->middleware('role:centre_director,agency_admin,platform_admin,auditor');
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
        // Replace the schedule with a standard one: Canada (NACI) or US (ACIP, covers Ohio) — 2026-10-09.
        Route::post  ('/immunization/schedule/preset',    [\App\Http\Controllers\Api\ImmunizationScheduleController::class, 'loadPreset']);
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
    // Walks & outings — educator-started live-GPS trips (parents of present children can watch)
    Route::middleware('role:educator,home_visitor,centre_director,agency_admin,platform_admin')->group(function () {
        Route::get ('/provider/walks/active',      [\App\Http\Controllers\Api\WalkController::class, 'active']);
        // A whole day of walks across the centres you oversee — who is out, with
        // which educator, where they set off from and where they are now.
        Route::get ('/provider/walks/tracker',       [\App\Http\Controllers\Api\WalkController::class, 'tracker']);
        Route::get ('/provider/walks/tracker-dates', [\App\Http\Controllers\Api\WalkController::class, 'trackerDates']);
        /* Where home is, so the phone can notice it has left. The device compares its
           own position; it never sends one back. */
        Route::get ('/provider/walks/geofence',    [\App\Http\Controllers\Api\WalkController::class, 'geofence']);
        Route::get ('/provider/walks/eligible-children', [\App\Http\Controllers\Api\WalkController::class, 'eligibleChildren']);
        Route::post('/provider/walks/start',       [\App\Http\Controllers\Api\WalkController::class, 'start']);
        Route::post('/provider/walks/{id}/end',    [\App\Http\Controllers\Api\WalkController::class, 'end']);
    });
    Route::middleware('role:guardian')->group(function () {
        Route::get('/parent/active-walks',         [\App\Http\Controllers\Api\WalkController::class, 'parentActiveWalks']);
        Route::get('/parent/walks',                [\App\Http\Controllers\Api\WalkController::class, 'parentWalks']);
    });
    // Attendance pattern
    Route::get ('/attendance/pattern/{childId}',  [\App\Http\Controllers\Api\AttendancePatternController::class, 'get']);
    Route::post('/attendance/pattern/{childId}',  [\App\Http\Controllers\Api\AttendancePatternController::class, 'set']);
    Route::middleware('role:educator,centre_director,agency_admin,platform_admin')->group(function () {
        Route::get('/attendance/weekly-overview', [\App\Http\Controllers\Api\AttendancePatternController::class, 'weeklyOverview']);
    });
    // Report cards
    Route::middleware('role:educator,centre_director,agency_admin,platform_admin')->group(function () {
        Route::get ('/report-cards/pending',         [\App\Http\Controllers\Api\ReportCardController::class, 'pending']);
        Route::get ('/report-cards/requests',        [\App\Http\Controllers\Api\ReportCardController::class, 'requests']);
        Route::get ('/report-cards/child/{childId}', [\App\Http\Controllers\Api\ReportCardController::class, 'listForChild']);
        Route::post('/report-cards/generate',        [\App\Http\Controllers\Api\ReportCardController::class, 'generate']);
        Route::patch('/report-cards/{id}',           [\App\Http\Controllers\Api\ReportCardController::class, 'update']);
        Route::post('/report-cards/{id}/send',       [\App\Http\Controllers\Api\ReportCardController::class, 'send']);
        Route::post('/report-cards/{id}/submit',      [\App\Http\Controllers\Api\ReportCardController::class, 'submit']);
        Route::post('/report-cards/{id}/approve',     [\App\Http\Controllers\Api\ReportCardController::class, 'approve']);
        Route::post('/report-cards/{id}/reject',      [\App\Http\Controllers\Api\ReportCardController::class, 'reject']);
    });
    Route::get('/report-cards/{id}/pdf',          [\App\Http\Controllers\Api\ReportCardController::class, 'pdf']);
    // Activity zones
    Route::get ('/zones',                         [\App\Http\Controllers\Api\ZonesController::class, 'listZones']);
    Route::middleware('role:educator,centre_director,agency_admin,platform_admin')->group(function () {
        Route::post('/zones',                     [\App\Http\Controllers\Api\ZonesController::class, 'createZone']);
        Route::post('/zones/visit',               [\App\Http\Controllers\Api\ZonesController::class, 'logVisit']);
        Route::get ('/zones/daily-report',        [\App\Http\Controllers\Api\ZonesController::class, 'dailyReport']);
        Route::get ('/zones/options',             [\App\Http\Controllers\Api\ZonesController::class, 'options']);
    });
    // Support tickets
    Route::get ('/tickets',                       [\App\Http\Controllers\Api\SupportTicketController::class, 'listMine']);
    // Volumes, response/resolution times against the SLA, breaches, ageing and trends for the tickets screen (2026-10-28).
    Route::get ('/tickets/stats',                 [\App\Http\Controllers\Api\SupportTicketController::class, 'stats']);
    Route::post('/tickets',                       [\App\Http\Controllers\Api\SupportTicketController::class, 'create']);
    Route::get ('/tickets/{id}',                  [\App\Http\Controllers\Api\SupportTicketController::class, 'show']);
    // Attachments. Both ends check the ticket is one this user may see, and files are
    // stored off the public root and streamed — never reachable by guessing a name.
    Route::post('/tickets/{id}/files',            [\App\Http\Controllers\Api\SupportTicketController::class, 'uploadFile']);
    Route::get ('/tickets/{id}/files/{fileId}',   [\App\Http\Controllers\Api\SupportTicketController::class, 'downloadFile']);
    Route::post('/tickets/{id}/reply',            [\App\Http\Controllers\Api\SupportTicketController::class, 'reply']);
    Route::post('/tickets/{id}/escalate',         [\App\Http\Controllers\Api\SupportTicketController::class, 'escalate']);   // 2026-10-21
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
        // Provincial fee subsidies (2026-09-29): one per child at a time; invoices deduct them.
        Route::get   ('/compliance/subsidies',            [\App\Http\Controllers\Api\SubsidyController::class, 'index']);
        Route::get   ('/compliance/subsidies/csv',        [\App\Http\Controllers\Api\SubsidyController::class, 'csv']);
        Route::post  ('/compliance/subsidies',            [\App\Http\Controllers\Api\SubsidyController::class, 'store']);
        Route::patch ('/compliance/subsidies/{id}',       [\App\Http\Controllers\Api\SubsidyController::class, 'update'])->where('id', '[0-9]+');
        Route::post  ('/compliance/subsidies/{id}/end',   [\App\Http\Controllers\Api\SubsidyController::class, 'end'])->where('id', '[0-9]+');
        Route::delete('/compliance/subsidies/{id}',       [\App\Http\Controllers\Api\SubsidyController::class, 'destroy'])->where('id', '[0-9]+');
        // The province's or government's documents on a subsidy (private disk, staff only).
        Route::post  ('/compliance/subsidies/{id}/documents',        [\App\Http\Controllers\Api\SubsidyController::class, 'uploadDocuments'])->where('id', '[0-9]+');
        Route::get   ('/compliance/subsidies/{id}/documents/{doc}',  [\App\Http\Controllers\Api\SubsidyController::class, 'downloadDocument'])->where(['id' => '[0-9]+', 'doc' => '[0-9]+']);
        Route::delete('/compliance/subsidies/{id}/documents/{doc}',  [\App\Http\Controllers\Api\SubsidyController::class, 'removeDocument'])->where(['id' => '[0-9]+', 'doc' => '[0-9]+']);
    });
    // Educator points & rewards (2026-10-20): earners see their own; directors/admins award.
    Route::middleware('role:educator,home_visitor,centre_director,agency_admin,platform_admin')->group(function () {
        Route::get   ('/educator-points/me',            [\App\Http\Controllers\Api\EducatorRewardsController::class, 'me']);
        Route::get   ('/educator-points',               [\App\Http\Controllers\Api\EducatorRewardsController::class, 'index']);
        // Award history (2026-11-02): who was awarded, month by month, kept for good. Directors: their centres.
        Route::get   ('/educator-points/history',       [\App\Http\Controllers\Api\EducatorRewardsController::class, 'history']);
        Route::get   ('/educator-points/settings',      [\App\Http\Controllers\Api\EducatorRewardsController::class, 'settings']);
        Route::put   ('/educator-points/settings',      [\App\Http\Controllers\Api\EducatorRewardsController::class, 'saveSettings']);
        Route::post  ('/educator-points/awards',        [\App\Http\Controllers\Api\EducatorRewardsController::class, 'award'])->middleware('throttle:30,1,r-grp-3568');
        // The certificate of appreciation email, signed by the agency owner (2026-10-30).
        Route::get   ('/educator-points/thanks',        [\App\Http\Controllers\Api\EducatorRewardsController::class, 'thanksInfo']);
        Route::post  ('/educator-points/thanks',        [\App\Http\Controllers\Api\EducatorRewardsController::class, 'thanks'])->middleware('throttle:30,1,r-grp-3571');
        Route::post  ('/educator-points/thanks/note',   [\App\Http\Controllers\Api\EducatorRewardsController::class, 'thanksNote'])->middleware('throttle:20,1,r-ty-note');
        // Automatic monthly thank-you awards (2026-10-31): settings, drafts to review, history. Agency admins (checked in the controller).
        Route::get   ('/educator-points/auto',                    [\App\Http\Controllers\Api\ThankYouAutoController::class, 'show']);
        Route::put   ('/educator-points/auto',                    [\App\Http\Controllers\Api\ThankYouAutoController::class, 'save'])->middleware('throttle:30,1,r-ty-auto-save');
        Route::post  ('/educator-points/auto/drafts/{id}',        [\App\Http\Controllers\Api\ThankYouAutoController::class, 'update'])->whereNumber('id')->middleware('throttle:60,1,r-ty-auto-edit');
        Route::post  ('/educator-points/auto/drafts/{id}/send',   [\App\Http\Controllers\Api\ThankYouAutoController::class, 'send'])->whereNumber('id')->middleware('throttle:60,1,r-ty-auto-send');
        Route::post  ('/educator-points/auto/drafts/{id}/skip',   [\App\Http\Controllers\Api\ThankYouAutoController::class, 'skip'])->whereNumber('id')->middleware('throttle:60,1,r-ty-auto-skip');
        Route::post  ('/educator-points/auto/send-all',           [\App\Http\Controllers\Api\ThankYouAutoController::class, 'sendAll'])->middleware('throttle:6,1,r-ty-auto-all');
        Route::delete('/educator-points/awards/{id}',   [\App\Http\Controllers\Api\EducatorRewardsController::class, 'cancel'])->whereNumber('id');
        Route::post  ('/educator-points/awards/{id}/refresh', [\App\Http\Controllers\Api\EducatorRewardsController::class, 'refreshOne'])->whereNumber('id')->middleware('throttle:30,1,r-grp-3573');
        // This agency's own Giftbit account and catalogue (2026-10-20).
        Route::get   ('/educator-points/notify-preview',       [\App\Http\Controllers\Api\EducatorRewardsController::class, 'notifyPreview']);
        Route::get   ('/educator-points/tango',                [\App\Http\Controllers\Api\EducatorRewardsController::class, 'tangoShow']);
        Route::put   ('/educator-points/tango',                [\App\Http\Controllers\Api\EducatorRewardsController::class, 'tangoSave']);
        Route::post  ('/educator-points/tango/test',           [\App\Http\Controllers\Api\EducatorRewardsController::class, 'tangoTest'])->middleware('throttle:6,1,r-grp-3578');
        Route::get   ('/educator-points/giftbit',              [\App\Http\Controllers\Api\EducatorRewardsController::class, 'giftbitShow']);
        Route::put   ('/educator-points/giftbit',              [\App\Http\Controllers\Api\EducatorRewardsController::class, 'giftbitSave']);
        Route::post  ('/educator-points/giftbit/test',         [\App\Http\Controllers\Api\EducatorRewardsController::class, 'giftbitTest'])->middleware('throttle:6,1,r-grp-3581');
        Route::get   ('/educator-points/giftbit/brands',       [\App\Http\Controllers\Api\EducatorRewardsController::class, 'brands'])->middleware('throttle:30,1,r-grp-3582');
        Route::get   ('/educator-points/giftbit/brands/{code}',[\App\Http\Controllers\Api\EducatorRewardsController::class, 'brandDetail'])->where('code', '[A-Za-z0-9_-]+')->middleware('throttle:60,1,r-grp-3583');
    });
    // Safe Arrival (2026-09-29): the day's board, closing a case, and the agency's settings.
    Route::middleware('role:educator,centre_director,agency_admin,platform_admin')->group(function () {
        // Parent issues, staff side (2026-10-13): centre- and room-scoped in the controller.
        Route::get  ('/parent-issues',               [\App\Http\Controllers\Api\ParentIssueController::class, 'staffIndex']);
        Route::get  ('/parent-issues/{id}',          [\App\Http\Controllers\Api\ParentIssueController::class, 'staffShow'])->whereNumber('id');
        Route::patch('/parent-issues/{id}',          [\App\Http\Controllers\Api\ParentIssueController::class, 'staffUpdate'])->whereNumber('id');
        Route::post ('/parent-issues/{id}/messages', [\App\Http\Controllers\Api\ParentIssueController::class, 'staffMessage'])->whereNumber('id')->middleware('throttle:30,1,r-grp-3591');
        Route::get ('/safe-arrival/today',              [\App\Http\Controllers\Api\SafeArrivalController::class, 'today']);
        Route::get ('/safe-arrival/history',            [\App\Http\Controllers\Api\SafeArrivalController::class, 'history']);   // past checks + who was alerted (2026-11-03)
        Route::post('/safe-arrival/{childId}/resolve',  [\App\Http\Controllers\Api\SafeArrivalController::class, 'resolve'])->where('childId', '[0-9]+');
        Route::post  ('/safe-arrival/{childId}/late',   [\App\Http\Controllers\Api\SafeArrivalController::class, 'late'])->where('childId', '[0-9]+');        // running late (2026-11-06)
        Route::delete('/safe-arrival/{childId}/late',   [\App\Http\Controllers\Api\SafeArrivalController::class, 'cancelLate'])->where('childId', '[0-9]+');
        // End of day (Safe Departure, 2026-10-22): picked up at… / still here / other.
        Route::post('/safe-arrival/departure/{childId}/resolve', [\App\Http\Controllers\Api\SafeArrivalController::class, 'resolveDeparture'])->where('childId', '[0-9]+');
        // Phone messages a director/admin approves (2026-11-24, App\Support\ParentLine).
        Route::get ('/safe-arrival/phone-messages',              [\App\Http\Controllers\Api\ParentLineController::class, 'index']);
        Route::get ('/safe-arrival/phone-messages/{id}/audio',   [\App\Http\Controllers\Api\ParentLineController::class, 'audio'])->whereNumber('id');
        Route::post('/safe-arrival/phone-messages/{id}/approve', [\App\Http\Controllers\Api\ParentLineController::class, 'approve'])->whereNumber('id')->middleware('throttle:30,1,r-pmsg');
        Route::post('/safe-arrival/phone-messages/{id}/decline', [\App\Http\Controllers\Api\ParentLineController::class, 'decline'])->whereNumber('id')->middleware('throttle:30,1,r-pmsg');
        Route::get ('/safe-arrival/settings',           [\App\Http\Controllers\Api\SafeArrivalController::class, 'settings']);
        Route::put ('/safe-arrival/settings',           [\App\Http\Controllers\Api\SafeArrivalController::class, 'saveSettings']);
        Route::post('/safe-arrival/test-call',          [\App\Http\Controllers\Api\SafeArrivalController::class, 'testCall'])->middleware('throttle:5,60,r-safe-arrival-test-call');
        // The family record's subsidy history, and CWELCC enrolment changes as dated periods.
        Route::get   ('/compliance/families/{id}/subsidies', [\App\Http\Controllers\Api\SubsidyController::class, 'familyHistory'])->where('id', '[0-9]+');
        Route::post  ('/compliance/families/{id}/cwelcc',    [\App\Http\Controllers\Api\SubsidyController::class, 'familyCwelcc'])->where('id', '[0-9]+');
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
        // Insights panel on Payment schedules (2026-11-02): read-only figures, active agency, director = own centres.
        Route::get ('/payment-plans/stats',      [\App\Http\Controllers\Api\PaymentScheduleStatsController::class, 'stats']);
        Route::get ('/payment-plans/family/{id}',[\App\Http\Controllers\Api\PaymentPlanController::class, 'listForFamily']);
        Route::post('/payment-plans',            [\App\Http\Controllers\Api\PaymentPlanController::class, 'create']);
        Route::get ('/payment-plans/description-default', [\App\Http\Controllers\Api\PaymentPlanController::class, 'descriptionDefault']);
        Route::post('/payment-plans/{id}/cancel',[\App\Http\Controllers\Api\PaymentPlanController::class, 'cancel']);
        // Edit an existing schedule - rewrites only its unissued tail. See update().
        Route::patch('/payment-plans/{id}',       [\App\Http\Controllers\Api\PaymentPlanController::class, 'update'])->where('id','[0-9]+');
    /* One instalment, not the whole tail. A row's own edit must not renumber
       every invoice after it. */
    Route::patch('/payment-plans/{plan}/installments/{installment}', [\App\Http\Controllers\Api\PaymentPlanController::class, 'updateInstallment'])
        ->where('plan', '[0-9]+')->where('installment', '[0-9]+');
    /* Remove an UNISSUED instalment. An issued one must be voided in Accounting,
       where the family gets told. */
    Route::delete('/payment-plans/{plan}/installments/{installment}', [\App\Http\Controllers\Api\PaymentPlanController::class, 'deleteInstallment'])
        ->where('plan', '[0-9]+')->where('installment', '[0-9]+');
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
        // AI writing help beside announcement / message / email / incident boxes (2026-10-28, AI Bundle).
        Route::post('/ai/assist/write',                    [\App\Http\Controllers\Api\AiAssistController::class, 'write'])->middleware('throttle:30,1,r-ai-assist-write');
        // AI picks a library food photo for menu dishes local matching could not place (AI Bundle, 2026-10-31).
        Route::post('/ai/assist/food-photo',               [\App\Http\Controllers\Api\FoodPhotoController::class, 'ai'])->middleware('throttle:20,1,foodai');
        // AI finds a picture for a lesson plan activity (AI Bundle, 2026-11-02).
        Route::post('/ai/assist/lesson-image',             [\App\Http\Controllers\Api\LessonPlanController::class, 'imageAi'])->middleware('throttle:20,1,lpimgai');
    });
    // Ask KiddieTrac - the admin AI assistant (AI Bundle, 2026-11-03). Read-only tools, scoped in AskScope/AskTools.
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::post('/ai/assist/ask', [\App\Http\Controllers\Api\AskController::class, 'ask'])->middleware('throttle:12,1,r-ai-ask');
    });
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::post('/ai/doc-extract/{id}/auto-link',      [\App\Http\Controllers\Api\AiV2Controller::class, 'autoLinkExtraction']);
        Route::get ('/ai/enrollment-forecast',             [\App\Http\Controllers\Api\AiV2Controller::class, 'enrollmentForecast']);
        Route::get ('/ai/attendance-anomalies',            [\App\Http\Controllers\Api\AiV2Controller::class, 'attendanceAnomalies']);
        Route::put ('/ai/attendance-anomalies/rules',      [\App\Http\Controllers\Api\AiV2Controller::class, 'attendanceAnomalyRules']);
    });

    // QBO bulk push
    Route::middleware('role:agency_admin,platform_admin')->group(function () {
        Route::post('/qbo/sync/invoices/bulk',             [\App\Http\Controllers\Api\QboController::class, 'bulkSyncInvoices']);
    });

    // Time-off calendar block read endpoint
    Route::middleware('role:centre_director,agency_admin,platform_admin')->group(function () {
        Route::get('/director/schedule/time-off-blocks',   [\App\Http\Controllers\Api\SchedulingController::class, 'timeOffBlocks']);
        // Birthdays, absences, time off, vacations and closures for the calendar grid.
        Route::get('/director/calendar/overlays',          [\App\Http\Controllers\Api\CalendarOverlayController::class, 'index']);
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
    // 'integration' (2026-11-24): the silent service role an integration token holds - it can call this API and nothing else,
    // and no staff list, notification or email audience includes it (people roles never name it).
    Route::middleware('role:agency_admin,platform_admin,integration')->prefix('integration')->group(function () {
        Route::get ('/ping',     [\App\Http\Controllers\Api\IntegrationController::class, 'ping']);
        Route::post('/centres',  [\App\Http\Controllers\Api\IntegrationController::class, 'upsertCentre']);
        Route::post('/families', [\App\Http\Controllers\Api\IntegrationController::class, 'upsertFamily']);
        Route::post('/guardians', [\App\Http\Controllers\Api\IntegrationController::class, 'upsertGuardian']);
        Route::post('/children', [\App\Http\Controllers\Api\IntegrationController::class, 'upsertChild']);
        Route::post('/children/deactivate', [\App\Http\Controllers\Api\IntegrationController::class, 'deactivateChild']);
        /* A source system switching a family OFF. De-enrolment, not deletion — and it
           answers 422 with the outstanding balance rather than closing the accounts
           quietly, so the far end can put the figure in front of a person. */
        Route::post('/families/deactivate', [\App\Http\Controllers\Api\IntegrationController::class, 'deactivateFamily']);
        // ...and switching it back on. Restores rather than creating a second family.
        Route::post('/families/restore',    [\App\Http\Controllers\Api\IntegrationController::class, 'restoreFamilyExternal']);
        // One family's open invoices, so the partner can say which one a payment pays (2026-11-03). Read-only.
        Route::get ('/families/open-invoices', [\App\Http\Controllers\Api\IntegrationController::class, 'familyOpenInvoices']);
        Route::post('/invoices', [\App\Http\Controllers\Api\IntegrationController::class, 'upsertInvoice']);
        Route::post('/payroll',  [\App\Http\Controllers\Api\IntegrationController::class, 'upsertPayroll']);
        Route::post('/waitlist', [\App\Http\Controllers\Api\IntegrationController::class, 'upsertWaitlist']);
        Route::get ('/waitlist/pull', [\App\Http\Controllers\Api\IntegrationController::class, 'pullWaitlist']);
        Route::get ('/contacts/pull', [\App\Http\Controllers\Api\IntegrationController::class, 'pullContacts']);
        // The other direction: families and children that ORIGINATED here, so a
        // consumer can create them. pullContacts only refreshes what it already owns.
        Route::get ('/pull/new', [\App\Http\Controllers\Api\IntegrationController::class, 'pullNew']);
        // People too (2026-10-19): staff and parents, so a consumer can mirror them.
        Route::get ('/pull/users', [\App\Http\Controllers\Api\IntegrationController::class, 'pullUsers']);
        Route::post('/sync',     [\App\Http\Controllers\Api\IntegrationController::class, 'sync']);
        /* What we actually hold, by id — so a push integration can check that what it
           sent arrived, instead of only knowing that it was sent. */
        Route::get ('/audit',    [\App\Http\Controllers\Api\IntegrationController::class, 'audit']);
    });







});
});

// v22p5: kiosk mode — public endpoints (no auth; centre token in URL)
Route::prefix('kiosk')->group(function () {
    Route::get('/{token}', [\App\Http\Controllers\Api\KioskController::class, 'lookup']);
    Route::post('/{token}/check-event', [\App\Http\Controllers\Api\KioskController::class, 'checkEvent'])->middleware('throttle:120,1,r-x-check-event');
});

// ===== v22p51 PUBLIC ROUTES =====


// v22p51 — Stripe parent-pay webhook (no auth; signature-verified inside)
Route::post('/stripe/parent-webhook', [\App\Http\Controllers\Api\StripeParentPayController::class, 'webhook'])->middleware('throttle:600,1,r-stripe-parent-webhook');

// v22p51 — Public agency landing pages (HTML)
Route::get('/public/landing/{slug}', [\App\Http\Controllers\Api\LandingController::class, 'landing']);

// v22p51 — QBO OAuth callback (no auth — state cookie identifies agency)
Route::get('/qbo/callback', [\App\Http\Controllers\Api\QboController::class, 'callback']);
// Xero OAuth callback (public — a one-use, server-held state identifies the agency and admin)
Route::get('/xero/callback', [\App\Http\Controllers\Api\XeroController::class, 'callback'])->middleware('throttle:30,1,r-xero-callback');
// Sage Accounting OAuth callback (public — one-use, server-held state)
Route::get('/sage/callback', [\App\Http\Controllers\Api\SageController::class, 'callback'])->middleware('throttle:30,1,r-sage-callback');

// v22p51 — public locale bundles (no auth required so the login page can localize too)
Route::get('/locale/public/{lang}', [\App\Http\Controllers\Api\LocaleController::class, 'strings']);


// ===== v22p53 PUBLIC =====

Route::post('/kiosk/{token}/signature', [\App\Http\Controllers\Api\OperationsController::class, 'captureSignature'])->middleware('throttle:120,1,r-kiosk-x-signature');




// v22p63 SSO (public, /api/v1 prefix)
Route::prefix('v1')->group(function () {
    Route::get ('/sso/providers',                [\App\Http\Controllers\Api\SsoController::class, 'providers']);
    Route::get ('/sso/{provider}',               [\App\Http\Controllers\Api\SsoController::class, 'redirect']);
    Route::get ('/sso/{provider}/callback',      [\App\Http\Controllers\Api\SsoController::class, 'callback']);
    Route::post('/kiosk/{token}/temperature',    [\App\Http\Controllers\Api\KioskTemperatureController::class, 'record'])->middleware('throttle:120,1,r-kiosk-x-temperature');
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

/* Knowledge base - the agency's OWN articles. Every signed-in role may read and
   write one; only the author, or someone who manages the agency, may edit or
   remove another person's. Scoping happens inside the controller through the
   shared resolver, never the raw X-Active-Agency-Id header. */
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('kb',         [App\Http\Controllers\Api\KnowledgeBaseController::class, 'index']);
    Route::post('kb',        [App\Http\Controllers\Api\KnowledgeBaseController::class, 'store']);
    Route::get('kb/{id}',    [App\Http\Controllers\Api\KnowledgeBaseController::class, 'show'])->whereNumber('id');
    Route::put('kb/{id}',    [App\Http\Controllers\Api\KnowledgeBaseController::class, 'update'])->whereNumber('id');
    Route::delete('kb/{id}', [App\Http\Controllers\Api\KnowledgeBaseController::class, 'destroy'])->whereNumber('id');
});

/* The pulse - one cheap request answering "has anything I can see changed?", so a
   change made by ANOTHER user reaches the screen without a reload. Replaces the
   question a dozen separate badge pollers were each asking on their own timer. */
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('pulse', [App\Http\Controllers\Api\PulseController::class, 'index']);
});

/* "What do we hold about this person, and when is it destroyed?" - the question a
   privacy request actually asks. Reads the SAME agencies.settings->compliance policy
   the retention purge acts on, so the answer shown and the deletion performed cannot
   disagree. */
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('admin/users/{user}/data-map', [App\Http\Controllers\Api\UserDataMapController::class, 'show'])->whereNumber('user');
});

/* SLEEP SUPERVISION CHECKS (2026-10-13; Ontario O. Reg. 137/15 s.33.1). Educators (their
   rooms), directors (their centres), admins (the agency) — scoped in the controller.
   Report, settings and the physician-direction write check the role again inside. */
Route::prefix('v1')->middleware(['auth:sanctum', 'role:educator,centre_director,agency_admin,platform_admin'])->group(function () {
    Route::get ('sleep-checks/status',   [App\Http\Controllers\Api\SleepCheckController::class, 'status']);
    Route::get ('sleep-checks/room',     [App\Http\Controllers\Api\SleepCheckController::class, 'room']);
    Route::post('sleep-checks',          [App\Http\Controllers\Api\SleepCheckController::class, 'store']);
    Route::get ('sleep-checks/report',   [App\Http\Controllers\Api\SleepCheckController::class, 'report']);
    Route::get ('sleep-checks/settings', [App\Http\Controllers\Api\SleepCheckController::class, 'settings']);
    Route::post('sleep-checks/settings', [App\Http\Controllers\Api\SleepCheckController::class, 'saveSettings']);
    Route::post('sleep-checks/children/{child}/direction', [App\Http\Controllers\Api\SleepCheckController::class, 'direction'])->whereNumber('child');
});

/* ONTARIO ECE WORKFORCE FUNDING (2026-10-13) - WEG + CWELCC workforce compensation: wage
   components, pay-period lines, funding receipts (32-day check), year-end reconciliation.
   Admins only; the controller re-checks agency_admin of the ACTIVE agency and CA-ON. */
Route::prefix('v1')->middleware(['auth:sanctum', 'role:agency_admin,platform_admin'])->group(function () {
    Route::get ('workforce-funding/status',   [App\Http\Controllers\Api\WorkforceFundingController::class, 'status']);
    Route::get ('workforce-funding/settings', [App\Http\Controllers\Api\WorkforceFundingController::class, 'settings']);
    Route::post('workforce-funding/settings', [App\Http\Controllers\Api\WorkforceFundingController::class, 'saveSettings']);
    Route::post('workforce-funding/policy',   [App\Http\Controllers\Api\WorkforceFundingController::class, 'policy']);
    Route::get ('workforce-funding/staff',    [App\Http\Controllers\Api\WorkforceFundingController::class, 'staff']);
    Route::get ('workforce-funding/staff/{user}', [App\Http\Controllers\Api\WorkforceFundingController::class, 'person'])->whereNumber('user');
    Route::post('workforce-funding/staff/{user}/components', [App\Http\Controllers\Api\WorkforceFundingController::class, 'addComponent'])->whereNumber('user');
    Route::post ('workforce-funding/components/{id}', [App\Http\Controllers\Api\WorkforceFundingController::class, 'updateComponent'])->whereNumber('id');
    Route::patch('workforce-funding/components/{id}', [App\Http\Controllers\Api\WorkforceFundingController::class, 'updateComponent'])->whereNumber('id');
    Route::get ('workforce-funding/period',   [App\Http\Controllers\Api\WorkforceFundingController::class, 'period']);
    Route::get ('workforce-funding/receipts', [App\Http\Controllers\Api\WorkforceFundingController::class, 'receipts']);
    Route::post('workforce-funding/receipts', [App\Http\Controllers\Api\WorkforceFundingController::class, 'addReceipt']);
    Route::post('workforce-funding/receipts/{id}/void', [App\Http\Controllers\Api\WorkforceFundingController::class, 'voidReceipt'])->whereNumber('id');
    Route::get ('workforce-funding/year-end', [App\Http\Controllers\Api\WorkforceFundingController::class, 'yearEnd']);
});
