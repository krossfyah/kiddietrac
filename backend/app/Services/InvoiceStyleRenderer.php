<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TEN MORE INVOICE STYLES, FROM ONE DRAWING ENGINE (2026-09-17).
 *
 * Anthony asked for ten more to choose from, "all different styles and formats and
 * colours". Ten hand-written renderers would be ten places to fix the next time an
 * invoice needs a subsidy line, so there is one renderer and ten SPECS: each names its
 * palette, its header treatment, its typography and how the table and totals are drawn.
 * Adding an eleventh is a dozen lines in STYLES, not a new class.
 *
 * They are deliberately different documents rather than one document in ten colours —
 * a band header reads nothing like a left rail or a plain rule, and a serif letterhead
 * reads nothing like a condensed receipt. Colour alone would not have answered the ask.
 *
 * EVERY STYLE USES THE AGENCY'S OWN LOGO AND DETAILS. A style chooses the shape of the
 * document; the brand inside it stays the agency's.
 *
 * Tables throughout, never flex or grid: dompdf orphans grid cells, which is the same
 * reason iLearn's own template is built from tables.
 */
final class InvoiceStyleRenderer
{
    /**
     * @var array<string,array<string,mixed>>
     *   accent   — the one strong colour
     *   ink      — body text
     *   paper    — the page behind the sheet
     *   header   — band | rule | rail | split | plain | receipt
     *   font     — sans | serif | mono
     *   rows     — striped | bordered | plain
     *   caps     — uppercase column headings
     *   radius   — corner rounding in px
     */
    public const STYLES = [
        'classic_navy' => [
            'label' => 'Classic Navy', 'blurb' => 'Serif letterhead with a deep navy band and bordered lines — formal and traditional.',
            'accent' => '#0B2545', 'ink' => '#111827', 'paper' => '#EEF1F6',
            'header' => 'band', 'font' => 'serif', 'rows' => 'bordered', 'caps' => true, 'radius' => 0,
        ],
        'minimal_mono' => [
            'label' => 'Minimal Mono', 'blurb' => 'No colour at all — hairlines, generous white space and a quiet type scale.',
            'accent' => '#111827', 'ink' => '#111827', 'paper' => '#FFFFFF',
            'header' => 'plain', 'font' => 'sans', 'rows' => 'plain', 'caps' => false, 'radius' => 0,
        ],
        'modern_teal' => [
            'label' => 'Modern Teal', 'blurb' => 'Rounded card, soft teal tint and striped rows — the friendliest of the set.',
            'accent' => '#0FA3B1', 'ink' => '#0F172A', 'paper' => '#ECFBFC',
            'header' => 'split', 'font' => 'sans', 'rows' => 'striped', 'caps' => true, 'radius' => 14,
        ],
        'bold_slate' => [
            'label' => 'Bold Slate', 'blurb' => 'A heavy dark header block with reversed white type. Strong and modern.',
            'accent' => '#1F2937', 'ink' => '#111827', 'paper' => '#F3F4F6',
            'header' => 'band', 'font' => 'sans', 'rows' => 'plain', 'caps' => true, 'radius' => 8,
        ],
        'warm_sand' => [
            'label' => 'Warm Sand', 'blurb' => 'Cream paper and brown serif ink — warm, printed, unlike anything on a screen.',
            'accent' => '#8B5E34', 'ink' => '#3B2F2A', 'paper' => '#FBF6EE',
            'header' => 'rule', 'font' => 'serif', 'rows' => 'plain', 'caps' => false, 'radius' => 6,
        ],
        'sunrise' => [
            'label' => 'Sunrise', 'blurb' => 'A warm orange band across the top with light, open spacing beneath it.',
            'accent' => '#EA580C', 'ink' => '#1F2937', 'paper' => '#FFF7ED',
            'header' => 'band', 'font' => 'sans', 'rows' => 'striped', 'caps' => true, 'radius' => 12,
        ],
        'forest' => [
            'label' => 'Forest', 'blurb' => 'Deep green with a boxed totals panel — calm and easy to read in print.',
            'accent' => '#166534', 'ink' => '#14210F', 'paper' => '#F1F7F1',
            'header' => 'rule', 'font' => 'sans', 'rows' => 'bordered', 'caps' => true, 'radius' => 10,
        ],
        'plum_rail' => [
            'label' => 'Plum Rail', 'blurb' => 'A coloured rail down the left edge instead of a header band.',
            'accent' => '#7E22CE', 'ink' => '#1E1B2E', 'paper' => '#F8F5FD',
            'header' => 'rail', 'font' => 'sans', 'rows' => 'plain', 'caps' => false, 'radius' => 10,
        ],
        'compact_receipt' => [
            'label' => 'Compact Receipt', 'blurb' => 'Narrow, condensed and monospaced — closest to a till receipt. Good for short invoices.',
            'accent' => '#334155', 'ink' => '#0F172A', 'paper' => '#FFFFFF',
            'header' => 'receipt', 'font' => 'mono', 'rows' => 'plain', 'caps' => false, 'radius' => 0,
        ],
        'corporate_grey' => [
            'label' => 'Corporate Grey', 'blurb' => 'Formal letterhead: rule lines, right-aligned meta block and understated grey.',
            'accent' => '#475569', 'ink' => '#0F172A', 'paper' => '#FFFFFF',
            'header' => 'split', 'font' => 'serif', 'rows' => 'bordered', 'caps' => true, 'radius' => 0,
        ],
    ];

