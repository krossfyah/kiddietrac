<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p63 — Custom payment plans.
 * Director creates a plan: $1,200 split into 4 monthly installments
 * starting June 1. Each installment generates its own invoice line on
 * its due date (handled by a daily cron — for MVP we record metadata).
 */
final class PaymentPlanController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorizesTenantAccess;

    use ResolvesCentreContext;

    public function listForFamily(Request $request, int $familyId): JsonResponse
    {
        // SECURITY (v22p96): guardian / centre staff, or a platform_admin scoped
        // to the agency they've switched into (was a global platform bypass).
        abort_unless($this->canAccessFamilyScoped($request, $familyId), 403);
        $plans = DB::table('payment_plans')->where('family_id', $familyId)
            ->orderByDesc('created_at')->get();
        $planIds = $plans->pluck('id');
        /* The invoice each instalment raised travels with it, so the table can show a
           number and the cancel dialog can list exactly what it would withdraw —
           neither needing a second round trip. A LEFT join: an imported schedule
           raised no invoice, and null is the honest answer for it. */
        $installments = DB::table('payment_plan_installments as pi')
            ->leftJoin('invoices as i', 'i.id', '=', 'pi.invoice_id')
            ->whereIn('pi.payment_plan_id', $planIds)
            ->orderBy('pi.due_date')
            ->get([
                'pi.id', 'pi.payment_plan_id', 'pi.due_date', 'pi.amount', 'pi.status',
                'pi.invoice_id', 'pi.paid_at',
                'i.invoice_number', 'i.status as invoice_status',
                'i.issued_at as invoice_issues_on', 'i.balance_due as invoice_balance',
            ])
            ->groupBy('payment_plan_id');
        $plans->transform(function ($p) use ($installments) {
            $p->installments = $installments->get($p->id, collect());
            return $p;
        });
        return response()->json(['data' => $plans]);
    }

    public function create(Request $request): JsonResponse
    {
        /* Two shapes, on purpose.

           installments[] is what the schedule builder sends: the exact dates and
           amounts somebody reviewed and corrected. The older total/count/cadence trio
           still works for callers that have not moved, and is simply expanded into the
           same list below. */
        $data = $request->validate([
            'family_id' => 'required|integer',
            'notes' => 'nullable|string|max:1000',

            'installments' => 'nullable|array|min:1|max:60',
            'installments.*.due_date' => 'required_with:installments|date_format:Y-m-d',
            'installments.*.amount' => 'required_with:installments|numeric|min:0.01',

            'total_amount' => 'required_without:installments|numeric|min:0.01',
            'installment_count' => 'required_without:installments|integer|min:2|max:24',
            'first_due_date' => 'required_without:installments|date',
            'cadence' => 'required_without:installments|in:weekly,biweekly,monthly',
        ]);
        $this->assertStaff($request);

        /* SECURITY (2026-08-25): assertStaff() only proves the caller is staff SOMEWHERE
           — it never looked at family_id. Any director of any agency could therefore
           raise a payment plan, and its installments, against another agency's family
           and notify that family's guardians. Ownership of the family is the question
           this endpoint actually has to answer. Found by the re-parenting sweep. */
        $this->assertFamily((int) $request->user()->id, (int) $data['family_id']);

        /* One list either way, so everything below has a single shape to handle. */
        $lines = [];
        if (! empty($data['installments'])) {
            foreach ($data['installments'] as $row) {
                $lines[] = [
                    'due_date' => substr((string) $row['due_date'], 0, 10),
                    'amount' => round((float) $row['amount'], 2),
                ];
            }
            usort($lines, fn ($a, $b) => strcmp($a['due_date'], $b['due_date']));
        } else {
            $count = (int) $data['installment_count'];
            $per = round((float) $data['total_amount'] / $count, 2);
            $cursor = Carbon::parse($data['first_due_date']);
            for ($i = 1; $i <= $count; $i++) {
                // Last installment carries any rounding remainder.
                $lines[] = [
                    'due_date' => $cursor->toDateString(),
                    'amount' => $i === $count
                        ? round((float) $data['total_amount'] - $per * ($count - 1), 2)
                        : $per,
                ];
                $cursor = $cursor->copy()->add(match ($data['cadence']) {
                    'weekly' => '1 week', 'biweekly' => '2 weeks', 'monthly' => '1 month',
                });
            }
        }

        /* DERIVED, never accepted. A stated total that disagrees with the sum of its
           own instalments is a discrepancy waiting to be found by an accountant. */
        $total = round(array_sum(array_column($lines, 'amount')), 2);

        $family = DB::table('families')->where('id', $data['family_id'])->first(['id', 'centre_id']);
        abort_unless($family, 404, 'No such family.');

        $planId = DB::table('payment_plans')->insertGetId([
            'family_id' => $data['family_id'],
            'total_amount' => $total,
            'installment_count' => count($lines),
            'status' => 'active',
            'notes' => $data['notes'] ?? null,
            'created_by_user_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lines as $n => $line) {
            $invoiceId = $this->raiseScheduledInvoice(
                (int) $family->id, (int) $family->centre_id, $line, $planId, $n + 1, count($lines)
            );
            DB::table('payment_plan_installments')->insert([
                'payment_plan_id' => $planId,
                'due_date' => $line['due_date'],
                'amount' => $line['amount'],
                'invoice_id' => $invoiceId,
                'status' => 'pending',
                'created_at' => now(),
            ]);
        }

        $data['total_amount'] = $total;
        $data['installment_count'] = count($lines);
        $data['cadence'] = $data['cadence'] ?? 'scheduled';

        // Notify guardians
        $gids = DB::table('guardians')->where('family_id', $data['family_id'])->pluck('user_id');
        foreach ($gids as $gid) {
            DB::table('notifications')->insert([
                'user_id' => $gid, 'type' => 'payment_plan',
                'title' => 'Payment plan created',
                'body' => '$' . number_format((float) $data['total_amount'], 2) . ' over ' . $data['installment_count'] . ' ' . $data['cadence'] . ' installments',
                'data' => json_encode(['link' => '#payment-plans', 'plan_id' => $planId]),
                'created_at' => now(),
            ]);
        }

        return response()->json(['id' => $planId], 201);
    }

    /**
     * One instalment becomes one invoice, held as a DRAFT until the 1st of its month.
     *
     * A schedule that produces nothing is a note; the money has to become a document
     * the family can be sent and a payment can be matched against. But raising six
     * invoices the moment a schedule is agreed would drop six debts into an account
     * today for months that have not started — so issued_at is the first day of the
     * month the instalment falls due in, and the invoice waits at 'draft' until
     * invoices:issue-scheduled reaches that date.
     */
    private function raiseScheduledInvoice(int $familyId, int $centreId, array $line, int $planId, int $n, int $of): ?int
    {
        try {
            $due = Carbon::parse($line['due_date']);
            $issueOn = $due->copy()->startOfMonth();

            $number = 'INV-' . $issueOn->format('Ym') . '-' . str_pad((string) $familyId, 4, '0', STR_PAD_LEFT)
                . '-' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);

            $invoiceId = DB::table('invoices')->insertGetId([
                'centre_id' => $centreId,
                'family_id' => $familyId,
                'invoice_number' => $number,
                // period_start/period_end are NOT NULL; the billed month is the period.
                'period_start' => $issueOn->toDateString(),
                'period_end' => $due->copy()->endOfMonth()->toDateString(),
                'issued_at' => $issueOn->toDateString(),
                'due_at' => $due->toDateString(),
                'subtotal' => $line['amount'],
                'total' => $line['amount'],
                'balance_due' => $line['amount'],
                /* DRAFT until its month begins. Nothing reads a draft as owed — the
                   ledger's isOpen() excludes it — so the family's balance does not move
                   until the invoice is actually issued. */
                'status' => 'draft',
                'notes' => 'Payment schedule #' . $planId . ' — instalment ' . $n . ' of ' . $of,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('invoice_lines')->insert([
                'invoice_id' => $invoiceId,
                'description' => 'Payment schedule instalment ' . $n . ' of ' . $of,
                'line_type' => 'tuition',
                'quantity' => 1,
                'unit_amount' => $line['amount'],
                'amount' => $line['amount'],
            ]);

            return $invoiceId;
        } catch (\Throwable $e) {
            /* The schedule is still worth having if one invoice could not be raised —
               the instalment records the obligation either way, and a null invoice_id
               is visible rather than silent. */
            report($e);

            return null;
        }
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $this->assertStaff($request);

        /* WHICH invoices to withdraw is the caller's decision, not this method's.

           It used to void every unissued invoice on the schedule. A family who has
           already promised October's payment should not have October's invoice vanish
           because the schedule after it was cancelled — so nothing is voided that was
           not named. An absent key voids nothing, the safe default for a caller that
           has not been updated. */
        $data = $request->validate([
            'void_invoice_ids' => 'nullable|array',
            'void_invoice_ids.*' => 'integer',
        ]);
        $asked = collect($data['void_invoice_ids'] ?? [])->map(fn ($v) => (int) $v)->filter()->unique();

        DB::table('payment_plans')->where('id', $id)->update([
            'status' => 'cancelled', 'updated_at' => now(),
        ]);

        /* Confined to invoices THIS schedule raised — an id from anywhere else is
           ignored rather than trusted — and to DRAFTS, because an issued invoice has
           been seen by the family and may have been paid against. Withdrawing one of
           those as a side effect of cancelling a schedule is how a ledger stops
           reconciling; it needs the deliberate void on the invoice itself. */
        $mine = DB::table('payment_plan_installments')->where('payment_plan_id', $id)
            ->whereNotNull('invoice_id')->pluck('invoice_id');
        $toVoid = $asked->intersect($mine);

        $withdrawn = $toVoid->isEmpty() ? 0 : DB::table('invoices')
            ->whereIn('id', $toVoid)->where('status', 'draft')
            ->update(['status' => 'void', 'balance_due' => 0, 'updated_at' => now()]);

        // The schedule is over either way; an invoice kept open outlives it.
        DB::table('payment_plan_installments')->where('payment_plan_id', $id)
            ->where('status', 'pending')->update(['status' => 'cancelled']);

        try {
            \App\Support\Audit::write([
                'user_id' => $request->user()->id,
                'action' => 'payment_schedule.cancelled',
                'entity_type' => 'payment_plan',
                'entity_id' => $id,
                'payload' => json_encode([
                    'invoices_withdrawn' => $withdrawn,
                    'summary' => 'Cancelled payment schedule #' . $id . ' — withdrew ' . $withdrawn
                        . ' unissued invoice(s); any not named were left standing.',
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* never fail a cancel over its own audit row */ }

        return response()->json(['status' => 'cancelled', 'invoices_withdrawn' => $withdrawn]);
    }

    public function myPlans(Request $request): JsonResponse
    {
        $u = $request->user();
        $famId = DB::table('guardians')->where('user_id', $u->id)->value('family_id');
        abort_unless($famId, 404);
        return $this->listForFamily($request, (int) $famId);
    }

    private function assertStaff(Request $request): void
    {
        $u = $request->user();
        $isStaff = DB::table('role_assignments')->where('user_id', $u->id)
            ->whereIn('role', ['centre_director', 'agency_admin', 'platform_admin'])
            ->where('active', 1)->exists();
        abort_unless($isStaff, 403);
    }
}
