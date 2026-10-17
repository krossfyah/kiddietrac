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
    /* ATTENDANCE ANOMALIES, REBUILT (2026-10-16). Anthony: "anomalies section why isn't it
       detecting all other educators with children?" then "can you add ability to set other
       activity patterns to detect those as well".

       The old check only flagged a DROP: no observations in 30 days after 3+ in the prior 90,
       or care logs below a quarter of observations. A child whose educator never records
       anything (0 and 0) could never trip either, and at iLearn that was two whole providers:
       11 children checked in almost every day with no observations and no daily records.

       Now a set of PATTERNS, each on/off with its own numbers, saved per agency in
       platform_settings 'anomaly_rules.agency.<id>'. Attendance comes from check_events,
       daily records from daily_care_logs, observations from observations; days are the
       agency's local days. Only open centres and enrolled children (App\Support\Live), and a
       centre director sees only their own centres (App\Support\CentreScope); before this a
       director saw the whole agency. */
    public const ANOMALY_RULES = [
        'no_daily_records' => ['label' => 'Daily records missing', 'enabled' => true, 'params' => ['min_days' => 3, 'pct' => 50],
            'help' => 'Attended at least {min_days} days but has daily records (meals, naps, diapering…) on fewer than {pct}% of them.'],
        'no_observations' => ['label' => 'No observations', 'enabled' => true, 'params' => ['min_days' => 10, 'min_obs' => 1],
            'help' => 'Attended at least {min_days} days but has fewer than {min_obs} observations in the window.'],
        'observation_drop' => ['label' => 'Observations stopped', 'enabled' => true, 'params' => ['prior' => 3],
            'help' => 'Had at least {prior} observations in the 90 days before, and none in the window.'],
        'attendance_drop' => ['label' => 'Attendance dropped', 'enabled' => true, 'params' => ['pct' => 50, 'min_prior_days' => 6],
            'help' => 'Came on fewer than {pct}% of their usual days in the last 14 days (compared with the 60 days before, when they came at least {min_prior_days} days).'],
        'long_days' => ['label' => 'Long days in care', 'enabled' => false, 'params' => ['hours' => 10, 'min_days' => 3],
            'help' => 'In care more than {hours} hours on at least {min_days} days.'],
        'missing_check_out' => ['label' => 'Not checked out', 'enabled' => true, 'params' => ['min_days' => 2],
            'help' => 'Checked in but never checked out on at least {min_days} days.'],
    ];

    private function anomalyRules(int $agencyId): array
    {
        $raw = DB::table('platform_settings')->where('key', 'anomaly_rules.agency.' . $agencyId)->value('value');
        $saved = $raw ? json_decode($raw, true) : [];
        $out = [];
        foreach (self::ANOMALY_RULES as $k => $def) {
            $s = is_array($saved[$k] ?? null) ? $saved[$k] : [];
            $params = [];
            foreach ($def['params'] as $p => $d) {
                $v = $s['params'][$p] ?? $d;
                $params[$p] = is_numeric($v) ? max(0, min(1000, (int) round((float) $v))) : $d;
            }
            $out[$k] = ['key' => $k, 'label' => $def['label'], 'help' => $def['help'], 'enabled' => array_key_exists('enabled', $s) ? (bool) $s['enabled'] : $def['enabled'],
                'params' => $params, 'defaults' => $def['params']];
        }
        return $out;
    }

    /** PUT /ai/attendance-anomalies/rules {rules: {key: {enabled, params: {..}}}} — agency admins. */
    public function attendanceAnomalyRules(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $wide = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->where(function ($w) use ($agencyId) { $w->where('role', 'platform_admin')->orWhere(fn ($x) => $x->where('role', 'agency_admin')->where('agency_id', $agencyId)); })
            ->exists();
        abort_unless($wide, 403, 'Only an agency admin can change what is detected.');
        $in = (array) $request->input('rules', []);
        $save = [];
        foreach (self::ANOMALY_RULES as $k => $def) {
            $r = is_array($in[$k] ?? null) ? $in[$k] : [];
            $params = [];
            foreach ($def['params'] as $p => $d) {
                $v = $r['params'][$p] ?? $d;
                $params[$p] = is_numeric($v) ? max(0, min(1000, (int) round((float) $v))) : $d;
            }
            $save[$k] = ['enabled' => array_key_exists('enabled', $r) ? (bool) $r['enabled'] : $def['enabled'], 'params' => $params];
        }
        DB::table('platform_settings')->updateOrInsert(['key' => 'anomaly_rules.agency.' . $agencyId],
            ['value' => json_encode($save), 'updated_at' => now(), 'created_at' => now()]);
        try {
            \App\Support\Audit::write(['user_id' => $request->user()->id, 'agency_id' => $agencyId, 'action' => 'anomaly_rules.updated',
                'entity_type' => 'agency', 'entity_id' => $agencyId, 'payload' => json_encode($save), 'created_at' => now()]);
        } catch (\Throwable $e) {}
        return response()->json(['rules' => array_values($this->anomalyRules($agencyId))]);
    }

    public function attendanceAnomalies(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $days = max(7, min(120, (int) $request->query('days', 30)));
        $rules = $this->anomalyRules($agencyId);
        $tz = DB::table('agencies')->where('id', $agencyId)->value('timezone') ?: config('app.display_timezone', 'America/Toronto');
        $now = Carbon::now($tz);
        $winStart = $now->copy()->subDays($days)->startOfDay();
        $drop14 = $now->copy()->subDays(14)->startOfDay();
        $prior60 = $drop14->copy()->subDays(60);
        $from = ($winStart->lt($prior60) ? $winStart : $prior60)->copy()->setTimezone('UTC');

        $centreIds = \App\Support\Live::centreIds($agencyId);
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        if ($scope !== null) {
            $centreIds = array_values(array_intersect($centreIds, $scope));
        }
        $q = DB::table('children')->join('families', 'families.id', '=', 'children.family_id')
            ->join('centres', 'centres.id', '=', 'families.centre_id')
            ->whereIn('families.centre_id', $centreIds ?: [0]);
        \App\Support\Live::enrolledChildren($q);
        \App\Support\Live::families($q);
        $children = $q->select('children.id', 'children.first_name', 'children.last_name', 'children.family_id', 'families.centre_id', 'centres.name as centre_name')
            ->orderBy('centres.name')->orderBy('children.first_name')->get();
        $ids = $children->pluck('id')->all() ?: [0];

        $local = fn ($ts) => Carbon::parse($ts, 'UTC')->setTimezone($tz);
        // Attendance by local day: first check-in, last check-out.
        $att = [];
        foreach (DB::table('check_events')->whereIn('child_id', $ids)->where('occurred_at', '>=', $from)
            ->whereIn('event_type', ['check_in', 'check_out'])->orderBy('occurred_at')->get(['child_id', 'event_type', 'occurred_at']) as $ev) {
            $t = $local($ev->occurred_at);
            $d = $t->toDateString();
            $a = &$att[$ev->child_id][$d];
            if ($ev->event_type === 'check_in') {
                $a['in'] = $a['in'] ?? $t;
            } else {
                $a['out'] = $t;
            }
            unset($a);
        }
        $logDays = [];
        foreach (DB::table('daily_care_logs')->whereIn('child_id', $ids)->where('occurred_at', '>=', $winStart->copy()->setTimezone('UTC'))
            ->get(['child_id', 'occurred_at']) as $l) {
            $logDays[$l->child_id][$local($l->occurred_at)->toDateString()] = true;
        }
        $obsWin = DB::table('observations')->whereIn('child_id', $ids)->where('observed_at', '>=', $winStart->copy()->setTimezone('UTC'))
            ->groupBy('child_id')->selectRaw('child_id, COUNT(*) n')->pluck('n', 'child_id')->all();
        $obsPrior = DB::table('observations')->whereIn('child_id', $ids)
            ->where('observed_at', '>=', $winStart->copy()->subDays(90)->setTimezone('UTC'))
            ->where('observed_at', '<', $winStart->copy()->setTimezone('UTC'))
            ->groupBy('child_id')->selectRaw('child_id, COUNT(*) n')->pluck('n', 'child_id')->all();

        $winDate = $winStart->toDateString();
        $d14 = $drop14->toDateString();
        $p60 = $prior60->toDateString();
        $on = fn ($k) => ! empty($rules[$k]['enabled']);
        $P = fn ($k, $p) => (int) $rules[$k]['params'][$p];

        $out = [];
        $byCentre = [];
        foreach ($children as $c) {
            $daysAll = $att[$c->id] ?? [];
            $inWin = array_filter($daysAll, fn ($v, $d) => $d >= $winDate && isset($v['in']), ARRAY_FILTER_USE_BOTH);
            $attended = count($inWin);
            $withLogs = count(array_intersect_key($inWin, $logDays[$c->id] ?? []));
            $obs = (int) ($obsWin[$c->id] ?? 0);
            $obsBefore = (int) ($obsPrior[$c->id] ?? 0);
            $signals = [];

            if ($on('no_daily_records') && $attended >= $P('no_daily_records', 'min_days')
                && $withLogs * 100 < $attended * $P('no_daily_records', 'pct')) {
                $signals[] = ['rule' => 'no_daily_records', 'text' => $withLogs === 0
                    ? "No daily records on any of the {$attended} days attended"
                    : "Daily records on {$withLogs} of {$attended} days attended"];
            }
            if ($on('no_observations') && $attended >= $P('no_observations', 'min_days') && $obs < $P('no_observations', 'min_obs')) {
                $signals[] = ['rule' => 'no_observations', 'text' => ($obs === 0 ? 'No observations' : "Only {$obs} observation" . ($obs === 1 ? '' : 's')) . " in {$days} days ({$attended} days attended)"];
            }
            if ($on('observation_drop') && $obs === 0 && $obsBefore >= $P('observation_drop', 'prior')) {
                $signals[] = ['rule' => 'observation_drop', 'text' => "No observations in {$days} days (had {$obsBefore} in the 90 days before)"];
            }
            if ($on('attendance_drop')) {
                $recent = count(array_filter($daysAll, fn ($v, $d) => $d >= $d14 && isset($v['in']), ARRAY_FILTER_USE_BOTH));
                $before = count(array_filter($daysAll, fn ($v, $d) => $d >= $p60 && $d < $d14 && isset($v['in']), ARRAY_FILTER_USE_BOTH));
                $expected = $before * 14 / 60;
                if ($before >= $P('attendance_drop', 'min_prior_days') && $recent * 100 < $expected * $P('attendance_drop', 'pct')) {
                    $signals[] = ['rule' => 'attendance_drop', 'text' => "Came {$recent} day" . ($recent === 1 ? '' : 's') . ' in the last 14 (usually about ' . round($expected) . ')'];
                }
            }
            if ($on('long_days')) {
                $long = count(array_filter($inWin, fn ($v) => isset($v['out']) && $v['out']->gt($v['in'])
                    && $v['in']->diffInMinutes($v['out']) > $P('long_days', 'hours') * 60));
                if ($long >= $P('long_days', 'min_days')) {
                    $signals[] = ['rule' => 'long_days', 'text' => "In care over {$P('long_days', 'hours')} hours on {$long} days"];
                }
            }
            if ($on('missing_check_out')) {
                $today = $now->toDateString();
                $open = count(array_filter($inWin, fn ($v, $d) => $d < $today && (! isset($v['out']) || $v['out']->lt($v['in'])), ARRAY_FILTER_USE_BOTH));
                if ($open >= $P('missing_check_out', 'min_days')) {
                    $signals[] = ['rule' => 'missing_check_out', 'text' => "Not checked out on {$open} days"];
                }
            }

            $b = &$byCentre[$c->centre_id];
            $b = $b ?? ['centre_id' => (int) $c->centre_id, 'centre_name' => $c->centre_name, 'children' => 0, 'flagged' => 0];
            $b['children']++;
            if ($signals) {
                $b['flagged']++;
            }
            unset($b);
            if (! $signals) {
                continue;
            }
            $out[] = [
                'child_id' => $c->id, 'child_name' => trim("{$c->first_name} {$c->last_name}"), 'family_id' => $c->family_id,
                'centre_id' => (int) $c->centre_id, 'centre_name' => $c->centre_name,
                'signals' => array_column($signals, 'text'), 'signal_rules' => array_column($signals, 'rule'),
                'days_attended' => $attended, 'days_with_records' => $withLogs, 'obs_window' => $obs, 'obs_prior_90' => $obsBefore,
                // kept for older screens
                'obs_30' => $obs, 'obs_90' => $obs + $obsBefore, 'logs_30' => $withLogs,
            ];
        }
        $canEdit = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->where(function ($w) use ($agencyId) { $w->where('role', 'platform_admin')->orWhere(fn ($x) => $x->where('role', 'agency_admin')->where('agency_id', $agencyId)); })
            ->exists();

        return response()->json(['data' => $out, 'window_days' => $days, 'rules' => array_values($rules),
            'centres' => array_values($byCentre), 'children_checked' => $children->count(), 'can_edit_rules' => $canEdit]);
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
