<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p72 — Per-agency email settings (admin).
 * Reads/writes the email_from_name / email_from_address / email_smtp_encryption
 * columns already present on the agencies table.
 */
final class EmailSettingsController extends Controller
{
    private function resolveAgencyId(Request $request): int
    {
        $header = $request->header('X-Active-Agency-Id');
        if ($header) {
            return (int) $header;
        }
        $u = $request->user();
        return (int) DB::table('role_assignments')
            ->where('user_id', $u->id)
            ->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])
            ->value('agency_id');
    }

    private function assertAdmin(Request $request): void
    {
        $u = $request->user();
        $ok = DB::table('role_assignments')
            ->where('user_id', $u->id)
            ->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin'])
            ->exists();
        abort_unless($ok, 403, 'Admin only');
    }

    /** GET /admin/email-settings */
    public function show(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('agencies')->where('id', $agencyId)
            ->select('id', 'name', 'email_from_name', 'email_from_address', 'email_smtp_encryption')
            ->first();
        abort_unless($row, 404, 'Agency not found');

        return response()->json([
            'agency_id'             => $row->id,
            'agency_name'           => $row->name,
            'email_from_name'       => $row->email_from_name,
            'email_from_address'    => $row->email_from_address,
            'email_smtp_encryption' => $row->email_smtp_encryption ?: 'tls',
            // Server default fallback so the admin knows what's used if blank
            'default_from'          => config('mail.from.address'),
        ]);
    }

    /** PATCH /admin/email-settings */
    public function update(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);

        $data = $request->validate([
            'email_from_name'       => ['nullable', 'string', 'max:120'],
            'email_from_address'    => ['nullable', 'email', 'max:190'],
            'email_smtp_encryption' => ['nullable', 'in:tls,ssl,none'],
        ]);

        DB::table('agencies')->where('id', $agencyId)->update([
            'email_from_name'       => $data['email_from_name'] ?? null,
            'email_from_address'    => $data['email_from_address'] ?? null,
            'email_smtp_encryption' => $data['email_smtp_encryption'] ?? 'tls',
            'updated_at'            => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /** POST /admin/email-settings/test — send a test email to the current admin */
    public function sendTest(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);
        $u = $request->user();
        $agency = DB::table('agencies')->where('id', $agencyId)->first();

        $to = $u->email;
        if (!$to) {
            return response()->json(['ok' => false, 'message' => 'Your account has no email address'], 422);
        }

        try {
            $fromAddr = $agency->email_from_address ?: config('mail.from.address');
            $fromName = $agency->email_from_name ?: ($agency->name ?? config('mail.from.name'));
            \Illuminate\Support\Facades\Mail::raw(
                "This is a test email from KiddieTrac for {$agency->name}.\n\n"
                . "If you received this, your agency email settings are working.\n\n"
                . "From name: {$fromName}\nFrom address: {$fromAddr}",
                function ($m) use ($to, $fromAddr, $fromName) {
                    $m->to($to)->subject('KiddieTrac email test')->from($fromAddr, $fromName);
                }
            );
            return response()->json(['ok' => true, 'sent_to' => $to]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }
    }
}
