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
 * v22p53 — AI v2:
 *  - AI auto-tag observations to HDLH framework
 *  - Auto-translate parent/staff messages (cached)
 *  - AI doc-extract auto-link to matching staff/child
 *  - Predictive enrollment forecast (SQL + AI tone)
 *  - Anomaly attendance detection
 */
final class AiV2Controller extends Controller
{
    use ResolvesCentreContext;

    // =========================================================
    // (1) Auto-tag observations to HDLH framework
    // =========================================================
    public function tagObservation(Request $request): JsonResponse
    {
        $key = env('ANTHROPIC_API_KEY');
        abort_unless($key, 503, 'AI not configured');
        $data = $request->validate([
            'text' => 'required|string|min:10|max:5000',
        ]);
        $prompt = "Tag this early-childhood observation against Ontario's HDLH (How Does Learning Happen) framework. Return JSON only:\n" .
                  "{\"domain\":\"Belonging|Well-being|Engagement|Expression\"," .
                  "\"milestones\":[\"...\"],\"key_themes\":[\"...\"]}\n\n" .
                  "Observation:\n" . $data['text'];
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
            if (!$res->ok()) return response()->json(['error' => 'AI failed', 'status' => $res->status()], 502);
            $raw = $res->json('content.0.text') ?? '';
            $jsonStart = strpos($raw, '{');
            $jsonEnd = strrpos($raw, '}');
            if ($jsonStart === false) return response()->json(['error' => 'no JSON in response', 'raw' => $raw], 502);
            $parsed = json_decode(substr($raw, $jsonStart, $jsonEnd - $jsonStart + 1), true);
            return response()->json($parsed);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    // =========================================================
    // (2) Auto-translate messages (cached per message+target)
    // =========================================================
    public function translateMessage(Request $request): JsonResponse
    {
        $key = env('ANTHROPIC_API_KEY');
        abort_unless($key, 503);
        $data = $request->validate([
            'message_id' => 'required|integer',
            'target_lang' => 'required|in:en,fr,es',
        ]);
        // SECURITY (v22p96): only a participant of the message's conversation may
        // translate it. The access check runs BEFORE the cache return — otherwise
        // a cached translation leaked any message's content by id to any caller.
        $msg = DB::table('messages')->where('id', $data['message_id'])->first();
        abort_unless($msg, 404);
        $conv = DB::table('conversations')->where('id', $msg->conversation_id)->first();
        abort_unless($conv, 404);
        $u = $request->user();
        $allowed = $conv->family_id
            ? $this->canAccessFamilyScoped($request, (int) $conv->family_id)
            : ($conv->centre_id && ($this->authorizeCentreAccess($u, (int) $conv->centre_id)
                || ($this->isPlatformAdminUser($u)
                    && (int) DB::table('centres')->where('id', $conv->centre_id)->value('agency_id') === (int) $this->resolveAgencyId($request))));
        abort_unless($allowed, 403);
        // cached?
        $cached = DB::table('message_translations')
            ->where('message_id', $data['message_id'])
            ->where('target_lang', $data['target_lang'])
            ->first();
        if ($cached) return response()->json(['translation' => $cached->translated_text, 'cached' => true]);
        $body = $msg->body ?? $msg->content ?? '';
        if (!$body) return response()->json(['translation' => '', 'cached' => false]);

        $langMap = ['en' => 'English', 'fr' => 'French', 'es' => 'Spanish'];
        $prompt = "Translate the following childcare message to {$langMap[$data['target_lang']]}. Keep it natural and warm. Return only the translated text, no preamble.\n\n" . $body;
        try {
            $res = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
                'max_tokens' => 1000,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
            if (!$res->ok()) return response()->json(['error' => 'translate failed'], 502);
            $t = trim($res->json('content.0.text') ?? '');
            DB::table('message_translations')->insert([
                'message_id' => $data['message_id'],
                'source_lang' => 'auto',
                'target_lang' => $data['target_lang'],
                'translated_text' => $t,
                'created_at' => now(),
            ]);
            return response()->json(['translation' => $t, 'cached' => false]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    // =========================================================
    // (3) Auto-link AI doc extraction to staff/child record
    // =========================================================
    public function autoLinkExtraction(Request $request, int $extractionId): JsonResponse
    {
        $ext = DB::table('ai_doc_extractions')->where('id', $extractionId)->first();
        abort_unless($ext, 404);
        abort_unless($ext->status === 'extracted', 422, 'Extraction not ready');
        // SECURITY (v22p96): the caller must own the extraction's agency (a
        // platform_admin only when switched into it), and candidate name searches
        // are constrained to THAT agency. Previously this matched users/children by
        // name across ALL agencies and inserted cross-tenant credential rows.
        $u = $request->user();
        abort_unless($this->isPlatformAdminUser($u)
            ? (int) $ext->agency_id === (int) $this->resolveAgencyId($request)
            : $this->userBelongsToAgency($u->id, (int) $ext->agency_id), 403);
        $agencyCentreIds = DB::table('centres')->where('agency_id', $ext->agency_id)->pluck('id');
        $agencyUserIds = DB::table('role_assignments')->where('active', true)
            ->where(function ($q) use ($ext, $agencyCentreIds) {
                $q->where('agency_id', $ext->agency_id)
                  ->orWhereIn('centre_id', $agencyCentreIds->all() ?: [0]);
            })->pluck('user_id')->unique();
        $fields = json_decode($ext->extracted_fields, true);
        $type = $ext->doc_type;
        $result = ['action' => 'none', 'matches' => []];

        if ($type === 'background_check' || $type === 'certification') {
            $name = $fields['holder_name'] ?? null;
            if ($name) {
                [$first, $last] = $this->splitName($name);
                $matches = DB::table('users')
                    ->whereIn('id', $agencyUserIds->all() ?: [0])
                    ->where('first_name', 'like', "%{$first}%")
                    ->where('last_name', 'like', "%{$last}%")
                    ->limit(5)->get(['id', 'first_name', 'last_name', 'email']);
                $result['matches'] = $matches;
                if ($matches->count() === 1) {
                    $userId = $matches->first()->id;
                    if ($type === 'background_check') {
                        DB::table('background_checks')->insert([
                            'agency_id' => $ext->agency_id,
                            'user_id' => $userId,
                            'check_type' => $fields['check_type'] ?? 'vss',
                            'reference' => $fields['reference'] ?? null,
                            'issued_at' => $fields['issued_date'] ?? null,
                            'expires_at' => $fields['expiry_date'] ?? Carbon::now()->addYear()->toDateString(),
                            'document_url' => $ext->document_url,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $result['action'] = 'inserted background_check';
                    } else {
                        DB::table('certifications')->insert([
                            'user_id' => $userId,
                            'cert_type' => $fields['cert_name'] ?? 'first_aid',
                            'issued_at' => $fields['issued_date'] ?? null,
                            'expires_at' => $fields['expiry_date'] ?? Carbon::now()->addYear()->toDateString(),
                            'document_url' => $ext->document_url,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $result['action'] = 'inserted certification';
                    }
                    DB::table('ai_doc_extractions')->where('id', $extractionId)->update([
                        'target_table' => $type === 'background_check' ? 'background_checks' : 'certifications',
                        'target_id' => $userId,
                        'status' => 'linked',
                        'updated_at' => now(),
                    ]);
                }
            }
        } elseif ($type === 'immunization') {
            $name = $fields['patient_name'] ?? null;
            if ($name) {
                [$first, $last] = $this->splitName($name);
                $matches = DB::table('children as c')
                    ->join('families as f', 'f.id', '=', 'c.family_id')
                    ->whereIn('f.centre_id', $agencyCentreIds->all() ?: [0])
                    ->where('c.first_name', 'like', "%{$first}%")
                    ->where('c.last_name', 'like', "%{$last}%")
                    ->whereNull('c.deleted_at')
                    ->limit(5)->get(['c.id', 'c.first_name', 'c.last_name']);
                $result['matches'] = $matches;
            }
        }
        return response()->json($result);
    }

    // =========================================================
    // (4) Predictive enrollment forecast
    // =========================================================
    public function enrollmentForecast(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $months = 6;
        $now = Carbon::now();
        $currentEnrolled = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')
            ->count();

        $tourPipeline = DB::table('tour_bookings')
            ->where('agency_id', $agencyId)
            ->whereIn('status', ['requested', 'scheduled', 'completed'])
            ->where('tour_at', '>=', $now->copy()->subDays(60))
            ->count();
        $monthlyTours = max(1, round($tourPipeline / 2));

        // tour → enrolment conversion: assume 30%
        $monthlyAdds = (int) round($monthlyTours * 0.30);
        $monthlyChurnPct = 0.025;

        $proj = [];
        $running = $currentEnrolled;
        for ($i = 1; $i <= $months; $i++) {
            $month = $now->copy()->addMonths($i);
            $churn = (int) round($running * $monthlyChurnPct);
            $running = $running + $monthlyAdds - $churn;
            $proj[] = [
                'month' => $month->format('Y-m'),
                'projected_enrolment' => $running,
                'monthly_adds' => $monthlyAdds,
                'monthly_churn' => $churn,
            ];
            DB::table('enrollment_forecasts')->updateOrInsert(
                ['agency_id' => $agencyId, 'centre_id' => null, 'forecast_for_month' => $month->format('Y-m')],
                [
                    'projected_enrolment' => $running,
                    'tour_pipeline' => $tourPipeline,
                    'churn_predicted' => $churn,
                    'confidence' => $i <= 2 ? 'high' : ($i <= 4 ? 'medium' : 'low'),
                    'computed_at' => now(),
                ]
            );
        }

        return response()->json([
            'current_enrolment' => $currentEnrolled,
            'tour_pipeline_60d' => $tourPipeline,
            'monthly_adds_assumption' => $monthlyAdds,
            'monthly_churn_pct' => $monthlyChurnPct,
            'projection' => $proj,
        ]);
    }

    // =========================================================
    // (5) Anomaly attendance detection
    // =========================================================
    public function attendanceAnomalies(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $days = (int) $request->query('days', 30);
        $start = Carbon::now()->subDays($days)->startOfDay();

        // Use tour_bookings + observations as activity proxy when no attendance table exists.
        $children = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')
            ->select('ch.id', 'ch.first_name', 'ch.last_name', 'ch.family_id')
            ->get();

        $anomalies = [];
        foreach ($children as $child) {
            $obs30 = DB::table('observations')
                ->where('child_id', $child->id)
                ->where('observed_at', '>=', $start)->count();
            $obs90 = DB::table('observations')
                ->where('child_id', $child->id)
                ->where('observed_at', '>=', Carbon::now()->subDays(90))->count();
            $logs30 = DB::table('daily_care_logs')
                ->where('child_id', $child->id)
                ->where('occurred_at', '>=', $start)->count();

            $signals = [];
            if ($obs30 === 0 && $obs90 >= 3) $signals[] = 'No observations in 30 days (had ' . $obs90 . ' in prior 90)';
            if ($logs30 < 5 && $logs30 < ($obs90 / 4)) $signals[] = 'Care-log activity dropped';
            if (empty($signals)) continue;

            $anomalies[] = [
                'child_id' => $child->id,
                'child_name' => "{$child->first_name} {$child->last_name}",
                'family_id' => $child->family_id,
                'signals' => $signals,
                'obs_30' => $obs30,
                'obs_90' => $obs90,
                'logs_30' => $logs30,
            ];
        }
        return response()->json(['data' => $anomalies, 'window_days' => $days]);
    }

    private function splitName(string $full): array
    {
        $parts = preg_split('/\s+/', trim($full));
        if (count($parts) < 2) return [$full, ''];
        $first = array_shift($parts);
        $last = implode(' ', $parts);
        return [$first, $last];
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
