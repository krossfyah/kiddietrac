<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\EducatorPoints;
use App\Support\ThankYouAuto;
use App\Support\ThankYouAward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Automatic thank-you awards (2026-10-31) — agency admins only.
 *  GET  /educator-points/auto                 settings, signers, staff (for exclusions), preview, drafts, history
 *  PUT  /educator-points/auto                 save settings
 *  POST /educator-points/auto/drafts/{id}     edit a draft's letter / certificate message
 *  POST /educator-points/auto/drafts/{id}/send
 *  POST /educator-points/auto/drafts/{id}/skip
 *  POST /educator-points/auto/send-all        send every pending draft
 */
class ThankYouAutoController extends Controller
{
    use ResolvesCentreContext;

    private function agency(Request $request): int
    {
        $aid = (int) $this->resolveAgencyId($request);
        $roles = \App\Support\AgencyRole::names($request);
        abort_unless($aid && array_intersect($roles, ['agency_admin', 'platform_admin']), 403, 'Only agency administrators manage automatic awards.');
        return $aid;
    }

    private function draftOut(object $d): array
    {
        $u = DB::table('users')->where('id', $d->user_id)->first(['first_name', 'last_name']);
        return ['id' => (int) $d->id, 'user_id' => (int) $d->user_id, 'name' => $u ? trim($u->first_name . ' ' . $u->last_name) : 'Staff member',
            'month' => $d->month, 'month_label' => ThankYouAuto::monthLabel($d->month), 'points' => (int) $d->points,
            'letter' => (string) $d->letter, 'letter_ai' => (bool) $d->letter_ai, 'message' => (string) ($d->message ?? ''),
            'status' => $d->status, 'expires_at' => $d->expires_at, 'decided_at' => $d->decided_at, 'created_at' => $d->created_at];
    }

