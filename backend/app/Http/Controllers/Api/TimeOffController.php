<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p51 — Time-off request workflow.
 * Staff submit; centre director / agency_admin approve / decline.
 * Approval auto-creates a "blocked" calendar marker so shifts can't be
 * scheduled over approved time off (handled in SchedulingController).
 */
final class TimeOffController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        $rows = DB::table('time_off_requests')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('start_at')
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function listAgency(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $status = $request->query('status');
        $q = DB::table('time_off_requests as tor')
            ->join('users as u', 'u.id', '=', 'tor.user_id')
            ->where('tor.agency_id', $agencyId)
            ->orderByDesc('tor.start_at')
            ->select(
                'tor.*', DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"), 'u.email as user_email',
                // decided_by_id and decided_at have always been written and never read
                // back — the id was returned raw by tor.* and no screen could turn it
                // into a person. The decision is half the record: who allowed it matters
                // as much as that it was allowed.
                DB::raw("TRIM(CONCAT(COALESCE(d.first_name,''), ' ', COALESCE(d.last_name,''))) as decided_by_name")
            )
            ->leftJoin('users as d', 'd.id', '=', 'tor.decided_by_id');
        if ($status) $q->where('tor.status', $status);
        return response()->json(['data' => $q->get()]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'request_type'  => 'required|string|in:vacation,sick,personal,bereavement,jury,other',
            'start_at'      => 'required|date',
            'end_at'        => 'required|date|after_or_equal:start_at',
            'reason'        => 'nullable|string|max:2000',
            'centre_id'     => 'nullable|integer',
        ]);
        $user = $request->user();
        $agencyId = $this->resolveAgencyId($request);

        $id = DB::table('time_off_requests')->insertGetId([
            'agency_id'    => $agencyId,
            'user_id'      => $user->id,
            'centre_id'    => $data['centre_id'] ?? null,
            'request_type' => $data['request_type'],
            'start_at'     => $data['start_at'],
            'end_at'       => $data['end_at'],
            'reason'       => $data['reason'] ?? null,
            'status'       => 'pending',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Centre directors and agency admins are the people who can act on this, so
        // they get both a bell and an email. A bell alone waits for them to open the
        // app, and the request waits with it.
        $approvers = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.agency_id', $agencyId)
            ->whereIn('ra.role', ['centre_director', 'agency_admin'])
            ->where('ra.active', true)
            ->whereNull('u.deleted_at')
            ->select('u.id', 'u.email', 'u.first_name', 'u.last_name')
            ->distinct()->get();

        // $user->name is not a thing on this model — no `name` column and no accessor for
        // it, only getFullNameAttribute. It used to be interpolated here and produced
        // "New time-off request from " with nothing after it.
        $who = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'A team member';
        $when = Carbon::parse($data['start_at'])->format('M j') . ' – '
              . Carbon::parse($data['end_at'])->format('M j');

        foreach ($approvers as $ap) {
            DB::table('notifications')->insert([
                'user_id'    => $ap->id,
                'type'       => 'time_off',
                'title'      => 'New time-off request from ' . $who,
                'body'       => $data['request_type'] . ' · ' . $when,
                'data' => json_encode(['link' => '#time-off']),
                'created_at' => now(),
            ]);

            $this->mailApprover($agencyId, $ap, $who, (string) $data['request_type'], $when, $data['reason'] ?? null);
        }

        return response()->json(['id' => $id, 'status' => 'pending'], 201);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:approved,denied',
            'decision_notes' => 'nullable|string|max:1000',
        ]);
        $row = DB::table('time_off_requests')->where('id', $id)->first();
        abort_unless($row, 404);
        $this->assertAgencyAccess($request, (int) $row->agency_id);

        DB::table('time_off_requests')->where('id', $id)->update([
            'status'        => $data['status'],
            'decided_by_id' => $request->user()->id,
            'decided_at'    => now(),
            'decision_notes'=> $data['decision_notes'] ?? null,
            'updated_at'    => now(),
        ]);

        $when = Carbon::parse($row->start_at)->format('M j') . ' – '
              . Carbon::parse($row->end_at)->format('M j');

        DB::table('notifications')->insert([
            'user_id'    => $row->user_id,
            'type'       => 'time_off',
            'title'      => 'Your time-off request was ' . $data['status'],
            'body'       => $when . (! empty($data['decision_notes']) ? ' · ' . $data['decision_notes'] : ''),
            'data' => json_encode(['link' => '#time-off']),
            'created_at' => now(),
        ]);

        // Emailed as well as belled. Somebody who asked for leave is waiting on this to
        // book something, and "check the app occasionally" is not an answer.
        $this->mailDecision($row, (string) $data['status'], $when, $data['decision_notes'] ?? null, $request->user());

        return response()->json(['status' => $data['status']]);
    }

    /**
     * Tell an approver a request is waiting.
     *
     * Never lets a mail failure fail the request itself — the time-off row is already
     * saved and the bell is already written, and a bounced address for one director must
     * not hand the person requesting leave a 500.
     */
    private function mailApprover(?int $agencyId, object $approver, string $who, string $type, string $when, ?string $reason): void
    {
        if (! filter_var((string) $approver->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        if (\App\Support\Suppression::isUser((int) $approver->id)) {
            return;
        }

        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $reasonRow = $reason
            ? '<tr><td style="padding:10px 0 0;font-size:14px;line-height:1.6;color:#334155;">'
              . '<strong>Reason given:</strong> ' . $e($reason) . '</td></tr>'
            : '';

        $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            . '<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">'
            . $e($who) . ' has requested time off and is waiting on a decision.</td></tr>'
            . '<tr><td style="padding:6px 0;"><div style="background:#F1F5F9;border-radius:10px;padding:14px 16px;'
            . 'font-size:15px;color:#0F172A;"><strong>' . $e(ucfirst($type)) . '</strong><br>' . $e($when) . '</div></td></tr>'
            . $reasonRow
            . '<tr><td style="padding:16px 0 0;font-size:14px;line-height:1.6;color:#64748B;">'
            . 'Approve or decline it under <strong>Time off</strong> in KiddieTrac.</td></tr></table>';

        try {
            $html = EmailTemplate::wrap($agencyId, $body, [
                'eyebrow' => 'ACTION NEEDED',
                'title' => 'Time-off request from ' . $who,
                'subtitle' => ucfirst($type) . ' · ' . $when,
                'preheader' => $who . ' requested ' . $type . ' for ' . $when,
            ]);
            $name = trim(($approver->first_name ?? '') . ' ' . ($approver->last_name ?? ''));
            AgencyMailer::forAgency($agencyId)->mailer()->html($html, function ($m) use ($approver, $name, $who) {
                $m->to($approver->email, $name ?: null)->subject('Time-off request from ' . $who);
            });
        } catch (\Throwable $ex) {
            \Illuminate\Support\Facades\Log::warning('Time-off approver email failed', [
                'approver' => $approver->id, 'error' => $ex->getMessage(),
            ]);
        }
    }

    /** Tell the requester what was decided, and by whom. */
    private function mailDecision(object $row, string $status, string $when, ?string $notes, $decider): void
    {
        $u = DB::table('users')->where('id', $row->user_id)->first();
        if (! $u || ! filter_var((string) $u->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        if (\App\Support\Suppression::isUser((int) $u->id)) {
            return;
        }

        $approved = $status === 'approved';
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $by = trim((($decider->first_name ?? '') . ' ' . ($decider->last_name ?? ''))) ?: 'your centre';
        $accent = $approved ? '#16A34A' : '#B91C1C';
        $tint = $approved ? '#DCFCE7' : '#FEE2E2';

        // A declined request needs the reason more than an approved one does, but both
        // carry it when there is one — a decision with no explanation invites a second
        // request for the same dates.
        $notesRow = $notes
            ? '<tr><td style="padding:12px 0 0;font-size:14px;line-height:1.6;color:#334155;">'
              . '<strong>Note from ' . $e($by) . ':</strong> ' . $e($notes) . '</td></tr>'
            : '';

        $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            . '<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">'
            . 'Your time-off request has been ' . ($approved ? 'approved' : 'declined') . '.</td></tr>'
            . '<tr><td style="padding:6px 0;"><div style="background:' . $tint . ';border-radius:10px;padding:14px 16px;'
            . 'font-size:15px;color:#0F172A;"><strong style="color:' . $accent . ';text-transform:uppercase;'
            . 'font-size:12px;letter-spacing:.06em;">' . ($approved ? 'Approved' : 'Declined') . '</strong><br>'
            . '<span style="font-size:15px;">' . $e(ucfirst((string) $row->request_type)) . ' · ' . $e($when)
            . '</span></div></td></tr>'
            . $notesRow
            . '<tr><td style="padding:16px 0 0;font-size:14px;line-height:1.6;color:#64748B;">'
            . 'Decided by ' . $e($by) . ' on ' . now()->format('j M Y') . '.'
            . ($approved ? ' It now shows on the staff calendar.' : ' Speak to your centre if you need to discuss it.')
            . '</td></tr></table>';

        try {
            $html = EmailTemplate::wrap((int) $row->agency_id, $body, [
                'eyebrow' => $approved ? 'APPROVED' : 'DECLINED',
                'title' => 'Your time-off request was ' . ($approved ? 'approved' : 'declined'),
                'subtitle' => $when,
                'preheader' => 'Your time off for ' . $when . ' was ' . ($approved ? 'approved' : 'declined'),
            ]);
            $name = trim((($u->first_name ?? '') . ' ' . ($u->last_name ?? '')));
            AgencyMailer::forAgency((int) $row->agency_id)->mailer()->html($html, function ($m) use ($u, $name, $approved) {
                $m->to($u->email, $name ?: null)
                  ->subject('Your time-off request was ' . ($approved ? 'approved' : 'declined'));
            });
        } catch (\Throwable $ex) {
            \Illuminate\Support\Facades\Log::warning('Time-off decision email failed', [
                'user' => $u->id, 'error' => $ex->getMessage(),
            ]);
        }
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $row = DB::table('time_off_requests')->where('id', $id)->first();
        abort_unless($row, 404);
        abort_unless((int) $row->user_id === (int) $request->user()->id, 403, 'Only the requester can cancel');
        abort_unless($row->status === 'pending', 422, 'Cannot cancel a decided request');
        DB::table('time_off_requests')->where('id', $id)->update([
            'status' => 'cancelled', 'updated_at' => now(),
        ]);
        return response()->json(['status' => 'cancelled']);
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        // SECURITY (v22p94): only honour the header if the user is platform_admin
        // or holds an active role for that exact agency (else fall back below).
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->where(function ($w) use ($activeId) { $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin'); })->exists()) return $activeId;
        // SECURITY (v22p98): a platform_admin with no valid SELECTED agency must NOT
        // fall through to their first role's agency (iLearn) — require an explicit
        // choice, else agency-scoped data leaked to a super-admin on a header-less call.
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400, 'No agency context');
        return (int) $first;
    }

    private function assertAgencyAccess(Request $request, int $agencyId): void
    {
        $u = $request->user();
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) return;
        $hasRole = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('agency_id', $agencyId)->whereIn('role', ['agency_admin', 'centre_director'])
            ->where('active', true)->exists();
        abort_unless($hasRole, 403);
    }
}
