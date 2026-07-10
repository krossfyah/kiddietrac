<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Per-agency Data Retention & Compliance settings (SOC 2 — Privacy / CC / C1).
 * Agency admins control how long their data is kept, consent/privacy details,
 * and the data-protection contact. Persisted in the agencies.settings JSON blob
 * under the "compliance" key (agency-scoped, no schema change).
 *
 * These record the agency's POLICY. Automatic enforcement (purging/anonymising
 * past-retention records) is intentionally decoupled — it is applied by a
 * separate, safeguarded job only when auto_enforce is enabled.
 */
final class DataRetentionController extends Controller
{
    private const DEFAULTS = [
        'child_record_years' => 7,          // keep child/enrolment records this long after departure
        'daily_log_months'   => 36,         // attendance / daily logs
        'message_months'     => 24,         // parent–educator messages
        'document_years'     => 7,          // uploaded documents
        'audit_log_months'   => 24,         // security/audit trail
        'auto_enforce'       => false,      // apply retention automatically (nightly)
        'enforce_mode'       => 'anonymize', // anonymize | delete
        'require_consent'    => true,       // require parent consent at enrolment
        'privacy_policy_url' => '',
        'data_contact_email' => '',
        'notes'              => '',
    ];

    private function resolveAgencyId(Request $request): int
    {
        $header = $request->header('X-Active-Agency-Id');
        if ($header) {
            return (int) $header;
        }
        $u = $request->user();
        return (int) DB::table('role_assignments')
            ->where('user_id', $u->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])
            ->value('agency_id');
    }

    private function assertAdmin(Request $request): void
    {
        $u = $request->user();
        $ok = DB::table('role_assignments')
            ->where('user_id', $u->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin'])
            ->exists();
        abort_unless($ok, 403, 'Admin only');
    }

    private function read(int $agencyId): array
    {
        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        $settings = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        $c = (isset($settings['compliance']) && is_array($settings['compliance'])) ? $settings['compliance'] : [];
        return array_merge(self::DEFAULTS, $c);
    }

    /** GET /admin/compliance-settings */
    public function show(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('agencies')->where('id', $agencyId)->select('id', 'name')->first();
        abort_unless($row, 404, 'Agency not found');

        return response()->json([
            'agency_id'   => $row->id,
            'agency_name' => $row->name,
            'compliance'  => $this->read($agencyId),
        ]);
    }

    /** PATCH /admin/compliance-settings */
    public function update(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);

        $data = $request->validate([
            'child_record_years' => ['nullable', 'integer', 'min:1', 'max:50'],
            'daily_log_months'   => ['nullable', 'integer', 'min:1', 'max:600'],
            'message_months'     => ['nullable', 'integer', 'min:1', 'max:600'],
            'document_years'     => ['nullable', 'integer', 'min:1', 'max:50'],
            'audit_log_months'   => ['nullable', 'integer', 'min:1', 'max:600'],
            'auto_enforce'       => ['nullable', 'boolean'],
            'enforce_mode'       => ['nullable', 'in:anonymize,delete'],
            'require_consent'    => ['nullable', 'boolean'],
            'privacy_policy_url' => ['nullable', 'url', 'max:300'],
            'data_contact_email' => ['nullable', 'email', 'max:180'],
            'notes'              => ['nullable', 'string', 'max:2000'],
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

        $row = DB::table('agencies')->where('id', $agencyId)->select('settings')->first();
        abort_unless($row, 404, 'Agency not found');
        $settings = ($row->settings) ? (json_decode($row->settings, true) ?: []) : [];
        $settings['compliance'] = $current;

        DB::table('agencies')->where('id', $agencyId)->update([
            'settings'   => json_encode($settings),
            'updated_at' => now(),
        ]);

        // Audit the change (payload JSON — audit_logs.payload has a json_valid CHECK).
        try {
            DB::table('audit_logs')->insert([
                'user_id'     => $request->user()->id ?? null,
                'action'      => 'compliance_settings_updated',
                'entity_type' => 'agency',
                'entity_id'   => $agencyId,
                'payload'     => json_encode(['fields' => array_keys($data)]),
                'ip_address'  => $request->ip(),
                'user_agent'  => substr((string) $request->userAgent(), 0, 500),
                'created_at'  => now(),
            ]);
        } catch (Throwable $e) {
            // never fail the save because of audit
        }

        return response()->json(['status' => 'saved', 'compliance' => $current]);
    }
}
