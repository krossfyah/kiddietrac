<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Issue the invoices a payment schedule raised, on the first of their own month.
 *
 * A schedule agreed in September must not drop six invoices into a family's account
 * today for months that have not started. So PaymentPlanController raises each
 * instalment's invoice as a DRAFT with issued_at set to the first day of the month it
 * falls due in, and this command flips it to 'sent' once that day arrives.
 *
 * WHY DRAFT IS SAFE TO LEAVE SITTING. Nothing in the platform reads a draft as owed —
 * isOpen() excludes it, so it is absent from the family's balance, from Outstanding on
 * the account ledger, and from every reminder — right up until the moment it is issued.
 *
 * IT ONLY EVER TOUCHES WHAT A SCHEDULE RAISED. The invoice must be reachable through
 * payment_plan_installments.invoice_id and belong to a schedule that is still active.
 * A draft somebody is composing by hand elsewhere in the portal is not this command's
 * business, and a cancelled schedule's drafts stay cancelled.
 *
 * IDEMPOTENT. It selects only status='draft', and the update moves them off 'draft',
 * so a second run in the same day finds nothing. A missed day catches up on the next
 * run rather than skipping the month — the condition is "issue date has passed", not
 * "issue date is today".
 */
final class IssueScheduledInvoices extends Command
{
    protected $signature = 'invoices:issue-scheduled {--dry-run : List what would be issued and change nothing}';

    protected $description = 'Issue payment-schedule invoices whose first-of-the-month has arrived';

    public function handle(): int
    {
        $today = now()->toDateString();
        $dry = (bool) $this->option('dry-run');

        $rows = DB::table('invoices as i')
            ->join('payment_plan_installments as pi', 'pi.invoice_id', '=', 'i.id')
            ->join('payment_plans as p', 'p.id', '=', 'pi.payment_plan_id')
            ->where('i.status', 'draft')
            ->whereDate('i.issued_at', '<=', $today)
            ->where('p.status', 'active')
            ->whereNotIn('pi.status', ['cancelled'])
            ->get(['i.id', 'i.invoice_number', 'i.family_id', 'i.centre_id', 'i.total', 'i.issued_at', 'i.due_at', 'p.id as plan_id']);

        if ($rows->isEmpty()) {
            $this->info('Nothing to issue.');

            return self::SUCCESS;
        }

        $this->info($rows->count() . ' invoice(s) due to be issued as at ' . $today . ($dry ? ' (dry run)' : ''));

        $issued = 0;
        foreach ($rows as $r) {
            $this->line(sprintf('  %-24s family %-5d %10s  issue %s  due %s',
                $r->invoice_number, $r->family_id, number_format((float) $r->total, 2), $r->issued_at, $r->due_at));

            if ($dry) {
                continue;
            }

            try {
                /* Guarded on status again inside the write: two workers, or a run that
                   overlaps the previous one, must not issue the same invoice twice. */
                $ok = DB::table('invoices')->where('id', $r->id)->where('status', 'draft')
                    ->update(['status' => 'sent', 'updated_at' => now()]);
                if (! $ok) {
                    continue;
                }
                $issued++;

                $agencyId = DB::table('centres')->where('id', $r->centre_id)->value('agency_id');
                Audit::write([
                    'agency_id' => $agencyId,
                    'action' => 'invoice.issued_from_schedule',
                    'entity_type' => 'invoice',
                    'entity_id' => $r->id,
                    'payload' => json_encode([
                        'invoice_number' => $r->invoice_number,
                        'family_id' => $r->family_id,
                        'payment_plan_id' => $r->plan_id,
                        'total' => (float) $r->total,
                        'due_at' => $r->due_at,
                        'summary' => 'Issued ' . $r->invoice_number . ' ($' . number_format((float) $r->total, 2)
                            . ') from payment schedule #' . $r->plan_id . ' — its month has begun.',
                    ]),
                    'created_at' => now(),
                ]);
            } catch (Throwable $e) {
                report($e);
                $this->warn('  ! ' . $r->invoice_number . ': ' . $e->getMessage());
            }
        }

        $this->info($dry ? 'Dry run — nothing changed.' : ($issued . ' issued.'));

        return self::SUCCESS;
    }
}
