<?php

declare(strict_types=1);

namespace App\Support;

use setasign\Fpdi\Fpdi;

/**
 * Exact-fidelity PDF fill for the home-visitor inspection forms.
 *
 * Instead of re-drawing the layout, this imports the ACTUAL original PDF
 * (flattened to a static PDF 1.4 template) as the page background — so the
 * logos, tinted field boxes, field sizes, fonts and page layout are literally
 * the original document — and overlays the submitted answers at the true field
 * coordinates recorded in map_*.json (extracted from the original AcroForm
 * widgets). Coordinates are PDF points, top-left origin (same as FPDF's 'pt').
 */
final class HccFormPdf
{
    private static function paths(string $formType): array
    {
        $slug = $formType === 'monthly_monitoring' ? 'monthly' : 'quarterly';
        $dir = base_path('storage/app/hcc-templates');
        return [$dir . "/tmpl_{$slug}_14.pdf", $dir . "/map_{$slug}.json"];
    }

    public static function available(string $formType): bool
    {
        [$tpl, $map] = self::paths($formType);
        return is_file($tpl) && is_file($map);
    }

    public static function render(string $formType, array $answers): string
    {
        [$tplPath, $mapPath] = self::paths($formType);
        $map = json_decode((string) file_get_contents($mapPath), true)['map'] ?? [];

        $pdf = new Fpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $count = $pdf->setSourceFile($tplPath);

        for ($p = 0; $p < $count; $p++) {
            $tpl = $pdf->importPage($p + 1);
            $size = $pdf->getTemplateSize($tpl);
            // Pages may mix portrait/landscape (the Ministry checklist is landscape).
            $orient = ($size['width'] > $size['height']) ? 'L' : 'P';
            $pdf->AddPage($orient, [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl);
            foreach ($map as $key => $v) {
                if ((int) $v['p'] !== $p) {
                    continue;
                }
                self::drawField($pdf, $key, $v, $answers);
            }
        }
        return $pdf->Output('S');
    }

    private static function val(array $answers, string $key)
    {
        // Table cells are keyed "<blockId>__<row>__<colId>".
        if (substr_count($key, '__') === 2) {
            [$base, $r, $col] = explode('__', $key);
            $rows = $answers[$base] ?? null;
            if (is_array($rows) && isset($rows[(int) $r]) && is_array($rows[(int) $r])) {
                return $rows[(int) $r][$col] ?? '';
            }
            return '';
        }
        return $answers[$key] ?? '';
    }

    private static function drawField(Fpdi $pdf, string $key, array $v, array $answers): void
    {
        $t = $v['t'];
        if ($t === 'text') {
            $s = self::val($answers, $key);
            if (is_scalar($s) && trim((string) $s) !== '') {
                self::text($pdf, $v['r'], (string) $s);
            }
        } elseif ($t === 'yna') {
            $val = strtolower(trim((string) ($answers[$key] ?? '')));
            if (isset($v[$val])) {
                self::mark($pdf, $v[$val]);
            }
        } elseif ($t === 'yn') {
            $val = strtolower(trim((string) ($answers[$key] ?? '')));
            if (isset($v[$val])) {
                self::mark($pdf, $v[$val]);
            }
        } elseif ($t === 'check') {
            if (!empty($answers[$key])) {
                self::mark($pdf, $v['r']);
            }
        } elseif ($t === 'choice') {
            $val = trim((string) ($answers[$key] ?? ''));
            if ($val !== '' && isset($v['opts'][$val])) {
                self::mark($pdf, $v['opts'][$val]);
            }
        }
    }

    /** Draw a bold X centred in a checkbox rect [x0,y0,x1,y1]. */
    private static function mark(Fpdi $pdf, array $r): void
    {
        $pdf->SetLineWidth(0.9);
        $pdf->SetDrawColor(17, 24, 39);
        $in = 1.6;
        $pdf->Line($r[0] + $in, $r[1] + $in, $r[2] - $in, $r[3] - $in);
        $pdf->Line($r[0] + $in, $r[3] - $in, $r[2] - $in, $r[1] + $in);
    }

    /** Draw answer text within a field rect (single-line centred, or wrapped for tall cells). */
    private static function text(Fpdi $pdf, array $r, string $s): void
    {
        $w = $r[2] - $r[0];
        $h = $r[3] - $r[1];
        $s = self::latin1($s);
        $fs = (int) round(min(9.0, max(6.5, $h > 22 ? 8.0 : $h - 3.5)));
        $pdf->SetFont('Helvetica', '', $fs);
        $pdf->SetTextColor(15, 23, 42);
        if ($h > 22) {
            // Multi-line cell (comments / textareas): wrap inside the box.
            $pdf->SetXY($r[0] + 2, $r[1] + 1.5);
            $pdf->MultiCell($w - 4, $fs + 2.0, $s, 0, 'L');
        } else {
            $pdf->SetXY($r[0] + 2, $r[1]);
            $pdf->Cell($w - 4, $h, $s, 0, 0, 'L');
        }
    }

    private static function latin1(string $s): string
    {
        $out = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
        return $out !== false ? $out : $s;
    }
}
