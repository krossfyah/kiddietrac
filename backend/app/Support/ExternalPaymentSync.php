<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A PAYMENT A PARTNER RECORDED, AGAINST A KIDDIETRAC INVOICE (2026-11-02).
 *
 * Anthony: "iL-INV-202610-0136-02 (invoice) was marked as paid on ilearn - based on invoices marked paid, can you
 * autosync to kiddietrac (full sweep)".
 *
 * Some iLearn families are billed THROUGH KiddieTrac: their invoices are native here (iL-INV-202610-0136-01) and
 * they pay by Interac e-Transfer, quoting that number, into iLearn's inbox. iLearn records the money and, because
 * its approval needs an invoice, auto-creates one of its own ("Auto-created from Interac e-Transfer"), which then
 * arrives here as an external invoice marked paid. The KiddieTrac invoice the parent actually paid was never told:
 * it stayed "sent" with its full balance, next to a second, paid copy of the same money.
 *
 * iLearn now sends, with each payment, the KiddieTrac invoice number(s) it names (`applies_to`, read from the
 * e-Transfer memo or the payment notes). This class mirrors such a payment onto that invoice:
 *
 *   - IDEMPOTENT. A mirror row is keyed (external_source, external_id = payment-{partner id}, external_ref, invoice_id), so a
 *     re-push updates it and never counts the money twice. A payment the partner deletes, fully refunds or unlinks
 *     is withdrawn on the next push of the invoice it was pushed with (external_ref).
 *   - SAME FAMILY ONLY. The invoice number is looked up inside the one family the partner invoice is filed under,
 *     never across families or agencies. An unknown number is reported, not guessed.
 *   - NEVER A GUESS ON AMOUNTS. One invoice named: applied only if the payment fits its balance. Several named:
 *     applied only if the payment equals their balances exactly. Anything else is reported back as a flag.
 *   - NO SIDE EFFECTS. This is a record of money that already moved somewhere else. It never charges (no
 *     AutopayRunner, Stripe, Zum or Helcim). apply() itself sends nothing; since 2026-11-02 the caller hands
 *     the payment rows it CREATED (new_payment_ids) to receipt(), which sends the manual "Record payment" receipt
 *     and paid-in-full notice once per partner payment, ever (see receipt()). Status and balance are recomputed from the payment rows, the
 *     same sum InvoiceController::recordPayment uses, so a paid invoice drops out of autopay runs and reminders
 *     because its balance is 0.
 */
