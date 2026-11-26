<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p59 — Help-desk tickets (separate from chat).
 * Any user can raise a ticket; admins triage + reply.
 */
final class SupportTicketController extends Controller
{
    use ResolvesCentreContext;

    /**
     * SECURITY (v22p96): a staff member may only act on a ticket that belongs to
     * their OWN agency; a platform_admin only on tickets of the agency they've
     * switched into (X-Active-Agency-Id). The prior global `$isStaff` check let
     * any agency's staff — and a switched super-admin — read/reply/triage every
     * agency's tickets (which carry billing/PII free-text). The raiser always
     * keeps access to their own ticket.
     */
    /* RESELLERS AND KIDDIETRAC'S OWN TICKETS (2026-10-21). App problems, questions, ideas
       and guide feedback go to KiddieTrac HQ. A Reseller works in its agencies through a
       granted agency-admin role; it may follow such a ticket's STATUS but not read the
       conversation, answer it or change it - those are between the agency and KiddieTrac.
       Anyone with a genuine role in the agency (or who raised the ticket) is unaffected. */
    public const HQ_CATEGORIES = ['technical', 'documentation', 'question', 'feature'];

    private function isHqTicket(object $ticket): bool
    {
        return in_array((string) ($ticket->category ?? ''), self::HQ_CATEGORIES, true);
    }

