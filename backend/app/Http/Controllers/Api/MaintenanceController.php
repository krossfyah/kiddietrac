<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Scheduled platform maintenance (super-admin). One window row at a time.
 * The login block lives in AuthController; this manages the window + the
 * heads-up emails.
 */
class MaintenanceController extends Controller
{
    /** The current window row (latest), or null. */
    public static function window(): ?object
    {
        return DB::table('maintenance_windows')->orderByDesc('id')->first();
    }

    /** Is the portal in maintenance right now? */
    public static function isDown(): bool
    {
        $w = self::window();
        if (! $w || ! $w->active) {
            return false;
        }
        $now = now();
        if ($w->starts_at && $now->lt(Carbon::parse($w->starts_at))) {
            return false;
        }
        if ($w->ends_at && $now->gt(Carbon::parse($w->ends_at))) {
            return false;
        }

        return true;
    }

    public function show(Request $request): JsonResponse
    {
        $w = self::window();

        return response()->json([
            'window' => $w ? [
                'id' => (int) $w->id,
                'starts_at' => $w->starts_at,
                'ends_at' => $w->ends_at,
                'message' => $w->message,
                'active' => (bool) $w->active,
                'notified_at' => $w->notified_at,
            ] : null,
            'is_down_now' => self::isDown(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'message' => ['nullable', 'string', 'max:1000'],
            'active' => ['required', 'boolean'],
        ]);

        $row = [
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'message' => $data['message'] ?? null,
            'active' => (bool) $data['active'],
            'updated_at' => now(),
        ];

        $existing = self::window();
        if ($existing) {
            DB::table('maintenance_windows')->where('id', $existing->id)->update($row);
            $id = $existing->id;
        } else {
            $row['created_by'] = $request->user()->id ?? null;
            $row['created_at'] = now();
            $id = DB::table('maintenance_windows')->insertGetId($row);
        }

        // Business-critical change → alert the owners.
        \App\Support\CriticalNotifier::send('Scheduled maintenance window ' . ($existing ? 'updated' : 'created'), array_values(array_filter([
            isset($row['starts_at']) ? 'Starts: ' . $row['starts_at'] : null,
            isset($row['ends_at']) ? 'Ends: ' . $row['ends_at'] : null,
            isset($row['is_active']) ? 'Active: ' . ($row['is_active'] ? 'yes' : 'no') : null,
            'Portal login will be blocked during the window (platform admins exempt).',
        ])));

        return response()->json(['message' => 'Saved', 'id' => $id, 'is_down_now' => self::isDown()]);
    }

    /** Email the maintenance notice to a single test address. */
    public function sendTest(Request $request): JsonResponse
    {
        $to = $request->validate(['email' => ['required', 'email']])['email'];
        $w = self::window() ?: (object) ['starts_at' => null, 'ends_at' => null, 'message' => null];
        $r = self::sendNotice($to, $w, true, null);

        // "Sent" only when the mail gate let it through (never-default-a-recipient rule 2).
        if ($r === 'sent') {
            return response()->json(['sent' => true, 'message' => 'Test sent to ' . $to]);
        }

        return response()->json([
            'sent' => false,
            'message' => $r === 'held'
                ? 'The mail gate held the test to ' . $to . ' (see Email log for the reason).'
                : 'Could not send the test email.',
        ], $r === 'held' ? 200 : 500);
    }

    /**
     * WHO THE "EMAIL ALL USERS" NOTICE GOES TO (2026-11-27, help sweep P0).
     *
     * It used to be every address in `users`, Bcc'd in batches of 40 with X-KT-Bypass-Suppression: test agencies
     * (#6, #18, #25, #26), deactivated and suspended people, closed homes (families #17/#80 on the closed Bruni
     * Meeser home) and integration accounts were all in it, and one held recipient cancelled a whole batch.
     *
     * Now one person = one message, and only people still with us:
     *   - the account is active (not invited, deactivated, suspended or deleted) and has a real mailbox;
     *   - they hold an active staff role in a live, NON-test agency (at an open centre when the role is placed at
     *     one), or are a guardian of a live family at an open centre of such an agency, or are a platform admin;
     *   - a test-only person (App\Support\TestAgency) never counts; one address is mailed once.
     * Each message is stamped with that person's agency (MailScope::agency), so SuppressAgencyMail applies that
     * agency's switches and every account gate, exactly as for the agency's own mail. The preview predicts what
     * the gate will hold (agency or centre email switched off); the send reports what it actually did.
     *
     * @return array{people: array<int,array{user_id:int,email:string,agency_id:?int}>, by_agency: array, held: int, excluded: array}
     */
    public static function audience(): array
    {
        $test = \App\Support\TestAgency::ids();
        $liveAgencies = DB::table('agencies')->whereNull('deleted_at')->pluck('name', 'id')->all();
        $users = DB::table('users')->whereNull('deleted_at')->whereNotNull('email')->where('email', '!=', '')
            ->orderBy('id')->get(['id', 'email', 'status']);

        $excluded = ['not_active' => 0, 'test_only' => 0, 'no_live_link' => 0, 'no_mailbox' => 0, 'same_address' => 0];
        $closed = \App\Support\Live::CLOSED_CENTRE_STATUSES;

        // Staff links: active staff role rows in a live non-test agency (a centre-placed row needs an open centre).
        $staff = [];
        $platform = [];
        $ra = DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.active', 1)
            ->get(['ra.user_id', 'ra.role', 'ra.agency_id', 'ra.centre_id', 'c.agency_id as cag', 'c.status as cstatus', 'c.deleted_at as cdel']);
        foreach ($ra as $r) {
            $uid = (int) $r->user_id;
            if ($r->role === 'platform_admin') {
                $platform[$uid] = true;
                continue;
            }
            // Guardian rows are judged by their family below; integration accounts never get mail.
            if (! in_array($r->role, \App\Support\Live::STAFF_ROLES, true)) {
                continue;
            }
            $ag = (int) ($r->agency_id ?: $r->cag);
            if (! $ag || ! isset($liveAgencies[$ag]) || in_array($ag, $test, true)) {
                continue;
            }
            if ($r->centre_id && ($r->cdel !== null || in_array((string) $r->cstatus, $closed, true))) {
                continue;
            }
            $staff[$uid][$ag] = true;
        }
        // Family links: guardian of a live family at an open centre of a live non-test agency.
        $fam = [];
        $gq = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')->whereNotNull('g.user_id');
        \App\Support\Live::families($gq, 'f');
        \App\Support\Live::centres($gq, 'c');
        foreach ($gq->get(['g.user_id', 'c.agency_id']) as $g) {
            $ag = (int) $g->agency_id;
            if (! $ag || ! isset($liveAgencies[$ag]) || in_array($ag, $test, true)) {
                continue;
            }
            $fam[(int) $g->user_id][$ag] = true;
        }

        $people = [];
        $seen = [];
        $byAgency = [];
        $held = 0;
        foreach ($users as $u) {
            $uid = (int) $u->id;
            $email = mb_strtolower(trim((string) $u->email));
            if ((string) $u->status !== 'active') { $excluded['not_active']++; continue; }
            if (\App\Support\TestAgency::isUser($uid)) { $excluded['test_only']++; continue; }
            $ags = array_keys(($staff[$uid] ?? []) + ($fam[$uid] ?? []));
            if (! $ags && empty($platform[$uid])) { $excluded['no_live_link']++; continue; }
            if (\App\Listeners\SuppressAgencyMail::isUndeliverable($email)) { $excluded['no_mailbox']++; continue; }
            if (isset($seen[$email])) { $excluded['same_address']++; continue; }
            $seen[$email] = true;
            sort($ags);
            // The agency whose switches govern this message: the one Suppression files them under, if it is live.
            $own = \App\Support\Suppression::agencyOfUser($uid);
            $ag = ($own && in_array((int) $own, $ags, true)) ? (int) $own : ($ags[0] ?? null);
            if (! empty($platform[$uid])) {
                $ag = null;   // platform staff hear about maintenance as platform staff
            }
            $willHold = $ag && (\App\Support\Suppression::agencyOff($uid) || \App\Support\Suppression::blockedByCentreRoom($uid));
            $people[] = ['user_id' => $uid, 'email' => $email, 'agency_id' => $ag];
            $k = $ag ? (string) $ag : 'platform';
            if (! isset($byAgency[$k])) {
                $byAgency[$k] = ['agency_id' => $ag, 'name' => $ag ? (string) $liveAgencies[$ag] : 'KiddieTrac platform staff', 'people' => 0, 'held' => 0];
            }
            $byAgency[$k]['people']++;
            if ($willHold) {
                $byAgency[$k]['held']++;
                $held++;
            }
        }
        $byAgency = array_values($byAgency);
        usort($byAgency, fn ($a, $b) => $b['people'] <=> $a['people']);

        return ['people' => $people, 'by_agency' => $byAgency, 'held' => $held, 'excluded' => $excluded];
    }

    /** GET: who "Email all users" would reach, by agency, before anything is sent. Sends nothing. */
    public function notifyPreview(Request $request): JsonResponse
    {
        $a = self::audience();
        $w = self::window();

        return response()->json([
            'total' => count($a['people']),
            'held' => $a['held'],
            'by_agency' => $a['by_agency'],
            'excluded' => $a['excluded'],
            'confirm_phrase' => 'EMAIL ' . count($a['people']),
            'notified_at' => $w->notified_at ?? null,
        ]);
    }

    /**
     * Email everyone still with us the maintenance heads-up: one message each, through the normal mail gate.
     * Needs the typed phrase from the preview ("EMAIL <count>"); if the count has changed since, it is refused,
     * so what the admin confirmed is what is sent. Who sent it and how many were sent / held / failed is audited.
     */
    public function notifyAll(Request $request): JsonResponse
    {
        $w = self::window();
        if (! $w) {
            return response()->json(['message' => 'Set up the maintenance window first.'], 422);
        }
        $a = self::audience();
        $phrase = 'EMAIL ' . count($a['people']);
        $typed = strtoupper(preg_replace('/\s+/', ' ', trim((string) $request->input('confirm', ''))));
        if ($typed !== $phrase) {
            return response()->json([
                'message' => 'Type ' . $phrase . ' to confirm. The number is how many people will be emailed right now.',
                'confirm_phrase' => $phrase,
            ], 422);
        }

        @set_time_limit(600);
        $sent = 0;
        $held = 0;
        $failed = 0;
        $byAgency = [];
        foreach ($a['people'] as $p) {
            $r = self::sendNotice($p['email'], $w, false, $p['agency_id']);
            $k = $p['agency_id'] ? (string) $p['agency_id'] : 'platform';
            $byAgency[$k] = $byAgency[$k] ?? ['sent' => 0, 'held' => 0, 'failed' => 0];
            $byAgency[$k][$r]++;
            if ($r === 'sent') { $sent++; } elseif ($r === 'held') { $held++; } else { $failed++; }
        }
        DB::table('maintenance_windows')->where('id', $w->id)->update(['notified_at' => now(), 'updated_at' => now()]);

        try {
            \App\Support\Audit::write([
                'user_id' => $request->user()->id ?? null,
                'action' => 'platform.maintenance.notified_all',
                'entity_type' => 'maintenance_window',
                'entity_id' => (int) $w->id,
                'payload' => json_encode(['audience' => count($a['people']), 'sent' => $sent, 'held' => $held,
                    'failed' => $failed, 'by_agency' => $byAgency, 'excluded' => $a['excluded']]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Maintenance notify audit failed: ' . $e->getMessage());
        }

        $msg = 'Heads-up emailed to ' . $sent . ' ' . ($sent === 1 ? 'person' : 'people') . '.';
        if ($held) {
            $msg .= ' ' . $held . ' held by the mail gate (email switched off for them).';
        }
        if ($failed) {
            $msg .= ' ' . $failed . ' could not be sent (see Email log).';
        }

        return response()->json(['message' => $msg, 'sent' => $sent, 'held' => $held, 'failed' => $failed]);
    }

    /**
     * Send the branded maintenance notice to ONE person and say what happened: 'sent', 'held' (the mail gate,
     * SuppressAgencyMail, cancelled it) or 'failed'.
     *
     * A tenant person's copy is stamped with their agency (MailScope::agency), so that agency's switches and every
     * account gate apply; platform staff and the test go as platform mail. Nothing here skips the test-agency rule.
     */
    private static function sendNotice(string $to, object $w, bool $isTest, ?int $agencyId): string
    {
        $to = trim($to);
        if ($to === '') {
            return 'failed';
        }

        $fmt = function ($v) {
            if (! $v) {
                return null;
            }
            try { return Carbon::parse($v)->format('D, M j, Y \a\t g:i A'); } catch (\Throwable $e) { return (string) $v; }
        };
        $start = $fmt($w->starts_at ?? null);
        $end = $fmt($w->ends_at ?? null);
        $when = $start ? ('<p><strong>When:</strong> ' . e($start) . ($end ? ' &ndash; ' . e($end) : '') . '</p>') : '';
        $msg = ! empty($w->message) ? '<p style="background:#EEF1F8;--kt-edge:#3BBBBE;padding:14px 16px;border-radius:6px;">' . nl2br(e($w->message)) . '</p>' : '';

        // Render through the shared branded layout (official logo + privacy/terms
        // footer). Sends from the configured noreply address.
        $content = '<h1>🛠️ Scheduled maintenance</h1>'
            . ($isTest ? '<p style="background:#FEF3C7;color:#92400E;border-radius:8px;padding:10px 14px;font-size:14px;">This is a <strong>test</strong> of the maintenance notice.</p>' : '')
            . '<p>Hello,</p>'
            . '<p>KiddieTrac will be temporarily unavailable for scheduled maintenance. During this time you won\'t be able to sign in. We\'re sorry for any inconvenience.</p>'
            . $when
            . $msg;

        try {
            $html = view('emails.layout', [
                'slot' => $content,
                'title' => 'Scheduled maintenance' . ($isTest ? ' (test)' : ''),
                'preheader' => 'KiddieTrac scheduled-maintenance notice',
            ])->render();
        } catch (\Throwable $e) {
            Log::warning('Maintenance layout render failed: ' . $e->getMessage());

            return 'failed';
        }

        try {
            $sentMsg = Mail::html($html, function ($m) use ($to, $isTest, $agencyId) {
                $m->from(config('mail.from.address', 'noreply@kiddietrac.com'), config('mail.from.name', 'KiddieTrac'));
                $sym = $m->getSymfonyMessage();
                if ($agencyId && ! $isTest) {
                    \App\Support\MailScope::agency($sym, $agencyId);
                } else {
                    \App\Support\MailScope::platform($sym);
                }
                if ($isTest) {
                    $sym->getHeaders()->addTextHeader('X-KT-Preview', '1');
                }
                $m->to($to)->subject(($isTest ? '[TEST] ' : '') . 'KiddieTrac — scheduled maintenance');
            });
        } catch (\Throwable $e) {
            Log::warning('Maintenance notice send failed: ' . $e->getMessage());

            return 'failed';
        }

        return $sentMsg === null ? 'held' : 'sent';
    }
}
