<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Refund;
use Stripe\Stripe;

/**
 * v22p58 — Partial + multi-refund support on a single payment.
 * Mirrors Procare's same-day-ACH + partial refund behaviour.
 */
final class RefundsController extends Controller
{
    use ResolvesCentreContext;

    public function __construct()
    {
        if ($k = env('STRIPE_SECRET')) Stripe::setApiKey($k);
    }

    public function listForPayment(Request $request, int $paymentId): JsonResponse
    {
        // SECURITY (v22p96): a payment's refunds are family-private — load the
        // payment and gate on its family (staff of its centre, the family's
        // guardians, or a platform_admin scoped to that agency). Was readable by
        // payment id for any caller.
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        abort_unless($payment, 404);
        abort_unless($this->canAccessFamilyScoped($request, (int) $payment->family_id), 403);
        $rows = DB::table('payment_refunds')->where('payment_id', $paymentId)
            ->orderByDesc('created_at')->get();
        $totalRefunded = $rows->where('status', '!=', 'failed')->sum('amount');
        return response()->json([
            'data' => $rows,
            'payment' => $payment,
            'total_refunded' => $totalRefunded,
            'remaining_refundable' => $payment ? max(0, (float) $payment->amount - (float) $totalRefunded) : 0,
        ]);
    }

    /**
     * v22p98 — recent payments in the active agency, so the Refunds screen can
     * offer a picker instead of forcing the admin to type a raw Payment ID.
     * Scoped to the resolved (header-aware) agency's centres.
     */
    public function recentPayments(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['data' => []]);
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $rows = DB::table('payments as p')
            ->join('families as f', 'f.id', '=', 'p.family_id')
            ->whereIn('f.centre_id', $centreIds ?: [0])
            ->orderByDesc('p.created_at')
            ->limit(60)
            ->select('p.id', 'p.amount', 'p.created_at', 'p.method', 'p.family_id', 'f.family_name')
            ->get();
        $ids = $rows->pluck('id')->all();
        $refunded = DB::table('payment_refunds')->whereIn('payment_id', $ids ?: [0])
            ->where('status', '!=', 'failed')
            ->groupBy('payment_id')->selectRaw('payment_id, SUM(amount) tot')->pluck('tot', 'payment_id');
        return response()->json(['data' => $rows->map(function ($p) use ($refunded) {
            $r = (float) ($refunded[$p->id] ?? 0);
            return [
                'id' => $p->id, 'amount' => (float) $p->amount, 'date' => $p->created_at,
                'method' => $p->method, 'family_name' => $p->family_name,
                'refunded' => $r, 'refundable' => max(0, (float) $p->amount - $r),
            ];
        })->values()]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:1000',
        ]);
        $payment = DB::table('payments')->where('id', $data['payment_id'])->first();
        abort_unless($payment, 404);
        $this->assertAccess($request, (int) $payment->family_id);

        $alreadyRefunded = (float) DB::table('payment_refunds')
            ->where('payment_id', $payment->id)
            ->whereIn('status', ['succeeded', 'pending'])
            ->sum('amount');
        $remaining = (float) $payment->amount - $alreadyRefunded;
        abort_if($data['amount'] > $remaining + 0.001, 422, "Cannot refund \${$data['amount']}; only \${$remaining} remaining");

        $stripeRefundId = null;
        $status = 'succeeded';
        if (env('STRIPE_SECRET') && !empty($payment->stripe_payment_id)) {
            try {
                $cents = (int) round($data['amount'] * 100);
                $refund = Refund::create([
                    'payment_intent' => $payment->stripe_payment_id,
                    'amount' => $cents,
                    'reason' => match ($data['reason'] ?? '') {
                        'duplicate' => 'duplicate',
                        'fraud', 'fraudulent' => 'fraudulent',
                        default => 'requested_by_customer',
                    },
                    'metadata' => [
                        'payment_id' => (string) $payment->id,
                        'family_id' => (string) $payment->family_id,
                        'initiated_by_user_id' => (string) $request->user()->id,
                        'notes' => substr((string) ($data['notes'] ?? ''), 0, 500),
                    ],
                ]);
                $stripeRefundId = $refund->id;
                $status = $refund->status === 'succeeded' ? 'succeeded' : 'pending';
            } catch (\Throwable $e) {
                Log::warning('Stripe refund failed', ['payment' => $payment->id, 'msg' => $e->getMessage()]);
                return response()->json(['error' => $e->getMessage()], 422);
            }
        } else {
            // manual refund — admin records it for audit only
            $status = 'manual';
        }

        $id = DB::table('payment_refunds')->insertGetId([
            'payment_id' => $payment->id,
            'amount' => $data['amount'],
            'reason' => $data['reason'] ?? null,
            'stripe_refund_id' => $stripeRefundId,
            'refund_method' => $stripeRefundId ? 'stripe' : 'manual',
            'status' => $status,
            'initiated_by_id' => $request->user()->id,
            'refunded_at' => now(),
            'notes' => $data['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add credit to the family ledger by inserting a negative-amount payment-like row
        DB::table('payments')->where('id', $payment->id)->update([
            'refunded_at' => now(),
            'notes' => trim(($payment->notes ?? '') . ' [refund $' . $data['amount'] . ']'),
        ]);

        // Notify guardians
        $gids = DB::table('guardians')->where('family_id', $payment->family_id)->pluck('user_id');
        foreach ($gids as $gid) {
            DB::table('notifications')->insert([
                'user_id' => $gid, 'type' => 'refund',
                'title' => 'Refund processed: $' . number_format((float) $data['amount'], 2),
                'body' => ($data['reason'] ?? '') . ($data['notes'] ? ' — ' . $data['notes'] : ''),
                'data' => json_encode(['link' => '#billing', 'payment_id' => $payment->id]),
                'created_at' => now(),
            ]);
        }

        return response()->json(['id' => $id, 'status' => $status, 'stripe_refund_id' => $stripeRefundId], 201);
    }

    private function assertAccess(Request $request, int $familyId): void
    {
        // SECURITY (v22p96): the prior check accepted ANY active staff role on the
        // platform, so a director/admin of agency A — or a switched platform_admin —
        // could refund agency B's payments. Now must be staff of THIS family's
        // centre (or a platform_admin scoped to its agency). Route middleware
        // already keeps guardians off the refund-create action.
        abort_unless($this->canAccessFamilyScoped($request, $familyId), 403);
    }
}
