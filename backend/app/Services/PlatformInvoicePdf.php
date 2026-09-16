<?php

declare(strict_types=1);

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * PDF for an invoice the PLATFORM issues to an AGENCY (2026-08-25).
 *
 * Deliberately NOT built on InvoicePdfRenderer. That one brands the document with the
 * ISSUING AGENCY because it exists for agency→family invoices. Here the issuer is
 * KiddieTrac and the agency is the customer, so reusing it would put the customer's own
 * logo and address in the "from" position on a bill addressed to them.
 *
 * ISSUER DETAILS ARE CONFIGURED, NEVER INVENTED. A business address or tax number that
 * nobody entered would be a fabricated detail on a financial document, which is worse
 * than an absent one. Anything unset is simply omitted, and a banner marks the invoice
 * as incomplete so it is obvious before it goes to a customer.
 *
 * Set them with platform_settings keys:
 *   invoice.issuer.name      invoice.issuer.address   invoice.issuer.tax_label
 *   invoice.issuer.email      invoice.issuer.tax_id    invoice.issuer.payment_terms
 */
final class PlatformInvoicePdf
{
    /** Issuer fields, read from platform_settings with sensible fallbacks where honest. */
    private function issuer(): array
    {
        $get = function (string $key, ?string $default = null) {
            try {
                $v = DB::table('platform_settings')->where('key', $key)->value('value');

                return ($v === null || $v === '') ? $default : (string) $v;
            } catch (Throwable $e) {
                return $default;
            }
        };

        return [
            /* Name and email are safe to default: they are already what the platform
               sends mail as, so they are facts, not guesses. */
            'name' => $get('invoice.issuer.name', $get('mail.from_name', 'KiddieTrac')),
            'email' => $get('invoice.issuer.email', $get('mail.from', 'noreply@kiddietrac.com')),
            /* These have no honest default. Blank until someone sets them. */
            'address' => $get('invoice.issuer.address'),
            'tax_id' => $get('invoice.issuer.tax_id'),
            'tax_label' => $get('invoice.issuer.tax_label', 'Tax ID'),
            'terms' => $get('invoice.issuer.payment_terms'),
            /* Invoice queries go to sales@, not the noreply@ the mail is sent from —
               replying to noreply reaches nobody. */
            'reference_email' => $get('invoice.issuer.reference_email', 'sales@kiddietrac.com'),
            /* The portal's own primary, so the invoice matches the product. */
            'brand' => $get('invoice.issuer.brand_color', '#1F6080'),
        ];
    }

    /**
     * The logo as a data URI.
     *
     * Embedded rather than linked: dompdf runs with isRemoteEnabled=false, so a remote
     * <img> renders as nothing at all — an invoice with a silently missing logo looks
     * broken, which is worse than one deliberately without.
     */
    private function logoDataUri(): ?string
    {
        /* The BRAND WORDMARK, not the PWA app icon.
           MEASURED, not ordered by filename. This is printed at ~190px wide, so what
           matters is pixel width: the widest source gives the highest effective DPI on
           paper. logo-wordmark@2x.png is only 232px across (~117dpi in that box, which
           is why the logo looked soft); login-wordmark.png is 701px — the same wordmark
           at 3x — and was simply never considered.
           logo-mark is the last resort: a mark alone is still recognisably KiddieTrac,
           whereas the rounded-square app icon reads as a phone icon on a page. */
        $candidates = [
            base_path('../parent-portal/login-wordmark.png'),
            base_path('../parent-portal/logo-wordmark@2x.png'),
            base_path('../parent-portal/logo-wordmark-large.png'),
            base_path('../parent-portal/logo-wordmark.png'),
            base_path('../parent-portal/logo-mark.png'),
        ];

        $measured = [];
        foreach ($candidates as $i => $c) {
            if (! is_file($c) || ! is_readable($c)) {
                continue;
            }
            $size = @getimagesize($c);
            /* Unreadable dimensions still keep the file as a candidate, just ranked
               last — a logo that renders is better than no logo at all. */
            $measured[] = ['path' => $c, 'w' => $size ? (int) $size[0] : 0, 'order' => $i];
        }
        /* Widest wins; the declared order breaks ties so the preferred lockup is kept
           when two assets are the same size. */
        usort($measured, fn ($a, $b) => $b['w'] <=> $a['w'] ?: $a['order'] <=> $b['order']);

        foreach (array_column($measured, 'path') as $path) {
            try {
                if (is_file($path) && is_readable($path)) {
                    $bytes = file_get_contents($path);
                    if ($bytes !== false && $bytes !== '') {
                        return 'data:image/png;base64,' . base64_encode($bytes);
                    }
                }
            } catch (Throwable $e) {
                // fall through to the next candidate
            }
        }

        return null;
    }