    private const FONTS = [
        'sans'  => "-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif",
        'serif' => "Georgia,'Times New Roman',Times,serif",
        'mono'  => "Consolas,Menlo,'DejaVu Sans Mono',monospace",
    ];

    public function renderFromInvoiceId(int $invoiceId, string $style, bool $forPdf = true): ?string
    {
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        if (! $inv) {
            return null;
        }

        $agencyId = (int) DB::table('centres')->where('id', $inv->centre_id)->value('agency_id');
        $agency = $agencyId ? DB::table('agencies')->where('id', $agencyId)->first() : null;
        $family = DB::table('families')->where('id', $inv->family_id)->first();
        $lines = DB::table('invoice_lines')->where('invoice_id', $invoiceId)->get();

        $rows = [];
        if ($lines->isEmpty()) {
            $rows[] = [trim((string) ($inv->notes ?? '')) ?: 'Child care services', 1, (float) ($inv->subtotal ?: $inv->total)];
        } else {
            foreach ($lines as $l) {
                $qty = (float) ($l->quantity ?? 1);
                $rows[] = [(string) ($l->description ?? '—'), $qty,
                    isset($l->amount) ? (float) $l->amount : $qty * (float) ($l->unit_amount ?? 0)];
            }
        }

        $totals = [['Subtotal', (float) ($inv->subtotal ?? $inv->total), false]];
        if ((float) ($inv->subsidy_amount ?? 0) > 0)  { $totals[] = ['Subsidy',  -(float) $inv->subsidy_amount, false]; }
        if ((float) ($inv->discount_amount ?? 0) > 0) { $totals[] = ['Discount', -(float) $inv->discount_amount, false]; }
        if ((float) ($inv->tax_amount ?? 0) > 0)      { $totals[] = ['Tax',       (float) $inv->tax_amount, false]; }
        $totals[] = ['Total', (float) $inv->total, true];
        if ((float) ($inv->amount_paid ?? 0) > 0 && strtolower((string) $inv->status) !== 'paid') {
            $totals[] = ['Paid to date', (float) $inv->amount_paid, false];
            $totals[] = ['Balance due', (float) $inv->balance_due, true];
        }

        return $this->draw($style, $agency, [
            'number' => (string) ($inv->invoice_number ?: ('INV-' . $inv->id)),
            'status' => ucfirst((string) ($inv->status ?: 'draft')),
            'is_paid' => strtolower((string) $inv->status) === 'paid',
            'issued' => $inv->issued_at,
            'due' => $inv->due_at,
            'bill_to' => (string) ($family->family_name ?? 'Family'),
            'address' => $this->familyAddress($family),
            'email' => $this->primaryGuardianEmail((int) $inv->family_id) ?: (string) ($family->primary_email ?? ''),
            'rows' => $rows,
            'totals' => $totals,
        ], $forPdf);
    }

