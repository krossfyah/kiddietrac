<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Invoices the platform raises against agencies (2026-08-25).
 *
 * Platform-admin only — these are KiddieTrac's own receivables, not an agency's. An
 * agency director must never see them, which is why they live on /platform/* rather than
 * anywhere an agency-scoped role can reach.
 *
 * Deliberately sends nothing. Issuing marks the invoice issued and stamps the date;
 * delivering it is a separate step, so recording what was billed cannot accidentally
 * email a customer.
 */
class PlatformInvoiceController extends Controller
{
    /** Invoices, newest period first, with the agency name resolved. */
    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('platform_invoices as pi')
            ->leftJoin('agencies as a', 'a.id', '=', 'pi.agency_id')
            ->when($request->query('status'), fn ($x) => $x->where('pi.status', $request->query('status')))
            ->when($request->query('agency_id'), fn ($x) => $x->where('pi.agency_id', (int) $request->query('agency_id')))
            ->orderByDesc('pi.period_start')
            ->orderBy('a.name')
            ->limit(500)
            ->get([
                'pi.id', 'pi.agency_id', 'pi.number', 'pi.period_start', 'pi.period_end',
                'pi.plan_code', 'pi.amount_cents', 'pi.amount_paid_cents', 'pi.currency',
                'pi.status', 'pi.issued_at', 'pi.due_at', 'pi.paid_at', 'pi.payment_reference',
                'a.name as agency_name',
                'a.contact_email as agency_email', 'a.invoice_emails',
                'pi.sent_at', 'pi.sent_to',
                'pi.subtotal_cents', 'pi.tax_cents', 'pi.tax_rate_bps', 'pi.tax_label',
                'a.created_at as agency_created_at', 'a.billing_interval as agency_interval', 'pi.series',
            ]);
        /* BOARDED (2026-10-25): when the agency signed with KiddieTrac - the earlier of the agency's creation and
           its first licence. */
        $firstLic = DB::table('agency_licences')->whereIn('agency_id', $rows->pluck('agency_id')->filter()->unique()->all() ?: [0])
            ->groupBy('agency_id')->pluck(DB::raw('MIN(starts_at)'), 'agency_id');
        $cus = \App\Support\SalesCustomers::numbersForAgencies($rows->pluck('agency_id')->all());   // customer numbers (2026-11-11)
        $rows = $rows->map(function ($r) use ($firstLic, $cus) {
            $r->customer_number = $r->agency_id ? ($cus[(int) $r->agency_id] ?? null) : null;
            $c = $r->agency_created_at ? substr((string) $r->agency_created_at, 0, 10) : null;
            $l = $firstLic[$r->agency_id] ?? null;
            $r->boarded_at = $c && $l ? min($c, (string) $l) : ($c ?: $l);
            unset($r->agency_created_at);
            // How the agency is billed: its licence's interval, else its plan's (2026-10-25).
            $pl = $r->agency_id ? \App\Support\Licences::planLine((int) $r->agency_id) : null;
            $r->billing_interval = $pl ? $pl->interval : ($r->agency_interval ?: null);
            unset($r->agency_interval);
            /* How it was raised (2026-10-25): a one-off is Manual, a -PY invoice is Prepay, anything else came from the
               recurring run or a licence renewal. A reissue takes the type of the invoice it replaced. */
            $series = (string) ($r->series ?? 'recurring');
            if (str_starts_with($series, 'reissue-')) {
                $origId = (int) explode('-', $series)[1];
                $series = (string) (DB::table('platform_invoices')->where('id', $origId)->value('series') ?? 'recurring');
                $origNum = (string) DB::table('platform_invoices')->where('id', $origId)->value('number');
            }
            $r->raised = str_starts_with($series, 'oneoff') ? 'manual' : (str_ends_with(preg_replace('/-R\d+$/', '', (string) $r->number), '-PY') ? 'prepay' : 'recurring');
            return $r;
        });

        /* Totals exclude void. A cancelled invoice is not money owed, and counting it is
           exactly how the agency receivables figure was overstated by $4,046 earlier. */
        $live = $rows->where('status', '!=', 'void');
        $today = \App\Support\AgencyTime::today();

        /* One set of totals PER CURRENCY. Summing CAD and USD into a single figure
           produces a number that is not money in any currency — see the note at the
           top of the patch that introduced this. */
        $totals = [];
        foreach ($live->groupBy(fn ($r) => \App\Support\PlatformBilling::normaliseCurrency($r->currency)) as $cur => $set) {
            $totals[] = [
                'currency' => $cur,
                'billed_cents' => (int) $set->sum('amount_cents'),
                'tax_cents' => (int) $set->sum('tax_cents'),
                'paid_cents' => (int) $set->sum('amount_paid_cents'),
                'outstanding_cents' => (int) $set->sum(fn ($r) => max(0, (int) $r->amount_cents - (int) $r->amount_paid_cents)),
                'overdue_count' => $set->filter(fn ($r) => $r->status !== 'paid' && $r->due_at && $r->due_at < $today)->count(),
            ];
        }
        usort($totals, fn ($a, $b) => strcmp($a['currency'], $b['currency']));

