<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\PaymentProviders;
use App\Support\ZumRails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Does our money agree with itself, and with Zum? (2026-09-02)
 *
 * Written because of a real failure: a transaction read Completed at Zum and 'submitted'
 * here for hours, because their webhook could never be accepted. Nothing alerted; the
 * invoice simply stayed unpaid. Settlement arrives asynchronously, so a webhook that stops
 * being delivered is invisible by design — the only way to know is to ask.
 *
 * Four checks, each answering a question somebody would otherwise ask too late:
 *
 *   1. Do invoices.amount_paid and the payments ledger agree? They are written in the same
 *      transaction now, so a disagreement means something wrote around it.
 *   2. Did every settled payment reach the ledger? A payment the invoice knows about and
 *      the ledger does not is money missing from the receipts and the family statement.
 *   3. Does our status match Zum's? This is the webhook-delivery check. --fix settles the
 *      ones Zum says are complete, through the same path a webhook would take.
 *   4. Is any refund larger than the payment it belongs to?
 *
 * Read-only unless --fix. Reports nothing but problems, so a clean run is one line and can
 * be scheduled without becoming noise.
 */
class PaymentsReconcileCommand extends Command
{
    protected $signature = 'payments:reconcile
                            {--agency= : restrict to one agency}
                            {--days=90 : how far back to look}
                            {--fix : settle transactions Zum reports as complete}';

