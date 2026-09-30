<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Batch payment run (2026-09-29): charge every autopay family's open invoices on their saved card.
 *
 * Shared by the nightly `invoices:autopay-charge` (03:00) and Finance -> Payment run, where an
 * agency admin previews and runs it on demand. Each run is written to payment_runs.
 *
 * Two things the nightly command did not do, which a manual button makes dangerous:
 *   - It had no double-charge guard (its comment claimed one). Every charge now carries a Stripe
 *     idempotency key per invoice, amount and day, so "Run now" after the 03:00 run, or two
 *     admins pressing it together, cannot take the same money twice.
 *   - It relied on the webhook to write the payment, and the webhook's duplicate check read a
 *     column that does not exist (stripe_pi_id), so it threw before inserting. A succeeded charge
 *     is now recorded here, deduplicated on payments.stripe_payment_id.
 */
final class AutopayRunner
{
    /** Invoices a run would charge now, optionally for one agency. */
    public static function candidates(?int $agencyId = null)
    {
        $q = DB::table('invoices as i')
            ->join('centres as c', 'c.id', '=', 'i.centre_id')
            ->join('families as f', 'f.id', '=', 'i.family_id')
            ->whereIn('i.status', ['sent', 'overdue', 'partial'])
            ->where('i.balance_due', '>', 0)
            ->whereNull('f.deleted_at')
            ->where('f.autopay_enabled', 1)
            ->whereNotNull('f.autopay_payment_method_id')
            ->whereNotNull('f.stripe_customer_id');
        if ($agencyId) $q->where('c.agency_id', $agencyId);

        return $q->orderBy('f.family_name')->orderBy('i.due_at')->get([
            'i.id', 'i.balance_due', 'i.invoice_number', 'i.due_at', 'i.status', 'i.family_id', 'c.agency_id', 'c.name as centre_name',
            'f.stripe_customer_id', 'f.autopay_payment_method_id', 'f.family_name', 'f.autopay_card_brand', 'f.autopay_card_last4',
        ]);
    }

    /** Open balances the run will NOT collect (no autopay / no card on file), for the preview. */
    public static function notCovered(int $agencyId)
    {
        return DB::table('invoices as i')
            ->join('centres as c', 'c.id', '=', 'i.centre_id')
            ->join('families as f', 'f.id', '=', 'i.family_id')
            ->where('c.agency_id', $agencyId)
            ->whereIn('i.status', ['sent', 'overdue', 'partial'])
            ->where('i.balance_due', '>', 0)
            ->whereNull('f.deleted_at')
            ->where(fn ($w) => $w->where('f.autopay_enabled', 0)->orWhereNull('f.autopay_enabled')
                ->orWhereNull('f.autopay_payment_method_id')->orWhereNull('f.stripe_customer_id'))
            ->selectRaw('f.id as family_id, f.family_name, COUNT(*) as invoices, SUM(i.balance_due) as balance, MIN(i.due_at) as oldest_due, MAX(f.autopay_enabled) as autopay_enabled')
            ->groupBy('f.id', 'f.family_name')->orderByDesc('balance')->get();
    }

