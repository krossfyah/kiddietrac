<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "What do we hold about this person, and when is it destroyed?"
 *
 * This is the question a privacy request actually asks, and until now the honest
 * answer was that nobody could assemble one — the information is spread across a
 * dozen tables and the retention policy lived only in prose.
 *
 * Each category reports where it lives, how much of it there is, WHY it is kept, and
 * when it is due for destruction. The destruction date is only ever given where the
 * agency has actually configured a policy for that category; everywhere else the
 * basis is named and the date is left null rather than invented. A retention date
 * shown to an administrator who then repeats it to a parent has to be true, and the
 * statutory periods differ by record type and jurisdiction — that is the agency's
 * determination to make and record, not the software's to guess.
 *
 * Policy is read from agencies.settings->compliance, the same place
 * RetentionPurgeCommand reads, so what is displayed here is what the purge will
 * actually act on. Two sources of truth for retention would be worse than none.
 */
class UserDataMapController extends Controller
{
    use ResolvesCentreContext;

    public function show(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (! $this->userBelongsToAgency($userId, $agencyId)) {
            return response()->json(['message' => 'User not in your agency'], 403);
        }

        $user = DB::table('users')->where('id', $userId)->first();
        if (! $user) return response()->json(['message' => 'User not found'], 404);

        $settings = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        $policy = (isset($settings['compliance']) && is_array($settings['compliance'])) ? $settings['compliance'] : [];
        $purgeOn = (bool) ($policy['retention_purge_enabled'] ?? false);

        $roles = DB::table('role_assignments')->where('user_id', $userId)->pluck('role')->all();
        $isGuardian = in_array('guardian', $roles, true);

        // A month-based policy turns into a real date only when the agency has set
        // one. months === null means "no policy configured" and the caller must show
        // the basis instead of a date.
        $dueDate = function (?int $months, ?string $oldest) {
            if (! $months || ! $oldest) return null;
            try { return Carbon::parse($oldest)->addMonths($months)->toDateString(); } catch (\Throwable $e) { return null; }
        };

        $cat = [];
        $add = function (string $label, string $where, int $count, string $basis, ?string $due, bool $purged = false) use (&$cat) {
            if ($count <= 0) return;
            $cat[] = [
                'label' => $label, 'where' => $where, 'count' => $count,
                'basis' => $basis, 'destroy_on' => $due, 'auto_purged' => $purged,
            ];
        };

        // ── the account itself ────────────────────────────────────────────
        $add('Account and profile', 'users, user_profiles', 1,
            'Identity and contact details for the account. Removed when the record is permanently deleted.', null);

        // ── what they DID (staff) ─────────────────────────────────────────
        $punches = (int) DB::table('time_punches')->where('user_id', $userId)->count();
        $add('Time clock entries', 'time_punches', $punches,
            'Hours worked. Employment and payroll rules set the minimum retention — confirm the period for your jurisdiction.', null);

        $recorded = (int) DB::table('daily_events')->where('recorded_by_id', $userId)->count()
            + (Schema::hasTable('daily_care_logs')
                ? (int) DB::table('daily_care_logs')->where('recorded_by_id', $userId)->count() : 0);
        $add('Care they recorded for children', 'daily_events, daily_care_logs', $recorded,
            'Part of each CHILD\'s record, not personal information about this person — retained with the child\'s file and not removable on their request.', null);

        $checks = (int) DB::table('check_events')->where('recorded_by_id', $userId)->count();
        $add('Check-ins and check-outs they recorded', 'check_events', $checks,
            'Attendance evidence on the children\'s records; kept with those records.', null);

        // The documents table is polymorphic but does not use Laravel's default
        // column names, so ask the schema rather than assuming — a wrong column here
        // 500s the whole map, which is exactly what it did.
        if (Schema::hasTable('documents') && Schema::hasColumn('documents', 'scope_id')) {
            $q = DB::table('documents')->where('scope_id', $userId);
            if (Schema::hasColumn('documents', 'scope_type')) $q->where('scope_type', 'like', '%user%');
            $docs = (int) $q->count();
            $add('Documents filed against them', 'documents', $docs,
                'Certifications, screening checks and signed agreements. Part of the agency\'s licensing records while the obligation applies.', null);
        }

        if (Schema::hasTable('user_notes')) {
            $notes = (int) DB::table('user_notes')->where('user_id', $userId)->count();
            $add('Administrative notes about them', 'user_notes', $notes,
                'Internal notes. Not required by law — removable once no longer needed.', null, false);
        }

        // ── messages, which the purge DOES act on ─────────────────────────
        $msgMonths = isset($policy['message_months']) ? (int) $policy['message_months'] : null;
        if (Schema::hasTable('messages')) {
            $sent = (int) DB::table('messages')->where('sender_id', $userId)->count();
            $oldest = DB::table('messages')->where('sender_id', $userId)->min('created_at');
            $add('Messages they sent', 'messages', $sent,
                $msgMonths
                    ? ('Agency policy: kept ' . $msgMonths . ' months' . ($purgeOn ? ', then removed automatically.' : '. Automatic purge is OFF, so nothing is removed yet.'))
                    : 'No message retention policy set for this agency, so nothing is removed automatically.',
                $dueDate($msgMonths, $oldest), $purgeOn && $msgMonths);
        }

        $audits = (int) DB::table('audit_logs')->where('user_id', $userId)->count();
        $add('Audit trail of their actions', 'audit_logs', $audits,
            'Security and accountability record of what was changed and by whom. Retained as evidence; not removable on request.', null);

        // ── a guardian's family, which is the question a parent asks ──────
        if ($isGuardian && Schema::hasTable('guardians')) {
            $familyIds = DB::table('guardians')->where('user_id', $userId)->pluck('family_id')->all();
            if ($familyIds) {
                $childIds = DB::table('children')->whereIn('family_id', $familyIds)->pluck('id')->all();
                $add('Children linked to them', 'children', count($childIds),
                    'The child\'s own licensed record — enrolment, attendance, care, medical and incident information. Held under the agency\'s child care licensing obligations and NOT removable at a parent\'s request while those apply.', null);

                if ($childIds) {
                    $childCare = (int) DB::table('daily_events')->whereIn('child_id', $childIds)->count()
                        + (Schema::hasTable('daily_care_logs')
                            ? (int) DB::table('daily_care_logs')->whereIn('child_id', $childIds)->count() : 0);
                    $add('Daily care recorded for their children', 'daily_events, daily_care_logs', $childCare,
                        'Part of the child\'s licensed record.', null);

                    if (Schema::hasTable('incidents')) {
                        $inc = (int) DB::table('incidents')->whereIn('child_id', $childIds)->count();
                        $add('Incident reports involving their children', 'incidents', $inc,
                            'Serious occurrence and incident records carry their own statutory retention — confirm the period with your licensing authority.', null);
                    }
                }

                if (Schema::hasTable('invoices')) {
                    $inv = (int) DB::table('invoices')->whereIn('family_id', $familyIds)->count();
                    $add('Invoices and payments', 'invoices', $inv,
                        'Financial records. Tax rules set the minimum retention (commonly six years in Canada) — confirm for your jurisdiction.', null);
                }
            }
        }

        return response()->json([
            'user' => [
                'id' => (int) $user->id,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'email' => $user->email,
                'status' => $user->status,
                'roles' => $roles,
            ],
            'categories' => $cat,
            'policy' => [
                'purge_enabled' => $purgeOn,
                'message_months' => $msgMonths,
                'announcement_months' => isset($policy['announcement_months']) ? (int) $policy['announcement_months'] : null,
            ],
            // Said plainly, because an administrator may repeat it to the person asking.
            'note' => 'Categories without a destruction date are held under a legal obligation whose period this system does not assert. '
                . 'Record your agency\'s determined periods in Settings, and they will appear here and drive the automatic purge.',
        ]);
    }
}
