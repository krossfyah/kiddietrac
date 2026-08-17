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
        $rows = DB::table('reenrollment_campaigns')->where('agency_id', $agencyId)
            ->orderByDesc('created_at')->get();
        return response()->json(['data' => $rows]);
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
        // Find all currently-enrolled children at this centre/agency
        $q = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')
            ->select('ch.id as child_id', 'ch.family_id');
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
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($kids as $k) {
            DB::table('reenrollment_responses')->insert([
                'campaign_id' => $campaignId,
                'family_id' => $k->family_id,
                'child_id' => $k->child_id,
                'response' => 'pending',
                'created_at' => now(),
            ]);
        }
        // Notify guardians of each child
        $familyIds = $kids->pluck('family_id')->unique();
        $gids = DB::table('guardians')->whereIn('family_id', $familyIds)->pluck('user_id')->unique();
        foreach ($gids as $gid) {
            DB::table('notifications')->insert([
                'user_id' => $gid, 'type' => 'reenrollment',
                'title' => "Re-enrol for {$data['target_term']}",
                'body' => "Confirm by " . Carbon::parse($data['deadline'])->format('M j'),
                'data' => json_encode(['link' => '#reenrollment', 'campaign_id' => $campaignId]),
                'created_at' => now(),
            ]);
        }
        return response()->json(['id' => $campaignId, 'invited' => $kids->count()], 201);
    }

    public function respondCampaign(Request $request, int $campaignId): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'required|integer',
            'response' => 'required|in:yes,no,unsure',
            'notes' => 'nullable|string|max:500',
        ]);
        $u = $request->user();
        // SECURITY (v22p94): only respond for a child you actually have access to
        // (own family / centre) — was updating by child_id from the body with no
        // ownership check, letting a guardian alter any family's re-enrollment.
        abort_unless($this->canAccessChildId($u, (int) $data['child_id']), 403);
        $familyId = DB::table('guardians')->where('user_id', $u->id)->value('family_id');
        DB::table('reenrollment_responses')
            ->where('campaign_id', $campaignId)
            ->where('child_id', $data['child_id'])
            ->update([
                'response' => $data['response'],
                'responded_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);
        DB::table('reenrollment_campaigns')->where('id', $campaignId)->update([
            'total_responded' => DB::raw('(SELECT COUNT(*) FROM reenrollment_responses WHERE campaign_id = ' . $campaignId . ' AND response <> "pending")'),
            'total_renewed' => DB::raw('(SELECT COUNT(*) FROM reenrollment_responses WHERE campaign_id = ' . $campaignId . ' AND response = "yes")'),
            'updated_at' => now(),
        ]);
        return response()->json(['status' => $data['response']]);
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
                DB::table('notifications')->insert([
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
            'ip_address' => $request->ip(),
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
                $agencyId = $isPlatform ? (int) $request->header('X-Active-Agency-Id') : (\App\Support\AuditScope::ownAgency((int) $u->id) ?? 0);
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
            ->orderByDesc('sd.signed_at')
            ->limit(200)
            ->select('sd.*', DB::raw("CONCAT(u.first_name,' ',u.last_name) as signer_full_name"), DB::raw("(CASE WHEN sd.document_type='privacy_nda' THEN (SELECT d.file_url FROM documents d WHERE d.scope_type='user' AND d.scope_id=sd.signer_user_id AND d.category='agreement' ORDER BY d.id DESC LIMIT 1) ELSE NULL END) as doc_file_url"))
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function downloadSignedDoc(Request $request, int $id): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $u = $request->user();
        $sd = DB::table('signed_documents')->where('id', $id)->first();
        abort_unless($sd, 404);
        // Same access as the list: the signer, or any admin/director/platform_admin.
        $isAdmin = DB::table('role_assignments')->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])
            ->where('active', 1)->exists();
        abort_unless((int) $sd->signer_user_id === (int) $u->id || $isAdmin, 403);

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
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }
}
