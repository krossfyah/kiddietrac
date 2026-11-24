<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE PRIVACY NOTICE, ACKNOWLEDGED (2026-11-10, PIPEDA review; Anthony chose "Real acknowledgment").
 *
 * Until now the Data retention switch "Require parent consent at enrolment" was saved and never read. Now, for every
 * agency with it on (the default), each parent of a family there is shown the agency's privacy notice once and taps
 * "I've read it"; the version, link and time are recorded per parent per agency (privacy_acknowledgments). When the
 * agency changes its notice (a new privacy policy link, or "Ask parents again") the version goes up and parents are
 * asked again. Switched off, nobody is asked - the screen warns, and the change is audited (DataRetentionController).
 *
 * A parent belongs to an agency through their family's centre (guardians -> families -> centres), so a parent at two
 * agencies confirms each agency's notice separately. A super admin viewing as a parent never confirms for them.
 * Separate from photo, text and marketing permission, which each have their own yes/no.
 */
class PrivacyNoticeController extends Controller
{
    public const DEFAULT_URL = 'https://kiddietrac.com/privacy/';

    /** GET /me/privacy-notice - the notices this parent still has to confirm. */
    public function mine(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        if ($this->viewingAs($request)) return response()->json(['pending' => [], 'viewing_as' => true]);
        $pending = [];
        foreach ($this->parentAgencies($uid) as $a) {
            $n = self::notice($a);
            if (! $n['required']) continue;
            $done = DB::table('privacy_acknowledgments')->where('user_id', $uid)->where('agency_id', $a->id)->where('version', $n['version'])->exists();
            if (! $done) $pending[] = ['agency_id' => (int) $a->id, 'agency_name' => $a->name, 'url' => $n['url'], 'own_policy' => $n['own'],
                'contact' => $n['contact'], 'version' => $n['version']];
        }

        return response()->json(['pending' => $pending]);
    }

    /** POST /me/privacy-notice {agency_id, version} - "I've read it". */
    public function acknowledge(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        abort_if($this->viewingAs($request), 403, 'Only the parent can confirm this.');
        $d = $request->validate(['agency_id' => ['required', 'integer'], 'version' => ['required', 'integer', 'min:1'],
            'source' => ['sometimes', 'nullable', 'in:portal,onboarding']]);
        $a = collect($this->parentAgencies($uid))->firstWhere('id', (int) $d['agency_id']);
        abort_unless($a, 403, 'You are not a parent at that agency.');
        $n = self::notice($a);
        abort_unless((int) $d['version'] === $n['version'], 409, 'The privacy notice was just updated. Please read the new one.');
        DB::table('privacy_acknowledgments')->insertOrIgnore(['user_id' => $uid, 'agency_id' => (int) $a->id, 'version' => $n['version'],
            'notice_url' => $n['url'], 'source' => $d['source'] ?? 'portal', 'acknowledged_at' => now()]);
        $this->audit($uid, (int) $a->id, 'privacy_notice.acknowledged', 'Confirmed reading the privacy notice (version ' . $n['version'] . ')');

        return $this->mine($request);
    }

    /** GET /admin/privacy-notice - who has confirmed the current notice. */
    public function status(Request $request): JsonResponse
    {
        $agencyId = $this->adminAgency($request);
        $a = DB::table('agencies')->where('id', $agencyId)->first(['id', 'name', 'settings']);
        $n = self::notice($a);
        $parents = $this->agencyParents($agencyId);
        $acks = DB::table('privacy_acknowledgments')->where('agency_id', $agencyId)->where('version', $n['version'])
            ->whereIn('user_id', $parents->pluck('id')->all() ?: [0])->pluck('acknowledged_at', 'user_id');
        $last = DB::table('privacy_acknowledgments')->where('agency_id', $agencyId)->max('acknowledged_at');
        $tz = AgencyTime::tz($agencyId);

        return response()->json([
            'required' => $n['required'], 'version' => $n['version'], 'url' => $n['url'], 'own_policy' => $n['own'],
            'parents' => $parents->count(), 'acknowledged' => $acks->count(),
            // First name and last initial only, and only the ones still to confirm - the admin's to-do list.
            'waiting' => $parents->reject(fn ($p) => isset($acks[$p->id]))->take(100)
                ->map(fn ($p) => trim($p->first_name . ' ' . mb_substr((string) $p->last_name, 0, 1) . ($p->last_name ? '.' : '')))->values(),
            'last_at' => $last ? Carbon::parse($last, 'UTC')->setTimezone($tz)->format('M j, Y') : null,
        ]);
    }