final class ExternalPaymentSync
{
    /**
     * @param  array<int, array<string,mixed>>  $payments  [{id, date, amount (net), method, reference, applies_to[]}]
     * @return array{applied: list<array>, flags: list<array>, withdrawn: int, shell_invoice_id: ?int, shell_number: ?string}
     */
    public static function apply(
        int $agencyId,
        string $source,
        int $familyId,
        string $externalInvoiceId,
        array $payments,
        bool $active,
        bool $autoCreated,
        float $externalTotal,
        string $label,
        ?int $byUserId
    ): array {
        $out = ['applied' => [], 'flags' => [], 'withdrawn' => 0, 'shell_invoice_id' => null, 'shell_number' => null, 'new_payment_ids' => []];

        $out = DB::transaction(function () use ($agencyId, $source, $familyId, $externalInvoiceId, $payments, $active, $autoCreated, $externalTotal, $label, $byUserId, $out) {
            $existing = DB::table('payments')->where('external_source', $source)
                ->where('external_ref', $externalInvoiceId)->lockForUpdate()->get();

            $desired = [];        // key "pid|invoiceId" => row
            $netAll = 0.0;
            $netApplied = 0.0;
            $firstNative = null;

            foreach ($active ? $payments : [] as $p) {
                $pid = trim((string) ($p['id'] ?? ''));
                $net = round((float) ($p['amount'] ?? 0), 2);
                if ($net <= 0.004) continue;
                $netAll += $net;
                $refs = array_values(array_unique(array_filter(array_map(
                    fn ($n) => strtoupper(trim((string) $n)), (array) ($p['applies_to'] ?? [])
                ))));
                if ($pid === '' || ! $refs) continue;      // an ordinary partner payment: stays on the external invoice
                /* The partner applied it to a real bill of its own, not a receipt shell: mirroring it as well would
                   count the money twice, and which of the two bills is the right one is a person's call. */
                if (! $autoCreated) {
                    $out['flags'][] = ['payment' => $pid, 'amount' => $net, 'applies_to' => $refs,
                        'reason' => 'names ' . implode(', ', $refs) . ' but was applied to its own invoice there, ' . $externalInvoiceId];
                    continue;
                }

                $natives = DB::table('invoices')->where('family_id', $familyId)
                    ->whereIn(DB::raw('UPPER(invoice_number)'), $refs)
                    ->orderBy('due_at')->orderBy('id')->lockForUpdate()->get();
                $usable = $natives->reject(fn ($n) => strtolower((string) $n->status) === 'void')->values();
                if ($usable->count() !== count($refs)) {
                    $known = $natives->pluck('invoice_number')->map(fn ($x) => strtoupper((string) $x))->all();
                    $out['flags'][] = ['payment' => $pid, 'amount' => $net, 'applies_to' => $refs,
                        'reason' => $natives->count() !== count($refs)
                            ? 'no invoice ' . implode(', ', array_diff($refs, $known)) . ' in this family'
                            : 'the invoice named is void'];
                    continue;
                }

                $extKey = 'payment-' . $pid;
                $remaining = [];
                foreach ($usable as $n) {
                    $paidOthers = (float) DB::table('payments')->where('invoice_id', $n->id)->where('status', 'succeeded')
                        ->whereNot(fn ($q) => $q->where('external_source', $source)->where('external_id', $extKey)
                            ->where('external_ref', $externalInvoiceId))
                        ->sum('amount');
                    $remaining[$n->id] = max(0.0, round((float) $n->total - $paidOthers, 2));
                }

                $split = [];
                if ($usable->count() === 1) {
                    $n = $usable[0];
                    if ($net <= $remaining[$n->id] + 0.01) {
                        $split[$n->id] = $net;
                    } else {
                        $out['flags'][] = ['payment' => $pid, 'amount' => $net, 'applies_to' => $refs,
                            'reason' => 'payment $' . number_format($net, 2) . ' is more than the $'
                                . number_format($remaining[$n->id], 2) . ' left on ' . $n->invoice_number];
                        continue;
                    }
                } else {
                    $sum = round(array_sum($remaining), 2);
                    if (abs($sum - $net) <= 0.01) {
                        foreach ($usable as $n) if ($remaining[$n->id] > 0.004) $split[$n->id] = $remaining[$n->id];
                    } else {
                        $out['flags'][] = ['payment' => $pid, 'amount' => $net, 'applies_to' => $refs,
                            'reason' => 'payment $' . number_format($net, 2) . ' does not equal the $' . number_format($sum, 2)
                                . ' left on the ' . count($refs) . ' invoices it names'];
                        continue;
                    }
                }

                $method = self::method((string) ($p['method'] ?? ''));
                $ref = trim((string) ($p['reference'] ?? '')) ?: null;
                $notes = 'Paid in ' . $label . ' · ' . (trim((string) ($p['method'] ?? '')) ?: 'payment')
                    . ($ref ? ' · ref ' . $ref : '');
                $refunded = round((float) ($p['refunded'] ?? 0), 2);
                if ($refunded > 0.004) $notes .= ' · $' . number_format($refunded, 2) . ' refunded in ' . $label;
                $date = ! empty($p['date']) ? substr((string) $p['date'], 0, 10) : null;

                foreach ($split as $invId => $amt) {
                    $n = $usable->firstWhere('id', $invId);
                    $firstNative ??= $n;
                    $netApplied += $amt;
                    $desired[$pid . '|' . $invId] = [
                        'invoice_id' => (int) $invId, 'family_id' => $familyId, 'amount' => round($amt, 2),
                        'method' => $method, 'status' => 'succeeded', 'reference_number' => $ref ? mb_substr($ref, 0, 120) : null,
                        // A calendar day from the partner; noon keeps it on that day in every agency timezone.
                        'paid_at' => $date ? $date . ' 12:00:00' : now(),
                        'notes' => $notes, 'external_source' => $source, 'external_id' => $extKey,
                        'external_ref' => $externalInvoiceId,
                        '_number' => $n->invoice_number, '_pid' => $pid,
                    ];
                }
            }

            $touched = [];
            $byKey = [];
            foreach ($existing as $row) {
                $byKey[substr((string) $row->external_id, 8) . '|' . $row->invoice_id] = $row;
            }

            foreach ($byKey as $key => $row) {
                if (isset($desired[$key])) continue;
                DB::table('payments')->where('id', $row->id)->delete();
                $touched[(int) $row->invoice_id] = true;
                $out['withdrawn']++;
                self::audit($agencyId, 'payment.external_withdrawn', (int) $row->invoice_id, [
                    'external_id' => $row->external_id, 'external_ref' => $externalInvoiceId, 'amount' => (float) $row->amount,
                    'summary' => 'Removed a $' . number_format((float) $row->amount, 2) . ' payment mirrored from ' . $source
                        . ' — it is no longer recorded there against this invoice.',
                ]);
            }

            foreach ($desired as $key => $d) {
                $number = $d['_number'];
                $pid = $d['_pid'];
                unset($d['_number'], $d['_pid']);
                $row = $byKey[$key] ?? null;
                if ($row) {
                    $changed = abs((float) $row->amount - $d['amount']) > 0.004 || (string) $row->notes !== $d['notes']
                        || (string) $row->reference_number !== (string) $d['reference_number'] || (string) $row->method !== $d['method']
                        || substr((string) $row->paid_at, 0, 10) !== substr((string) $d['paid_at'], 0, 10);
                    if ($changed) {
                        DB::table('payments')->where('id', $row->id)->update($d + ['updated_at' => now()]);
                    }
                } else {
                    $out['new_payment_ids'][] = (int) DB::table('payments')->insertGetId($d + ['recorded_by_id' => $byUserId, 'created_at' => now(), 'updated_at' => now()]);
                    self::audit($agencyId, 'payment.external_applied', (int) $d['invoice_id'], [
                        'external_id' => $d['external_id'], 'external_ref' => $externalInvoiceId, 'amount' => $d['amount'],
                        'reference' => $d['reference_number'],
                        'summary' => $d['notes'] . ' — $' . number_format($d['amount'], 2) . ' applied to ' . $number,
                    ]);
                }
                $touched[(int) $d['invoice_id']] = true;
                $out['applied'][] = ['payment' => $pid, 'invoice_id' => (int) $d['invoice_id'], 'number' => $number, 'amount' => $d['amount']];
            }

            foreach (array_keys($touched) as $invId) {
                self::recompute((int) $invId);
            }

            /* A RECEIPT SHELL. The partner made this invoice only to hold the e-Transfer (its approval needs one); every
               cent of it now sits on KiddieTrac invoices. Shown as well, the family's statement would bill the same
               money twice. Voided, which every list, ledger, reminder and report already reads as "not a bill". */
            if ($active && $autoCreated && $firstNative && $netAll > 0.004
                && abs($netApplied - $netAll) <= 0.01 && abs($netAll - $externalTotal) <= 0.01) {
                $out['shell_invoice_id'] = (int) $firstNative->id;
                $out['shell_number'] = (string) $firstNative->invoice_number;
            }

            return $out;
        });

        return $out;
    }

