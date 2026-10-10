<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgencyMailer;
use App\Support\AgencyTime;
use App\Support\BioReminders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Notifications → "Provider bios" (2026-10-09). Agency admins choose whether and
 * how often providers without a bio are reminded; see App\Support\BioReminders.
 */
class BioRemindersController extends Controller
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
        $last = BioReminders::lastSent($agencyId);
        $tz = AgencyTime::tz($agencyId);
        $missing = BioReminders::missing($agencyId, $s);

        return ['settings' => $s, 'tz' => $tz,
            'missing' => $missing->map(fn ($p) => ['name' => trim($p->first_name . ' ' . $p->last_name), 'length' => $p->length,
                'has_photo' => $p->has_photo, 'reminders' => $p->reminders])->values(),
            'last_sent_at' => $last && $last->last_sent_at ? \Illuminate\Support\Carbon::parse($last->last_sent_at, 'UTC')->setTimezone($tz)->format('M j, Y g:i A') : null];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'enabled' => 'nullable|boolean',
            'frequency' => 'nullable|in:' . implode(',', BioReminders::FREQUENCIES),
            'weekly_day' => 'nullable|integer|min:1|max:7',
            'every_n_days' => 'nullable|integer|min:1|max:30',
            'time' => 'nullable|date_format:H:i',
            'min_chars' => 'nullable|integer|min:40|max:400',
            'max_reminders' => 'nullable|integer|min:0|max:52',
            'email' => 'nullable|boolean', 'in_app' => 'nullable|boolean', 'push' => 'nullable|boolean',
            'banner' => 'nullable|boolean', 'cc_summary' => 'nullable|boolean',
        ]);
    }

    private function merged(int $agencyId, array $data): array
    {
        $s = BioReminders::read($agencyId);
        foreach (BioReminders::DEFAULTS as $k => $def) {
            if (array_key_exists($k, $data) && $data[$k] !== null) {
                $s[$k] = is_bool($def) ? (bool) $data[$k] : (is_int($def) ? (int) $data[$k] : $data[$k]);
            }
        }

        return $s;
    }

    public function show(Request $request): JsonResponse
    {
        $a = $this->agencyId($request);

        return response()->json($this->payload($a, BioReminders::read($a)));
    }

    public function update(Request $request): JsonResponse
    {
        $a = $this->agencyId($request);
        $before = BioReminders::read($a);
        $s = BioReminders::write($a, $this->merged($a, $this->validated($request)));
        $changed = [];
        foreach ($s as $k => $v) if (($before[$k] ?? null) !== $v) $changed[] = $k . ': ' . json_encode($before[$k] ?? null) . ' → ' . json_encode($v);
        try {
            \App\Support\Audit::write(['user_id' => $request->user()->id, 'agency_id' => $a, 'action' => 'providers.bio_reminder_settings', 'entity_type' => 'agency', 'entity_id' => $a,
                'payload' => json_encode(['summary' => 'Provider bio reminder settings changed' . ($changed ? ': ' . implode('; ', $changed) : ' (no changes)')]), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json($this->payload($a, $s));
    }

    /** The reminder a provider gets — the first one missing a bio, or a sample. Sends nothing. */
    public function preview(Request $request): JsonResponse
    {
        $a = $this->agencyId($request);
        $s = $this->merged($a, $this->validated($request));
        [$subject, $html, $sample] = $this->build($a, $s);

        return response()->json(['subject' => $subject, 'html' => $html, 'sample' => $sample, 'missing' => BioReminders::missing($a, $s)->count()]);
    }

    /** ONE test reminder to an address the admin types. Never defaulted. */
    public function test(Request $request): JsonResponse
    {
        $a = $this->agencyId($request);
        $to = $request->validate(['to' => ['required', 'email', 'max:190']])['to'];
        $s = $this->merged($a, $this->validated($request));
        [$subject, $html] = $this->build($a, $s, true);
        $before = (int) DB::table('email_logs')->max('id');
        try {
            AgencyMailer::forAgency($a)->html($html, function ($m) use ($to, $subject) { $m->to($to)->subject($subject); });
        } catch (\Throwable $e) {
            return response()->json(['sent' => false, 'status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 300)]);
        }
        $row = DB::table('email_logs')->where('id', '>', $before)->where('to_email', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], strtolower($to)) . '%')->orderByDesc('id')->first(['status', 'error']);
        $status = $row ? (string) $row->status : 'failed';
        try {
            \App\Support\Audit::write(['user_id' => $request->user()->id, 'agency_id' => $a, 'action' => 'providers.bio_reminder_test', 'entity_type' => 'agency', 'entity_id' => $a,
                'payload' => json_encode(['summary' => 'Test provider bio reminder to ' . $to . ': ' . $status]), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['sent' => $status === 'sent', 'status' => $status,
            'reason' => $status === 'sent' ? null : ($row->error ?? ($row ? null : 'The mail layer recorded nothing for this message.'))]);
    }

    private function build(int $agencyId, array $s, bool $test = false): array
    {
        $p = BioReminders::missing($agencyId, $s)->first();
        $sample = false;
        if (! $p) {
            $sample = true;
            $p = (object) ['user_id' => 0, 'first_name' => 'Maria', 'last_name' => 'Sample', 'email' => '', 'has_photo' => false,
                'centre_id' => 0, 'centre' => 'Sample provider', 'length' => 0, 'reminders' => 0];
        }
        [$subject, $html] = BioReminders::email($agencyId, $p, $s, $test || $sample);

        return [$subject, $html, $sample];
    }
}
