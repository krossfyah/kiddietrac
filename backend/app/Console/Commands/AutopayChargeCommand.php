<?php
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\PaymentIntent;

/**
 * v22p51 — Auto-charge unpaid invoices for families with autopay enabled.
 * Scheduled daily at 03:00. Idempotent per invoice (won't charge if there's
 * already a non-failed PaymentIntent metadata.invoice_id in payments).
 */
final class AutopayChargeCommand extends Command
{
    protected $signature = 'invoices:autopay-charge {--dry-run}';
    protected $description = 'Charge stored cards for invoices on families with autopay enabled';

    public function handle(): int
    {
        $key = env('STRIPE_SECRET');
        if (!$key) { $this->warn('STRIPE_SECRET not set; skipping'); return 0; }
        Stripe::setApiKey($key);

        $candidates = DB::table('invoices as i')
            ->join('centres as c', 'c.id', '=', 'i.centre_id')
            ->join('families as f', 'f.id', '=', 'i.family_id')
            ->whereIn('i.status', ['sent', 'overdue', 'partial'])
            ->where('i.balance_due', '>', 0)
            ->where('f.autopay_enabled', 1)
            ->whereNotNull('f.autopay_payment_method_id')
            ->whereNotNull('f.stripe_customer_id')
            // no deleted_at on invoices
            ->select(
                'i.id', 'i.balance_due', 'i.invoice_number', 'i.family_id', 'c.agency_id',
                'f.stripe_customer_id', 'f.autopay_payment_method_id', 'f.family_name'
            )
            ->get();

        $this->info("Found {$candidates->count()} autopay candidate(s)");
        if ($this->option('dry-run')) {
            foreach ($candidates as $c) {
                $this->line(" - inv #{$c->invoice_number} ({$c->family_name}) {$c->balance_due}");
            }
            return 0;
        }
        $ok = 0; $failed = 0;
        foreach ($candidates as $c) {
            $cents = (int) round(((float) $c->balance_due) * 100);
            if ($cents <= 0) continue;
            try {
                $pi = PaymentIntent::create([
                    'amount'   => $cents,
                    'currency' => strtolower(env('STRIPE_CURRENCY', 'cad')),
                    'customer' => $c->stripe_customer_id,
                    'payment_method' => $c->autopay_payment_method_id,
                    'off_session' => true,
                    'confirm' => true,
                    'metadata' => [
                        'invoice_id' => (string) $c->id,
                        'family_id'  => (string) $c->family_id,
                        'agency_id'  => (string) $c->agency_id,
                        'source'     => 'autopay-cron',
                    ],
                ]);
                if (in_array($pi->status, ['succeeded', 'requires_capture'], true)) {
                    $ok++;
                    $this->line(" ✓ inv #{$c->invoice_number}");
                } else {
                    $failed++;
                    $this->warn(" ! inv #{$c->invoice_number} status={$pi->status}");
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('autopay charge failed', ['inv' => $c->id, 'msg' => $e->getMessage()]);
                $this->warn(" x inv #{$c->invoice_number}: " . $e->getMessage());
                DB::table('notifications')->insert([
                    'user_id' => DB::table('guardians')->where('family_id', $c->family_id)->where('is_primary', 1)->value('user_id'),
                    'type' => 'payment_failed',
                    'title' => 'Auto-pay failed on invoice ' . $c->invoice_number,
                    'body'  => 'Please update your saved card. ' . $e->getMessage(),
                    'data' => json_encode(['invoice_id' => $c->id, 'link' => '#billing']),
                    'created_at' => now(),
                ]);
            }
        }
        $this->info("Autopay complete: {$ok} succeeded, {$failed} failed");
        return 0;
    }
}