    protected $description = 'Check the payments ledger against the invoices and against Zum';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);
        $problems = 0;

        // ── 1. invoices vs the ledger ────────────────────────────────────────────
        $this->line('Invoices against the payments ledger…');
        $invoices = DB::table('invoices')->where('created_at', '>=', $since)
            ->when($this->option('agency'), function ($q) {
                $q->whereIn('centre_id', DB::table('centres')
                    ->where('agency_id', (int) $this->option('agency'))->pluck('id'));
            })
            ->get(['id', 'invoice_number', 'total', 'amount_paid', 'balance_due', 'status']);

        foreach ($invoices as $inv) {
            /* A VOID INVOICE IS NOT A DISCREPANCY. It carries no balance and has no
               payment because it was cancelled, not paid — the same rule isVoid() and
               stillDue() apply everywhere else. Comparing total-amount_paid against
               balance_due for one fails by construction, every run, forever, and three
               such rows were the entire output of this command on production.

               A DRAFT has not been issued, so it has nothing to reconcile against
               either.

               This is not cosmetic: a check that reports non-problems teaches whoever
               runs it to skim past the warnings, and the real one arrives in the same
               list as the noise. */
            if (in_array(strtolower(trim((string) $inv->status)), ['void', 'cancelled', 'draft'], true)) {
                continue;
            }

            /* Net of refunds, which is what amount_paid means. A refunded row keeps its
               amount and gains refunded_at — the ledger records gross takings and gives
               them back separately, so a naive SUM(amount) would never match. */
            $paid = (float) DB::table('payments')->where('invoice_id', $inv->id)
                ->where('status', '!=', 'failed')->sum('amount');
            $refunded = (float) DB::table('zum_refunds as r')
                ->join('zum_transactions as t', 't.id', '=', 'r.zum_transaction_id_local')
                ->where('t.invoice_id', $inv->id)->where('r.status', 'settled')->sum('r.amount');
            $net = round($paid - $refunded, 2);

            if (abs($net - (float) $inv->amount_paid) > 0.005) {
                $problems++;
                $this->warn(sprintf('  %-14s amount_paid=%.2f but ledger net=%.2f (paid %.2f, refunded %.2f)',
                    $inv->invoice_number, (float) $inv->amount_paid, $net, $paid, $refunded));
            }

            // A balance that does not follow from the total is its own problem.
            $expected = round((float) $inv->total - (float) $inv->amount_paid, 2);
            if (abs($expected - (float) $inv->balance_due) > 0.005 && $expected >= 0) {
                $problems++;
                $this->warn(sprintf('  %-14s balance_due=%.2f but total-paid=%.2f',
                    $inv->invoice_number, (float) $inv->balance_due, $expected));
            }
        }

        // ── 2. settled payments that never reached the ledger ────────────────────
        $this->line('Settled payments missing from the ledger…');
        foreach (DB::table('zum_transactions')->where('status', 'settled')
            ->where('direction', 'in')->whereNotNull('invoice_id')
            ->where('created_at', '>=', $since)->get() as $t) {
            $has = DB::table('payments')->where('reference_number', 'ZUM-' . $t->zum_transaction_id)->exists();
            if (! $has) {
                $problems++;
                $this->warn(sprintf('  payment #%d (%.2f, invoice %d) has no ledger row', $t->id, $t->amount, $t->invoice_id));
            }
        }

        // ── 3. our status against Zum's ──────────────────────────────────────────
        $this->line('Our status against Zum…');
        $drift = 0;
        $pending = DB::table('zum_transactions')
            ->whereIn('status', ['pending', 'submitted', 'cancelling', 'in_review'])
            ->whereNotNull('zum_transaction_id')
            ->where('created_at', '>=', $since)
            ->get();

        foreach ($pending as $t) {
            $agencyId = (int) ($t->agency_id ?: 0);
            if (! $agencyId || ! ZumRails::configured($agencyId)) {
                continue;
            }
            try {
                $cfg = PaymentProviders::config($agencyId, PaymentProviders::ZUM);
                $res = Http::withToken((string) ZumRails::token($agencyId))->timeout(20)->acceptJson()
                    ->get(rtrim((string) $cfg['base_url'], '/') . '/api/transaction/' . $t->zum_transaction_id);
                $theirs = (string) ($res->json()['result']['TransactionStatus'] ?? '');
            } catch (Throwable $e) {
                $this->error(sprintf('  could not ask Zum about #%d: %s', $t->id, $e->getMessage()));
                continue;
            }
            if ($theirs === '') {
                continue;
            }

            $ours = $t->status;
            $theirSettled = in_array(strtolower($theirs), ['completed', 'succeeded', 'success'], true);
            /* Terminal failures, which this check used to skip entirely. A charge
               that failed at Zum stayed 'submitted' here for ever and nothing said
               so — the drift most worth reporting, because that money is never
               arriving. Marked failed, never "settled": there is nothing to settle
               and the ledger must not be touched. */
            $theirDead = in_array(strtolower($theirs), ['failed', 'declined', 'rejected', 'cancelled', 'canceled'], true);

            if ($theirSettled && $ours !== 'settled') {
                $drift++;
                $problems++;
                $this->warn(sprintf('  payment #%d: Zum says %s, we say %s%s',
                    $t->id, $theirs, $ours, $this->option('fix') ? ' — settling' : ''));

                if ($this->option('fix')) {
                    $this->settle($t);
                }
            } elseif ($theirDead) {
                $drift++;
                $problems++;
                $this->warn(sprintf('  payment #%d: Zum says %s, we say %s%s',
                    $t->id, $theirs, $ours, $this->option('fix') ? ' — marking failed' : ''));

                if ($this->option('fix')) {
                    DB::table('zum_transactions')->where('id', $t->id)->update([
                        'status' => strtolower($theirs) === 'cancelled' || strtolower($theirs) === 'canceled'
                            ? 'cancelled' : 'failed',
                        'updated_at' => now(),
                    ]);
                    $this->info(sprintf('    marked #%d as %s', $t->id, strtolower($theirs)));
                }
            }
        }
        if ($drift > 0 && ! $this->option('fix')) {
            $this->line('  (run with --fix to settle or fail these; a persistent drift means the webhook is not being delivered)');
        }

        // ── 4. refunds larger than their payment ─────────────────────────────────
        $this->line('Refunds against their payments…');
        foreach (DB::table('zum_transactions')->where('refunded_amount', '>', 0)
            ->where('created_at', '>=', $since)->get() as $t) {
            if ((float) $t->refunded_amount > (float) $t->amount + 0.005) {
                $problems++;
                $this->error(sprintf('  payment #%d refunded %.2f of %.2f — MORE than was taken',
                    $t->id, (float) $t->refunded_amount, (float) $t->amount));
            }
        }

        $this->newLine();
        if ($problems === 0) {
            $this->info(sprintf('Clean: %d invoice(s) and %d in-flight payment(s) checked, nothing to report.',
                count($invoices), count($pending)));
        } else {
            $this->error($problems . ' problem(s) found.');
        }

        return self::SUCCESS;
    }

    /**
     * Settle a transaction Zum reports as complete.
     *
     * Goes through the same crediting logic the webhook uses rather than reimplementing it:
     * two code paths that both credit invoices is how they end up disagreeing.
     */
    private function settle(object $t): void
    {
        try {
            $cfg = PaymentProviders::config((int) $t->agency_id, PaymentProviders::ZUM);
            $body = json_encode([
                'Type' => 'Transaction',
                'Data' => ['Id' => $t->zum_transaction_id, 'TransactionStatus' => 'Completed'],
            ], JSON_UNESCAPED_SLASHES);

            $req = \Illuminate\Http\Request::create('/api/v1/zumrails/webhook', 'POST', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ZUMRAILS_SIGNATURE' => hash_hmac('sha256', $body, (string) $cfg['webhook_secret']),
            ], $body);

            app(\App\Http\Controllers\Api\ZumWebhookController::class)->handle($req);
            $this->info(sprintf('    settled #%d', $t->id));
        } catch (Throwable $e) {
            $this->error(sprintf('    could not settle #%d: %s', $t->id, $e->getMessage()));
        }
    }
}
