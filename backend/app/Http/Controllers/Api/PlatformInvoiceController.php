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
                'a.contact_email as agency_email',
                'pi.sent_at', 'pi.sent_to',
                'pi.subtotal_cents', 'pi.tax_cents', 'pi.tax_rate_bps', 'pi.tax_label',
            ]);

        /* Totals exclude void. A cancelled invoice is not money owed, and counting it is
           exactly how the agency receivables figure was overstated by $4,046 earlier. */
        $live = $rows->where('status', '!=', 'void');
        $today = now()->toDateString();

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

        return response()->json([
            'invoices' => $rows->values()->all(),
            'totals' => $totals,
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
            'intervals' => \App\Support\PlatformBilling::INTERVALS,
        ]);
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
        ]);

        if (! DB::table('agencies')->where('id', $agencyId)->exists()) {
            return response()->json(['message' => 'Agency not found.'], 404);
        }

        /* A tax rate with no name prints as a bare "Tax" line on a real invoice, which
           is not acceptable in a jurisdiction that requires it named (HST, GST, VAT). */
        if ((int) $data['tax_rate_bps'] > 0 && trim((string) ($data['tax_label'] ?? '')) === '') {
            return response()->json([
                'message' => 'A tax rate needs a name for the invoice — HST, GST, VAT, Sales tax.',
            ], 422);
        }

        $data['next_invoice_at'] = $data['next_invoice_at']
            ? \Illuminate\Support\Carbon::parse($data['next_invoice_at'])->toDateString()
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
            return response()->json([
                'preview' => true,
                'today' => now()->toDateString(),
                'would_raise' => $billable,
                'skipped' => array_values(array_filter($plan, fn ($p) => $p['skip_reason'] !== null)),
            ]);
        }

        $result = $raiser->commit($billable, optional($request->user())->id);

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
            'to' => ['required', 'email:rfc'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

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

        $html = \App\Services\EmailTemplate::wrap(null, $body, [
            'eyebrow' => 'INVOICE',
            'title' => 'Invoice ' . $inv->number,
            'subtitle' => $money . ($due ? ' — due ' . $due : ''),
            'preheader' => 'Invoice ' . $inv->number . ' for ' . $money . '.',
        ]);

        $to = $data['to'];
        Mail::html($html, function ($m) use ($to, $inv, $pdf) {
            $m->to($to)
              ->from('noreply@kiddietrac.com', 'KiddieTrac')
              ->replyTo('sales@kiddietrac.com', 'KiddieTrac')
              ->subject('Invoice ' . $inv->number . ' from KiddieTrac');
            /* Operational billing mail. It must not be dropped by the agency
               notification switches, which govern parent/staff notifications. */
            $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            $m->attachData($pdf, $inv->number . '.pdf', ['mime' => 'application/pdf']);
        });

        $update = ['sent_at' => now(), 'sent_to' => $to, 'updated_at' => now()];
        if ((string) $inv->status === 'draft') {
            $update['status'] = 'issued';
            $update['issued_at'] = now();
        }
        DB::table('platform_invoices')->where('id', $id)->update($update);

        return response()->json(['ok' => true, 'sent_to' => $to, 'status' => $update['status'] ?? $inv->status]);
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

    /** Set the switches, and the issuer details the invoice needs. */
    public function saveAutomation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auto_raise' => ['required', 'boolean'],
            'auto_email' => ['required', 'boolean'],
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

        /* auto_email without auto_raise cannot do anything — there would be nothing
           raised to send — so enabling it implies raising. */
        $raise = $data['auto_raise'] || $data['auto_email'];
        $put('billing.auto_raise', $raise ? 'true' : 'false');
        $put('billing.auto_email', $data['auto_email'] ? 'true' : 'false');

        return response()->json(['ok' => true, 'auto_raise' => $raise, 'auto_email' => (bool) $data['auto_email']]);
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

    /** Record a payment. A partial payment is allowed and leaves the invoice unpaid. */
    public function markPaid(Request $request, int $id): JsonResponse
    {
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
            'notes' => trim((string) $inv->notes . ' [voided ' . now()->toDateString() . ']'),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'status' => 'void']);
    }
}
