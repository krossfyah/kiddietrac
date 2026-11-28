<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * v22p51 — AI features.
 *   - GET /ai/weekly-recap/{child}    Anthropic weekly recap (parent-friendly).
 *   - GET /ai/churn-risk              Per-family churn risk (pure SQL heuristic).
 *   - POST /ai/doc-extract            Upload a doc image → extract fields.
 */
final class AiController extends Controller
{
    use ResolvesCentreContext;

    /** Pure-SQL heuristic churn-risk — no AI key required. */
    public function churnRisk(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);

        $now = Carbon::now();
        $win30 = $now->copy()->subDays(30);
        $win90 = $now->copy()->subDays(90);

        /* Open providers and current families only (2026-10-09): a closed provider's
           families are not at risk of leaving - they have left. App\Support\Live is the
           one definition of open. */
        $base = \App\Support\Live::families(\App\Support\Live::centres(DB::table('families as f')
            ->join('centres as c', 'c.id', '=', 'f.centre_id'), 'c'), 'f')
            ->where('c.agency_id', $agencyId)
            // A centre director sees their own centres' families only (2026-11-27, CentreScope; privacy).
            ->when(($scope = \App\Support\CentreScope::ids($request, (int) $agencyId)) !== null, fn ($q) => $q->whereIn('c.id', $scope ?: [0]))
            ->whereNull('f.deleted_at')
            ->select('f.id', 'f.family_name');

        $rows = $base->get()->map(function ($fam) use ($win30, $win90) {
            $famId = (int) $fam->id;
            $obs30 = DB::table('observations as o')
                ->join('children as c', 'c.id', '=', 'o.child_id')
                ->where('c.family_id', $famId)
                ->where('o.observed_at', '>=', $win30)
                ->count();
            $obs90 = DB::table('observations as o')
                ->join('children as c', 'c.id', '=', 'o.child_id')
                ->where('c.family_id', $famId)
                ->where('o.observed_at', '>=', $win90)
                ->count();
            $obsRate30 = $obs90 > 0 ? ($obs30 / max(1, $obs90 / 3)) : 1.0;

            $overdue = DB::table('invoices')
                ->where('family_id', $famId)
                ->whereIn('status', ['sent', 'overdue', 'partial'])
                ->where('due_at', '<', Carbon::now())
                ->count();
            $balance = (float) DB::table('invoices')
                ->where('family_id', $famId)
                ->whereIn('status', ['sent', 'overdue', 'partial'])
                ->sum('balance_due');

            $signIns30 = 0; // check_ins not on this system
            /* legacy stub: DB::table('check_ins as ci')
                ->join('children as c', 'c.id', '=', 'ci.child_id')
                */

            $signIns90 = 0; /* DB::table('check_ins as ci')
                ->join('children as c', 'c.id', '=', 'ci.child_id')
                ->where('c.family_id', $famId)
                */

            $signInRate = 1.0;

            $score = 0;
            $signals = [];
            if ($obsRate30 < 0.5) { $score += 20; $signals[] = 'Observations down >50%'; }
            if ($obsRate30 < 0.25) { $score += 15; $signals[] = 'Very few observations'; }
            if ($overdue >= 1) { $score += 15; $signals[] = $overdue . ' overdue invoice(s)'; }
            if ($overdue >= 2) { $score += 15; $signals[] = 'Repeated payment issues'; }
            if ($balance > 500) { $score += 10; $signals[] = 'Balance over $500'; }
            if ($signInRate < 0.5) { $score += 20; $signals[] = 'Check-ins down >50%'; }
            if ($signInRate < 0.25) { $score += 15; $signals[] = 'Barely attending'; }
            $score = min(100, $score);
            return [
                'family_id'   => $famId,
                'family_name' => $fam->family_name,
                'risk_score'  => $score,
                'bucket'      => $score >= 60 ? 'high' : ($score >= 30 ? 'medium' : 'low'),
                'signals'     => $signals,
            ];
        })->sortByDesc('risk_score')->values();

