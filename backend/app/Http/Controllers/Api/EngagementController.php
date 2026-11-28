<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * v22p56 — Procare-parity engagement:
 *   - Re-enrollment campaigns (Sept / Jan cycle invites)
 *   - Family engagement scores (heuristic)
 *   - NPS survey automation
 *   - Document e-sign with audit trail
 */
final class EngagementController extends Controller
{
    use ResolvesCentreContext;

    // =====================
    // Re-enrollment campaigns
    // =====================
    public function listCampaigns(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        /* Centre scope (2026-11-27, same shape as vacation holds / tuition increases): a director saw every centre's
           campaigns. They now see their own centres' and the agency-wide ones; the centre is named on each row. */
        $scope = \App\Support\CentreScope::ids($request, (int) $agencyId);
        $rows = DB::table('reenrollment_campaigns as rc')->leftJoin('centres as c', 'c.id', '=', 'rc.centre_id')
            ->where('rc.agency_id', $agencyId)
            ->when($scope !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('rc.centre_id')->orWhereIn('rc.centre_id', $scope ?: [0])))
            ->orderByDesc('rc.created_at')->select('rc.*', 'c.name as centre_name')->get();
        /* Counts from the answers themselves (2026-11-28, ReenrolReplies): Responded / Renewed never moved because no
           family could answer. A director's counts on an agency-wide campaign are their own centres' families only. */
        $counts = \App\Support\ReenrolReplies::counts($rows->pluck('id')->all(), $scope);
        $today = \App\Support\AgencyTime::today((int) $agencyId);
        $remDays = \App\Support\BillingRules::reenrolReminderDays((int) $agencyId);
        $rows = $rows->map(function ($r) use ($counts, $today, $remDays) {
            $n = $counts[(int) $r->id] ?? ['invited' => 0, 'responded' => 0, 'renewed' => 0, 'declined' => 0, 'unsure' => 0];
            $r->total_invited = $n['invited'];
            $r->total_responded = $n['responded'];
            $r->total_renewed = $n['renewed'];
            $r->total_declined = $n['declined'];
            $r->open = $r->status === 'sent' && $today <= substr((string) $r->deadline, 0, 10);
            $r->reminder_on = $remDays > 0 ? Carbon::parse(substr((string) $r->deadline, 0, 10))->subDays($remDays)->toDateString() : null;

            return $r;
        });

