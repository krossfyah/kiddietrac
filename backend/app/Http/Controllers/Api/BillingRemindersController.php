<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Per-agency invoice & payment reminder schedules. Agency admins AND centre
 * directors configure when automated reminders go out. Stored in the agencies
 * settings JSON under "billing_reminders". Reminders default OFF — nothing is
 * sent until an agency turns them on. The billing:reminders command consumes
 * this config.
 */
final class BillingRemindersController extends Controller
{
    private const DEFAULTS = [
        'invoice_enabled'     => false,   // remind before an invoice is due
        'invoice_days_before' => '7,3',   // days before due_at
        'overdue_enabled'     => false,   // remind after an invoice is overdue
        'overdue_days_after'  => '1,7,14', // days after due_at
        'send_time'           => '09:00', // agency-local send time (HH:MM)
        'channel_email'       => true,
        'cc_admin'            => false,   // also copy the agency billing contact
        'custom_message'      => '',      // optional line added to each reminder
    ];

    private function resolveAgencyId(Request $request): int
    {
        $u = $request->user();
        $header = (int) $request->header('X-Active-Agency-Id');

        // The header is a request, not a fact. Honour it only for a platform_admin (who
        // may target any agency) or someone who actually holds an active role in the
        // agency named. Otherwise fall back to their own — never trust it blindly.
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', 1)->exists();
        if ($header && $isPlatform) {
            return $header;
        }
        if ($header && DB::table('role_assignments')->where('user_id', $u->id)
            ->where('active', 1)->where('agency_id', $header)->exists()) {
            return $header;
        }

        return (int) DB::table('role_assignments')
            ->where('user_id', $u->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])
            ->value('agency_id');
    }

    private function assertAccess(Request $request): void
    {
        $u = $request->user();
        $ok = DB::table('role_assignments')
            ->where('user_id', $u->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])
            ->exists();
        abort_unless($ok, 403, 'Billing access required');
    }

    /** Normalise "7, 3, 1, 3, 400" → "1,3,7" (unique, sorted, 0–365). */
    private function normDays(?string $csv): string
    {
        $days = collect(explode(',', (string) $csv))
            ->map(fn ($x) => (int) trim($x))
            ->filter(fn ($n) => $n >= 0 && $n <= 365)
            ->unique()->sort()->values();
        return $days->implode(',');
    }

    private function read(int $agencyId): array
    {
        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $settings = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        $r = (isset($settings['billing_reminders']) && is_array($settings['billing_reminders'])) ? $settings['billing_reminders'] : [];
        return array_merge(self::DEFAULTS, $r);
    }

    /** GET /admin/billing-reminders */
    public function show(Request $request): JsonResponse
    {
        $this->assertAccess($request);
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('agencies')->where('id', $agencyId)->select('id', 'name')->first();
        abort_unless($row, 404, 'Agency not found');

        return response()->json([
            'agency_id'      => $row->id,
            'agency_name'    => $row->name,
            'reminders'      => $this->read($agencyId),
            'global_enabled' => (bool) config('billing.reminders_enabled'),
        ]);
    }

    /** POST /admin/billing-reminders */
    public function update(Request $request): JsonResponse
    {
        $this->assertAccess($request);
        $agencyId = $this->resolveAgencyId($request);

        $data = $request->validate([
            'invoice_enabled'     => ['nullable', 'boolean'],
            'invoice_days_before' => ['nullable', 'string', 'max:120', 'regex:/^[0-9,\s]*$/'],
            'overdue_enabled'     => ['nullable', 'boolean'],
            'overdue_days_after'  => ['nullable', 'string', 'max:120', 'regex:/^[0-9,\s]*$/'],
            'send_time'           => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'channel_email'       => ['nullable', 'boolean'],
            'cc_admin'            => ['nullable', 'boolean'],
            'custom_message'      => ['nullable', 'string', 'max:500'],
        ]);

        $current = $this->read($agencyId);
        foreach (self::DEFAULTS as $k => $def) {
            if (is_bool($def)) {
                if ($request->has($k)) {
                    $current[$k] = $request->boolean($k);
                }
            } elseif (array_key_exists($k, $data) && $data[$k] !== null) {
                $current[$k] = $data[$k];
            }
        }
        $current['invoice_days_before'] = $this->normDays($current['invoice_days_before']) ?: '7,3';
        $current['overdue_days_after']  = $this->normDays($current['overdue_days_after']) ?: '1,7,14';

        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        abort_unless($row, 404, 'Agency not found');
        $settings = ($row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        $settings['billing_reminders'] = $current;

        DB::table('agencies')->where('id', $agencyId)->update([
            'settings'   => json_encode($settings),
            'updated_at' => now(),
        ]);

        try {
            DB::table('audit_logs')->insert([
                'user_id'     => $request->user()->id ?? null,
                'action'      => 'billing_reminders_updated',
                'entity_type' => 'agency',
                'entity_id'   => $agencyId,
                'payload'     => json_encode(['fields' => array_keys($data)]),
                'ip_address'  => $request->ip(),
                'user_agent'  => substr((string) $request->userAgent(), 0, 500),
                'created_at'  => now(),
            ]);
        } catch (Throwable $e) {
            // never fail the save on audit
        }

        return response()->json(['status' => 'saved', 'reminders' => $current]);
    }
}
