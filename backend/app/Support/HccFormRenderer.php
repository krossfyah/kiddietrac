<?php

declare(strict_types=1);

namespace App\Support;

use Dompdf\Dompdf;

/**
 * Renders a completed home-visitor inspection form (schema + answers) to a
 * standalone, print-ready HTML document. Used for the downloadable PDF (dompdf)
 * and for a read-only web view. Layout mirrors the original paper forms:
 * section bars, Yes/No/N/A + Comments tables, checkbox grids, tables and
 * signature blocks. dompdf-safe (tables + inline styles only — no flex/grid).
 */
final class HccFormRenderer
{
    /** @var array<string,mixed> */
    private array $a;

    private function __construct(array $answers)
    {
        $this->a = $answers;
    }

    public static function html(array $schema, array $answers, array $meta = []): string
    {
        $schema = isset($schema['sections'][0]['blocks'][0]['_id']) ? $schema : HccFormSchemas::assignIds($schema);
        $r = new self($answers);
        return $r->doc($schema, $meta);
    }

    /** Render straight to PDF bytes, with a stamped "Page N of M" on every page. */
    public static function toPdf(array $schema, array $answers, array $meta = []): string
    {
        $html = self::html($schema, $answers, $meta);
        $pdf = new Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf->loadHtml($html);
        $pdf->setPaper('letter');
        $pdf->render();
        try {
            $canvas = $pdf->getCanvas();
            $font = $pdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
            $w = $canvas->get_width();
            $h = $canvas->get_height();
            // dompdf substitutes {PAGE_NUM}/{PAGE_COUNT}; CSS counter(pages) is unreliable here.
            $canvas->page_text($w - 120, $h - 40, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0.54, 0.58, 0.64]);
        } catch (\Throwable $e) { /* page number is cosmetic — never fail the PDF for it */ }
        return $pdf->output();
    }

    private function v(string $id): string
    {
        $x = $this->a[$id] ?? '';
        return is_scalar($x) ? (string) $x : '';
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** A Yes/No/N/A triple showing the selected value as a filled box. */
    private function yna(string $id): string
    {
        $val = strtolower($this->v($id));
        $cell = function (string $key, string $label) use ($val) {
            $on = $val === $key;
            $box = $on ? '&#9746;' : '&#9744;'; // ☒ / ☐
            $c = $on ? '#0B3B57' : '#6B7280';
            return '<td align="center" style="padding:2px 6px;font-size:12px;color:' . $c . ';white-space:nowrap;">' . $box . '</td>';
        };
        return $cell('yes', 'Yes') . $cell('no', 'No') . $cell('na', 'N/A');
    }

    private function box(bool $on, string $label): string
    {
        $b = $on ? '&#9746;' : '&#9744;';
        $c = $on ? '#0B3B57' : '#374151';
        return '<span style="color:' . $c . ';font-size:12px;">' . $b . '</span> <span style="color:#1f2937;">' . $this->e($label) . '</span>';
    }

    private function doc(array $schema, array $meta): string
    {
        $accent = $schema['accent'] ?? '#159FB4';
        $ministry = ($schema['brand'] ?? '') === 'ministry';
        $conf = $this->e($schema['confidential'] ?? '');

        // ── Running header / footer (repeat on every page in dompdf) ──
        $runHeader = '';
        if ($ministry) {
            $ont = \App\Support\HccFormAssets::ONTARIO_LOGO;
            $runHeader = '<div id="rh"><table style="width:100%;border-collapse:collapse;"><tr>'
                . '<td style="vertical-align:top;padding:0;"><div style="font-size:13px;font-weight:800;color:#111;">Standard Home Visitor Checklist</div>'
                . '<div style="font-size:10px;color:#222;">Ministry of Education</div>'
                . '<div style="font-size:10px;color:#222;">Child Care Quality Assurance and Licensing Branch</div></td>'
                . '<td align="right" style="vertical-align:top;padding:0;"><img src="' . $ont . '" style="height:34px;"></td>'
                . '</tr></table></div>';
        } else {
            // iLearn: the confidential line is the running header strip (small, top).
            $runHeader = '<div id="rh"><div style="font-size:9px;color:#94a3b8;">' . $conf . '</div></div>';
        }
        $footLeft = $ministry ? 'July 2021' : $conf;
        // Left label repeats via this fixed div; the "Page N of M" on the right is
        // stamped by toPdf() (dompdf's CSS counter(pages) resolves to 0 here).
        $runFooter = '<div id="rf"><div style="font-size:9px;color:#8a94a3;">' . $footLeft . '</div></div>';

        // ── Page-1 masthead (once, in the flow) ──
        $masthead = '';
        if (!$ministry) {
            $il = \App\Support\HccFormAssets::ILEARN_LOGO;
            $masthead = '<table style="width:100%;border-collapse:collapse;border-bottom:3px solid ' . $accent . ';margin:0 0 12px;"><tr>'
                . '<td style="width:210px;padding:0 14px 10px 0;vertical-align:middle;"><img src="' . $il . '" style="width:190px;"></td>'
                . '<td style="padding:0 0 10px;vertical-align:middle;">'
                . '<div style="font-size:19px;font-weight:800;color:#111;">' . $this->e($schema['title']) . '</div>'
                . '<div style="font-size:19px;font-weight:800;color:#111;">' . $this->e($schema['title2'] ?? '') . '</div></td>'
                . '</tr></table>';
        }

        $body = '';
        foreach ($schema['sections'] as $sec) {
            $body .= $this->section($sec, $accent, $ministry);
        }

        // Top margin reserves room for the running header; bottom for the footer.
        $topM = $ministry ? 96 : 46;
        $rhTop = $ministry ? -80 : -34;

        $css = 'body{font-family:DejaVu Sans,Arial,sans-serif;color:#1f2937;font-size:10.5px;line-height:1.4;margin:0;}'
            . '@page{margin:' . $topM . 'px 34px 54px 34px;}'
            . 'table{border-collapse:collapse;} .brdr td,.brdr th{border:1px solid #C7CDD4;}'
            . 'tr{page-break-inside:avoid;} .blk{page-break-inside:avoid;} .avoidbreak{page-break-inside:avoid;}'
            . '#rh{position:fixed;top:' . $rhTop . 'px;left:0;right:0;' . ($ministry ? 'border-bottom:2px solid #111;padding-bottom:5px;' : '') . '}'
            . '#rf{position:fixed;bottom:-40px;left:0;right:0;border-top:1px solid #ddd;padding-top:4px;}';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>'
            . $runHeader . $runFooter . $masthead . $body
            . '</body></html>';
    }

    private function bar(array $sec, string $accent, bool $ministry): string
    {
        if (($sec['bar'] ?? 'none') === 'none') {
            return !empty($sec['title']) ? '<div style="font-size:12px;font-weight:800;color:#111;margin:14px 0 6px;">' . $this->e($sec['title']) . '</div>' : '';
        }
        if ($sec['bar'] === 'cat') {
            // Ministry green category band
            return '<div style="background:#D9EAD3;border:1px solid #B7CCA9;font-weight:800;font-size:11.5px;color:#274E13;padding:5px 9px;margin:12px 0 0;">' . $this->e($sec['title']) . '</div>';
        }
        if ($sec['bar'] === 'catplain') {
            return '<div style="font-size:12px;font-weight:800;color:#111;letter-spacing:.4px;border-bottom:1px solid #111;padding-bottom:3px;margin:16px 0 8px;">' . $this->e($sec['title']) . '</div>';
        }
        // dark (monthly teal/charcoal bar)
        return '<div style="background:#2B2F36;color:#fff;font-weight:800;font-size:11.5px;padding:6px 10px;margin:16px 0 8px;border-left:5px solid ' . $accent . ';">' . $this->e($sec['title']) . '</div>';
    }

    private function section(array $sec, string $accent, bool $ministry): string
    {
        $out = $this->bar($sec, $accent, $ministry);
        foreach ($sec['blocks'] as $b) {
            $out .= $this->block($b, $sec);
        }
        return $out;
    }

    private function block(array $b, array $sec): string
    {
        switch ($b['type']) {
            case 'head':        return $this->head($b);
            case 'subhead':     return '<div style="background:#E9ECEF;font-weight:700;font-size:11px;color:#333;padding:4px 8px;margin:8px 0 4px;">' . $this->e($b['text']) . '</div>';
            case 'intro':       return '<div style="font-size:10.5px;color:#333;margin:6px 0;">' . $this->e($b['text']) . '</div>';
            case 'static':      return '<div style="font-size:10.5px;color:#333;">' . $b['html'] . '</div>';
            case 'checklist':   return $this->checklist($b);
            case 'checkgroup':  return $this->checkgroup($b);
            case 'textareas':   return $this->textareas($b);
            case 'table':       return $this->table($b);
            case 'yn':          return $this->yn($b);
            case 'sign':        return $this->sign($b);
        }
        return '';
    }

    private function head(array $b): string
    {
        $wmap = ['full' => 100, 'half' => 50, 'third' => 33, 'quarter' => 25];
        // Pack fields into rows so half-width fields pair up, matching the original.
        $rowsHtml = '';
        $acc = 0;
        $cells = '';
        $flush = function () use (&$rowsHtml, &$cells, &$acc) {
            if ($cells !== '') $rowsHtml .= '<tr>' . $cells . '</tr>';
            $cells = ''; $acc = 0;
        };
        foreach ($b['fields'] as $f) {
            $w = $wmap[$f['w'] ?? 'half'] ?? 50;
            if ($acc + $w > 100 && $cells !== '') $flush();
            $cells .= '<td style="width:' . $w . '%;padding:4px 10px 6px 0;vertical-align:top;">'
                . '<span style="font-weight:700;color:#222;font-size:10.5px;">' . $this->e($f['label']) . ':</span> '
                . '<span style="display:inline-block;min-width:60px;border:1px solid #C7CDD4;background:#fff;padding:2px 6px;font-size:10.5px;">' . ($this->e($this->v($f['id'])) ?: '&nbsp;') . '</span></td>';
            $acc += $w;
            if ($acc >= 100) $flush();
        }
        $flush();
        return '<table style="width:100%;margin:4px 0 8px;">' . $rowsHtml . '</table>';
    }

    private function checklist(array $b): string
    {
        $ministry = !empty($b['ministry']);
        $head = '<tr style="background:#E9ECEF;">';
        if ($ministry) {
            $head .= '<th style="width:26px;padding:4px;font-size:9.5px;">Q#</th><th style="width:78px;padding:4px;font-size:9.5px;">Reference</th><th style="width:52px;padding:4px;font-size:9.5px;">Risk</th><th style="padding:4px;font-size:9.5px;text-align:left;">Description of Requirement</th>';
        } else {
            $head .= '<th style="padding:4px;font-size:9.5px;text-align:left;">Item</th>';
        }
        $head .= '<th style="width:24px;padding:4px;font-size:9.5px;">Yes</th><th style="width:24px;padding:4px;font-size:9.5px;">No</th><th style="width:26px;padding:4px;font-size:9.5px;">N/A</th><th style="width:150px;padding:4px;font-size:9.5px;">Comments</th></tr>';

        $body = '';
        foreach ($b['items'] as $it) {
            $desc = '<div>' . $this->e($it['desc']) . '</div>';
            if (!empty($it['bullets'])) {
                $desc .= '<ul style="margin:3px 0 0 14px;padding:0;">';
                foreach ($it['bullets'] as $bl) $desc .= '<li>' . $this->e($bl) . '</li>';
                $desc .= '</ul>';
            }
            if (!empty($it['note2'])) {
                $desc .= '<div style="font-size:9.5px;color:#555;margin-top:3px;">' . $this->e($it['note2']) . '</div>';
            }
            $ref = '';
            if ($ministry) {
                $ref = $this->e($it['ref'] ?? '');
                if (!empty($it['note'])) $ref .= '<div style="font-size:8.5px;color:#666;font-style:italic;">' . $this->e($it['note']) . '</div>';
            }
            // Comments cell content (comment text + any prompt inputs)
            $cc = nl2br($this->e($this->v($it['_cid'] ?? '')));
            if (!empty($it['_pids'])) {
                foreach ($it['prompts'] as $pi => $plabel) {
                    $cc .= '<div style="margin-top:3px;"><span style="color:#555;">' . $this->e($plabel) . '</span> ' . $this->e($this->v($it['_pids'][$pi])) . '</div>';
                }
            }

            if (!empty($it['_sids'])) {
                // Multi-sub item: description + first sub on the lead row, remaining subs each on their own row.
                $nSubs = count($it['_sids']);
                $body .= '<tr>';
                if ($ministry) {
                    $body .= '<td style="padding:5px;vertical-align:top;font-weight:700;">' . $this->e($it['n'] ?? '') . '.</td>'
                        . '<td style="padding:5px;vertical-align:top;font-size:9.5px;">' . $ref . '</td>'
                        . '<td style="padding:5px;vertical-align:top;font-size:9.5px;">' . $this->e($it['risk'] ?? '') . '</td>';
                }
                $body .= '<td style="padding:5px;vertical-align:top;">' . $desc
                    . '<div style="margin-top:4px;">- ' . $this->e($it['subs'][0]) . '</div></td>'
                    . $this->yna($it['_sids'][0])
                    . '<td rowspan="' . $nSubs . '" style="padding:5px;vertical-align:top;font-size:10px;">' . $cc . '</td></tr>';
                for ($si = 1; $si < $nSubs; $si++) {
                    $body .= '<tr>';
                    if ($ministry) $body .= '<td></td><td></td><td></td>';
                    $body .= '<td style="padding:5px;vertical-align:top;">- ' . $this->e($it['subs'][$si]) . '</td>' . $this->yna($it['_sids'][$si]) . '</tr>';
                }
            } else {
                $body .= '<tr>';
                if ($ministry) {
                    $body .= '<td style="padding:5px;vertical-align:top;font-weight:700;">' . $this->e($it['n'] ?? '') . '.</td>'
                        . '<td style="padding:5px;vertical-align:top;font-size:9.5px;">' . $ref . '</td>'
                        . '<td style="padding:5px;vertical-align:top;font-size:9.5px;">' . $this->e($it['risk'] ?? '') . '</td>';
                }
                $body .= '<td style="padding:5px;vertical-align:top;">' . $desc . '</td>' . $this->yna($it['_vid'] ?? '')
                    . '<td style="padding:5px;vertical-align:top;font-size:10px;">' . $cc . '</td></tr>';
            }
        }
        return '<table class="brdr" style="width:100%;margin:4px 0 8px;font-size:10.5px;">' . $head . $body . '</table>';
    }

    private function checkgroup(array $b): string
    {
        $out = '';
        if (!empty($b['intro'])) $out .= '<div style="font-weight:700;font-size:10.5px;color:#333;margin:6px 0 4px;">' . $this->e($b['intro']) . '</div>';
        $cols = (int) ($b['cols'] ?? 2);
        $w = (int) floor(100 / $cols);
        $rows = '';
        foreach (array_chunk($b['boxes'], $cols, true) as $chunk) {
            $rows .= '<tr>';
            foreach ($chunk as $i => $label) {
                $on = (bool) $this->v($b['_bids'][$i]);
                $rows .= '<td style="width:' . $w . '%;padding:3px 8px;">' . $this->box($on, $label) . '</td>';
            }
            $rows .= '</tr>';
        }
        $out .= '<table style="width:100%;margin:2px 0 6px;">' . $rows . '</table>';
        if (!empty($b['_otherId'])) {
            $out .= '<div style="font-size:10.5px;margin:2px 0 6px;">Other: <span style="border-bottom:1px solid #C7CDD4;">' . $this->e($this->v($b['_otherId'])) . '</span></div>';
        }
        return $out;
    }

    private function textareas(array $b): string
    {
        $out = '';
        foreach ($b['items'] as $it) {
            $val = nl2br($this->e($this->v($it['id'])));
            $out .= '<div style="font-weight:700;font-size:10.5px;color:#333;margin:8px 0 3px;">' . $this->e($it['label']) . '</div>'
                . '<div style="border:1px solid #C7CDD4;border-radius:4px;padding:7px 9px;min-height:' . (int) (($it['rows'] ?? 3) * 14) . 'px;font-size:10.5px;">' . $val . '</div>';
        }
        return $out;
    }

    private function table(array $b): string
    {
        $out = '';
        if (!empty($b['intro'])) $out .= '<div style="font-size:10.5px;color:#333;margin:6px 0 4px;">' . $this->e($b['intro']) . '</div>';
        $head = '<tr style="background:#E9ECEF;">';
        foreach ($b['columns'] as $c) $head .= '<th style="padding:4px;font-size:9.5px;">' . $this->e($c['label']) . '</th>';
        $head .= '</tr>';
        if (!empty($b['note'])) {
            $head .= '<tr><td colspan="' . count($b['columns']) . '" style="padding:3px 6px;font-size:9px;color:#555;background:#F6F7F8;">' . $this->e($b['note']) . '</td></tr>';
        }
        $rowsData = $this->a[$b['_id']] ?? [];
        if (!is_array($rowsData)) $rowsData = [];
        $body = '';
        $n = max((int) ($b['rows'] ?? 3), count($rowsData));
        for ($i = 0; $i < $n; $i++) {
            $row = $rowsData[$i] ?? [];
            $body .= '<tr>';
            foreach ($b['columns'] as $c) {
                $cellVal = is_array($row) ? ($row[$c['id']] ?? '') : '';
                $body .= '<td style="padding:5px;height:18px;font-size:10px;">' . $this->e(is_scalar($cellVal) ? (string) $cellVal : '') . '</td>';
            }
            $body .= '</tr>';
        }
        $out .= '<table class="brdr" style="width:100%;margin:4px 0 6px;">' . $head . $body . '</table>';
        if (!empty($b['comments'])) {
            $out .= '<div style="font-weight:700;font-size:10.5px;color:#333;margin:6px 0 3px;">' . $this->e($b['comments']['label']) . '</div>'
                . '<div style="border:1px solid #C7CDD4;border-radius:4px;padding:7px 9px;min-height:42px;font-size:10.5px;">' . nl2br($this->e($this->v($b['comments']['id']))) . '</div>';
        }
        return $out;
    }

    private function yn(array $b): string
    {
        $rows = '';
        foreach ($b['items'] as $it) {
            $val = strtolower($this->v($it['id']));
            $y = $val === 'yes' ? '&#9746;' : '&#9744;';
            $n = $val === 'no' ? '&#9746;' : '&#9744;';
            $rows .= '<tr><td style="padding:4px 12px 4px 0;font-size:10.5px;">' . $this->e($it['label']) . '</td>'
                . '<td style="padding:4px 8px;font-size:11px;">' . $y . ' Yes</td>'
                . '<td style="padding:4px 8px;font-size:11px;">' . $n . ' No</td></tr>';
        }
        return '<table style="margin:4px 0 6px;">' . $rows . '</table>';
    }

    private function sign(array $b): string
    {
        $out = '';
        if (!empty($b['statements'])) {
            $out .= '<table class="brdr" style="width:100%;margin:6px 0;"><tr>';
            foreach ($b['statements'] as $s) {
                $out .= '<td style="width:50%;padding:7px;font-size:9.5px;font-weight:700;vertical-align:top;">' . $this->e($s) . '</td>';
            }
            $out .= '</tr></table>';
        }
        $rows = '';
        foreach ($b['columns'] as $c) {
            $rows .= '<tr><td style="padding:6px 10px 6px 0;font-weight:700;font-size:10.5px;white-space:nowrap;vertical-align:bottom;">' . $this->e($c['label']) . ':</td>'
                . '<td style="padding:6px 0;border-bottom:1px solid #333;font-size:10.5px;">' . $this->e($this->v($c['id'])) . '</td></tr>';
        }
        $out .= '<table style="width:100%;margin:4px 0;">' . $rows . '</table>';
        return $out;
    }
}