        DB::table('churn_risk_snapshots')->where('agency_id', $agencyId)
            ->where('computed_at', '<', Carbon::now()->subDay())
            ->delete();
        /* insertOrIgnore (2026-10-09, tickets #138-140): two loads of this screen in the
           same second wrote the same (family, second) twice and the unique key turned the
           second into a 500 - the screen failed instead of showing the list. The snapshot
           is the same either way; the duplicate is simply skipped. */
        foreach ($rows as $r) {
            DB::table('churn_risk_snapshots')->insertOrIgnore([
                'agency_id'   => $agencyId,
                'family_id'   => $r['family_id'],
                'risk_score'  => $r['risk_score'],
                'signals'     => json_encode($r['signals']),
                'computed_at' => Carbon::now(),
            ]);
        }
        return response()->json(['data' => $rows]);
    }

    /** Anthropic-powered weekly recap for a child. */
    public function weeklyRecap(Request $request, int $childId): JsonResponse
    {
        $key = config('services.anthropic.key');
        abort_unless($key, 503, 'AI not configured');
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        abort_unless($child, 404);
        // SECURITY (v22p94): only the child's guardians/centre staff.
        abort_unless($this->canAccessChildId($request->user(), $childId), 403);
        /* THE PARENTS' "✨ THIS WEEK" (2026-10-28, AI Bundle). The child's OWN agency must have the bundle (the
           route gate reads the signed-in user's agency), the call is metered to that agency, the recap is kept for
           6 hours so a parent opening it twice is one AI call, and a failure says so in words - it returned the
           raw exception text before (see [[kiddietrac-no-raw-errors]]). */
        abort_unless(\App\Support\AiBundle::forChild($childId), 403, 'The weekly recap is part of the AI Bundle.');
        $recapKey = 'ai-weekly-recap:' . $childId . ':' . now()->format('o-W');
        if ($hit = \Illuminate\Support\Facades\Cache::get($recapKey)) {
            return response()->json($hit + ['cached' => true]);
        }
        try {
            $agencyForMeter = DB::table('children as ch')->join('families as fa', 'fa.id', '=', 'ch.family_id')
                ->join('centres as ce', 'ce.id', '=', 'fa.centre_id')->where('ch.id', $childId)->value('ce.agency_id');
            \App\Support\UsageMeter::forAgency($agencyForMeter ? (int) $agencyForMeter : null);
        } catch (\Throwable $e) {
        }

        $weekStart = \App\Support\AgencyTime::boundaryUtc(null, 'week');
        $observations = DB::table('observations')
            ->where('child_id', $childId)
            ->where('observed_at', '>=', $weekStart)
            ->select('body as notes', 'observed_at', 'framework', 'domain')
            ->orderBy('observed_at')
            ->get();
        $logs = DB::table('daily_care_logs')
            ->where('child_id', $childId)
            ->where('occurred_at', '>=', $weekStart)
            ->select('log_type', 'occurred_at', 'details')
            ->orderBy('occurred_at')
            ->get();

        $obsText = $observations->map(fn ($o) => '- ' . Carbon::parse($o->observed_at)->format('D M j') . ': ' . $o->notes)->implode("\n");
        $logCounts = $logs->countBy('log_type')->map(fn ($c, $k) => $k . '×' . $c)->values()->implode(', ');

        $prompt = "Write a warm 1-paragraph weekly recap (4-6 sentences) for {$child->first_name}'s parent. Friendly, specific, uses {$child->first_name}'s name. Highlight one growth moment and one fun memory. Avoid generic phrases.\n\nObservations this week:\n{$obsText}\n\nActivities tracked: {$logCounts}";

        try {
            \App\Support\AiFresh::as('weekly_recap', \App\Support\AiFresh::agencyFrom(['child_id' => $childId]));   // fresh writing: never this agency's last recaps again
            $res = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'),
                'max_tokens' => 600,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
            if (!$res->ok()) {
                Log::warning('AI recap failed', ['child' => $childId, 'status' => $res->status(), 'body' => mb_substr($res->body(), 0, 300)]);
                return response()->json(['message' => 'The weekly recap is not available right now. Please try again later.'], 503);
            }
            $text = trim((string) (\App\Support\AiConfig::text($res->json()) ?? ''));
            if ($text === '') {
                return response()->json(['message' => 'There is not enough from this week for a recap yet.'], 422);
            }
            $out = [
                'recap' => $text,
                'observations_count' => $observations->count(),
                'activities_count' => $logs->count(),
                'week_start' => $weekStart->toDateString(),
            ];
            \Illuminate\Support\Facades\Cache::put($recapKey, $out, now()->addHours(6));
            return response()->json($out);
        } catch (\Throwable $e) {
            Log::warning('AI recap failed', ['child' => $childId, 'msg' => $e->getMessage()]);
            return response()->json(['message' => 'The weekly recap is not available right now. Please try again later.'], 503);
        }
    }

    /**
     * Anthropic-vision-based document extraction.
     *
     * THE FILE GOES TO THE AI AS ITS BYTES, NEVER AS A LINK (2026-11-28). This used to accept a
     * "publicly reachable" document URL and hand it to the AI to fetch, and it saved every upload on
     * the PUBLIC disk (/storage/ai-extractions/...), so an ID card or immunization record sat at a
     * permanent public address. Now: an uploaded image or PDF only (no URL mode), sent inline as
     * base64, and kept on the private disk for the auto-link step. A URL in the request is refused.
     */
    public function docExtract(Request $request): JsonResponse
    {
        $key = config('services.anthropic.key');
        abort_unless($key, 503, 'Reading documents is not available right now.');
        if (! $request->hasFile('file')) {
            return response()->json(['message' => 'Choose the photo or scan to upload. A link to a document is not accepted: the file itself is read, so it never has to be public.'], 422);
        }
        $data = $request->validate([
            'doc_type'     => 'required|string|in:immunization,certification,background_check,id,enrollment',
            'file'         => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:10240',
        ]);

        $agencyId = $this->resolveAgencyId($request);

        $f = $request->file('file');
        $mime = (string) ($f->getMimeType() ?: $f->getClientMimeType());
        $b64 = base64_encode((string) file_get_contents($f->getRealPath()));
        if ($mime === 'application/pdf') {
            $imageBlock = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64]];
        } else {
            if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) $mime = 'image/jpeg';
            $imageBlock = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]];
        }
        $storedPath = null;
        try {
            $ext = strtolower($f->getClientOriginalExtension() ?: ($mime === 'application/pdf' ? 'pdf' : 'jpg'));
            $storedPath = $f->storeAs('ai-extractions/' . $agencyId, (string) \Illuminate\Support\Str::uuid() . '.' . $ext, 'local') ?: null;
        } catch (\Throwable $e) {}

        $extractionId = DB::table('ai_doc_extractions')->insertGetId([
            'agency_id'      => $agencyId,
            'uploaded_by_id' => $request->user()->id,
            'doc_type'       => $data['doc_type'],
            'document_url'   => null,
            'document_path'  => $storedPath,
            'document_name'  => mb_substr((string) $f->getClientOriginalName(), 0, 190) ?: null,
            'status'         => 'processing',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $promptForType = [
            'immunization' => 'Return JSON: {"vaccine_name":"...","dose":"...","date_administered":"YYYY-MM-DD","next_due":"YYYY-MM-DD","provider":"...","patient_name":"..."}.',
            'certification' => 'Return JSON: {"cert_name":"...","holder_name":"...","issued_date":"YYYY-MM-DD","expiry_date":"YYYY-MM-DD","issuing_body":"...","reference":"..."}.',
            'background_check' => 'Return JSON: {"check_type":"VSS|criminal|...","holder_name":"...","issued_date":"YYYY-MM-DD","expiry_date":"YYYY-MM-DD","reference":"..."}.',
            'id' => 'Return JSON: {"document_type":"...","name":"...","date_of_birth":"YYYY-MM-DD","id_number":"..."}.',
            'enrollment' => 'Return JSON: {"child_name":"...","date_of_birth":"YYYY-MM-DD","parent_name":"...","emergency_contact":"...","address":"..."}.',
        ][$data['doc_type']];

        try {
            $res = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'),
                'max_tokens' => 800,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        $imageBlock,
                        ['type' => 'text', 'text' => "Extract structured fields from this document. {$promptForType} Only return valid JSON, no commentary. Use null for missing fields."],
                    ],
                ]],
            ]);
            if (!$res->ok()) {
                DB::table('ai_doc_extractions')->where('id', $extractionId)->update([
                    'status' => 'failed', 'error_message' => 'HTTP ' . $res->status(), 'updated_at' => now(),
                ]);
                return response()->json(['message' => 'The document could not be read this time. Please try again, or use a clearer photo.'], 502);
            }
            $raw = \App\Support\AiConfig::text($res->json()) ?? '';
            $jsonStart = strpos($raw, '{');
            $jsonEnd = strrpos($raw, '}');
            $jsonStr = $jsonStart !== false && $jsonEnd !== false ? substr($raw, $jsonStart, $jsonEnd - $jsonStart + 1) : $raw;
            $parsed = json_decode($jsonStr, true);
            DB::table('ai_doc_extractions')->where('id', $extractionId)->update([
                'status' => 'extracted',
                'extracted_fields' => json_encode($parsed),
                'updated_at' => now(),
            ]);
            return response()->json(['id' => $extractionId, 'fields' => $parsed]);
        } catch (\Throwable $e) {
            DB::table('ai_doc_extractions')->where('id', $extractionId)->update([
                'status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now(),
            ]);
            Log::warning('AI doc extract failed', ['extraction' => $extractionId, 'msg' => $e->getMessage()]);
            return response()->json(['message' => 'The document could not be read this time. Please try again.'], 502);
        }
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

    private function assertAgencyAdmin(Request $request, int $agencyId): void
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
