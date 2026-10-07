<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgencyMailer;
use App\Support\AgencyTime;
use App\Support\TimeOffReminders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Clock settings → Time-off approvals. Agency admins (and platform admins)
 * choose whether and how often approvers are reminded about pending time-off requests.
 * Stored in agencies.settings->timeoff_reminders; see App\Support\TimeOffReminders.
 */
class TimeOffRemindersController extends Controller
{
    private function agencyId(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $header = (int) $request->header('X-Active-Agency-Id');
        if ($header && DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
                ->where(fn ($q) => $q->where('role', 'platform_admin')->orWhere(fn ($w) => $w->where('agency_id', $header)->where('role', 'agency_admin')))->exists()) {
            return $header;
        }
        $own = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->where('role', 'agency_admin')->value('agency_id');
        abort_unless($own, 403, 'Agency administrators only.');

        return (int) $own;
    }

    private function payload(int $agencyId, array $s): array
    {
        $last = TimeOffReminders::lastSent($agencyId);
        $tz = AgencyTime::tz($agencyId);
        $pending = DB::table('time_off_requests')->where('agency_id', $agencyId)->where('status', 'pending')->count();

        return ['settings' => $s, 'tz' => $tz, 'pending_now' => $pending,
            'last_sent_at' => $last && $last->last_sent_at ? \Illuminate\Support\Carbon::parse($last->last_sent_at, 'UTC')->setTimezone($tz)->format('M j, Y g:i A') : null];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'enabled' => 'nullable|boolean',
            'frequency' => 'nullable|in:' . implode(',', TimeOffReminders::FREQUENCIES),
            'weekly_day' => 'nullable|integer|min:1|max:7',
            'every_n_days' => 'nullable|integer|min:1|max:30',
            'time' => 'nullable|date_format:H:i',
            'min_hours' => 'nullable|integer|min:0|max:720',
            'recipients' => 'nullable|in:' . implode(',', TimeOffReminders::RECIPIENTS),
            'email' => 'nullable|boolean',
            'in_app' => 'nullable|boolean',
            'push' => 'nullable|boolean',
        ]);
    }

    private function merged(int $agencyId, array $data): array
    {
        $s = TimeOffReminders::read($agencyId);
        foreach (TimeOffReminders::DEFAULTS as $k => $def) {
            if (array_key_exists($k, $data) && $data[$k] !== null) {
                $s[$k] = is_bool($def) ? (bool) $data[$k] : (is_int($def) ? (int) $data[$k] : $data[$k]);
            }
        }

        return $s;
    }

    public function show(Request $request): JsonResponse
    {
        $a = $this->agencyId($request);

        return response()->json($this->payload($a, TimeOffReminders::read($a)));
    }

    public function update(Request $request): JsonResponse
    {
        $a = $this->agencyId($request);
        $before = TimeOffReminders::read($a);
        $s = TimeOffReminders::write($a, $this->merged($a, $this->validated($request)));
        $changed = [];
        foreach ($s as $k => $v) if (($before[$k] ?? null) !== $v) $changed[] = $k . ': ' . json_encode($before[$k] ?? null) . ' → ' . json_encode($v);
        try {
            \App\Support\Audit::write(['user_id' => $request->user()->id, 'agency_id' => $a, 'action' => 'timeoff.reminder_settings', 'entity_type' => 'agency', 'entity_id' => $a,
                'payload' => json_encode(['summary' => 'Time-off approval reminder settings changed' . ($changed ? ': ' . implode('; ', $changed) : ' (no changes)')]), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json($this->payload($a, $s));
    }

    /** What an approver would receive — the signed-in admin's own digest, built from the
        agency's real pending requests (or a sample when there are none). Sends nothing. */
    public function preview(Request $request): JsonResponse
    {
        $a = $this->agencyId($request);
        $s = $this->merged($a, $this->validated($request));
        [$subject, $html, $count, $sample, $approvers] = $this->build($request, $a, $s);

        return response()->json(['subject' => $subject, 'html' => $html, 'requests' => $count, 'sample' => $sample, 'approvers' => $approvers]);
    }

    /** Send ONE test digest to an address the admin types. Never defaulted. */
    public function test(Request $request): JsonResponse
    {
        $a = $this->agencyId($request);
        $to = $request->validate(['to' => ['required', 'email', 'max:190']])['to'];
        $s = $this->merged($a, $this->validated($request));
        [$subject, $html] = $this->build($request, $a, $s, true);
        $before = (int) DB::table('email_logs')->max('id');
        try {
            AgencyMailer::forAgency($a)->html($html, function ($m) use ($to, $subject) { $m->to($to)->subject($subject); });
        } catch (\Throwable $e) {
            return response()->json(['sent' => false, 'status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 300)]);
        }
        $row = DB::table('email_logs')->where('id', '>', $before)->where('to_email', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], strtolower($to)) . '%')->orderByDesc('id')->first(['status', 'error']);
        $status = $row ? (string) $row->status : 'failed';
        try {
            \App\Support\Audit::write(['user_id' => $request->user()->id, 'agency_id' => $a, 'action' => 'timeoff.reminder_test', 'entity_type' => 'agency', 'entity_id' => $a,
                'payload' => json_encode(['summary' => 'Test time-off reminder to ' . $to . ': ' . $status]), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['sent' => $status === 'sent', 'status' => $status,
            'reason' => $status === 'sent' ? null : ($row->error ?? ($row ? null : 'The mail layer recorded nothing for this message.'))]);
    }

    private function build(Request $request, int $agencyId, array $s, bool $test = false): array
    {
        $reqs = TimeOffReminders::pending($agencyId, array_merge($s, ['min_hours' => 0]));
        $map = TimeOffReminders::approvers($agencyId, $s, $reqs);
        $me = (object) ['user_id' => (int) $request->user()->id, 'email' => $request->user()->email, 'first_name' => $request->user()->first_name, 'last_name' => $request->user()->last_name];
        $mine = $map[$me->user_id]['requests'] ?? $reqs->values()->all();
        $sample = false;
        if (! $mine) {
            $sample = true;
            $mine = [(object) ['id' => 0, 'user_id' => 0, 'staff_name' => 'Sample Educator', 'request_type' => 'vacation',
                'start_at' => now()->addDays(10)->toDateString(), 'end_at' => now()->addDays(12)->toDateString(), 'all_day' => 1,
                'start_time' => null, 'end_time' => null, 'reason' => 'Family trip', 'created_at' => now()->subDays(2)->toDateTimeString()]];
        }
        [$subject, $html] = TimeOffReminders::digest($agencyId, $me, $mine, $test || $sample);

        return [$subject, $html, count($mine), $sample, count($map)];
    }
}
