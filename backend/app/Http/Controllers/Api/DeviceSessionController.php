<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SIGN OUT A LOST PHONE OR KIOSK REMOTELY (2026-11-09, Anthony: "Sign out a lost phone or kiosk remotely in one click
 * ... wire this up for kiddietrac" - from the childfriendlypro comparison; before this only fingerprint sign-in could be
 * withdrawn).
 *
 * Every sign-in is a Sanctum token, one per device or browser (its name is the browser's user agent). Signing a device
 * out deletes that token, so the device's next request is refused and it is back at the sign-in page. It also ends Face
 * ID / fingerprint and PIN unlock there, because both only reopen that same stored token (kt-biometric.js, kt-pin.js),
 * and it removes the device's push registration (device_tokens.access_token_id), so a lost phone stops showing
 * notifications about children.
 *
 *   GET  /me/devices                          your own signed-in devices
 *   POST /me/devices/sign-out                 {token_id} one, or {others: true} every device but this one
 *   GET  /admin/users/{user}/devices          a person's devices (an administrator)
 *   POST /admin/users/{user}/devices/sign-out {token_id} one, or nothing = every device
 *
 * Who may sign someone else out: a platform admin; an agency admin for anyone who works or has a family in the active
 * agency; a centre director for people at a centre they direct (never an admin). Kiosk tablets sign in with the centre's
 * kiosk link, not a person: KioskController::rotateToken signs every kiosk at the centre out at once.
 */
final class DeviceSessionController extends Controller
{
    private const USER = 'App\\Models\\User';

    /** The person's live sign-ins, newest use first. */
    private function rows(int $uid, ?int $current, ?string $tz): array
    {
        $toks = DB::table('personal_access_tokens')->where('tokenable_type', self::USER)->where('tokenable_id', $uid)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByRaw('COALESCE(last_used_at, created_at) DESC')->limit(100)
            ->get(['id', 'name', 'created_at', 'last_used_at']);
        $push = DB::table('device_tokens')->whereIn('access_token_id', $toks->pluck('id')->all() ?: [0])
            ->get(['access_token_id', 'platform', 'device_name'])->keyBy('access_token_id');
        $fmt = fn ($v) => $v ? Carbon::parse($v, 'UTC')->setTimezone($tz ?: config('app.timezone'))->format('M j, Y g:i A') : null;

        return $toks->map(function ($t) use ($current, $push, $fmt) {
            $p = $push->get($t->id);
            $name = (string) $t->name;
            $kind = str_starts_with($name, 'impersonation:') ? 'admin_view' : ($p && in_array($p->platform, ['ios', 'android'], true) ? 'app' : 'browser');
            $label = $kind === 'admin_view' ? 'An administrator viewing as this person'
                : ($p && $p->device_name && ! preg_match('/Mozilla\//', (string) $p->device_name) ? (string) $p->device_name . ' · KiddieTrac app' : BiometricController::describeDevice($name));
            return ['id' => (int) $t->id, 'label' => $label, 'kind' => $kind, 'platform' => $p->platform ?? null, 'push' => (bool) $p,
                'signed_in' => $fmt($t->created_at), 'last_used' => $fmt($t->last_used_at ?: $t->created_at),
                'last_used_at' => $t->last_used_at, 'current' => $current !== null && (int) $t->id === $current];
        })->values()->all();
    }

    /** Delete these sign-ins of $uid and their push registrations. Returns how many were signed out. */
    private function signOut(int $uid, ?array $ids, ?int $keep): int
    {
        $q = DB::table('personal_access_tokens')->where('tokenable_type', self::USER)->where('tokenable_id', $uid);
        if ($ids !== null) $q->whereIn('id', $ids ?: [0]);
        if ($keep) $q->where('id', '!=', $keep);
        $gone = $q->pluck('id')->map(fn ($v) => (int) $v)->all();
        if (! $gone) return 0;
        DB::transaction(function () use ($gone) {
            DB::table('device_tokens')->whereIn('access_token_id', $gone)->delete();
            DB::table('personal_access_tokens')->whereIn('id', $gone)->delete();
        });

        return count($gone);
    }

    private function current(Request $request): ?int
    {
        $t = $request->user()?->currentAccessToken();
        return $t && isset($t->id) ? (int) $t->id : null;
    }

    private function audit(Request $request, int $uid, string $summary): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => ((int) $request->header('X-Active-Agency-Id')) ?: null,
                'action' => 'security.devices_signed_out', 'entity_type' => 'user', 'entity_id' => $uid,
                'payload' => json_encode(['summary' => $summary]), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }

    /** GET /me/devices */
    public function mine(Request $request): JsonResponse
    {
        $tz = AgencyTime::tz(((int) $request->header('X-Active-Agency-Id')) ?: null);
        return response()->json(['devices' => $this->rows((int) $request->user()->id, $this->current($request), $tz)]);
    }

    /** POST /me/devices/sign-out {token_id} | {others: true} */
    public function signOutMine(Request $request): JsonResponse
    {
        $d = $request->validate(['token_id' => ['nullable', 'integer'], 'others' => ['nullable', 'boolean']]);
        $uid = (int) $request->user()->id;
        $cur = $this->current($request);
        if (! empty($d['others'])) {
            $n = $this->signOut($uid, null, $cur);
            $this->audit($request, $uid, 'Signed out of every other device (' . $n . ')');
            return response()->json(['ok' => true, 'signed_out' => $n]);
        }
        abort_if(empty($d['token_id']), 422, 'Choose a device to sign out.');
        abort_if($cur && (int) $d['token_id'] === $cur, 422, 'That is the device you are using. Use Sign out in the menu instead.');
        $label = collect($this->rows($uid, $cur, null))->firstWhere('id', (int) $d['token_id'])['label'] ?? null;
        abort_unless($label, 404, 'That device is already signed out.');
        $n = $this->signOut($uid, [(int) $d['token_id']], null);
        $this->audit($request, $uid, 'Signed out of ' . $label . ' remotely');
        return response()->json(['ok' => true, 'signed_out' => $n]);
    }

    /**
     * May the caller sign $target out? Fails closed. Platform admins anyone; agency admins anyone with a role in the
     * active agency (not a platform admin); directors people at a centre they direct (not an admin of any kind).
     */
    private function assertMay(Request $request, int $target): int
    {
        $me = (int) $request->user()->id;
        $agencyId = (int) $request->header('X-Active-Agency-Id');
        $mine = DB::table('role_assignments')->where('user_id', $me)->where('active', true)->get(['role', 'agency_id', 'centre_id']);
        if ($mine->contains('role', 'platform_admin')) return $agencyId;
        abort_unless($agencyId > 0, 422, 'Choose an agency first.');
        $theirs = DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')->where('ra.user_id', $target)->where('ra.active', true)
            ->get(['ra.role', 'ra.agency_id', 'ra.centre_id', 'c.agency_id as centre_agency']);
        abort_if($theirs->contains('role', 'platform_admin'), 403, 'Only a platform administrator can do this for that account.');
        $inAgency = $theirs->contains(fn ($r) => (int) $r->agency_id === $agencyId || (int) $r->centre_agency === $agencyId);
        abort_unless($inAgency, 403, 'That person is not in this agency.');
        if ($mine->contains(fn ($r) => $r->role === 'agency_admin' && (int) $r->agency_id === $agencyId)) return $agencyId;
        $directs = $mine->filter(fn ($r) => $r->role === 'centre_director' && $r->centre_id)->pluck('centre_id')->map(fn ($v) => (int) $v)->all();
        $atMine = $directs && $theirs->contains(fn ($r) => in_array((int) $r->centre_id, $directs, true));
        $isAdmin = $theirs->contains(fn ($r) => in_array($r->role, ['agency_admin', 'centre_director'], true) && ! in_array((int) $r->centre_id, $directs, true));
        abort_unless($atMine && ! $isAdmin && $target !== $me, 403, 'Only an administrator, or the director of their centre, can sign this person out.');

        return $agencyId;
    }

    /** GET /admin/users/{user}/devices */
    public function forUser(Request $request, int $user): JsonResponse
    {
        $agencyId = $this->assertMay($request, $user);
        return response()->json(['devices' => $this->rows($user, $user === (int) $request->user()->id ? $this->current($request) : null, AgencyTime::tz($agencyId ?: null))]);
    }

    /** POST /admin/users/{user}/devices/sign-out {token_id} | {} = every device */
    public function signOutUser(Request $request, int $user): JsonResponse
    {
        $this->assertMay($request, $user);
        $d = $request->validate(['token_id' => ['nullable', 'integer']]);
        $keep = $user === (int) $request->user()->id ? $this->current($request) : null;   // never your own current session
        $first = DB::table('users')->where('id', $user)->value('first_name') ?: 'this person';
        if (! empty($d['token_id'])) {
            $label = collect($this->rows($user, null, null))->firstWhere('id', (int) $d['token_id'])['label'] ?? null;
            abort_unless($label, 404, 'That device is already signed out.');
            $n = $this->signOut($user, [(int) $d['token_id']], $keep);
            $this->audit($request, $user, 'Signed ' . $first . ' out of ' . $label . ' remotely');
        } else {
            $n = $this->signOut($user, null, $keep);
            $this->audit($request, $user, 'Signed ' . $first . ' out of every device (' . $n . ')');
        }

        return response()->json(['ok' => true, 'signed_out' => $n]);
    }
}
