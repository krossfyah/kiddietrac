<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyRole;
use App\Support\CentreScope;
use App\Support\NpsSurvey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * NPS (2026-11-28): results (centre-scoped for directors), the agency's survey setting, "Send now", the parent/staff
 * portal prompt, and the public signed-link page. App\Support\NpsSurvey holds the rules.
 */
final class NpsController extends Controller
{
    private function agency(Request $request): int
    {
        $a = AgencyRole::activeAgency($request);
        abort_unless($a, 400, 'Select an agency first.');

        return (int) $a;
    }

    private function assertAdmin(Request $request): void
    {
        abort_unless(array_intersect(AgencyRole::names($request), ['platform_admin', 'agency_admin']), 403, 'Only an agency admin can change or send the survey.');
    }

    /** GET /engagement/nps?centre_id&audience — score, trend, comments, rounds; a director sees their own centres. */
    public function summary(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $scope = CentreScope::ids($request, $agencyId);
        $centreId = (int) $request->query('centre_id', 0) ?: null;
        if ($centreId && ! CentreScope::canRead($scope, $centreId)) abort(404);
        if ($centreId && ! DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->exists()) abort(404);
        $out = NpsSurvey::summary($agencyId, $scope, $centreId, $request->query('audience'));
        $isAdmin = (bool) array_intersect(AgencyRole::names($request), ['platform_admin', 'agency_admin']);
        $out['can_manage'] = $isAdmin;
        if ($isAdmin) $out['settings'] = NpsSurvey::settings($agencyId);
        $out['centres'] = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope ?: [0]))->orderBy('name')->get(['id', 'name']);
        // The old "data" key (rows) is kept for anything still reading it.
        $out['data'] = $out['comments'];

        return response()->json($out);
    }

    /** PUT /engagement/nps/settings {enabled, every_months, families, staff} — agency admins. */
    public function saveSettings(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'every_months' => 'required|integer|in:3,6,12',
            'families' => 'required|boolean',
            'staff' => 'required|boolean',
        ]);
        if ($data['enabled'] && ! $data['families'] && ! $data['staff']) {
            return response()->json(['message' => 'Choose families, staff or both.'], 422);
        }

        return response()->json(['settings' => NpsSurvey::saveSettings($this->agency($request), $data)]);
    }

    /** GET /engagement/nps/preview?families&staff — how many people "Send now" would ask. */
    public function preview(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->agency($request);

        return response()->json(NpsSurvey::preview($agencyId, $request->boolean('families'), $request->boolean('staff')));
    }

    /** POST /engagement/nps/send {families, staff, count, confirm:"SEND"} — the count must still match the preview. */
    public function sendNow(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $data = $request->validate([
            'families' => 'required|boolean', 'staff' => 'required|boolean',
            'count' => 'required|integer|min:1', 'confirm' => 'required|string',
        ]);
        $agencyId = $this->agency($request);
        if (strtoupper(trim($data['confirm'])) !== 'SEND') {
            return response()->json(['message' => 'Type SEND to confirm.'], 422);
        }
        if (\App\Support\TestAgency::isAgency($agencyId)) {
            return response()->json(['message' => 'This is a test agency: it sends nothing to anyone, so no survey was started.'], 422);
        }
        if (\App\Support\Features::skips($agencyId, 'parent_feedback', 'NPS send now')) {
            return response()->json(['message' => 'Parent feedback is not part of this agency\'s plan.'], 403);
        }
        $lock = \Illuminate\Support\Facades\Cache::lock('nps-send:' . $agencyId, 60);
        if (! $lock->get()) return response()->json(['message' => 'A survey is already being started. Please wait a moment.'], 409);
        try {
            $p = NpsSurvey::preview($agencyId, $data['families'], $data['staff']);
            if ($p['people'] !== (int) $data['count']) {
                return response()->json(['message' => 'The number of people changed to ' . $p['people'] . '. Check the preview again.', 'preview' => $p], 409);
            }
            if (DB::table('nps_surveys')->where('agency_id', $agencyId)->where('created_at', '>=', now()->subDay())->exists()) {
                return response()->json(['message' => 'A survey already went out in the last 24 hours.'], 409);
            }
            $r = NpsSurvey::start($agencyId, $data['families'], $data['staff'], 'send_now', (int) $request->user()->id);
        } finally {
            $lock->release();
        }

        return response()->json(['status' => 'started', 'invited' => $r['invited'], 'survey_id' => $r['survey_id'],
            'message' => 'The survey is on its way to ' . $r['invited'] . ' people. Emails and app notices go out over the next few minutes.']);
    }

    /** GET /engagement/nps/pending — the signed-in person's open invite, for the portal prompt. */
    public function pending(Request $request): JsonResponse
    {
        $agencyId = AgencyRole::activeAgency($request);
        $inv = DB::table('nps_invites')->where('user_id', $request->user()->id)->whereNull('answered_at')->where('status', 'sent')
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->where('expires_at', '>', now())->orderByDesc('id')->first();
        if (! $inv) return response()->json(['pending' => null]);

        return response()->json(['pending' => ['invite_id' => (int) $inv->id, 'audience' => $inv->audience,
            'agency' => \App\Support\FamilyFacingName::agency((int) $inv->agency_id)]]);
    }

    /** POST /engagement/nps {invite_id, score, comment} — answer from inside the portal. */
    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invite_id' => 'required|integer', 'score' => 'required|integer|min:0|max:10', 'comment' => 'nullable|string|max:2000',
        ]);
        $inv = DB::table('nps_invites')->where('id', $data['invite_id'])->where('user_id', $request->user()->id)
            ->where('status', 'sent')->where('expires_at', '>', now())->first();
        if (! $inv) return response()->json(['message' => 'This survey has closed.'], 404);
        NpsSurvey::answer($inv, (int) $data['score'], $data['comment'] ?? null, 'portal');

        return response()->json(['status' => 'saved']);
    }

    /** GET /public/nps/{token} — reads only (mail scanners open links). */
    public function show(string $token): JsonResponse
    {
        $inv = NpsSurvey::find($token);
        if (! $inv) return response()->json(['message' => 'This survey link has expired or is not valid.', 'brand' => null], 404);
        $u = DB::table('users')->where('id', $inv->user_id)->first();
        $score = $inv->response_id ? DB::table('nps_responses')->where('id', $inv->response_id)->first(['score', 'comment']) : null;

        return response()->json([
            'brand' => \App\Support\AttendanceReply::brand((int) $inv->agency_id),
            'locale' => in_array((string) ($u->locale ?? $u->preferred_locale ?? ''), ['fr', 'es', 'hi'], true) ? (string) ($u->locale ?? $u->preferred_locale) : 'en',
            'agency' => \App\Support\FamilyFacingName::agency((int) $inv->agency_id),
            'first_name' => (string) ($u->first_name ?? ''),
            'audience' => $inv->audience,
            'answer' => $score ? ['score' => (int) $score->score, 'comment' => $score->comment] : null,
        ]);
    }

    /** POST /public/nps/{token} {score, comment, website(honeypot)} */
    public function answer(Request $request, string $token): JsonResponse
    {
        if (trim((string) $request->input('website', '')) !== '') return response()->json(['status' => 'saved']);
        $data = $request->validate(['score' => 'required|integer|min:0|max:10', 'comment' => 'nullable|string|max:2000']);
        $inv = NpsSurvey::find($token);
        if (! $inv) return response()->json(['message' => 'This survey link has expired or is not valid.'], 404);
        // NpsSurvey::answer writes the audit_logs row (nps.answered / nps.answer_changed) for this guest answer.
        NpsSurvey::answer($inv, (int) $data['score'], $data['comment'] ?? null, 'email link');

        return response()->json(['status' => 'saved']);
    }
}
