<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\KtDompdf as Dompdf;   // translating Dompdf (2026-10-22)
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
    public function issuer(): array
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
            // The legal name, from the one place it is kept (App\Support\Company, 2026-11-04).
            'name' => \App\Support\Company::legalName(),
            'email' => $get('invoice.issuer.email', $get('mail.from', 'noreply@kiddietrac.com')),
            /* These have no honest default. Blank until someone sets them. */
            'address' => $get('invoice.issuer.address'),
            'tax_id' => $get('invoice.issuer.tax_id'),
            'tax_label' => $get('invoice.issuer.tax_label', 'Tax ID'),
            'terms' => $get('invoice.issuer.payment_terms'),
            /* Invoice queries go to sales@, not the noreply@ the mail is sent from —
               replying to noreply reaches nobody. */
            'reference_email' => $get('invoice.issuer.reference_email', 'sales@kiddietrac.com'),
            'phone' => $get('invoice.issuer.phone', '1-855-400-9996'),
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
        /* (2026-10-25) The invoice prints this on its NAVY header band: login-wordmark.png is the
           white + teal wordmark made for navy, so it is right there and invisible on white paper.
           The BRAND WORDMARK, not the PWA app icon.
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

    /**
     * The invoice as HTML. Kept separate so it can be previewed without rendering a PDF.
     *
     * REDESIGNED (2026-10-25, Anthony: "the invoice attached does not appear professional and logo
     * not showing up correctly again - full sweep"). The logo picker chose the widest file, which
     * is the white + teal wordmark made for navy grounds, so "Kiddie" vanished on white paper. Now
     * the wordmark sits on a navy band (where it belongs, as on the sales sheets), with the invoice
     * identity beside it; From / Billed to / Details in three columns; an amount-due panel; an
     * itemised table with a navy header; totals with the tax line named and rated; notes; payment;
     * and a quiet footer. Helvetica (a core PDF font) instead of DejaVu.
     */
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
            ]);
        if (! $inv) {
            return null;
        }

        $iss = $this->issuer();
        $logo = $this->logoDataUri();
        $cur = $inv->currency ?: 'CAD';
        $money = fn ($cents) => \App\Support\PlatformBilling::money((int) $cents, $cur);
        // A credit (complimentary, discount, refund credit) reads "− CAD $x" on one line (2026-10-25).
        $signed = fn ($cents) => (int) $cents < 0 ? '-' . \App\Support\PlatformBilling::money(abs((int) $cents), $cur) : \App\Support\PlatformBilling::money((int) $cents, $cur);
        $date = fn ($d) => $d ? Carbon::parse($d)->format('M j, Y') : '—';
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        // (416) 570-2747 rather than 4165702747; anything else is printed as entered.
        $tel = function ($v) { $d = preg_replace('/\D/', '', (string) $v); if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
            return strlen($d) === 10 ? '(' . substr($d, 0, 3) . ') ' . substr($d, 3, 3) . '-' . substr($d, 6) : (string) $v; };
        $navy = '#0A1E55'; $teal = '#1DB9C3'; $ink = '#0F172A'; $muted = '#64748B'; $line = '#E2E8F0';

        $taxCents = (int) ($inv->tax_cents ?? 0);
        $lineAmount = (int) ($inv->subtotal_cents ?: ((int) $inv->amount_cents - $taxCents));
        $due = max(0, (int) $inv->amount_cents - (int) $inv->amount_paid_cents);
        $rateBps = (int) ($inv->tax_rate_bps ?? 0);

        /* Incomplete only where it matters: no business address, or tax charged with no tax number
           to show beside it. Says so on the face of the document rather than looking finished. */
        $missing = [];
        if (! $iss['address']) { $missing[] = 'business address'; }
        if ($taxCents > 0 && ! $iss['tax_id']) { $missing[] = strtolower($iss['tax_label']) . ' number'; }
        /* NOT ON THE CUSTOMER'S COPY (2026-10-25, Anthony: "with the physical invoice remove the HST warning -
           customers don't need to see that!"). Sales -> Invoices shows it to platform admins instead. */
        $banner = '';

        $status = (string) $inv->status;
        $stCol = ['paid' => '#16A34A', 'void' => '#64748B', 'issued' => $teal, 'sent' => $teal, 'overdue' => '#DC2626'][$status] ?? '#D97706';
        $stWord = ['paid' => 'PAID', 'void' => 'VOID', 'issued' => 'ISSUED', 'sent' => 'SENT', 'overdue' => 'OVERDUE', 'draft' => 'DRAFT'][$status] ?? strtoupper($status);

        // ── items ──
        $items = [];
        if (\Illuminate\Support\Facades\Schema::hasTable('platform_invoice_lines')) {
            foreach (DB::table('platform_invoice_lines')->where('invoice_id', $inv->id)->orderBy('id')->get() as $ln) {
                $items[] = ['desc' => (string) $ln->description, 'part' => $ln->part_number,
                    'period' => $ln->period_start ? $date($ln->period_start) . ' – ' . $date($ln->period_end) : '',
                    'qty' => (float) $ln->qty, 'unit' => (int) $ln->unit_cents, 'amount' => (int) $ln->amount_cents];
            }
        }
        if (! $items) {
            $items[] = ['desc' => 'KiddieTrac subscription' . ($inv->plan_code ? ' — ' . ucfirst((string) $inv->plan_code) . ' plan' : ''), 'part' => null,
                'period' => $date($inv->period_start) . ' – ' . $date($inv->period_end), 'qty' => 1.0, 'unit' => $lineAmount, 'amount' => $lineAmount];
        }
        $rows = '';
        foreach ($items as $i => $it) {
            $qty = rtrim(rtrim(number_format($it['qty'], 2), '0'), '.');
            $bg = $i % 2 ? '#F8FAFC' : '#FFFFFF';
            $rows .= '<tr style="background:' . $bg . ';">'
                . '<td style="padding:7px 10px;border-bottom:1px solid ' . $line . ';color:' . $muted . ';width:22px;">' . ($i + 1) . '</td>'
                . '<td style="padding:7px 10px;border-bottom:1px solid ' . $line . ';">'
                . '<div style="font-weight:bold;color:' . $ink . ';font-size:11px;">' . $e($it['desc']) . '</div>'
                . '<div style="color:' . $muted . ';font-size:9px;margin-top:2px;">' . ($it['part'] ? 'Part # ' . $e($it['part']) : '') . ($it['part'] && $it['period'] ? ' &nbsp;·&nbsp; ' : '') . $e($it['period']) . '</div></td>'
                . '<td style="padding:7px 10px;border-bottom:1px solid ' . $line . ';text-align:center;">' . $e($qty) . '</td>'
                . '<td style="padding:7px 10px;border-bottom:1px solid ' . $line . ';text-align:right;white-space:nowrap;">' . $e($signed($it['unit'])) . '</td>'
                . '<td style="padding:7px 10px;border-bottom:1px solid ' . $line . ';text-align:right;white-space:nowrap;font-weight:bold;color:' . ((int) $it['amount'] < 0 ? '#047857' : $ink) . ';">' . $e($signed($it['amount'])) . '</td></tr>';
        }

        $tot = fn ($l, $v, $strong = false) => '<tr><td style="padding:5px 10px;color:' . ($strong ? '#FFFFFF' : $muted) . ';font-size:' . ($strong ? '11px;font-weight:bold' : '10px') . ';">' . $e($l) . '</td>'
            . '<td style="padding:5px 10px;text-align:right;color:' . ($strong ? '#FFFFFF' : $ink) . ';font-size:' . ($strong ? '14px;font-weight:bold' : '11px') . ';">' . $e($v) . '</td></tr>';
        $totals = $tot('Subtotal', $money($lineAmount))
            . ($rateBps > 0 || $taxCents > 0 ? $tot(($inv->tax_label ?: 'Tax') . ' (' . \App\Support\PlatformBilling::ratePercent($rateBps) . ')', $money($taxCents)) : '')
            . $tot('Total', $money((int) $inv->amount_cents))
            . ((int) $inv->amount_paid_cents > 0 ? $tot('Paid', '-' . $money((int) $inv->amount_paid_cents)) : '');

        $addr = collect([$inv->address_line1, $inv->address_line2,
                trim(implode(', ', array_filter([$inv->city, $inv->province])) . ' ' . (string) $inv->postal_code), $inv->country])
            ->map(fn ($l) => trim((string) $l))->filter()->unique()->values()->all();
        $billTo = '<div style="font-size:12px;font-weight:bold;color:' . $ink . ';margin-bottom:3px;">' . $e($inv->agency_legal_name ?: $inv->agency_name) . '</div>'
            . implode('', array_map(fn ($l) => '<div>' . $e($l) . '</div>', $addr))
            . ($inv->agency_email ? '<div>' . $e($inv->agency_email) . '</div>' : '')
            . ($inv->agency_phone ? '<div>' . $e($tel($inv->agency_phone)) . '</div>' : '')
            . ($inv->agency_tax_registration ? '<div>' . $e($inv->tax_label ?: 'Tax') . ' # ' . $e($inv->agency_tax_registration) . '</div>' : '');
        $from = '<div style="font-size:12px;font-weight:bold;color:' . $ink . ';margin-bottom:3px;">' . $e($iss['name']) . '</div>'
            . ($iss['address'] ? '<div>' . nl2br($e((string) $iss['address'])) . '</div>' : '')
            . ($iss['phone'] ? '<div>' . $e($iss['phone']) . '</div>' : '')
            . '<div>' . $e($iss['reference_email']) . '</div>'
            . ($iss['tax_id'] ? '<div>' . $e($iss['tax_label']) . ' # ' . $e($iss['tax_id']) . '</div>' : '');
        $kv = fn ($k, $v) => '<tr><td style="padding:3px 0;color:' . $muted . ';">' . $e($k) . '</td><td style="padding:3px 0;text-align:right;color:' . $ink . ';font-weight:bold;">' . $e($v) . '</td></tr>';
        $cusNo = \App\Support\SalesCustomers::numberForAgency($inv->agency_id ? (int) $inv->agency_id : null);   // KT-CUS-0002 (2026-11-11)
        $details = '<table style="width:100%;">' . $kv('Invoice #', $inv->number) . ($cusNo ? $kv('Customer #', $cusNo) : '') . $kv('Invoice date', $date($inv->issued_at ?: $inv->created_at))
            . $kv('Due date', $date($inv->due_at)) . $kv('Billing period', $date($inv->period_start) . ' – ' . $date($inv->period_end)) . $kv('Currency', $cur) . '</table>';
        $label = fn ($t) => '<div style="font-size:8.5px;font-weight:bold;letter-spacing:1.4px;color:' . $teal . ';text-transform:uppercase;margin-bottom:6px;">' . $t . '</div>';

        $acct = \App\Support\PlatformAccounts::settings();
        $paidRows = '';
        if (\Illuminate\Support\Facades\Schema::hasTable('platform_invoice_payments')) {
            $plist = DB::table('platform_invoice_payments')->where('invoice_id', $inv->id)->where('status', 'confirmed')->orderBy('paid_on')->get();
            if ($plist->count()) {
                $paidRows = '<div style="margin-top:10px;font-size:8.5px;font-weight:bold;letter-spacing:1.2px;color:' . $muted . ';">PAYMENTS RECEIVED</div><table style="width:100%;">';
                foreach ($plist as $pp) {
                    $paidRows .= '<tr><td style="padding:3px 10px;font-size:9.5px;color:' . $muted . ';">' . $e($date($pp->paid_on)) . ' · ' . $e(\App\Support\PlatformAccounts::METHODS[$pp->method] ?? $pp->method) . ($pp->receipt_number ? ' · ' . $e($pp->receipt_number) : '') . '</td>'
                        . '<td style="padding:3px 10px;text-align:right;font-size:10px;color:#16A34A;font-weight:bold;">' . $e($money((int) $pp->amount_cents)) . '</td></tr>';
                }
                $paidRows .= '</table>';
            }
        }
        $free = (int) $inv->amount_cents === 0;
        $payBox = $status === 'paid' && ! $free
            ? '<b style="color:#16A34A;">Paid in full' . ($inv->paid_at ? ' on ' . $e($date($inv->paid_at)) : '') . '.</b> Thank you. Your receipts list each payment received.'
            : ($free
            ? '<b style="color:' . $ink . ';">No payment required.</b> This invoice is for $0.00 and settles automatically on its due date. It is your record of the KiddieTrac services provided for the period.'
            : ($iss['terms'] ? nl2br($e((string) $iss['terms'])) : 'Payment is due by <b>' . $e($date($inv->due_at)) . '</b>. Please quote <b>' . $e($inv->number) . '</b> with your payment.')
              . '<br><br><b style="color:' . $ink . ';">How to pay</b><br>' . nl2br($e($acct['payment_instructions']))
              . '<br><br>You can pay early, pay in part, or prepay the full year from the Licence page in your portal.');

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . '@page{margin:0;}'
            . 'body{font-family:Helvetica,Arial,sans-serif;color:#334155;font-size:10px;line-height:1.5;margin:0;padding:0;}'
            . 'table{border-collapse:collapse;}'
            . '.wrap{padding:0 40px 46px;}'
            . '</style></head><body>'
            // ── navy band: the wordmark where it belongs, and the invoice identity ──
            . '<div style="background:' . $navy . ';padding:20px 40px 16px;">'
            . '<table style="width:100%;"><tr>'
            . '<td style="vertical-align:middle;">' . ($logo ? '<img src="' . $logo . '" style="height:54px;">' : '<div style="font-size:26px;font-weight:bold;color:#fff;">' . $e($iss['name']) . '</div>')
            . '<div style="color:#9FB3D9;font-size:9px;letter-spacing:.4px;margin-top:4px;">Smart Childcare Management Platform</div></td>'
            . '<td style="vertical-align:middle;text-align:right;">'
            . '<div style="color:#FFFFFF;font-size:26px;font-weight:bold;letter-spacing:5px;">INVOICE</div>'
            . '<div style="color:#CFE0F5;font-size:11px;margin-top:2px;">' . $e($inv->number) . '</div>'
            . '<div style="display:inline-block;margin-top:8px;padding:3px 10px;border-radius:10px;font-size:8.5px;font-weight:bold;letter-spacing:1.2px;color:#fff;background:' . $stCol . ';">' . $e($stWord) . '</div>'
            . '</td></tr></table></div>'
            // The brand gradient underline, as on the marketing material and emails (2026-10-25).
            . (($grad = \App\Support\PlatformAccounts::dataUri(base_path('public/email/brand-gradient.png'))) ? '<img src="' . $grad . '" style="display:block;width:100%;height:6px;">' : '<div style="height:6px;background:' . $teal . ';"></div>')
            . '<div class="wrap" style="padding-top:16px;">'
            . $banner
            // ── from / billed to / details, and the amount due ──
            . '<table style="width:100%;"><tr>'
            . '<td style="width:30%;vertical-align:top;padding-right:14px;">' . $label('From') . $from . '</td>'
            . '<td style="width:30%;vertical-align:top;padding-right:14px;">' . $label('Billed to') . $billTo . '</td>'
            . '<td style="width:40%;vertical-align:top;">' . $label('Invoice details') . $details . '</td>'
            . '</tr></table>'
            . '<table style="width:100%;margin-top:12px;"><tr>'
            . '<td style="background:#F1F7FB;--kt-edge:' . $teal . ';padding:9px 16px;">'
            . '<div style="font-size:8.5px;font-weight:bold;letter-spacing:1.4px;color:' . $muted . ';text-transform:uppercase;">' . ($status === 'paid' ? 'Paid in full' : 'Amount due') . '</div>'
            . '<div style="font-size:24px;font-weight:bold;color:' . ($status === 'paid' ? '#16A34A' : $navy) . ';margin-top:2px;">' . $e($money($status === 'paid' ? (int) $inv->amount_paid_cents : $due)) . '</div></td>'
            . '<td style="background:#F1F7FB;padding:9px 16px;text-align:right;vertical-align:middle;">'
            . '<div style="font-size:8.5px;font-weight:bold;letter-spacing:1.4px;color:' . $muted . ';text-transform:uppercase;">Due date</div>'
            . '<div style="font-size:15px;font-weight:bold;color:' . $ink . ';margin-top:2px;">' . $e($date($inv->due_at)) . '</div></td>'
            . '</tr></table>'
            // ── items ──
            . '<table style="width:100%;margin-top:14px;">'
            . '<tr style="background:' . $navy . ';">'
            . '<th style="padding:8px 10px;color:#fff;font-size:8.5px;letter-spacing:1px;text-align:left;">#</th>'
            . '<th style="padding:8px 10px;color:#fff;font-size:8.5px;letter-spacing:1px;text-align:left;">DESCRIPTION</th>'
            . '<th style="padding:8px 10px;color:#fff;font-size:8.5px;letter-spacing:1px;text-align:center;">QTY</th>'
            . '<th style="padding:8px 10px;color:#fff;font-size:8.5px;letter-spacing:1px;text-align:right;">UNIT PRICE</th>'
            . '<th style="padding:8px 10px;color:#fff;font-size:8.5px;letter-spacing:1px;text-align:right;">AMOUNT</th></tr>'
            . $rows . '</table>'
            // ── totals ──
            . '<table style="width:100%;margin-top:10px;"><tr><td style="width:55%;vertical-align:top;padding-right:18px;">'
            . ($inv->notes ? $label('Notes') . '<div style="color:#334155;">' . nl2br($e(preg_replace('/\s*\[voided[^\]]*\]/', '', (string) $inv->notes))) . '</div>' : '')
            . '</td><td style="width:45%;vertical-align:top;">'
            . '<table style="width:100%;">' . $totals . '</table>'
            . '<table style="width:100%;margin-top:4px;background:' . $navy . ';">' . $tot($status === 'paid' ? 'Paid in full' : 'Amount due', $money($due), true) . '</table>'
            . $paidRows
            . '</td></tr></table>'
            // ── payment ──
            . '<table style="width:100%;margin-top:14px;"><tr>'
            . '<td style="width:50%;vertical-align:top;border:1px solid ' . $line . ';--kt-edge:' . $navy . ';padding:12px 14px;">' . $label('Payment') . '<div>' . $payBox . '</div></td>'
            . '<td style="width:2%;"></td>'
            . '<td style="width:48%;vertical-align:top;border:1px solid ' . $line . ';--kt-edge:' . $teal . ';padding:12px 14px;">' . $label('Terms &amp; conditions') . '<div style="font-size:9px;line-height:1.55;">' . nl2br($e($acct['terms_full'])) . '</div></td>'
            . '</tr></table>'
            . '<div style="display:none;">'
            . ($inv->payment_reference ? '<div style="margin-top:6px;color:' . $muted . ';">Payment reference: ' . $e($inv->payment_reference) . '</div>' : '')
            . '</div>'
            . '<div style="margin-top:10px;text-align:center;color:' . $muted . ';font-size:9px;"><b style="color:' . $navy . ';font-size:11px;">Thank you for choosing KiddieTrac.</b> &nbsp;Questions? Email ' . $e($iss['reference_email']) . ($iss['phone'] ? ' or call ' . $e($iss['phone']) : '') . ', quoting ' . $e($inv->number) . '.</div>'
            . '</div>'
            // ── footer, on the page ──
            . '<div style="position:fixed;bottom:0;left:0;right:0;padding:10px 40px;border-top:1px solid ' . $line . ';color:#94A3B8;font-size:8px;">'
            . '<table style="width:100%;"><tr><td>' . $e($iss['name']) . ($iss['address'] ? ' · ' . $e(preg_replace('/\s*\n\s*/', ', ', (string) $iss['address'])) : '') . '</td>'
            . '<td style="text-align:right;">kiddietrac.com · ' . $e($inv->number) . '</td></tr></table></div>'
            // The watermark goes LAST so it is drawn over everything else, centred on the page.
            . ($status === 'paid' ? '<div style="position:fixed;top:0;left:0;width:100%;height:100%;"><div style="position:absolute;top:400px;left:0;width:100%;text-align:center;"><span style="display:inline-block;font-size:150px;font-weight:bold;letter-spacing:12px;color:#16A34A;opacity:.20;border:9px solid #16A34A;border-radius:26px;padding:0 34px;transform:rotate(-28deg);">PAID</span></div></div>' : '')
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
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
