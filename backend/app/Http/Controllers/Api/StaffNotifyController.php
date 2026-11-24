<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\StaffNotifyRequirement as Req;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Required app notifications for staff (2026-10-31) - see App\Support\StaffNotifyRequirement.
 *   GET  /me/notify-requirement                 the caller: is it required here, what the server sees of their devices
 *   GET  /admin/staff-notify-settings            agency admins: the setting
 *   POST /admin/staff-notify-settings            agency admins: change it (audited)
 *   GET  /admin/notify-readiness                 admins and directors (their centres): who can receive notifications
 *   POST /admin/notify-readiness/instructions    agency admins: email + bell setup steps to chosen staff (confirm=true)
 */
final class StaffNotifyController extends Controller
{
    private function agencyId(Request $request): int
    {
        $aid = \App\Support\AgencyRole::activeAgency($request);
        if ($aid) return $aid;
        $header = (int) $request->header('X-Active-Agency-Id');
        if ($header && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)->where('role', 'platform_admin')->exists()) return $header;
        return 0;
    }

    private function isAdmin(Request $request): bool
    {
        return \App\Support\AgencyRole::constrain(DB::table('role_assignments'))
            ->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin'])->exists();
    }

    public function me(Request $request): JsonResponse
    {
        $aid = $this->agencyId($request);
        $uid = (int) $request->user()->id;
        /* app_gate (2026-11-02, Anthony: "this should be mandatory for the app to work properly including GPS"): in the
           Android / iPhone app EVERYONE (staff of every agency and parents) must allow notifications and location
           while using the app, except platform admins, website-demo people and service logins. Platform switch
           PlatformSettings app.require_permissions ('0' turns it off at once, no deploy). Browsers are not gated. */
        $appGate = \App\Support\DevicePresence::gateFor($uid);
        if (! $aid) return response()->json(['required' => false, 'app_gate' => $appGate]);
        $required = Req::requiredFor($aid, $uid);
        return response()->json([
            'required' => $required,
            'app_gate' => $appGate,
            'agency' => (string) DB::table('agencies')->where('id', $aid)->value('name'),
            'locked' => $required ? Req::LOCKED : [],
            'devices' => Req::devices($uid),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        abort_unless($this->isAdmin($request), 403, 'Only agency administrators can change this.');
        $aid = $this->agencyId($request);
        abort_unless($aid > 0, 404);
        return response()->json(['settings' => Req::settings($aid), 'roles' => Req::ROLES]);
    }

    public function save(Request $request): JsonResponse
    {
        abort_unless($this->isAdmin($request), 403, 'Only agency administrators can change this.');
        $aid = $this->agencyId($request);
        abort_unless($aid > 0, 404);
        $data = $request->validate(['required' => 'required|boolean', 'roles' => 'array', 'roles.*' => 'string|in:' . implode(',', array_keys(Req::ROLES))]);
        $before = Req::settings($aid);
        $after = Req::save($aid, (bool) $data['required'], (array) ($data['roles'] ?? []));
        $label = fn ($s) => $s['required'] ? 'required for ' . (implode(', ', array_map(fn ($r) => Req::ROLES[$r], $s['roles'])) ?: 'nobody') : 'not required';
        try {
            \App\Support\Audit::write([
                'action' => 'settings.staff_notifications_updated', 'agency_id' => $aid, 'user_id' => $request->user()->id,
                'summary' => 'Changed required app notifications for staff: ' . $label($before) . ' → ' . $label($after),
                'payload' => json_encode(['before' => $before, 'after' => $after]),
            ]);
        } catch (\Throwable $e) {}
        return response()->json(['ok' => true, 'settings' => $after]);
    }

    /* Immunization and drill notices, per role (2026-11-09, App\Support\StaffTopicNotify). Admins only. */
    public function topicsShow(Request $request): JsonResponse
    {
        abort_unless($this->isAdmin($request), 403, 'Only agency administrators can change this.');
        $aid = $this->agencyId($request);
        abort_unless($aid > 0, 404);
        return response()->json(['settings' => \App\Support\StaffTopicNotify::settings($aid),
            'topics' => \App\Support\StaffTopicNotify::TOPICS, 'roles' => \App\Support\StaffTopicNotify::ROLES]);
    }

    public function topicsSave(Request $request): JsonResponse
    {
        abort_unless($this->isAdmin($request), 403, 'Only agency administrators can change this.');
        $aid = $this->agencyId($request);
        abort_unless($aid > 0, 404);
        $rules = ['settings' => 'required|array'];
        foreach (array_keys(\App\Support\StaffTopicNotify::TOPICS) as $t) {
            foreach (array_keys(\App\Support\StaffTopicNotify::ROLES) as $r) {
                $rules["settings.$t.$r"] = 'required|boolean';
            }
        }
        $data = $request->validate($rules);
        $before = \App\Support\StaffTopicNotify::settings($aid);
        $after = \App\Support\StaffTopicNotify::save($aid, (array) $data['settings']);
        $off = function ($s) {
            $parts = [];
            foreach ($s as $t => $roles) {
                $no = array_keys(array_filter($roles, fn ($v) => ! $v));
                $parts[] = \App\Support\StaffTopicNotify::TOPICS[$t] . ': ' . ($no ? 'off for ' . implode(', ', array_map(fn ($r) => \App\Support\StaffTopicNotify::ROLES[$r], $no)) : 'everyone');
            }
            return implode('; ', $parts);
        };
        try {
            \App\Support\Audit::write([
                'action' => 'settings.topic_notifications_updated', 'agency_id' => $aid, 'user_id' => $request->user()->id,
                'summary' => 'Changed who hears about immunizations and drills: ' . $off($before) . ' → ' . $off($after),
                'payload' => json_encode(['before' => $before, 'after' => $after]),
            ]);
        } catch (\Throwable $e) {}
        return response()->json(['ok' => true, 'settings' => $after]);
    }

    public function readiness(Request $request): JsonResponse
    {
        $aid = $this->agencyId($request);
        abort_unless($aid > 0, 404);
        $mine = \App\Support\Visibility::centreIds($aid, $request->user());
        $all = DB::table('centres')->where('agency_id', $aid)->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all();
        sort($mine); sort($all);
        $res = Req::readiness($aid, $this->isAdmin($request) || $mine === $all ? null : $mine);
        $res['can_send'] = $this->isAdmin($request);
        return response()->json($res);
    }

    public function instructions(Request $request): JsonResponse
    {
        abort_unless($this->isAdmin($request), 403, 'Only agency administrators can send these.');
        $aid = $this->agencyId($request);
        abort_unless($aid > 0, 404);
        $data = $request->validate(['user_ids' => 'required|array|min:1|max:200', 'user_ids.*' => 'integer', 'confirm' => 'boolean']);
        $people = collect(Req::readiness($aid)['people'])->whereIn('user_id', array_map('intval', $data['user_ids']))->values();
        if (empty($data['confirm'])) {
            return response()->json(['would' => $people->map(fn ($p) => ['user_id' => $p['user_id'], 'name' => $p['name'], 'email' => $p['email'], 'status' => $p['status']])]);
        }
        $sent = 0; $failed = 0; $names = [];
        foreach ($people as $p) {
            if (\App\Support\Suppression::isUser((int) $p['user_id'])) continue;
            [$subject, $html] = Req::instructionsEmail($aid, $p);
            if (filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                try {
                    \App\Services\AgencyMailer::forAgency($aid)->html($html, function ($m) use ($p, $subject) { $m->to($p['email'], $p['name'] ?: null)->subject($subject); });
                    $sent++;
                } catch (\Throwable $e) { $failed++; Log::warning('notify instructions email failed', ['user' => $p['user_id'], 'error' => $e->getMessage()]); }
            }
            \App\Support\Notify::write(['user_id' => $p['user_id'], 'type' => 'staff_notify_setup', 'title' => '📲 Turn on notifications on your phone',
                'body' => 'Required for your role: headcounts, Safe Arrival alerts and messages must reach your phone. Open the app and tap Turn on notifications.',
                'data' => json_encode(['link' => '#dashboard']), 'created_at' => now()]);
            $names[] = $p['name'];
        }
        try {
            \App\Support\Audit::write([
                'action' => 'staff_notifications.instructions_sent', 'agency_id' => $aid, 'user_id' => $request->user()->id,
                'summary' => 'Sent notification setup instructions to ' . count($names) . ' staff (' . implode(', ', $names) . ') — ' . $sent . ' email(s)' . ($failed ? ', ' . $failed . ' failed' : ''),
                'payload' => json_encode(['user_ids' => $people->pluck('user_id'), 'emails' => $sent, 'failed' => $failed]),
            ]);
        } catch (\Throwable $e) {}
        return response()->json(['ok' => true, 'emails' => $sent, 'failed' => $failed, 'notified' => count($names)]);
    }
}
