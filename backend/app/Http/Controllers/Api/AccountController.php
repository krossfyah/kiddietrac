<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * In-app account-deletion path required by App Store Guideline 5.1.1(v).
 * KiddieTrac users are provisioned by their agency, so "delete" raises a
 * deletion request to the agency administrator (a visible in-app path) rather
 * than a silent self-serve wipe.
 */
final class AccountController extends Controller
{
    public function requestDeletion(Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $user = $request->user();

        if (DB::table('account_deletion_requests')->where('user_id', $user->id)->where('status', 'requested')->exists()) {
            return response()->json(['message' => 'You already have a pending deletion request. Your agency administrator will be in touch.'], 200);
        }

        $agencyId = $this->agencyOf((int) $user->id);
        DB::table('account_deletion_requests')->insert([
            'user_id'    => (int) $user->id,
            'agency_id'  => $agencyId,
            'reason'     => $data['reason'] ?? null,
            'status'     => 'requested',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try { $this->notifyAdmins($user, $agencyId, $data['reason'] ?? null); }
        catch (\Throwable $e) { Log::error('Account deletion notify: ' . $e->getMessage()); }

        return response()->json([
            'message' => 'Your account deletion request has been sent to your agency administrator, who will confirm and permanently remove your account and data.',
        ], 201);
    }

    private function agencyOf(int $userId): ?int
    {
        $a = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)->whereNotNull('agency_id')->value('agency_id');
        if ($a) return (int) $a;
        $c = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)->whereNotNull('centre_id')->value('centre_id');
        if ($c) return (int) DB::table('centres')->where('id', $c)->value('agency_id');
        $centreId = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->where('g.user_id', $userId)->value('f.centre_id');
        if ($centreId) return (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        return null;
    }

    private function notifyAdmins($user, ?int $agencyId, ?string $reason): void
    {
        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ('User #' . $user->id);
        $adminIds = $agencyId
            ? DB::table('role_assignments')->where('active', true)->whereIn('role', ['agency_admin', 'centre_director'])
                ->where('agency_id', $agencyId)->pluck('user_id')->unique()->values()->all()
            : [];
        // Fallback: agency_admins by centre → agency, if none matched directly.
        if (empty($adminIds) && $agencyId) {
            $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
            $adminIds = DB::table('role_assignments')->where('active', true)->where('role', 'centre_director')
                ->whereIn('centre_id', $centreIds ?: [0])->pluck('user_id')->unique()->values()->all();
        }

        foreach ($adminIds as $uid) {
            try {
                DB::table('notifications')->insert([
                    'user_id' => (int) $uid, 'type' => 'account_deletion',
                    'title'   => '🗑️ Account deletion request',
                    'body'    => $name . ' has asked to delete their KiddieTrac account and data. Please review in the Users screen.',
                    'data'    => json_encode(['link' => '#admin']), 'created_at' => now(),
                ]);
            } catch (\Throwable $e) {}
            try { app(\App\Services\FcmService::class)->sendToUser((int) $uid, '🗑️ Account deletion request', $name . ' requested account deletion.', '#admin'); } catch (\Throwable $e) {}
            $u = DB::table('users')->where('id', $uid)->first();
            if ($u && ! empty($u->email)) {
                try {
                    $html = \App\Services\EmailTemplate::wrap($agencyId,
                        '<p>Hello ' . e($u->first_name ?: 'there') . ',</p>'
                        . '<p><strong>' . e($name) . '</strong>' . ($user->email ? ' (' . e($user->email) . ')' : '') . ' has requested that their KiddieTrac account and personal data be permanently deleted.</p>'
                        . ($reason ? '<p><strong>Reason given:</strong> ' . e($reason) . '</p>' : '')
                        . '<p>Please review and, if appropriate, remove this user and their data from the <em>Users</em> screen in KiddieTrac.</p>', []);
                    $mailer = \App\Services\AgencyMailer::forAgency($agencyId);
                    $fromA = $mailer->fromAddress();
                    $fromN = $mailer->fromName();
                    $mailer->mailer()->html($html, function ($m) use ($u, $fromA, $fromN) {
                        $m->to($u->email, trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')))->from($fromA, $fromN)->subject('Account deletion request');
                    });
                } catch (\Throwable $e) {}
            }
        }
    }
}
