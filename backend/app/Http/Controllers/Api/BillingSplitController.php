<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\BillingSplit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who pays what, per family (Families -> family -> Billing split).
 *   GET  /admin/families/{id}/billing-split          the setting in force, its history, guardians (+ how each pays), preview
 *   PUT  /admin/families/{id}/billing-split          save a new setting from a date ({config, effective_from}); the v1 body
 *                                                    ({mode: single|split_50_50|custom, shares}) still works
 *   POST /admin/families/{id}/billing-split/preview  how the latest invoice would split under an unsaved setting
 * Staff with access to the family (agency admins, the family's directors, platform admin in the agency).
 */
class BillingSplitController extends Controller
{
    use ResolvesCentreContext;

    public function show(Request $request, int $familyId): JsonResponse
    {
        $family = $this->family($request, $familyId);
        $cfg = BillingSplit::config($familyId);
        $guardians = $this->guardians($family);

        $history = DB::table('family_billing_splits as s')->leftJoin('users as u', 'u.id', '=', 's.created_by')
            ->where('s.family_id', $familyId)->orderByDesc('s.effective_from')->orderByDesc('s.id')->limit(50)
            ->get(['s.id', 's.mode', 's.config', 's.effective_from', 's.created_at', 'u.first_name', 'u.last_name'])
            ->map(function ($r) {
                $c = BillingSplit::normalise(json_decode((string) $r->config, true) ?: []);
                return ['id' => (int) $r->id, 'effective_from' => $r->effective_from, 'created_at' => $r->created_at,
                    'by' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: null,
                    'label' => BillingSplit::label($c), 'text' => BillingSplit::describe($c), 'config' => $c];
            });
        $upcoming = DB::table('family_billing_splits')->where('family_id', $familyId)->where('effective_from', '>', AgencyTime::todayDate()->toDateString())
            ->orderBy('effective_from')->first(['effective_from', 'config']);

        return response()->json([
            // v1 fields, still read by older screens
            'mode' => $family->billing_split ?: 'single',
            'payers' => BillingSplit::payers($familyId),
            // v2
            'config' => $cfg ?: ['mode' => 'single', 'payer' => $this->primary($familyId)],
            'label' => BillingSplit::label($cfg),
            'text' => BillingSplit::describe($cfg),
            'upcoming' => $upcoming ? ['effective_from' => $upcoming->effective_from,
                'text' => BillingSplit::describe(BillingSplit::normalise(json_decode((string) $upcoming->config, true) ?: []))] : null,
            'history' => $history,
            'guardians' => $guardians,
            // The family-level methods (one card / bank account for the whole family, used by autopay).
            'family_card' => trim(implode(' · ', array_filter([
                $family->autopay_card_last4 ? 'Family card on file: ' . ucfirst((string) $family->autopay_card_brand) . ' •' . $family->autopay_card_last4 . ($family->autopay_enabled ? ' (autopay on)' : '') : null,
                $family->ach_last4 ? 'Family bank account: ' . ($family->ach_bank_name ?: 'Bank') . ' •' . $family->ach_last4 : null,
            ]))) ?: null,
            'children' => DB::table('children')->where('family_id', $familyId)->whereNull('deleted_at')->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'enrollment_status']),
            'preview' => $this->preview($familyId, $cfg),
            'today' => AgencyTime::todayDate()->toDateString(),
        ]);
    }

    public function update(Request $request, int $familyId): JsonResponse
    {
        $family = $this->family($request, $familyId);
        $guardianIds = DB::table('guardians')->where('family_id', $familyId)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $childIds = DB::table('children')->where('family_id', $familyId)->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all();

        if ($request->has('config')) {
            $data = $request->validate([
                'config' => ['required', 'array'],
                'config.mode' => ['required', 'in:' . implode(',', BillingSplit::MODES)],
                'effective_from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2020-01-01'],
            ]);
            $cfg = BillingSplit::normalise($request->input('config'));
        } else {
            // v1 body
            $data = $request->validate([
                'mode' => ['required', 'in:single,split_50_50,custom'],
                'shares' => ['nullable', 'array'],
                'shares.*.guardian_id' => ['required', 'integer'],
                'shares.*.pct' => ['required', 'numeric', 'min:0', 'max:100'],
            ]);
            $cfg = $data['mode'] === 'single'
                ? ['mode' => 'single', 'payer' => $this->primary($familyId)]
                : BillingSplit::normalise(['mode' => 'percent', 'shares' => $data['shares'] ?? []]);
        }
        if ($err = BillingSplit::validate($cfg, $guardianIds, $childIds)) {
            return response()->json(['message' => $err, 'errors' => ['config' => [$err]]], 422);
        }
        $from = $data['effective_from'] ?? AgencyTime::todayDate()->toDateString();
        $before = BillingSplit::config($familyId, $from);
        BillingSplit::save($familyId, $cfg, $from, (int) $request->user()->id);

        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $this->agencyOf($family), 'action' => 'billing.split_updated',
                'entity_type' => 'family', 'entity_id' => $familyId, 'payload' => json_encode([
                    'summary' => 'Billing split for ' . $family->family_name . ' from ' . $from . ': ' . BillingSplit::label($cfg) . ' - ' . BillingSplit::describe($cfg),
                    'before' => $before, 'after' => $cfg, 'effective_from' => $from,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now()]);
        } catch (\Throwable $e) {
        }

        return $this->show($request, $familyId);
    }

    /** POST .../preview {config} - the latest invoice split under an unsaved setting (nothing saved). */
    public function previewUnsaved(Request $request, int $familyId): JsonResponse
    {
        $this->family($request, $familyId);
        $request->validate(['config' => ['required', 'array'], 'config.mode' => ['required', 'in:' . implode(',', BillingSplit::MODES)]]);
        $cfg = BillingSplit::normalise($request->input('config'));
        $guardianIds = DB::table('guardians')->where('family_id', $familyId)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $childIds = DB::table('children')->where('family_id', $familyId)->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all();
        return response()->json(['error' => BillingSplit::validate($cfg, $guardianIds, $childIds), 'text' => BillingSplit::describe($cfg),
            'preview' => $this->preview($familyId, $cfg)]);
    }

    /** How the family's most recent invoice splits under $cfg: [{guardian_id, name, share}] + the invoice it used. */
    private function preview(int $familyId, ?array $cfg): ?array
    {
        $inv = DB::table('invoices')->where('family_id', $familyId)->whereNotIn('status', ['void'])
            ->orderByDesc('issued_at')->orderByDesc('id')->first(['id', 'invoice_number', 'total', 'issued_at', 'period_start', 'period_end']);
        if (! $inv) return null;
        $lines = DB::table('invoice_lines')->where('invoice_id', $inv->id)->get(['amount', 'child_id', 'line_type', 'payer_guardian_id', 'description'])
            ->map(fn ($r) => (array) $r)->all();
        $total = round((float) $inv->total, 2);
        $rows = (! $cfg || $cfg['mode'] === 'single')
            ? [['guardian_id' => (int) ($cfg['payer'] ?? $this->primary($familyId)), 'share' => $total]]
            : BillingSplit::allocate($total, $lines, $cfg);
        $names = BillingSplit::names(array_column($rows, 'guardian_id'));
        return ['invoice' => ['id' => (int) $inv->id, 'number' => $inv->invoice_number, 'total' => $total, 'issued_at' => $inv->issued_at,
            'lines' => array_map(fn ($l) => ['description' => $l['description'], 'amount' => round((float) $l['amount'], 2), 'line_type' => $l['line_type']], $lines)],
            'shares' => array_map(fn ($r) => $r + ['name' => $names[$r['guardian_id']] ?? 'Guardian'], $rows)];
    }

    /** Guardians with how each one pays (what the family has on file, and what they last paid with). */
    private function guardians(object $family): array
    {
        $rows = DB::table('guardians as g')->leftJoin('users as u', 'u.id', '=', 'g.user_id')->where('g.family_id', $family->id)
            ->where(fn ($w) => $w->whereNull('g.billing_hidden')->orWhere('g.billing_hidden', 0))
            ->orderByDesc('g.is_primary')->orderBy('g.id')
            ->get(['g.id', 'g.user_id', 'g.relationship', 'g.is_primary', 'g.can_receive_billing', 'g.billing_share_pct', 'u.first_name', 'u.last_name', 'u.email', 'u.status']);
        $zum = Schema::hasTable('zum_users') ? DB::table('zum_users')->whereIn('user_id', $rows->pluck('user_id')->filter())->pluck('user_id')->map(fn ($v) => (int) $v)->all() : [];
        $last = DB::table('payments')->whereIn('payer_guardian_id', $rows->pluck('id'))->whereNotIn('status', ['failed', 'void'])
            ->orderByDesc('paid_at')->get(['payer_guardian_id', 'method', 'paid_at', 'amount'])->groupBy('payer_guardian_id')->map->first();
        $methodWord = ['stripe_card' => 'Card', 'helcim_card' => 'Card', 'card' => 'Card', 'stripe_ach' => 'Bank (ACH)', 'eft' => 'Bank debit',
            'interac' => 'Interac e-Transfer', 'cash' => 'Cash', 'cheque' => 'Cheque', 'manual' => 'Recorded by the office'];
        return $rows->map(function ($r) use ($family, $zum, $last, $methodWord) {
            $methods = [];
            if ($r->user_id && in_array((int) $r->user_id, $zum, true)) $methods[] = 'Bank / card linked (Zum)';
            $l = $last[$r->id] ?? null;
            return ['id' => (int) $r->id, 'user_id' => $r->user_id ? (int) $r->user_id : null,
                'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: ($r->email ?: 'Guardian #' . $r->id),
                'first_name' => $r->first_name, 'email' => $r->email, 'relationship' => $r->relationship, 'is_primary' => (bool) $r->is_primary,
                'can_receive_billing' => (bool) $r->can_receive_billing, 'billing_share_pct' => (float) $r->billing_share_pct,
                'signed_in' => $r->status && ! in_array($r->status, ['invited', 'not_invited', 'deactivated', 'suspended'], true),
                'methods' => $methods,
                'last_payment' => $l ? ['method' => $methodWord[$l->method] ?? $l->method, 'paid_at' => $l->paid_at, 'amount' => round((float) $l->amount, 2)] : null];
        })->values()->all() + [];
    }

    private function primary(int $familyId): int
    {
        return (int) DB::table('guardians')->where('family_id', $familyId)->orderByDesc('is_primary')->orderBy('id')->value('id');
    }

    private function family(Request $request, int $familyId): object
    {
        $family = DB::table('families')->where('id', $familyId)->whereNull('deleted_at')->first();
        abort_unless($family, 404);
        abort_unless($this->canAccessFamilyScoped($request, $familyId), 403);
        $staff = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['platform_admin', 'agency_admin', 'centre_director'])->exists();
        abort_unless($staff, 403);
        /* Staff who run THIS family's centre (2026-11-05 leak sweep): canAccessFamilyScoped says yes to the family's own
           parent and to any staff role at the centre, and the director check above was any centre in the agency, so
           a parent who directs another centre, or a director elsewhere who teaches here, could rewrite who pays.
           Now: an agency admin of the family's agency, the director of its centre, or a platform admin acting in it. */
        $agencyId = $this->agencyOf($family);
        $roles = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)->get(['role', 'agency_id', 'centre_id']);
        $runs = $agencyId && ($roles->contains(fn ($r) => $r->role === 'platform_admin')
            ? (int) $this->resolveAgencyId($request) === $agencyId
            : $roles->contains(fn ($r) => ($r->role === 'agency_admin' && (int) $r->agency_id === $agencyId)
                || ($r->role === 'centre_director' && (int) $r->centre_id === (int) $family->centre_id)));
        abort_unless($runs, 403);
        return $family;
    }

    private function agencyOf(object $family): ?int
    {
        $a = DB::table('centres')->where('id', $family->centre_id)->value('agency_id');
        return $a ? (int) $a : null;
    }
}