    /**
     * THE RECEIPT FOR A PAYMENT RECORDED IN THE PARTNER (2026-11-02, Anthony: "Send the receipt").
     *
     * Exactly what a manual "Record payment" sends - the "paid in full" bell notice to the family's guardians
     * (PaidMail::paidInFullNotice, the code recordPayment uses) and the PaidMail receipt email to the payer - and only
     * ONCE per partner payment, ever:
     *   - only for a mirror row created for the first time in this push (a re-push updates, never inserts);
     *   - external_payment_receipts is claimed first, keyed by (source, partner payment id) and kept even if the
     *     mirror row is later withdrawn, so a reversal and re-apply finds it claimed and stays silent. The payments
     *     applied before receipts were switched on were claimed by the migration as 'backfill' and never receipt;
     *   - payments.receipt_sent_at is stamped on the row.
     * Only native family invoices are ever mirrored onto, never the voided partner copy or a provider/contractor bill.
     */
    public static function receipt(int $paymentId, bool $dryRun = false, array &$claimedNow = []): array
    {
        $p = DB::table('payments')->where('id', $paymentId)->first();
        if (! $p || ! $p->external_source || ! $p->invoice_id || $p->receipt_sent_at || (string) $p->status !== 'succeeded') {
            return ['payment' => $paymentId, 'send' => false, 'why' => 'not a fresh mirrored payment'];
        }
        $claimed = DB::table('external_payment_receipts')->where('external_source', $p->external_source)
            ->where('external_id', $p->external_id)->first();
        $key = $p->external_source . '|' . $p->external_id;
        $ours = isset($claimedNow[$key]);   // one partner payment split over several invoices in this same push
        if ($claimed && ! $ours) {
            return ['payment' => $paymentId, 'send' => false, 'why' => 'already ' . $claimed->outcome . ' for ' . $p->external_id];
        }
        $inv = DB::table('invoices')->where('id', $p->invoice_id)->first();
        if (! $inv || ! $inv->family_id || strtolower((string) $inv->status) === 'void') {
            return ['payment' => $paymentId, 'send' => false, 'why' => 'not a family invoice'];
        }
        if ($dryRun) {
            return ['payment' => $paymentId, 'send' => true, 'why' => 'would send (dry run)'];
        }
        $won = $ours || DB::table('external_payment_receipts')->insertOrIgnore([
            'external_source' => $p->external_source, 'external_id' => $p->external_id,
            'payment_id' => $paymentId, 'outcome' => 'sent', 'created_at' => now(),
        ]);
        if (! $won) {
            return ['payment' => $paymentId, 'send' => false, 'why' => 'claimed by a concurrent push'];
        }
        $claimedNow[$key] = true;
        $inv = DB::table('invoices')->where('id', $p->invoice_id)->first();   // re-read: status after recompute
        if ($inv && strtolower((string) $inv->status) === 'paid') {
            try {
                PaidMail::paidInFullNotice($inv, (int) $inv->id, null);
            } catch (Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('external payment notice failed', ['payment' => $paymentId, 'error' => $e->getMessage()]);
            }
        }
        PaidMail::afterPayment($paymentId);          // never throws; dedupes per address in payment_paid_mails
        PaymentAlerts::received((int) $paymentId);   // the office, once per payment (2026-11-05)
        DB::table('payments')->where('id', $paymentId)->update(['receipt_sent_at' => now()]);

        return ['payment' => $paymentId, 'send' => true, 'why' => 'sent'];
    }

