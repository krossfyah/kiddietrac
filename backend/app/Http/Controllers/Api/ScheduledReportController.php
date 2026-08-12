<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled reports — an admin sets up a canned report to be emailed on a
 * cadence. Delivery is done by the kiddietrac:send-scheduled-reports command.
 */
class ScheduledReportController extends Controller
{
    use ResolvesCentreContext;

    private const TYPES = [
        'attendance', 'enrollment', 'payments', 'invoices', 'families',
        'staff_hours', 'waitlist', 'incidents', 'observations', 'tours',
    ];
    private const TITLES = [
        'attendance' => 'Attendance log', 'enrollment' => 'Enrollment roster',
        'payments' => 'Payments received', 'invoices' => 'Invoices & balances',
        'families' => 'Family directory', 'staff_hours' => 'Staff hours',
        'waitlist' => 'Waitlist', 'incidents' => 'Incidents & injuries',
        'observations' => 'Observations', 'tours' => 'Tour bookings',
    ];

    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) {
            return response()->json(['message' => 'No active agency.'], 422);
        }

        $rows = DB::table('report_schedules as rs')
            ->leftJoin('users as u', 'u.id', '=', 'rs.recipient_user_id')
            ->leftJoin('centres as c', 'c.id', '=', 'rs.centre_id')
            ->where('rs.agency_id', $agencyId)
            ->orderByDesc('rs.created_at')
            ->get([
                'rs.*',
                'u.first_name as ru_first', 'u.last_name as ru_last', 'u.email as ru_email',
                'c.name as centre_name',
            ]);

        $out = $rows->map(function ($r) {
            $recipient = $r->recipient_email ?: trim(($r->ru_first ?? '') . ' ' . ($r->ru_last ?? ''));
            if (! $recipient && $r->ru_email) {
                $recipient = $r->ru_email;
            }

            return [
                'id' => (int) $r->id,
                'report_type' => $r->report_type,
                'report_title' => self::TITLES[$r->report_type] ?? $r->report_type,
                'centre_id' => $r->centre_id ? (int) $r->centre_id : null,
                'centre_name' => $r->centre_name,
                'format' => $r->format,
                'frequency' => $r->frequency,
                'day_of_week' => $r->day_of_week !== null ? (int) $r->day_of_week : null,
                'day_of_month' => $r->day_of_month !== null ? (int) $r->day_of_month : null,
                'send_time' => $r->send_time,
                'range_kind' => $r->range_kind,
                'recipient' => $recipient ?: '—',
                'recipient_email' => $r->recipient_email,
                'recipient_user_id' => $r->recipient_user_id ? (int) $r->recipient_user_id : null,
                'active' => (bool) $r->active,
                'last_sent_on' => $r->last_sent_on,
                'schedule_label' => $this->label($r),
            ];
        });

        return response()->json(['schedules' => $out->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) {
            return response()->json(['message' => 'No active agency.'], 422);
        }
        $data = $this->validated($request, $agencyId);
        $data['agency_id'] = $agencyId;
        $data['created_by'] = $request->user()->id ?? null;
        $data['created_at'] = now();
        $data['updated_at'] = now();

        $id = DB::table('report_schedules')->insertGetId($data);

        // Confirm the setup to the person who created it (branded email).
        try {
            $creator = $request->user();
            if ($creator && ! empty($creator->email)) {
                $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                $when = ucfirst((string) $data['frequency']);
                if (($data['frequency'] ?? '') === 'weekly' && isset($data['day_of_week'])) {
                    $when .= ' on ' . ($days[$data['day_of_week']] ?? '');
                } elseif (($data['frequency'] ?? '') === 'monthly' && isset($data['day_of_month'])) {
                    $when .= ' on day ' . $data['day_of_month'];
                }
                $when .= ' at ' . ($data['send_time'] ?? '');
                $recipient = $data['recipient_email'] ?? null;
                if (! $recipient && ! empty($data['recipient_user_id'])) {
                    $recipient = DB::table('users')->where('id', $data['recipient_user_id'])->value('email');
                }
                $reportLabel = ucwords(str_replace('_', ' ', (string) $data['report_type']));
                $body = "Your scheduled report has been set up successfully.\n\n"
                      . "• Report: {$reportLabel}\n"
                      . "• Format: " . strtoupper((string) $data['format']) . "\n"
                      . "• Frequency: {$when}\n"
                      . "• Delivered to: " . ($recipient ?: 'the selected recipient') . "\n\n"
                      . "It will be generated and emailed automatically on this schedule. You can edit or cancel it anytime under Reports → Scheduled reports.";
                $name = trim((($creator->first_name ?? '') . ' ' . ($creator->last_name ?? ''))) ?: 'there';
                \Illuminate\Support\Facades\Mail::to($creator->email, $name !== 'there' ? $name : null)->queue(
                    (new \App\Mail\AccountNotice(
                        recipientName: $name,
                        subjectLine:   'Your scheduled report is set up',
                        bodyText:      $body,
                        ctaLabel:      'Manage scheduled reports',
                        ctaUrl:        config('app.url', 'https://app.kiddietrac.com'),
                    ))->onQueue('mail')
                );
            }
        } catch (\Throwable $e) {
            // never fail the setup because the confirmation email couldn't queue
        }

        return response()->json(['message' => 'Schedule created', 'id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('report_schedules')->where('id', $id)->where('agency_id', $agencyId)->first();
        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }
        // Allow a lightweight active-only toggle, or a full edit.
        if ($request->has('active') && count($request->all()) === 1) {
            DB::table('report_schedules')->where('id', $id)->update([
                'active' => (bool) $request->boolean('active'),
                'updated_at' => now(),
            ]);
            return response()->json(['message' => 'Saved']);
        }
        $data = $this->validated($request, $agencyId);
        $data['updated_at'] = now();
        DB::table('report_schedules')->where('id', $id)->update($data);

        return response()->json(['message' => 'Saved']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $deleted = DB::table('report_schedules')->where('id', $id)->where('agency_id', $agencyId)->delete();
        if (! $deleted) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['message' => 'Deleted']);
    }

    /** Send one schedule immediately (test the setup). */
    public function runNow(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('report_schedules')->where('id', $id)->where('agency_id', $agencyId)->first();
        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $sent = \App\Console\Commands\SendScheduledReports::sendOne($row, true);

        return $sent
            ? response()->json(['message' => 'Sent'])
            : response()->json(['message' => 'Could not send (no recipient or empty report).'], 422);
    }

    private function validated(Request $request, int $agencyId): array
    {
        $v = $request->validate([
            'report_type' => ['required', 'in:' . implode(',', self::TYPES)],
            'centre_id' => ['nullable', 'integer'],
            'format' => ['required', 'in:pdf,csv,both'],
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'day_of_month' => ['nullable', 'integer', 'between:1,28'],
            'send_time' => ['required', 'regex:/^\d{1,2}:\d{2}$/'],
            'range_kind' => ['required', 'in:last_7d,last_30d,this_month,last_month,all'],
            'recipient_user_id' => ['nullable', 'integer'],
            'recipient_email' => ['nullable', 'email', 'max:190'],
            'active' => ['nullable', 'boolean'],
        ]);

        if (empty($v['recipient_user_id']) && empty($v['recipient_email'])) {
            abort(422, 'Choose a recipient (a user or an email address).');
        }
        // Scope the centre + recipient user to the agency.
        if (! empty($v['centre_id']) && ! DB::table('centres')->where('id', $v['centre_id'])->where('agency_id', $agencyId)->exists()) {
            $v['centre_id'] = null;
        }
        $v['active'] = $request->has('active') ? (bool) $request->boolean('active') : true;

        return $v;
    }

    private function label($r): string
    {
        $dow = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $when = $r->frequency === 'daily'
            ? 'Every day'
            : ($r->frequency === 'weekly'
                ? 'Every ' . ($dow[$r->day_of_week ?? 1] ?? 'Monday')
                : 'Monthly on day ' . ($r->day_of_month ?? 1));

        return $when . ' at ' . $r->send_time;
    }
}
