<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * THE iLEARN INVOICE, REDRAWN FROM KIDDIETRAC'S OWN DATA (2026-09-17).
 *
 * Anthony: "please use the template from the ilearn invoice and bring this over to
 * kiddietrac for ilearn only — look at the existing pdfs produced and recreate this
 * exactly."
 *
 * Every other iLearn family's invoice is an iLearn document: raised at ilearnhcc.com,
 * numbered iL-INV-…, and fetched by KiddieTrac when one is emailed. The families now
 * being billed THROUGH KiddieTrac get a KiddieTrac invoice instead, and the two looked
 * nothing alike — which is the whole complaint. So this reproduces iLearn's
 * `resources/views/print/invoice.blade.php` and its `<x-print.layout>` wrapper, read
 * from the iLearn source rather than guessed from a screenshot: the same purple rule
 * under the header, the same logo block and right-aligned org panel, the same
 * Bill-to/Status/Date/Due meta table, the same Description/Qty/Amount lines, the same
 * totals block, the PAID IN FULL stamp and watermark, the Interac reminder, the payment
 * terms, the policy line and the yellow "file this away" note.
 *
 * WHAT IS DELIBERATELY NOT COPIED: iLearn's record ids (REC/SUP numbers), its payment
 * deep-link, and its contact-derived child list. Those name rows in iLearn's database
 * that have no counterpart here, and inventing them would put fiction on an invoice.
 * The layout holds without them.
 *
 * Fields come from KiddieTrac: invoices + invoice_lines + families + guardians +
 * agencies branding. Money is formatted the same way, and the tax line is only shown
 * when there is tax, matching what the agency's existing documents do.
 */
final class IlearnInvoiceRenderer
{
    /** Brand purple from iLearn's own stylesheet (--brand). */
    private const BRAND = '#7C3AED';

