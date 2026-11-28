<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p57 — Drip campaigns + curriculum library.
 */
final class GrowthController extends Controller
{
    // ===== Drip campaigns (extends marketing_campaigns) =====
    public function listDrip(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $q = DB::table('marketing_campaigns')
            ->where('agency_id', $agencyId)
            ->whereNotNull('trigger_event')
            ->whereNull('deleted_at');   // a deleted drip is kept (audit) but never listed (2026-11-28)
        /* A director sees agency-wide drips and their own centres' only (2026-11-05 leak sweep). */
        $scope = $this->directorScope($request, $agencyId);
        if ($scope !== null) $q->where(fn ($x) => $x->whereNull('centre_id')->orWhereIn('centre_id', $scope ?: [0]));
        $rows = $q->orderByDesc('created_at')->get(['id', 'agency_id', 'centre_id', 'title', 'subject', 'body_html', 'status', 'trigger_event', 'trigger_delay_days', 'created_at', 'updated_at']);
        /* EACH DRIP'S NUMBERS (2026-11-28): how many went, failed, are waiting, and when the last one went. */
        $byId = [];
        if ($rows->isNotEmpty()) {
            foreach (DB::table('drip_sends')->whereIn('campaign_id', $rows->pluck('id'))
                ->selectRaw('campaign_id, status, COUNT(*) n, MAX(sent_at) last_sent')->groupBy('campaign_id', 'status')->get() as $r) {
                $byId[(int) $r->campaign_id][$r->status] = (int) $r->n;
                if ($r->last_sent) $byId[(int) $r->campaign_id]['last_sent_at'] = max($byId[(int) $r->campaign_id]['last_sent_at'] ?? '', (string) $r->last_sent);
            }
        }
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('name', 'id');
        foreach ($rows as $r) {
            $n = $byId[(int) $r->id] ?? [];
            $r->stats = ['sent' => $n['sent'] ?? 0, 'failed' => $n['failed'] ?? 0, 'queued' => $n['queued'] ?? 0, 'last_sent_at' => $n['last_sent_at'] ?? null];
            $r->centre_name = $r->centre_id ? ($centres[(int) $r->centre_id] ?? null) : null;
            $r->may_edit = $scope === null || ($r->centre_id && in_array((int) $r->centre_id, $scope, true));
        }
        return response()->json(['data' => $rows, 'triggers' => self::DRIP_TRIGGERS, 'scoped' => $scope !== null]);
    }

    /** What each trigger is called on the screen; only these are sent by drip:dispatch. */
    private const DRIP_TRIGGERS = ['tour_booked' => 'After a tour is booked', 'enrollment_complete' => 'After enrolment is complete', 'birthday' => 'On a child\'s birthday'];

    /** A drip this caller may CHANGE: this agency, not deleted; a director only their own centres' (never agency-wide). */
    private function ownDrip(Request $request, int $agencyId, int $id): object
    {
        $c = DB::table('marketing_campaigns')->where('id', $id)->where('agency_id', $agencyId)
            ->whereNotNull('trigger_event')->whereNull('deleted_at')->first();
        abort_unless($c, 404, 'That drip campaign was not found.');
        $scope = $this->directorScope($request, $agencyId);
        if ($scope !== null) {
            abort_unless($c->centre_id && in_array((int) $c->centre_id, $scope, true), $c->centre_id ? 404 : 403,
                $c->centre_id ? 'That drip campaign was not found.' : 'Only an agency admin can change an agency-wide drip campaign.');
        }
        return $c;
    }