    /**
     * Settings → Educator awards (2026-11-02): a centre director may READ how automatic awards are set up (on/off,
     * mode, schedule, rule, who is told), nothing else: no staff list, no preview, no drafts, no history, no names
     * beyond the signer's first name. Saving and every draft action stay agency-admin only (agency()).
     */
    private function directorView(Request $request): ?JsonResponse
    {
        $aid = (int) $this->resolveAgencyId($request);
        $roles = \App\Support\AgencyRole::names($request);
        if (! $aid || array_intersect($roles, ['agency_admin', 'platform_admin']) || ! in_array('centre_director', $roles, true)) {
            return null;
        }
        $c = ThankYouAuto::settings($aid);
        $c['exclude'] = [];
        $runAt = ThankYouAuto::runAt($aid, $c);
        $nextRun = now()->setTimezone(\App\Support\AgencyTime::tz($aid))->greaterThan($runAt) ? ThankYouAuto::runAt($aid, $c, now()->addMonthNoOverflow()->startOfMonth()->addDay()) : $runAt;
        return response()->json([
            'settings' => $c, 'can_edit' => false,
            'schedule_label' => ThankYouAuto::scheduleLabel($c), 'rule_label' => ThankYouAuto::ruleLabel($c),
            'next_run' => $nextRun->format('l, F j \a\t g:i A'),
            'signers' => array_map(fn ($s) => ['key' => $s['key'], 'first' => $s['first'], 'title' => $s['title']], ThankYouAward::signers($aid)),
            'ai_available' => \App\Support\AiBundle::on($aid),
            'managers' => [], 'staff' => [], 'preview' => [], 'drafts' => [], 'history' => [],
            'preview_month' => ThankYouAuto::targetMonth($aid), 'preview_month_label' => ThankYouAuto::monthLabel(ThankYouAuto::targetMonth($aid)),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        if ($dv = $this->directorView($request)) return $dv;
        $aid = $this->agency($request);
        $c = ThankYouAuto::settings($aid);
        $month = ThankYouAuto::targetMonth($aid);
        $staff = array_values(array_map(fn ($e) => ['user_id' => (int) $e['user_id'], 'name' => $e['name'], 'role' => $e['role']], EducatorPoints::earners($aid)));
        usort($staff, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $preview = array_values(array_map(fn ($r) => ['user_id' => $r['user_id'], 'name' => $r['name'], 'role' => $r['role'], 'points' => $r['points']], ThankYouAuto::qualify($aid, $month, $c)));
        $drafts = DB::table('thank_you_auto_drafts')->where('agency_id', $aid)->whereIn('status', ['pending', 'sending'])->orderByDesc('points')->get()->map(fn ($d) => $this->draftOut($d))->all();
        $history = DB::table('thank_you_auto_drafts')->where('agency_id', $aid)->whereNotIn('status', ['pending', 'sending'])->orderByDesc('updated_at')->limit(100)->get()->map(fn ($d) => $this->draftOut($d))->all();
        $runAt = ThankYouAuto::runAt($aid, $c);
        $nextRun = now()->setTimezone(\App\Support\AgencyTime::tz($aid))->greaterThan($runAt) ? ThankYouAuto::runAt($aid, $c, now()->addMonthNoOverflow()->startOfMonth()->addDay()) : $runAt;
        return response()->json([
            'settings' => $c, 'can_edit' => true,
            // Who hears about the drafts in review mode (first names: a staff screen, but kept short).
            'managers' => array_map(fn ($m) => ['first' => $m['first']], ThankYouAuto::managers($aid)),
            'schedule_label' => ThankYouAuto::scheduleLabel($c), 'rule_label' => ThankYouAuto::ruleLabel($c),
            'next_run' => $nextRun->format('l, F j \a\t g:i A'),
            'signers' => array_map(fn ($s) => ['key' => $s['key'], 'first' => $s['first'], 'title' => $s['title']], ThankYouAward::signers($aid)),
            'ai_available' => \App\Support\AiBundle::on($aid),
            'staff' => $staff, 'preview_month' => $month, 'preview_month_label' => ThankYouAuto::monthLabel($month), 'preview' => $preview,
            'drafts' => $drafts, 'history' => $history,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $in = $request->validate([
            'enabled' => 'nullable|boolean', 'when' => 'nullable|in:day,last,first_weekday', 'day' => 'nullable|integer|min:1|max:28',
            'time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'rule' => 'nullable|in:min_points,top,all_active',
            'min_points' => 'nullable|integer|min:1|max:1000000', 'top_n' => 'nullable|integer|min:1|max:50', 'floor' => 'nullable|integer|min:1|max:1000000',
            'roles' => 'nullable|array', 'roles.*' => 'in:educator,home_visitor,centre_director', 'exclude' => 'nullable|array', 'exclude.*' => 'integer',
            'signer' => 'nullable|string|max:20', 'tone' => 'nullable|in:warm,formal,short', 'message' => 'nullable|string|max:600',
            'mode' => 'nullable|in:review,auto', 'pdf' => 'nullable|boolean', 'bcc_managers' => 'nullable|boolean', 'expire_days' => 'nullable|integer|min:3|max:60',
        ]);
        $in = array_filter($in, fn ($v) => $v !== null);
        if (array_key_exists('signer', $request->all()) && $request->input('signer') === null) $in['signer'] = null;
        [$c, $changes] = ThankYouAuto::save($aid, $in);
        if ($changes) {
            ThankYouAuto::audit($aid, (int) $request->user()->id, 'thank_you_auto.settings', null, [
                'summary' => 'Changed the automatic thank-you award settings — ' . implode('; ', $changes) . '.',
                'changes' => $changes,
            ]);
        }
        return $this->show($request);
    }

    private function draft(Request $request, int $id): object
    {
        $aid = $this->agency($request);
        $d = DB::table('thank_you_auto_drafts')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($d, 404);
        return $d;
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $d = $this->draft($request, $id);
        abort_unless($d->status === 'pending', 422, 'This draft was already handled.');
        $in = $request->validate(['letter' => 'nullable|string|max:3000', 'message' => 'nullable|string|max:600']);
        $upd = ['updated_at' => now()];
        if (array_key_exists('letter', $in)) $upd['letter'] = trim((string) $in['letter']);
        if (array_key_exists('message', $in)) $upd['message'] = trim((string) $in['message']) ?: null;
        DB::table('thank_you_auto_drafts')->where('id', $id)->update($upd);
        ThankYouAuto::audit((int) $d->agency_id, (int) $request->user()->id, 'thank_you_auto.draft_edited', $id, [
            'summary' => 'Edited the automatic thank-you award draft for ' . ThankYouAuto::name((int) $d->user_id) . ' (' . ThankYouAuto::monthLabel($d->month) . ')'
                . (isset($upd['letter']) ? ' — letter' : '') . (array_key_exists('message', $upd) ? (isset($upd['letter']) ? ' and' : ' —') . ' certificate message' : '') . '.',
        ]);
        return response()->json(['draft' => $this->draftOut(DB::table('thank_you_auto_drafts')->where('id', $id)->first())]);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $d = $this->draft($request, $id);
        $res = ThankYouAuto::sendDraft((int) $d->id, (int) $request->user()->id);
        if (! ($res['ok'] ?? false)) return response()->json(['message' => $res['message'] ?? 'Could not send it.'], 422);
        return response()->json(['ok' => true]);
    }

    public function skip(Request $request, int $id): JsonResponse
    {
        $d = $this->draft($request, $id);
        $n = DB::table('thank_you_auto_drafts')->where('id', $id)->where('status', 'pending')
            ->update(['status' => 'skipped', 'decided_by_id' => (int) $request->user()->id, 'decided_at' => now(), 'updated_at' => now()]);
        abort_unless($n, 422, 'This draft was already handled.');
        ThankYouAuto::audit((int) $d->agency_id, (int) $request->user()->id, 'thank_you_auto.draft_skipped', $id, [
            'summary' => 'Skipped the automatic thank-you award for ' . ThankYouAuto::name((int) $d->user_id) . ' (' . ThankYouAuto::monthLabel($d->month) . ', ' . number_format((int) $d->points) . ' points). Nothing was sent.',
        ]);
        return response()->json(['ok' => true]);
    }

    public function sendAll(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        @set_time_limit(300);
        $sent = 0; $failed = [];
        foreach (DB::table('thank_you_auto_drafts')->where('agency_id', $aid)->where('status', 'pending')->orderByDesc('points')->pluck('id') as $id) {
            $r = ThankYouAuto::sendDraft((int) $id, (int) $request->user()->id);
            ($r['ok'] ?? false) ? $sent++ : $failed[] = (int) $id;
        }
        return response()->json(['ok' => true, 'sent' => $sent, 'failed' => count($failed)]);
    }
}