    public function renderFromInvoiceId(int $invoiceId, bool $forPdf = true): ?string
    {
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        if (! $inv) {
            return null;
        }

        $agencyId = (int) DB::table('centres')->where('id', $inv->centre_id)->value('agency_id');
        $agency = $agencyId ? DB::table('agencies')->where('id', $agencyId)->first() : null;
        $family = DB::table('families')->where('id', $inv->family_id)->first();

        $settings = [];
        if ($agency && $agency->settings) {
            try { $settings = json_decode((string) $agency->settings, true) ?: []; } catch (Throwable $e) {}
        }

        $agencyName = $agency->name ?? 'Your childcare agency';
        $agencyAddr = trim((string) ($settings['brand_address'] ?? ''));
        $agencyEmail = (string) ($agency->contact_email ?? $agency->brand_support_email ?? '');
        $agencyPhone = (string) ($agency->contact_phone ?? '');
        $logo = $this->inlineLogo($agency->brand_logo_url ?? $agency->logo_url ?? null);

        $number = (string) ($inv->invoice_number ?: ('INV-' . $inv->id));
        $status = ucfirst((string) ($inv->status ?: 'draft'));
        $isPaid = strtolower((string) $inv->status) === 'paid';

        // Bill-to: the family, with its primary guardian's address and email.
        $party = (string) ($family->family_name ?? 'Family');
        $billAddr = $this->familyAddress($family);
        $partyEmail = $this->primaryGuardianEmail((int) $inv->family_id)
            ?: (string) ($family->primary_email ?? '');

        $lines = DB::table('invoice_lines')->where('invoice_id', $invoiceId)->get();

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $money = fn ($v) => '$' . number_format((float) $v, 2);
        $date = function ($v) {
            if (! $v) { return '—'; }
            try { return Carbon::parse($v)->format('F j, Y'); } catch (Throwable $ex) { return '—'; }
        };

        $rows = '';
        if ($lines->isEmpty()) {
            $desc = trim((string) ($inv->notes ?? '')) ?: 'Child care services';
            $rows = '<tr><td>' . $e($desc) . '</td><td class="num">1</td><td class="num">'
                . $money($inv->subtotal ?: $inv->total) . '</td></tr>';
        } else {
            foreach ($lines as $l) {
                $qty = (float) ($l->quantity ?? 1);
                $amount = isset($l->amount) ? (float) $l->amount : ($qty * (float) ($l->unit_amount ?? 0));
                $rows .= '<tr><td>' . $e($l->description ?? '—') . '</td>'
                    . '<td class="num">' . rtrim(rtrim(number_format($qty, 2), '0'), '.') . '</td>'
                    . '<td class="num">' . $money($amount) . '</td></tr>';
            }
        }

        $totals = '<tr><td>Subtotal</td><td>' . $money($inv->subtotal ?? $inv->total) . '</td></tr>';
        if ((float) ($inv->subsidy_amount ?? 0) > 0) {
            $totals .= '<tr><td>Subsidy</td><td>−' . $money($inv->subsidy_amount) . '</td></tr>';
        }
        if ((float) ($inv->discount_amount ?? 0) > 0) {
            $totals .= '<tr><td>Discount</td><td>−' . $money($inv->discount_amount) . '</td></tr>';
        }
        if ((float) ($inv->tax_amount ?? 0) > 0) {
            $totals .= '<tr><td>Tax</td><td>' . $money($inv->tax_amount) . '</td></tr>';
        }
        $totals .= '<tr class="grand"><td>Total</td><td>' . $money($inv->total) . '</td></tr>';
        if ((float) ($inv->amount_paid ?? 0) > 0 && ! $isPaid) {
            $totals .= '<tr><td>Paid to date</td><td>' . $money($inv->amount_paid) . '</td></tr>'
                . '<tr class="grand"><td>Balance due</td><td>' . $money($inv->balance_due) . '</td></tr>';
        }

        $terms = trim((string) ($settings['invoice_terms'] ?? ''));
        $policy = trim((string) ($settings['payment_policy'] ?? ''));
        $bank = trim((string) ($agency->brand_bank_info ?? ''));

        $body = '<table class="meta" style="width:100%;border-collapse:collapse">'
            . '<tr>'
            . '<td style="vertical-align:top;width:55%;padding:2px 0">'
            . '<span class="k">Bill to:</span> <strong>' . $e($party) . '</strong>'
            . ($billAddr ? '<div style="margin-top:2px;font-size:.88rem;line-height:1.4;color:#334155">'
                . '<span class="k">Address:</span> <span style="white-space:pre-line">' . $e($billAddr) . '</span></div>' : '')
            . ($partyEmail ? '<div style="margin-top:2px"><span class="k">Email:</span> ' . $e($partyEmail) . '</div>' : '')
            . '</td>'
            . '<td style="vertical-align:top;width:45%;padding:2px 0"><span class="k">Status:</span> ' . $e($status) . '</td>'
            . '</tr>'
            . '<tr>'
            . '<td style="padding:2px 0"><span class="k">Date:</span> ' . $e($date($inv->issued_at)) . '</td>'
            . '<td style="padding:2px 0"><span class="k">Due:</span> ' . $e($date($inv->due_at)) . '</td>'
            . '</tr>'
            . ($inv->period_start && $inv->period_end
                ? '<tr><td colspan="2" style="padding:2px 0"><span class="k">Period:</span> '
                  . $e($date($inv->period_start)) . ' – ' . $e($date($inv->period_end)) . '</td></tr>'
                : '')
            . '</table>'

            . '<table class="lines"><thead><tr><th>Description</th><th class="num">Qty</th>'
            . '<th class="num">Amount</th></tr></thead><tbody>' . $rows . '</tbody></table>'

            . '<table class="totals">' . $totals . '</table>'

            . '<div>' . ($isPaid ? '<span class="stamp paid">PAID IN FULL</span>' : '') . '</div>';

        if (! $isPaid) {
            $body .= '<div style="margin-top:14px;padding:10px 14px;background:#EFF6FF;border:1px solid #93C5FD;'
                . 'border-radius:8px;font-size:.82rem;color:#1E3A5F;line-height:1.55">'
                . '<strong>💳 Paying by Interac e-Transfer?</strong> '
                . 'Please include your invoice number <strong>' . $e($number) . '</strong> in the e-Transfer message '
                . 'when sending payment to <strong>' . $e($agencyName) . '</strong>. '
                . 'This lets us match your payment to your account right away and helps us avoid any processing '
                . 'delays or accounting discrepancies.'
                . ($bank ? '<div style="margin-top:8px;white-space:pre-line">' . $e($bank) . '</div>' : '')
                . '</div>';
        }

        if ($terms !== '') {
            $body .= '<div style="margin-top:18px;padding-top:14px;border-top:1px solid #e5e7eb;font-size:.82rem;'
                . 'color:#334155;line-height:1.55"><p style="margin:0 0 6px"><strong>Payment terms:</strong> '
                . $e($terms) . '</p></div>';
        }
        if ($policy !== '') {
            $body .= '<div style="margin-top:14px;font-size:.82rem;color:#334155;line-height:1.55">'
                . '<strong>Payment policy:</strong> ' . $e($policy) . '</div>';
        }

        $body .= '<div style="margin-top:14px;padding:10px 14px;background:#FEFCE8;border:1px solid #EAB308;'
            . 'border-radius:8px;font-size:.82rem;color:#334155;line-height:1.55">'
            . '<strong>Please file this document away safely for future reference.</strong> '
            . 'You will need it for your own records, tax filings, and any future audit or dispute about this invoice.'
            . '</div>';

        if ($isPaid) {
            $body .= '<div class="watermark paid">PAID IN FULL</div>';
        }

        return $this->layout(
            $body,
            $agencyName, $agencyAddr, $agencyEmail, $agencyPhone, $logo,
            'Invoice', $number,
            'Invoice ' . $number . ' · Generated ' . now()->format('M j, Y g:i A'),
            $forPdf
        );
    }