    /**
     * Charge. Returns the payment_runs id. $only limits the run to these invoice ids (the ones the
     * admin left ticked in the preview); null = every candidate.
     */
    public static function run(?int $agencyId, string $source, ?int $userId = null, ?array $only = null, bool $log = true): array
    {
        $candidates = self::candidates($agencyId);
        if ($only !== null) $candidates = $candidates->whereIn('id', array_map('intval', $only))->values();

        $results = [];
        $ok = 0; $failed = 0; $charged = 0.0;
        $key = StripeConfig::secret();

        foreach ($candidates as $c) {
            $row = ['invoice_id' => (int) $c->id, 'invoice_number' => $c->invoice_number, 'family' => $c->family_name,
                'amount' => round((float) $c->balance_due, 2), 'card' => trim(($c->autopay_card_brand ?? '') . ' ' . ($c->autopay_card_last4 ? '•' . $c->autopay_card_last4 : ''))];
            if (! $key) {
                $results[] = $row + ['result' => 'skipped', 'message' => StripeConfig::NOT_CONFIGURED];
                continue;
            }
            $cents = (int) round(((float) $c->balance_due) * 100);
            if ($cents <= 0) continue;
            try {
                \Stripe\Stripe::setApiKey($key);
                $pi = \Stripe\PaymentIntent::create([
                    'amount' => $cents,
                    'currency' => strtolower((string) config('services.stripe.currency', env('STRIPE_CURRENCY', 'cad'))),
                    'customer' => $c->stripe_customer_id,
                    'payment_method' => $c->autopay_payment_method_id,
                    'off_session' => true,
                    'confirm' => true,
                    'metadata' => ['invoice_id' => (string) $c->id, 'family_id' => (string) $c->family_id, 'agency_id' => (string) $c->agency_id, 'source' => 'autopay-' . $source],
                ], ['idempotency_key' => 'autopay-' . $c->id . '-' . $cents . '-' . now()->format('Y-m-d')]);

                if (in_array($pi->status, ['succeeded', 'requires_capture'], true)) {
                    if ($pi->status === 'succeeded') self::record((int) $c->id, (int) $c->family_id, $pi->id, ((int) $pi->amount_received) / 100);
                    $ok++; $charged += $cents / 100;
                    $results[] = $row + ['result' => 'charged', 'message' => $pi->id];
                } else {
                    $failed++;
                    $results[] = $row + ['result' => 'failed', 'message' => 'Card needs attention (' . $pi->status . ')'];
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('autopay charge failed', ['inv' => $c->id, 'msg' => $e->getMessage()]);
                $results[] = $row + ['result' => 'failed', 'message' => $e->getMessage()];
                try {
                    Notify::write([
                        'user_id' => DB::table('guardians')->where('family_id', $c->family_id)->where('is_primary', 1)->value('user_id'),
                        'type' => 'payment_failed',
                        'title' => 'Auto-pay failed on invoice ' . $c->invoice_number,
                        'body' => 'Please update your saved card. ' . $e->getMessage(),
                        'data' => json_encode(['invoice_id' => $c->id, 'link' => '#billing']),
                        'created_at' => now(),
                    ]);
                } catch (\Throwable $e2) {
                }
            }
        }

        $skipped = count(array_filter($results, fn ($r) => $r['result'] === 'skipped'));
        $summary = ['candidates' => count($results), 'succeeded' => $ok, 'failed' => $failed, 'skipped' => $skipped, 'charged' => round($charged, 2)];
        $runId = null;
        if ($log && ($agencyId || count($results))) {
            $runId = DB::table('payment_runs')->insertGetId([
                'agency_id' => $agencyId, 'started_by' => $userId, 'source' => $source,
                'status' => ! $key ? 'no_provider' : ($failed ? ($ok ? 'partial' : 'failed') : 'completed'),
                'candidates' => $summary['candidates'], 'succeeded' => $ok, 'failed' => $failed, 'skipped' => $skipped,
                'total_charged' => $summary['charged'], 'results' => json_encode($results, JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return ['run_id' => $runId, 'provider' => $key ? 'stripe' : null, 'summary' => $summary, 'results' => $results];
    }

    /** Write the payment for a succeeded charge, once (the webhook may also arrive). */
    public static function record(int $invoiceId, ?int $familyId, string $piId, float $amount): void
    {
        DB::transaction(function () use ($invoiceId, $familyId, $piId, $amount) {
            if (DB::table('payments')->where('stripe_payment_id', $piId)->lockForUpdate()->exists()) return;
            $inv = DB::table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            if (! $inv) return;
            DB::table('payments')->insert([
                'invoice_id' => $invoiceId, 'family_id' => $familyId ?: $inv->family_id, 'amount' => $amount,
                'method' => 'stripe_card', 'status' => 'succeeded', 'stripe_payment_id' => $piId,
                'paid_at' => now(), 'notes' => 'Auto-pay', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $newBal = max(0, round((float) $inv->balance_due - $amount, 2));
            DB::table('invoices')->where('id', $invoiceId)->update([
                'balance_due' => $newBal, 'amount_paid' => round((float) $inv->amount_paid + $amount, 2),
                'status' => $newBal <= 0.01 ? 'paid' : 'partial', 'updated_at' => now(),
            ]);
        });
    }
}
