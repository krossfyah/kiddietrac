<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AutopayRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * v22p51 — Auto-charge unpaid invoices for families with autopay enabled.
 * Scheduled daily at 03:00. The charging lives in AutopayRunner (2026-09-29), shared with
 * Finance -> Payment run; each agency's nightly run is logged in payment_runs, and a Stripe
 * idempotency key per invoice/amount/day stops a same-day manual run charging twice.
 */
final class AutopayChargeCommand extends Command
{
    protected $signature = 'invoices:autopay-charge {--dry-run}';
    protected $description = 'Charge stored cards for invoices on families with autopay enabled';

    public function handle(): int
    {
        $candidates = AutopayRunner::candidates();
        $this->info("Found {$candidates->count()} autopay candidate(s)");
        if ($this->option('dry-run')) {
            foreach ($candidates as $c) {
                $this->line(" - inv #{$c->invoice_number} ({$c->family_name}) {$c->balance_due}");
            }
            return 0;
        }
        if (! \App\Support\StripeConfig::configured()) { $this->warn('STRIPE_SECRET not set; skipping'); return 0; }

        foreach ($candidates->pluck('agency_id')->unique() as $agencyId) {
            $r = AutopayRunner::run((int) $agencyId, 'nightly');
            $s = $r['summary'];
            $this->info("Agency {$agencyId}: {$s['succeeded']} succeeded, {$s['failed']} failed, \${$s['charged']} charged");
        }
        return 0;
    }
}