    /**
     * PUT /marketing/drip/{id} - change a drip (2026-11-28). Only what has NOT gone yet changes: every send is keyed on
     * (drip, the event that triggered it) in drip_sends, so nobody who already got it is sent it again, and a new
     * subject or text reaches only the people still to come. Changing WHAT starts it once it has sent would be a
     * different drip, so that is refused (make a new one).
     */
    public function updateDrip(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'subject' => 'required|string|max:200',
            'body_html' => 'required|string',
            'trigger_event' => 'required|string|in:tour_booked,enrollment_started,enrollment_complete,birthday,inactivity_30d',
            'trigger_delay_days' => 'required|integer|min:0|max:365',
            'centre_id' => 'nullable|integer',
        ]);
        $agencyId = $this->resolveAgencyId($request);
        $c = $this->ownDrip($request, $agencyId, $id);
        $this->checkCentre($request, $agencyId, $data['centre_id'] ?? null, false);
        $sent = DB::table('drip_sends')->where('campaign_id', $id)->exists();
        abort_if($sent && $data['trigger_event'] !== $c->trigger_event, 422,
            'This drip has already sent emails, so what starts it cannot change. Create a new drip campaign instead.');
        $was = ['title' => $c->title, 'subject' => $c->subject, 'trigger_event' => $c->trigger_event, 'trigger_delay_days' => (int) $c->trigger_delay_days, 'centre_id' => $c->centre_id];
        DB::table('marketing_campaigns')->where('id', $id)->update([
            'title' => $data['title'], 'subject' => $data['subject'], 'body_html' => $data['body_html'],
            'audience' => 'custom',   // the enum has no drip value (2026-11-28); trigger_event marks a drip 'trigger_event' => $data['trigger_event'],
            'trigger_delay_days' => $data['trigger_delay_days'], 'centre_id' => $data['centre_id'] ?? null, 'updated_at' => now(),
        ]);
        $this->dripAudit($request, $agencyId, 'drip.updated', $id, ['was' => $was, 'body_changed' => $c->body_html !== $data['body_html']]);
        return response()->json(['ok' => true]);
    }

    /** POST /marketing/drip/{id}/pause and /resume. A paused drip sends nothing; events while paused are not caught up. */
    public function pauseDrip(Request $request, int $id): JsonResponse
    {
        return $this->setDripStatus($request, $id, 'paused');
    }

    public function resumeDrip(Request $request, int $id): JsonResponse
    {
        return $this->setDripStatus($request, $id, 'active');
    }

    private function setDripStatus(Request $request, int $id, string $to): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $c = $this->ownDrip($request, $agencyId, $id);
        if ($c->status !== $to) {
            DB::table('marketing_campaigns')->where('id', $id)->update(['status' => $to, 'updated_at' => now()]);
            $this->dripAudit($request, $agencyId, $to === 'paused' ? 'drip.paused' : 'drip.resumed', $id, ['title' => $c->title]);
        }
        return response()->json(['ok' => true, 'status' => $to]);
    }

    /** DELETE /marketing/drip/{id} - soft delete: deleted_at set, the sends kept, an audit row written. */
    public function deleteDrip(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $c = $this->ownDrip($request, $agencyId, $id);
        DB::table('marketing_campaigns')->where('id', $id)->update(['deleted_at' => now(), 'status' => 'paused', 'updated_at' => now()]);
        $this->dripAudit($request, $agencyId, 'drip.deleted', $id, ['title' => $c->title, 'trigger_event' => $c->trigger_event,
            'sent' => DB::table('drip_sends')->where('campaign_id', $id)->where('status', 'sent')->count()]);
        return response()->json(['ok' => true]);
    }

    private function dripAudit(Request $request, int $agencyId, string $action, int $id, array $payload): void
    {
        \App\Support\Audit::write(['user_id' => $request->user()->id, 'agency_id' => $agencyId, 'action' => $action,
            'entity_type' => 'marketing_campaign', 'entity_id' => $id, 'payload' => json_encode($payload)]);
    }

    public function createDrip(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'subject' => 'required|string|max:200',
            'body_html' => 'required|string',
            'trigger_event' => 'required|string|in:tour_booked,enrollment_started,enrollment_complete,birthday,inactivity_30d',
            'trigger_delay_days' => 'required|integer|min:0|max:365',
            'centre_id' => 'nullable|integer',
        ]);
        $agencyId = $this->resolveAgencyId($request);
        // A director's drip goes to their own centre only, never the whole agency (2026-11-05 leak sweep).
        $this->checkCentre($request, $agencyId, $data['centre_id'] ?? null, false);
        $id = DB::table('marketing_campaigns')->insertGetId([
            'agency_id' => $agencyId,
            'centre_id' => $data['centre_id'] ?? null,
            'created_by_user_id' => $request->user()->id,
            'title' => $data['title'],
            'subject' => $data['subject'],
            'body_html' => $data['body_html'],
            'audience' => 'custom',   // the enum has no drip value (2026-11-28); trigger_event marks a drip
            'channel' => 'email',
            'status' => 'active',
            'trigger_event' => $data['trigger_event'],
            'trigger_delay_days' => $data['trigger_delay_days'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->dripAudit($request, $agencyId, 'drip.created', $id, ['title' => $data['title'], 'trigger_event' => $data['trigger_event']]);
        return response()->json(['id' => $id], 201);
    }

    public function dripStats(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $cq = DB::table('marketing_campaigns')->where('agency_id', $agencyId)
            ->whereNotNull('trigger_event')->whereNull('deleted_at');
        // Same centre limit as listDrip (2026-11-05 leak sweep).
        $scope = $this->directorScope($request, $agencyId);
        if ($scope !== null) $cq->where(fn ($x) => $x->whereNull('centre_id')->orWhereIn('centre_id', $scope ?: [0]));
        $campaignIds = $cq->pluck('id');
        $sent = DB::table('drip_sends')->whereIn('campaign_id', $campaignIds);
        return response()->json([
            'campaigns' => $campaignIds->count(),
            'active' => (clone $cq)->where('status', 'active')->count(),
            'queued' => (clone $sent)->where('status', 'queued')->count(),
            'sent' => (clone $sent)->where('status', 'sent')->count(),
            'failed' => (clone $sent)->where('status', 'failed')->count(),
        ]);
    }

    // ===== Curriculum library =====
    public function listCurriculum(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('curriculum_templates as ct')
            ->leftJoin('users as u', 'u.id', '=', 'ct.created_by_user_id')
            // One-activity ideas only; multi-week units have their own screen (CurriculumUnitController, 2026-11-03).
            ->where(fn ($k) => $k->whereNull('ct.kind')->orWhere('ct.kind', 'activity'))
            ->where(function ($q) use ($agencyId) {
                $q->where('ct.agency_id', $agencyId)->orWhere('ct.visibility', 'platform');
            })
            ->orderByDesc('ct.is_featured')->orderBy('ct.title')
            ->select('ct.*', DB::raw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as author_name"))
            ->get();
        $rows->transform(function ($r) {
            $r->activities = $r->activities ? json_decode($r->activities, true) : [];
            $r->materials = $r->materials ? json_decode($r->materials, true) : [];
            $r->hdlh_domains = $r->hdlh_domains ? json_decode($r->hdlh_domains, true) : [];
            return $r;
        });
        return response()->json(['data' => $rows]);
    }

    public function createCurriculum(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'nullable|integer',
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:5000',
            'age_band' => 'nullable|string|max:40',
            'duration_minutes' => 'nullable|integer|min:5|max:240',
            'materials' => 'nullable|array',
            'hdlh_domains' => 'nullable|array',
            'activities' => 'nullable|array',
            'visibility' => 'nullable|in:private,centre,agency,platform',
        ]);
        $agencyId = $this->resolveAgencyId($request);
        // centre_id must be this agency's, and a director's own when given (2026-11-05 leak sweep).
        $this->checkCentre($request, $agencyId, $data['centre_id'] ?? null, true);
        $id = DB::table('curriculum_templates')->insertGetId([
            'agency_id' => $agencyId,
            'centre_id' => $data['centre_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'age_band' => $data['age_band'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'materials' => isset($data['materials']) ? json_encode($data['materials']) : null,
            'hdlh_domains' => isset($data['hdlh_domains']) ? json_encode($data['hdlh_domains']) : null,
            'activities' => isset($data['activities']) ? json_encode($data['activities']) : null,
            /* 'platform' puts a template in EVERY agency's library: only KiddieTrac (platform admins)
               may do that (2026-11-03; any director could before). */
            'visibility' => (($data['visibility'] ?? 'agency') === 'platform' && ! DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists())
                ? 'agency' : ($data['visibility'] ?? 'agency'),
            'created_by_user_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
    }

    public function useCurriculum(Request $request, int $id): JsonResponse
    {
        /* Scoped (2026-11-03): this counted and RETURNED any agency's template by id. Only the
           library and the active agency's own templates now. */
        $agencyId = $this->resolveAgencyId($request);
        $scope = fn () => DB::table('curriculum_templates')->where('id', $id)
            ->where(fn ($q) => $q->where('agency_id', $agencyId)->orWhere('visibility', 'platform'));
        abort_unless($scope()->exists(), 404);
        $scope()->update(['use_count' => DB::raw('use_count + 1'), 'updated_at' => now()]);
        $row = $scope()->first();
        return response()->json(['data' => $row]);
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
        /* The membership-checked active agency (2026-11-05 leak sweep): this took the first role row's agency_id,
           which is NULL for a director's centre row (400) or another agency's for a person with roles in two. */
        $first = \App\Support\AgencyRole::activeAgency($request);
        abort_unless($first, 400);
        return (int) $first;
    }

    /* The centres a director may run drip campaigns / centre templates for (2026-11-05 leak sweep): null = agency-wide
       (admin / platform); otherwise the centres where the caller is centre_director in this agency. Fails closed. */
    private function directorScope(Request $request, int $agencyId): ?array
    {
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        if ($scope === null) return null;
        $dir = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))
            ->where('user_id', $request->user()->id)->where('role', 'centre_director')->where('active', true)
            ->whereNotNull('centre_id')->pluck('centre_id')->map(fn ($v) => (int) $v)->all();
        return array_values(array_intersect($scope, $dir));
    }

    /* A submitted centre_id must be a centre of this agency and, for a director, one they direct (2026-11-05 leak
       sweep): it was written unchecked, so a drip campaign or template could be filed against any centre id. */
    private function checkCentre(Request $request, int $agencyId, $centreId, bool $nullAllowedForScoped): void
    {
        if (! empty($centreId)) {
            abort_unless(DB::table('centres')->where('id', (int) $centreId)->where('agency_id', $agencyId)->exists(), 422, 'That centre is not in your agency.');
        }
        $scope = $this->directorScope($request, $agencyId);
        if ($scope === null) return;
        if (empty($centreId)) {
            abort_unless($nullAllowedForScoped, 403, 'Choose one of your centres.');
            return;
        }
        abort_unless(in_array((int) $centreId, $scope, true), 403, 'You can only choose a centre you direct.');
    }
}
