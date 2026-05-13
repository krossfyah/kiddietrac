<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * v13: Nightly autopay job.
 *
 * Cron: every day at 06:00
 *   0 6 * * * cd ~/kiddietrac/backend && php artisan kiddietrac:auto-charge-invoices >> ~/cron-autopay.log 2>&1
 *
 * For each invoice with status='sent' and balance_due > 0 where the family
 * has autopay_enabled, create an off-session PaymentIntent on the agency's
 * Connect account using the saved payment method.
 */
final class AutoChargeInvoices extends Command
{
    protected $signature = 'kiddietrac:auto-charge-invoices {--dry-run : List what would be charged without charging}';
    protected $description = 'Charge invoices for autopay-enabled families';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Looking for invoices to auto-charge…');

        $key = env('STRIPE_SECRET_KEY');
        if (! $key) {
            $this->error('STRIPE_SECRET_KEY not configured');
            return 1;
        }

        $candidates = DB::table('invoices')
            ->join('families', 'families.id', '=', 'invoices.family_id')
            ->where('families.autopay_enabled', true)
            ->whereNotNull('families.autopay_payment_method_id')
            ->whereIn('invoices.status', ['sent', 'partial', 'overdue'])
            ->where('invoices.balance_due', '>', 0)
            ->whereDate('invoices.due_at', '<=', now()->addDays(2)->toDateString())
            ->select(
                'invoices.id', 'invoices.invoice_number', 'invoices.balance_due',
                'invoices.family_id', 'invoices.centre_id',
                'families.stripe_customer_id', 'families.autopay_payment_method_id',
                'families.family_name'
            )
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Nothing to charge.');
            return 0;
        }

        $this->info('Found ' . $candidates->count() . ' invoice(s) to charge.');

        $charged = 0;
        $failed = 0;

        foreach ($candidates as $inv) {
            $centre = DB::table('centres')->where('id', $inv->centre_id)->first();
            $agency = DB::table('agencies')->where('id', $centre->agency_id)->first();
            $settings = json_decode($agency->settings ?? '{}', true) ?: [];
            $connectId = $settings['stripe']['connect_id'] ?? null;

            if (! $connectId) {
                $this->warn("  Invoice {$inv->invoice_number}: no Stripe Connect account; skipping");
                continue;
            }

            $amountCents = (int) round($inv->balance_due * 100);
            $feePct = (int) env('STRIPE_PLATFORM_FEE_PCT', 10);
            $feeCents = (int) round($amountCents * $feePct / 100);

            $this->line("  Invoice {$inv->invoice_number} → {$inv->family_name}: \${$inv->balance_due} CAD");

            if ($dryRun) {
                $charged++;
                continue;
            }

            try {
                $resp = Http::withBasicAuth($key, '')
                    ->withHeaders(['Stripe-Account' => $connectId])
                    ->asForm()
                    ->post('https://api.stripe.com/v1/payment_intents', [
                        'amount' => $amountCents,
                        'currency' => 'cad',
                        'customer' => $inv->stripe_customer_id,
                        'payment_method' => $inv->autopay_payment_method_id,
                        'off_session' => 'true',
                        'confirm' => 'true',
                        'application_fee_amount' => $feeCents,
                        'metadata[invoice_id]' => (string) $inv->id,
                        'metadata[family_id]' => (string) $inv->family_id,
                        'metadata[autopay]' => 'true',
                    ]);

                $body = $resp->json() ?: [];
                $pi = $body['id'] ?? null;
                $status = $body['status'] ?? 'unknown';

                DB::table('payments')->insert([
                    'invoice_id' => $inv->id,
                    'family_id' => $inv->family_id,
                    'amount' => $inv->balance_due,
                    'method' => 'stripe_card',
                    'status' => $status === 'succeeded' ? 'succeeded' : ($status === 'requires_action' ? 'pending' : 'failed'),
                    'stripe_payment_id' => $pi,
                    'paid_at' => $status === 'succeeded' ? now() : null,
                    'notes' => 'Autopay attempt',
                    'created_at' => now(),
                ]);

                if ($status === 'succeeded') {
                    $newPaid = $inv->balance_due;
                    DB::table('invoices')->where('id', $inv->id)->update([
                        'amount_paid' => DB::raw('amount_paid + ' . $inv->balance_due),
                        'balance_due' => 0,
                        'status' => 'paid',
                        'updated_at' => now(),
                    ]);
                    $this->info("    ✓ Charged \${$inv->balance_due}");
                    $charged++;
                } else {
                    $this->warn("    ✗ Status: $status");
                    $failed++;
                }
            } catch (\Throwable $e) {
                Log::error('Autopay charge failed', [
                    'invoice_id' => $inv->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("    ✗ Exception: " . $e->getMessage());
                $failed++;
            }
        }

        DB::table('audit_logs')->insert([
            'user_id' => null,
            'action' => 'autopay.batch_run',
            'entity_type' => 'system',
            'entity_id' => null,
            'payload' => json_encode([
                'attempted' => $candidates->count(),
                'charged' => $charged,
                'failed' => $failed,
                'dry_run' => $dryRun,
            ]),
            'created_at' => now(),
        ]);

        $this->info("\nSummary: charged=$charged failed=$failed total=" . $candidates->count());
        return 0;
    }
}