    /**
     * amount_paid / balance_due / status from the payment rows - the same sum InvoiceController::recordPayment uses.
     * Writes nothing else: no notification, no email, no charge.
     */
    public static function recompute(int $invoiceId): void
    {
        $inv = DB::table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();
        if (! $inv || strtolower((string) $inv->status) === 'void') return;
        $paid = round((float) DB::table('payments')->where('invoice_id', $invoiceId)->where('status', 'succeeded')->sum('amount'), 2);
        $balance = max(0.0, round((float) $inv->total - $paid, 2));
        $cur = strtolower((string) $inv->status);
        $status = match (true) {
            $paid > 0.004 && $balance <= 0.01 => 'paid',
            $paid > 0.004 => 'partial',
            in_array($cur, ['paid', 'partial'], true) => 'sent',   // the money that settled it was withdrawn
            default => $inv->status,
        };
        DB::table('invoices')->where('id', $invoiceId)->update([
            'amount_paid' => $paid, 'balance_due' => $balance, 'status' => $status, 'updated_at' => now(),
        ]);
    }

    private static function method(string $m): string
    {
        $k = strtolower($m);

        return match (true) {
            str_contains($k, 'interac') || str_contains($k, 'e-transfer') || str_contains($k, 'etransfer') => 'interac',
            str_contains($k, 'cash') => 'cash',
            str_contains($k, 'cheque') || str_contains($k, 'check') => 'cheque',
            str_contains($k, 'card') => 'card',
            str_contains($k, 'eft') || str_contains($k, 'bank') || str_contains($k, 'direct') => 'eft',
            default => 'manual',
        };
    }

    private static function audit(int $agencyId, string $action, int $invoiceId, array $payload): void
    {
        try {
            Audit::write([
                'agency_id' => $agencyId, 'action' => $action, 'entity_type' => 'invoice', 'entity_id' => $invoiceId,
                'payload' => json_encode($payload), 'created_at' => now(),
            ]);
        } catch (Throwable $e) { /* never fail a sync over its own audit row */ }
    }
}
