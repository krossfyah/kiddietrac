<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\AutopayRunner;
use App\Support\StripeConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Finance -> Payment run (2026-09-29). Agency admins preview who auto-pay will charge, untick
 * anyone to hold back, run it now, and see every run (manual and the 03:00 nightly) with its
 * per-invoice results. Charging is admin-only: directors do not get this screen.
 */
class PaymentRunController extends Controller
{
    use ResolvesCentreContext;

    public function preview(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $c = AutopayRunner::candidates($agencyId);

        return response()->json([
            'provider' => StripeConfig::configured() ? 'stripe' : null,
            'provider_message' => StripeConfig::configured() ? null : 'No card processor is connected for this agency, so nothing can be charged yet. Families can still pay by e-transfer, cash or cheque, recorded on the invoice.',
            'candidates' => $c->map(fn ($r) => ['invoice_id' => (int) $r->id, 'invoice_number' => $r->invoice_number, 'family_id' => (int) $r->family_id,
                'family' => $r->family_name, 'centre' => $r->centre_name, 'due_date' => $r->due_at, 'status' => $r->status,
                'amount' => round((float) $r->balance_due, 2), 'card' => trim(($r->autopay_card_brand ?? '') . ' ' . ($r->autopay_card_last4 ? '•' . $r->autopay_card_last4 : ''))])->values(),
            'total' => round((float) $c->sum('balance_due'), 2),
            'not_covered' => AutopayRunner::notCovered($agencyId),
            'last_nightly' => DB::table('payment_runs')->where('agency_id', $agencyId)->where('source', 'nightly')->max('created_at'),
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $data = $request->validate(['invoice_ids' => ['required', 'array', 'min:1'], 'invoice_ids.*' => ['integer']]);
        if (! StripeConfig::configured()) {
            return response()->json(['message' => 'No card processor is connected, so nothing was charged.'], 422);
        }
        // One run per agency at a time (two admins, or a double click).
        $lock = Cache::lock('payment-run:' . $agencyId, 300);
        if (! $lock->get()) {
            return response()->json(['message' => 'A payment run is already in progress for this agency.'], 409);
        }
        try {
            $r = AutopayRunner::run($agencyId, 'manual', (int) $request->user()->id, $data['invoice_ids']);
        } finally {
            $lock->release();
        }
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => 'billing.payment_run',
                'entity_type' => 'payment_run', 'entity_id' => $r['run_id'], 'payload' => json_encode(['summary' => 'Payment run: '
                    . $r['summary']['succeeded'] . ' charged ($' . number_format($r['summary']['charged'], 2) . '), ' . $r['summary']['failed'] . ' failed']),
                'created_at' => now()]);
        } catch (\Throwable $e) {
        }

        return response()->json($r);
    }

    public function history(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        return response()->json(['runs' => DB::table('payment_runs as r')->leftJoin('users as u', 'u.id', '=', 'r.started_by')
            ->where('r.agency_id', $agencyId)->orderByDesc('r.id')->limit(50)
            ->get(['r.id', 'r.source', 'r.status', 'r.candidates', 'r.succeeded', 'r.failed', 'r.skipped', 'r.total_charged', 'r.created_at', 'u.first_name', 'u.last_name'])]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $run = DB::table('payment_runs')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($run, 404);
        $run->results = json_decode((string) $run->results, true) ?: [];
        return response()->json(['run' => $run]);
    }

    private function agency(Request $request): int
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId > 0, 400, 'Select an agency first.');
        $ok = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)
            ->where(fn ($q) => $q->where('role', 'platform_admin')->orWhere(fn ($w) => $w->where('role', 'agency_admin')->where('agency_id', $agencyId)))->exists();
        abort_unless($ok, 403);
        return $agencyId;
    }
}
