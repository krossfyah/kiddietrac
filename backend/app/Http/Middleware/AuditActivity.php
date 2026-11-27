<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Portal-wide activity audit (2026-07-08).
 *
 * Records EVERY state-changing API request (POST/PUT/PATCH/DELETE) — by any
 * signed-in user — into audit_logs, so the audit trail captures the whole
 * portal (chats/messages, billing, enrolments, settings, etc.) without having
 * to instrument each controller by hand. Successful reads and a small denylist
 * of high-frequency/noise endpoints are skipped. Both successes AND failures
 * are recorded (the HTTP status is stored), so failed attempts are visible too.
 *
 * Never throws: any logging error is swallowed so it can't break a request.
 */
class AuditActivity
{
    /** Path fragments we never audit (polling, tracking pixels, auth probes, read receipts). */
    private const SKIP = [
        'unread-count', 'auth/me', 'auth/refresh', '/e/o/', 'heartbeat',
        'presence', 'ping', '/read', 'typing', 'csrf',
        // push/device is a routine FCM token upsert that fires on EVERY app launch/
        // foreground — it flooded the audit trail with "Registered a device for push"
        // and isn't a meaningful user action. staff/punch is skipped here because
        // CareController@punch writes its OWN detailed audit entry (clock IN vs OUT
        // with the centre + duration), which the generic middleware entry can't.
        // staff/break/ (2026-11-05): breakStart / breakEnd write their own the same way.
        'push/device', 'push/subscribe', 'staff/punch', 'staff/break/',
        // quick-notes (2026-11-26): QuickNotesController writes its own named entry (which fields changed). The raw
        // request line would copy a PRIVATE note's words into the agency's audit log, readable by its admins.
        'quick-notes',
        // walkie/ (2026-11-27): the walkie-talkie posts its audio here several times a second; WalkieController writes its own
        // entries (who talked to whom, when, how long) and the request body (the audio) must never reach the audit log.
        'walkie/',
        // diag/net: the browser's once-a-session connection-speed summary (NetQuality).
        'diag/net',
        // diag/search-words: a screen's on-screen words for the global search (SearchKeywords) - never stored in audit.
        'diag/search-words',
        // integration/* = machine-to-machine sync between iLearn and KiddieTrac
        // (waitlist + contacts, every 5 min). It re-pushes the same rows on a schedule
        // and was writing ~1,500 identical "integration/waitlist" audit rows a DAY —
        // pure noise, not a person's action. Skip the whole integration namespace.
        'integration/',
        // badges/sync: the page telling the person's iPhones their icon number went down (kt-badges.js). It fires
        // whenever a count drops, from every open tab - 80 "Synced the app icon badge" rows on 2026-11-02, none of
        // them something a person did. The reads that cause it are audited on their own.
        'badges/sync',
        /* AUTOMATIC TRAFFIC, NOT ACTIONS (2026-11-03, Anthony: "why so many entries for matched menu dishes to food
           photos ... fix this and anything similiar like this - full sweep"). The week before: 777 food-images/match,
           637 me/device-permissions, 367 demo/pulse, 317 me/markers, 309 notifications/seen, 247 mark-read.
           - food-images/match: the menu editor looking up a photo for a dish name AS IT IS TYPED ("Di", "Diegs",
             "Digestive"...) - a read-only search sent as POST. Saving the menu is audited (operations/menu); the paid
             AI lookup (ai/assist/food-photo) stays audited.
           - mark-read / read-all / seen: read receipts. '/read' above was meant to cover them but never matched
             "mark-read" (a hyphen, not a slash) - the same spelling gap kt-live.js closed on 2026-09-08.
           - me/device-permissions: the app reporting what the phone allows, on start and on return (kt-device-perms.js).
           - demo/pulse: the web demo's timer asking for its next scripted, fictional notification.
           - me/markers: interface state (when the bell was last opened, tips dismissed). auth/me/ui-prefs is already
             covered by 'auth/me'. */
        'food-images/match', 'mark-read', 'read-all', '/seen', 'me/device-permissions', 'demo/pulse', 'me/markers',
    ];

