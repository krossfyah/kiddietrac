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
        /* WHO SET THE SCHEDULE UP (2026-09-17).

           Anthony: "the payment schedule created for sanford family was done by Safia but
           it doesnt show up under the issued by column."

           It did not, because nothing on that schedule has been ISSUED yet - every row is
           still a draft - and `issued_by_user_id` records who put an invoice OUT, not who
           planned it. The name was never missing from the database, only from the answer:
           payment_plans.created_by_user_id has held it all along and nothing read it. */
        $plans = DB::table('payment_plans as p')
            ->leftJoin('users as cu', 'cu.id', '=', 'p.created_by_user_id')
            ->where('p.family_id', $familyId)
            ->orderByDesc('p.created_at')
            ->get([
                'p.*',
                DB::raw("TRIM(CONCAT(COALESCE(cu.first_name,''),' ',COALESCE(cu.last_name,''))) as created_by_name"),
            ]);
        $planIds = $plans->pluck('id');
        /* The invoice each instalment raised travels with it, so the table can show a
           number and the cancel dialog can list exactly what it would withdraw —
           neither needing a second round trip. A LEFT join: an imported schedule
           raised no invoice, and null is the honest answer for it. */
        /* A WITHDRAWN INSTALMENT IS NOT PART OF THE SCHEDULE (2026-09-17).

           Editing a schedule cancels the unissued tail and raises a replacement for it,
           so a plan edited from 3 x $100 to 2 x $175 holds five rows. Listing all five
           made the card contradict its own header - "$350.00 over 2 instalments" above a
           table of five - and invited somebody to read a voided line as money owed.

           The cancelled rows stay in the table and in the audit log, which is where the
           history belongs; the schedule shows what the schedule IS. */
        $installments = DB::table('payment_plan_installments as pi')
            ->leftJoin('invoices as i', 'i.id', '=', 'pi.invoice_id')
            /* WHO ISSUED IT, not merely when. Left-joined: null is the honest answer for
               the 06:00 cron and for anything imported, and the table says "Automatic"
               rather than leaving a person-shaped blank. */
            ->leftJoin('users as iu', 'iu.id', '=', 'i.issued_by_user_id')
            ->whereIn('pi.payment_plan_id', $planIds)
            ->where('pi.status', '!=', 'cancelled')
            ->orderBy('pi.due_date')
            ->get([
                'pi.id', 'pi.payment_plan_id', 'pi.due_date', 'pi.amount', 'pi.status',
                'pi.invoice_id', 'pi.paid_at',
                'i.invoice_number', 'i.status as invoice_status',
                'i.issued_at as invoice_issues_on', 'i.balance_due as invoice_balance',
                'i.issued_by_user_id as invoice_issued_by_id',
                DB::raw("TRIM(CONCAT(COALESCE(iu.first_name,''),' ',COALESCE(iu.last_name,''))) as invoice_issued_by"),
            ])
            ->groupBy('payment_plan_id');

        /* AN IMPORTED INSTALMENT FINDS ITS INVOICE BY DUE DATE.

           iLearn raises the invoice and the schedule separately and nothing ties them
           together on this side, so invoice_id is null for every imported instalment —
           399 of them — while the invoice itself exists and matches on (family, due
           date) in every single case.

           Resolved here rather than written: storing a guessed link would turn a good
           guess into a fact the next sync knows nothing about. Where several share a
           date the one matching the amount wins, but a DIFFERENCE does not reject the
           match — 32 are invoices iLearn adjusted after the schedule was agreed, and
           that gap is precisely what somebody reading this screen needs to see. */
        $external = DB::table('external_invoices')->where('family_id', $familyId)
            ->get(['id', 'number', 'status', 'due_at', 'total', 'balance_due'])
            ->groupBy(fn ($r) => substr((string) $r->due_at, 0, 10));

        $installments = $installments->map(function ($group) use ($external) {
            return $group->map(function ($i) use ($external) {
                if ($i->invoice_number) { return $i; }          // raised here; already linked
                $day = substr((string) $i->due_date, 0, 10);
                $candidates = $external->get($day);
                if (! $candidates || $candidates->isEmpty()) { return $i; }

                $exact = $candidates->first(fn ($c) => abs((float) $c->total - (float) $i->amount) < 0.005);
                $hit = $exact ?: $candidates->first();

                // The id travels too, so the row can offer to OPEN the invoice and not
                // just name it — an instalment matched by due date has no invoice_id.
                $i->external_invoice_id = $hit->id;
                $i->invoice_number = $hit->number;
                $i->invoice_status = $hit->status;
                $i->invoice_balance = $hit->balance_due;
                $i->invoice_total = (float) $hit->total;
                // Said out loud so a $189 instalment against a $207.90 invoice is
                // visible rather than quietly mismatched.
                $i->invoice_differs = abs((float) $hit->total - (float) $i->amount) >= 0.005;
                $i->invoice_matched_by = 'due date';

                return $i;
            });
        });

        /* WHO A RESEND WOULD GO TO. The kebab's Resend opens a dialog with the family's
           guardian addresses already in it - shown, never assumed, because a resend that
           silently picks its own recipient is how an invoice reaches the wrong inbox.
           `guardians` has no email column; the address lives on the user. */
        $emails = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $familyId)->whereNotNull('u.email')
            ->pluck('u.email')->unique()->values()->all();

        /* `description` and `line_items` are columns on payment_plans now, so the
           SELECT * above already carries them to the screen. */
        $plans->transform(function ($p) use ($installments, $emails) {
            $p->installments = $installments->get($p->id, collect());
            $p->guardian_emails = $emails;
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

            /* ISSUE NOW, OR HOLD AS DRAFTS (2026-09-17).

               A schedule has always raised every instalment as a draft dated the first of
               its own month, and the 06:00 cron issued each one when that day came. Right
               for a plan running into next year - and wrong for the common case, a plan
               whose first instalments are for months already underway, where the drafts
               simply sat there owed by nobody.

               So the builder now asks, and defaults to issuing. Absent entirely, the old
               behaviour stands: every caller that predates this keeps holding drafts. */
            'issue_now' => 'nullable|boolean',

            /* WHAT THE MONEY IS FOR, AND WHAT ELSE IS ON THE BILL (2026-09-17).

               Anthony: "new payment schedule should have a description line to be entered
               for the total amount and also ability to add a line item(s) as well."

               Every instalment used to raise an invoice whose only line read "Payment
               schedule instalment 3 of 7" - true, and useless to a parent trying to work
               out what they are paying for. `description` is that line's real wording.

               `line_items` are charges that ride along with the instalments: a supply fee
               on every one, a registration fee on the first only. `applies` says which,
               because guessing would mean either billing a one-off seven times or
               quietly dropping six of a recurring charge. */
            'description' => 'nullable|string|max:200',
            'line_items' => 'nullable|array|max:20',
            'line_items.*.description' => 'required_with:line_items|string|max:200',
            'line_items.*.amount' => 'required_with:line_items|numeric|not_in:0|min:-100000|max:100000',
            'line_items.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'line_items.*.applies' => 'nullable|in:every,first',

            /* HOW MUCH NOTICE THIS SCHEDULE GIVES (2026-09-17).

               Anthony: "add an option in the pop up to indicate due immediately or can
               choose date when are actually due (grace period)."

               0 means the invoice is issued and due the same day. Absent means "use the
               agency's setting", which is a different thing from 0 and has to stay
               different - see the column's migration. */
            'issue_lead_days' => 'nullable|integer|min:0|max:60',
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

        /* THE EXTRAS ARE FOLDED INTO THE INSTALMENT AMOUNTS, not carried beside them.

           An instalment's `amount` and its invoice's `total` are compared all over this
           screen — the table flags them when they differ, on purpose, because a mismatch
           usually means an imported invoice was adjusted afterwards. If an extra charge
           only ever landed on the invoice, every instalment on the schedule would light
           up as "invoiced $245" against a $220 row, and a real discrepancy would be
           indistinguishable from a feature working correctly. So the charge is added to
           the instalment too, and the two agree by construction. */
        /* Null and 0 are different answers, so array_key_exists rather than `??` - a
           schedule that is due on issue must not be read as "no preference". Declared up
           here with the other derived values because the payment_plans insert below reads
           it; defining it beside $issueNow further down left it undefined at the insert. */
        $lead = array_key_exists('issue_lead_days', $data) && $data['issue_lead_days'] !== null
            ? (int) $data['issue_lead_days'] : null;

        $description = trim((string) ($data['description'] ?? ''));
        $extras = [];
        $firstOnly = [];
        foreach (($data['line_items'] ?? []) as $li) {
            $row = [
                'description' => trim((string) $li['description']),
                'amount' => round((float) $li['amount'], 2),
                'tax_rate' => (isset($li['tax_rate']) && (float) $li['tax_rate'] > 0)
                    ? round((float) $li['tax_rate'], 2) : null,
                'applies' => ($li['applies'] ?? 'every') === 'first' ? 'first' : 'every',
            ];
            if ($row['applies'] === 'first') { $firstOnly[] = $row; } else { $extras[] = $row; }
        }

        $chargeOf = function (array $rows) {
            $sum = 0.0;
            foreach ($rows as $x) {
                $sum += (float) $x['amount'];
                if (! empty($x['tax_rate'])) { $sum += (float) $x['amount'] * ((float) $x['tax_rate'] / 100); }
            }

            return round($sum, 2);
        };
        $perInstalment = $chargeOf($extras);
        $onceOff = $chargeOf($firstOnly);

        foreach ($lines as $idx => $l) {
            $lines[$idx]['amount'] = round((float) $l['amount'] + $perInstalment + ($idx === 0 ? $onceOff : 0), 2);
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
            'description' => $description !== '' ? $description : null,
            /* Kept so an EDIT can re-raise the tail carrying the same extras. Without
               this, editing a schedule would quietly drop every extra charge from the
               instalments it rebuilt. */
            'line_items' => ($extras || $firstOnly)
                ? json_encode(['every' => $extras, 'first' => $firstOnly]) : null,
            // Remembered so an EDIT re-raises its tail with the same notice period.
            'issue_lead_days' => $lead,
            'created_by_user_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $issueNow = (bool) ($data['issue_now'] ?? false);
        $issuedIds = [];

        foreach ($lines as $n => $line) {
            $invoiceId = $this->raiseScheduledInvoice(
                (int) $family->id, (int) $family->centre_id, $line, $planId, $n + 1, count($lines),
                $description,
                // The one-off charges belong to the first invoice and nowhere else.
                $n === 0 ? array_merge($extras, $firstOnly) : $extras,
                $lead
            );
            DB::table('payment_plan_installments')->insert([
                'payment_plan_id' => $planId,
                'due_date' => $line['due_date'],
                'amount' => $line['amount'],
                'invoice_id' => $invoiceId,
                'status' => 'pending',
                'created_at' => now(),
            ]);

            /* Issued HERE rather than left to the cron, and stamped with the person who
               asked for it - the same two writes InvoiceController::issue() makes, so a
               schedule issued at creation and one issued a row at a time read identically
               afterwards. issued_at becomes today because that is when it went out. */
            if ($issueNow && $invoiceId) {
                DB::table('invoices')->where('id', $invoiceId)->where('status', 'draft')->update([
                    'status' => 'sent',
                    'issued_at' => now()->toDateString(),
                    'issued_by_user_id' => $request->user()->id,
                    'updated_at' => now(),
                ]);
                $issuedIds[] = $invoiceId;
            }
        }

        if ($issuedIds) {
            try {
                $agencyId = DB::table('centres')->where('id', $family->centre_id)->value('agency_id');
                $numbers = DB::table('invoices')->whereIn('id', $issuedIds)
                    ->orderBy('id')->pluck('invoice_number')->all();
                \App\Support\Audit::write([
                    'agency_id' => $agencyId,
                    'user_id' => $request->user()->id,
                    'action' => 'invoice.issued_manually',
                    'entity_type' => 'payment_plan',
                    'entity_id' => $planId,
                    'payload' => json_encode([
                        'payment_plan_id' => $planId,
                        'family_id' => (int) $family->id,
                        'count' => count($issuedIds),
                        // Every number, not a count - a reader has to be able to see WHICH.
                        'invoice_numbers' => $numbers,
                        'summary' => 'Issued ' . count($issuedIds) . ' invoice(s) on creating payment schedule #'
                            . $planId . ': ' . implode(', ', $numbers) . '.',
                    ]),
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) { /* never fail the schedule over its own audit row */ }
        }

        $data['total_amount'] = $total;
        $data['installment_count'] = count($lines);
        $data['cadence'] = $data['cadence'] ?? 'scheduled';

        // Notify guardians
        $gids = DB::table('guardians')->where('family_id', $data['family_id'])->pluck('user_id');
        foreach ($gids as $gid) {
            \App\Support\Notify::write([
                'user_id' => $gid, 'type' => 'payment_plan',
                'title' => 'Payment schedule created',
                'body' => '$' . number_format((float) $data['total_amount'], 2) . ' over ' . $data['installment_count'] . ' ' . $data['cadence'] . ' installments',
                'data' => json_encode(['link' => '#payment-plans', 'plan_id' => $planId]),
                'created_at' => now(),
            ]);
        }

        return response()->json([
            'id' => $planId,
            'issued' => count($issuedIds),
            'held_as_draft' => count($lines) - count($issuedIds),
        ], 201);
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
    private function raiseScheduledInvoice(int $familyId, int $centreId, array $line, int $planId, int $n, int $of, string $description = '', array $extras = [], ?int $leadOverride = null): ?int
    {
        /* Extras are the same on every invoice this call raises; totalled once here so
           the arithmetic below reads as arithmetic and not as a loop. */
        $extraNet = 0.0;
        $extraTax = 0.0;
        foreach ($extras as $x) {
            $extraNet += (float) $x['amount'];
            if (! empty($x['tax_rate'])) { $extraTax += (float) $x['amount'] * ((float) $x['tax_rate'] / 100); }
        }
        $extraNet = round($extraNet, 2);
        $extraTax = round($extraTax, 2);
        $extraTotal = round($extraNet + $extraTax, 2);

        try {
            $due = Carbon::parse($line['due_date']);

            /* ISSUED A FIXED NUMBER OF DAYS BEFORE IT IS DUE (2026-09-17).

               This was `$due->startOfMonth()`, which is not a lead time at all - it is
               "whenever this month happens to have started". On the Sanford-Saganek
               biweekly plan that meant the 9th and the 23rd both issued on the 1st, so
               one family got 8 days' notice on one invoice and 22 on the next, while a
               4th-of-the-month instalment got 3. Anthony: "issue the invoices 5 days in
               advance (make this a configurable setting)."

               THE DUE DATE IS UNTOUCHED. It is the date somebody typed into the builder
               and the date the family agreed to; only when the paper arrives has moved.

               The BILLED PERIOD and the invoice NUMBER still key off the DUE month, not
               the issue date - an instalment due 4 December belongs to December however
               early its invoice goes out, and anchoring the number on the issue date
               would file it under 202611. */
            /* The schedule's own answer wins over the agency's, and 0 is an answer -
               `?:` would quietly turn "due on issue" back into the agency default. */
            $lead = $leadOverride !== null ? $leadOverride : $this->issueLeadDays($centreId);
            $issueOn = $due->copy()->subDays($lead);
            $billedMonth = $due->copy()->startOfMonth();

            /* The agency's own numbering convention, not this method's opinion of one.
               InvoiceNumber's default reproduces exactly what this line used to build,
               so an agency that never opens the setting sees no change. */
            $number = \App\Services\InvoiceNumber::next(
                (int) DB::table('centres')->where('id', $centreId)->value('agency_id'),
                ['date' => $billedMonth, 'family_id' => $familyId, 'n' => $n]
            );

            /* THE MONEY, BROKEN OUT. $line['amount'] is what the family pays for this
               instalment; `base` is the part that is the instalment itself and `extras`
               are the charges riding along with it. They must sum to the instalment or
               the invoice would disagree with the schedule that raised it. */
            $base = round((float) $line['amount'] - $extraTotal, 2);

            $invoiceId = DB::table('invoices')->insertGetId([
                'centre_id' => $centreId,
                'family_id' => $familyId,
                'invoice_number' => $number,
                // period_start/period_end are NOT NULL; the billed month is the period.
                'period_start' => $billedMonth->toDateString(),
                'period_end' => $due->copy()->endOfMonth()->toDateString(),
                'issued_at' => $issueOn->toDateString(),
                'due_at' => $due->toDateString(),
                'subtotal' => round($base + $extraNet, 2),
                'tax_amount' => $extraTax,
                'total' => $line['amount'],
                'balance_due' => $line['amount'],
                /* DRAFT until its issue date arrives. Nothing reads a draft as owed —
                   the ledger's isOpen() excludes it — so the family's balance does not
                   move until the invoice is actually issued. */
                'status' => 'draft',
                'notes' => 'Payment schedule #' . $planId . ' — instalment ' . $n . ' of ' . $of,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /* The description somebody typed, not this method's own words. Blank falls
               back to the old wording so a schedule created before the field existed,
               or by a caller that does not send one, still reads sensibly. */
            DB::table('invoice_lines')->insert([
                'invoice_id' => $invoiceId,
                'description' => $description !== '' ? $description
                    : ('Payment schedule instalment ' . $n . ' of ' . $of),
                'line_type' => 'tuition',
                'quantity' => 1,
                'unit_amount' => $base,
                'amount' => $base,
            ]);

            foreach ($extras as $x) {
                DB::table('invoice_lines')->insert([
                    'invoice_id' => $invoiceId,
                    'description' => $x['description'],
                    'line_type' => ((float) $x['amount']) < 0 ? 'adjustment' : 'extra_day',
                    'quantity' => 1,
                    'unit_amount' => $x['amount'],
                    'amount' => $x['amount'],
                    'tax_rate' => $x['tax_rate'],
                ]);
            }

            return $invoiceId;
        } catch (\Throwable $e) {
            /* The schedule is still worth having if one invoice could not be raised —
               the instalment records the obligation either way, and a null invoice_id
               is visible rather than silent. */
            report($e);

            return null;
        }
    }

    /* EDIT A SCHEDULE - the same form that created it, and the same rules (2026-09-17).

       "for payment schedules I need an option to edit plans just the same way we created
        them with the same view for a family/parent."

       WHAT AN EDIT MAY AND MAY NOT TOUCH. An instalment whose invoice has been ISSUED
       has been seen by the family and may have been paid against; moving its date or its
       amount after the fact is how a ledger stops reconciling. So an edit rewrites only
       the UNISSUED tail - instalments still pending whose invoice is still a draft (or
       which never got one) - and leaves everything settled exactly where it is. The same
       line cancel() draws, for the same reason.

       The tail is withdrawn and re-raised rather than patched in place: an instalment's
       invoice carries its own number, period and line, and re-deriving those from the
       new dates is what raiseScheduledInvoice() already does correctly. Withdrawn drafts
       keep their numbers, so the sequence continues past them rather than reusing one.

       The family is told, because what they owe has changed. */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'installments' => 'required|array|min:1|max:60',
            'installments.*.due_date' => 'required|date_format:Y-m-d',
            'installments.*.amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:1000',
        ]);
        $this->assertStaff($request);

        $plan = DB::table('payment_plans')->where('id', $id)->first();
        abort_unless($plan, 404, 'No such payment schedule.');
        abort_if((string) $plan->status === 'cancelled', 422,
            'This schedule has been cancelled. Create a new one rather than editing it.');

        // Ownership of the FAMILY, not merely of a staff role somewhere.
        $this->assertFamily((int) $request->user()->id, (int) $plan->family_id);

        $family = DB::table('families')->where('id', $plan->family_id)->first(['id', 'centre_id']);
        abort_unless($family, 404, 'No such family.');

        $stored = $plan->line_items ? json_decode((string) $plan->line_items, true) : null;
        $planExtras = is_array($stored) && isset($stored['every']) && is_array($stored['every'])
            ? $stored['every'] : [];

        $existing = DB::table('payment_plan_installments as pi')
            ->leftJoin('invoices as i', 'i.id', '=', 'pi.invoice_id')
            ->where('pi.payment_plan_id', $id)
            ->get(['pi.id', 'pi.status', 'pi.amount', 'pi.due_date', 'pi.invoice_id',
                DB::raw('i.status as invoice_status')]);

        $isSettled = fn ($r) => (string) $r->status !== 'pending'
            || ($r->invoice_id && strtolower((string) ($r->invoice_status ?? '')) !== 'draft');

        $locked = $existing->filter($isSettled)->values();
        $editable = $existing->reject($isSettled)->values();

        $lines = [];
        foreach ($data['installments'] as $row) {
            $lines[] = [
                'due_date' => substr((string) $row['due_date'], 0, 10),
                'amount' => round((float) $row['amount'], 2),
            ];
        }
        usort($lines, fn ($a, $b) => strcmp($a['due_date'], $b['due_date']));

        DB::transaction(function () use ($id, $editable, $locked, $lines, $family, $existing, $data, $plan, $planExtras) {
            // Withdraw the tail. Only drafts, and only this schedule's own.
            $draftIds = $editable->pluck('invoice_id')->filter()->values();
            if ($draftIds->isNotEmpty()) {
                DB::table('invoices')->whereIn('id', $draftIds)->where('status', 'draft')
                    ->update(['status' => 'void', 'balance_due' => 0, 'updated_at' => now()]);
            }
            if ($editable->isNotEmpty()) {
                DB::table('payment_plan_installments')->whereIn('id', $editable->pluck('id'))
                    ->update(['status' => 'cancelled']);
            }

            /* The sequence continues past withdrawn instalments so a re-raised invoice
               cannot take a number a voided one already carries. */
            $seq = $existing->count();
            $of = $locked->count() + count($lines);
            foreach ($lines as $n => $line) {
                /* A RE-RAISED INSTALMENT CARRIES THE SAME EXTRAS AS ITS SIBLINGS.
                   Without this, editing a schedule would rebuild its tail with the
                   recurring charges silently dropped - a family's supply fee would
                   vanish from March onward and nothing on the screen would say so.

                   ONLY the recurring ones. A 'first instalment only' charge has already
                   been billed on an invoice this edit does not touch; re-adding it here
                   would bill a one-off registration fee a second time. */
                $invoiceId = $this->raiseScheduledInvoice(
                    (int) $family->id, (int) $family->centre_id, $line, $id, $seq + $n + 1, $of,
                    (string) ($plan->description ?? ''), $planExtras,
                    $plan->issue_lead_days === null ? null : (int) $plan->issue_lead_days
                );
                DB::table('payment_plan_installments')->insert([
                    'payment_plan_id' => $id,
                    'due_date' => $line['due_date'],
                    'amount' => $line['amount'],
                    'invoice_id' => $invoiceId,
                    'status' => 'pending',
                    'created_at' => now(),
                ]);
            }

            // Derived from what the schedule now holds, settled instalments included.
            $total = round($locked->sum(fn ($r) => (float) $r->amount)
                + array_sum(array_column($lines, 'amount')), 2);

            DB::table('payment_plans')->where('id', $id)->update([
                'total_amount' => $total,
                'installment_count' => $locked->count() + count($lines),
                'notes' => $data['notes'] ?? ($plan->notes ?? null),
                'updated_at' => now(),
            ]);
        });

        try {
            \App\Support\Audit::write([
                'user_id' => $request->user()->id,
                'action' => 'payment_schedule.updated',
                'entity_type' => 'payment_plan',
                'entity_id' => $id,
                'payload' => json_encode([
                    'kept' => $locked->count(),
                    'replaced' => $editable->count(),
                    'new_installments' => count($lines),
                    'summary' => 'Edited payment schedule #' . $id . ' - kept ' . $locked->count()
                        . ' settled instalment(s), replaced ' . $editable->count()
                        . ' unissued one(s) with ' . count($lines) . '.',
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* never fail an edit over its own audit row */ }

        foreach (DB::table('guardians')->where('family_id', $plan->family_id)->pluck('user_id') as $gid) {
            try {
                \App\Support\Notify::write([
                    'user_id' => $gid, 'type' => 'payment_plan',
                    'title' => 'Payment schedule updated',
                    'body' => 'Your upcoming instalments have changed. Tap to review the new dates and amounts.',
                    'data' => json_encode(['link' => '#payment-plans', 'plan_id' => $id]),
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) { /* a notification must not fail the edit */ }
        }

        return response()->json([
            'status' => 'updated',
            'kept' => $locked->count(),
            'replaced' => $editable->count(),
            'installments' => count($lines),
        ]);
    }

    /* EDIT ONE INSTALMENT, NOT THE WHOLE SCHEDULE (2026-09-17).

       Anthony: "the actions where you can edit the schedule should only allow you to
       edit the individual schedule and not all."

       The row kebab used to open the whole-plan editor, which is the wrong tool for
       "the 9 October one should be $240". That editor withdraws and re-raises the entire
       unissued tail, so correcting one figure renumbered every invoice after it. Here
       one row moves and nothing else is touched.

       IN PLACE, NOT WITHDRAWN AND RE-RAISED. update() has to void and re-raise because
       it changes how many instalments there are and therefore every invoice's "n of N".
       A single edit changes neither, so the draft is simply corrected - it keeps its
       number, and the family never sees a gap in the sequence.

       ONLY AN UNISSUED ONE. Once an invoice has been issued the family has seen it and
       may have paid against it; moving its date or amount afterwards is how a ledger
       stops reconciling. Void it and add a replacement instead - the same line update()
       and cancel() draw. */
    public function updateInstallment(Request $request, int $planId, int $installmentId): JsonResponse
    {
        $data = $request->validate([
            'due_date' => 'required|date_format:Y-m-d',
            'amount' => 'required|numeric|min:0.01',
        ]);
        $this->assertStaff($request);

        $plan = DB::table('payment_plans')->where('id', $planId)->first();
        abort_unless($plan, 404, 'No such payment schedule.');
        abort_if((string) $plan->status === 'cancelled', 422, 'This schedule has been cancelled.');
        $this->assertFamily((int) $request->user()->id, (int) $plan->family_id);

        /* Scoped to the PLAN as well as the id: an instalment id belonging to another
           family's schedule must not be editable just because the caller can reach this
           one. */
        $inst = DB::table('payment_plan_installments as pi')
            ->leftJoin('invoices as i', 'i.id', '=', 'pi.invoice_id')
            ->where('pi.id', $installmentId)->where('pi.payment_plan_id', $planId)
            ->first(['pi.id', 'pi.status', 'pi.amount', 'pi.due_date', 'pi.invoice_id',
                DB::raw('i.status as invoice_status'), DB::raw('i.invoice_number as invoice_number')]);
        abort_unless($inst, 404, 'No such instalment on this schedule.');

        $issued = (string) $inst->status !== 'pending'
            || ($inst->invoice_id && strtolower((string) ($inst->invoice_status ?? '')) !== 'draft');
        if ($issued) {
            return response()->json([
                'message' => 'That instalment has already been issued'
                    . ($inst->invoice_number ? ' as ' . $inst->invoice_number : '')
                    . '. Void it and add a replacement rather than editing it.',
            ], 422);
        }

        $family = DB::table('families')->where('id', $plan->family_id)->first(['id', 'centre_id']);
        abort_unless($family, 404, 'No such family.');

        $due = substr((string) $data['due_date'], 0, 10);
        $amount = round((float) $data['amount'], 2);
        $was = ['due_date' => substr((string) $inst->due_date, 0, 10), 'amount' => (float) $inst->amount];

        DB::transaction(function () use ($installmentId, $planId, $inst, $due, $amount, $family, $plan) {
            DB::table('payment_plan_installments')->where('id', $installmentId)
                ->update(['due_date' => $due, 'amount' => $amount]);

            if ($inst->invoice_id) {
                /* The issue date is DERIVED from the due date, so moving one moves the
                   other - a stale issued_at would issue the invoice against the schedule
                   it used to have. The number and the billed period stay put: this is
                   the same invoice, corrected. */
                $dueC = Carbon::parse($due);
                $lead = $plan->issue_lead_days !== null
                    ? (int) $plan->issue_lead_days
                    : $this->issueLeadDays((int) $family->centre_id);
                DB::table('invoices')->where('id', $inst->invoice_id)->where('status', 'draft')->update([
                    'due_at' => $due,
                    'issued_at' => $dueC->copy()->subDays($lead)->toDateString(),
                    'period_start' => $dueC->copy()->startOfMonth()->toDateString(),
                    'period_end' => $dueC->copy()->endOfMonth()->toDateString(),
                    'subtotal' => $amount,
                    'total' => $amount,
                    'balance_due' => $amount,
                    'updated_at' => now(),
                ]);
                DB::table('invoice_lines')->where('invoice_id', $inst->invoice_id)
                    ->update(['unit_amount' => $amount, 'amount' => $amount]);
            }

            /* DERIVED from what the schedule now holds, cancelled rows excluded - the
               stored total is a cache of the instalments and must never disagree. */
            $live = DB::table('payment_plan_installments')->where('payment_plan_id', $planId)
                ->where('status', '!=', 'cancelled')->get(['amount']);
            DB::table('payment_plans')->where('id', $planId)->update([
                'total_amount' => round($live->sum(function ($r) { return (float) $r->amount; }), 2),
                'installment_count' => $live->count(),
                'updated_at' => now(),
            ]);
        });

        try {
            \App\Support\Audit::write([
                'agency_id' => DB::table('centres')->where('id', $family->centre_id)->value('agency_id'),
                'user_id' => $request->user()->id,
                'action' => 'payment_schedule.installment_updated',
                'entity_type' => 'payment_plan',
                'entity_id' => $planId,
                'payload' => json_encode([
                    'installment_id' => $installmentId,
                    'invoice_number' => $inst->invoice_number,
                    'from' => $was,
                    'to' => ['due_date' => $due, 'amount' => $amount],
                    'summary' => 'Edited instalment ' . ($inst->invoice_number ?: '#' . $installmentId)
                        . ' on schedule #' . $planId . ': ' . $was['due_date'] . ' $'
                        . number_format($was['amount'], 2) . ' -> ' . $due . ' $'
                        . number_format($amount, 2) . '.',
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* never fail an edit over its own audit row */ }

        return response()->json(['status' => 'updated', 'due_date' => $due, 'amount' => $amount]);
    }

    /* REMOVE AN UNISSUED INSTALMENT FROM A SCHEDULE (2026-09-17).

       Backs "Delete all" on the selected rows. Deliberately NOT the void path: void
       withdraws a document the family has already seen, emails them to say so and lives
       in Accounting. This is for an instalment that never went out - there is nobody to
       tell, because nobody was ever told.

       The draft invoice behind it is voided rather than deleted, so its NUMBER stays
       spent and the next instalment cannot be issued under a number that once meant
       something else. */
    public function deleteInstallment(Request $request, int $planId, int $installmentId): JsonResponse
    {
        $this->assertStaff($request);

        $plan = DB::table('payment_plans')->where('id', $planId)->first();
        abort_unless($plan, 404, 'No such payment schedule.');
        $this->assertFamily((int) $request->user()->id, (int) $plan->family_id);

        $inst = DB::table('payment_plan_installments as pi')
            ->leftJoin('invoices as i', 'i.id', '=', 'pi.invoice_id')
            ->where('pi.id', $installmentId)->where('pi.payment_plan_id', $planId)
            ->first(['pi.id', 'pi.status', 'pi.amount', 'pi.invoice_id',
                DB::raw('i.status as invoice_status'), DB::raw('i.invoice_number as invoice_number')]);
        abort_unless($inst, 404, 'No such instalment on this schedule.');

        $issued = (string) $inst->status !== 'pending'
            || ($inst->invoice_id && strtolower((string) ($inst->invoice_status ?? '')) !== 'draft');
        if ($issued) {
            return response()->json([
                'message' => ($inst->invoice_number ?: 'That instalment')
                    . ' has already been issued. Void it from Accounting instead — the family '
                    . 'has seen it and has to be told it is cancelled.',
            ], 422);
        }

        DB::transaction(function () use ($installmentId, $planId, $inst) {
            if ($inst->invoice_id) {
                DB::table('invoices')->where('id', $inst->invoice_id)->where('status', 'draft')
                    ->update(['status' => 'void', 'balance_due' => 0, 'updated_at' => now()]);
            }
            DB::table('payment_plan_installments')->where('id', $installmentId)
                ->update(['status' => 'cancelled']);

            $live = DB::table('payment_plan_installments')->where('payment_plan_id', $planId)
                ->where('status', '!=', 'cancelled')->get(['amount']);
            DB::table('payment_plans')->where('id', $planId)->update([
                'total_amount' => round($live->sum(function ($r) { return (float) $r->amount; }), 2),
                'installment_count' => $live->count(),
                'updated_at' => now(),
            ]);
        });

        try {
            \App\Support\Audit::write([
                'agency_id' => DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')
                    ->where('f.id', $plan->family_id)->value('c.agency_id'),
                'user_id' => $request->user()->id,
                'action' => 'payment_schedule.installment_removed',
                'entity_type' => 'payment_plan',
                'entity_id' => $planId,
                'payload' => json_encode([
                    'installment_id' => $installmentId,
                    'invoice_number' => $inst->invoice_number,
                    'amount' => (float) $inst->amount,
                    'summary' => 'Removed instalment ' . ($inst->invoice_number ?: '#' . $installmentId)
                        . ' ($' . number_format((float) $inst->amount, 2) . ') from schedule #' . $planId
                        . '; its draft invoice was voided.',
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* never fail the removal over its own audit row */ }

        return response()->json(['status' => 'removed']);
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

    /** @var array<int,int> centre id => lead days, so a 12-instalment plan asks once. */
    private array $leadDaysCache = [];

    private function issueLeadDays(int $centreId): int
    {
        if (array_key_exists($centreId, $this->leadDaysCache)) {
            return $this->leadDaysCache[$centreId];
        }
        $lead = 5;
        try {
            $agencyId = DB::table('centres')->where('id', $centreId)->value('agency_id');
            $raw = $agencyId ? DB::table('agencies')->where('id', $agencyId)->value('settings') : null;
            $settings = $raw ? json_decode((string) $raw, true) : null;
            if (is_array($settings) && isset($settings['billing_setup']['invoice_issue_lead_days'])) {
                $lead = (int) $settings['billing_setup']['invoice_issue_lead_days'];
            }
        } catch (\Throwable $e) {
            /* A schedule is still worth raising on the default; an unreadable settings
               blob must not stop a director billing a family. */
            report($e);
        }
        // 0 means "issue it on the day it is due", which is a legitimate policy.
        $lead = max(0, min(60, $lead));

        return $this->leadDaysCache[$centreId] = $lead;
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