        return response()->json(['data' => $rows, 'reminder_days' => $remDays]);
    }

    /** GET /engagement/reenrollment/{id} - each family's answers, child by child (a director: their own centres). */
    public function showCampaign(Request $request, int $id): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        $c = DB::table('reenrollment_campaigns')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($c && ($scope === null || $c->centre_id === null || in_array((int) $c->centre_id, $scope, true)), 404);
        $q = DB::table('reenrollment_responses as r')->join('children as ch', 'ch.id', '=', 'r.child_id')
            ->join('families as f', 'f.id', '=', 'r.family_id')->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->where('r.campaign_id', $id)->where('ce.agency_id', $agencyId)
            ->when($scope !== null, fn ($w) => $w->whereIn('f.centre_id', $scope ?: [0]))
            ->orderBy('f.family_name')->orderBy('ch.first_name');
        $rows = $q->get(['r.family_id', 'f.family_name', 'ce.name as centre_name', 'r.child_id', 'ch.first_name', 'ch.last_name', 'r.response', 'r.notes', 'r.responded_at', 'r.responded_via', 'r.reminded_at']);
        $families = $rows->groupBy('family_id')->map(fn ($g) => [
            'family_id' => (int) $g[0]->family_id, 'family_name' => $g[0]->family_name, 'centre_name' => $g[0]->centre_name,
            'children' => $g->map(fn ($r) => ['name' => trim($r->first_name . ' ' . $r->last_name), 'response' => $r->response, 'notes' => $r->notes,
                'responded_at' => $r->responded_at, 'via' => $r->responded_via, 'reminded_at' => $r->reminded_at])->values(),
        ])->values();
        $n = \App\Support\ReenrolReplies::counts([$id], $scope)[$id] ?? null;

        return response()->json(['data' => $c, 'counts' => $n, 'families' => $families]);
    }

    /** GET /engagement/reenrollment/mine - the signed-in parent's open (and recently closed) re-enrolments. */
    public function myCampaigns(Request $request): JsonResponse
    {
        $familyIds = DB::table('guardians')->where('user_id', $request->user()->id)->pluck('family_id')->map(fn ($v) => (int) $v)->all();
        $out = [];
        if ($familyIds) {
            $camps = DB::table('reenrollment_responses as r')->join('reenrollment_campaigns as c', 'c.id', '=', 'r.campaign_id')
                ->whereIn('r.family_id', $familyIds)->where('c.status', 'sent')
                ->whereDate('c.deadline', '>=', now()->subDays(60)->toDateString())
                ->select('c.*', 'r.family_id')->distinct()->orderByDesc('c.deadline')->get();
            foreach ($camps as $c) {
                $out[] = [
                    'id' => (int) $c->id, 'family_id' => (int) $c->family_id, 'name' => $c->campaign_name, 'term' => $c->target_term,
                    'deadline' => substr((string) $c->deadline, 0, 10), 'open' => \App\Support\ReenrolReplies::isOpen($c),
                    'children' => \App\Support\ReenrolReplies::children((int) $c->id, (int) $c->family_id),
                ];
            }
        }

        return response()->json(['data' => $out]);
    }

    public function createCampaign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'nullable|integer',
            'campaign_name' => 'required|string|max:120',
            'target_term' => 'required|string|max:40',
            'deadline' => 'required|date|after:today',
        ]);
        $agencyId = $this->resolveAgencyId($request);
        /* The centre must be one you run, in this agency; only an agency-wide admin may send to
           the whole agency (2026-11-05 leak sweep). centre_id was written unchecked and a
           director who left it blank invited - and notified - every family in the agency. */
        $scope = \App\Support\CentreScope::ids($request, (int) $agencyId);
        if (! empty($data['centre_id'])) {
            $inAgency = (int) DB::table('centres')->where('id', $data['centre_id'])->value('agency_id') === (int) $agencyId;
            if (! $inAgency || ! \App\Support\CentreScope::canWrite($scope, $data['centre_id'])) {
                return response()->json(['message' => 'That centre is not one you manage.'], 403);
            }
        } elseif ($scope !== null) {
            return response()->json(['message' => 'Choose one of your centres for this campaign.'], 422);
        }
        // The deadline is a day in the agency's own calendar (the "after:today" above is the server's).
        if (substr((string) $data['deadline'], 0, 10) <= \App\Support\AgencyTime::today((int) $agencyId)) {
            return response()->json(['message' => 'The deadline must be after today.'], 422);
        }
        // Find all currently-enrolled children at this centre/agency
        $q = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')
            ->select('ch.id as child_id', 'ch.family_id');
        // Only families still with us at open centres (2026-11-27): a departed family or a closed centre's is never invited.
        \App\Support\Live::families($q, 'f');
        \App\Support\Live::centres($q, 'c');
        if (!empty($data['centre_id'])) $q->where('f.centre_id', $data['centre_id']);
        $kids = $q->get();

        $campaignId = DB::table('reenrollment_campaigns')->insertGetId([
            'agency_id' => $agencyId,
            'centre_id' => $data['centre_id'] ?? null,
            'campaign_name' => $data['campaign_name'],
            'target_term' => $data['target_term'],
            'deadline' => $data['deadline'],
            'status' => 'sent',
            'sent_at' => now(),
            'total_invited' => $kids->count(),
            'created_by_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($kids as $k) {
            DB::table('reenrollment_responses')->insertOrIgnore([
                'campaign_id' => $campaignId,
                'family_id' => $k->family_id,
                'child_id' => $k->child_id,
                'response' => 'pending',
                'created_at' => now(),
            ]);
        }
        /* Each guardian: a bell AND a house-frame email whose button opens the family's signed reply page
           (2026-11-28, ReenrolReplies). Before, a bell linked to a #reenrollment screen parents did not have. */
        $campaign = \App\Support\ReenrolReplies::campaign((int) $campaignId);
        $told = \App\Support\ReenrolReplies::notifyFamilies($campaign, $kids->pluck('family_id')->unique()->all(), 'invite');
        \App\Support\Audit::write(['user_id' => $request->user()->id, 'agency_id' => (int) $agencyId, 'action' => 'reenrolment.launched',
            'entity_type' => 'reenrollment_campaign', 'entity_id' => (int) $campaignId,
            'payload' => json_encode(['summary' => 'Re-enrolment for ' . $data['target_term'] . ' launched: ' . $kids->count() . ' children, ' . $told . ' families told'], JSON_UNESCAPED_UNICODE),
            'created_at' => now()]);
        return response()->json(['id' => $campaignId, 'invited' => $kids->count(), 'families' => $told], 201);
    }

    /* POST /engagement/reenrollment/{id}/respond - the parent's answers in the portal: {answers:[{child_id, response,
       note}]} or the old single {child_id, response, notes}. Only the parent's own family's invited children, only
       until the deadline (ReenrolReplies::answer). */
    public function respondCampaign(Request $request, int $campaignId): JsonResponse
    {
        $data = $request->validate([
            'answers' => 'nullable|array|max:20',
            'answers.*.child_id' => 'required_with:answers|integer',
            'answers.*.response' => 'required_with:answers|in:yes,no,unsure',
            'answers.*.note' => 'nullable|string|max:500',
            'child_id' => 'required_without:answers|integer',
            'response' => 'required_without:answers|in:yes,no,unsure',
            'notes' => 'nullable|string|max:500',
        ]);
        $answers = $data['answers'] ?? [['child_id' => $data['child_id'], 'response' => $data['response'], 'note' => $data['notes'] ?? null]];
        $u = $request->user();
        $c = \App\Support\ReenrolReplies::campaign($campaignId);
        abort_unless($c, 404);
        // The family: the one this parent is a guardian of AND the children belong to (never the first guardian row).
        $childFamily = DB::table('children')->whereIn('id', array_map(fn ($a) => (int) $a['child_id'], $answers))->distinct()->pluck('family_id')->map(fn ($v) => (int) $v)->all();
        $familyId = count($childFamily) === 1 ? $childFamily[0] : 0;
        abort_unless($familyId && DB::table('guardians')->where('user_id', $u->id)->where('family_id', $familyId)->exists(), 403);
        [$ok, $msg] = \App\Support\ReenrolReplies::answer($c, $familyId, $answers, (int) $u->id, 'portal');
        if (! $ok) return response()->json(['message' => $msg], 422);

        return response()->json(['ok' => true, 'message' => $msg, 'children' => \App\Support\ReenrolReplies::children($campaignId, $familyId)]);
    }

    // =====================
    // Family engagement scores
    // =====================
    public function engagementScores(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $now = Carbon::now();
        $win30 = $now->copy()->subDays(30);

        $families = DB::table('families as f')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
            ->whereNull('f.deleted_at')
            ->select('f.id', 'f.family_name')
            ->get();

        $rows = $families->map(function ($fam) use ($win30, $agencyId) {
            $famId = (int) $fam->id;
            $childIds = DB::table('children')->where('family_id', $famId)->whereNull('deleted_at')->pluck('id');
            // Signals: messages sent, observations read, photos viewed, forms submitted, feedback given
            $msgs = DB::table('messages')->where('sender_id',
                DB::table('guardians')->where('family_id', $famId)->value('user_id'))
                ->where('created_at', '>=', $win30)->count();
            $obs = DB::table('observations')->whereIn('child_id', $childIds)
                ->where('observed_at', '>=', $win30)->count();
            $logs = DB::table('daily_care_logs')->whereIn('child_id', $childIds)
                ->where('occurred_at', '>=', $win30)->count();
            $feedback = DB::table('parent_feedback')->where('family_id', $famId)
                ->where('created_at', '>=', $win30)->count();
            $forms = DB::table('custom_form_responses')->where('submitted_by_user_id',
                DB::table('guardians')->where('family_id', $famId)->value('user_id'))
                ->where('submitted_at', '>=', $win30)->count();

            $score = min(100,
                ($msgs * 8) + ($obs * 2) + ($logs * 1) + ($feedback * 15) + ($forms * 10)
            );
            $signals = [];
            if ($msgs > 0) $signals[] = "{$msgs} messages";
            if ($feedback > 0) $signals[] = "{$feedback} feedback";
            if ($forms > 0) $signals[] = "{$forms} forms";
            if ($obs > 5) $signals[] = "{$obs} observations viewed";

            return [
                'family_id' => $famId,
                'family_name' => $fam->family_name,
                'score' => $score,
                'bucket' => $score >= 60 ? 'engaged' : ($score >= 25 ? 'moderate' : 'low'),
                'signals' => $signals,
            ];
        })->sortByDesc('score')->values();

        // Cache snapshot
        DB::table('engagement_scores')->where('agency_id', $agencyId)
            ->where('computed_at', '<', $now->copy()->subDay())->delete();
        foreach ($rows as $r) {
            DB::table('engagement_scores')->insert([
                'agency_id' => $agencyId,
                'family_id' => $r['family_id'],
                'score' => $r['score'],
                'signals' => json_encode($r['signals']),
                'computed_at' => $now,
            ]);
        }
        return response()->json(['data' => $rows]);
    }

    // =====================
    // NPS survey
    // =====================
    public function submitNps(Request $request): JsonResponse
    {
        $data = $request->validate([
            'score' => 'required|integer|min:0|max:10',
            'comment' => 'nullable|string|max:2000',
        ]);
        $u = $request->user();
        $familyId = DB::table('guardians')->where('user_id', $u->id)->value('family_id');
        $family = $familyId ? DB::table('families')->where('id', $familyId)->first() : null;
        $centre = $family ? DB::table('centres')->where('id', $family->centre_id)->first() : null;
        $agencyId = $centre ? $centre->agency_id : ($this->resolveAgencyId($request));
        $id = DB::table('nps_responses')->insertGetId([
            'agency_id' => $agencyId,
            'centre_id' => $family ? $family->centre_id : null,
            'family_id' => $familyId,
            'user_id' => $u->id,
            'score' => $data['score'],
            'comment' => $data['comment'] ?? null,
            'survey_period' => Carbon::now()->format('Y-Q'),
            'created_at' => now(),
        ]);
        // Notify directors of detractors (≤6)
        if ($data['score'] <= 6 && $family) {
            $dids = DB::table('role_assignments')->where('centre_id', $family->centre_id)
                ->whereIn('role', ['centre_director', 'agency_admin'])->where('active', 1)
                ->pluck('user_id')->unique();
            foreach ($dids as $did) {
                \App\Support\Notify::write([
                    'user_id' => $did, 'type' => 'nps',
                    'title' => "NPS detractor: {$data['score']}/10 from " . ($family->family_name ?? 'family'),
                    'body' => substr((string) ($data['comment'] ?? ''), 0, 200),
                    'data' => json_encode(['link' => '#nps']),
                    'created_at' => now(),
                ]);
            }
        }
        return response()->json(['id' => $id], 201);
    }

    public function npsSummary(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('nps_responses')->where('agency_id', $agencyId)
            ->where('created_at', '>=', Carbon::now()->subMonths(12))
            ->orderByDesc('created_at')->limit(500)->get();
        $promoters = $rows->where('score', '>=', 9)->count();
        $detractors = $rows->where('score', '<=', 6)->count();
        $passive = $rows->count() - $promoters - $detractors;
        $nps = $rows->count() > 0 ? round((($promoters - $detractors) / $rows->count()) * 100) : 0;
        return response()->json([
            'data' => $rows,
            'summary' => [
                'count' => $rows->count(),
                'nps' => $nps,
                'promoters' => $promoters,
                'passive' => $passive,
                'detractors' => $detractors,
            ],
        ]);
    }

    // =====================
    // Document e-sign
    // =====================
    public function signDocument(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type' => 'required|string|max:60',
            'source_table' => 'nullable|string|max:40',
            'source_id' => 'nullable|integer',
            'signer_name' => 'required|string|max:120',
            'signature_data' => 'required|string',
            'document_text' => 'required|string|max:50000',
        ]);
        $u = $request->user();
        $agencyId = $this->resolveAgencyId($request);
        $hash = hash('sha256', $data['document_text'] . '|' . $u->id . '|' . microtime(true));
        // Signature stamp (2026-11-27): IP + approximate place, read on the server at signing time.
        $stamp = \App\Support\SignatureStamp::capture($request);
        $id = DB::table('signed_documents')->insertGetId([
            'agency_id' => $agencyId,
            'document_type' => $data['document_type'],
            'source_table' => $data['source_table'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'signer_user_id' => $u->id,
            'signer_name' => $data['signer_name'],
            'signature_data' => $data['signature_data'],
            'document_hash' => $hash,
            'signed_at' => now(),
            'ip_address' => $stamp['ip'] ?? $request->ip(),
            'signer_location' => $stamp['location'],
            'user_agent' => substr((string) $request->userAgent(), 0, 300),
            'created_at' => now(),
        ]);
        return response()->json(['id' => $id, 'hash' => $hash, 'signed_at' => now()->toIso8601String()], 201);
    }

    public function listSignedDocs(Request $request): JsonResponse
    {
        $u = $request->user();

        // TENANT ISOLATION: an admin/director may see signed documents ONLY for
        // users in THEIR agency/centre — never every agency's. (The old query
        // returned ALL signed_documents to anyone holding an admin role anywhere,
        // leaking other agencies' signed docs.) Build the allowed signer set.
        $roles = DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)->pluck('role')->all();
        $isPlatform    = in_array('platform_admin', $roles, true);
        $isAgencyAdmin = in_array('agency_admin', $roles, true);
        $isDirector    = in_array('centre_director', $roles, true);

        $scopedUserIds = [(int) $u->id]; // always your own signed docs
        if ($isPlatform || $isAgencyAdmin || $isDirector) {
            $centreIds = [];
            if ($isDirector && ! $isAgencyAdmin && ! $isPlatform) {
                // Director: only the centre(s) they direct.
                $centreIds = DB::table('role_assignments')->where('user_id', $u->id)
                    ->where('role', 'centre_director')->where('active', 1)->whereNotNull('centre_id')
                    ->pluck('centre_id')->all();
            } else {
                // Agency admin / platform admin: the active agency they're viewing.
                $agencyId = $isPlatform ? (int) $request->header('X-Active-Agency-Id') : (int) (\App\Support\AgencyRole::activeAgency($request) ?? \App\Support\AuditScope::ownAgency((int) $u->id) ?? 0);
                if ($agencyId && DB::table('agencies')->where('id', $agencyId)->whereNull('deleted_at')->exists()) {
                    $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
                    $agencyStaff = DB::table('role_assignments')->where('agency_id', $agencyId)->where('active', 1)->pluck('user_id')->all();
                    $scopedUserIds = array_merge($scopedUserIds, $agencyStaff);
                }
            }
            if (! empty($centreIds)) {
                $centreStaff = DB::table('role_assignments')->whereIn('centre_id', $centreIds)->where('active', 1)->pluck('user_id')->all();
                $guardians = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
                    ->whereIn('f.centre_id', $centreIds)->pluck('g.user_id')->all();
                $scopedUserIds = array_merge($scopedUserIds, $centreStaff, $guardians);
            }
        }
        $scopedUserIds = array_values(array_unique(array_map('intval', $scopedUserIds)));

        $rows = DB::table('signed_documents as sd')
            ->leftJoin('users as u', 'u.id', '=', 'sd.signer_user_id')
            ->whereIn('sd.signer_user_id', $scopedUserIds ?: [(int) $u->id])
            /* TENANT, NOT JUST PERSON (leak guard, 2026-11-27): the signer list is a PERSON filter - someone who is
               staff or a parent here may have signed papers for ANOTHER agency (Test Agency's admin was shown a
               platform admin's iLearn NDA, signature and IP included). Your own papers always; anyone else's only
               when filed under the agency on screen. */
            ->where(fn ($q) => $q->where('sd.signer_user_id', (int) $u->id)
                ->orWhere('sd.agency_id', ($isPlatform ? (int) $request->header('X-Active-Agency-Id') : (int) (\App\Support\AgencyRole::activeAgency($request) ?? 0)) ?: -1))
            ->orderByDesc('sd.signed_at')
            ->limit(200)
            ->select('sd.*', DB::raw("CONCAT(u.first_name,' ',u.last_name) as signer_full_name"), DB::raw("(CASE WHEN sd.document_type='privacy_nda' THEN (SELECT d.file_url FROM documents d WHERE d.scope_type='user' AND d.scope_id=sd.signer_user_id AND d.category='agreement' ORDER BY d.id DESC LIMIT 1) ELSE NULL END) as doc_file_url"))
            ->get()
            /* The signature stamp (2026-11-27) is drawn only in the signed-document view this list opens, for the
               same people who can already open it; the raw location column is not passed on. */
            ->map(function ($r) {
                $r->stamp = \App\Support\SignatureStamp::lines($r->signed_at, $r->ip_address ?? null, $r->signer_location ?? null, $r->agency_id ? (int) $r->agency_id : null) ?: null;
                unset($r->signer_location);
                return $r;
            });
        return response()->json(['data' => $rows]);
    }

    public function downloadSignedDoc(Request $request, int $id): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $u = $request->user();
        $sd = DB::table('signed_documents')->where('id', $id)->first();
        abort_unless($sd, 404);
        // Same access as the list: the signer, or any admin/director/platform_admin.
        $isAdmin = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])
            ->where('active', 1)->exists();
        abort_unless((int) $sd->signer_user_id === (int) $u->id || $isAdmin, 403);
        // Someone else's paper: only one filed under the agency on screen (leak guard, 2026-11-27 - was any id, any agency).
        $onScreen = DB::table('role_assignments')->where('user_id', $u->id)->where('role', 'platform_admin')->where('active', 1)->exists()
            ? (int) $request->header('X-Active-Agency-Id') : (int) (\App\Support\AgencyRole::activeAgency($request) ?? 0);
        abort_unless((int) $sd->signer_user_id === (int) $u->id || ((int) $sd->agency_id > 0 && (int) $sd->agency_id === $onScreen), 404);

        $disk = Storage::disk('public');
        $rel = null;
        if ($sd->document_type === 'privacy_nda') {
            $doc = DB::table('documents')->where('scope_type', 'user')
                ->where('scope_id', $sd->signer_user_id)->where('category', 'agreement')
                ->orderByDesc('id')->first();
            if ($doc && $doc->file_url) {
                $rel = ltrim(preg_replace('#^/?storage/#', '', $doc->file_url), '/');
            }
        }
        if (! $rel || ! $disk->exists($rel)) {
            $sig = (string) ($sd->signature_data ?? '');
            if (str_starts_with($sig, '/storage/') || str_starts_with($sig, 'storage/')) {
                $rel = ltrim(preg_replace('#^/?storage/#', '', $sig), '/');
            }
        }
        abort_unless($rel && $disk->exists($rel), 404, 'No downloadable file is stored for this signature.');

        // Datestamped, human filename: <DocumentType>-<Signer>-<YYYY-MM-DD>.<ext>
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION)) ?: 'pdf';
        $date = Carbon::parse($sd->signed_at ?? now())->format('Y-m-d');
        $type = trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $sd->document_type), '-') ?: 'document';
        $who = trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($sd->signer_name ?? 'signer')), '-') ?: 'signer';
        $name = $type . '-' . $who . '-' . $date . '.' . $ext;

        return response()->download($disk->path($rel), $name);
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
        if (\App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }
}
