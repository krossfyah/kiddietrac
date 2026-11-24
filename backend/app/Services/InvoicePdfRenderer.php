<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * v15: Branded invoice renderer.
 *
 * Generates an HTML invoice that uses the issuing agency's brand
 * (logo, colours, support email, bank info) instead of the bare
 * Kiddietrac default. White-label fields come from the agencies
 * table (added by the v15 migration).
 *
 * USAGE (when wiring into your existing invoice flow):
 *
 *     $renderer = app(InvoicePdfRenderer::class);
 *     $html = $renderer->renderHtml($invoice, $agency);
 *     // Then pass $html to whatever PDF library you use
 *     // (DomPDF, mPDF, Snappy, etc.):
 *     return PDF::loadHTML($html)->stream("invoice-{$invoice->number}.pdf");
 *
 * If you don't have a PDF library yet, the returned HTML is fully
 * print-stylesheet aware and renders cleanly via the browser's
 * native "Print to PDF" function.
 *
 * Fields the agency should populate for full white-label experience:
 *   - brand_logo_url          (PNG, ideally 400x100 transparent)
 *   - brand_primary_color     (#hex)
 *   - brand_support_email
 *   - brand_bank_info         (multi-line text shown in footer)
 *   - powered_by_visible      (1 = show "powered by Kiddietrac" footer)
 *
 * If any are NULL, the renderer falls back to Kiddietrac defaults.
 */
final class InvoicePdfRenderer
{
    private array $kt_defaults = [
        'logo_url'        => 'https://app.kiddietrac.com/logo-wordmark@2x.png',
        'primary_color'   => '#081C41',
        'support_email'   => 'support@kiddietrac.com',
        'bank_info'       => '',   // the How to pay block (InvoicePayInfo) says it properly
        'powered_by'      => 'Powered by KiddieTrac — The Smart Childcare Management Platform',
    ];

    /**
     * Render a full invoice HTML document.
     *
     * @param object|array $invoice  Invoice data — flexible shape, see below
     * @param object|array|null $agency  Agency record from DB, or null to use defaults
     */
    public function renderHtml($invoice, $agency = null): string
    {
        $brand = $this->resolveBrand($agency);
        $inv = (object) $invoice;
        $items = $inv->items ?? [];
        if (is_string($items)) $items = json_decode($items, true) ?: [];
        if (! is_array($items)) $items = [];

        $primaryColor = htmlspecialchars($brand['primary_color']);
        $logoUrl      = htmlspecialchars($brand['logo_url']);
        $supportEmail = htmlspecialchars($brand['support_email']);
        $issuerName   = htmlspecialchars($brand['issuer_name']);
        $issuerAddress = !empty($brand['address']) ? nl2br(htmlspecialchars((string) $brand['address'])) : '';
        $issuerPhone   = !empty($brand['phone']) ? htmlspecialchars((string) $brand['phone']) : '';
        $issuerAddressBlock = $issuerAddress ? "<div class=\"detail\">{$issuerAddress}</div>" : '';
        $issuerPhoneBlock   = $issuerPhone ? "<div class=\"detail\">☎ {$issuerPhone}</div>" : '';
        $bankInfo     = nl2br(htmlspecialchars($brand['bank_info']));
        $bankBlock    = $bankInfo !== '' ? '<strong>Payment instructions</strong>' . $bankInfo : '';
        $poweredBy    = $brand['powered_by'];

        $invoiceNumber = htmlspecialchars((string) ($inv->number ?? $inv->id ?? '—'));
        $issueDate     = htmlspecialchars((string) ($inv->issued_at ?? $inv->created_at ?? \App\Support\AgencyTime::today()));
        $dueDate       = htmlspecialchars((string) ($inv->due_at ?? $inv->due_date ?? ''));
        $billToName    = htmlspecialchars((string) ($inv->bill_to_name ?? $inv->family_name ?? ''));
        $billToEmail   = htmlspecialchars((string) ($inv->bill_to_email ?? ''));
        // The family's reference ID (2026-10-14, App\Support\RefCode).
        $billToRef     = ! empty($inv->family_id) ? htmlspecialchars((string) (\App\Support\RefCode::families([(int) $inv->family_id])[(int) $inv->family_id] ?? '')) : '';
        $childName     = htmlspecialchars((string) ($inv->child_name ?? ''));
        $totalCents    = (int) ($inv->total_cents ?? $inv->amount_cents ?? 0);
        $currency      = htmlspecialchars((string) ($inv->currency ?? 'CAD'));
        $notes         = htmlspecialchars((string) ($inv->notes ?? ''));
        $statusRaw     = (string) ($inv->status ?? 'unpaid');
        $status        = htmlspecialchars($statusRaw);
        $statusBadge = match (strtolower($statusRaw)) {
            'paid'                         => '<span class="badge paid">PAID</span>',
            'partial', 'partially_paid'    => '<span class="badge unpaid">PARTIALLY PAID</span>',
            'past_due', 'overdue'          => '<span class="badge overdue">OVERDUE</span>',
            'cancelled', 'void'            => '<span class="badge cancelled">VOID</span>',
            'draft'                        => '<span class="badge cancelled">DRAFT</span>',
            default                        => '<span class="badge unpaid">UNPAID</span>',
        };

        $itemRows = '';
        $subtotal = 0;
        foreach ($items as $row) {
            $r = (object) $row;
            $desc  = htmlspecialchars((string) ($r->description ?? $r->label ?? '—'));
            $qty   = (float)  ($r->quantity ?? $r->qty ?? 1);
            $unit  = (int)    ($r->unit_cents ?? $r->amount_cents ?? 0);
            $line  = (int)    ($r->total_cents ?? round($qty * $unit));
            $subtotal += $line;
            $sub   = trim((string) ($r->sub ?? ''));
            $itemRows .= sprintf(
                '<tr><td>%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>',
                $desc . ($sub !== '' ? '<div class="sub">' . htmlspecialchars($sub) . '</div>' : ''),
                $this->fmtQty($qty),
                $this->fmtMoney($unit, $currency),
                $this->fmtMoney($line, $currency)
            );
        }
        // If no items, show a single line with the invoice total
        if (empty($items)) {
            $itemRows = sprintf(
                '<tr><td>%s</td><td class="num">1</td><td class="num">%s</td><td class="num">%s</td></tr>',
                htmlspecialchars((string) ($inv->description ?? 'Childcare services')),
                $this->fmtMoney($totalCents, $currency),
                $this->fmtMoney($totalCents, $currency)
            );
            $subtotal = $totalCents;
        }

        $taxCents = max(0, $totalCents - $subtotal);
        $hasTax = $taxCents > 0;

        $totalRows = [];
        if (isset($inv->totals) && is_array($inv->totals) && $inv->totals) {
            $totalRows = $inv->totals;
        } else {
            $totalRows[] = ['Subtotal', $subtotal, false];
            if ($hasTax) $totalRows[] = ['Tax', $taxCents, false];
            $totalRows[] = ['Total', $totalCents, true];
        }
        $totalsHtml = '';
        foreach ($totalRows as $t) {
            [$tl, $tc, $tb] = [(string) $t[0], (int) $t[1], ! empty($t[2])];
            $totalsHtml .= '<tr' . ($tb ? ' class="total"' : '') . '><td class="label">' . htmlspecialchars($tl) . '</td><td class="value">'
                . ($tc < 0 ? '−' . $this->fmtMoney(-$tc, $currency) : $this->fmtMoney($tc, $currency)) . '</td></tr>';
        }
        $billToExtra = '';
        foreach ((array) ($inv->bill_to_lines ?? []) as $bl) {
            if (trim((string) $bl) !== '') $billToExtra .= '<div class="detail">' . nl2br(htmlspecialchars((string) $bl)) . '</div>';
        }
        $logoHtml = $logoUrl !== ''
            ? '<img src="' . $logoUrl . '" alt="' . $issuerName . '">'
            : '<div class="wordmark">' . $issuerName . '</div>';

        $footerPoweredBy = $brand['show_powered_by']
            ? "<div class='powered-by'>" . htmlspecialchars($poweredBy) . "</div>"
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice #{$invoiceNumber}</title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: 'DejaVu Sans', -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif;
    color: #1f2937;
    background: #ffffff;
    margin: 0;
    padding: 26px 30px;
    font-size: 12px;
    line-height: 1.45;
  }
  .invoice {
    max-width: 720px;
    margin: 0 auto;
  }
  table.head { width: 100%; border-collapse: collapse; border-bottom: 3px solid {$primaryColor}; margin-bottom: 18px; }
  table.head td { vertical-align: top; padding: 0 0 14px 0; }
  .wordmark { font-size: 22px; font-weight: 700; color: {$primaryColor}; }
  table.parties { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  table.parties td { width: 50%; vertical-align: top; padding: 0 16px 0 0; }
  table.items td .sub { font-size: 12px; color: #6b7280; margin-top: 2px; }
  table.totals { margin-left: 52%; width: 48%; border-collapse: collapse; }
  table.totals td { padding: 5px 0; font-size: 14px; }
  table.totals td.value { text-align: right; }
  table.totals tr.total td { border-top: 2px solid {$primaryColor}; padding-top: 10px; font-weight: 700; font-size: 16px; color: {$primaryColor}; }
  .logo img { max-height: 52px; max-width: 220px; }
  .invoice-meta { text-align: right; }
  .invoice-meta h1 {
    margin: 0 0 8px 0;
    font-size: 26px;
    font-weight: 700;
    color: {$primaryColor};
    letter-spacing: -0.5px;
  }
  .invoice-meta .number { font-size: 14px; color: #6b7280; }
  .invoice-meta .dates { margin-top: 16px; font-size: 13px; }
  .invoice-meta .dates div { margin-bottom: 4px; }
  .invoice-meta .dates label { color: #6b7280; margin-right: 8px; }
  .badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.5px;
    margin-top: 8px;
  }
  .badge.paid { background: #d1fae5; color: #065f46; }
  .badge.unpaid { background: #fef3c7; color: #92400e; }
  .badge.overdue { background: #fee2e2; color: #991b1b; }
  .badge.cancelled { background: #f3f4f6; color: #6b7280; }
  .party h3 {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #6b7280;
    margin: 0 0 8px 0;
    font-weight: 700;
  }
  .party .name { font-weight: 700; font-size: 14px; }
  .party .detail { color: #6b7280; font-size: 11.5px; }
  table.items {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 24px;
  }
  table.items thead th {
    text-align: left;
    border-bottom: 2px solid #e5e7eb;
    padding: 10px 8px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #6b7280;
  }
  table.items thead th.num { text-align: right; }
  table.items tbody td {
    padding: 7px 6px;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: top;
  }
  table.items tbody td.num { text-align: right; font-variant-numeric: tabular-nums; }
  .totals {
    margin-left: auto;
    width: 280px;
  }
  .totals .row {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    font-size: 14px;
  }
  .totals .row.total {
    border-top: 2px solid {$primaryColor};
    padding-top: 12px;
    margin-top: 8px;
    font-weight: 700;
    font-size: 18px;
    color: {$primaryColor};
  }
  .totals .label { color: #6b7280; }
  .totals .value { font-variant-numeric: tabular-nums; }
  .notes {
    margin-top: 14px;
    padding: 10px 12px;
    background: #f9fafb;
    --kt-edge:{$primaryColor};
    font-size: 13px;
    color: #6b7280;
  }
  .notes strong { color: #1f2937; }
  .foot {
    margin-top: 18px;
    padding-top: 10px;
    border-top: 1px solid #e5e7eb;
    font-size: 12px;
    color: #6b7280;
  }
  footer .bank strong { color: #1f2937; display: block; margin-bottom: 4px; }
  .powered-by {
    text-align: right;
    margin-top: 6px;
    font-size: 11px;
    opacity: 0.7;
  }
  @media print {
    body { padding: 20px; }
    @page { margin: 18mm; }
  }
</style>
</head>
<body>
<div class="invoice">
  <table class="head"><tr>
    <td class="logo">{$logoHtml}</td>
    <td class="invoice-meta">
      <h1>INVOICE</h1>
      <div class="number">#{$invoiceNumber}</div>
      {$statusBadge}
      <div class="dates">
        <div><label>Issued:</label>{$issueDate}</div>
HTML
        . ($dueDate ? "<div><label>Due:</label>{$dueDate}</div>" : '')
        . <<<HTML
      </div>
    </td>
  </tr></table>

  <table class="parties"><tr>
    <td class="party">
      <h3>From</h3>
      <div class="name">{$issuerName}</div>
      {$issuerAddressBlock}
      {$issuerPhoneBlock}
      <div class="detail">{$supportEmail}</div>
    </td>
    <td class="party">
      <h3>Bill To</h3>
      <div class="name">{$billToName}</div>
HTML
        . ($billToRef ? "<div class='detail'>Account ID: {$billToRef}</div>" : '')
        . ($billToEmail ? "<div class='detail'>{$billToEmail}</div>" : '')
        . ($childName ? "<div class='detail'>For: {$childName}</div>" : '')
        . $billToExtra
        . <<<HTML
    </td>
  </tr></table>

  <table class="items">
    <thead>
      <tr>
        <th>Description</th>
        <th class="num">Qty</th>
        <th class="num">Unit</th>
        <th class="num">Amount</th>
      </tr>
    </thead>
    <tbody>
      {$itemRows}
    </tbody>
  </table>

  <table class="totals">{$totalsHtml}</table>
HTML
        . ($notes ? "<div class='notes'><strong>Notes:</strong> {$notes}</div>" : '')
        . <<<HTML

  <div class="foot">
    {$bankBlock}
    {$footerPoweredBy}
  </div>
</div>
</body>
</html>
HTML;
    }

    /**
     * Resolve which brand to use for this invoice.
     * Falls back to Kiddietrac defaults for any field the agency hasn't set.
     */
    private function resolveBrand($agency): array
    {
        if (! $agency) {
            return [
                'logo_url'         => $this->kt_defaults['logo_url'],
                'primary_color'    => $this->kt_defaults['primary_color'],
                'support_email'    => $this->kt_defaults['support_email'],
                'bank_info'        => $this->kt_defaults['bank_info'],
                'issuer_name'      => \App\Support\Company::legalName(),
                'powered_by'       => $this->kt_defaults['powered_by'],
                'show_powered_by'  => true,
            ];
        }

        $a = (object) $agency;
        $get = fn (string $prop) => property_exists($a, $prop) ? ($a->$prop ?? null) : null;
        $name = $get('name') ?: 'Kiddietrac';

        // v22p88: pull the agency's full info onto white-label invoices — the
        // business address (stored in settings.brand_address) + phone.
        $address = null;
        $settingsRaw = $get('settings');
        if ($settingsRaw) {
            $s = json_decode((string) $settingsRaw, true);
            if (is_array($s) && !empty($s['brand_address'])) $address = (string) $s['brand_address'];
        }

        $logo = (string) ($get('brand_logo_url') ?: '');
        if ($logo !== '' && str_starts_with($logo, '/')) {
            $logo = 'https://api.kiddietrac.com' . $logo;
        }
        $showPowered = (int) ($get('powered_by_visible') ?? 1) === 1;
        if ($logo === '') {
            $logo = $showPowered ? $this->kt_defaults['logo_url'] : '';
        }

        return [
            'logo_url'         => $logo,
            'primary_color'    => $get('brand_primary_color') ?: $this->kt_defaults['primary_color'],
            'support_email'    => $get('brand_support_email') ?: $get('contact_email') ?: $this->kt_defaults['support_email'],
            'bank_info'        => $get('brand_bank_info')     ?: $this->kt_defaults['bank_info'],
            'issuer_name'      => $name,
            'address'          => $address,
            'phone'            => $get('contact_phone'),
            'powered_by'       => $this->kt_defaults['powered_by'],
            'show_powered_by'  => (int) ($get('powered_by_visible') ?? 1) === 1,
        ];
    }

    /**
     * Convenience: render an invoice purely from invoice ID, fetching
     * the agency by tracing invoice -> centre -> agency.
     */
    public function renderFromInvoiceId(int $invoiceId): ?string
    {
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        if (! $invoice) return null;

        $agency = null;
        if (isset($invoice->agency_id)) {
            $agency = DB::table('agencies')->where('id', $invoice->agency_id)->first();
        } elseif (isset($invoice->centre_id)) {
            $row = DB::table('centres')->where('id', $invoice->centre_id)->first(['agency_id']);
            if ($row) {
                $agency = DB::table('agencies')->where('id', $row->agency_id)->first();
            }
        }

        return $this->renderHtml($this->fromNative($invoice, $agency), $agency);
    }

    /**
     * A row of `invoices` (+ its invoice_lines, family, guardians, children) as the document renderHtml() draws:
     * number, dates, Bill To, every line with its child, subsidy / discount / tax, total, paid and balance due.
     */
    private function fromNative(object $inv, ?object $agency): object
    {
        $cents = fn ($v) => (int) round(((float) $v) * 100);
        $date = function ($v) {
            if (! $v) return '';
            try { return \Illuminate\Support\Carbon::parse(substr((string) $v, 0, 10))->format('F j, Y'); } catch (\Throwable $e) { return (string) $v; }
        };
        $family = $inv->family_id ? DB::table('families')->where('id', $inv->family_id)->first() : null;
        $guardians = $inv->family_id
            ? DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                ->where('g.family_id', $inv->family_id)->whereNull('u.deleted_at')
                ->orderByDesc('g.is_primary')->orderBy('g.id')
                ->get(['u.first_name', 'u.last_name', 'u.email'])
            : collect();
        $lines = DB::table('invoice_lines')->where('invoice_id', $inv->id)->orderBy('id')->get();
        $childIds = $lines->pluck('child_id')->filter()->unique()->values()->all();
        $kids = $childIds
            ? DB::table('children')->whereIn('id', $childIds)->get(['id', 'first_name', 'last_name'])->keyBy('id')
            : collect();

        $items = [];
        foreach ($lines as $l) {
            $desc = trim((string) ($l->description ?? '')) ?: 'Child care';
            $kid = $l->child_id && isset($kids[$l->child_id]) ? $kids[$l->child_id] : null;
            $sub = '';
            if ($kid && stripos($desc, (string) $kid->first_name) === false) {
                $sub = trim($kid->first_name . ' ' . $kid->last_name);
            }
            $qty = (float) ($l->quantity ?? 1) ?: 1.0;
            $amount = isset($l->amount) ? (float) $l->amount : $qty * (float) ($l->unit_amount ?? 0);
            $unit = isset($l->unit_amount) && $l->unit_amount !== null ? (float) $l->unit_amount : ($qty ? $amount / $qty : $amount);
            $items[] = ['description' => $desc, 'sub' => $sub, 'quantity' => $qty, 'unit_cents' => $cents($unit), 'total_cents' => $cents($amount)];
        }
        if (! $items) {
            $items[] = ['description' => 'Child care' . ($inv->period_start ? ' — ' . $date($inv->period_start) . ' to ' . $date($inv->period_end) : ''),
                'quantity' => 1, 'unit_cents' => $cents($inv->subtotal ?: $inv->total), 'total_cents' => $cents($inv->subtotal ?: $inv->total)];
        }

        $sumLines = array_sum(array_column($items, 'total_cents'));
        $totals = [['Subtotal', $inv->subtotal !== null && (float) $inv->subtotal > 0 ? $cents($inv->subtotal) : $sumLines, false]];
        if ((float) ($inv->subsidy_amount ?? 0) > 0)  $totals[] = ['Subsidy',  -$cents($inv->subsidy_amount), false];
        if ((float) ($inv->discount_amount ?? 0) > 0) $totals[] = ['Discount', -$cents($inv->discount_amount), false];
        if ((float) ($inv->tax_amount ?? 0) > 0)      $totals[] = ['Tax',       $cents($inv->tax_amount), false];
        $totals[] = ['Total', $cents($inv->total), true];
        if ((float) ($inv->amount_paid ?? 0) > 0) {
            $totals[] = ['Paid to date', -$cents($inv->amount_paid), false];
            $totals[] = ['Balance due', $cents($inv->balance_due ?? max(0, (float) $inv->total - (float) $inv->amount_paid)), true];
        }

        $names = $guardians->map(fn ($g) => trim($g->first_name . ' ' . $g->last_name))->filter()->values()->all();
        $addr = $family ? trim(implode("\n", array_filter([
            trim((string) ($family->address_line1 ?? '')),
            trim((string) ($family->address_line2 ?? '')),
            trim(implode(' ', array_filter([(string) ($family->city ?? ''), (string) ($family->province ?? ''), strtoupper((string) ($family->postal_code ?? ''))]))),
        ]))) : '';
        $period = $inv->period_start ? 'Period: ' . $date($inv->period_start) . ($inv->period_end ? ' – ' . $date($inv->period_end) : '') : '';

        return (object) [
            'id' => $inv->id,
            'number' => (string) ($inv->invoice_number ?: ('INV-' . $inv->id)),
            'issued_at' => $date($inv->issued_at ?: $inv->created_at),
            'due_at' => $date($inv->due_at),
            'status' => (string) ($inv->status ?: 'draft'),
            'family_id' => $inv->family_id,
            'bill_to_name' => (string) ($family->family_name ?? ($names[0] ?? 'Family')),
            'bill_to_email' => (string) ($guardians->first()->email ?? ($family->primary_email ?? '')),
            'bill_to_lines' => array_values(array_filter([$names ? implode(', ', $names) : '', $addr, $period])),
            'currency' => (string) ($agency->currency ?? 'CAD') ?: 'CAD',
            'items' => $items,
            'total_cents' => $cents($inv->total),
            'totals' => $totals,
            // Staff notes print as before; the old internal "Payment schedule #N - instalment n of N" line never does.
            'notes' => trim((string) preg_replace('/Payment schedule\s*#?\d*\s*[-—–]?\s*instalment\s*\d+\s*of\s*\d+\.?/i', '', (string) ($inv->notes ?? ''))),
        ];
    }

    private function fmtMoney(int $cents, string $currency = 'CAD'): string
    {
        $amount = number_format($cents / 100, 2);
        return match (strtoupper($currency)) {
            'USD' => "\${$amount}",
            'CAD' => "C\${$amount}",
            'EUR' => "€{$amount}",
            'GBP' => "£{$amount}",
            default => "{$currency} {$amount}",
        };
    }

    private function fmtQty(float $q): string
    {
        return $q == (int) $q ? (string) (int) $q : number_format($q, 2);
    }
}