    /** The same drawing, with sample figures — for the Branding screen's live preview. */
    public function renderSample(?object $agency, string $style, bool $forPdf = false): string
    {
        return $this->draw($style, $agency, [
            'number' => 'PREVIEW-' . date('Ymd'),
            'status' => 'Unpaid',
            'is_paid' => false,
            'issued' => date('Y-m-d'),
            'due' => date('Y-m-d', strtotime('+14 days')),
            'bill_to' => 'The Thompson Family',
            'address' => "12 Example Street\nToronto ON M5V 1A1",
            'email' => 'sarah.thompson@example.com',
            'rows' => [
                ['Full-time childcare — June 2026', 1, 1650.00],
                ['Late pickup fee (June 8)', 1, 25.00],
                ['Field trip — Royal Botanical Gardens', 1, 35.00],
            ],
            'totals' => [['Subtotal', 1710.00, false], ['Subsidy', -385.00, false], ['Total', 1325.00, true]],
        ], $forPdf);
    }

    /** @param array<string,mixed> $d */
    private function draw(string $style, ?object $agency, array $d, bool $forPdf): string
    {
        $s = self::STYLES[$style] ?? self::STYLES['minimal_mono'];
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $money = fn ($v) => ($v < 0 ? '−$' : '$') . number_format(abs((float) $v), 2);
        $date = function ($v) {
            if (! $v) { return '—'; }
            try { return Carbon::parse($v)->format('j M Y'); } catch (Throwable $ex) { return '—'; }
        };

        $settings = [];
        if ($agency && ! empty($agency->settings)) {
            try { $settings = json_decode((string) $agency->settings, true) ?: []; } catch (Throwable $ex) {}
        }
        $name = (string) ($agency->name ?? 'Your childcare agency');
        $addr = trim((string) ($settings['brand_address'] ?? ''));
        $mail = (string) ($agency->contact_email ?? $agency->brand_support_email ?? '');
        $tel  = (string) ($agency->contact_phone ?? '');
        $logo = $this->inlineLogo($agency->brand_logo_url ?? $agency->logo_url ?? null);

        $accent = $s['accent']; $ink = $s['ink']; $font = self::FONTS[$s['font']];
        $radius = (int) $s['radius'];
        $narrow = $s['header'] === 'receipt';
        $sheetW = $narrow ? 420 : 760;

        /* The header is what makes these read as different documents rather than one
           document recoloured, so each treatment is drawn in full rather than toggled
           with a class. */
        $org = '<div style="font-size:12px;line-height:1.5">'
            . '<strong style="display:block;font-size:15px;margin-bottom:2px">' . $e($name) . '</strong>'
            . ($addr !== '' ? nl2br($e($addr)) . '<br>' : '')
            . ($mail !== '' ? $e($mail) . '<br>' : '')
            . ($tel !== '' ? $e($tel) : '')
            . '</div>';
        $logoImg = $logo ? '<img src="' . $logo . '" alt="' . $e($name) . '" style="height:56px;width:auto">' : '';
        $title = 'INVOICE';

        switch ($s['header']) {
            case 'band':
                $header = '<table style="width:100%;border-collapse:collapse;background:' . $accent . ';color:#fff;'
                    . 'border-radius:' . $radius . 'px"><tr>'
                    . '<td style="padding:22px 24px;vertical-align:middle">' . ($logo
                        ? '<img src="' . $logo . '" alt="" style="height:52px;width:auto;background:#fff;padding:6px;border-radius:8px">'
                        : '<div style="font-size:20px;font-weight:800">' . $e($name) . '</div>')
                    . '</td>'
                    . '<td style="padding:22px 24px;text-align:right;vertical-align:middle">'
                    . '<div style="font-size:28px;font-weight:800;letter-spacing:.06em">' . $title . '</div>'
                    . '<div style="font-size:12px;opacity:.85">' . $e($d['number']) . '</div></td>'
                    . '</tr></table>'
                    . '<table style="width:100%;border-collapse:collapse;margin-top:16px"><tr>'
                    . '<td style="vertical-align:top">' . $org . '</td></tr></table>';
                break;

            case 'rail':
                $header = '<table style="width:100%;border-collapse:collapse"><tr>'
                    . '<td style="width:10px;background:' . $accent . ';border-radius:' . $radius . 'px"></td>'
                    . '<td style="padding-left:18px;vertical-align:top">'
                    . '<div style="font-size:26px;font-weight:800;color:' . $accent . '">' . $title . '</div>'
                    . '<div style="font-size:12px;color:#64748B;margin-bottom:10px">' . $e($d['number']) . '</div>'
                    . $logoImg . $org . '</td></tr></table>';
                break;

            case 'split':
                $header = '<table style="width:100%;border-collapse:collapse"><tr>'
                    . '<td style="vertical-align:middle;width:55%">' . $logoImg
                    . '<div style="font-size:24px;font-weight:800;color:' . $accent . ';margin-top:6px">' . $title . '</div>'
                    . '<div style="font-size:12px;color:#64748B">' . $e($d['number']) . '</div></td>'
                    . '<td style="vertical-align:top;text-align:right">' . $org . '</td></tr></table>'
                    . '<div style="height:3px;background:' . $accent . ';margin:16px 0 20px"></div>';
                break;

            case 'rule':
                $header = '<div style="font-size:26px;font-weight:800;color:' . $accent . '">' . $title . '</div>'
                    . '<div style="font-size:12px;color:#64748B;margin-bottom:12px">' . $e($d['number']) . '</div>'
                    . '<div style="border-top:2px solid ' . $accent . ';margin:0 0 16px"></div>'
                    . '<table style="width:100%;border-collapse:collapse"><tr>'
                    . '<td style="vertical-align:top;width:60%">' . $logoImg . '</td>'
                    . '<td style="vertical-align:top;text-align:right">' . $org . '</td></tr></table>';
                break;

            case 'receipt':
                $header = '<div style="text-align:center">' . $logoImg
                    . '<div style="font-size:16px;font-weight:800;margin-top:6px">' . $e($name) . '</div>'
                    . '<div style="font-size:11px;color:#64748B;white-space:pre-line">' . $e($addr) . '</div>'
                    . '<div style="border-top:1px dashed ' . $accent . ';margin:12px 0"></div>'
                    . '<div style="font-size:13px;font-weight:700;letter-spacing:.1em">' . $title . '</div>'
                    . '<div style="font-size:11px;color:#64748B">' . $e($d['number']) . '</div></div>';
                break;

            default: // plain
                $header = '<table style="width:100%;border-collapse:collapse"><tr>'
                    . '<td style="vertical-align:top">' . $logoImg
                    . '<div style="font-size:22px;font-weight:700;margin-top:8px">' . $title . '</div>'
                    . '<div style="font-size:12px;color:#64748B">' . $e($d['number']) . '</div></td>'
                    . '<td style="vertical-align:top;text-align:right">' . $org . '</td></tr></table>'
                    . '<div style="border-top:1px solid #D1D5DB;margin:18px 0"></div>';
        }

        $meta = '<table style="width:100%;border-collapse:collapse;font-size:13px;margin:18px 0">'
            . '<tr><td style="vertical-align:top;width:55%;padding:2px 0">'
            . '<span style="color:#6B7280">Bill to:</span> <strong>' . $e($d['bill_to']) . '</strong>'
            . ($d['address'] ? '<div style="color:#475569;white-space:pre-line;margin-top:2px">' . $e($d['address']) . '</div>' : '')
            . ($d['email'] ? '<div style="color:#475569;margin-top:2px">' . $e($d['email']) . '</div>' : '')
            . '</td>'
            . '<td style="vertical-align:top;text-align:right;padding:2px 0">'
            . '<div><span style="color:#6B7280">Status:</span> <strong>' . $e($d['status']) . '</strong></div>'
            . '<div><span style="color:#6B7280">Issued:</span> ' . $e($date($d['issued'])) . '</div>'
            . '<div><span style="color:#6B7280">Due:</span> ' . $e($date($d['due'])) . '</div>'
            . '</td></tr></table>';

        $th = 'padding:9px 10px;text-align:left;font-size:' . ($s['caps'] ? '11px' : '12px') . ';'
            . 'color:' . ($s['rows'] === 'striped' ? '#fff' : '#6B7280') . ';'
            . ($s['caps'] ? 'text-transform:uppercase;letter-spacing:.05em;' : '')
            . ($s['rows'] === 'striped' ? 'background:' . $accent . ';' : 'border-bottom:2px solid ' . $accent . ';');
        $tdBase = 'padding:9px 10px;font-size:13px;'
            . ($s['rows'] === 'bordered' ? 'border-bottom:1px solid #E5E7EB;' : '')
            . ($s['rows'] === 'plain' ? 'border-bottom:1px solid #F1F5F9;' : '');

        $body = '';
        $i = 0;
        foreach ($d['rows'] as [$desc, $qty, $amt]) {
            $zebra = ($s['rows'] === 'striped' && $i % 2 === 1) ? 'background:#F8FAFC;' : '';
            $body .= '<tr><td style="' . $tdBase . $zebra . '">' . $e($desc) . '</td>'
                . '<td style="' . $tdBase . $zebra . 'text-align:right">' . rtrim(rtrim(number_format((float) $qty, 2), '0'), '.') . '</td>'
                . '<td style="' . $tdBase . $zebra . 'text-align:right">' . $money($amt) . '</td></tr>';
            $i++;
        }
        $table = '<table style="width:100%;border-collapse:collapse"><thead><tr>'
            . '<th style="' . $th . '">Description</th>'
            . '<th style="' . $th . 'text-align:right">Qty</th>'
            . '<th style="' . $th . 'text-align:right">Amount</th></tr></thead><tbody>' . $body . '</tbody></table>';

        $tot = '';
        foreach ($d['totals'] as [$lbl, $val, $strong]) {
            $tot .= '<tr><td style="padding:6px 0;' . ($strong ? 'border-top:2px solid ' . $ink . ';font-weight:800;font-size:15px;' : 'font-size:13px;') . '">' . $e($lbl) . '</td>'
                . '<td style="padding:6px 0;text-align:right;' . ($strong ? 'border-top:2px solid ' . $ink . ';font-weight:800;font-size:15px;' : 'font-size:13px;') . '">' . $money($val) . '</td></tr>';
        }
        $totalsBox = '<table style="margin-left:auto;width:' . ($narrow ? '100%' : '280px') . ';border-collapse:collapse;margin-top:14px;'
            . ($s['header'] === 'rule' || $s['header'] === 'split'
                ? 'background:#fff;border:1px solid #E5E7EB;border-radius:' . $radius . 'px;padding:4px 12px;' : '')
            . '">' . $tot . '</table>';

        $stamp = $d['is_paid']
            ? '<div style="margin-top:12px"><span style="display:inline-block;padding:4px 12px;border-radius:6px;'
              . 'background:rgba(16,185,129,.14);color:#0a8c63;font-weight:800;font-size:12px">PAID IN FULL</span></div>'
            : '';

        $note = '<div style="margin-top:18px;font-size:12px;color:#64748B;line-height:1.55">'
            . 'Please quote invoice <strong>' . $e($d['number']) . '</strong> with any payment. '
            . 'Keep this document for your records.</div>';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Invoice</title></head>'
            . '<body style="margin:0;padding:' . ($forPdf ? '0' : '28px') . ';background:' . ($forPdf ? '#fff' : $s['paper'])
            . ';font-family:' . $font . ';color:' . $ink . '">'
            . '<div style="max-width:' . $sheetW . 'px;margin:0 auto;background:#fff;'
            . 'padding:' . ($narrow ? '24px 22px' : '34px 38px') . ';'
            . ($forPdf ? '' : 'border:1px solid rgba(15,23,42,.08);border-radius:' . max($radius, 4) . 'px;')
            . '">'
            . $header . $meta . $table . $totalsBox . $stamp . $note
            . '<div style="margin-top:26px;padding-top:14px;border-top:1px solid #E5E7EB;font-size:11px;color:#94A3B8">'
            . $e($name) . ' · Invoice ' . $e($d['number']) . '</div>'
            . '</div></body></html>';
    }

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
            // keep the URL
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