    /** Request fields to redact from the stored payload. */
    private const REDACT = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'secret', 'api_key', 'apikey', 'client_secret', 'card', 'card_number',
        'cvv', 'cvc', 'ssn', 'sin', 'kiosk_pin', 'pin',
    ];

    /**
     * Claim any audit row this request wrote without an agency stamp.
     *
     * The audit view matches al.agency_id exactly, so an unstamped row is invisible to
     * everyone. Most controllers write their own richer audit entries — staff.clock_in,
     * child.check_in_by_staff, chat.email_notified — and almost none of them stamp.
     * Rather than patching 27 call sites and hoping the 28th remembers, the middleware
     * that already knows the agency adopts whatever the request left behind.
     *
     * Bounded to rows written by THIS actor during THIS request, so it can never reach
     * across to another tenant's row: same actor, same agency, same few seconds.
     */
    /**
     * Set by a DB listener when this request really does INSERT into audit_logs.
     *
     * Without it, claimUnstamped ran AuditScope::resolve() plus an UPDATE on every
     * request in the system — including the reads that make up the overwhelming
     * majority of traffic and never write an audit row at all. Three writes per read
     * was what pinned throughput at ~28 req/s with the CPU idle. (2026-08-29)
     */
    private bool $auditRowWritten = false;

    private function claimUnstamped(Request $request, $user, ?string $startedAt): void
    {
        if (! $user || ! $startedAt) {
            return;
        }
        // Nothing was written, so there is nothing unstamped to adopt.
        if (! $this->auditRowWritten) {
            return;
        }
        try {
            $agencyId = \App\Support\AuditScope::resolve((int) $user->id, $request);
            if (! $agencyId) {
                return;
            }
            DB::table('audit_logs')
                ->whereNull('agency_id')
                ->where('user_id', $user->id)
                ->where('created_at', '>=', $startedAt)
                ->update(['agency_id' => $agencyId]);
        } catch (\Throwable $e) {
            // An audit stamp is worth having, never worth failing a request over.
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        /* Watch for a real audit insert. Cheaper than the UPDATE it avoids, and exact:
           whatever writes an audit row — controller, observer, service — trips this. */
        \Illuminate\Support\Facades\DB::listen(function ($q) {
            if (! $this->auditRowWritten
                && stripos($q->sql, 'insert into `audit_logs`') !== false) {
                $this->auditRowWritten = true;
            }
        });

        // Taken BEFORE anything runs, so the window covers every row this request
        // goes on to write.
        $startedAt = now()->toDateTimeString();
        \App\Support\Audit::$namedThisRequest = false;

        $response = $next($request);
        try {
            $this->record($request, $response);
            // Controllers write their own richer entries — staff.clock_in,
            // child.check_in_by_staff — and almost none of them stamp the agency.
            // Adopt whatever this request left unstamped, here, once.
            $this->claimUnstamped($request, $request->user(), $startedAt);
        } catch (Throwable $e) {
            // Auditing must never break the actual request.
        }
        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        $method = strtoupper($request->method());
        $isWrite = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        /* A read that FAILS is worth keeping; a read that works is not.

           Educators could not download the Daily Supervision Check for a whole day
           and nothing anywhere recorded it, because a download is a GET and this
           returned before writing a row. The cause (a signed URL having its host
           rewritten to the portal's, so every request 404'd) was invisible until
           somebody complained.

           Auditing all reads would drown the table — reads are most of the traffic.
           Auditing the ones that come back 4xx/5xx costs almost nothing and is the
           signal somebody actually needs. */
        if (! $isWrite) {
            if ($method !== 'GET') {
                return;
            }
            $st = $response->getStatusCode();
            if ($st < 400 && ! ($request->user() && $this->isExport($request->path()))) {
                return;                 // a working read (an export of records is the exception)
            }
            if (in_array($st, [401, 429], true)) {
                // Ordinary traffic: an expired token in somebody's pocket, or a rate
                // limit doing its job. Real login failures are audited by AuthController.
                return;
            }
            if ($st === 404 && $request->route() === null) {
                // No such route at all — scanners and stale bookmarks. Only a 404
                // from a route we do serve says something about us.
                return;
            }
        }
        $user = $request->user();
        if (! $user) {
            /* SIGNED-OUT ACTIONS THAT ARE STILL SOMEBODY'S ACTION (2026-10-21). Sign-in,
               password reset and the signed form/file links audit themselves in their
               controllers; the routes in GUEST below did not audit at all - a parent
               signing a child in at the kiosk, a director approving time off from the email
               link, an RSVP. Each is filed under the agency its token or record belongs to. */
            if ($isWrite) {
                $this->recordGuest($request, $response);
            }
            return;
        }
        $path = $request->path();
        /* A DOWNLOAD OF PEOPLE'S DATA IS AN EVENT (2026-10-21). Successful reads are not
           audited (that would drown the table), but an export, a CSV or a PDF of records is
           exactly what an auditor asks about after a leak: who took a copy, and when. */
        if (! $isWrite && $response->getStatusCode() < 400 && ! $this->isExport($path)) {
            return;
        }
        foreach (self::SKIP as $frag) {
            if (stripos($path, $frag) !== false) {
                return;
            }
        }
        /* ONE FILE, ONE ENTRY (2026-10-09: "when uploading the video to sales lead the audit
           log has multiple entries for the same request"). A recording arrives as ~8 MB
           pieces, one request each: a 200 MB video wrote 25 identical
           "post:api/v1/sales/activities/48/upload" rows. SalesActivityFileController writes
           the meaningful one (sales.activity_file_added: which file, how big) when the last
           piece lands, so a successful piece is not audited here. A FAILED piece still is. */
        if (preg_match('#/sales/activities/\d+/upload$#', '/' . $path) && $response->getStatusCode() < 400) {
            return;
        }
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $status = $response->getStatusCode();
        $ok = $status >= 200 && $status < 300;

        /* ONE ROW PER ACTION (2026-10-20). When the controller already wrote a named entry
           for this request ("child.care_schedule_updated", "staff.clock_in"), the raw
           request line was a second row saying the same thing in URL form. A FAILED request
           is still written: the named entry is usually only written on success. */
        if ($ok && \App\Support\Audit::$namedThisRequest) {
            return;
        }

        /* action = "post:admin/centres" (route name if present), capped at 80 chars.

           getName() returns null for an unnamed route only while the route cache is cold.
           `artisan route:cache` gives every unnamed route a placeholder name --
           "generated::" plus sixteen random characters -- so on a cached production app
           this was never null, the readable fallback never ran, and the log filled with
           strings that mean nothing and CHANGE on every cache rebuild. A placeholder is
           the absence of a name, so treat it as one. */
        $routeName = optional($request->route())->getName();
        if ($routeName !== null && str_starts_with($routeName, 'generated::')) {
            $routeName = null;
        }
        $action = $routeName ?: (strtolower($method) . ':' . $path);
        if (! $ok) {
            $action .= ' [fail]';
        }
        $action = substr($action, 0, 80);

        // Best-effort entity from the first numeric route param.
        $entityId = null;
        $entityType = null;
        foreach ((array) optional($request->route())->parameters() as $k => $v) {
            if (is_numeric($v)) {
                $entityId = (int) $v;
                /* A parameter literally called "id" names nothing -- "id#10" tells a
                   reader less than the path does. Keep the number, take the type from
                   the path instead by leaving $entityType null for the block below. */
                $entityType = in_array(strtolower((string) $k), ['id', 'i', 'n'], true)
                    ? null
                    : substr((string) $k, 0, 80);
                break;
            }
        }
        if (! $entityType) {
            // Derive a coarse entity type from the path (first non-admin segment).
            $seg = array_values(array_filter(explode('/', $path), function ($s) {
                return $s !== '' && $s !== 'admin' && $s !== 'api' && ! preg_match('/^v\d+$/', $s) && ! is_numeric($s);
            }));
            $entityType = isset($seg[0]) ? substr($seg[0], 0, 80) : null;
        }

        $payload = [
            'method'  => $method,
            'path'    => $path,
            'status'  => $status,
            'input'   => $this->safeInput($request),
        ];
        $headerAgency = $request->header('X-Active-Agency-Id');
        if ($headerAgency) {
            $payload['active_agency_id'] = (int) $headerAgency;
        }

        // The agency this action BELONGS to — stamped so the per-agency audit log
        // can filter strictly by it (no cross-tenant leakage). A platform_admin is
        // tagged to the agency they've switched into; everyone else to their own.
        $agencyId = \App\Support\AuditScope::resolve((int) ($user->id ?? 0), $request);

        \App\Support\Audit::write([
            'user_id'     => $user->id ?? null,
            'agency_id'   => $agencyId,
            'action'      => $action,
            'entity_type' => \App\Support\AuditLabels::entityType($entityType),
            'entity_id'   => $entityId,
            'payload'     => json_encode($payload),
            'ip_address'  => substr((string) $request->ip(), 0, 45),
            'user_agent'  => substr((string) $request->userAgent(), 0, 500),
            'created_at'  => now(),
        ]);
    }

    /* Reads that hand someone a copy of records: exports, CSVs, PDFs, printouts, downloads.
       Photos (media/) and the app's own housekeeping are left out - they are viewing, not
       taking a copy, and would bury the rows that matter. */
    private const EXPORT = ['#/exports?(/|$)#', '#\.csv$#', '#/csv(/|$)#', '#/download(/|$)#', '#/pdf$#', '#/print$#'];
    private const EXPORT_SKIP = ['media/', 'diag/', 'whats-new', 'marketing-site/', 'storage/'];

    private function isExport(string $path): bool
    {
        foreach (self::EXPORT_SKIP as $s) {
            if (stripos($path, $s) !== false) {
                return false;
            }
        }
        foreach (self::EXPORT as $re) {
            if (preg_match($re, $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Signed-out write routes that are a person's action, and how to find whose agency it is.
     *
     * Deliberately NOT here (see kt:audit-labels --coverage): payment and SMS/voice webhooks
     * (machine traffic with their own logs), website visitors (leads, chat, newsletter),
     * diagnostics, and form drafts (autosave). Sign-in, reset, signed forms/files, NDA and
     * proposal signing, sign-up and SMS consent audit themselves in their controllers.
     */
    public const GUEST = [
        '#^api/kiosk/([^/]+)/check-event$#'          => ['kiosk.check_event', 'kiosk'],
        '#^api/kiosk/([^/]+)/signature$#'            => ['kiosk.signature', 'kiosk'],
        '#^api/v1/kiosk/([^/]+)/temperature$#'       => ['kiosk.temperature', 'kiosk'],
        '#^api/v1/time-off/act/(\d+)$#'              => ['time_off.decided_by_link', 'time_off'],
        '#^api/v1/feedback/day/(\d+)$#'              => ['parent_feedback.submitted', 'child'],
        '#^api/v1/public/rsvp/event/([^/]+)$#'       => ['rsvp.submitted', 'rsvp_event'],
        '#^api/v1/public/rsvp/invite/([^/]+)$#'      => ['rsvp.submitted', 'rsvp_invite'],
        '#^api/v1/public/give/([^/]+)$#'             => ['donation.pledged', 'donation'],
        '#^api/v1/public/tours$#'                    => ['tour.booked', 'tour'],
        '#^api/v1/public/proposal/([^/]+)/decline$#' => ['sales_quote.client_declined', null],
        '#^api/v1/auth/register$#'                   => ['auth.registered', null],
    ];

    private function recordGuest(Request $request, Response $response): void
    {
        $path = $request->path();
        foreach (self::GUEST as $re => [$action, $kind]) {
            if (! preg_match($re, $path, $m)) {
                continue;
            }
            $status = $response->getStatusCode();
            $ok = $status >= 200 && $status < 300;
            if ($ok && \App\Support\Audit::$namedThisRequest) {
                return;     // the controller wrote its own, better entry
            }
            $key = $m[1] ?? null;
            [$agencyId, $entityType, $entityId, $actorId] = $this->guestOwner($kind, $key, $request, $ok);
            \App\Support\Audit::write([
                'user_id'     => $actorId,
                'agency_id'   => $agencyId,
                'action'      => substr($action . ($ok ? '' : ' [fail]'), 0, 80),
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'payload'     => json_encode([
                    'method'     => strtoupper($request->method()),
                    // A kiosk or RSVP token is a key: never copy it into the log.
                    'path'       => ($key !== null && ! ctype_digit($key)) ? str_replace($key, '…', $path) : $path,
                    'status'     => $status,
                    'signed_out' => true,
                    'input'      => $this->safeInput($request),
                ]),
                'ip_address'  => substr((string) $request->ip(), 0, 45),
                'user_agent'  => substr((string) $request->userAgent(), 0, 500),
                'created_at'  => now(),
            ]);
            return;
        }
    }

    /** [agency_id, entity_type, entity_id, actor user id] for a signed-out action. */
    private function guestOwner(?string $kind, ?string $key, Request $request, bool $ok): array
    {
        try {
            // A signed link names who it was sent to (?u=); only trusted once the request
            // succeeded, i.e. the signature held.
            $u = $ok ? (int) $request->query('u') : 0;
            $actor = ($u > 0 && DB::table('users')->where('id', $u)->exists()) ? $u : null;
            switch ($kind) {
                case 'kiosk':
                    $c = DB::table('centres')->where('kiosk_token', (string) $key)->first(['id', 'agency_id']);
                    return [$c ? (int) $c->agency_id : null, 'centre', $c ? (int) $c->id : null, null];
                case 'time_off':
                    $t = DB::table('time_off_requests')->where('id', (int) $key)->first(['id', 'agency_id']);
                    return [$t ? (int) $t->agency_id : null, 'time_off_request', $t ? (int) $t->id : null, $actor];
                case 'child':
                    $ag = DB::table('enrollments as e')->join('rooms as r', 'r.id', '=', 'e.room_id')
                        ->join('centres as c', 'c.id', '=', 'r.centre_id')
                        ->where('e.child_id', (int) $key)->orderByDesc('e.id')->value('c.agency_id');
                    return [$ag ? (int) $ag : null, 'child', (int) $key, $actor];
                case 'rsvp_event':
                    $e = DB::table('rsvp_events')->where('public_token', (string) $key)->first(['id', 'agency_id']);
                    return [$e ? (int) $e->agency_id : null, 'rsvp_event', $e ? (int) $e->id : null, null];
                case 'rsvp_invite':
                    $i = DB::table('rsvp_invites')->where('token', (string) $key)->first(['event_id', 'agency_id', 'user_id']);
                    return [$i ? (int) $i->agency_id : null, 'rsvp_event', $i ? (int) $i->event_id : null,
                        ($ok && $i && $i->user_id) ? (int) $i->user_id : null];
                case 'donation':
                    $d = DB::table('donation_campaigns')->where('slug', (string) $key)->first(['id', 'agency_id']);
                    return [$d ? (int) $d->agency_id : null, 'donation_campaign', $d ? (int) $d->id : null, null];
                case 'tour':
                    $ag = DB::table('agencies')->where('slug', (string) $request->input('agency_slug'))->value('id');
                    $cid = (int) $request->input('centre_id');
                    return [$ag ? (int) $ag : null, 'centre', $cid ?: null, null];
            }
        } catch (Throwable $e) {
        }
        return [null, null, null, null];
    }

    /** Redacted, size-capped snapshot of the request body for the audit record. */
    private function safeInput(Request $request): array
    {
        $input = $request->except(self::REDACT);
        // Redact any nested key whose name looks sensitive.
        array_walk_recursive($input, function (&$v, $k) {
            if (is_string($k)) {
                $lk = strtolower($k);
                foreach (self::REDACT as $bad) {
                    if (strpos($lk, $bad) !== false) { $v = '[redacted]'; return; }
                }
            }
            if (is_string($v) && strlen($v) > 500) {
                $v = substr($v, 0, 500) . '…';
            }
        });
        $json = json_encode($input);
        if ($json !== false && strlen($json) > 4000) {
            return ['_truncated' => true, 'keys' => array_slice(array_keys($input), 0, 40)];
        }
        return $input;
    }
}