    /** Does this caller reach the ticket's agency ONLY through a reseller grant? */
    private function viaResellerOnly(Request $request, object $ticket): bool
    {
        $u = $request->user();
        if (! $u || (int) ($ticket->raised_by_user_id ?? 0) === (int) $u->id || $ticket->agency_id === null) {
            return false;
        }
        $roles = DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)->pluck('role')->all();
        if (! in_array('reseller_admin', $roles, true) || in_array('platform_admin', $roles, true)) {
            return false;
        }
        return ! DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $u->id)->where('ra.active', 1)->whereNull('ra.granted_by_reseller_id')
            ->where('ra.role', '!=', 'reseller_admin')
            ->where(function ($q) use ($ticket) { $q->where('ra.agency_id', $ticket->agency_id)->orWhere('c.agency_id', $ticket->agency_id); })
            ->exists();
    }

    /** HQ ticket seen through a reseller grant: status only. */
    private function statusOnly(Request $request, object $ticket): bool
    {
        return $this->isHqTicket($ticket) && $this->viaResellerOnly($request, $ticket);
    }

    private function statusOnlyRow(object $t): object
    {
        return (object) (array_intersect_key((array) $t, array_flip(['id', 'agency_id', 'centre_id', 'category', 'request_type', 'priority',
            'subject', 'status', 'source', 'created_at', 'updated_at', 'resolved_at', 'raised_by_name'])) + ['status_only' => true]);
    }

    private function staffMayAccessTicket(Request $request, object $ticket): bool
    {
        $u = $request->user();
        $isStaff = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])
            ->where('active', 1)->exists();
        if (! $isStaff) return false;
        if ($this->isPlatformAdminUser($u)) {
            /* A ticket with NO agency belongs to the platform, and the platform admin is
               the only person who can act on it — index() already lists them for exactly
               that reason. This guard did not agree: (int) null === (int) $agencyId is
               0 === 2, so the row appeared in the queue and 403'd the moment it was
               clicked. Anthony hit it on ticket #27 at 10:30 on 2026-08-31 and the failed
               open filed crash ticket #31.

               HALF A FIX IS ITS OWN BUG. The list was widened for unattributed tickets on
               2026-08-31 and the guard beside it was not, which is worse than leaving both
               strict: a row you can see and cannot open reads as the portal being broken,
               not as a permission boundary. Reader and guard have to move together. */
            return $ticket->agency_id === null
                || (int) $ticket->agency_id === (int) $this->resolveAgencyId($request);
        }
        // A tenant admin still sees only their own agency's tickets — an unattributed
        // platform ticket is not theirs, and (int) null would quietly match agency 0.
        /* ...and only in the agency they are working in (2026-11-05 leak sweep): the staff check above counts
           roles in the ACTIVE agency, but userBelongsToAgency() said yes to ANY role in the ticket's agency, so an
           admin of agency A who is only an educator (or parent) in agency B could read, reply to, reassign and close
           B's tickets. Same agency listMine() lists. */
        return $ticket->agency_id !== null
            && (int) $ticket->agency_id === (int) $this->resolveAgencyId($request)
            && $this->userBelongsToAgency($u->id, (int) $ticket->agency_id);
    }
    public function listMine(Request $request): JsonResponse
    {
        $u = $request->user();
        $isStaff = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])
            ->where('active', 1)->exists();
        $q = DB::table('support_tickets as t')
            ->leftJoin('users as raised', 'raised.id', '=', 't.raised_by_user_id')
            ->leftJoin('users as assigned', 'assigned.id', '=', 't.assigned_user_id')
            ->orderByDesc('t.created_at')
            ->select('t.*',
                // A website ticket has no account behind it (2026-09-29): show who wrote in.
                DB::raw("COALESCE(CONCAT(raised.first_name,' ',raised.last_name), CONCAT(t.requester_name,' <',t.requester_email,'> · website')) as raised_by_name"),
                DB::raw("CONCAT(assigned.first_name,' ',assigned.last_name) as assigned_name"));
        if ($isStaff) {
            // SECURITY (v22p96): resolve the active agency securely (honours the
            // header only for members/platform_admin) — never trust it raw.
            $agencyId = (int) $this->resolveAgencyId($request);
            /* A ticket with NO agency is a PLATFORM-level problem — an unhandled server
               error on a public endpoint, or one raised before anybody signed in, so
               there was no agency to attribute it to. Filtered strictly on agency_id
               those are invisible to every agency at once, which is the same trap that
               hid the auto sign-off audit rows: written faithfully, readable by nobody.
               A platform admin sees them; a tenant admin still sees only their own.
               (2026-08-31) */
            if ($this->isPlatformAdminUser($u)) {
                $q->where(function ($w) use ($agencyId) {
                    $w->where('t.agency_id', $agencyId)->orWhereNull('t.agency_id');
                });
            } else {
                $q->where('t.agency_id', $agencyId);
            }
        } else {
            $q->where('t.raised_by_user_id', $u->id);
        }
        $rows = $q->limit(200)->get();
        if ($isStaff) {
            $rows = $rows->map(fn ($t) => $this->statusOnly($request, $t) ? $this->statusOnlyRow($t) : $t)->values();
        }
        return response()->json(['data' => $rows]);
    }


    /* SLA TARGETS, by priority, in clock hours (2026-10-28, Anthony: "for support tickets add some graphs and sla's").
       First response = the first reply from anyone other than the person who raised it. */
    public const SLA = [
        'urgent' => ['respond_h' => 1, 'resolve_h' => 8, 'label' => 'Urgent'],
        'high'   => ['respond_h' => 4, 'resolve_h' => 24, 'label' => 'High'],
        'normal' => ['respond_h' => 8, 'resolve_h' => 72, 'label' => 'Normal'],
        'low'    => ['respond_h' => 24, 'resolve_h' => 168, 'label' => 'Low'],
    ];

    /**
     * GET /tickets/stats — the numbers behind the Support tickets screen: volumes, first-response and resolution
     * times against the SLA, what is breaching now, how old the open ones are, created vs resolved by week, and
     * the commonest kinds of request. Scoped EXACTLY like listMine (the same tickets the screen lists).
     */
    public function stats(Request $request): JsonResponse
    {
        $u = $request->user();
        $isStaff = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])->where('active', 1)->exists();
        $q = DB::table('support_tickets as t');
        if ($isStaff) {
            $agencyId = (int) $this->resolveAgencyId($request);
            if ($this->isPlatformAdminUser($u)) {
                $q->where(function ($w) use ($agencyId) { $w->where('t.agency_id', $agencyId)->orWhereNull('t.agency_id'); });
            } else {
                $q->where('t.agency_id', $agencyId);
            }
        } else {
            $q->where('t.raised_by_user_id', $u->id);
        }
        $rows = $q->where('t.created_at', '>=', now()->subDays(365))->orderByDesc('t.id')->limit(3000)
            ->get(['t.id', 't.agency_id', 't.source', 't.priority', 't.status', 't.category', 't.request_type', 't.raised_by_user_id', 't.created_at', 't.resolved_at']);
        if ($isStaff) {
            $rows = $rows->reject(fn ($t) => $this->statusOnly($request, $t))->values();
        }
        $ids = $rows->pluck('id')->all();
        $firstReply = [];
        if ($ids) {
            foreach (DB::table('support_ticket_messages as m')->join('support_tickets as t', 't.id', '=', 'm.ticket_id')
                ->whereIn('m.ticket_id', $ids)->whereNotNull('m.user_id')
                ->whereRaw('(t.raised_by_user_id IS NULL OR m.user_id <> t.raised_by_user_id)')
                ->groupBy('m.ticket_id')->selectRaw('m.ticket_id, MIN(m.created_at) as first_at')->get() as $r) {
                $firstReply[(int) $r->ticket_id] = $r->first_at;
            }
        }

        $now = now();
        $sla = fn ($p) => self::SLA[strtolower((string) $p)] ?? self::SLA['normal'];
        $hours = fn ($a, $b) => max(0, \Illuminate\Support\Carbon::parse((string) $a)->diffInSeconds(\Illuminate\Support\Carbon::parse((string) $b)) / 3600);
        $median = function (array $v) { if (! $v) { return null; } sort($v); $n = count($v); return round($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2, 1); };
        $isDone = fn ($s) => in_array($s, ['resolved', 'closed'], true);

        $respond = []; $resolve = []; $respondMet = 0; $respondN = 0; $resolveMet = 0; $resolveN = 0;
        $open = 0; $breaching = 0; $aging = ['Under 1 day' => 0, '1–3 days' => 0, '3–7 days' => 0, 'Over 7 days' => 0];
        $openSla = []; $byCat = []; $byPri = [];
        $since90 = $now->copy()->subDays(90);
        foreach ($rows as $t) {
            $tgt = $sla($t->priority);
            $created = \Illuminate\Support\Carbon::parse((string) $t->created_at);
            $fr = $firstReply[(int) $t->id] ?? null;
            $cat = trim((string) ($t->request_type ?: $t->category ?: 'other'));
            $byCat[$cat] = ($byCat[$cat] ?? 0) + 1;
            $pk = strtolower((string) ($t->priority ?: 'normal'));
            $byPri[$pk] = ($byPri[$pk] ?? 0) + 1;
            if ($created->gte($since90)) {
                if ($fr) { $h = $hours($t->created_at, $fr); $respond[] = $h; $respondN++; if ($h <= $tgt['respond_h']) { $respondMet++; } }
                if ($isDone($t->status) && $t->resolved_at) { $h = $hours($t->created_at, $t->resolved_at); $resolve[] = $h; $resolveN++; if ($h <= $tgt['resolve_h']) { $resolveMet++; } }
            }
            if (! $isDone($t->status)) {
                $open++;
                $ageH = $hours($t->created_at, $now);
                $aging[$ageH < 24 ? 'Under 1 day' : ($ageH < 72 ? '1–3 days' : ($ageH < 168 ? '3–7 days' : 'Over 7 days'))]++;
                $respDue = $created->copy()->addHours($tgt['respond_h']);
                $resDue = $created->copy()->addHours($tgt['resolve_h']);
                $state = 'ok';
                if ((! $fr && $now->gt($respDue)) || $now->gt($resDue)) { $state = 'breached'; $breaching++; }
                elseif ((! $fr && $now->diffInMinutes($respDue, false) < 60) || $now->diffInHours($resDue, false) < 4) { $state = 'soon'; }
                $openSla[(int) $t->id] = [
                    'state' => $state, 'responded' => (bool) $fr,
                    'respond_due' => $respDue->toIso8601String(), 'resolve_due' => $resDue->toIso8601String(),
                ];
            }
        }

        // Created vs resolved, by week, last 12 weeks (weeks start Monday).
        $weeks = [];
        $w0 = $now->copy()->startOfWeek()->subWeeks(11);
        for ($i = 0; $i < 12; $i++) {
            $ws = $w0->copy()->addWeeks($i);
            $weeks[$ws->toDateString()] = ['week' => $ws->toDateString(), 'label' => $ws->format('M j'), 'created' => 0, 'resolved' => 0];
        }
        foreach ($rows as $t) {
            $k = \Illuminate\Support\Carbon::parse((string) $t->created_at)->startOfWeek()->toDateString();
            if (isset($weeks[$k])) { $weeks[$k]['created']++; }
            if ($t->resolved_at) {
                $k2 = \Illuminate\Support\Carbon::parse((string) $t->resolved_at)->startOfWeek()->toDateString();
                if (isset($weeks[$k2])) { $weeks[$k2]['resolved']++; }
            }
        }
        arsort($byCat);
        $since30 = $now->copy()->subDays(30);

        return response()->json([
            'targets' => self::SLA,
            'tiles' => [
                'open' => $open,
                'breaching' => $breaching,
                'new_30' => $rows->filter(fn ($t) => \Illuminate\Support\Carbon::parse((string) $t->created_at)->gte($since30))->count(),
                'resolved_30' => $rows->filter(fn ($t) => $t->resolved_at && \Illuminate\Support\Carbon::parse((string) $t->resolved_at)->gte($since30))->count(),
                'median_first_response_h' => $median($respond),
                'median_resolution_h' => $median($resolve),
                'response_sla_pct' => $respondN ? (int) round(100 * $respondMet / $respondN) : null,
                'resolve_sla_pct' => $resolveN ? (int) round(100 * $resolveMet / $resolveN) : null,
                'measured_on' => 'Tickets raised in the last 90 days',
            ],
            'weekly' => array_values($weeks),
            'categories' => array_map(fn ($k, $v) => ['key' => $k, 'count' => $v], array_slice(array_keys($byCat), 0, 6), array_slice(array_values($byCat), 0, 6)),
            'priorities' => $byPri,
            'aging' => $aging,
            'open_sla' => (object) $openSla,
        ]);
    }

    /**
     * May this user see this ticket at all?
     *
     * The person who raised it, or staff of the agency it belongs to. Everything to do
     * with files goes through here, so a file can never be reachable by somebody who
     * could not open the ticket it hangs off.
     */
    private function maySeeTicket(Request $request, $ticket): bool
    {
        if (! $ticket) {
            return false;
        }
        if ($this->statusOnly($request, $ticket)) {
            return false;   // files of a platform-team ticket are not a Reseller's
        }
        $uid = (int) $request->user()->id;
        if ((int) ($ticket->raised_by_user_id ?? 0) === $uid) {
            return true;
        }

        return $this->staffMayAccessTicket($request, $ticket);
    }

    /** POST /tickets/{id}/files — attach a screenshot, an export or a log. */
    public function uploadFile(Request $request, int $id): JsonResponse
    {
        $ticket = DB::table('support_tickets')->where('id', $id)->first();
        abort_unless($this->maySeeTicket($request, $ticket), 403, 'Not your ticket.');

        $request->validate([
            // Images for screenshots, plus the text and archive formats a log or an
            // export actually arrives as. Deliberately no executables.
            'file' => ['required', 'file', 'max:10240',
                      'mimes:jpg,jpeg,png,gif,webp,heic,pdf,txt,log,csv,json,xml,zip'],
        ]);

        $file = $request->file('file');
        $name = (string) \Illuminate\Support\Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin');
        // Stored on the PRIVATE disk. A ticket attachment can hold a child's name, an
        // invoice or a screenshot of somebody's record, so it is never served from the
        // public root where a guessed filename would reach it.
        $path = $file->storeAs('ticket-files/'.$id, $name);

        $fileId = DB::table('support_ticket_files')->insertGetId([
            'ticket_id' => $id,
            'path' => $path,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'mime' => (string) $file->getClientMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'uploaded_by_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('support_tickets')->where('id', $id)->update(['updated_at' => now()]);

        return response()->json([
            'id' => $fileId,
            'original_name' => $file->getClientOriginalName(),
            'size_bytes' => $file->getSize(),
        ]);
    }

    /** GET /tickets/{id}/files/{fileId} — stream one back. */
    public function downloadFile(Request $request, int $id, int $fileId)
    {
        $ticket = DB::table('support_tickets')->where('id', $id)->first();
        abort_unless($this->maySeeTicket($request, $ticket), 403, 'Not your ticket.');

        $row = DB::table('support_ticket_files')->where('id', $fileId)->where('ticket_id', $id)->first();
        abort_unless($row, 404);

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        abort_unless($disk->exists($row->path), 404);

        return response()->stream(function () use ($disk, $row) {
            echo $disk->get($row->path);
        }, 200, [
            'Content-Type' => $row->mime ?: 'application/octet-stream',
            // inline so a screenshot opens in the browser rather than downloading.
            'Content-Disposition' => 'inline; filename="'.addslashes($row->original_name).'"',
        ]);
    }
    /**
     * SERVICE-DESK REQUEST TYPES (2026-10-21). Anthony: "raise a ticket should have
     * categories and better input to allowing the user to enter in information - mimic
     * jira service desk". The person picks WHAT they need, the form asks that type's own
     * questions, and the type decides the category (kept for routing and old reports) and
     * who hears about it: app problems, questions and ideas go to KiddieTrac HQ; anything
     * about the agency's own running goes to that agency's admins and directors.
     */
    public const REQUEST_TYPES = [
        'problem'     => ['label' => 'Report a problem',          'category' => 'technical',   'hq' => true],
        'question'    => ['label' => 'How do I…?',                'category' => 'question',    'hq' => true],
        'idea'        => ['label' => 'Suggest an improvement',    'category' => 'feature',     'hq' => true],
        // Agency plan only, 3 courtesy requests a year, reviewed by HQ (2026-10-24, App\Support\Licences::enhancements).
        'enhancement' => ['label' => 'Request an enhancement',    'category' => 'feature',     'hq' => true],
        // A plan or add-on the agency would like (the Upgrade popup, App\Support\PlanUpsell, 2026-11-26) - KiddieTrac sells it.
        'addon'       => ['label' => 'Add a feature to our plan', 'category' => 'account',     'hq' => true],
        'account'     => ['label' => 'Account & sign-in',         'category' => 'account',     'hq' => false],
        'billing'     => ['label' => 'Billing & payments',        'category' => 'billing',     'hq' => false],
        'enrollment'  => ['label' => 'Enrollment & families',     'category' => 'enrollment',  'hq' => false],
        'maintenance' => ['label' => 'Maintenance / repair',      'category' => 'maintenance', 'hq' => false],
        'policy'      => ['label' => 'Policy & compliance',       'category' => 'policy',      'hq' => false],
        'other'       => ['label' => 'Something else',            'category' => 'other',       'hq' => false],
    ];

    /** Plain-language impact => priority, as the form asks it. */
    private const IMPACT = [
        'question'  => ['priority' => 'low',    'label' => 'Just a question or idea'],
        'minor'     => ['priority' => 'normal', 'label' => 'Annoying, but we can work around it'],
        'slowing'   => ['priority' => 'high',   'label' => 'It is slowing us down'],
        'blocked'   => ['priority' => 'urgent', 'label' => 'We cannot run the day'],
        'safety'    => ['priority' => 'urgent', 'label' => 'A child’s safety or care is affected'],
    ];

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'request_type' => 'nullable|string|in:' . implode(',', array_keys(self::REQUEST_TYPES)),
            /* `documentation` is raised by the help screen when somebody marks a guide
               unhelpful and says why. Not offered in the ticket form — nobody files one
               by hand — same as `crash`. */
            'category' => 'nullable|string|in:billing,enrollment,maintenance,technical,policy,documentation,other,question,feature,account',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'impact' => 'nullable|string|in:' . implode(',', array_keys(self::IMPACT)),
            'subject' => 'required|string|max:200',
            'body' => 'nullable|string|max:10000',
            'centre_id' => 'nullable|integer',
            // The type's own questions, in the order asked: [{label, value}].
            'details' => 'nullable|array|max:20',
            'details.*.label' => 'required_with:details|string|max:80',
            'details.*.value' => 'nullable|string|max:4000',
            // What the portal captured by itself (screen, device, app version).
            'context' => 'nullable|array|max:20',
            'context.*' => 'nullable|string|max:300',
        ]);
        $type = $data['request_type'] ?? null;
        /* ENHANCEMENT REQUESTS (2026-10-24, Anthony: "enhancement requests (3 times a year courtesy
           based on review process)"). Agency plan only; refused before anything is written. */
        if ($type === 'enhancement') {
            $en = \App\Support\Licences::enhancements((int) ($this->resolveAgencyId($request) ?: 0));
            if (! $en['eligible']) {
                return response()->json(['message' => 'Enhancement requests are included with the Agency plan. Contact KiddieTrac to upgrade.'], 422);
            }
            if ($en['left'] < 1) {
                return response()->json(['message' => 'Your ' . $en['allowance'] . ' enhancement requests for ' . $en['year'] . ' have been used. Contact KiddieTrac about more custom work.'], 422);
            }
        }
        $category = $type ? self::REQUEST_TYPES[$type]['category'] : ($data['category'] ?? 'other');
        $priority = isset($data['impact']) ? self::IMPACT[$data['impact']]['priority'] : ($data['priority'] ?? 'normal');

        $u = $request->user();
        /* Whose agency. The first role's agency_id is NULL for anyone whose role is filed
           against a centre (directors, educators, many parents), which filed their tickets
           under no agency at all — readable only by a platform admin. The agency they are
           working in comes first, then their own. */
        $agencyId = (int) ($this->resolveAgencyId($request) ?: 0);
        if (! $agencyId) {
            $agencyId = (int) (\App\Support\AuditScope::ownAgency((int) $u->id) ?: 0);
        }
        if (! $agencyId) {
            $agencyId = (int) DB::table('role_assignments')->where('user_id', $u->id)
                ->where('active', 1)->value('agency_id');
        }

        // A centre named on the form must be one of this agency's.
        $centreId = isset($data['centre_id']) && $agencyId
            ? (DB::table('centres')->where('id', (int) $data['centre_id'])->where('agency_id', $agencyId)->value('id') ?: null)
            : null;

        $details = array_values(array_filter(array_map(function ($d) {
            $v = trim((string) ($d['value'] ?? ''));
            return $v === '' ? null : ['label' => trim((string) $d['label']), 'value' => $v];
        }, $data['details'] ?? [])));
        $context = array_filter(array_map(fn ($v) => trim((string) $v), $data['context'] ?? []), fn ($v) => $v !== '');

        /* The body carries the answers too, as plain text, so every place that only knows
           `body` (old screens, the CSV, the email digest, search) still reads everything. */
        $body = trim((string) ($data['body'] ?? ''));
        if ($details) {
            $body .= ($body !== '' ? "\n\n" : '') . implode("\n", array_map(fn ($d) => $d['label'] . ': ' . $d['value'], $details));
        }
        if (isset($data['impact'])) {
            $body .= "\n\nImpact: " . self::IMPACT[$data['impact']]['label'];
        }

        $id = DB::table('support_tickets')->insertGetId([
            'agency_id' => $agencyId ?: null,
            'centre_id' => $centreId,
            'raised_by_user_id' => $u->id,
            'category' => $category,
            'request_type' => $type,
            'priority' => $priority,
            'impact' => $data['impact'] ?? null,
            'subject' => $data['subject'],
            'body' => $body !== '' ? $body : null,
            'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            'context' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $typeLabel = $type ? self::REQUEST_TYPES[$type]['label'] : ucfirst(str_replace('_', ' ', $category));
        $toHq = $type ? self::REQUEST_TYPES[$type]['hq'] : in_array($category, ['technical', 'documentation', 'question', 'feature'], true);

        \App\Support\Audit::write([
            'user_id' => $u->id, 'agency_id' => $agencyId ?: null, 'action' => 'support_ticket.raised',
            'entity_type' => 'support_ticket', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Raised ticket #' . $id . ' (' . $typeLabel . ', ' . $priority . '): ' . $data['subject']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);

        /* PLATFORM ADMINS ONLY (2026-11-24, App\Support\TicketAlerts). Anthony: "when support tickets are logged they
           need to only be alerted and sent to platform admin and not to the agency". This used to ring every admin
           and director of the agency and email them the account / billing / enrolment tickets. */
        \App\Support\TicketAlerts::bell($id, "🎫 {$typeLabel}: {$data['subject']}", (string) $body, (int) $u->id);
        // v22p97: EMAIL the ticket so it never sits unseen. In-app notifications
        // alone miss admins who don't log in, and KiddieTrac HQ heard nothing at
        // all. Wrapped so a mail failure can never break ticket creation.
        try {
            $raiser = DB::table('users')->where('id', $u->id)->first();
            $agencyName = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'KiddieTrac');
            $raiserName = trim(((string) ($raiser->first_name ?? '')) . ' ' . ((string) ($raiser->last_name ?? ''))) ?: 'A user';
            $raiserEmail = (string) ($raiser->email ?? '');
            $pri = strtoupper($priority);
            $subjectLine = "[Support · {$agencyName}] #{$id} ({$pri}) {$typeLabel}: " . $data['subject'];
            $row = fn ($k, $v) => '<tr><td style="padding:3px 14px 3px 0;color:#64748b;vertical-align:top;white-space:nowrap;">' . e($k)
                . '</td><td style="color:#0f172a;white-space:pre-wrap;">' . e($v) . '</td></tr>';
            $table = $row('Request', $typeLabel) . $row('Agency', $agencyName)
                . ($centreId ? $row('Centre', (string) DB::table('centres')->where('id', $centreId)->value('name')) : '')
                . $row('Priority', $pri . (isset($data['impact']) ? ' — ' . self::IMPACT[$data['impact']]['label'] : ''))
                . $row('Summary', $data['subject']);
            foreach ($details as $d) {
                $table .= $row($d['label'], $d['value']);
            }
            $inner = '<p style="margin:0 0 12px;font-size:14px;color:#334155;"><strong>' . e($raiserName) . '</strong>'
                . ($raiserEmail ? ' (<a href="mailto:' . e($raiserEmail) . '" style="color:#1F6080;">' . e($raiserEmail) . '</a>)' : '')
                . ' raised a support ticket.</p>'
                . '<table style="border-collapse:collapse;font-size:14px;margin:0 0 14px;">' . $table . '</table>'
                . (trim((string) ($data['body'] ?? '')) !== ''
                    ? '<div style="white-space:pre-wrap;background:#F8FAFC;border-radius:8px;padding:4px 0 4px 14px;color:#334155;font-size:14px;line-height:1.55;">'
                        . nl2br(e((string) $data['body'])) . '</div>' : '')
                . ($context ? '<p style="color:#94a3b8;font-size:12px;margin:14px 0 0;">Sent from: '
                    . e(implode(' · ', array_map(fn ($k, $v) => $k . ' ' . $v, array_keys($context), $context))) . '</p>' : '')
                . '<p class="kt-muted" style="color:#94a3b8;font-size:12px;margin:16px 0 0;">Ticket #' . $id . ' &middot; open it under <strong>Support</strong> in the portal to reply.</p>';
            $html = \App\Services\EmailTemplate::wrap(
                null,
                $inner,
                [
                    'force_brand' => 'kt',   // a platform alert, always KiddieTrac-branded
                    'eyebrow'     => 'SUPPORT TICKET #' . $id,
                    'title'       => $typeLabel,
                    'subtitle'    => $agencyName . ' · ' . $pri . ' priority',
                    'preheader'   => $typeLabel . ': ' . $data['subject'],
                ]
            );
            // HQ inbox + every platform admin (Anthony chose "Both"); replying reaches the person who raised it.
            \App\Support\TicketAlerts::email($subjectLine, $html, $raiserEmail !== '' ? $raiserEmail : null);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('support ticket notify failed: ' . $e->getMessage());
        }
        return response()->json(['id' => $id, 'routed_to' => 'kiddietrac'], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $ticket = DB::table('support_tickets')->where('id', $id)->first();
        abort_unless($ticket, 404);
        $u = $request->user();
        abort_unless($ticket->raised_by_user_id === $u->id || $this->staffMayAccessTicket($request, $ticket), 403);
        if ($this->statusOnly($request, $ticket)) {
            return response()->json(['ticket' => $this->statusOnlyRow($ticket), 'messages' => [], 'files' => [], 'events' => [],
                'status_only' => true, 'message' => 'This ticket is with the platform team. You can follow its status here.']);
        }
        $messages = DB::table('support_ticket_messages as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.ticket_id', $id)
            ->orderBy('m.created_at')
            ->select('m.*', DB::raw("CONCAT(u.first_name,' ',u.last_name) as author_name"))
            ->get();

        // Who is staff decides which side of the conversation a reply sits on. Worked
        // out once here rather than guessed at in the browser.
        $authorIds = $messages->pluck('user_id')->filter()->unique()->values()->all();
        $staffIds = $authorIds
            ? DB::table('role_assignments')->whereIn('user_id', $authorIds)
                ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])
                ->where('active', 1)->pluck('user_id')->unique()->all()
            : [];
        foreach ($messages as $m) {
            $m->is_staff = in_array((int) $m->user_id, array_map('intval', $staffIds), true);
        }

        // People by name. The screen cannot turn an id into a person and should not try.
        $name = function ($uid) {
            if (! $uid) {
                return null;
            }
            $u = DB::table('users')->where('id', $uid)->first(['first_name', 'last_name']);

            return $u ? trim($u->first_name.' '.$u->last_name) : null;
        };
        $ticket->raised_by_name = $name($ticket->raised_by_user_id);
        $ticket->assigned_name = $name($ticket->assigned_user_id ?? null);
        $ticket->resolved_by_name = $name($ticket->resolved_by_user_id ?? null);
        $ticket->centre_name = $ticket->centre_id
            ? DB::table('centres')->where('id', $ticket->centre_id)->value('name')
            : null;

        $files = DB::table('support_ticket_files')->where('ticket_id', $id)
            ->orderBy('id')
            ->get(['id', 'original_name', 'mime', 'size_bytes', 'created_at']);

        // The history. Without it a ticket can only show where it ended up, never how it
        // got there — which was most of what made the screen unreadable.
        $events = DB::table('support_ticket_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.ticket_id', $id)
            ->orderBy('e.created_at')->orderBy('e.id')
            ->select('e.*', DB::raw("CONCAT(u.first_name,' ',u.last_name) as actor_name"))
            ->get();

        return response()->json([
            'ticket' => $ticket,
            'messages' => $messages,
            'files' => $files,
            'events' => $events,
        ]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|max:10000']);
        $ticket = DB::table('support_tickets')->where('id', $id)->first();
        abort_unless($ticket, 404);
        $u = $request->user();
        $isStaff = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])
            ->where('active', 1)->exists();
        // v22p96: scoped — global staff role alone is not enough; must own the agency.
        abort_unless($ticket->raised_by_user_id === $u->id || $this->staffMayAccessTicket($request, $ticket), 403);
        abort_if($this->statusOnly($request, $ticket), 403, 'This ticket is with the platform team.');
        DB::table('support_ticket_messages')->insert([
            'ticket_id' => $id, 'user_id' => $u->id, 'body' => $data['body'],
            'created_at' => now(),
        ]);
        // Replying moves the ticket automatically. That is a real status change and
        // belongs in the history like any other, otherwise the timeline shows a ticket
        // changing state with nothing to explain it.
        $newStatus = $isStaff ? 'awaiting_user' : 'open';
        DB::table('support_tickets')->where('id', $id)->update([
            'status' => $newStatus,
            'updated_at' => now(),
        ]);
        if ((string) $ticket->status !== $newStatus) {
            $this->logEvent($id, 'status', (string) $ticket->status, $newStatus, null, $u->id);
        }
        // Notify the other side. Staff replied -> the person who raised it. The raiser replied -> the platform admins
        // only (2026-11-24, TicketAlerts), never the agency's admins or an agency person it was assigned to.
        $otherId = $isStaff ? $ticket->raised_by_user_id : null;
        if (! $isStaff) {
            \App\Support\TicketAlerts::bell($id, "Reply on ticket: {$ticket->subject}", (string) $data['body'], (int) $u->id);
        }
        if ($otherId) {
            \App\Support\Notify::write([
                'user_id' => $otherId, 'type' => 'support_ticket',
                'title' => "Reply on ticket: {$ticket->subject}",
                'body' => substr($data['body'], 0, 200),
                'data' => json_encode(['link' => '#tickets', 'ticket_id' => $id]),
                'created_at' => now(),
            ]);
        }

        /* And EMAIL them. The in-app bell alone only reaches people who happen to open
           the portal — which is exactly the audience a support reply is least likely to
           reach, and why tickets sat in `awaiting_user` with the user never told. */
        $this->sendReplyNotice($ticket, $u, $data['body'], $isStaff, $otherId);

        return response()->json(['status' => 'replied']);
    }

    /**
     * Tell the other side, by email, that their ticket has a reply.
     *
     * Mirrors the resolution notice in updateStatus(): same branded shell, same mailer,
     * same BCC to HQ so support has the thread. Best-effort throughout — a reply that
     * was written and stored must never fail because mail did.
     */
    private function sendReplyNotice($ticket, $author, string $body, bool $authorIsStaff, $otherId): void
    {
        try {
            $agencyId = $ticket->agency_id ? (int) $ticket->agency_id : null;

            /* Who hears about it. Staff replied → the person who raised it. The raiser
               replied → whoever is assigned; and with nobody assigned, the agency's
               admins and directors, so a customer's reply is not filed into silence. */
            $recipients = [];
            if ($otherId) {
                $e = DB::table('users')->where('id', $otherId)->whereNull('deleted_at')->value('email');
                if ($e) { $recipients[] = $e; }
            }
            if (! $authorIsStaff) {
                // The raiser replied: email the platform (HQ inbox + platform admins), never the agency (2026-11-24).
                $who0 = trim(((string) ($author->first_name ?? '')) . ' ' . ((string) ($author->last_name ?? ''))) ?: 'The person who raised it';
                $an0 = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'KiddieTrac');
                $h0 = \App\Services\EmailTemplate::wrap(null,
                    '<p style="margin:0 0 12px;font-size:14px;color:#334155;"><strong>' . e($who0) . '</strong>'
                    . (! empty($author->email) ? ' (' . e((string) $author->email) . ')' : '') . ' replied on ticket #' . (int) $ticket->id
                    . ' from <strong>' . e($an0) . '</strong>.</p>'
                    . '<div style="font-size:15px;font-weight:800;color:#0F172A;margin:0 0 10px;">' . e((string) $ticket->subject) . '</div>'
                    . '<div style="white-space:pre-wrap;background:#F8FAFC;border-radius:8px;padding:4px 0 4px 14px;color:#334155;font-size:14.5px;line-height:1.6;margin:0 0 16px;">'
                    . nl2br(e(mb_strimwidth($body, 0, 1200, '…'))) . '</div>'
                    . '<p class="kt-muted" style="color:#94a3b8;font-size:12px;margin:0;">Open it under <strong>Support</strong> in the portal to reply.</p>',
                    ['force_brand' => 'kt', 'eyebrow' => 'SUPPORT TICKET #' . (int) $ticket->id, 'title' => 'New reply on a ticket',
                     'subtitle' => $an0, 'preheader' => $who0 . ' replied — #' . (int) $ticket->id . ' ' . (string) $ticket->subject]);
                \App\Support\TicketAlerts::email('[Support · ' . $an0 . '] Reply on #' . (int) $ticket->id . ' ' . mb_strimwidth((string) $ticket->subject, 0, 80, '…'),
                    $h0, ! empty($author->email) ? (string) $author->email : null);
                return;
            }
            $recipients = array_values(array_unique(array_filter($recipients)));
            if (! $recipients) {
                return;
            }

            $agencyName = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'KiddieTrac');
            $tz = \App\Support\AgencyTime::tz($agencyId);
            $when = now()->setTimezone($tz);
            $who = trim(((string) ($author->first_name ?? '')) . ' ' . ((string) ($author->last_name ?? '')))
                ?: ($authorIsStaff ? 'The team' : 'The person who raised it');

            $html = \App\Services\EmailTemplate::wrap($agencyId,
                '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">'
                . e($who) . ' replied to your support request'
                . ($authorIsStaff ? ' at <strong>' . e($agencyName) . '</strong>' : '') . '.</p>'

                . '<div class="kt-panel" style="background:#F8FAFC;border:1px solid #E2E8F0;'
                . '--kt-edge:#1F6FB2;border-radius:10px;padding:14px 16px;margin:0 0 14px;">'
                .   '<div class="kt-muted" style="font-size:11px;font-weight:800;letter-spacing:1px;'
                .     'color:#64748B;text-transform:uppercase;margin-bottom:4px;">Ticket #'
                .     (int) $ticket->id . '</div>'
                .   '<div style="font-size:15px;font-weight:800;color:#0F172A;">'
                .     e((string) $ticket->subject) . '</div>'
                . '</div>'

                /* The reply itself, not just a nudge to come and read it. Trimmed, because
                   an email is a notice — the full thread lives in the portal. */
                . '<div style="white-space:pre-wrap;background:#F8FAFC;border-radius:8px;padding:4px 0 4px 14px;'
                . 'color:#334155;font-size:14.5px;line-height:1.6;margin:0 0 16px;">'
                . nl2br(e(mb_strimwidth($body, 0, 1200, '…'))) . '</div>'

                . '<p style="margin:0 0 6px;font-size:14px;line-height:1.6;color:#475569;">'
                . 'Sent ' . e($when->format('D, M j, Y')) . ' at ' . e($when->format('g:i A'))
                . ' (' . e($when->format('T')) . ').</p>'
                . '<p style="margin:0;font-size:14px;line-height:1.6;color:#475569;">'
                . 'Open <strong>Support</strong> in the portal to reply.</p>',
                [
                    'eyebrow' => 'SUPPORT',
                    'title' => 'New reply on your ticket',
                    'preheader' => $who . ' replied — ticket #' . (int) $ticket->id
                        . ' · ' . (string) $ticket->subject,
                ]
            );

            \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($recipients, $ticket) {
                    $m->to($recipients[0])->subject('New reply on your ticket #' . (int) $ticket->id
                        . ' — ' . mb_strimwidth((string) $ticket->subject, 0, 60, '…'));
                    if (count($recipients) > 1) { $m->bcc(array_slice($recipients, 1, 20)); }
                    $m->bcc('info@kiddietrac.com');
                });
        } catch (\Throwable $e) {
            // The reply is already saved. Losing the notice must not lose the reply.
            \Illuminate\Support\Facades\Log::error('Ticket reply notice failed', [
                'ticket' => $ticket->id ?? null, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record one change against a ticket.
     *
     * Best-effort on purpose: losing a history line must never cost the change itself,
     * or a missing table would make the portal unable to resolve a ticket at all.
     */
    private function logEvent(int $ticketId, string $type, ?string $from, ?string $to, ?string $note, $userId): void
    {
        try {
            DB::table('support_ticket_events')->insert([
                'ticket_id' => $ticketId,
                'type' => $type,
                'from_value' => $from !== null ? mb_substr($from, 0, 60) : null,
                'to_value' => $to !== null ? mb_substr($to, 0, 60) : null,
                'note' => $note,
                'user_id' => $userId,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // History is worth having, not worth failing over.
        }
    }

    /**
     * POST /tickets/{id}/escalate {note?} — hand an agency ticket to KiddieTrac (2026-10-21).
     * Staff who can act on the ticket (a Reseller included) send it to the platform team:
     * it is noted on the ticket's history and emailed to the HQ inbox, the same way a
     * ticket raised for HQ is. The ticket stays with the agency to answer meanwhile.
     */
    public function escalate(Request $request, int $id): JsonResponse
    {
        $ticket = DB::table('support_tickets')->where('id', $id)->first();
        abort_unless($ticket, 404);
        abort_unless($this->staffMayAccessTicket($request, $ticket), 403);
        abort_if($this->isHqTicket($ticket), 422, 'This ticket is already with the platform team.');
        $data = $request->validate(['note' => 'nullable|string|max:2000']);
        $u = $request->user();
        $note = trim((string) ($data['note'] ?? ''));
        $this->logEvent($id, 'escalated', null, 'platform team', $note !== '' ? $note : null, $u->id);
        try {
            $agencyName = (string) (DB::table('agencies')->where('id', $ticket->agency_id)->value('name') ?: 'an agency');
            $who = trim($u->first_name . ' ' . $u->last_name);
            $inner = '<p style="margin:0 0 12px;font-size:14px;color:#334155;"><strong>' . e($who) . '</strong> (' . e((string) $u->email) . ') escalated a ticket from <strong>'
                . e($agencyName) . '</strong> to the platform team.</p>'
                . '<table style="border-collapse:collapse;font-size:14px;margin:0 0 14px;">'
                . '<tr><td style="padding:3px 14px 3px 0;color:#64748b;">Ticket</td><td>#' . $id . ' · ' . e((string) $ticket->subject) . '</td></tr>'
                . '<tr><td style="padding:3px 14px 3px 0;color:#64748b;">Status</td><td>' . e((string) $ticket->status) . ' · ' . e(strtoupper((string) $ticket->priority)) . '</td></tr></table>'
                . ($note !== '' ? '<div style="white-space:pre-wrap;background:#F8FAFC;border-radius:8px;padding:4px 0 4px 14px;color:#334155;font-size:14px;">' . nl2br(e($note)) . '</div>' : '')
                . '<p style="color:#94a3b8;font-size:12px;margin:16px 0 0;">Open it under <strong>Support</strong> in the portal (switch into ' . e($agencyName) . ').</p>';
            $html = \App\Services\EmailTemplate::wrap(null, $inner, ['force_brand' => 'kt', 'eyebrow' => 'ESCALATED TICKET #' . $id,
                'title' => (string) $ticket->subject, 'subtitle' => $agencyName, 'preheader' => 'Escalated: ' . $ticket->subject]);
            $subject = "[Escalated · {$agencyName}] #{$id} " . $ticket->subject;
            // HQ inbox + every platform admin, and their bell (2026-11-24, TicketAlerts).
            \App\Support\TicketAlerts::email($subject, $html, ! empty($u->email) ? (string) $u->email : null);
            \App\Support\TicketAlerts::bell($id, '⬆️ Escalated: ' . (string) $ticket->subject, $note !== '' ? $note : $agencyName, (int) $u->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ticket escalation mail failed: ' . $e->getMessage());
        }
        return response()->json(['ok' => true, 'message' => 'Sent to the platform team. It stays open here until it is resolved.']);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $ticket = DB::table('support_tickets')->where('id', $id)->first();
        abort_unless($ticket, 404);
        // v22p96: must be staff of THIS ticket's agency (or platform_admin scoped
        // to it) — not just any staff anywhere.
        abort_unless($this->staffMayAccessTicket($request, $ticket), 403);
        abort_if($this->statusOnly($request, $ticket), 403, 'This ticket is with the platform team.');
        $data = $request->validate([
            'status' => 'required|in:open,awaiting_user,resolved,closed',
            'assigned_user_id' => 'nullable|integer',
            'priority' => 'nullable|in:low,normal,high,urgent',
            // How it was fixed. Written down against the ticket, because otherwise the
            // answer exists only in the head of whoever closed it.
            'resolution' => 'nullable|string|max:4000',
        ]);

        /* The assignee must be someone who can work the ticket (2026-11-05 leak sweep): any user id was written,
           and the assignee is then emailed every reply from the raiser, so a ticket's content could be routed to a
           person in another agency. An admin or director of the ticket's agency, or a platform admin. */
        if (! empty($data['assigned_user_id']) && (int) $data['assigned_user_id'] !== (int) ($ticket->assigned_user_id ?? 0)) {
            $aid = (int) $data['assigned_user_id'];
            $tAgency = $ticket->agency_id !== null ? (int) $ticket->agency_id : null;
            $ok = DB::table('role_assignments')->where('user_id', $aid)->where('active', 1)->where('role', 'platform_admin')->exists()
                || ($tAgency !== null && DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
                    ->where('ra.user_id', $aid)->where('ra.active', 1)->whereIn('ra.role', ['agency_admin', 'centre_director'])
                    ->where(fn ($q) => $q->where('ra.agency_id', $tAgency)->orWhere('c.agency_id', $tAgency))->exists());
            if (! $ok) {
                return response()->json(['message' => 'That person cannot be assigned this ticket.'], 422);
            }
        }

        $finishing = in_array($data['status'], ['resolved', 'closed'], true);
        $wasFinished = in_array((string) $ticket->status, ['resolved', 'closed'], true);

        $update = [
            'status' => $data['status'],
            'assigned_user_id' => array_key_exists('assigned_user_id', $data) ? $data['assigned_user_id'] : DB::raw('assigned_user_id'),
            'priority' => $data['priority'] ?? DB::raw('priority'),
            'resolved_at' => $finishing ? now() : null,
            'updated_at' => now(),
        ];
        if ($finishing) {
            $update['resolved_by_user_id'] = $request->user()->id;
        }
        // A blank note must not wipe a resolution that was already written.
        if (! empty($data['resolution'])) {
            $update['resolution'] = $data['resolution'];
        } elseif (! $finishing) {
            $update['resolution'] = null;   // reopened — the old answer no longer holds
            $update['resolved_by_user_id'] = null;
        }

        DB::table('support_tickets')->where('id', $id)->update($update);

        if ((string) $ticket->status !== $data['status']) {
            $this->logEvent($id, 'status', (string) $ticket->status, $data['status'],
                $data['resolution'] ?? null, $request->user()->id);
        }
        if (! empty($data['priority']) && $data['priority'] !== (string) $ticket->priority) {
            $this->logEvent($id, 'priority', (string) $ticket->priority, $data['priority'],
                null, $request->user()->id);
        }
        if (array_key_exists('assigned_user_id', $data)
            && (int) $data['assigned_user_id'] !== (int) ($ticket->assigned_user_id ?? 0)) {
            $to = $data['assigned_user_id']
                ? DB::table('users')->where('id', $data['assigned_user_id'])
                    ->selectRaw("CONCAT(first_name,' ',last_name) as n")->value('n')
                : null;
            $this->logEvent($id, 'assigned', null, $to, null, $request->user()->id);
        }
        // Tell the person who raised it — but only on a real transition INTO a
        // finished state, so re-saving a resolved ticket or changing its priority
        // does not mail them again.
        if ($finishing && ! $wasFinished) {
            $this->notifyReporterResolved($ticket, $data['status'], $request->user());
        }

        return response()->json(['status' => $data['status']]);
    }

    /**
     * Email whoever raised the ticket that it is done, BCC'ing the agency inbox so
     * there is a record of what was said.
     *
     * Auto-filed CRASH tickets are deliberately excluded. Nobody wrote in: the
     * reporter is whichever user's browser happened to throw, often a parent reading
     * their child's page. Telling them "your support ticket has been resolved" would
     * be inventing a conversation they never had.
     */
    private function notifyReporterResolved($ticket, string $status, $actor): void
    {
        try {
            /* BOTH machine prefixes. This matched only the CLIENT crash endpoint
               ("...from a crash report"), while the server-side handler writes
               "...from an unhandled server error" - 45 of the 77 auto-filed tickets on
               2026-09-23, every one of them able to mail a "your request has been
               resolved" to whichever user happened to be signed in when the server
               threw. Matching the shared prefix covers both and any third filer. */
            if (str_starts_with((string) $ticket->body, 'Automatically filed from ')) {
                return;   // machine-filed; there is no correspondent
            }
            $uid = (int) ($ticket->raised_by_user_id ?? 0);
            if (! $uid) return;
            $user = DB::table('users')->where('id', $uid)->whereNull('deleted_at')->first();
            if (! $user || empty($user->email)) return;

            $agency = $ticket->agency_id ? DB::table('agencies')->where('id', $ticket->agency_id)->first() : null;
            $agencyName = $agency->name ?? 'KiddieTrac';
            $tz = \App\Support\AgencyTime::tz($ticket->agency_id ? (int) $ticket->agency_id : null);
            $when = now()->setTimezone($tz);
            $first = trim((string) ($user->first_name ?? '')) ?: 'Hello';
            $word = $status === 'closed' ? 'closed' : 'resolved';

            $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">' . e($first) . ',</p>'
                . '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">'
                . 'The request you raised with <strong>' . e($agencyName) . '</strong> has been ' . e($word) . '.</p>'
                . '<div class="kt-panel" style="background:#F8FAFC;border:1px solid #E2E8F0;--kt-edge:#1F6FB2;border-radius:10px;padding:14px 16px;margin:0 0 14px;">'
                .   '<div class="kt-muted" style="font-size:11px;font-weight:800;letter-spacing:1px;color:#64748B;text-transform:uppercase;margin-bottom:4px;">Ticket #' . (int) $ticket->id . '</div>'
                .   '<div style="font-size:15px;font-weight:800;color:#0F172A;">' . e((string) $ticket->subject) . '</div>'
                .   '<div class="kt-muted" style="font-size:12.5px;color:#64748B;margin-top:6px;">Raised ' . e(\App\Support\AgencyTime::fmt($ticket->created_at, $tz, 'D, M j, Y') ?? '') . '</div>'
                . '</div>'
                . '<p style="margin:0 0 6px;font-size:14px;line-height:1.6;color:#475569;">'
                . 'Marked ' . e($word) . ' on ' . e($when->format('D, M j, Y')) . ' at ' . e($when->format('g:i A'))
                . ' (' . e($when->format('T')) . ').</p>'
                . '<p style="margin:0;font-size:14px;line-height:1.6;color:#475569;">'
                . 'If this is not sorted, reply to this email and it will be reopened.</p>';

            $html = \App\Services\EmailTemplate::wrap(
                $ticket->agency_id ? (int) $ticket->agency_id : null,
                $body,
                ['eyebrow' => 'SUPPORT', 'title' => 'Your request has been ' . $word,
                 'preheader' => 'Ticket #' . (int) $ticket->id . ' — ' . (string) $ticket->subject]
            );

            \App\Services\AgencyMailer::forAgency($ticket->agency_id ? (int) $ticket->agency_id : null)
                ->html($html, function ($m) use ($user, $ticket, $word) {
                    $m->to($user->email)
                      ->bcc('info@kiddietrac.com')
                      ->subject('Your request has been ' . $word . ' — ticket #' . (int) $ticket->id);
                });
        } catch (\Throwable $e) {
            // Never let the notification stop the ticket being resolved.
            \Illuminate\Support\Facades\Log::error('Ticket resolution notice failed', [
                'ticket' => $ticket->id ?? null, 'error' => $e->getMessage(),
            ]);
        }
    }
}
