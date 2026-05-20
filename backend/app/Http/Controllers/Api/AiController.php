<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

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
    /** Pure-SQL heuristic churn-risk — no AI key required. */
    public function churnRisk(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);

        $now = Carbon::now();
        $win30 = $now->copy()->subDays(30);
        $win90 = $now->copy()->subDays(90);

        $base = DB::table('families as f')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
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
        foreach ($rows as $r) {
            DB::table('churn_risk_snapshots')->insert([
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
        $key = env('ANTHROPIC_API_KEY');
        abort_unless($key, 503, 'AI not configured');
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        abort_unless($child, 404);

        $weekStart = Carbon::now()->startOfWeek();
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
            $res = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
                'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
                'max_tokens' => 600,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
            if (!$res->ok()) return response()->json(['error' => 'AI call failed', 'status' => $res->status()], 502);
            $text = $res->json('content.0.text') ?? '';
            return response()->json([
                'recap' => trim($text),
                'observations_count' => $observations->count(),
                'activities_count' => $logs->count(),
                'week_start' => $weekStart->toDateString(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('AI recap failed', ['child' => $childId, 'msg' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    /**
     * Anthropic-vision-based document extraction.
     * Accepts an image URL + doc_type. Returns extracted JSON fields.
     */
    public function docExtract(Request $request): JsonResponse
    {
        $key = env('ANTHROPIC_API_KEY');
        abort_unless($key, 503, 'AI not configured');
        $data = $request->validate([
            'document_url' => 'required|string|url',
            'doc_type'     => 'required|string|in:immunization,certification,background_check,id,enrollment',
        ]);

        $agencyId = $this->resolveAgencyId($request);
        $extractionId = DB::table('ai_doc_extractions')->insertGetId([
            'agency_id'      => $agencyId,
            'uploaded_by_id' => $request->user()->id,
            'doc_type'       => $data['doc_type'],
            'document_url'   => $data['document_url'],
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
                'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
                'max_tokens' => 800,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => ['type' => 'url', 'url' => $data['document_url']]],
                        ['type' => 'text', 'text' => "Extract structured fields from this document. {$promptForType} Only return valid JSON, no commentary. Use null for missing fields."],
                    ],
                ]],
            ]);
            if (!$res->ok()) {
                DB::table('ai_doc_extractions')->where('id', $extractionId)->update([
                    'status' => 'failed', 'error_message' => 'HTTP ' . $res->status(), 'updated_at' => now(),
                ]);
                return response()->json(['error' => 'AI extraction failed', 'status' => $res->status()], 502);
            }
            $raw = $res->json('content.0.text') ?? '';
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
                'status' => 'failed', 'error_message' => $e->getMessage(), 'updated_at' => now(),
            ]);
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($activeId) return $activeId;
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