    /** iLearn's <x-print.layout>, reproduced. Tables rather than flex/grid — dompdf. */
    private function layout(string $slot, string $agencyName, string $addr, string $email,
        string $phone, ?string $logo, string $docTitle, string $docSub, string $footNote, bool $pdf): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $brand = self::BRAND;

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<title>' . $e($docTitle) . '</title><style>'
            . ':root{--ink:#1a1a2e;--muted:#6b7280;--line:#e3e3ee;--brand:' . $brand . ';}'
            . '*{box-sizing:border-box;}'
            . 'body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1a1a2e;'
            . 'margin:0;padding:32px;background:#f3f4f8;}'
            . '.sheet{max-width:760px;margin:0 auto;background:#fff;padding:40px 44px;position:relative;'
            . 'overflow:hidden;border:1px solid #e3e3ee;border-radius:10px;}'
            . 'table.doc-head{display:table;width:100%;border-bottom:3px solid ' . $brand . ';'
            . 'padding-bottom:18px;margin-bottom:24px;}'
            . '.doc-head h1{margin:0;font-size:1.5rem;} .doc-head .sub{color:#6b7280;font-size:.85rem;margin-top:4px;}'
            . '.org{text-align:right;font-size:.82rem;color:#6b7280;}'
            . '.org strong{display:block;color:#1a1a2e;font-size:1rem;margin-bottom:2px;}'
            . 'table.meta{display:table;width:100%;margin-bottom:24px;font-size:.9rem;} .meta .k{color:#6b7280;}'
            . 'table.lines{width:100%;border-collapse:collapse;margin:18px 0;font-size:.9rem;}'
            . 'table.lines th,table.lines td{padding:9px 10px;border-bottom:1px solid #e3e3ee;text-align:left;}'
            . 'table.lines th{background:#faf9ff;font-size:.74rem;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;}'
            . 'table.lines td.num,table.lines th.num{text-align:right;}'
            . 'table.totals{margin-left:auto;width:280px;font-size:.92rem;border-collapse:collapse;}'
            . 'table.totals td{padding:6px 0;} table.totals td+td{text-align:right;}'
            . 'table.totals tr.grand td{border-top:2px solid #1a1a2e;padding-top:10px;font-weight:800;font-size:1.1rem;}'
            . '.stamp{display:inline-block;margin-top:8px;padding:4px 12px;border-radius:6px;font-weight:800;font-size:.8rem;}'
            . '.stamp.paid{background:rgba(16,185,129,.14);color:#0a8c63;}'
            . '.watermark{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-22deg);'
            . 'font-weight:900;letter-spacing:.06em;opacity:.14;z-index:0;white-space:nowrap;font-size:62px;}'
            . '.watermark.paid{color:#0a8c63;border:6px solid #0a8c63;padding:10px 24px;border-radius:12px;}'
            . '.foot{margin-top:34px;padding-top:16px;border-top:1px solid #e3e3ee;color:#6b7280;font-size:.8rem;}'
            . ($pdf ? 'body{background:#fff;padding:0;} .sheet{border:0;border-radius:0;max-width:none;padding:0;}' : '')
            . '</style></head><body>'
            . '<div class="sheet">'
            . '<table class="doc-head" style="width:100%;border-collapse:collapse"><tr>'
            . '<td style="vertical-align:middle;width:60%"><table style="border-collapse:collapse"><tr>'
            . ($logo ? '<td style="width:96px;vertical-align:middle"><img src="' . $logo . '" alt="' . $e($agencyName) . '"'
                . ' style="height:72px;width:auto;border-radius:10px;background:#fff;padding:6px;border:1px solid #e5e7eb"></td>' : '')
            . '<td style="vertical-align:middle;padding-left:14px"><h1 style="margin:0">' . $e($docTitle) . '</h1>'
            . ($docSub !== '' ? '<div class="sub">' . $e($docSub) . '</div>' : '')
            . '</td></tr></table></td>'
            . '<td class="org" style="vertical-align:top;width:40%;text-align:right">'
            . '<strong>' . $e($agencyName) . '</strong>'
            . ($addr !== '' ? nl2br($e($addr)) . '<br>' : '')
            . ($email !== '' ? $e($email) . '<br>' : '')
            . ($phone !== '' ? $e($phone) : '')
            . '</td></tr></table>'
            . $slot
            . '<div class="foot">' . $e($footNote) . '</div>'
            . '</div></body></html>';
    }

    /* The logo as a data: URI. iLearn inlines it for exactly the reason we need to —
       a print or PDF pipeline that cannot fetch a remote image silently drops it, and
       an invoice without the agency's mark is the one thing this whole exercise is
       about. Falls back to the URL on any failure rather than losing the header. */
    private function inlineLogo(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $abs = preg_match('#^https?://#i', $url) ? $url : ('https://app.kiddietrac.com/' . ltrim($url, '/'));
        try {
            $bytes = @file_get_contents($abs, false, stream_context_create(['http' => ['timeout' => 5]]));
            if ($bytes && strlen($bytes) > 30) {
                $low = strtolower($abs);
                $mime = (str_ends_with($low, '.jpg') || str_ends_with($low, '.jpeg')) ? 'image/jpeg'
                    : (str_ends_with($low, '.svg') ? 'image/svg+xml' : 'image/png');

                return 'data:' . $mime . ';base64,' . base64_encode($bytes);
            }
        } catch (Throwable $e) {
            // fall through
        }

        return $abs;
    }

    private function familyAddress(?object $family): ?string
    {
        if (! $family) {
            return null;
        }
        $bits = array_filter([
            trim((string) ($family->address_line1 ?? '')),
            trim((string) ($family->address_line2 ?? '')),
            trim(implode(' ', array_filter([
                (string) ($family->city ?? ''),
                (string) ($family->province ?? ''),
                strtoupper((string) ($family->postal_code ?? '')),
            ]))),
        ]);

        return $bits ? implode("\n", $bits) : null;
    }

    private function primaryGuardianEmail(int $familyId): ?string
    {
        $email = DB::table('guardians as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $familyId)
            ->whereNull('u.deleted_at')
            ->orderByDesc('g.is_primary')
            ->value('u.email');

        return $email ? (string) $email : null;
    }
}