        // What the invoices still need from KiddieTrac's own details (shown here, never on the customer's PDF).
        $iss = app(\App\Services\PlatformInvoicePdf::class)->issuer();
        $missing = array_values(array_filter([! $iss['address'] ? 'business address' : null, ! $iss['tax_id'] ? ($iss['tax_label'] ?: 'Tax') . ' number' : null]));
        return response()->json([
            'invoices' => $rows->values()->all(),
            'totals' => $totals,
            'issuer_missing' => $missing,
        ]);
    }

    /**
     * Agency billing plans — what each agency is charged, in what currency, how often.
     *
     * Every agency is listed, including unpriced ones, because "who is not yet set up to
     * be billed" is the question this screen exists to answer.
     */
    public function plans(): JsonResponse
    {
        $rows = DB::table('agencies')->orderBy('name')->get([
            'id', 'name', 'billing_status', 'plan_code', 'plan_amount_cents', 'plan_currency',
            'billing_interval', 'next_invoice_at', 'tax_rate_bps', 'tax_label', 'tax_registration',
            'contact_email', 'contact_phone',
            'legal_name', 'address_line1', 'address_line2', 'city', 'province', 'postal_code', 'country', 'website',
            // Recurring invoicing + BCC (2026-10-25).
            'recurring_enabled', 'billing_starts_at', 'billing_ends_at', 'account_manager_user_id', 'invoice_bcc',
        ]);

        return response()->json([
            'agencies' => $rows->map(function ($a) {
                $sub = (int) ($a->plan_amount_cents ?? 0);
                $bps = (int) ($a->tax_rate_bps ?? 0);

                return [
                    'id' => $a->id,
                    'name' => $a->name,
                    'billing_status' => $a->billing_status,
                    'plan_code' => $a->plan_code,
                    'plan_amount_cents' => $sub,
                    'plan_currency' => \App\Support\PlatformBilling::normaliseCurrency($a->plan_currency),
                    'billing_interval' => \App\Support\PlatformBilling::normaliseInterval($a->billing_interval),
                    'next_invoice_at' => $a->next_invoice_at,
                    'tax_rate_bps' => $bps,
                    'tax_label' => $a->tax_label,
                    /* Licence-managed agencies are billed from their licences, not this price (2026-10-25):
                       the screen shows the licence total and keeps the price field out of the way. */
                    'licence_managed' => \App\Support\Licences::managed((int) $a->id),
                    'licence_total_cents' => (int) round((float) DB::table('agency_licences')->where('agency_id', $a->id)->whereIn('status', \App\Support\Licences::LIVE)->sum(DB::raw('qty * unit_price_cents'))),
                    'licence_interval' => optional(\App\Support\Licences::planLine((int) $a->id))->interval,
                    'licence_term_months' => (int) (optional(\App\Support\Licences::planLine((int) $a->id))->term_months ?? 0) ?: null,
                    // Before discounts / complimentary, and what comes off (2026-11-04).
                    ...self::licenceGross((int) $a->id),
                    'recurring_enabled' => (bool) ($a->recurring_enabled ?? false), 'billing_starts_at' => $a->billing_starts_at ?? null, 'billing_ends_at' => $a->billing_ends_at ?? null,
                    'account_manager_user_id' => $a->account_manager_user_id ? (int) $a->account_manager_user_id : null, 'invoice_bcc' => $a->invoice_bcc ?? 'default',
                    'recurring_skip' => \App\Support\PlatformBilling::recurringSkip($a, \App\Support\AgencyTime::today()),
                    'tax_registration' => $a->tax_registration,
                    'contact_email' => $a->contact_email,
                    'contact_phone' => $a->contact_phone,
                    'legal_name' => $a->legal_name,
                    'address_line1' => $a->address_line1,
                    'address_line2' => $a->address_line2,
                    'city' => $a->city,
                    'province' => $a->province,
                    'postal_code' => $a->postal_code,
                    'country' => $a->country,
                    'website' => $a->website,
                    /* What the invoice's Bill To block can actually print today. */
                    'billing_address_complete' => trim((string) $a->address_line1) !== ''
                        && trim((string) $a->city) !== '',
                    /* Precomputed so the screen never re-implements the tax maths. */
                    'tax_cents' => \App\Support\PlatformBilling::taxCents($sub, $bps),
                    'total_cents' => \App\Support\PlatformBilling::totalCents($sub, $bps),
                ];
            })->all(),
            'currencies' => \App\Support\PlatformBilling::CURRENCIES,
            // Who can be an account's salesperson: superadmins and sales reps (2026-10-25).
            'account_managers' => DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')->whereIn('ra.role', ['platform_admin', 'sales_rep'])->where('ra.active', 1)->whereNull('u.deleted_at')
                ->select('u.id', DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as name"), 'u.email')->distinct()->orderBy('name')->get(),
            'recurring' => \App\Support\PlatformBilling::recurring(),
            'intervals' => \App\Support\PlatformBilling::INTERVALS,
        ]);
    }

    /* PRICE BEFORE DISCOUNTS (2026-11-04, Anthony: "billing plans table should show the total before any
       discounts/complimentary and add another column for the discounts/complimentary"). Per live licence: a
       complimentary one at its catalogue price for one term, all of it taken off (as KT-CMP-0001 does on the
       invoice); one priced below the catalogue at the catalogue price, the difference taken off; a negative
       line (a discount product) taken off. gross - discount = licence_total_cents. */
    private static function licenceGross(int $agencyId): array
    {
        $gross = 0; $off = 0;
        foreach (DB::table('agency_licences')->where('agency_id', $agencyId)->whereIn('status', \App\Support\Licences::LIVE)->get() as $l) {
            $qty = (float) $l->qty; $net = (int) round($qty * (int) $l->unit_price_cents);
            if ($net < 0) { $off += -$net; continue; }
            $list = (int) round($qty * \App\Support\Licences::listCents($l));
            if (\App\Support\Licences::isComp($l) || $list > $net) { $gross += $list; $off += $list - $net; }
            else $gross += $net;
        }
        return ['licence_gross_cents' => $gross, 'licence_discount_cents' => $off];
    }

    /** Set an agency's recurring plan: price, currency, cadence, tax. */
    public function savePlan(Request $request, int $agencyId): JsonResponse
    {
        $data = $request->validate([
            'plan_amount_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'plan_currency' => ['required', 'string', 'in:' . implode(',', \App\Support\PlatformBilling::CURRENCIES)],
            'billing_interval' => ['required', 'string', 'in:' . implode(',', \App\Support\PlatformBilling::INTERVALS)],
            'plan_code' => ['nullable', 'string', 'max:40'],
            /* Null next_invoice_at = not on recurring billing. That is a real choice, not
               a missing value, so it is explicitly nullable rather than defaulted. */
            'next_invoice_at' => ['nullable', 'date'],
            'tax_rate_bps' => ['required', 'integer', 'min:0', 'max:10000'],
            'tax_label' => ['nullable', 'string', 'max:40'],
            'tax_registration' => ['nullable', 'string', 'max:60'],
            'billing_status' => ['nullable', 'string', 'in:trial,active,past_due,suspended'],
            /* Business identity for the invoice's Bill To block. All optional: a
               partially filled profile prints only the lines it has, so this must
               never force an agency to be fully described before it can be billed. */
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:180'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:180'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'province' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:24'],
            'country' => ['sometimes', 'nullable', 'string', 'max:80'],
            'website' => ['sometimes', 'nullable', 'string', 'max:180'],
            'contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:180'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            // Recurring invoicing + BCC, per agency (2026-10-25).
            'recurring_enabled' => ['sometimes', 'boolean'], 'billing_starts_at' => ['sometimes', 'nullable', 'date'], 'billing_ends_at' => ['sometimes', 'nullable', 'date'],
            'account_manager_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'], 'invoice_bcc' => ['sometimes', 'in:default,none,superadmins,sales,both'],
        ]);

        if (! DB::table('agencies')->where('id', $agencyId)->exists()) {
            return response()->json(['message' => 'Agency not found.'], 404);
        }
        // A licence-managed agency's billing status comes from its licence (Licences::derivedStatus), never a form.
        if (\App\Support\Licences::managed((int) $agencyId)) unset($data['billing_status']);
        if (! empty($data['billing_starts_at']) && ! empty($data['billing_ends_at']) && $data['billing_ends_at'] < $data['billing_starts_at']) {
            return response()->json(['message' => 'The issuing end date is before the start date.'], 422);
        }

        /* A tax rate with no name prints as a bare "Tax" line on a real invoice, which
           is not acceptable in a jurisdiction that requires it named (HST, GST, VAT). */
        if ((int) $data['tax_rate_bps'] > 0 && trim((string) ($data['tax_label'] ?? '')) === '') {
            return response()->json([
                'message' => 'A tax rate needs a name for the invoice — HST, GST, VAT, Sales tax.',
            ], 422);
        }

        /* Invoices are raised on the 1st (2026-11-04): a date that is not the 1st moves to the 1st of the next month. */
        $data['next_invoice_at'] = $data['next_invoice_at']
            ? (function ($d) { $c = \Illuminate\Support\Carbon::parse($d); return ($c->day === 1 ? $c : $c->addMonthNoOverflow()->startOfMonth())->toDateString(); })($data['next_invoice_at'])
            : null;
        $data['updated_at'] = now();
        DB::table('agencies')->where('id', $agencyId)->update($data);

        return response()->json(['ok' => true]);
    }

    /**
     * The invoice PDF, streamed inline for viewing.
     *
     * Rendered on demand from the live row rather than stored, so it always reflects
     * current state — a stored copy would drift the moment a payment was recorded.
     */
    public function pdf(Request $request, int $id)
    {
        $inv = DB::table('platform_invoices')->where('id', $id)->first(['number']);
        if (! $inv) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $bytes = app(\App\Services\PlatformInvoicePdf::class)->render((string) $inv->number);
        if ($bytes === null || $bytes === '') {
            return response()->json(['message' => 'Could not render that invoice.'], 500);
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            /* inline, not attachment: this is a preview. Forcing a download just to
               look at a row is hostile; saving is the browser's own control. */
            'Content-Disposition' => 'inline; filename="' . $inv->number . '.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Raise every invoice that is DUE, on each agency's own schedule.
     *
     * The decision and the writing both live in PlatformInvoiceRaiser, which the artisan
     * command also uses. They were briefly separate implementations that disagreed about
     * periods and tax, so the same data produced different invoices depending on whether
     * you used the button or the command.
     *
     * Previews unless commit=true. This writes financial records, so the screen shows
     * exactly who would be billed before anything happens.
     */
    public function raise(Request $request, \App\Services\PlatformInvoiceRaiser $raiser): JsonResponse
    {
        $plan = $raiser->plan();
        $billable = $raiser->billable($plan);

        if (! $request->boolean('commit')) {
            /* Who is being billed, in enough detail to check before raising (2026-09-29):
               where the invoice would be emailed and whether the Bill To block is complete. */
            $ids = array_column($plan, 'agency_id');
            $info = DB::table('agencies')->whereIn('id', $ids)->get(['id', 'legal_name', 'contact_email', 'address_line1', 'city', 'province', 'billing_status', 'tax_registration'])->keyBy('id');
            $withInfo = fn ($p) => $p + [
                'legal_name' => $info[$p['agency_id']]->legal_name ?? null,
                'contact_email' => $info[$p['agency_id']]->contact_email ?? null,
                'address' => trim(implode(', ', array_filter([$info[$p['agency_id']]->address_line1 ?? null, $info[$p['agency_id']]->city ?? null, $info[$p['agency_id']]->province ?? null]))),
                'billing_status' => $info[$p['agency_id']]->billing_status ?? null,
                'interval_label' => \App\Support\PlatformBilling::intervalLabel($p['interval']),
                'next_after' => $p['next_invoice_at'] ? \App\Support\PlatformBilling::advance($p['next_invoice_at'], $p['interval']) : null,
                'due_at' => \App\Support\PlatformBilling::dueDate(\App\Support\AgencyTime::today()),
                // Extra line items waiting for this agency's next invoice (added on top when raised).
                'extras' => \App\Support\PlatformAccounts::pendingExtras((int) $p['agency_id']),
                // Referral credits that come off this invoice when raised (2026-11-26), on the subtotal with the extras.
                'referral_credits' => \App\Support\ReferralCredits::preview((int) $p['agency_id'], (string) $p['period_start'],
                    (int) $p['subtotal_cents'] + (int) array_sum(array_map(fn ($x) => (int) round((float) $x->qty * (int) $x->unit_cents), \App\Support\PlatformAccounts::pendingExtras((int) $p['agency_id'])))),
            ];

            return response()->json([
                'preview' => true,
                'today' => \App\Support\AgencyTime::today(),
                'would_raise' => array_map($withInfo, $billable),
                'skipped' => array_values(array_filter($plan, fn ($p) => $p['skip_reason'] !== null)),
            ]);
        }

        /* From the review popup: only the ticked agencies, with any edits. Without `items`
           (older clients, scripts) everything due is raised as planned, as before. */
        $edits = [];
        if ($request->has('items')) {
            $data = $request->validate([
                'items' => ['required', 'array', 'min:1'],
                'items.*.agency_id' => ['required', 'integer'],
                'items.*.subtotal_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
                'items.*.tax_rate_bps' => ['required', 'integer', 'min:0', 'max:10000'],
                'items.*.tax_label' => ['nullable', 'string', 'max:40'],
                'items.*.due_at' => ['required', 'date'],
                'items.*.notes' => ['nullable', 'string', 'max:1000'],
                'items.*.save_to_plan' => ['sometimes', 'boolean'],
            ]);
            foreach ($data['items'] as $it) {
                if ((int) $it['tax_rate_bps'] > 0 && trim((string) ($it['tax_label'] ?? '')) === '') {
                    return response()->json(['message' => 'A tax rate needs a name for the invoice (HST, GST, VAT).'], 422);
                }
                $edits[(int) $it['agency_id']] = $it;
            }
            $billable = array_values(array_filter($billable, fn ($p) => isset($edits[$p['agency_id']])));
            if (! $billable) {
                return response()->json(['message' => 'None of those agencies is due any more. Refresh and try again.'], 409);
            }
        }

        $result = $raiser->commit($billable, optional($request->user())->id, null, $edits);

        // "Use this price from now on": the edit also becomes the agency's plan.
        foreach ($edits as $agencyId => $it) {
            if (! empty($it['save_to_plan'])) {
                DB::table('agencies')->where('id', $agencyId)->update([
                    'plan_amount_cents' => (int) $it['subtotal_cents'], 'tax_rate_bps' => (int) $it['tax_rate_bps'],
                    'tax_label' => $it['tax_label'] ?? null, 'updated_at' => now(),
                ]);
            }
        }

        return response()->json([
            'preview' => false,
            'raised' => $result['raised'],
            'count' => count($result['raised']),
            'already_billed' => $result['failed'],
        ]);
    }

    /**
     * Email the invoice to a named recipient, with the PDF attached.
     *
     * `to` is REQUIRED and never falls back to a stored contact address. The screen
     * prefills the agency's own address so this is not tedious, but the address is
     * confirmed by a person before a customer is emailed.
     *
     * Sending a draft also issues it: a document that has left the building is no longer
     * a draft, and leaving it as one would let it be silently edited afterwards.
     */
    public function email(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:1000'],          // one address, or several separated by commas (2026-10-25)
            'save' => ['nullable', 'boolean'],                   // keep them on the agency for next time
            'message' => ['nullable', 'string', 'max:2000'],
        ]);
        $toList = array_values(array_unique(array_filter(array_map(fn ($x) => strtolower(trim($x)), preg_split('/[,;\s]+/', (string) $data['to'])))));
        $badTo = array_values(array_filter($toList, fn ($x) => ! filter_var($x, FILTER_VALIDATE_EMAIL)));
        if (! $toList || $badTo) {
            return response()->json(['message' => $badTo ? 'Not an email address: ' . implode(', ', $badTo) : 'Give at least one address.'], 422);
        }
        if (count($toList) > 10) {
            return response()->json(['message' => 'Up to 10 addresses per invoice.'], 422);
        }

        $inv = DB::table('platform_invoices as pi')
            ->leftJoin('agencies as a', 'a.id', '=', 'pi.agency_id')
            ->where('pi.id', $id)
            ->first(['pi.*', 'a.name as agency_name']);

        if (! $inv) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }
        if ((string) $inv->status === 'void') {
            return response()->json(['message' => 'This invoice is void and cannot be sent.'], 422);
        }

        /* Hard-fail rather than send an email with a missing attachment — a payment
           request with no invoice attached is worse than no email. */
        $pdf = app(\App\Services\PlatformInvoicePdf::class)->render((string) $inv->number);
        if (! $pdf) {
            return response()->json(['message' => 'The invoice PDF could not be rendered, so nothing was sent.'], 500);
        }

        $currency = $inv->currency ?: 'CAD';
        $money = \App\Support\PlatformBilling::money((int) $inv->amount_cents, $currency);
        $due = $inv->due_at ? \Illuminate\Support\Carbon::parse($inv->due_at)->format('j M Y') : null;

        $body = '<p style="margin:0 0 14px;">Hello ' . e((string) $inv->agency_name) . ',</p>'
            . '<p style="margin:0 0 14px;line-height:1.6;">Please find invoice <strong>'
            . e((string) $inv->number) . '</strong> attached, for ' . e($money) . '.'
            . ($due ? ' It is due on ' . e($due) . '.' : '') . '</p>'
            . (trim((string) ($data['message'] ?? '')) !== ''
                ? '<p style="margin:0 0 14px;line-height:1.6;">' . nl2br(e(trim($data['message']))) . '</p>'
                : '')
            . '<p style="margin:16px 0 0;font-size:13px;color:#64748B;line-height:1.6;">'
            . 'Questions about this invoice? Reply to this email and it will reach us.</p>';

        // The ACCOUNTS template (2026-10-25): its own header, the invoice at a glance, how to pay, terms.
        [$acctSubject, $html] = \App\Support\PlatformAccounts::compose(\App\Support\PlatformAccounts::invoice($id), 'invoice');
        if (trim((string) ($data['message'] ?? '')) !== '') {
            $html = str_replace('<p style="margin:0 0 16px;line-height:1.65;">', '<p style="margin:0 0 16px;line-height:1.65;">' . nl2br(e(trim($data['message']))) . '</p><p style="margin:0 0 16px;line-height:1.65;">', $html);
        }

        $to = $toList;
        $bcc = array_values(array_diff(\App\Support\PlatformBilling::invoiceBcc((int) $inv->agency_id), $toList));   // 2026-10-25
        Mail::html($html, function ($m) use ($to, $inv, $pdf, $bcc, $acctSubject) {
            if ($bcc) $m->bcc($bcc);
            $m->to($to)
              ->from('noreply@kiddietrac.com', 'KiddieTrac Billing')
              ->replyTo('sales@kiddietrac.com', 'KiddieTrac Billing')
              ->subject($acctSubject);
            /* Operational billing mail. It must not be dropped by the agency
               notification switches, which govern parent/staff notifications. */
            $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            $m->attachData($pdf, $inv->number . '.pdf', ['mime' => 'application/pdf']);
        });

        $update = ['sent_at' => now(), 'sent_to' => mb_substr(implode(', ', $toList), 0, 190), 'updated_at' => now()];
        if (! empty($data['save']) && $inv->agency_id) {
            DB::table('agencies')->where('id', (int) $inv->agency_id)->update(['invoice_emails' => implode(',', $toList)]);
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => (int) $inv->agency_id, 'action' => 'platform.invoice_emails_saved', 'entity_type' => 'agency', 'entity_id' => (int) $inv->agency_id,
                'payload' => json_encode(['summary' => 'Saved the invoice addresses: ' . implode(', ', $toList)]), 'created_at' => now()]);
        }
        if ((string) $inv->status === 'draft') {
            $update['status'] = 'issued';
            $update['issued_at'] = now();
        }
        DB::table('platform_invoices')->where('id', $id)->update($update);

        return response()->json(['ok' => true, 'sent_to' => $toList, 'saved' => ! empty($data['save']), 'status' => $update['status'] ?? $inv->status]);
    }

    /**
     * Edit a DRAFT invoice.
     *
     * Drafts only. An issued invoice has been sent to the customer as a PDF, and quietly
     * changing the amount afterwards means our record and their copy disagree.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'subtotal_cents' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'tax_rate_bps' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'tax_label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'currency' => ['sometimes', 'string', 'in:CAD,USD'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'plan_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $inv = DB::table('platform_invoices')->where('id', $id)->first();
        if (! $inv) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        /* The gate is "has the customer seen it", NOT the status label. An invoice marked
           issued but never emailed has reached nobody and is still ours to correct.
           Gating on status === draft hid Edit on an issued-but-unsent invoice, which is
           precisely the case where editing is safe and useful. Once it HAS been emailed,
           their PDF and our row must never diverge — that is what Void is for. */
        if ($inv->sent_at) {
            return response()->json([
                'message' => $inv->number . ' was emailed to ' . $inv->sent_to . ' on '
                    . \Illuminate\Support\Carbon::parse($inv->sent_at)->format('j M Y')
                    . '. Void it and raise a replacement rather than changing what they hold.',
            ], 422);
        }
        if ((string) $inv->status === 'void') {
            return response()->json(['message' => 'A void invoice cannot be edited.'], 422);
        }
        if ((string) $inv->status === 'paid') {
            return response()->json(['message' => 'A paid invoice cannot be edited.'], 422);
        }
        if (! $data) {
            return response()->json(['message' => 'Nothing to change.'], 422);
        }

        /* Tax is always recomputed from the subtotal and rate, so the stored total can
           never disagree with the two numbers printed above it on the invoice. */
        $subtotal = array_key_exists('subtotal_cents', $data)
            ? (int) $data['subtotal_cents'] : (int) $inv->subtotal_cents;
        $bps = array_key_exists('tax_rate_bps', $data)
            ? (int) $data['tax_rate_bps'] : (int) $inv->tax_rate_bps;

        if ($bps > 0 && trim((string) ($data['tax_label'] ?? $inv->tax_label ?? '')) === '') {
            return response()->json([
                'message' => 'A tax rate needs a name for the invoice — HST, GST, VAT, Sales tax.',
            ], 422);
        }

        $data['subtotal_cents'] = $subtotal;
        $data['tax_rate_bps'] = $bps;
        $data['tax_cents'] = \App\Support\PlatformBilling::taxCents($subtotal, $bps);
        $data['amount_cents'] = \App\Support\PlatformBilling::totalCents($subtotal, $bps);

        if (array_key_exists('due_at', $data) && $data['due_at']) {
            $data['due_at'] = \Illuminate\Support\Carbon::parse($data['due_at'])->toDateString();
        }
        $data['updated_at'] = now();
        DB::table('platform_invoices')->where('id', $id)->update($data);

        return response()->json(['ok' => true]);
    }

    /**
     * Delete a DRAFT invoice.
     *
     * Drafts only, and never one that has been emailed. Anything the customer has seen
     * stays on the books as a void row, so the history still shows it existed. Deleting
     * an issued invoice would erase evidence of a claim we actually made.
     */
    public function destroy(int $id): JsonResponse
    {
        $inv = DB::table('platform_invoices')->where('id', $id)->first();
        if (! $inv) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }
        if ((string) $inv->status !== 'draft') {
            return response()->json([
                'message' => 'Only drafts can be deleted. ' . $inv->number . ' is '
                    . $inv->status . ' — use Void, which keeps the record.',
            ], 422);
        }
        if ($inv->sent_at) {
            return response()->json([
                'message' => 'This draft has already been emailed to ' . $inv->sent_to
                    . ', so it cannot be deleted. Void it instead.',
            ], 422);
        }

        if ((int) $inv->amount_paid_cents > 0) {
            return response()->json([
                'message' => 'This draft has a payment recorded against it and cannot be deleted.',
            ], 422);
        }

        DB::table('platform_invoices')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    /** The nightly-run switches, plus whether auto-email can actually arm. */
    public function automation(): JsonResponse
    {
        $get = fn (string $k) => DB::table('platform_settings')->where('key', $k)->value('value');
        $on = fn (string $k) => in_array(strtolower((string) $get($k)), ['1', 'true', 'yes', 'on'], true);

        $address = trim((string) $get('invoice.issuer.address'));
        $taxId = trim((string) $get('invoice.issuer.tax_id'));
        $missing = [];
        if ($address === '') { $missing[] = 'business address'; }
        if ($taxId === '') { $missing[] = 'tax registration number'; }

        return response()->json([
            'auto_raise' => $on('billing.auto_raise'),
            'auto_email' => $on('billing.auto_email'),
            /* The command enforces this regardless of the switch; the screen shows it so
               the switch is never silently inert. */
            'issuer_ready' => $missing === [],
            'issuer_missing' => $missing,
            'issuer' => ['address' => $address, 'tax_id' => $taxId],
        ]);
    }

    /** GET / PUT /platform/billing-recurring — on/off, all or selected agencies, the due-date rule, auto-email, BCC default. */
    public function recurring(): JsonResponse
    {
        $s = \App\Support\PlatformBilling::recurring();
        $s['auto_email'] = in_array(strtolower((string) DB::table('platform_settings')->where('key', 'billing.auto_email')->value('value')), ['1', 'true', 'yes', 'on'], true);
        $s['bcc_default'] = \App\Support\PlatformAccounts::settings()['bcc_default'] ?? 'none';
        $s['example_due'] = \App\Support\PlatformBilling::dueDate(\App\Support\AgencyTime::today(), $s);
        return response()->json($s);
    }

    public function saveRecurring(Request $request): JsonResponse
    {
        $d = $request->validate(['enabled' => ['required', 'boolean'], 'scope' => ['required', 'in:all,selected'], 'due_rule' => ['required', 'in:end_of_month,days_after,day_of_month'],
            'due_days' => ['nullable', 'integer', 'min:0', 'max:120'], 'due_day' => ['nullable', 'integer', 'min:1', 'max:28'], 'auto_email' => ['nullable', 'boolean'],
            'bcc_default' => ['nullable', 'in:none,superadmins,sales,both'], 'run_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/']]);
        $put = fn (string $k, string $v) => DB::table('platform_settings')->updateOrInsert(['key' => $k], ['value' => $v, 'updated_at' => now(), 'created_at' => now()]);
        $put('billing.recurring', json_encode(['scope' => $d['scope'], 'due_rule' => $d['due_rule'], 'due_days' => (int) ($d['due_days'] ?? 14), 'due_day' => (int) ($d['due_day'] ?? 1),
            'run_time' => $d['run_time'] ?? (\App\Support\PlatformBilling::recurring()['run_time'] ?? '06:00')]));
        $put('billing.auto_raise', $d['enabled'] ? 'true' : 'false');
        $put('billing.auto_email', ($d['enabled'] && ! empty($d['auto_email'])) ? 'true' : 'false');
        if (! empty($d['bcc_default'])) \App\Support\PlatformAccounts::saveSettings(['bcc_default' => $d['bcc_default']]);
        \Illuminate\Support\Facades\Cache::forget('platform_settings.all');
        \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => null, 'action' => 'platform.recurring_invoices_saved', 'entity_type' => 'platform_settings', 'entity_id' => null,
            'payload' => json_encode(['summary' => 'Recurring invoices ' . ($d['enabled'] ? 'ON' : 'OFF') . ' for ' . ($d['scope'] === 'all' ? 'all agencies' : 'selected agencies') . ', due ' . str_replace('_', ' ', $d['due_rule'])]), 'created_at' => now()]);
        return $this->recurring();
    }

    /** Set the switches, and the issuer details the invoice needs. */
    public function saveAutomation(Request $request): JsonResponse
    {
        $data = $request->validate([
            /* The switches are optional (2026-11-27): Settings > Billing now saves only the business details, and
               Accounting AR/AP > Settings > Recurring invoices is the one place they are switched. */
            'auto_raise' => ['sometimes', 'boolean'],
            'auto_email' => ['sometimes', 'boolean'],
            'issuer_address' => ['sometimes', 'nullable', 'string', 'max:400'],
            'issuer_tax_id' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        $put = function (string $k, string $v) {
            DB::table('platform_settings')->updateOrInsert(
                ['key' => $k],
                ['value' => $v, 'updated_at' => now(), 'created_at' => now()]
            );
        };

        if (array_key_exists('issuer_address', $data)) {
            $put('invoice.issuer.address', (string) $data['issuer_address']);
        }
        if (array_key_exists('issuer_tax_id', $data)) {
            $put('invoice.issuer.tax_id', (string) $data['issuer_tax_id']);
        }

        $on = fn (string $k) => in_array(strtolower((string) DB::table('platform_settings')->where('key', $k)->value('value')), ['1', 'true', 'yes', 'on'], true);
        if (array_key_exists('auto_raise', $data) || array_key_exists('auto_email', $data)) {
            /* auto_email without auto_raise cannot do anything — there would be nothing
               raised to send — so enabling it implies raising. */
            $email = array_key_exists('auto_email', $data) ? (bool) $data['auto_email'] : $on('billing.auto_email');
            $raise = (array_key_exists('auto_raise', $data) ? (bool) $data['auto_raise'] : $on('billing.auto_raise')) || $email;
            $put('billing.auto_raise', $raise ? 'true' : 'false');
            $put('billing.auto_email', $email ? 'true' : 'false');
        }
        \Illuminate\Support\Facades\Cache::forget('platform_settings.all');

        return response()->json(['ok' => true, 'auto_raise' => $on('billing.auto_raise'), 'auto_email' => $on('billing.auto_email')]);
    }

    /** Mark a draft as issued. Records the date; sends nothing. */
    public function issue(Request $request, int $id): JsonResponse
    {
        $inv = DB::table('platform_invoices')->where('id', $id)->first();
        if (! $inv) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($inv->status !== 'draft') {
            return response()->json(['message' => 'Only a draft can be issued.'], 422);
        }

        DB::table('platform_invoices')->where('id', $id)->update([
            'status' => 'issued',
            'issued_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'status' => 'issued']);
    }

    /**
     * POST /platform/invoices/{id}/payments {amount, method, reference?, paid_on?, notify?} — the payments
     * ledger (2026-10-25): full, part or early; receipt number; receipt / paid email to the agency.
     */
    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'method' => ['required', 'in:' . implode(',', array_keys(\App\Support\PlatformAccounts::METHODS))],
            'reference' => ['nullable', 'string', 'max:120'], 'paid_on' => ['nullable', 'date'], 'notify' => ['nullable', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);
        $res = \App\Support\PlatformAccounts::record($id, (int) round($d['amount'] * 100), $d['method'], $d['reference'] ?? null, $d['paid_on'] ?? null, (int) $request->user()->id, 'confirmed', $d['notify'] ?? true, $d['note'] ?? null);
        \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $res['invoice']->agency_id ?? null, 'action' => 'platform_invoice.payment_recorded', 'entity_type' => 'platform_invoice', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Recorded ' . \App\Support\PlatformBilling::money((int) round($d['amount'] * 100), $res['invoice']->currency ?? 'CAD') . ' on ' . ($res['invoice']->number ?? '') . ' (receipt ' . $res['payment']->receipt_number . ')' . ($res['sent'] ? '; emailed' : '')]), 'created_at' => now()]);
        return response()->json(['payment' => $res['payment'], 'status' => $res['invoice']->status, 'amount_paid_cents' => (int) $res['invoice']->amount_paid_cents, 'sent' => $res['sent']]);
    }

    /** GET /platform/invoices/payments?status=pending — payments agencies reported, waiting for you. */
    public function pendingPayments(Request $request): JsonResponse
    {
        // ?all=1: the whole ledger (payments, refunds, returns), newest first, for Accounting AR/AP -> Payments.
        $all = $request->boolean('all');
        $rows = DB::table('platform_invoice_payments as p')->join('platform_invoices as i', 'i.id', '=', 'p.invoice_id')->leftJoin('agencies as a', 'a.id', '=', 'p.agency_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.reported_by')
            ->when(! $all, fn ($q) => $q->where('p.status', 'pending')->orderBy('p.id'))
            ->when($all, fn ($q) => $q->orderByDesc('p.paid_on')->orderByDesc('p.id')->limit(300))
            ->get(['p.*', 'i.number', 'i.currency as inv_currency', 'a.name as agency_name', DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as reported_by_name")]);
        return response()->json(['payments' => $rows, 'methods' => \App\Support\PlatformAccounts::METHODS]);
    }

    /** POST /platform/invoices/payments/{payment}/confirm | /reject */
    public function confirmPayment(Request $request, int $payment): JsonResponse
    {
        $res = \App\Support\PlatformAccounts::confirm($payment, (int) $request->user()->id, (bool) $request->input('notify', true));
        \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $res['invoice']->agency_id ?? null, 'action' => 'platform_invoice.payment_confirmed', 'entity_type' => 'platform_invoice_payment', 'entity_id' => $payment,
            'payload' => json_encode(['summary' => 'Confirmed a reported payment (receipt ' . $res['payment']->receipt_number . ')']), 'created_at' => now()]);
        return response()->json(['payment' => $res['payment'], 'status' => $res['invoice']->status ?? null, 'sent' => $res['sent']]);
    }

    public function rejectPayment(Request $request, int $payment): JsonResponse
    {
        $p = DB::table('platform_invoice_payments')->where('id', $payment)->where('status', 'pending')->first();
        abort_unless($p, 404, 'That payment is not waiting for a decision.');
        DB::table('platform_invoice_payments')->where('id', $payment)->update(['status' => 'rejected', 'note' => trim((string) $p->note . ' · Not received: ' . trim((string) $request->input('reason', ''))), 'updated_at' => now()]);
        if ($p->reported_by) \App\Support\Notify::write([['user_id' => (int) $p->reported_by, 'type' => 'platform_invoice', 'title' => 'A payment you reported could not be matched',
            'body' => 'KiddieTrac could not find the ' . \App\Support\PlatformBilling::money((int) $p->amount_cents, $p->currency) . ' payment. ' . trim((string) $request->input('reason', '')), 'data' => json_encode(['link' => '#licence']), 'created_at' => now()]]);
        \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $p->agency_id, 'action' => 'platform_invoice.payment_rejected', 'entity_type' => 'platform_invoice_payment', 'entity_id' => $payment,
            'payload' => json_encode(['summary' => 'Marked a reported payment as not received']), 'created_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** GET / PUT /platform/billing-emails — reminder cadence, recipients, paid emails, prepayment, terms. */
    public function billingEmails(): JsonResponse
    {
        return response()->json(['settings' => \App\Support\PlatformAccounts::settings(), 'defaults' => \App\Support\PlatformAccounts::DEFAULTS]);
    }

    public function saveBillingEmails(Request $request): JsonResponse
    {
        $s = \App\Support\PlatformAccounts::saveSettings($request->all());
        \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => null, 'action' => 'platform.billing_emails_saved', 'entity_type' => 'platform_settings', 'entity_id' => null,
            'payload' => json_encode(['summary' => 'Changed the KiddieTrac billing emails (reminders ' . ($s['reminders_enabled'] ? 'on' : 'off') . ')']), 'created_at' => now()]);
        return response()->json(['settings' => $s]);
    }

    /* ── lines, extras, NSF (2026-10-25) ─────────────────────────────────────────────── */

    /** GET /platform/invoices/{id}/detail — every line and every payment. */
    public function detail(int $id): JsonResponse
    {
        $inv = \App\Support\PlatformAccounts::invoice($id);
        abort_unless($inv, 404, 'Invoice not found.');
        $lines = DB::table('platform_invoice_lines')->where('invoice_id', $id)->orderBy('id')->get()
            ->map(function ($l) use ($inv) { $l->taxable = \App\Support\PlatformAccounts::lineTaxable($l, $inv); return $l; });
        $pays = DB::table('platform_invoice_payments')->where('invoice_id', $id)->orderBy('paid_on')->orderBy('id')->get();
        return response()->json(['invoice' => $inv, 'lines' => $lines, 'payments' => $pays, 'methods' => \App\Support\PlatformAccounts::METHODS,
            'customer_number' => \App\Support\SalesCustomers::numberForAgency($inv->agency_id ? (int) $inv->agency_id : null),
            'balance_cents' => \App\Support\PlatformAccounts::balance($inv), 'nsf_fee_cents' => (int) \App\Support\PlatformAccounts::settings()['nsf_fee_cents'],
            'nsf_enabled' => (bool) \App\Support\PlatformAccounts::settings()['nsf_enabled']]);
    }

    private function openInvoice(int $id): object
    {
        $inv = DB::table('platform_invoices')->where('id', $id)->first();
        abort_unless($inv, 404, 'Invoice not found.');
        abort_if(in_array($inv->status, ['void', 'paid'], true), 422, 'Lines can only change on an open invoice (not paid or void).');
        return $inv;
    }

    /** POST /platform/invoices/{id}/lines {description, qty, unit, taxable, part_number?} — a one-off line on an open invoice. */
    public function addLine(Request $request, int $id): JsonResponse
    {
        $inv = $this->openInvoice($id);
        $d = $request->validate(['description' => ['required', 'string', 'max:255'], 'qty' => ['nullable', 'numeric', 'min:0.01', 'max:100000'],
            'unit' => ['required', 'numeric', 'min:-100000', 'max:100000'], 'taxable' => ['nullable', 'boolean'], 'part_number' => ['nullable', 'string', 'max:20']]);
        $lid = DB::transaction(fn () => \App\Support\PlatformAccounts::addLine($id, ['kind' => 'manual', 'description' => $d['description'], 'part_number' => $d['part_number'] ?? null,
            'qty' => (float) ($d['qty'] ?? 1), 'unit_cents' => (int) round($d['unit'] * 100), 'taxable' => (bool) ($d['taxable'] ?? true),
            'period_start' => $inv->period_start, 'period_end' => $inv->period_end]));
        $fresh = \App\Support\PlatformAccounts::recompute($id);
        \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $inv->agency_id, 'action' => 'platform_invoice.line_added', 'entity_type' => 'platform_invoice', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Added "' . $d['description'] . '" to ' . $inv->number . ' (now ' . \App\Support\PlatformBilling::money((int) $fresh->amount_cents, $inv->currency) . ')']), 'created_at' => now()]);
        return response()->json(['line_id' => $lid, 'amount_cents' => (int) $fresh->amount_cents]);
    }

    /** DELETE /platform/invoices/{id}/lines/{line} — a manual or extra line, or an NSF fee (waived). */
    public function removeLine(Request $request, int $id, int $line): JsonResponse
    {
        $inv = $this->openInvoice($id);
        $ln = DB::table('platform_invoice_lines')->where('id', $line)->where('invoice_id', $id)->first();
        abort_unless($ln, 404, 'Line not found.');
        abort_unless(in_array($ln->kind, ['manual', 'extra', 'nsf'], true), 422, $ln->kind === 'late' ? 'Use Waive late fees for late fees.' : 'Licence and surcharge lines cannot be removed here.');
        DB::transaction(fn () => \App\Support\PlatformAccounts::removeLine($line));
        $fresh = \App\Support\PlatformAccounts::recompute($id);
        \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $inv->agency_id, 'action' => 'platform_invoice.line_removed', 'entity_type' => 'platform_invoice', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Removed "' . $ln->description . '" from ' . $inv->number]), 'created_at' => now()]);
        return response()->json(['amount_cents' => (int) $fresh->amount_cents]);
    }

    /** POST /platform/invoices/{id}/waive-late-fees */
    public function waiveLateFees(Request $request, int $id): JsonResponse
    {
        $this->openInvoice($id);
        $sum = \App\Support\PlatformAccounts::waiveLateFees($id, (int) $request->user()->id);
        return response()->json(['waived_cents' => $sum]);
    }

    /** POST /platform/invoices/payments/{payment}/return {reason?, charge_fee?, notify?} — NSF / returned payment. */
    public function returnPayment(Request $request, int $payment): JsonResponse
    {
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:255'], 'charge_fee' => ['nullable', 'boolean'], 'notify' => ['nullable', 'boolean']]);
        $res = \App\Support\PlatformAccounts::returnPayment($payment, (int) $request->user()->id, trim((string) ($d['reason'] ?? '')), (bool) ($d['charge_fee'] ?? true), (bool) ($d['notify'] ?? true));
        return response()->json(['status' => $res['invoice']->status ?? null, 'fee_cents' => $res['fee_cents'], 'sent' => $res['sent']]);
    }

    /**
     * POST /platform/invoices — a ONE-OFF invoice (2026-10-25): set-up, training, hardware, anything outside a
     * licence renewal. {agency_id, lines:[{description, qty?, unit, taxable?, part_number?}], due_at?, notes?,
     * tax_rate_bps?, tax_label?}. Always a DRAFT: issuing and emailing stay their own deliberate steps.
     */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'agency_id' => ['required', 'integer', 'exists:agencies,id'],
            'lines' => ['required', 'array', 'min:1', 'max:40'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0.01', 'max:100000'],
            'lines.*.unit' => ['required', 'numeric', 'min:-1000000', 'max:1000000'],
            'lines.*.taxable' => ['nullable', 'boolean'],
            'lines.*.part_number' => ['nullable', 'string', 'max:20'],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'tax_rate_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'tax_label' => ['nullable', 'string', 'max:40'],
        ]);
        $a = DB::table('agencies')->where('id', $d['agency_id'])->first();
        $bps = array_key_exists('tax_rate_bps', $d) && $d['tax_rate_bps'] !== null ? (int) $d['tax_rate_bps'] : (int) ($a->tax_rate_bps ?? 0);
        $label = trim((string) ($d['tax_label'] ?? $a->tax_label ?? '')) ?: ($bps > 0 ? 'HST' : null);
        $today = \App\Support\AgencyTime::todayDate();
        $n = (int) DB::table('platform_invoices')->where('agency_id', $a->id)->where('series', 'like', 'oneoff-%')->count() + 1;
        $number = 'KT-' . $today->format('Ymd') . '-' . str_pad((string) $a->id, 4, '0', STR_PAD_LEFT) . '-' . $n;
        while (DB::table('platform_invoices')->where('number', $number)->exists()) { $n++; $number = 'KT-' . $today->format('Ymd') . '-' . str_pad((string) $a->id, 4, '0', STR_PAD_LEFT) . '-' . $n; }
        $id = DB::transaction(function () use ($a, $d, $bps, $label, $today, $n, $number, $request) {
            $id = DB::table('platform_invoices')->insertGetId([
                'agency_id' => $a->id, 'number' => $number, 'series' => 'oneoff-' . $n, 'period_start' => $today->toDateString(), 'period_end' => $today->toDateString(),
                'plan_code' => $a->plan_code, 'subtotal_cents' => 0, 'tax_cents' => 0, 'tax_rate_bps' => $bps, 'tax_label' => $label, 'amount_cents' => 0, 'amount_paid_cents' => 0,
                'currency' => \App\Support\PlatformBilling::normaliseCurrency($a->plan_currency ?? null), 'status' => 'draft',
                'due_at' => ! empty($d['due_at']) ? \Carbon\Carbon::parse($d['due_at'])->toDateString() : $today->copy()->addDays(14)->toDateString(),
                'notes' => $d['notes'] ?? null, 'created_by_id' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($d['lines'] as $ln) {
                \App\Support\PlatformAccounts::addLine($id, ['kind' => 'manual', 'description' => $ln['description'], 'part_number' => $ln['part_number'] ?? null,
                    'qty' => (float) ($ln['qty'] ?? 1), 'unit_cents' => (int) round($ln['unit'] * 100), 'taxable' => (bool) ($ln['taxable'] ?? true),
                    'period_start' => $today->toDateString(), 'period_end' => $today->toDateString()]);
            }
            return $id;
        });
        $inv = DB::table('platform_invoices')->find($id);
        \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $a->id, 'action' => 'platform_invoice.created', 'entity_type' => 'platform_invoice', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Created one-off invoice ' . $number . ' for ' . $a->name . ': ' . \App\Support\PlatformBilling::money((int) $inv->amount_cents, $inv->currency) . ' (draft)']), 'created_at' => now()]);
        return response()->json(['invoice' => $inv], 201);
    }

    /** PUT /platform/invoices/{id}/edit {lines:[{id?, description, qty, unit, taxable, part_number?}], due_at?, notes?, tax_rate_bps?, tax_label?, reason?} */
    public function editInvoice(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['lines' => ['required', 'array', 'min:1', 'max:60'], 'lines.*.id' => ['nullable', 'integer'], 'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.qty' => ['nullable', 'numeric', 'min:0.01', 'max:100000'], 'lines.*.unit' => ['required', 'numeric', 'min:-1000000', 'max:1000000'], 'lines.*.taxable' => ['nullable', 'boolean'],
            'lines.*.part_number' => ['nullable', 'string', 'max:20'], 'due_at' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:1000'],
            'tax_rate_bps' => ['nullable', 'integer', 'min:0', 'max:10000'], 'tax_label' => ['nullable', 'string', 'max:40'], 'reason' => ['nullable', 'string', 'max:200']]);
        $inv = \App\Support\PlatformAccounts::editInvoice($id, $d, (int) $request->user()->id);
        return response()->json(['invoice' => ['id' => (int) $inv->id, 'number' => $inv->number, 'amount_cents' => (int) $inv->amount_cents, 'status' => $inv->status, 'agency_email' => $inv->agency_email]]);
    }

    /** POST /platform/invoices/{id}/refund {mode: full|partial, amount?, reason, notes?, credit?, notify?} */
    public function refundInvoice(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['mode' => ['required', 'in:full,partial'], 'amount' => ['required_if:mode,partial', 'nullable', 'numeric', 'min:0.01'], 'reason' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:500'], 'credit' => ['nullable', 'boolean'], 'notify' => ['nullable', 'boolean']]);
        $res = \App\Support\PlatformAccounts::refundInvoice($id, $d['mode'] === 'full' ? null : (int) round($d['amount'] * 100), trim($d['reason']), trim((string) ($d['notes'] ?? '')),
            (bool) ($d['credit'] ?? true), (bool) ($d['notify'] ?? true), (int) $request->user()->id);
        return response()->json(['refunded_cents' => array_sum(array_map(fn ($r) => (int) $r->amount_cents, $res['refunds'])), 'receipts' => array_map(fn ($r) => $r->receipt_number, $res['refunds']),
            'status' => $res['invoice']->status ?? null, 'sent' => $res['sent']]);
    }

    /** POST /platform/invoices/{id}/reissue {reason?} — void and raise a corrected copy from what is live now. */
    public function reissue(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $new = \App\Support\PlatformAccounts::reissue($id, (int) $request->user()->id, trim((string) ($d['reason'] ?? '')));
        return response()->json(['invoice' => ['id' => (int) $new->id, 'number' => $new->number, 'amount_cents' => (int) $new->amount_cents, 'status' => $new->status, 'agency_email' => $new->agency_email]]);
    }

    /** POST /platform/invoices/payments/{payment}/refund {amount, reason?, credit?, notify?} */
    public function refundPayment(Request $request, int $payment): JsonResponse
    {
        $d = $request->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'reason' => ['nullable', 'string', 'max:255'], 'credit' => ['nullable', 'boolean'], 'notify' => ['nullable', 'boolean']]);
        $res = \App\Support\PlatformAccounts::refund($payment, (int) round($d['amount'] * 100), (int) $request->user()->id, trim((string) ($d['reason'] ?? '')), (bool) ($d['credit'] ?? true), (bool) ($d['notify'] ?? true));
        return response()->json(['refund' => $res['refund'], 'status' => $res['invoice']->status ?? null, 'sent' => $res['sent']]);
    }

    /** GET /platform/billing-extras — every agency's extra line items, plus the agencies to pick from. */
    public function extras(): JsonResponse
    {
        $rows = DB::table('platform_invoice_extras as x')->leftJoin('agencies as a', 'a.id', '=', 'x.agency_id')->orderByDesc('x.active')->orderBy('a.name')->orderBy('x.id')
            ->get(['x.*', 'a.name as agency_name', 'a.plan_currency as currency']);
        $agencies = DB::table('agencies')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);
        return response()->json(['extras' => $rows, 'agencies' => $agencies]);
    }

    /** POST /platform/billing-extras {agency_id, description, qty?, unit, taxable?, recurring?, starts_on?, ends_on?, part_number?} */
    public function saveExtra(Request $request, ?int $extra = null): JsonResponse
    {
        $d = $request->validate(['agency_id' => [$extra ? 'nullable' : 'required', 'integer', 'exists:agencies,id'], 'description' => [$extra ? 'nullable' : 'required', 'string', 'max:255'],
            'qty' => ['nullable', 'numeric', 'min:0.01', 'max:100000'], 'unit' => [$extra ? 'nullable' : 'required', 'numeric', 'min:-100000', 'max:100000'],
            'taxable' => ['nullable', 'boolean'], 'recurring' => ['nullable', 'boolean'], 'active' => ['nullable', 'boolean'],
            'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date'], 'part_number' => ['nullable', 'string', 'max:20']]);
        $row = array_filter([
            'agency_id' => $d['agency_id'] ?? null, 'description' => $d['description'] ?? null, 'qty' => isset($d['qty']) ? (float) $d['qty'] : null,
            'unit_cents' => isset($d['unit']) ? (int) round($d['unit'] * 100) : null, 'part_number' => $d['part_number'] ?? null,
            'starts_on' => $d['starts_on'] ?? null, 'ends_on' => $d['ends_on'] ?? null,
        ], fn ($v) => $v !== null);
        foreach (['taxable', 'recurring', 'active'] as $k) if (array_key_exists($k, $d) && $d[$k] !== null) $row[$k] = (bool) $d[$k];
        if (array_key_exists('ends_on', $request->all()) && ! $request->input('ends_on')) $row['ends_on'] = null;
        if ($extra) {
            abort_unless(DB::table('platform_invoice_extras')->where('id', $extra)->exists(), 404, 'Not found.');
            DB::table('platform_invoice_extras')->where('id', $extra)->update($row + ['updated_at' => now()]);
            $id = $extra;
        } else {
            $id = DB::table('platform_invoice_extras')->insertGetId($row + ['qty' => $row['qty'] ?? 1, 'created_by_id' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $x = DB::table('platform_invoice_extras')->find($id);
        \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $x->agency_id, 'action' => 'platform_invoice.extra_saved', 'entity_type' => 'platform_invoice_extra', 'entity_id' => $id,
            'payload' => json_encode(['summary' => ($extra ? 'Changed' : 'Added') . ' extra line item "' . $x->description . '" (' . \App\Support\PlatformBilling::money((int) round((float) $x->qty * (int) $x->unit_cents), 'CAD') . ($x->recurring ? ', every invoice' : ', next invoice only') . ($x->active ? '' : ', paused') . ')']), 'created_at' => now()]);
        return response()->json(['extra' => $x], $extra ? 200 : 201);
    }

    /* ── referral credits (2026-11-26, App\Support\ReferralCredits) ─────────────────────────── */

    /** GET /platform/referral-credits: every credit, plus the customers and leads to pick from. */
    public function referralCredits(): JsonResponse
    {
        $rows = \Illuminate\Support\Facades\Schema::hasTable('referral_credits')
            ? DB::table('referral_credits')->orderByRaw("FIELD(status,'pending','partially_applied','applied','cancelled')")->orderByDesc('id')->get()->map(fn ($c) => \App\Support\ReferralCredits::row($c))->all() : [];
        $nums = \App\Support\SalesCustomers::numbersForAgencies(DB::table('agencies')->whereNull('deleted_at')->pluck('id')->all());
        $agencies = DB::table('agencies')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'plan_currency'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->name, 'customer_number' => $nums[(int) $a->id] ?? null, 'currency' => $a->plan_currency ?: 'CAD'])->all();
        // The "They referred" picker: leads + customers without a lead, test agencies tagged (ReferralCredits::parties).
        return response()->json(['credits' => $rows, 'agencies' => $agencies, 'parties' => \App\Support\ReferralCredits::parties(), 'this_month' => \App\Support\AgencyTime::todayDate()->format('Y-m')]);
    }

    /** POST /platform/referral-credits {agency_id, referred?: "L:<lead>"|"A:<agency>"|empty, type: fixed|percent, value, month: YYYY-MM, notes (required with no referral)} */
    public function saveReferralCredit(Request $request): JsonResponse
    {
        $d = $request->validate(['agency_id' => ['required', 'integer'], 'referred' => ['nullable', 'string', 'max:24'], 'referred_lead_id' => ['nullable', 'integer'], 'type' => ['required', 'in:fixed,percent'],
            'value' => ['required', 'numeric', 'min:0.01', 'max:100000'], 'month' => ['required', 'string', 'max:7'], 'notes' => ['nullable', 'string', 'max:500']]);
        return response()->json(['credit' => \App\Support\ReferralCredits::create($d, (int) $request->user()->id)], 201);
    }

    /** POST /platform/referral-credits/{id}/cancel: only before it is fully used; what is already on an invoice stays. */
    public function cancelReferralCredit(Request $request, int $id): JsonResponse
    {
        return response()->json(['credit' => \App\Support\ReferralCredits::cancel($id, (int) $request->user()->id)]);
    }

    public function deleteExtra(Request $request, int $extra): JsonResponse
    {
        $x = DB::table('platform_invoice_extras')->find($extra);
        abort_unless($x, 404, 'Not found.');
        DB::table('platform_invoice_extras')->where('id', $extra)->delete();
        \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $x->agency_id, 'action' => 'platform_invoice.extra_deleted', 'entity_type' => 'platform_invoice_extra', 'entity_id' => $extra,
            'payload' => json_encode(['summary' => 'Removed extra line item "' . $x->description . '"']), 'created_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** Record a payment. A partial payment is allowed and leaves the invoice unpaid. (Now through the ledger.) */
    public function markPaid(Request $request, int $id): JsonResponse
    {
        $inv0 = DB::table('platform_invoices')->where('id', $id)->first();
        if ($inv0 && $inv0->status !== 'void') {
            $amt = $request->input('amount_cents') !== null ? (int) $request->input('amount_cents') : max(0, (int) $inv0->amount_cents - (int) $inv0->amount_paid_cents);
            if ($amt > 0) {
                $res = \App\Support\PlatformAccounts::record($id, $amt, 'other', $request->input('reference'), null, (int) $request->user()->id);
                return response()->json(['ok' => true, 'status' => $res['invoice']->status, 'amount_paid_cents' => (int) $res['invoice']->amount_paid_cents]);
            }
        }
        $data = $request->validate([
            'amount_cents' => ['nullable', 'integer', 'min:0'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $inv = DB::table('platform_invoices')->where('id', $id)->first();
        if (! $inv) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($inv->status === 'void') {
            return response()->json(['message' => 'A void invoice cannot be paid.'], 422);
        }

        /* No amount means "paid in full" — the common case, and retyping the exact figure
           is an invitation to mistype it. */
        $paid = $data['amount_cents'] ?? (int) $inv->amount_cents;
        $total = (int) $inv->amount_paid_cents + (int) $paid;
        $settled = $total >= (int) $inv->amount_cents;

        DB::table('platform_invoices')->where('id', $id)->update([
            'amount_paid_cents' => $total,
            'status' => $settled ? 'paid' : $inv->status,
            'paid_at' => $settled ? now() : $inv->paid_at,
            'payment_reference' => $data['reference'] ?? $inv->payment_reference,
            'updated_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'status' => $settled ? 'paid' : $inv->status,
            'amount_paid_cents' => $total,
        ]);
    }

    /** Cancel an invoice. Never deleted — the number stays spent and auditable. */
    public function void(Request $request, int $id): JsonResponse
    {
        $inv = DB::table('platform_invoices')->where('id', $id)->first();
        if (! $inv) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($inv->status === 'paid') {
            return response()->json(['message' => 'A paid invoice cannot be voided — refund it instead.'], 422);
        }

        DB::table('platform_invoices')->where('id', $id)->update([
            'status' => 'void',
            'notes' => trim((string) $inv->notes . ' [voided ' . \App\Support\AgencyTime::today() . ']'),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'status' => 'void']);
    }
}
