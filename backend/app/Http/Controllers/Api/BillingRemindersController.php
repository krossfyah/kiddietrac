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
        $isPlatform = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $u->id)
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
        $ok = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))
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
        return \App\Support\InvoiceReminders::cfg($agencyId);
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
            // Who can be copied: agency admins, directors and other staff of THIS agency (2026-10-31).
            'staff'          => $this->staffList($request, $agencyId),
        ]);
    }

    /** Admins and directors (default copies) plus every other active staff member, for the "also copy" picker. */
    private function staffList(Request $request, int $agencyId): array
    {
        $cfg = $this->read($agencyId);
        $all = \App\Support\InvoiceReminders::staffChoices($agencyId, null, array_merge($cfg, ['cc_staff' => DB::table('role_assignments')
            ->where('agency_id', $agencyId)->where('active', 1)->whereIn('role', ['educator', 'home_visitor'])->pluck('user_id')->map(fn ($v) => (int) $v)->all()]));
        $see = $this->visibleStaffIds($request, $agencyId);
        $all = array_values(array_filter($all, fn ($s) => isset($see[(int) $s['user_id']])));
        return array_map(fn ($s) => ['user_id' => $s['user_id'], 'name' => $s['name'], 'email' => $s['email'], 'role' => $s['role']], $all);
    }

    /**
     * WHO THIS CALLER MAY SEE IN THE COPY PICKER (2026-11-27, leak guard centre scope). The schedule is agency-wide,
     * but a centre director sees only the staff of their own centres (CentreScope) plus the agency's admins, never a
     * colleague placed at another centre. Everyone is Live: no leavers, no closed-centre-only staff, no accounts
     * that are off. Agency admins keep the whole agency. @return array<int,true>
     */
    private function visibleStaffIds(Request $request, int $agencyId): array
    {
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        $ids = \App\Support\Live::staffIds($agencyId, $scope);
        if ($scope !== null) {
            $admins = DB::table('role_assignments')->where('agency_id', $agencyId)->where('active', 1)
                ->where('role', 'agency_admin')->pluck('user_id')->map(fn ($v) => (int) $v)->all();
            $ids = array_merge($ids, array_intersect(\App\Support\Live::staffIds($agencyId, null), $admins));
        }
        return array_fill_keys(array_map('intval', $ids), true);
    }

    /** GET /admin/billing-reminders/preview - who is due now and who today's reminders would go to (read-only). */
    public function preview(Request $request): JsonResponse
    {
        $this->assertAccess($request);
        $agencyId = $this->resolveAgencyId($request);
        abort_unless($agencyId, 404, 'Agency not found');
        $out = \App\Support\InvoiceReminders::preview($agencyId, 7, true);
        /* A centre director sees the invoices of their own centres only, and the copy list names only people they can
           see in the picker (2026-11-27, centre scope). An agency-wide invoice (no centre) is the agency's, not theirs. */
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        if ($scope !== null) {
            $cent = DB::table('invoices')->whereIn('id', array_column(array_filter($out['rows'], fn ($r) => $r['kind'] === 'native'), 'id') ?: [0])
                ->pluck('centre_id', 'id');
            $xcent = DB::table('external_invoices as x')->join('families as f', 'f.id', '=', 'x.family_id')
                ->whereIn('x.id', array_column(array_filter($out['rows'], fn ($r) => $r['kind'] === 'external'), 'id') ?: [0])
                ->pluck('f.centre_id', 'x.id');
            $emails = array_flip(array_map('strtolower', DB::table('users')->whereIn('id', array_keys($this->visibleStaffIds($request, $agencyId)) ?: [0])
                ->whereNotNull('email')->pluck('email')->map(fn ($e) => trim((string) $e))->all()));
            $out['rows'] = array_values(array_filter($out['rows'], function ($r) use ($cent, $xcent, $scope) {
                $c = $r['kind'] === 'native' ? ($cent[$r['id']] ?? null) : ($xcent[$r['id']] ?? null);
                return $c !== null && in_array((int) $c, $scope, true);
            }));
            foreach ($out['rows'] as &$r) {
                $r['copies'] = array_values(array_filter($r['copies'], fn ($e) => isset($emails[strtolower((string) $e)])));
            }
            unset($r);
        }
        return response()->json($out);
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
            'due_today'           => ['nullable', 'boolean'],
            'overdue_mode'        => ['nullable', 'in:daily,every,weekly,days'],
            'overdue_every'       => ['nullable', 'integer', 'min:1', 'max:60'],
            'overdue_max'         => ['nullable', 'integer', 'min:0', 'max:365'],
            'cc_enabled'          => ['nullable', 'boolean'],
            'cc_issued'           => ['nullable', 'boolean'],
            'cc_admins'           => ['nullable', 'boolean'],
            'cc_directors'        => ['nullable', 'boolean'],
            'cc_staff'            => ['nullable', 'array', 'max:50'],
            'cc_staff.*'          => ['integer'],
        ]);

        $current = $this->read($agencyId);
        $before = $current;
        foreach (\App\Support\InvoiceReminders::DEFAULTS as $k => $def) {
            if (is_bool($def)) {
                if ($request->has($k)) {
                    $current[$k] = $request->boolean($k);
                }
            } elseif ($k === 'cc_staff') {
                if (array_key_exists('cc_staff', $data)) {
                    // Only people with an active role in THIS agency can be copied.
                    $ids = array_values(array_unique(array_map('intval', (array) ($data['cc_staff'] ?? []))));
                    /* A centre director only picks from the people they can see, and their save never drops a copy the
                       agency set up for somebody outside their centres (they cannot see it, so they cannot have unticked it). */
                    $keep = [];
                    if (\App\Support\CentreScope::ids($request, $agencyId) !== null) {
                        $see = $this->visibleStaffIds($request, $agencyId);
                        $ids = array_values(array_filter($ids, fn ($id) => isset($see[$id])));
                        $keep = array_values(array_filter(array_map('intval', (array) ($current['cc_staff'] ?? [])), fn ($id) => ! isset($see[$id])));
                    }
                    $current['cc_staff'] = $ids ? DB::table('role_assignments')->where('agency_id', $agencyId)->where('active', 1)
                        ->whereIn('user_id', $ids)->where('role', '!=', 'guardian')->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->values()->all() : [];
                    $current['cc_staff'] = array_values(array_unique(array_merge($current['cc_staff'], $keep)));
                }
            } elseif (array_key_exists($k, $data) && $data[$k] !== null) {
                $current[$k] = is_int($def) ? (int) $data[$k] : $data[$k];
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
            \App\Support\Audit::write([
                'user_id'     => $request->user()->id ?? null,
                'action'      => 'billing_reminders_updated',
                'entity_type' => 'agency',
                'entity_id'   => $agencyId,
                'payload'     => json_encode((function () use ($before, $current, $data) {
                    $changes = [];
                    foreach ($current as $k => $v) {
                        if (($before[$k] ?? null) !== $v) $changes[$k] = ['from' => $before[$k] ?? null, 'to' => $v];
                    }
                    $f = fn ($v) => is_bool($v) ? ($v ? 'on' : 'off') : (is_array($v) ? count($v) . ' people' : ((string) $v === '' ? '(none)' : (string) $v));
                    $bits = [];
                    foreach ($changes as $k => $c) $bits[] = str_replace('_', ' ', $k) . ': ' . $f($c['from']) . ' → ' . $f($c['to']);
                    return ['summary' => $changes ? 'Changed invoice reminder settings — ' . implode('; ', $bits) : 'Saved invoice reminder settings (no changes)',
                        'changes' => $changes, 'fields' => array_keys($data)];
                })()),
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
