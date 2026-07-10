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
        'bank_info'       => 'Payment instructions available on request.',
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
        $poweredBy    = $brand['powered_by'];

        $invoiceNumber = htmlspecialchars((string) ($inv->number ?? $inv->id ?? '—'));
        $issueDate     = htmlspecialchars((string) ($inv->issued_at ?? $inv->created_at ?? date('Y-m-d')));
        $dueDate       = htmlspecialchars((string) ($inv->due_at ?? $inv->due_date ?? ''));
        $billToName    = htmlspecialchars((string) ($inv->bill_to_name ?? $inv->family_name ?? ''));
        $billToEmail   = htmlspecialchars((string) ($inv->bill_to_email ?? ''));
        $childName     = htmlspecialchars((string) ($inv->child_name ?? ''));
        $totalCents    = (int) ($inv->total_cents ?? $inv->amount_cents ?? 0);
        $currency      = htmlspecialchars((string) ($inv->currency ?? 'CAD'));
        $notes         = htmlspecialchars((string) ($inv->notes ?? ''));
        $statusRaw     = (string) ($inv->status ?? 'unpaid');
        $status        = htmlspecialchars($statusRaw);
        $statusBadge = match (strtolower($statusRaw)) {
            'paid'        => '<span class="badge paid">PAID</span>',
            'past_due'    => '<span class="badge overdue">OVERDUE</span>',
            'cancelled'   => '<span class="badge cancelled">CANCELLED</span>',
            default       => '<span class="badge unpaid">UNPAID</span>',
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
            $itemRows .= sprintf(
                '<tr><td>%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>',
                $desc,
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
  :root {
    --primary: {$primaryColor};
    --text: #1f2937;
    --muted: #6b7280;
    --border: #e5e7eb;
    --bg: #ffffff;
  }
  * { box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
    color: var(--text);
    background: var(--bg);
    margin: 0;
    padding: 40px;
    font-size: 14px;
    line-height: 1.5;
  }
  .invoice {
    max-width: 720px;
    margin: 0 auto;
  }
  header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 3px solid var(--primary);
    padding-bottom: 24px;
    margin-bottom: 32px;
  }
  .logo img { max-height: 60px; max-width: 240px; }
  .invoice-meta { text-align: right; }
  .invoice-meta h1 {
    margin: 0 0 8px 0;
    font-size: 32px;
    font-weight: 700;
    color: var(--primary);
    letter-spacing: -0.5px;
  }
  .invoice-meta .number { font-size: 14px; color: var(--muted); }
  .invoice-meta .dates { margin-top: 16px; font-size: 13px; }
  .invoice-meta .dates div { margin-bottom: 4px; }
  .invoice-meta .dates label { color: var(--muted); margin-right: 8px; }
  .badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.5px;
    margin-top: 8px;
  }
  .badge.paid { background: #d1fae5; color: #065f46; }
  .badge.unpaid { background: #fef3c7; color: #92400e; }
  .badge.overdue { background: #fee2e2; color: #991b1b; }
  .badge.cancelled { background: #f3f4f6; color: #6b7280; }
  .parties { display: flex; justify-content: space-between; gap: 32px; margin-bottom: 32px; }
  .party { flex: 1; }
  .party h3 {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
    margin: 0 0 8px 0;
    font-weight: 600;
  }
  .party .name { font-weight: 600; font-size: 16px; }
  .party .detail { color: var(--muted); font-size: 13px; }
  table.items {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 24px;
  }
  table.items thead th {
    text-align: left;
    border-bottom: 2px solid var(--border);
    padding: 10px 8px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
  }
  table.items thead th.num { text-align: right; }
  table.items tbody td {
    padding: 12px 8px;
    border-bottom: 1px solid var(--border);
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
    border-top: 2px solid var(--primary);
    padding-top: 12px;
    margin-top: 8px;
    font-weight: 700;
    font-size: 18px;
    color: var(--primary);
  }
  .totals .label { color: var(--muted); }
  .totals .value { font-variant-numeric: tabular-nums; }
  .notes {
    margin-top: 32px;
    padding: 16px;
    background: #f9fafb;
    border-left: 3px solid var(--primary);
    font-size: 13px;
    color: var(--muted);
  }
  .notes strong { color: var(--text); }
  footer {
    margin-top: 48px;
    padding-top: 24px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    font-size: 12px;
    color: var(--muted);
  }
  footer .bank { flex: 1; padding-right: 32px; }
  footer .bank strong { color: var(--text); display: block; margin-bottom: 4px; }
  .powered-by {
    text-align: right;
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
  <header>
    <div class="logo"><img src="{$logoUrl}" alt="{$issuerName}"></div>
    <div class="invoice-meta">
      <h1>INVOICE</h1>
      <div class="number">#{$invoiceNumber}</div>
      {$statusBadge}
      <div class="dates">
        <div><label>Issued:</label>{$issueDate}</div>
HTML
        . ($dueDate ? "<div><label>Due:</label>{$dueDate}</div>" : '')
        . <<<HTML
      </div>
    </div>
  </header>

  <div class="parties">
    <div class="party">
      <h3>From</h3>
      <div class="name">{$issuerName}</div>
      {$issuerAddressBlock}
      {$issuerPhoneBlock}
      <div class="detail">Support: {$supportEmail}</div>
    </div>
    <div class="party">
      <h3>Bill To</h3>
      <div class="name">{$billToName}</div>
HTML
        . ($billToEmail ? "<div class='detail'>{$billToEmail}</div>" : '')
        . ($childName ? "<div class='detail'>For: {$childName}</div>" : '')
        . <<<HTML
    </div>
  </div>

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

  <div class="totals">
    <div class="row">
      <span class="label">Subtotal</span>
      <span class="value">{$this->fmtMoney($subtotal, $currency)}</span>
    </div>
HTML
        . ($hasTax
            ? "<div class='row'><span class='label'>Tax</span><span class='value'>{$this->fmtMoney($taxCents, $currency)}</span></div>"
            : '')
        . <<<HTML
    <div class="row total">
      <span class="label">Total</span>
      <span class="value">{$this->fmtMoney($totalCents, $currency)}</span>
    </div>
  </div>
HTML
        . ($notes ? "<div class='notes'><strong>Notes:</strong> {$notes}</div>" : '')
        . <<<HTML

  <footer>
    <div class="bank">
      <strong>Payment instructions</strong>
      {$bankInfo}
    </div>
    {$footerPoweredBy}
  </footer>
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
                'issuer_name'      => 'Kiddietrac',
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

        return [
            'logo_url'         => $get('brand_logo_url')      ?: $this->kt_defaults['logo_url'],
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

        return $this->renderHtml($invoice, $agency);
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
