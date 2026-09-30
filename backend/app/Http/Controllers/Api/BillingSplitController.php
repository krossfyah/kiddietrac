<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\BillingSplit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET/PUT /admin/families/{id}/billing-split (2026-09-29) — who pays and what share.
 * Staff with access to the family (admins, the family's directors).
 */
class BillingSplitController extends Controller
{
    use ResolvesCentreContext;

    public function show(Request $request, int $familyId): JsonResponse
    {
        $family = $this->family($request, $familyId);
        return response()->json([
            'mode' => $family->billing_split ?: 'single',
            'guardians' => DB::table('guardians as g')->leftJoin('users as u', 'u.id', '=', 'g.user_id')->where('g.family_id', $familyId)
                ->orderByDesc('g.is_primary')->orderBy('g.id')
                ->get(['g.id', 'g.relationship', 'g.is_primary', 'g.can_receive_billing', 'g.billing_share_pct', 'u.first_name', 'u.last_name', 'u.email']),
            'payers' => BillingSplit::payers($familyId),
        ]);
    }

    public function update(Request $request, int $familyId): JsonResponse
    {
        $family = $this->family($request, $familyId);
        $data = $request->validate([
            'mode' => ['required', 'in:single,split_50_50,custom'],
            'shares' => ['nullable', 'array'],
            'shares.*.guardian_id' => ['required', 'integer'],
            'shares.*.pct' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        $ids = DB::table('guardians')->where('family_id', $familyId)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $shares = collect($data['shares'] ?? [])->map(fn ($s) => ['guardian_id' => (int) $s['guardian_id'], 'pct' => round((float) $s['pct'], 2)])
            ->filter(fn ($s) => in_array($s['guardian_id'], $ids, true));
        if ($data['mode'] !== 'single') {
            $paying = $shares->filter(fn ($s) => $s['pct'] > 0);
            if ($paying->count() < 2) {
                return response()->json(['message' => 'A split needs at least two guardians with a share.'], 422);
            }
            if (abs($paying->sum('pct') - 100) > 0.01) {
                return response()->json(['message' => 'The shares must add up to 100%. They add up to ' . round($paying->sum('pct'), 2) . '%.'], 422);
            }
        }
        DB::transaction(function () use ($familyId, $data, $shares) {
            DB::table('families')->where('id', $familyId)->update(['billing_split' => $data['mode'], 'updated_at' => now()]);
            if ($data['mode'] === 'single') {
                // One payer again: the primary guardian carries 100%; billing emails unchanged.
                $primary = DB::table('guardians')->where('family_id', $familyId)->orderByDesc('is_primary')->orderBy('id')->value('id');
                DB::table('guardians')->where('family_id', $familyId)->update(['billing_share_pct' => 0]);
                if ($primary) DB::table('guardians')->where('id', $primary)->update(['billing_share_pct' => 100, 'can_receive_billing' => 1]);
            } else {
                foreach ($shares as $s) {
                    DB::table('guardians')->where('id', $s['guardian_id'])->where('family_id', $familyId)
                        ->update(['billing_share_pct' => $s['pct'], 'can_receive_billing' => $s['pct'] > 0 ? 1 : 0]);
                }
            }
        });
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $this->agencyOf($family), 'action' => 'billing.split_updated',
                'entity_type' => 'family', 'entity_id' => $familyId, 'payload' => json_encode(['summary' => 'Billing split for ' . $family->family_name . ': '
                    . ($data['mode'] === 'single' ? 'single payer' : $shares->filter(fn ($s) => $s['pct'] > 0)->map(fn ($s) => '#' . $s['guardian_id'] . ' ' . $s['pct'] . '%')->implode(', '))]),
                'created_at' => now()]);
        } catch (\Throwable $e) {
        }

        return $this->show($request, $familyId);
    }

    private function family(Request $request, int $familyId): object
    {
        $family = DB::table('families')->where('id', $familyId)->whereNull('deleted_at')->first();
        abort_unless($family, 404);
        abort_unless($this->canAccessFamilyScoped($request, $familyId), 403);
        $staff = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['platform_admin', 'agency_admin', 'centre_director'])->exists();
        abort_unless($staff, 403);
        return $family;
    }

    private function agencyOf(object $family): ?int
    {
        $a = DB::table('centres')->where('id', $family->centre_id)->value('agency_id');
        return $a ? (int) $a : null;
    }
}
