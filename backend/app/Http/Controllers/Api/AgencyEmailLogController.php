<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * AN AGENCY'S OWN EMAIL LOG + EMAIL ERRORS (2026-11-03, Anthony: "email log in audit log and email errors are not
 * present for each agency - can you make sure they can see their own info through these subtabs").
 *
 * The Audit log's 📧 Email log and ⚠️ Email errors tabs read /platform/email-logs, which only platform admins can
 * call. These are the same lists for an agency admin, PINNED to the active agency: the agency comes from the
 * X-Active-Agency-Id header, the caller must be an active agency_admin of that agency (or a platform admin), and
 * the "whose" scope is forced to that agency - no 'all', no platform-level mail, no NULL-agency rows. Fails closed.
 * Listing / filtering / search is PlatformController's, so both views can never drift apart.
 */
class AgencyEmailLogController extends Controller
{
    /** The active agency the caller administers, or a 403. */
    private function agency(Request $request): int
    {
        $aid = (string) $request->header('X-Active-Agency-Id');
        abort_unless(ctype_digit($aid) && (int) $aid > 0, 403, 'Choose an agency first.');
        $uid = (int) optional($request->user())->id;
        $ok = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->where(fn ($w) => $w->where('role', 'platform_admin')
                ->orWhere(fn ($x) => $x->where('role', 'agency_admin')->where('agency_id', (int) $aid)))
            ->exists();
        abort_unless($ok, 403, 'Only an agency admin can see the agency\'s email log.');

        return (int) $aid;
    }

    /** One email of THIS agency's, or a 404 (another agency's mail and platform mail look like "not found"). */
    private function own(Request $request, int $id): int
    {
        $aid = $this->agency($request);
        abort_unless(Schema::hasColumn('email_logs', 'agency_id')
            && DB::table('email_logs')->where('id', $id)->where('agency_id', $aid)->exists(), 404, 'Not found');

        return $aid;
    }

    /** GET /admin/email-logs - the agency's mail (status=problems = the Email errors tab). */
    public function index(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        abort_unless(Schema::hasColumn('email_logs', 'agency_id'), 404, 'Not available.');
        $request->query->set('scope', '');                       // never 'all' or 'platform'
        $request->headers->set('X-Active-Agency-Id', (string) $aid);

        return app(PlatformController::class)->emailLogs($request);
    }

    /** GET /admin/email-logs/{id}/preview */
    public function preview(Request $request, int $id): JsonResponse
    {
        $this->own($request, $id);

        return app(PlatformController::class)->emailPreview($request, $id);
    }

    /** GET /admin/email-logs/{id}/attachments/{aid} */
    public function attachment(Request $request, int $id, int $aid)
    {
        $this->own($request, $id);

        return app(PlatformController::class)->emailAttachment($request, $id, $aid);
    }

    /**
     * POST /admin/email-logs/{id}/resend - to the ORIGINAL recipient only, through the normal delivery rules.
     * Unlike the platform resend this never sets X-KT-Bypass-Suppression: someone who opted out, or a demo
     * address, stays protected when an agency re-sends.
     */
    public function resend(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->own($request, $id);
        $row = DB::table('email_logs')->where('id', $id)->first();
        if (empty($row->body_html)) return response()->json(['message' => 'No stored content to resend for this message.'], 422);
        $to = trim((string) $row->to_email);
        if ($to === '') return response()->json(['message' => 'No recipient email on file.'], 422);

        try {
            Mail::html($row->body_html, function ($m) use ($to, $row) {
                $m->to($to, $row->to_name ?: null)->subject((string) ($row->subject ?: 'Message from KiddieTrac'));
                if (! empty($row->from_email)) { try { $m->from($row->from_email); } catch (\Throwable $e) {} }
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => 'The email could not be resent. Please try again in a moment.'], 422);
        }
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => 'email.resent',
                'entity_type' => 'email_log', 'entity_id' => $id,
                'payload' => json_encode(['summary' => 'Resent "' . mb_substr((string) $row->subject, 0, 120) . '" to ' . $to]),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true, 'message' => 'Resent to ' . $to]);
    }
}