    /** The invoice as HTML. Kept separate so it can be previewed without rendering a PDF. */
    public function renderHtml(string $number): ?string
    {
        $inv = DB::table('platform_invoices as pi')
            ->leftJoin('agencies as a', 'a.id', '=', 'pi.agency_id')
            ->where('pi.number', $number)
            ->first([
                'pi.*',
                'a.name as agency_name', 'a.contact_email as agency_email',
                'a.legal_name as agency_legal_name', 'a.address_line1', 'a.address_line2',
                'a.city', 'a.province', 'a.postal_code', 'a.country',
                'a.contact_phone as agency_phone', 'a.tax_registration as agency_tax_registration',
                'a.contact_phone as agency_phone',
            ]);

        if (! $inv) {
            return null;
        }

        $iss = $this->issuer();
        $logo = $this->logoDataUri();
        $cur = $inv->currency ?: 'CAD';
        $money = fn ($cents) => \App\Support\PlatformBilling::money((int) $cents, $cur);
        $date = fn ($d) => $d ? Carbon::parse($d)->format('j M Y') : '—';
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $period = Carbon::parse($inv->period_start)->format('F Y');
        $due = (int) $inv->amount_cents - (int) $inv->amount_paid_cents;
        $taxCents = (int) ($inv->tax_cents ?? 0);
        /* Invoices raised before tax existed have no subtotal stored; their total
           IS the subtotal, so fall back rather than printing 0.00. */
        $lineAmount = (int) ($inv->subtotal_cents ?: ((int) $inv->amount_cents - $taxCents));

        /* Says so on the face of the document when issuer details are missing. An
           incomplete invoice must not look finished. */
        $missing = [];
        if (! $iss['address']) { $missing[] = 'business address'; }
        if (! $iss['tax_id']) { $missing[] = strtolower($iss['tax_label']); }
        $banner = $missing
            ? '<div style="background:#FEF3C7;border:1px solid #F59E0B;color:#92400E;padding:9px 12px;'
              . 'border-radius:6px;font-size:10px;margin-bottom:16px;">INCOMPLETE — no '
              . $e(implode(' or ', $missing)) . ' configured. Set invoice.issuer.* in platform settings '
              . 'before sending this to a customer.</div>'
            : '';

        $statusColour = ['paid' => '#166534', 'void' => '#6B7280', 'issued' => '#1F6080'][$inv->status] ?? '#B45309';

        $row = fn ($label, $value, $bold = false) =>
            '<tr><td style="padding:7px 0;color:#64748B;font-size:11px;">' . $e($label) . '</td>'
            . '<td style="padding:7px 0;text-align:right;font-size:' . ($bold ? '15px;font-weight:700' : '12px')
            . ';color:#0F172A;">' . $e($value) . '</td></tr>';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,Helvetica,Arial,sans-serif;color:#0F172A;font-size:12px;margin:0;padding:34px 38px;}'
            . 'h1{font-size:26px;margin:0 0 2px;letter-spacing:-0.5px;}'
            . 'table{width:100%;border-collapse:collapse;}'
            . '.muted{color:#64748B;font-size:11px;line-height:1.5;}'
            . '.box{border:1px solid #E2E8F0;border-radius:8px;padding:14px 16px;}'
            . '</style></head><body>'
            // A brand bar across the head of the page, so the document is
            // recognisably KiddieTrac's before anything is read.
            . '<div style="height:6px;background:' . $e($iss['brand']) . ';margin:-34px -38px 22px;"></div>'
            . $banner
            // ── header: who is billing, and the invoice identity ──
            . '<table><tr>'
            . '<td style="vertical-align:top;">'
            /* A wordmark is wide. Constrained by width so it sits properly in the
               header — 40px of height sized for a square icon renders it tiny. */
            . ($logo ? '<img src="' . $logo . '" style="width:190px;margin-bottom:9px;">' : '')
            /* The wordmark already says the name; repeating it as a heading is
               redundant. Shown only when no logo could be loaded. */
            . ($logo ? '' : '<h1 style="color:' . $e($iss['brand']) . ';">' . $e($iss['name']) . '</h1>')
            . '<div class="muted">' . nl2br($e((string) $iss['address'])) . '</div>'
            . '<div class="muted">' . $e($iss['email']) . '</div>'
            . ($iss['tax_id'] ? '<div class="muted">' . $e($iss['tax_label']) . ': ' . $e($iss['tax_id']) . '</div>' : '')
            . '</td>'
            . '<td style="vertical-align:top;text-align:right;">'
            . '<div style="font-size:19px;font-weight:700;letter-spacing:2px;color:#64748B;">INVOICE</div>'
            . '<div style="font-size:13px;font-weight:700;margin-top:3px;">' . $e($inv->number) . '</div>'
            . '<div style="display:inline-block;margin-top:7px;padding:2px 9px;border-radius:9px;font-size:9px;'
            . 'font-weight:700;letter-spacing:1px;color:#fff;background:' . $statusColour . ';">'
            . strtoupper($e($inv->status)) . '</div>'
            . '</td></tr></table>'
            . '<hr style="border:0;border-top:2px solid ' . $e($iss['brand']) . ';margin:16px 0 18px;">'
            // ── bill-to and dates ──
            . '<table><tr>'
            . '<td style="width:55%;vertical-align:top;">'
            . '<div class="muted" style="text-transform:uppercase;letter-spacing:1px;font-weight:700;">Billed to</div>'
            /* The registered entity where one is recorded, otherwise the display name.
               Every line below is conditional: an unfilled field prints nothing, so a
               partial profile degrades quietly instead of showing gaps. */
            . '<div style="font-size:14px;font-weight:700;margin-top:4px;">'
                . $e($inv->agency_legal_name ?: $inv->agency_name) . '</div>'
            . collect([
                $inv->address_line1,
                $inv->address_line2,
                trim(implode(', ', array_filter([$inv->city, $inv->province]))
                    . ' ' . (string) $inv->postal_code),
                $inv->country,
            ])->map(fn ($l) => trim((string) $l))->filter()
              ->map(fn ($l) => '<div class="muted">' . $e($l) . '</div>')->implode('')
            . ($inv->agency_email ? '<div class="muted">' . $e($inv->agency_email) . '</div>' : '')
            . ($inv->agency_phone ? '<div class="muted">' . $e($inv->agency_phone) . '</div>' : '')
            . ($inv->agency_tax_registration
                ? '<div class="muted">' . $e($inv->tax_label ?: 'Tax ID') . ': '
                    . $e($inv->agency_tax_registration) . '</div>' : '')
            . ($inv->agency_email ? '<div class="muted">' . $e($inv->agency_email) . '</div>' : '')
            . ($inv->agency_phone ? '<div class="muted">' . $e($inv->agency_phone) . '</div>' : '')
            . '</td>'
            . '<td style="vertical-align:top;">'
            . '<table>'
            . $row('Invoice date', $date($inv->issued_at ?: $inv->created_at))
            . $row('Due date', $date($inv->due_at))
            . $row('Billing period', $date($inv->period_start) . ' – ' . $date($inv->period_end))
            . '</table></td></tr></table>'
            // ── the line ──
            . '<table style="margin-top:22px;">'
            . '<tr style="background:#F8FAFC;">'
            . '<th style="text-align:left;padding:9px 12px;font-size:10px;letter-spacing:1px;color:#64748B;">DESCRIPTION</th>'
            . '<th style="text-align:right;padding:9px 12px;font-size:10px;letter-spacing:1px;color:#64748B;">AMOUNT</th>'
            . '</tr>'
            . '<tr><td style="padding:13px 12px;border-bottom:1px solid #E2E8F0;">'
            . '<div style="font-weight:700;">KiddieTrac subscription — ' . $e($period) . '</div>'
            . '<div class="muted">'
            . ($inv->plan_code ? ucfirst($e($inv->plan_code)) . ' plan · ' : '')
            . $e($date($inv->period_start) . ' to ' . $date($inv->period_end)) . '</div></td>'
            . '<td style="padding:13px 12px;text-align:right;border-bottom:1px solid #E2E8F0;font-size:13px;">'
            . $e($money($lineAmount)) . '</td></tr>'
            . '</table>'
            // ── totals ──
            . '<table style="margin-top:14px;"><tr><td style="width:58%;"></td><td>'
            . '<table>'
            . $row('Subtotal', $money($lineAmount))
            /* The tax line is NAMED and carries its rate. "Tax" alone is not
               acceptable on an invoice in a jurisdiction that requires HST/GST/VAT
               to be identified, and the rate is what lets the customer check it. */
            . ($taxCents > 0
                ? $row(($inv->tax_label ?: 'Tax')
                    . ' (' . \App\Support\PlatformBilling::ratePercent((int) $inv->tax_rate_bps) . ')',
                    $money($taxCents))
                : '')
            . ($taxCents > 0 ? $row('Total', $money((int) $inv->amount_cents)) : '')
            . ((int) $inv->amount_paid_cents > 0 ? $row('Paid', '-' . $money($inv->amount_paid_cents)) : '')
            . '<tr><td colspan="2"><hr style="border:0;border-top:1px solid #E2E8F0;margin:5px 0;"></td></tr>'
            . $row($inv->status === 'paid' ? 'Paid in full' : 'Amount due', $money(max(0, $due)), true)
            . '</table></td></tr></table>'
            // ── footer ──
            // Payment terms. Defaults are derived from this invoice's own due date so
            // the wording can never contradict the date printed above it.
            . '<div style="margin-top:26px;border:1px solid #E2E8F0;border-left:3px solid '
                . $e($iss['brand']) . ';border-radius:6px;padding:12px 14px;">'
            . '<div style="font-size:10px;font-weight:700;letter-spacing:1px;color:#64748B;'
                . 'text-transform:uppercase;margin-bottom:6px;">Payment terms</div>'
            . '<div class="muted">'
            . ($iss['terms']
                ? nl2br($e((string) $iss['terms']))
                : 'Payment is due by ' . $e($date($inv->due_at)) . '. '
                  . 'Please quote invoice number ' . $e($inv->number) . ' with your payment. '
                  . 'Accounts unpaid after the due date may have platform access suspended '
                  . 'until the balance is settled.')
            . '</div></div>'
            /* How to actually pay. An invoice that states terms but never says which
               methods are accepted invites an email asking exactly that. */
            . '<div style="margin-top:10px;border:1px solid #E2E8F0;border-radius:6px;padding:12px 14px;">'
            . '<div style="font-size:10px;font-weight:700;letter-spacing:1px;color:#64748B;'
                . 'text-transform:uppercase;margin-bottom:6px;">How to pay</div>'
            . '<div class="muted">'
            . '<strong>All major cards accepted</strong> — Visa, Mastercard, American Express, '
            . 'Discover, plus Visa Debit and Debit Mastercard.<br>'
            . '<strong>EFT / bank transfer</strong> — electronic funds transfer, pre-authorised '
            . 'debit and Interac e-Transfer are also accepted.<br>'
            . 'To arrange payment or set up automatic billing, email '
            . $e($iss['reference_email']) . '.'
            . '</div></div>'
            . ($inv->payment_reference ? '<div class="muted" style="margin-top:8px;">Payment reference: '
                . $e($inv->payment_reference) . '</div>' : '')
            . '<div class="muted" style="margin-top:26px;border-top:1px solid #E2E8F0;padding-top:9px;">'
            . 'Questions about this invoice? Email ' . $e($iss['reference_email'])
            . ' quoting ' . $e($inv->number) . '.</div>'
            . '</body></html>';
    }

    /** Raw PDF bytes, or null if the invoice does not exist. */
    public function render(string $number): ?string
    {
        $html = $this->renderHtml($number);
        if ($html === null) {
            return null;
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);   // no network fetches from invoice HTML
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
