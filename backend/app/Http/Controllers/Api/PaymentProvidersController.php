<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PaymentProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Payment providers.
 *
 * Deliberately narrower than most admin screens: only an agency ADMIN or a platform
 * admin, never a centre director. A director runs a site; these keys move the agency's
 * money and belong with whoever owns the bank account.
 *
 * Reads never return a secret — only whether one is set and its last four characters.
 */
class PaymentProvidersController extends Controller
{
    private function resolveAgencyId(Request $request): int
    {
        $header = (int) $request->header('X-Active-Agency-Id');
        $uid = (int) $request->user()->id;

        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->get(['role', 'agency_id']);
        $isPlatform = $roles->contains(fn ($r) => $r->role === 'platform_admin');
        $owned = $roles->pluck('agency_id')->filter()->map(fn ($v) => (int) $v)->all();

        if ($header && ($isPlatform || in_array($header, $owned, true))) {
            return $header;
        }
        if ($owned) {
            return (int) $owned[0];
        }
        abort(403, 'No agency access.');
    }

    private function assertAdmin(Request $request): void
    {
        $ok = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', true)
            ->whereIn('role', ['agency_admin', 'platform_admin'])
            ->exists();

        abort_unless($ok, 403, 'Only an agency administrator can change payment settings.');
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->resolveAgencyId($request);

        $out = [];
        foreach (PaymentProviders::providers() as $p) {
            $out[$p] = PaymentProviders::forDisplay($agencyId, $p);
        }

        return response()->json([
            'agency_id' => $agencyId,
            'agency_name' => DB::table('agencies')->where('id', $agencyId)->value('name'),
            'providers' => $out,
            // The callback URL to paste into the provider's own portal. Shown rather than
            // documented, because a mistyped webhook URL fails silently at settlement.
            'webhook_urls' => [
                'zumrails' => rtrim(config('app.url'), '/').'/api/v1/zumrails/webhook',
                'stripe' => rtrim(config('app.url'), '/').'/api/v1/stripe/webhook',
            ],
        ]);
    }

    public function update(Request $request, string $provider): JsonResponse
    {
        $this->assertAdmin($request);
        abort_unless(in_array($provider, PaymentProviders::providers(), true), 404, 'Unknown provider.');
        $agencyId = $this->resolveAgencyId($request);

        $rules = [
            'enabled' => 'nullable|boolean',
            'mode' => 'nullable|in:sandbox,production',
        ];
        foreach (PaymentProviders::fields($provider) as $key => $_) {
            $rules[$key] = 'nullable|string|max:500';
        }
        $data = $request->validate($rules);

        PaymentProviders::save($agencyId, $provider, $data, (int) $request->user()->id);

        // Returned rather than assumed: an admin who has just typed a key wants to know
        // whether it counts as configured, not to be told "saved" and left guessing.
        return response()->json([
            'provider' => PaymentProviders::forDisplay($agencyId, $provider),
        ]);
    }
}
