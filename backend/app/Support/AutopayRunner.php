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

        $rows = $q->orderBy('f.family_name')->orderBy('i.due_at')->get([
            'i.id', 'i.balance_due', 'i.invoice_number', 'i.due_at', 'i.status', 'i.family_id', 'c.agency_id', 'c.name as centre_name',
            'f.stripe_customer_id', 'f.autopay_payment_method_id', 'f.family_name', 'f.autopay_card_brand', 'f.autopay_card_last4',
        ]);
        // A split invoice is autopaid per guardian, for their share (guardianCandidates) - never the whole bill on the family card.
        return $rows->reject(fn ($r) => \App\Support\SplitInvoiceCopy::isSplitInvoice((int) $r->id))->values();
    }

    /**
     * SPLIT BILLING (2026-10-30): each guardian with their own autopay card is charged their own share of each open split
     * invoice. Rows look like candidates() rows, with balance_due = the guardian's balance and payer_guardian_id set.
     */
    public static function guardianCandidates(?int $agencyId = null)
    {
        $q = DB::table('invoices as i')
            ->join('centres as c', 'c.id', '=', 'i.centre_id')
            ->join('families as f', 'f.id', '=', 'i.family_id')
            ->join('guardians as g', 'g.family_id', '=', 'f.id')
            ->whereIn('i.status', ['sent', 'overdue', 'partial'])->where('i.balance_due', '>', 0)->whereNull('f.deleted_at')
            ->where('g.autopay_enabled', 1)->whereNotNull('g.autopay_payment_method_id')->whereNotNull('g.stripe_customer_id');
        if ($agencyId) $q->where('c.agency_id', $agencyId);
        $out = collect();
        foreach ($q->orderBy('f.family_name')->orderBy('i.due_at')->get(['i.id', 'i.invoice_number', 'i.due_at', 'i.status', 'i.family_id', 'c.agency_id', 'c.name as centre_name',
            'f.family_name', 'g.id as gid', 'g.stripe_customer_id', 'g.autopay_payment_method_id', 'g.autopay_card_brand', 'g.autopay_card_last4']) as $r) {
            $inv = DB::table('invoices')->where('id', $r->id)->first();
            $mine = $inv ? BillingSplit::shareFor($inv, (int) $r->gid) : null;
            if (! $mine || $mine['balance'] <= 0.004) continue;
            $r->balance_due = $mine['balance'];
            $r->payer_guardian_id = (int) $r->gid;
            $r->invoice_number = SplitInvoiceCopy::numberFor($inv, (int) $r->gid) ?: $r->invoice_number;
            $r->family_name = $r->family_name . ' · ' . $mine['name'];
            $out->push($r);
        }
        return $out;
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
        $candidates = self::candidates($agencyId)->concat(self::guardianCandidates($agencyId))->values();
        if ($only !== null) $candidates = $candidates->whereIn('id', array_map('intval', $only))->values();

        $results = [];
        $ok = 0; $failed = 0; $charged = 0.0;
        /* THE AGENCY'S OWN STRIPE (2026-11-05, AgencyStripe): each invoice is charged on its centre's account, where
           the family's card was saved. KiddieTrac's .env key (StripeConfig) is only for KiddieTrac's own billing. */
        $clients = [];
        $usedAny = false;

        foreach ($candidates as $c) {
            $row = ['invoice_id' => (int) $c->id, 'invoice_number' => $c->invoice_number, 'family' => $c->family_name,
                'amount' => round((float) $c->balance_due, 2), 'card' => trim(($c->autopay_card_brand ?? '') . ' ' . ($c->autopay_card_last4 ? '•' . $c->autopay_card_last4 : ''))];
            $aid = (int) $c->agency_id;
            if (! array_key_exists($aid, $clients)) $clients[$aid] = AgencyStripe::client($aid);
            $client = $clients[$aid];
            if (! $client) {
                $results[] = $row + ['result' => 'skipped', 'message' => AgencyStripe::NOT_SET];
                continue;
            }
            $usedAny = true;
            $cents = (int) round(((float) $c->balance_due) * 100);
            if ($cents <= 0) continue;
            try {
                $pi = $client->paymentIntents->create([
                    'amount' => $cents,
                    'currency' => AgencyStripe::currency($aid),
                    'customer' => $c->stripe_customer_id,
                    'payment_method' => $c->autopay_payment_method_id,
                    'off_session' => true,
                    'confirm' => true,
                    'metadata' => ['invoice_id' => (string) $c->id, 'family_id' => (string) $c->family_id, 'agency_id' => (string) $c->agency_id, 'source' => 'autopay-' . $source,
                        'payer_guardian_id' => (string) ($c->payer_guardian_id ?? '')],
                ], ['idempotency_key' => 'autopay-' . (! empty($c->payer_guardian_id) ? 'g' . $c->payer_guardian_id . '-' : '') . $c->id . '-' . $cents . '-' . now()->format('Y-m-d')]);   // tz-ok: a dedupe key, deliberately on the server clock

                if (in_array($pi->status, ['succeeded', 'requires_capture'], true)) {
                    if ($pi->status === 'succeeded') self::record((int) $c->id, (int) $c->family_id, $pi->id, ((int) $pi->amount_received) / 100, ! empty($c->payer_guardian_id) ? (int) $c->payer_guardian_id : null);
                    $ok++; $charged += $cents / 100;
                    $results[] = $row + ['result' => 'charged', 'message' => $pi->id];
                } else {
                    $failed++;
                    $results[] = $row + ['result' => 'failed', 'message' => 'Card needs attention (' . $pi->status . ')'];
                    PaymentAlerts::failed($c, (string) $pi->status);
                    PaidMail::failed($c, (string) $pi->status);   // ...and the payer, by email (2026-11-06)
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('autopay charge failed', ['inv' => $c->id, 'msg' => $e->getMessage()]);
                $results[] = $row + ['result' => 'failed', 'message' => $e->getMessage()];
                try {
                    Notify::write([
                        'user_id' => ! empty($c->payer_guardian_id) ? DB::table('guardians')->where('id', $c->payer_guardian_id)->value('user_id')
                            : DB::table('guardians')->where('family_id', $c->family_id)->where('is_primary', 1)->value('user_id'),
                        'type' => 'payment_failed',
                        'title' => 'Auto-pay failed on invoice ' . $c->invoice_number,
                        'body' => 'Please update your saved card. ' . $e->getMessage(),
                        'data' => json_encode(['invoice_id' => $c->id, 'link' => '#billing']),
                        'created_at' => now(),
                    ]);
                } catch (\Throwable $e2) {
                }
                // ...and the office (App\Support\PaymentAlerts, 2026-11-05).
                PaymentAlerts::failed($c, $e->getMessage());
                PaidMail::failed($c, $e->getMessage());   // ...and the payer, by email (2026-11-06)
            }
        }

        $skipped = count(array_filter($results, fn ($r) => $r['result'] === 'skipped'));
        $summary = ['candidates' => count($results), 'succeeded' => $ok, 'failed' => $failed, 'skipped' => $skipped, 'charged' => round($charged, 2)];
        $runId = null;
        if ($log && ($agencyId || count($results))) {
            $runId = DB::table('payment_runs')->insertGetId([
                'agency_id' => $agencyId, 'started_by' => $userId, 'source' => $source,
                'status' => ! $usedAny && ! ($agencyId && AgencyStripe::client($agencyId)) ? 'no_provider' : ($failed ? ($ok ? 'partial' : 'failed') : 'completed'),
                'candidates' => $summary['candidates'], 'succeeded' => $ok, 'failed' => $failed, 'skipped' => $skipped,
                'total_charged' => $summary['charged'], 'results' => json_encode($results, JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $provider = $usedAny || ($agencyId && AgencyStripe::client($agencyId)) ? 'stripe' : null;

        return ['run_id' => $runId, 'provider' => $provider, 'summary' => $summary, 'results' => $results];
    }

    /** Write the payment for a succeeded charge, once (the webhook may also arrive). */
    public static function record(int $invoiceId, ?int $familyId, string $piId, float $amount, ?int $payerGuardianId = null, ?string $notes = null): void
    {
        // $notes (2026-11-03): "Apple Pay" / "Google Pay" for a wallet payment (WalletPayController); auto-pay otherwise.
        /* MORE THAN WAS OWED (2026-11-05, Stripe end-to-end): two taps or two phones each made a charge before either was
           recorded, and a $50 invoice took $100 and read "paid". Only what is still owed (the payer's own share on a
           split invoice; nothing on a void or refunded one) is applied; the rest is refunded to the same card at once
           (Anthony: "Refund it automatically"). The payment row keeps the full charge so it matches Stripe. */
        $pid = null; $applied = 0.0; $excess = 0.0; $wasOpen = true;
        DB::transaction(function () use ($invoiceId, $familyId, $piId, $amount, $payerGuardianId, $notes, &$pid, &$applied, &$excess, &$wasOpen) {
            if (DB::table('payments')->where('stripe_payment_id', $piId)->lockForUpdate()->exists()) return;
            $inv = DB::table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            if (! $inv) return;
            $wasOpen = ! in_array($inv->status, ['void', 'draft', 'refunded'], true);
            $open = $wasOpen ? max(0.0, (float) $inv->balance_due) : 0.0;
            if ($payerGuardianId && $open > 0 && ($share = BillingSplit::shareFor($inv, $payerGuardianId))) {
                $open = min($open, max(0.0, (float) $share['balance']));
            }
            $applied = round(min($amount, $open), 2);
            $excess = round($amount - $applied, 2);
            $pid = DB::table('payments')->insertGetId([
                'invoice_id' => $invoiceId, 'family_id' => $familyId ?: $inv->family_id, 'payer_guardian_id' => $payerGuardianId, 'amount' => $amount,
                'method' => 'stripe_card', 'status' => 'succeeded', 'stripe_payment_id' => $piId,
                'paid_at' => now(), 'notes' => $notes ?: 'Auto-pay', 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($applied > 0) {
                $newBal = max(0, round((float) $inv->balance_due - $applied, 2));
                DB::table('invoices')->where('id', $invoiceId)->update([
                    'balance_due' => $newBal, 'amount_paid' => round((float) $inv->amount_paid + $applied, 2),
                    'status' => $newBal <= 0.01 ? 'paid' : 'partial', 'updated_at' => now(),
                ]);
            }
        });
        if ($pid && $excess >= 0.01) {
            $why = ! $wasOpen ? 'the invoice had been cancelled'
                : ($applied <= 0 ? 'it was already paid' : 'it was more than was owed');
            AgencyStripe::refundExcess((int) $pid, $piId, $excess, AgencyStripe::agencyForInvoice($invoiceId), $payerGuardianId, $why);
        }
        // Receipt (and the invoice stamped PAID once settled) to the payer; once per payment (App\Support\PaidMail).
        if ($pid && $applied > 0) \App\Support\PaidMail::afterPayment((int) $pid);
        // The agency's admins and the centre's director: "💳 $X received" (App\Support\PaymentAlerts, 2026-11-05).
        if ($pid && $applied > 0) PaymentAlerts::received((int) $pid);
    }
}
