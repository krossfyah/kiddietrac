<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p51 — Time-off request workflow.
 * Staff submit; centre director / agency_admin approve / decline.
 * Approval auto-creates a "blocked" calendar marker so shifts can't be
 * scheduled over approved time off (handled in SchedulingController).
 */
final class TimeOffController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        $rows = DB::table('time_off_requests')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('start_at')
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function listAgency(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $status = $request->query('status');
        $q = DB::table('time_off_requests as tor')
            ->join('users as u', 'u.id', '=', 'tor.user_id')
            ->where('tor.agency_id', $agencyId)
            ->orderByDesc('tor.start_at')
            ->select(
                'tor.*', DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"), 'u.email as user_email',
                // decided_by_id and decided_at have always been written and never read
                // back — the id was returned raw by tor.* and no screen could turn it
                // into a person. The decision is half the record: who allowed it matters
                // as much as that it was allowed.
                DB::raw("TRIM(CONCAT(COALESCE(d.first_name,''), ' ', COALESCE(d.last_name,''))) as decided_by_name")
            )
            ->leftJoin('users as d', 'd.id', '=', 'tor.decided_by_id');
        if ($status) $q->where('tor.status', $status);
        return response()->json(['data' => $q->get()]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'request_type'  => 'required|string|in:vacation,sick,personal,bereavement,jury,other',
            'start_at'      => 'required|date',
            'end_at'        => 'required|date|after_or_equal:start_at',
            'reason'        => 'nullable|string|max:2000',
            'centre_id'     => 'nullable|integer',
            // Part of a day, for the appointments that do not need a whole one.
            'all_day'       => 'nullable|boolean',
            'start_time'    => 'nullable|date_format:H:i',
            'end_time'      => 'nullable|date_format:H:i|after:start_time',
        ]);

        // Part of a day only makes sense within ONE day. Asking for 2pm–4pm across a
        // fortnight has no meaning anyone could act on.
        $allDay = ! array_key_exists('all_day', $data) ? true : (bool) $data['all_day'];
        if (! $allDay) {
            if (substr((string) $data['start_at'], 0, 10) !== substr((string) $data['end_at'], 0, 10)) {
                return response()->json([
                    'message' => 'Part of a day has to be a single date. Use all day for a range.',
                ], 422);
            }
            if (empty($data['start_time']) || empty($data['end_time'])) {
                return response()->json([
                    'message' => 'Give a start and end time, or ask for the whole day.',
                ], 422);
            }
        }
        $user = $request->user();
        $agencyId = $this->resolveAgencyId($request);

        $id = DB::table('time_off_requests')->insertGetId([
            'agency_id'    => $agencyId,
            'user_id'      => $user->id,
            'centre_id'    => $data['centre_id'] ?? null,
            'request_type' => $data['request_type'],
            'start_at'     => $data['start_at'],
            'end_at'       => $data['end_at'],
            'reason'       => $data['reason'] ?? null,
            'all_day'      => $allDay,
            'start_time'   => $allDay ? null : $data['start_time'],
            'end_time'     => $allDay ? null : $data['end_time'],
            'status'       => 'pending',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Centre directors and agency admins are the people who can act on this, so
        // they get both a bell and an email. A bell alone waits for them to open the
        // app, and the request waits with it.
        $approvers = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.agency_id', $agencyId)
            ->whereIn('ra.role', ['centre_director', 'agency_admin'])
            ->where('ra.active', true)
            ->whereNull('u.deleted_at')
            ->select('u.id', 'u.email', 'u.first_name', 'u.last_name')
            ->distinct()->get();

        // $user->name is not a thing on this model — no `name` column and no accessor for
        // it, only getFullNameAttribute. It used to be interpolated here and produced
        // "New time-off request from " with nothing after it.
        $who = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'A team member';
        $fresh = DB::table('time_off_requests')->where('id', $id)->first();
        $when = self::whenPhrase($fresh);
        // The year belongs in anything someone reads weeks later — a request for
        // "Sep 3" in an inbox in December is genuinely ambiguous.
        $whenFull = $when . ', ' . Carbon::parse($data['start_at'])->format('Y');

        foreach ($approvers as $ap) {
            \App\Support\Notify::write([
                'user_id'    => $ap->id,
                'type'       => 'time_off',
                'title'      => 'New time-off request from ' . $who,
                'body'       => $data['request_type'] . ' · ' . $when,
                'data' => json_encode(['link' => '#time-off']),
                'created_at' => now(),
            ]);

            $this->mailApprover($agencyId, $ap, $who, (string) $data['request_type'], $whenFull,
                $data['reason'] ?? null, (int) $id);
        }

        return response()->json(['id' => $id, 'status' => 'pending'], 201);
    }

    /**
     * Close the centre for approved leave, but only when the person IS the centre.
     *
     * A home provider working alone has no cover — their time off shuts the doors, and
     * every family needs to know well in advance. An educator at a staffed centre does
     * not: somebody covers the room and nothing changes for parents. Emailing all of
     * them "we are closed" because one of nine educators booked a holiday is far worse
     * than sending nothing, so the test is deliberately strict — sole ACTIVE staff at
     * that centre, and nobody else.
     *
     * Writes a centre_closures row, which is what ClosureReminderCommand reads; the
     * lead times and the on/off switch stay under the agency's own closure settings, so
     * this changes what gets announced, not how.
     *
     * @return int|null the centre closed, or null if nothing was closed
     */
    private function closeCentreIfSoleStaff($row, int $actorId): ?int
    {
        // Away for part of a day is not a closure. The provider is still there for the
        // rest of it, and telling every family the centre is shut because of a two-hour
        // appointment is worse than telling them nothing.
        if (empty($row->all_day)) {
            return null;
        }

        // The request's own centre if it carries one; otherwise the single centre this
        // person is staff at. Somebody attached to several centres is by definition not
        // the sole staff member of one, so there is nothing to close.
        $centreId = (int) ($row->centre_id ?? 0);
        if (! $centreId) {
            $centreIds = DB::table('role_assignments')
                ->where('user_id', $row->user_id)->where('active', true)
                ->whereNotNull('centre_id')->distinct()->pluck('centre_id');
            if ($centreIds->count() !== 1) {
                return null;
            }
            $centreId = (int) $centreIds->first();
        }

        // Sole staff, or someone covers.
        $staffCount = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.centre_id', $centreId)->where('ra.active', true)
            ->whereIn('ra.role', ['educator', 'centre_director'])
            ->whereNull('u.deleted_at')
            ->distinct()->count('ra.user_id');
        if ($staffCount > 1) {
            return null;
        }

        // Already closed for these dates — someone entered it by hand, which is exactly
        // what people have been doing. Do not stack a second closure on top.
        $exists = DB::table('centre_closures')
            ->where('centre_id', $centreId)
            ->whereDate('closure_date', '<=', $row->end_at)
            ->where(function ($q) use ($row) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $row->start_at);
            })
            ->exists();
        if ($exists) {
            return null;
        }

        $who = DB::table('users')->where('id', $row->user_id)
            ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")
            ->value('n');

        try {
            DB::table('centre_closures')->insert([
                'centre_id' => $centreId,
                'closure_date' => $row->start_at,
                'end_date' => $row->end_at,
                'closure_type' => 'staff_leave',
                'reason' => trim(($who ?: 'The provider').' away'
                    .(($row->request_type ?? '') ? ' — '.str_replace('_', ' ', (string) $row->request_type) : '')),
                // Whether a closed day is billable is an agency policy decision, not
                // something to assume from a leave request. Left off; it is editable
                // on the closure like any other.
                'affects_billing' => 0,
                'created_by_id' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // A closure that fails to write must not fail the approval — the leave was
            // still granted, and the person is still off.
            \Illuminate\Support\Facades\Log::warning('Time-off closure not created', [
                'request' => $row->id, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $centreId;
    }

    /**
     * How a request reads to a human: "3–5 Sep", or "21 Aug, 2:00 PM – 4:00 PM".
     *
     * One place, because the request email, the decision email, the withdrawal email and
     * the calendar all have to say the same thing. They said it three different ways
     * before the times existed and would have said it four afterwards.
     */
    public static function whenPhrase($row): string
    {
        $start = Carbon::parse($row->start_at);
        $end = Carbon::parse($row->end_at);
        $sameDay = $start->toDateString() === $end->toDateString();

        if (! empty($row->all_day) || empty($row->start_time)) {
            return $sameDay ? $start->format('M j') : $start->format('M j').' – '.$end->format('M j');
        }

        $t = fn ($v) => Carbon::parse(substr((string) $v, 0, 8))->format('g:i A');

        return $start->format('M j').', '.$t($row->start_time).' – '.$t($row->end_time);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:approved,denied',
            'decision_notes' => 'nullable|string|max:1000',
        ]);
        $row = DB::table('time_off_requests')->where('id', $id)->first();
        abort_unless($row, 404);
        $this->assertAgencyAccess($request, (int) $row->agency_id);

        $result = $this->applyDecision($row, $data['status'], $data['decision_notes'] ?? null, $request->user());

        return response()->json($result);
    }

    /**
     * Record a decision and do everything that follows from it.
     *
     * Extracted so the one-tap buttons in the approver's email run exactly the same
     * path as the portal — the closure, the bell, the emails. Two code paths that both
     * "approve time off" is how they drift until one of them quietly stops closing the
     * centre.
     */
    private function applyDecision(object $row, string $status, ?string $notes, $actor): array
    {
        DB::table('time_off_requests')->where('id', $row->id)->update([
            'status'        => $status,
            'decided_by_id' => $actor->id,
            'decided_at'    => now(),
            'decision_notes'=> $notes,
            'updated_at'    => now(),
        ]);

        $data = ['status' => $status, 'decision_notes' => $notes];
        $when = self::whenPhrase($row);

        \App\Support\Notify::write([
            'user_id'    => $row->user_id,
            'type'       => 'time_off',
            'title'      => 'Your time-off request was ' . $data['status'],
            'body'       => $when . (! empty($data['decision_notes']) ? ' · ' . $data['decision_notes'] : ''),
            'data' => json_encode(['link' => '#time-off']),
            'created_at' => now(),
        ]);

        // Emailed as well as belled. Somebody who asked for leave is waiting on this to
        // book something, and "check the app occasionally" is not an answer.
        $this->mailDecision($row, (string) $status, $when, $notes, $actor);

        // A home provider IS the centre, so approving their leave closes it — and the
        // parents need telling on the usual reminder schedule.
        $closure = null;
        if ($status === 'approved') {
            $closure = $this->closeCentreIfSoleStaff($row, $actor->id);
            // Told now, not only 5 days before it happens. The scheduled reminders still
            // run on top of this — this is the confirmation, those are the nudges.
            $this->announceApproval($row, $closure, $actor);
        }

        return [
            'status' => $status,
            'closure_created' => (bool) $closure,
            'closure_centre_id' => $closure,
        ];
    }

    /**
     * Tell an approver a request is waiting.
     *
     * Never lets a mail failure fail the request itself — the time-off row is already
     * saved and the bell is already written, and a bounced address for one director must
     * not hand the person requesting leave a 500.
     */
    private function mailApprover(?int $agencyId, object $approver, string $who, string $type, string $when, ?string $reason, ?int $requestId = null): void
    {
        if (! filter_var((string) $approver->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        if (\App\Support\Suppression::isUser((int) $approver->id)) {
            return;
        }

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $reasonRow = $reason
            ? '<tr><td style="padding:10px 0 0;font-size:14px;line-height:1.6;color:#334155;">'
              . '<strong>Reason given:</strong> ' . $e($reason) . '</td></tr>'
            : '';

        $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            . '<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">'
            . $e($who) . ' has requested time off and is waiting on a decision.</td></tr>'
            . '<tr><td style="padding:6px 0;"><div style="background:#F1F5F9;border-radius:10px;padding:14px 16px;'
            . 'font-size:15px;color:#0F172A;"><strong>' . $e(ucfirst($type)) . '</strong><br>' . $e($when) . '</div></td></tr>'
            . $reasonRow
            . $this->quickButtons($requestId, (int) $approver->id)
            . '<tr><td style="padding:16px 0 0;font-size:14px;line-height:1.6;color:#64748B;">'
            . 'Or handle it under <strong>Time off</strong> in KiddieTrac.</td></tr></table>';

        try {
            $html = EmailTemplate::wrap($agencyId, $body, [
                'eyebrow' => 'ACTION NEEDED',
                'title' => 'Time-off request from ' . $who,
                'subtitle' => ucfirst($type) . ' · ' . $when,
                'preheader' => $who . ' requested ' . $type . ' for ' . $when,
            ]);
            $name = trim(($approver->first_name ?? '') . ' ' . ($approver->last_name ?? ''));
            // The dates go in the SUBJECT. An inbox full of "Time-off request from
            // Natasha" cannot be triaged without opening every one of them.
            $subject = 'Time off: ' . $who . ' — ' . $when;
            AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($approver, $name, $subject) {
                $m->to($approver->email, $name ?: null)->subject($subject);
            });
        } catch (\Throwable $ex) {
            \Illuminate\Support\Facades\Log::warning('Time-off approver email failed', [
                'approver' => $approver->id, 'error' => $ex->getMessage(),
            ]);
        }
    }

    /** Tell the requester what was decided, and by whom. */
    /**
     * The two buttons. Both open the same page; the one you tapped is only a hint at
     * which way you were leaning, because the decision is made on that page, not by the
     * link. That is what stops a mail scanner from deciding on your behalf.
     */
    private function quickButtons(?int $requestId, int $approverId): string
    {
        if (! $requestId) {
            return '';
        }
        try {
            $mk = fn ($a) => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'timeoff.act', now()->addDays(14), ['id' => $requestId, 'u' => $approverId, 'a' => $a]);
            $approve = $mk('approved');
            $decline = $mk('denied');
        } catch (\Throwable $e) {
            return '';
        }

        $btn = fn ($url, $label, $bg, $fg, $border) =>
            '<a href="' . $url . '" style="display:block;padding:14px 18px;border-radius:12px;'
            . 'background:' . $bg . ';color:' . $fg . ';border:' . $border . ';text-decoration:none;'
            . 'font-size:16px;font-weight:700;text-align:center;">' . $label . '</a>';

        return '<tr><td style="padding:18px 0 0;">'
            . '<div style="font-size:13px;color:#64748B;margin-bottom:10px;">Decide from here:</div>'
            . $btn($approve, 'Approve', '#16A34A', '#ffffff', '0')
            . '<div style="height:10px;line-height:10px;"> </div>'
            . $btn($decline, 'Decline', '#ffffff', '#B91C1C', '1px solid #FCA5A5')
            . '<div style="font-size:12px;color:#94A3B8;margin-top:10px;">'
            . 'You can add a note for them on the next screen.</div>'
            . '</td></tr>';
    }

    /**
     * GET — the page the email button opens. DISPLAYS ONLY.
     *
     * Mail scanners fetch every link in a message before it is delivered, so this must
     * not change anything. It shows the request and offers two buttons that POST.
     */
    public function actPage(Request $request, int $id)
    {
        $row = DB::table('time_off_requests')->where('id', $id)->first();
        $approver = $row ? DB::table('users')->where('id', (int) $request->query('u'))->first() : null;

        if (! $row || ! $approver) {
            return response($this->actShell('That request could not be found.',
                'It may have been removed.'), 404)->header('Content-Type', 'text/html');
        }

        // Re-checked at the moment of use, not only when the mail was sent. Somebody who
        // has since left the agency must not still be able to decide from an old email.
        if (! $this->isApproverFor((int) $approver->id, (int) $row->agency_id)) {
            return response($this->actShell('You cannot action this request.',
                'This link belongs to someone who no longer approves time off for this agency.'), 403)
                ->header('Content-Type', 'text/html');
        }

        if ($row->status !== 'pending') {
            $by = DB::table('users')->where('id', $row->decided_by_id)
                ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")->value('n');

            return response($this->actShell('Already '.e($row->status).'.',
                'This was '.e($row->status).($by ? ' by '.e($by) : '').
                ($row->decided_at ? ' on '.Carbon::parse($row->decided_at)->format('j M Y') : '').
                '. Nothing more to do.'), 200)->header('Content-Type', 'text/html');
        }

        $who = DB::table('users')->where('id', $row->user_id)
            ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")->value('n') ?: 'A team member';
        $action = $request->query('a') === 'denied' ? 'denied' : 'approved';

        return response($this->actForm($request, $row, $who, $action), 200)
            ->header('Content-Type', 'text/html');
    }

    /** POST — the actual decision, from a button on that page. */
    public function actSubmit(Request $request, int $id)
    {
        $row = DB::table('time_off_requests')->where('id', $id)->first();
        $approver = $row ? DB::table('users')->where('id', (int) $request->query('u')) ->first() : null;
        if (! $row || ! $approver || ! $this->isApproverFor((int) $approver->id, (int) $row->agency_id)) {
            return response($this->actShell('You cannot action this request.', ''), 403)
                ->header('Content-Type', 'text/html');
        }
        if ($row->status !== 'pending') {
            return response($this->actShell('Already decided.',
                'Someone got to it first — no change made.'), 200)->header('Content-Type', 'text/html');
        }

        $status = $request->input('decision') === 'denied' ? 'denied' : 'approved';
        $notes = trim((string) $request->input('decision_notes', '')) ?: null;

        $res = $this->applyDecision($row, $status, $notes, $approver);

        $msg = $status === 'approved' ? 'Approved.' : 'Declined.';
        $sub = 'We have let them know'.($notes ? ', along with your note' : '').'.'
            .(! empty($res['closure_created']) ? ' The centre is now marked closed for those dates and parents will be told.' : '');

        return response($this->actShell($msg, $sub, $status === 'approved' ? '#16A34A' : '#B91C1C'), 200)
            ->header('Content-Type', 'text/html');
    }

    private function isApproverFor(int $userId, int $agencyId): bool
    {
        return DB::table('role_assignments')->where('user_id', $userId)
            ->where('agency_id', $agencyId)
            ->whereIn('role', ['centre_director', 'agency_admin', 'platform_admin'])
            ->where('active', true)->exists();
    }

    /** A plain result page. Deliberately tiny — it is read on a phone, once. */
    private function actShell(string $title, string $sub, string $accent = '#1F6080'): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        return '<!doctype html><html><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.$e($title).'</title></head>'
            .'<body style="margin:0;font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#F1F5F9;">'
            .'<div style="max-width:460px;margin:0 auto;padding:48px 20px;">'
            .'<div style="background:#fff;border-radius:16px;padding:32px 24px;text-align:center;'
            .'box-shadow:0 2px 12px rgba(15,23,42,.08);">'
            .'<h1 style="margin:0 0 10px;font-size:22px;color:'.$accent.';">'.$e($title).'</h1>'
            .($sub ? '<p style="margin:0;font-size:15px;line-height:1.6;color:#475569;">'.$sub.'</p>' : '')
            .'</div></div></body></html>';
    }

    /** The decision form. */
    private function actForm(Request $request, object $row, string $who, string $action): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $when = self::whenPhrase($row).', '.Carbon::parse($row->start_at)->format('Y');
        // Same signature, so the POST is as protected as the GET was.
        $post = $request->fullUrl();

        return '<!doctype html><html><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Time-off request from '.$e($who).'</title></head>'
            .'<body style="margin:0;font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#F1F5F9;">'
            .'<div style="max-width:460px;margin:0 auto;padding:32px 16px;">'
            .'<div style="background:#fff;border-radius:16px;padding:24px;box-shadow:0 2px 12px rgba(15,23,42,.08);">'
            .'<div style="font-size:11.5px;font-weight:800;letter-spacing:.08em;color:#94A3B8;'
            .'text-transform:uppercase;margin-bottom:6px;">Time-off request</div>'
            .'<h1 style="margin:0 0 4px;font-size:21px;color:#0F172A;">'.$e($who).'</h1>'
            .'<div style="font-size:15px;color:#334155;margin-bottom:16px;">'
            .$e(ucfirst(str_replace('_', ' ', (string) $row->request_type))).' &middot; '.$e($when).'</div>'
            .($row->reason
                ? '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:12px 14px;'
                  .'font-size:14px;line-height:1.6;color:#334155;margin-bottom:16px;white-space:pre-wrap;">'
                  .'<strong>Reason given:</strong> '.$e($row->reason).'</div>'
                : '')
            .'<form method="POST" action="'.$e($post).'">'
            .'<label style="display:block;font-size:13px;font-weight:700;color:#334155;margin-bottom:5px;">'
            .'Add a note back to '.$e(explode(' ', $who)[0]).' (optional)</label>'
            .'<textarea name="decision_notes" rows="3" placeholder="Anything they should know…" '
            .'style="width:100%;box-sizing:border-box;padding:11px;border:1px solid #CBD5E1;border-radius:10px;'
            .'font-family:inherit;font-size:15px;margin-bottom:16px;"></textarea>'
            .'<button type="submit" name="decision" value="approved" '
            .'style="width:100%;padding:15px;border:0;border-radius:12px;background:#16A34A;color:#fff;'
            .'font-size:16px;font-weight:700;cursor:pointer;margin-bottom:10px;">Approve</button>'
            .'<button type="submit" name="decision" value="denied" '
            .'style="width:100%;padding:15px;border:1px solid #FCA5A5;border-radius:12px;background:#fff;'
            .'color:#B91C1C;font-size:16px;font-weight:700;cursor:pointer;">Decline</button>'
            .'</form>'
            .'<p style="font-size:12px;color:#94A3B8;margin:16px 0 0;text-align:center;">'
            .'This link is personal to you and expires in 14 days.</p>'
            .'</div></div></body></html>';
    }

    /**
     * Everybody who needs to know, told at the moment of approval.
     *
     * Three letters rather than one, because the same fact lands differently: a parent
     * needs to arrange care, an educator needs to know the rota moved, an admin needs
     * the record. One generic email to all three says nothing useful to any of them.
     *
     * Parents hear ONLY when the centre actually closes. Leave taken by one of several
     * educators changes nothing for a family, and telling them it does is alarming and
     * untrue.
     *
     * @param int|null $closedCentreId the centre this closed, or null if it closed none
     */
    private function announceApproval(object $row, ?int $closedCentreId, $actor): void
    {
        $when = self::whenPhrase($row).', '.Carbon::parse($row->start_at)->format('Y');
        $who = DB::table('users')->where('id', $row->user_id)
            ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")
            ->value('n') ?: 'A team member';
        $centreId = $closedCentreId ?: (int) ($row->centre_id ?? 0);
        $centreName = $centreId ? DB::table('centres')->where('id', $centreId)->value('name') : null;
        $agencyId = (int) $row->agency_id;
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $panel = function (string $tint, string $accent, string $label, string $line) use ($e) {
            return '<tr><td style="padding:6px 0;"><div style="background:'.$tint.';border-radius:10px;'
                .'padding:14px 16px;font-size:15px;color:#0F172A;"><strong style="color:'.$accent.';'
                .'text-transform:uppercase;font-size:12px;letter-spacing:.06em;">'.$e($label).'</strong><br>'
                .'<span style="font-size:15px;">'.$e($line).'</span></div></td></tr>';
        };

        $send = function (array $addresses, string $subject, string $body, array $meta) use ($agencyId, $centreId) {
            $addresses = array_values(array_unique(array_filter($addresses,
                fn ($a) => filter_var((string) $a, FILTER_VALIDATE_EMAIL))));
            if (! $addresses) {
                return;
            }
            try {
                $html = EmailTemplate::wrap($agencyId, $body, $meta);
            } catch (\Throwable $e2) {
                return;
            }
            // One blind copy per batch for the director / agency admin. Deduped, so the
            // admin block below never copies them on their own message.
            $bcc = \App\Support\MailOversight::bccFor($agencyId, $centreId ?: null, $addresses);
            foreach ($addresses as $__i => $addr) {
                $copyTo = \App\Support\MailOversight::firstOnly($bcc, $__i);
                try {
                    \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($addr, $subject, $copyTo) {
                        $m->to($addr)->subject($subject);
                        if ($copyTo) {
                            $m->bcc($copyTo);
                        }
                        // A closure is operational: it must arrive even where an agency
                        // has bulk notifications switched off.
                        $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                    });
                } catch (\Throwable $ex) {
                    \Illuminate\Support\Facades\Log::warning('Time-off approval email failed', [
                        'to' => $addr, 'error' => $ex->getMessage(),
                    ]);
                }
            }
        };

        // ── PARENTS — only when the doors are actually shut ─────────────────
        if ($closedCentreId) {
            $familyIds = DB::table('families')->where('centre_id', $closedCentreId)
                ->whereNull('deleted_at')->pluck('id');
            $parents = DB::table('users as u')->join('guardians as g', 'g.user_id', '=', 'u.id')
                ->whereIn('g.family_id', $familyIds)->whereNull('u.deleted_at')
                ->distinct()->pluck('u.email')->all();

            $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
                .'<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">'
                .'We wanted to let you know as early as we can that '
                .($centreName ? '<strong>'.$e($centreName).'</strong>' : 'your centre')
                .' will be closed on the date below.</td></tr>'
                .$panel('#FEF3C7', '#B45309', 'Closed', $when)
                // The thing a parent is actually worried about, answered before they have
                // to ask it.
                .'<tr><td style="padding:16px 0 0;font-size:15px;line-height:1.6;color:#334155;">'
                .'Your child’s place with us is completely unaffected. We will see them as usual on '
                .'the next opening day and everything carries on exactly as before.</td></tr>'
                .'<tr><td style="padding:14px 0 0;font-size:15px;line-height:1.6;color:#334155;">'
                .'We know a closure is not easy to plan around, especially at short notice, and we are '
                .'sorry for the disruption to your week. If it leaves you stuck for care, please just '
                .'reply to this email — we will help however we can.</td></tr>'
                .'<tr><td style="padding:16px 0 0;font-size:14px;line-height:1.6;color:#64748B;">'
                .'We will send a gentle reminder closer to the time. Thank you for your understanding.'
                .'</td></tr></table>';

            $send($parents, 'We will be closed on '.$when.($centreName ? ' — '.$centreName : ''), $body, [
                'eyebrow' => 'CENTRE CLOSURE',
                'title' => 'We will be closed on '.$when,
                'subtitle' => $centreName ?: '',
                // What they need to do, in the line the inbox shows before opening it.
                'preheader' => 'Your child’s place is unaffected — but you will need care for '.$when.'.',
            ]);
        }

        // ── EDUCATORS — the rota, not the doors ─────────────────────────────
        if ($centreId) {
            $educators = DB::table('users as u')->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
                ->where('ra.centre_id', $centreId)->where('ra.active', true)
                ->whereIn('ra.role', ['educator', 'home_visitor'])
                ->whereNull('u.deleted_at')->where('u.id', '!=', $row->user_id)
                ->distinct()->pluck('u.email')->all();

            $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
                .'<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">'
                .'<strong>'.$e($who).'</strong> has approved time off, so they will not be in on these dates.'
                .'</td></tr>'
                .$panel('#E0F2FE', '#075985', 'Away', $when)
                .'<tr><td style="padding:14px 0 0;font-size:14px;line-height:1.6;color:#334155;">'
                .($closedCentreId
                    ? 'The centre is closed for these dates, so no cover is needed.'
                    : 'Please check the rota — cover may need arranging.')
                .'</td></tr>'
                .'<tr><td style="padding:14px 0 0;font-size:14px;line-height:1.6;color:#64748B;">'
                .'It is on the staff calendar in KiddieTrac.</td></tr></table>';

            $send($educators, $who.' is away — '.$when, $body, [
                'eyebrow' => 'ROTA CHANGE',
                'title' => $who.' is away',
                'subtitle' => $when,
                'preheader' => $who.' is off on '.$when.'.',
            ]);
        }

        // ── ADMIN AND DIRECTORS — the record of what it did ─────────────────
        $admins = DB::table('users as u')->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->where('ra.agency_id', $agencyId)->where('ra.active', true)
            ->whereIn('ra.role', ['agency_admin', 'centre_director'])
            ->whereNull('u.deleted_at')
            ->distinct()->pluck('u.email')->all();

        $parentCount = $closedCentreId
            ? DB::table('users as u')->join('guardians as g', 'g.user_id', '=', 'u.id')
                ->whereIn('g.family_id', DB::table('families')->where('centre_id', $closedCentreId)
                    ->whereNull('deleted_at')->pluck('id'))
                ->whereNull('u.deleted_at')->distinct()->count('u.id')
            : 0;

        $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            .'<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">'
            .'<strong>'.$e($who).'</strong>’s time off was approved'
            .(trim(($actor->first_name ?? '').' '.($actor->last_name ?? ''))
                ? ' by '.$e(trim(($actor->first_name ?? '').' '.($actor->last_name ?? ''))) : '')
            .'.</td></tr>'
            .$panel('#DCFCE7', '#15803D', 'Approved', $when)
            .'<tr><td style="padding:14px 0 0;font-size:14px;line-height:1.6;color:#334155;">'
            .($closedCentreId
                ? ($centreName ? $e($centreName) : 'The centre').' is now marked <strong>closed</strong> for these dates and '
                  .$parentCount.' parent'.($parentCount === 1 ? '' : 's').' '
                  .($parentCount ? 'have been told' : 'were found to tell')
                  .'. Reminders follow on the usual schedule.'
                : 'No closure was created — the centre has other staff, so cover applies.')
            .'</td></tr></table>';

        $send($admins, 'Approved: '.$who.' off '.$when, $body, [
            'eyebrow' => 'TIME OFF APPROVED',
            'title' => $who.'’s time off approved',
            'subtitle' => $when,
            'preheader' => $who.' approved for '.$when
                .($closedCentreId ? ' — centre closed, parents notified.' : '.'),
        ]);
    }

    /**
     * Tell people the leave is off and the day is going ahead as normal.
     *
     * Approvers always hear: they made a decision that no longer stands, and the rota
     * may have been built around it.
     *
     * Parents hear ONLY if a closure reminder had already reached them. If nobody was
     * ever told the centre would be shut, an email saying it is open after all describes
     * a closure they never knew about — which is worse than saying nothing.
     */
    private function announceWithdrawal(object $row, $actor, ?int $reopenedCentreId, bool $parentsHadBeenTold): void
    {
        $name = trim((($actor->first_name ?? '').' '.($actor->last_name ?? ''))) ?: 'A team member';
        $when = self::whenPhrase($row);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            .'<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">'
            .$e($name).' has withdrawn their time-off request. They will be working as usual.</td></tr>'
            .'<tr><td style="padding:6px 0;"><div style="background:#DCFCE7;border-radius:10px;padding:14px 16px;'
            .'font-size:15px;color:#0F172A;"><strong style="color:#16A34A;text-transform:uppercase;font-size:12px;'
            .'letter-spacing:.06em;">Working as normal</strong><br>'
            .'<span style="font-size:15px;">'.$e($when).'</span></div></td></tr>'
            .($reopenedCentreId
                ? '<tr><td style="padding:14px 0 0;font-size:14px;line-height:1.6;color:#334155;">'
                  .'The closure for these dates has been removed and the calendar is back to normal.</td></tr>'
                : '')
            .'<tr><td style="padding:16px 0 0;font-size:14px;line-height:1.6;color:#64748B;">'
            .'Withdrawn on '.now()->format('j M Y').'. No action is needed.</td></tr></table>';

        $html = EmailTemplate::wrap((int) $row->agency_id, $body, [
            'eyebrow' => 'TIME OFF WITHDRAWN',
            'title' => $name.' will be in after all',
            'subtitle' => $when,
            'preheader' => $name.' has withdrawn their time off for '.$when.' and will be working as usual.',
        ]);
        $subject = $name.' will be in after all — '.$when;

        $to = [];

        // The approvers.
        foreach (DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.agency_id', $row->agency_id)
            ->whereIn('ra.role', ['centre_director', 'agency_admin'])
            ->where('ra.active', true)->whereNull('u.deleted_at')
            ->distinct()->pluck('u.email') as $addr) {
            $to[] = $addr;
        }

        // Colleagues at the centre, who may have been covering.
        if ($row->centre_id || $reopenedCentreId) {
            foreach (DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.centre_id', $reopenedCentreId ?: $row->centre_id)
                ->where('ra.role', 'educator')->where('ra.active', true)
                ->whereNull('u.deleted_at')->where('u.id', '!=', $row->user_id)
                ->distinct()->pluck('u.email') as $addr) {
                $to[] = $addr;
            }
        }

        // Parents — only where the closure had already been announced to them.
        if ($reopenedCentreId && $parentsHadBeenTold) {
            $familyIds = DB::table('families')->where('centre_id', $reopenedCentreId)
                ->whereNull('deleted_at')->pluck('id');
            foreach (DB::table('users as u')->join('guardians as g', 'g.user_id', '=', 'u.id')
                ->whereIn('g.family_id', $familyIds)->whereNull('u.deleted_at')
                ->distinct()->pluck('u.email') as $addr) {
                $to[] = $addr;
            }
        }

        foreach (array_unique(array_filter($to, fn ($a) => filter_var((string) $a, FILTER_VALIDATE_EMAIL))) as $addr) {
            try {
                \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($addr, $subject) {
                    $m->to($addr)->subject($subject);
                    // A rota change people need to act on, not bulk news.
                    $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                });
            } catch (\Throwable $ex) {
                \Illuminate\Support\Facades\Log::warning('Withdrawal email failed', [
                    'to' => $addr, 'error' => $ex->getMessage(),
                ]);
            }
        }
    }

    private function mailDecision(object $row, string $status, string $when, ?string $notes, $decider): void
    {
        $u = DB::table('users')->where('id', $row->user_id)->first();
        if (! $u || ! filter_var((string) $u->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        if (\App\Support\Suppression::isUser((int) $u->id)) {
            return;
        }

        $approved = $status === 'approved';
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $by = trim((($decider->first_name ?? '') . ' ' . ($decider->last_name ?? ''))) ?: 'your centre';
        $accent = $approved ? '#16A34A' : '#B91C1C';
        $tint = $approved ? '#DCFCE7' : '#FEE2E2';

        // A declined request needs the reason more than an approved one does, but both
        // carry it when there is one — a decision with no explanation invites a second
        // request for the same dates.
        $notesRow = $notes
            ? '<tr><td style="padding:12px 0 0;font-size:14px;line-height:1.6;color:#334155;">'
              . '<strong>Note from ' . $e($by) . ':</strong> ' . $e($notes) . '</td></tr>'
            : '';

        $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            . '<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">'
            . 'Your time-off request has been ' . ($approved ? 'approved' : 'declined') . '.</td></tr>'
            . '<tr><td style="padding:6px 0;"><div style="background:' . $tint . ';border-radius:10px;padding:14px 16px;'
            . 'font-size:15px;color:#0F172A;"><strong style="color:' . $accent . ';text-transform:uppercase;'
            . 'font-size:12px;letter-spacing:.06em;">' . ($approved ? 'Approved' : 'Declined') . '</strong><br>'
            . '<span style="font-size:15px;">' . $e(ucfirst((string) $row->request_type)) . ' · ' . $e($when)
            . '</span></div></td></tr>'
            . $notesRow
            . '<tr><td style="padding:16px 0 0;font-size:14px;line-height:1.6;color:#64748B;">'
            . 'Decided by ' . $e($by) . ' on ' . now()->format('j M Y') . '.'
            . ($approved ? ' It now shows on the staff calendar.' : ' Speak to your centre if you need to discuss it.')
            . '</td></tr></table>';

        try {
            $html = EmailTemplate::wrap((int) $row->agency_id, $body, [
                'eyebrow' => $approved ? 'APPROVED' : 'DECLINED',
                'title' => 'Your time-off request was ' . ($approved ? 'approved' : 'declined'),
                'subtitle' => $when,
                'preheader' => 'Your time off for ' . $when . ' was ' . ($approved ? 'approved' : 'declined'),
            ]);
            $name = trim((($u->first_name ?? '') . ' ' . ($u->last_name ?? '')));
            AgencyMailer::forAgency((int) $row->agency_id)->html($html, function ($m) use ($u, $name, $approved) {
                $m->to($u->email, $name ?: null)
                  ->subject('Your time-off request was ' . ($approved ? 'approved' : 'declined'));
            });
        } catch (\Throwable $ex) {
            \Illuminate\Support\Facades\Log::warning('Time-off decision email failed', [
                'user' => $u->id, 'error' => $ex->getMessage(),
            ]);
        }
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $row = DB::table('time_off_requests')->where('id', $id)->first();
        abort_unless($row, 404);
        abort_unless((int) $row->user_id === (int) $request->user()->id, 403, 'Only the requester can cancel');

        // An APPROVED request can be withdrawn too. Plans change, and the alternative
        // was asking an admin to edit the database. Only a request that has already
        // happened, or been declined, is beyond taking back.
        abort_unless(in_array((string) $row->status, ['pending', 'approved'], true), 422,
            'That request can no longer be withdrawn.');

        $wasApproved = (string) $row->status === 'approved';

        DB::table('time_off_requests')->where('id', $id)->update([
            'status' => 'cancelled', 'updated_at' => now(),
        ]);

        // Put the calendar back. Only a closure this system created for exactly these
        // dates is removed — anything typed in by hand is somebody's deliberate entry
        // and is not ours to delete.
        $reopened = null;
        $parentsHadBeenTold = false;
        if ($wasApproved) {
            $closure = DB::table('centre_closures')
                ->where('closure_type', 'staff_leave')
                ->whereDate('closure_date', $row->start_at)
                ->whereDate('end_date', $row->end_at)
                ->when($row->centre_id, fn ($q) => $q->where('centre_id', $row->centre_id))
                ->first();

            if ($closure) {
                // Did anyone outside actually hear about this? reminders_sent lists the
                // lead times already emailed. Empty means the closure never left the
                // building, and there is nothing to correct.
                $parentsHadBeenTold = trim((string) ($closure->reminders_sent ?? '')) !== '';
                DB::table('centre_closures')->where('id', $closure->id)->delete();
                $reopened = (int) $closure->centre_id;
            }
        }

        $this->announceWithdrawal($row, $request->user(), $reopened, $parentsHadBeenTold);

        return response()->json([
            'status' => 'cancelled',
            'calendar_reverted' => (bool) $reopened,
            'parents_notified' => $parentsHadBeenTold,
        ]);
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        // SECURITY (v22p94): only honour the header if the user is platform_admin
        // or holds an active role for that exact agency (else fall back below).
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->where(function ($w) use ($activeId) { $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin'); })->exists()) return $activeId;
        // SECURITY (v22p98): a platform_admin with no valid SELECTED agency must NOT
        // fall through to their first role's agency (iLearn) — require an explicit
        // choice, else agency-scoped data leaked to a super-admin on a header-less call.
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400, 'No agency context');
        return (int) $first;
    }

    private function assertAgencyAccess(Request $request, int $agencyId): void
    {
        $u = $request->user();
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) return;
        $hasRole = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('agency_id', $agencyId)->whereIn('role', ['agency_admin', 'centre_director'])
            ->where('active', true)->exists();
        abort_unless($hasRole, 403);
    }
}
