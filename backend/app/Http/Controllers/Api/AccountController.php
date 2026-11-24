<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * In-app account deletion, App Store Guideline 5.1.1(v).
 *
 * DELETING ARCHIVES, THE SAME WAY THE PORTAL DOES (2026-11-05, Anthony: "archive the users upon deletion like how
 * we built it in the portal and remove access right away and send emails out to admin/directors for the agency's
 * and if it is a kiddietrac employee you can send email to superadmin and archive as well based on retention
 * period set"). This only raised a request for the agency administrator to act on, and Apple asks for deletion
 * the person can complete themselves. Confirming now does what AdminController::destroyUser does for an admin:
 * every role closed (stamped with closed_with_account_at, so a restore reopens exactly those), every session and
 * device signed out, rooms released, the account marked deactivated + deleted_at. The records a centre must keep
 * (family, children, payroll, invoices) stay with the centre under its retention policy, exactly as for an account
 * an admin removed; a closed staff account is listed on the Archived screen with its retained-until date.
 *
 * Who is told: the admins and directors of every agency the person belonged to; for a KiddieTrac employee
 * (platform_admin / sales_rep) the KiddieTrac superadmins. An agency left with no admin or director to tell
 * falls back to the superadmins too, so a closure never goes unseen. The person gets a confirmation email.
 */
final class AccountController extends Controller
{
    /** The owner's account - never closable, by anyone (same list as AdminController). */
    private const PROTECTED_SUPER_ADMINS = ['mr.anthonyhosein@gmail.com'];

    /** KiddieTrac's own staff roles - their closure is reported to KiddieTrac, not to an agency. */
    private const PLATFORM_ROLES = ['platform_admin', 'sales_rep'];

    public function requestDeletion(Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $user = $request->user();
        $userId = (int) $user->id;

        if (in_array(strtolower(trim((string) $user->email)), self::PROTECTED_SUPER_ADMINS, true)) {
            return response()->json(['message' => 'This is the protected owner account and cannot be deleted from the app.'], 422);
        }

        // Read BEFORE closing: once the roles are closed there is nothing left to say where they were.
        $roles = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)->get(['role', 'agency_id', 'centre_id']);
        $agencyIds = $this->agenciesOf($userId, $roles);
        $isPlatform = $roles->contains(fn ($r) => in_array((string) $r->role, self::PLATFORM_ROLES, true));

        DB::transaction(function () use ($userId) {
            // Stamped closure first (closed_with_account_at), so a restore reopens exactly these roles.
            \App\Support\AccountStatus::closeRoles([$userId]);
            DB::table('role_assignments')->where('user_id', $userId)->update(['active' => false]);
            DB::table('educator_rooms')->where('user_id', $userId)->delete();
            \App\Support\RoleChange::endSessions($userId);
            DB::table('personal_access_tokens')->where('tokenable_id', $userId)->delete();
            // No more pushes to a phone whose owner has gone.
            DB::table('device_tokens')->where('user_id', $userId)->delete();
            DB::table('users')->where('id', $userId)->update([
                'status' => 'deactivated', 'deleted_at' => now(), 'updated_at' => now(),
            ]);
            // Any earlier request is answered by this; one completed row records the closure itself.
            DB::table('account_deletion_requests')->where('user_id', $userId)->where('status', 'requested')
                ->update(['status' => 'completed', 'handled_at' => now(), 'updated_at' => now()]);
        });

        $now = now();
        foreach ($agencyIds ?: [null] as $agencyId) {
            DB::table('account_deletion_requests')->insert([
                'user_id' => $userId, 'agency_id' => $agencyId, 'reason' => $data['reason'] ?? null,
                'status' => 'completed', 'handled_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        try {
            DB::table('audit_logs')->insert([
                'user_id' => $userId, 'agency_id' => $agencyIds[0] ?? null, 'action' => 'account.self_deleted',
                'entity_type' => 'user', 'entity_id' => $userId,
                'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'payload' => json_encode(['agencies' => $agencyIds, 'platform' => $isPlatform, 'reason' => $data['reason'] ?? null]),
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Account self-deletion audit row: ' . $e->getMessage());
        }

        // Told after the closure, never instead of it: a mail failure must not leave the account open.
        $told = [];
        foreach ($agencyIds as $agencyId) {
            try { $told = array_merge($told, $this->notifyAdmins($user, $agencyId, $data['reason'] ?? null)); }
            catch (\Throwable $e) { Log::error('Account deletion notify: ' . $e->getMessage()); }
        }
        if ($isPlatform || ! $agencyIds || ! $told) {
            try { $this->notifyAdmins($user, null, $data['reason'] ?? null, true); }
            catch (\Throwable $e) { Log::error('Account deletion notify (platform): ' . $e->getMessage()); }
        }
        try { $this->confirmToPerson($user, $agencyIds[0] ?? null); }
        catch (\Throwable $e) { Log::warning('Account deletion confirmation: ' . $e->getMessage()); }

        return response()->json([
            'deleted' => true,
            'message' => 'Your account has been deleted and you have been signed out everywhere. Records your centre must keep by law are archived and removed when their retention period ends.',
        ], 200);
    }

    /** Every agency the person holds (or held, via a family) a place in. */
    private function agenciesOf(int $userId, $roles): array
    {
        $ids = [];
        foreach ($roles as $r) {
            if ($r->agency_id) { $ids[] = (int) $r->agency_id; continue; }
            if ($r->centre_id) {
                $a = DB::table('centres')->where('id', $r->centre_id)->value('agency_id');
                if ($a) $ids[] = (int) $a;
            }
        }
        $fromFamilies = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')->where('g.user_id', $userId)
            ->whereNull('f.deleted_at')->pluck('c.agency_id')->map(fn ($v) => (int) $v)->all();
        return array_values(array_unique(array_filter(array_merge($ids, $fromFamilies))));
    }

    /**
     * Tell the agency's admins and directors (or, with $platform, KiddieTrac's superadmins) that this person
     * deleted their account. Returns the user ids told. Kept under this name: EmailPreviews and the email
     * override map (AccountController@notifyAdmins) call it.
     */
    private function notifyAdmins($user, ?int $agencyId, ?string $reason, bool $platform = false): array
    {
        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ('User #' . $user->id);
        if ($platform) {
            $adminIds = DB::table('role_assignments')->where('active', true)->where('role', 'platform_admin')
                ->pluck('user_id')->unique()->values()->all();
        } elseif ($agencyId) {
            $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
            $adminIds = DB::table('role_assignments')->where('active', true)
                ->whereIn('role', ['agency_admin', 'centre_director'])
                ->where(function ($q) use ($agencyId, $centreIds) {
                    $q->where('agency_id', $agencyId)->orWhereIn('centre_id', $centreIds ?: [0]);
                })->pluck('user_id')->unique()->values()->all();
        } else {
            return [];
        }
        $adminIds = array_values(array_diff(array_map('intval', $adminIds), [(int) $user->id]));

        $agencyName = $agencyId ? (string) DB::table('agencies')->where('id', $agencyId)->value('name') : 'KiddieTrac';
        $tz = \App\Support\AgencyTime::tz($agencyId ?: null);
        $when = now()->setTimezone($tz);
        $title = '🗑️ Account deleted';
        $line = $name . ' deleted their KiddieTrac account. It is archived and signed out everywhere; the records you keep stay under your retention policy.';

        foreach ($adminIds as $uid) {
            try {
                \App\Support\Notify::write([
                    'user_id' => (int) $uid, 'type' => 'account_deletion',
                    'title'   => $title, 'body' => $line,
                    'data'    => json_encode(['link' => '#admin']), 'created_at' => now(),
                ]);
            } catch (\Throwable $e) {}
            $u = DB::table('users')->where('id', $uid)->whereNull('deleted_at')->first();
            if (! $u || empty($u->email)) continue;
            try {
                $p = 'margin:0 0 12px;font-size:14.5px;color:#1E293B;line-height:1.6;';
                $html = \App\Services\EmailTemplate::wrap($agencyId,
                    '<p style="' . $p . '">Hello ' . e($u->first_name ?: 'there') . ',</p>'
                    . '<p style="' . $p . '"><strong>' . e($name) . '</strong>' . ($user->email ? ' (' . e($user->email) . ')' : '')
                    . ' deleted their KiddieTrac account' . ($platform ? '' : ' with <strong>' . e($agencyName) . '</strong>') . ' from the app on '
                    . e($when->format('D, M j, Y')) . ' at ' . e($when->format('g:i A T')) . '.</p>'
                    . '<p style="' . $p . '">Their access ended at once: they are signed out of every device and cannot sign in again. '
                    . 'The account is archived, the same as one removed from the <em>Users</em> screen. The records your centre keeps (family and children\'s files, staff and payroll records, invoices) stay as they are and are kept for your retention period. Nothing else needs doing.</p>'
                    . ($reason ? '<p style="' . $p . '"><strong>Reason given:</strong> ' . e($reason) . '</p>' : '')
                    . '<p class="kt-muted" style="margin:0;font-size:12.5px;color:#64748B;line-height:1.6;">If this was a mistake, an administrator can restore the account, the same way as one removed from the Users screen.</p>', []);
                $mailer = \App\Services\AgencyMailer::forAgency($agencyId);
                $fromA = $mailer->fromAddress();
                $fromN = $mailer->fromName();
                $mailer->html($html, function ($m) use ($u, $fromA, $fromN, $name) {
                    $m->to($u->email, trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')))->from($fromA, $fromN)->subject('Account deleted: ' . $name);
                });
            } catch (\Throwable $e) {}
        }

        return $adminIds;
    }

    /** The person's own receipt: what happened, what is kept and why. */
    private function confirmToPerson($user, ?int $agencyId): void
    {
        if (empty($user->email)) return;
        $tz = \App\Support\AgencyTime::tz($agencyId ?: null);
        $when = now()->setTimezone($tz);
        $first = trim((string) ($user->first_name ?? '')) ?: 'Hello';
        $p = 'margin:0 0 12px;font-size:14.5px;color:#1E293B;line-height:1.6;';
        $html = \App\Services\EmailTemplate::wrap($agencyId,
            '<p style="' . $p . '">' . e($first) . ',</p>'
            . '<p style="' . $p . '">Your KiddieTrac account was deleted on ' . e($when->format('D, M j, Y')) . ' at ' . e($when->format('g:i A T'))
            . '. You have been signed out of every device and can no longer sign in. You will not receive further emails or notifications.</p>'
            . '<p style="' . $p . '">Some records must be kept by law after an account closes, such as a child\'s care and attendance records, and invoices and payments. '
            . 'Those are archived by your centre, used for no other purpose, and removed when their retention period ends.</p>'
            . '<p class="kt-muted" style="margin:0;font-size:12.5px;color:#64748B;line-height:1.6;">If you did not do this, contact your centre straight away. They can restore the account.</p>', []);
        $mailer = \App\Services\AgencyMailer::forAgency($agencyId);
        $fromA = $mailer->fromAddress();
        $fromN = $mailer->fromName();
        $mailer->html($html, function ($m) use ($user, $fromA, $fromN) {
            $m->to($user->email, trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')))->from($fromA, $fromN)->subject('Your KiddieTrac account was deleted');
        });
    }
}
