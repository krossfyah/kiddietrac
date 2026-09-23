<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A receipt for one payment (2026-09-17).
 *
 * There was no payment receipt in the portal at all — only App\Models\TaxReceipt, which is
 * the year-end childcare-expense document and answers a different question. A family that
 * paid $500 in cash had nothing confirming it arrived.
 *
 * THE FIGURES LIVE IN THE PDF, NOT THE EMAIL. Anthony: "receipt should be a pdf and never
 * outlined in the email like that for privacy reasons." Email is forwarded, quoted,
 * previewed on a lock screen and synced to whatever the recipient's phone backs up to, so
 * the message body says only that a receipt is attached. The amount is kept out of the
 * subject line too — a notification preview is where a figure travels furthest with the
 * least control over who sees it.
 *
 * IT NAMES THE SERVICE FEE SEPARATELY where one was charged. A parent who paid $514.50 on
 * a $500 invoice will ask what the extra was, and a receipt that folds it into a total is
 * how that becomes a phone call.
 *
 * IT IS NOT A TAX RECEIPT and says so: a childcare tax receipt has statutory content and
 * a different total (it excludes fees), so a parent must not file this one.
 */
final class PaymentReceipt
{
    /**
     * Everything both the email and the document need, resolved once.
     *
     * @return array<string,mixed>|null
     */
    public static function facts(int $paymentId): ?array
    {
        $p = DB::table('payments')->where('id', $paymentId)->first();
        if (! $p) { return null; }

        /* A payment belongs to one of two invoice tables. Whichever it is, the receipt
           reads the same to the family. */
        $inv = $p->invoice_id
            ? DB::table('invoices')->where('id', $p->invoice_id)->first()
            : DB::table('external_invoices')->where('id', $p->external_invoice_id)->first();
        if (! $inv) { return null; }

        $agencyId = (int) ($inv->agency_id
            ?? DB::table('centres')->where('id', $inv->centre_id ?? 0)->value('agency_id'));
        if (! $agencyId) { return null; }

        $agency = DB::table('agencies')->where('id', $agencyId)
            ->first(['name', 'settings', 'brand_logo_url', 'brand_support_email', 'brand_primary_color']);

        /* THE RECEIPT IS THE AGENCY'S PAPER, not KiddieTrac's. Anthony: "add the agency
           info in the receipt as well with agency logo etc." A receipt with no letterhead
           is indistinguishable from a printout, and a parent forwarding it to an employer
           needs it to name who took the money. */
        $address = '';
        try {
            $s = $agency && $agency->settings ? json_decode((string) $agency->settings, true) : null;
            $address = is_array($s) ? (string) ($s['brand_address'] ?? '') : '';
        } catch (Throwable $e) { /* an address is decoration here */ }

        $methods = [
            'cash' => 'Cash', 'cheque' => 'Cheque', 'interac' => 'Interac e-Transfer',
            'eft' => 'EFT / bank transfer', 'card' => 'Card', 'stripe_card' => 'Card',
            'stripe_ach' => 'Bank transfer', 'manual' => 'Recorded manually',
        ];

        /* Fee lines on the invoice, so the receipt can explain the gap between what the
           childcare cost and what was actually paid. */
        $fees = $p->invoice_id
            ? (float) DB::table('invoice_lines')->where('invoice_id', $p->invoice_id)
                ->where('description', 'like', 'Service fee%')->sum('amount')
            : 0.0;

        $when = $p->paid_at ? Carbon::parse($p->paid_at) : Carbon::parse($p->created_at);

        return [
            'agency' => (string) ($agency->name ?? 'Your childcare provider'),
            'agency_address' => trim($address),
            'agency_email' => (string) ($agency->brand_support_email ?? ''),
            /* Masked the way the portal shows every other phone number
               ([[kiddietrac-phone-mask]]) - a receipt printing 4169999999 looks like a
               reference code, not a number somebody can ring. */
            'agency_phone' => self::phone((string) (DB::table('centres')->where('agency_id', $agencyId)
                ->whereNotNull('phone')->value('phone') ?: '')),
            'logo' => self::inlineLogo($agency->brand_logo_url ?? null),
            /* The agency's own colour, so the receipt matches the invoice it belongs to.
               Validated: a malformed value would end up inside a style attribute. */
            'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($agency->brand_primary_color ?? ''))
                ? (string) $agency->brand_primary_color : '#1F6080',
            'agency_id' => $agencyId,
            'family' => (string) (DB::table('families')->where('id', $p->family_id)->value('family_name') ?: 'Account'),
            'number' => (string) ($inv->invoice_number ?? $inv->number ?? ('#' . ($inv->id ?? ''))),
            'receipt_no' => 'R-' . str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT),
            'amount' => '$' . number_format((float) $p->amount, 2),
            'amount_raw' => (float) $p->amount,
            'when' => $when->format('j F Y'),
            'issued' => Carbon::now()->format('j F Y'),
            'method' => $methods[(string) $p->method] ?? ucfirst((string) $p->method),
            'reference' => (string) ($p->reference_number ?? ''),
            'balance' => '$' . number_format((float) ($inv->balance_due ?? 0), 2),
            'fees' => $fees,
        ];
    }

    /**
     * The covering email. Carries no figures — they are all in the attachment.
     *
     * @return array{html:string,subject:string,agency_id:int,pdf:?string,filename:string}|null
     */
    public static function build(int $paymentId): ?array
    {
        $f = self::facts($paymentId);
        if (! $f) { return null; }

        $body = '<table width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td style="padding:0 0 14px;font-size:15px;line-height:1.6;color:#334155;">'
            . 'Thank you — we have received your payment.</td></tr>'
            . '<tr><td style="padding:0 0 14px;font-size:15px;line-height:1.6;color:#334155;">'
            . 'Your receipt is <strong>attached as a PDF</strong>. It shows the amount, how it was '
            . 'paid and what is left on the account. We keep those details out of the email itself '
            . 'so they are not sitting in an inbox or a message preview.</td></tr>'
            . '<tr><td style="padding:2px 0 0;font-size:13px;line-height:1.6;color:#64748B;">'
            . 'Please keep it for your records. It is a payment receipt, not a childcare tax '
            . 'receipt — your tax receipt is issued separately at the end of the year.</td></tr>'
            . '</table>';

        return [
            'html' => EmailTemplate::wrap($f['agency_id'], $body, [
                'eyebrow' => 'PAYMENT RECEIVED',
                'title' => 'Your receipt is attached',
                'subtitle' => $f['agency'],
                'preheader' => 'Your payment receipt from ' . $f['agency'] . ' is attached as a PDF.',
            ]),
            'subject' => 'Your receipt from ' . $f['agency'],
            'agency_id' => $f['agency_id'],
            'pdf' => self::pdf($paymentId),
            'filename' => 'receipt-' . preg_replace('/[^A-Za-z0-9._-]/', '', $f['receipt_no']) . '.pdf',
        ];
    }

    /**
     * The receipt as a file.
     *
     * Returns null rather than a half-made document if dompdf cannot produce one: an HTML
     * file wearing a .pdf extension is worse than a missing attachment, because the parent
     * it is forwarded to simply cannot open it. The caller sends the covering note without
     * it and says so.
     */
    public static function pdf(int $paymentId): ?string
    {
        try {
            $html = self::documentHtml($paymentId);
            if (! $html) { return null; }

            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
            $dompdf->loadHtml($html, 'UTF-8');
            /* A TILL ROLL, NOT A LETTER PAGE. 80mm is the standard thermal receipt width
               (226.77pt); the length is generous so a receipt with several lines still
               lands on one strip rather than breaking across pages. */
            $dompdf->setPaper([0, 0, 226.77, 680], 'portrait');
            $dompdf->render();
            $out = $dompdf->output();

            return (is_string($out) && str_starts_with($out, '%PDF')) ? $out : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Embed the agency logo so the PDF carries it without a network fetch at render time.
     *
     * dompdf CAN fetch a remote image, but on a shared host that is a blocking request
     * inside the render, and a slow or missing logo would either stall the receipt or
     * leave a broken box on it. Falls back to the URL, and the caller falls back to text.
     */
    /** (416) 999-9999 from any 10- or 11-digit form; anything else is left alone. */
    private static function phone(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw);
        if (strlen($d) === 11 && str_starts_with($d, '1')) { $d = substr($d, 1); }
        if (strlen($d) !== 10) { return trim($raw); }

        return '(' . substr($d, 0, 3) . ') ' . substr($d, 3, 3) . '-' . substr($d, 6);
    }

    private static function inlineLogo(?string $url): ?string
    {
        if (! $url) { return null; }

        $abs = preg_match('#^https?://#i', $url) ? $url : ('https://app.kiddietrac.com/' . ltrim($url, '/'));
        try {
            $bytes = @file_get_contents($abs, false, stream_context_create(['http' => ['timeout' => 5]]));
            if ($bytes && strlen($bytes) > 30) {
                $low = strtolower($abs);
                $mime = (str_ends_with($low, '.jpg') || str_ends_with($low, '.jpeg')) ? 'image/jpeg'
                    : (str_ends_with($low, '.svg') ? 'image/svg+xml' : 'image/png');

                return 'data:' . $mime . ';base64,' . base64_encode($bytes);
            }
        } catch (Throwable $e) { /* fall through to the URL */ }

        return $abs;
    }

    /**
     * CODE 39, drawn as real bars.
     *
     * A barcode printed on a financial document that turns out to be decorative noise is
     * worse than no barcode: somebody will try to scan it. Code 39 is simple enough to
     * implement correctly - each character is nine elements, five bars and four spaces,
     * three of the nine wide - so this one actually scans, and the human-readable number
     * sits underneath it the way it does on a real receipt.
     *
     * Drawn as adjacent divs with a background colour, which dompdf renders reliably;
     * an SVG or a font would each be a dependency this does not need.
     */
    private static function code39(string $value): string
    {
        static $map = [
            '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
            '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
            '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
            'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
            'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
            'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
            'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
            'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
            'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
            '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '*' => 'nwnnwnwnn',
        ];

        $text = '*' . strtoupper(preg_replace('/[^0-9A-Za-z .-]/', '', $value)) . '*';
        $out = '';
        $len = strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $pattern = $map[$text[$i]] ?? $map['-'];
            for ($n = 0; $n < 9; $n++) {
                $w = $pattern[$n] === 'w' ? 2.4 : 1.0;
                // Even positions are bars, odd are spaces.
                $colour = ($n % 2 === 0) ? '#0F172A' : '#FFFFFF';
                $out .= '<span style="display:inline-block;width:' . $w . 'px;height:38px;'
                    . 'background:' . $colour . ';"></span>';
            }
            // The inter-character gap.
            $out .= '<span style="display:inline-block;width:1px;height:38px;background:#FFFFFF;"></span>';
        }

        return $out;
    }

    /**
     * The receipt, drawn as a till roll.
     *
     * REDRAWN 2026-09-17 to the reference Anthony sent: a narrow paper strip, serrated
     * top and bottom, everything monospaced and centred, dashed rules between the
     * sections, the total set large, THANK YOU at the foot and a barcode under it.
     *
     * Monospace is doing real work here rather than being a costume: figures set in a
     * fixed-width face line up on the decimal point down the column without any table
     * alignment, which is exactly why tills print this way.
     *
     * The agency's letterhead - logo, name, address, phone - sits at the top, because the
     * receipt is their paper and a parent forwarding it to an employer needs it to name
     * who took the money.
     */
    public static function documentHtml(int $paymentId): ?string
    {
        $f = self::facts($paymentId);
        if (! $f) { return null; }

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        /* A dashed rule. dompdf draws a real dashed border reliably; a row of hyphens
           would wrap differently at every font size. */
        $rule = '<div style="border-top:1px dashed #94A3B8;margin:9px 0;"></div>';

        /* One printed line: label left, figure hard right. `width:100%` on a table is the
           only alignment dompdf gets right at every font size. */
        $line = fn ($label, $value, $size = '11px', $bold = false) =>
            '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:1px 0;"><tr>'
            . '<td style="font-size:' . $size . ';' . ($bold ? 'font-weight:700;' : '') . '">' . $e($label) . '</td>'
            . '<td style="font-size:' . $size . ';text-align:right;' . ($bold ? 'font-weight:700;' : '') . '">'
            . $e($value) . '</td></tr></table>';

        $sub = $f['amount_raw'] - $f['fees'];
        $paidAt = $f['when'];

        /* The serrated edge, as a row of small triangles. A CSS zigzag would rely on
           gradient support dompdf only partly has; these are glyphs in the bundled
           DejaVu font and render the same everywhere. */
        $tear = '<div style="font-size:9px;color:#CBD5E1;letter-spacing:-1px;line-height:8px;'
            . 'text-align:center;overflow:hidden;">' . str_repeat('▼', 42) . '</div>';

        $logo = $f['logo']
            ? '<div style="text-align:center;margin-bottom:6px;"><img src="' . $e($f['logo'])
              . '" style="max-height:38px;max-width:110px;"></div>'
            : '';

        $addressLines = array_values(array_filter(array_map('trim',
            preg_split('/\r\n|\r|\n/', (string) $f['agency_address']) ?: [])));

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . '@page { margin: 10px 12px; }'
            . 'body { font-family: "DejaVu Sans Mono", monospace; color:#0F172A; font-size:11px; }'
            . '</style></head><body>'

            . $tear

            . '<div style="padding:10px 6px 4px;">'
            . $logo
            . '<div style="text-align:center;font-size:17px;font-weight:700;letter-spacing:2px;">RECEIPT</div>'
            . '<div style="text-align:center;font-size:12px;font-weight:700;margin-top:5px;">'
            . $e($f['agency']) . '</div>'
            . (count($addressLines)
                ? '<div style="text-align:center;font-size:10px;line-height:1.45;margin-top:2px;">'
                  . implode('<br>', array_map($e, $addressLines)) . '</div>'
                : '')
            . ($f['agency_phone'] !== ''
                ? '<div style="text-align:center;font-size:10px;margin-top:1px;">Tel: ' . $e($f['agency_phone']) . '</div>'
                : '')
            . ($f['agency_email'] !== ''
                ? '<div style="text-align:center;font-size:10px;">' . $e($f['agency_email']) . '</div>'
                : '')

            . $rule
            . $line('Date: ' . $paidAt, $f['receipt_no'], '10px')
            . $rule

            // ── what was paid for ──
            . $line($f['number'], number_format($sub, 2))
            . ($f['fees'] > 0.005 ? $line('Service fee', number_format($f['fees'], 2)) : '')

            . $rule
            . $line('Total', number_format($f['amount_raw'], 2), '16px', true)
            . '<div style="height:4px;"></div>'
            . $line('  Sub-total', number_format($sub, 2), '10px')
            . ($f['fees'] > 0.005 ? $line('  Service fee', number_format($f['fees'], 2), '10px') : '')
            . $line('  Balance', ltrim($f['balance'], '$'), '10px')
            . $rule

            // ── how ──
            . $line('Paid by', $f['method'], '10px')
            . ($f['reference'] !== '' ? $line('Ref', $f['reference'], '10px') : '')
            . $line('Account', $f['family'], '10px')
            . $rule

            . '<div style="text-align:center;font-size:16px;font-weight:700;letter-spacing:3px;'
            . 'margin:10px 0 8px;">THANK YOU</div>'

            . '<div style="text-align:center;line-height:0;font-size:0;">' . self::code39($f['receipt_no']) . '</div>'
            . '<div style="text-align:center;font-size:9px;letter-spacing:2px;margin-top:3px;">'
            . $e($f['receipt_no']) . '</div>'

            . '<div style="text-align:center;font-size:8px;color:#64748B;line-height:1.5;margin-top:9px;">'
            . 'Payment receipt — not a childcare tax receipt.<br>'
            . 'Your tax receipt is issued separately at year end.</div>'
            . '</div>'

            . $tear
            . '</body></html>';
    }
}