    /** POST /admin/privacy-notice/reask - the notice changed in a way a link does not show: ask every parent again. */
    public function reask(Request $request): JsonResponse
    {
        $agencyId = $this->adminAgency($request);
        $v = self::bump($agencyId);
        $this->audit((int) $request->user()->id, $agencyId, 'privacy_notice.reask', 'Asked every parent to read the privacy notice again (version ' . $v . ')');

        return $this->status($request);
    }

    /* ── helpers ──────────────────────────────────────────────────────────────────────────────────────────── */

    /** The agency's notice as parents see it. */
    public static function notice(object $agency): array
    {
        $s = json_decode((string) ($agency->settings ?? ''), true) ?: [];
        $c = (isset($s['compliance']) && is_array($s['compliance'])) ? $s['compliance'] : [];
        $url = trim((string) ($c['privacy_policy_url'] ?? ''));

        return [
            'required' => ! array_key_exists('require_consent', $c) || (bool) $c['require_consent'],
            'version' => max(1, (int) ($c['privacy_notice_version'] ?? 1)),
            'url' => $url !== '' ? $url : self::DEFAULT_URL,
            'own' => $url !== '',
            'contact' => trim((string) ($c['data_contact_email'] ?? '')) ?: null,
        ];
    }

    /** Raise the agency's notice version by one; returns the new version. */
    public static function bump(int $agencyId): int
    {
        return DB::transaction(function () use ($agencyId) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['settings']);
            $s = json_decode((string) ($row->settings ?? ''), true) ?: [];
            $s['compliance'] = (isset($s['compliance']) && is_array($s['compliance'])) ? $s['compliance'] : [];
            $v = max(1, (int) ($s['compliance']['privacy_notice_version'] ?? 1)) + 1;
            $s['compliance']['privacy_notice_version'] = $v;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);

            return $v;
        });
    }

    /** Agencies this person is a parent at, through their families' centres. */
    private function parentAgencies(int $uid): array
    {
        return DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')->join('agencies as a', 'a.id', '=', 'ce.agency_id')
            ->where('g.user_id', $uid)->whereNull('f.deleted_at')->whereNull('a.deleted_at')
            ->distinct()->get(['a.id', 'a.name', 'a.settings'])->all();
    }

    /** Real parents of an agency's families (not removed, not deactivated). */
    private function agencyParents(int $agencyId)
    {
        return DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('ce.agency_id', $agencyId)->whereNull('f.deleted_at')->whereNull('u.deleted_at')
            ->whereNotIn('u.status', ['deactivated', 'suspended'])
            ->distinct()->orderBy('u.first_name')->get(['u.id', 'u.first_name', 'u.last_name']);
    }

    /** The active agency, for its admin or a platform admin. Fails closed. */
    private function adminAgency(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $activeId = (int) $request->header('X-Active-Agency-Id');
        $platform = DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', 1)->exists();
        abort_unless($activeId, $platform ? 400 : 403, 'Select an agency first.');
        abort_unless($platform || DB::table('role_assignments')->where('user_id', $uid)->where('agency_id', $activeId)
            ->where('role', 'agency_admin')->where('active', 1)->exists(), 403, 'Only an agency admin can see this.');

        return $activeId;
    }

    private function viewingAs(Request $request): bool
    {
        $pat = method_exists($request->user(), 'currentAccessToken') ? $request->user()->currentAccessToken() : null;

        return $pat && isset($pat->name) && str_starts_with((string) $pat->name, 'impersonation:');
    }

    private function audit(int $uid, int $agencyId, string $action, string $summary): void
    {
        try {
            Audit::write(['user_id' => $uid, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => 'agency', 'entity_id' => $agencyId,
                'payload' => json_encode(['summary' => $summary]), 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
