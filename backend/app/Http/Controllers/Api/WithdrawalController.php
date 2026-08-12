<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Concerns\ResolvesCentreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class WithdrawalController extends Controller
{
    use ResolvesCentreContext;

    // ─── Parent: submit a withdrawal request ────────────────────────────
    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'last_day' => ['required', 'date'],
            'reason'   => ['nullable', 'string', 'max:1000'],
        ]);
        $user = $request->user();
        $child = DB::table('children')->where('id', $data['child_id'])->first();
        if (! $child) return response()->json(['message' => 'Child not found'], 404);

        $owns = DB::table('guardians')->where('user_id', $user->id)->where('family_id', $child->family_id)->exists();
        if (! $owns) abort(403);

        $open = DB::table('withdrawal_requests')->where('child_id', $data['child_id'])
            ->whereIn('status', ['pending', 'approved'])->whereNull('applied_at')->exists();
        if ($open) return response()->json(['message' => 'There is already an open withdrawal request for this child.'], 422);

        $id = DB::table('withdrawal_requests')->insertGetId([
            'child_id'        => (int) $child->id,
            'family_id'       => (int) $child->family_id,
            'requested_by_id' => (int) $user->id,
            'reason'          => $data['reason'] ?? null,
            'last_day'        => $data['last_day'],
            'status'          => 'pending',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        try { $this->notifySubmission((int) $id, $child, $user); }
        catch (\Throwable $e) { Log::error('Withdrawal submit notify: ' . $e->getMessage()); }

        return response()->json(['id' => $id, 'message' => 'Your withdrawal request has been submitted to the centre.'], 201);
    }

    // ─── Parent: list own requests ──────────────────────────────────────
    public function mine(Request $request): JsonResponse
    {
        $famIds = DB::table('guardians')->where('user_id', $request->user()->id)->pluck('family_id')->all();
        $rows = DB::table('withdrawal_requests as w')
            ->join('children as c', 'c.id', '=', 'w.child_id')
            ->whereIn('w.family_id', $famIds ?: [0])
            ->orderByDesc('w.created_at')
            ->select('w.*', 'c.first_name', 'c.preferred_name', 'c.last_name')
            ->get()->map(fn ($r) => [
                'id' => $r->id,
                'child_id' => $r->child_id,
                'child_name' => trim(($r->preferred_name ?: $r->first_name) . ' ' . ($r->last_name ?? '')),
                'last_day' => $r->last_day,
                'reason' => $r->reason,
                'status' => $r->status,
                'admin_note' => $r->admin_note,
                'effective_date' => $r->effective_date,
                'decided_at' => $r->decided_at,
                'applied_at' => $r->applied_at,
                'created_at' => $r->created_at,
            ]);
        return response()->json(['data' => $rows]);
    }

    // ─── Admin/director: pending + recent requests in their agency ──────
    public function adminIndex(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $rows = DB::table('withdrawal_requests as w')
            ->join('children as c', 'c.id', '=', 'w.child_id')
            ->join('families as f', 'f.id', '=', 'w.family_id')
            ->leftJoin('users as u', 'u.id', '=', 'w.requested_by_id')
            ->leftJoin('users as d', 'd.id', '=', 'w.decided_by_id')
            ->whereIn('f.centre_id', $centreIds ?: [0])
            ->orderByRaw("FIELD(w.status,'pending','approved','denied')")
            ->orderByDesc('w.created_at')
            ->select('w.*', 'c.first_name', 'c.preferred_name', 'c.last_name', 'f.family_name',
                'u.first_name as req_first', 'u.last_name as req_last', 'd.first_name as dec_first', 'd.last_name as dec_last')
            ->limit(200)->get()->map(fn ($r) => [
                'id' => $r->id,
                'child_id' => $r->child_id,
                'child_name' => trim(($r->preferred_name ?: $r->first_name) . ' ' . ($r->last_name ?? '')),
                'family_name' => $r->family_name,
                'requested_by' => trim(($r->req_first ?? '') . ' ' . ($r->req_last ?? '')) ?: '—',
                'last_day' => $r->last_day,
                'reason' => $r->reason,
                'status' => $r->status,
                'admin_note' => $r->admin_note,
                'effective_date' => $r->effective_date,
                'decided_by' => trim(($r->dec_first ?? '') . ' ' . ($r->dec_last ?? '')) ?: null,
                'decided_at' => $r->decided_at,
                'applied_at' => $r->applied_at,
                'created_at' => $r->created_at,
            ]);
        return response()->json(['data' => $rows]);
    }

    // ─── Admin/director: approve or deny ────────────────────────────────
    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status'         => ['required', 'in:approved,denied'],
            'note'           => ['nullable', 'string', 'max:1000'],
            'effective_date' => ['nullable', 'date'],
        ]);
        $w = DB::table('withdrawal_requests')->where('id', $id)->first();
        if (! $w) return response()->json(['message' => 'Not found'], 404);
        if ($w->status !== 'pending') return response()->json(['message' => 'This request has already been decided.'], 422);

        $child = DB::table('children')->where('id', $w->child_id)->first();
        $family = $child ? DB::table('families')->where('id', $child->family_id)->first() : null;
        $centreAgency = $family ? (int) DB::table('centres')->where('id', $family->centre_id)->value('agency_id') : 0;
        $allowed = $family && $this->authorizeCentreAccess($request->user(), (int) $family->centre_id);
        if (! $allowed) {
            $allowed = $centreAgency > 0 && $centreAgency === (int) $this->resolveAgencyId($request);
        }
        if (! $allowed) abort(403);

        $effective = $data['effective_date'] ?? $w->last_day;
        DB::table('withdrawal_requests')->where('id', $id)->update([
            'status'         => $data['status'],
            'admin_note'     => $data['note'] ?? null,
            'effective_date' => $data['status'] === 'approved' ? $effective : null,
            'decided_by_id'  => (int) $request->user()->id,
            'decided_at'     => now(),
            'updated_at'     => now(),
        ]);

        try { $this->notifyDecision((int) $id, $child, $data['status'], $effective); }
        catch (\Throwable $e) { Log::error('Withdrawal decision notify: ' . $e->getMessage()); }

        if ($data['status'] === 'approved' && Carbon::parse($effective)->startOfDay()->lte(now()->startOfDay())) {
            try { $this->applyWithdrawal((int) $id); } catch (\Throwable $e) { Log::error('Withdrawal apply: ' . $e->getMessage()); }
        }

        return response()->json(['message' => $data['status'] === 'approved' ? 'Withdrawal approved.' : 'Withdrawal declined.']);
    }

    /**
     * Apply an approved withdrawal: end enrolments, mark the child withdrawn,
     * deactivate the guardian(s) if no enrolled children remain, and notify the
     * room's educators (in-app + push + email). Idempotent via applied_at.
     * Called immediately on approval when due, or by the daily cron for
     * future-dated withdrawals.
     */
    public function applyWithdrawal(int $id): void
    {
        $w = DB::table('withdrawal_requests')->where('id', $id)->first();
        if (! $w || $w->status !== 'approved' || $w->applied_at) return;
        $child = DB::table('children')->where('id', $w->child_id)->first();
        if (! $child) return;
        $family = DB::table('families')->where('id', $child->family_id)->first();
        $centreId = $family ? (int) $family->centre_id : 0;
        $agencyId = $centreId ? (int) DB::table('centres')->where('id', $centreId)->value('agency_id') : 0;
        $effective = $w->effective_date ?: $w->last_day;
        $childName = ($child->preferred_name ?: $child->first_name);

        $roomIds = DB::table('enrollments')->where('child_id', $child->id)->whereNull('end_date')
            ->pluck('room_id')->filter()->unique()->values()->all();

        // End enrolments (child drops off the educator roster) + mark withdrawn.
        DB::table('enrollments')->where('child_id', $child->id)->whereNull('end_date')->update(['end_date' => $effective]);
        DB::table('children')->where('id', $child->id)->update(['enrollment_status' => 'withdrawn', 'updated_at' => now()]);

        // No enrolled children left in the family → deactivate the guardian users.
        $remaining = DB::table('children')->where('family_id', $child->family_id)
            ->where('enrollment_status', 'enrolled')->whereNull('deleted_at')->count();
        if ($remaining === 0) {
            $guardianIds = DB::table('guardians')->where('family_id', $child->family_id)->pluck('user_id')->all();
            if ($guardianIds) DB::table('users')->whereIn('id', $guardianIds)->update(['status' => 'inactive', 'updated_at' => now()]);
        }

        DB::table('withdrawal_requests')->where('id', $id)->update(['applied_at' => now(), 'updated_at' => now()]);

        // Notify the room's educators (fallback: centre educators) — app + push + email.
        $eduIds = ! empty($roomIds)
            ? DB::table('educator_rooms')->whereIn('room_id', $roomIds)->pluck('user_id')->unique()->values()->all()
            : [];
        if (empty($eduIds) && $centreId) {
            $eduIds = DB::table('role_assignments')->where('role', 'educator')->where('active', true)
                ->where('centre_id', $centreId)->pluck('user_id')->unique()->values()->all();
        }
        $effFmt = Carbon::parse($effective)->format('M j, Y');
        foreach ($eduIds as $uid) {
            $this->notify((int) $uid, '👋 Child withdrawn',
                $childName . ' has been withdrawn (effective ' . $effFmt . '). They have been removed from your roster and records.', '#today');
            $u = DB::table('users')->where('id', $uid)->first();
            if ($u && ! empty($u->email)) {
                $this->mailUser($agencyId, $u->email, trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                    'A child has been withdrawn',
                    '<p>Hello ' . e($u->first_name ?: 'there') . ',</p>'
                    . '<p><strong>' . e($childName) . '</strong> has been withdrawn from care, effective <strong>' . e($effFmt) . '</strong>.</p>'
                    . '<p>They have been removed from your roster and you no longer have access to their record. No action is needed from you.</p>');
            }
        }

        // Confirm to the parent.
        $reqUser = DB::table('users')->where('id', $w->requested_by_id)->first();
        if ($reqUser) {
            $this->notify((int) $reqUser->id, '✅ Withdrawal complete', $childName . '\'s withdrawal is now effective. Thank you.', '#billing');
            if (! empty($reqUser->email)) {
                $org = $this->orgName($agencyId);
                $this->mailUser($agencyId, $reqUser->email, trim(($reqUser->first_name ?? '') . ' ' . ($reqUser->last_name ?? '')),
                    'A fond farewell from ' . $org,
                    '<p>Dear ' . e($reqUser->first_name ?: 'there') . ',</p>'
                    . '<p>Today marks ' . e($childName) . '&rsquo;s last day with us, and we wanted to send one final, heartfelt goodbye.</p>'
                    . '<p>Thank you for being such a wonderful part of our community. It has been a true gift to care for ' . e($childName) . ' &mdash; we will remember their bright spirit fondly. Please give them the biggest hug from all of us.</p>'
                    . '<p>Wishing ' . e($childName) . ' and your whole family nothing but happiness in everything that lies ahead. Our door is always open to you. &#128155;</p>'
                    . '<p>With gratitude,<br>The team at ' . e($org) . '</p>');
            }
        }
    }

    // ─── notification helpers ───────────────────────────────────────────

    private function notifySubmission(int $id, $child, $requester): void
    {
        $family = DB::table('families')->where('id', $child->family_id)->first();
        $centreId = $family ? (int) $family->centre_id : 0;
        $agencyId = $centreId ? (int) DB::table('centres')->where('id', $centreId)->value('agency_id') : 0;
        $childName = ($child->preferred_name ?: $child->first_name);
        $requesterName = trim(($requester->first_name ?? '') . ' ' . ($requester->last_name ?? '')) ?: 'A parent';
        $w = DB::table('withdrawal_requests')->where('id', $id)->first();
        $lastFmt = $w && $w->last_day ? Carbon::parse($w->last_day)->format('M j, Y') : '';

        foreach ($this->staffRecipients($agencyId, $centreId) as $uid) {
            $this->notify((int) $uid, '📤 Withdrawal request',
                $requesterName . ' has requested to withdraw ' . $childName . ' (last day ' . $lastFmt . '). Tap to review.', '#withdrawals');
            $u = DB::table('users')->where('id', $uid)->first();
            if ($u && ! empty($u->email)) {
                $this->mailUser($agencyId, $u->email, trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                    'Withdrawal request — ' . $childName,
                    '<p>Hello ' . e($u->first_name ?: 'there') . ',</p>'
                    . '<p><strong>' . e($requesterName) . '</strong> has submitted a request to withdraw <strong>' . e($childName) . '</strong> from care.</p>'
                    . '<p><strong>Requested last day:</strong> ' . e($lastFmt) . '</p>'
                    . ($w && $w->reason ? '<p><strong>Reason:</strong> ' . e($w->reason) . '</p>' : '')
                    . '<p>Please review and approve or decline this request in KiddieTrac.</p>');
            }
        }

        // Copy to the parent.
        $this->notify((int) $requester->id, '📤 Withdrawal request sent',
            'We\'ve received your request to withdraw ' . $childName . ' (last day ' . $lastFmt . '). The centre will review it.', '#billing');
        if (! empty($requester->email)) {
            $this->mailUser($agencyId, $requester->email, $requesterName,
                'We received your withdrawal request',
                '<p>Hello ' . e($requester->first_name ?: 'there') . ',</p>'
                . '<p>This confirms we\'ve received your request to withdraw <strong>' . e($childName) . '</strong> from care, with a requested last day of <strong>' . e($lastFmt) . '</strong>.</p>'
                . '<p>The centre will review your request and let you know once it\'s approved.</p>');
        }
    }

    private function notifyDecision(int $id, $child, string $status, $effective): void
    {
        $w = DB::table('withdrawal_requests')->where('id', $id)->first();
        if (! $w) return;
        $family = $child ? DB::table('families')->where('id', $child->family_id)->first() : null;
        $centreId = $family ? (int) $family->centre_id : 0;
        $agencyId = $centreId ? (int) DB::table('centres')->where('id', $centreId)->value('agency_id') : 0;
        $childName = $child ? ($child->preferred_name ?: $child->first_name) : 'your child';
        $reqUser = DB::table('users')->where('id', $w->requested_by_id)->first();
        if (! $reqUser) return;
        $effFmt = Carbon::parse($effective)->format('M j, Y');

        if ($status === 'approved') {
            $this->notify((int) $reqUser->id, '✅ Withdrawal approved',
                $childName . '\'s withdrawal has been approved, effective ' . $effFmt . '.', '#billing');
            if (! empty($reqUser->email)) {
                $balance = $child ? $this->familyBalance((int) $child->family_id) : 0.0;
                $org = $this->orgName($agencyId);
                $billLine = $balance > 0.005
                    ? '<li><strong>Any final balance:</strong> Your account currently shows an outstanding balance of <strong>$' . number_format($balance, 2) . '</strong>. Please settle it in the app under <em>Billing</em> before ' . e($childName) . '&rsquo;s last day.</li>'
                    : '<li><strong>Billing:</strong> Your account is fully settled &mdash; thank you!</li>';
                $this->mailUser($agencyId, $reqUser->email, trim(($reqUser->first_name ?? '') . ' ' . ($reqUser->last_name ?? '')),
                    'Your withdrawal is approved &mdash; with heartfelt thanks',
                    '<p>Dear ' . e($reqUser->first_name ?: 'there') . ',</p>'
                    . '<p>We have approved your request to withdraw <strong>' . e($childName) . '</strong> from our care, with a final day of <strong>' . e($effFmt) . '</strong>.</p>'
                    . '<p>It has been our absolute privilege to be part of ' . e($childName) . '&rsquo;s early years. Watching them learn, laugh and grow has been a genuine joy, and they will be dearly missed by their educators and friends.</p>'
                    . '<p><strong>A few things to take care of before the last day, while you still have app access:</strong></p>'
                    . '<ul style="line-height:1.7;margin:0 0 6px;padding-left:20px;">'
                    . $billLine
                    . '<li><strong>Save your memories:</strong> Open <em>Photos</em> in the KiddieTrac app and download any pictures of ' . e($childName) . ' you would like to keep &mdash; access ends after the final day.</li>'
                    . '</ul>'
                    . ($w->admin_note ? '<p><strong>A note from us:</strong> ' . e($w->admin_note) . '</p>' : '')
                    . '<p>Thank you for trusting us with ' . e($childName) . '. We are so grateful to have shared this chapter of their story with your family, and we wish ' . e($childName) . ' &mdash; and all of you &mdash; every happiness in the adventures ahead. Once part of our community, always part of our community.</p>'
                    . '<p>With warm wishes and our deepest thanks,<br>The team at ' . e($org) . '</p>');
            }
        } else {
            $this->notify((int) $reqUser->id, 'Withdrawal request declined',
                'Your request to withdraw ' . $childName . ' was not approved. Please contact the centre.', '#billing');
            if (! empty($reqUser->email)) {
                $this->mailUser($agencyId, $reqUser->email, trim(($reqUser->first_name ?? '') . ' ' . ($reqUser->last_name ?? '')),
                    'About your withdrawal request',
                    '<p>Hello ' . e($reqUser->first_name ?: 'there') . ',</p>'
                    . '<p>Your request to withdraw <strong>' . e($childName) . '</strong> was not approved at this time.</p>'
                    . ($w->admin_note ? '<p><strong>A note from the centre:</strong> ' . e($w->admin_note) . '</p>' : '')
                    . '<p>Please contact the centre if you have any questions.</p>');
            }
        }
    }

    private function staffRecipients(int $agencyId, int $centreId): array
    {
        return DB::table('role_assignments')->where('active', true)
            ->where(function ($q) use ($agencyId, $centreId) {
                $q->where(function ($q2) use ($centreId) { $q2->where('role', 'centre_director')->where('centre_id', $centreId); })
                  ->orWhere(function ($q3) use ($agencyId) { $q3->where('role', 'agency_admin')->where('agency_id', $agencyId); });
            })
            ->pluck('user_id')->unique()->values()->all();
    }

    private function familyBalance(int $familyId): float
    {
        $inv = (float) DB::table('invoices')->where('family_id', $familyId)->sum('total');
        $paid = (float) DB::table('payments')->where('family_id', $familyId)->whereNotNull('paid_at')->sum('amount');
        return round($inv - $paid, 2);
    }

    private function orgName(int $agencyId): string
    {
        return (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'your childcare team');
    }

    private function notify(int $userId, string $title, string $body, string $link): void
    {
        try {
            DB::table('notifications')->insert([
                'user_id' => $userId, 'type' => 'withdrawal', 'title' => $title, 'body' => $body,
                'data' => json_encode(['link' => $link]), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {}
        try { app(\App\Services\FcmService::class)->sendToUser($userId, $title, $body, $link); } catch (\Throwable $e) {}
    }

    private function mailUser(int $agencyId, string $email, ?string $name, string $subject, string $bodyHtml): void
    {
        try {
            $html = \App\Services\EmailTemplate::wrap($agencyId, $bodyHtml, []);
            $mailer = \App\Services\AgencyMailer::forAgency($agencyId);
            $fromA = $mailer->fromAddress();
            $fromN = $mailer->fromName();
            $mailer->mailer()->html($html, function ($m) use ($email, $name, $fromA, $fromN, $subject) {
                $m->to($email, $name)->from($fromA, $fromN)->subject($subject);
            });
        } catch (\Throwable $e) { Log::warning('Withdrawal mail failed: ' . $e->getMessage()); }
    }
}
