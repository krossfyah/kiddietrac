<?php

declare(strict_types=1);

namespace App\Support;

use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Add the agency's counter-signature to a form the parent already completed.
 *
 * WHY A NEW PAGE RATHER THAN A STAMP ON THE LAST ONE. The parent's PDF is whatever their
 * browser produced from whatever the agency uploaded — the layout is not ours and its
 * last page may be full, or a table, or a signature block of its own. Writing into it
 * means guessing where there is room, and guessing wrong means a counter-signature across
 * somebody's medical notes. A clean final page always has room and always reads as what
 * it is: a separate act, by a separate party, at a separate time.
 *
 * THE VERSION PROBLEM, AND THE FIX THAT ALREADY EXISTS HERE. The free FPDI can only
 * import PDF ≤ 1.4, and every filled form on this platform is **1.7** (checked: all four
 * sampled). Ghostscript rewrites a copy at 1.4 — the same trick the HCC form templates
 * use — and FPDI then imports it happily. gs 9.27 is at /usr/bin/gs.
 *
 * EVERY FAILURE RETURNS NULL. A counter-signature that cannot be stamped must not lose
 * the counter-signature: the caller records it in the database either way and simply
 * sends the original alongside a plain-text account of who signed it and when. A missing
 * PDF is a worse outcome than a missing page in one.
 */
final class ManagedFormCountersign
{
    /** Ghostscript is not on every host; absence must degrade, not explode. */
    private const GS = '/usr/bin/gs';

    /**
     * @param  string  $filledAbs   the parent's completed PDF, on disk
     * @param  string  $sigDataUrl  the counter-signer's signature, a base64 PNG data URL
     * @return string|null          absolute path of the new PDF, or null if it could not be made
     */
    public static function append(
        string $filledAbs,
        string $formTitle,
        string $signerName,
        ?string $signerSignedAt,
        string $counterName,
        string $counterRole,
        string $sigDataUrl,
        ?string $note,
        string $agencyName,
        ?array $annotations = null
    ): ?string {
        if (! is_file($filledAbs)) {
            return null;
        }

        $tmpDir = sys_get_temp_dir();
        $flat = $tmpDir . '/cs-' . bin2hex(random_bytes(6)) . '.pdf';
        $sigPng = null;
        $out = null;

        try {
            /* 1.4 or FPDI will refuse it. -dPDFSETTINGS is left alone on purpose: this is
               a fidelity-preserving rewrite, not a compression pass. */
            $p = new Process([
                self::GS, '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER',
                '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.4',
                '-sOutputFile=' . $flat, $filledAbs,
            ], null, null, null, 120);
            $p->run();

            if (! is_file($flat) || filesize($flat) < 500) {
                return null;
            }

            $sigPng = self::signatureToFile($sigDataUrl, $tmpDir);

            $pdf = new Fpdi('P', 'pt');
            $pdf->SetAutoPageBreak(false);

            /* EVERY page of the original, at ITS OWN size and orientation. A form with a
               landscape page cropped to portrait is the sort of damage nobody notices
               until an inspector asks for it. */
            $count = $pdf->setSourceFile($flat);
            $stampedInline = false;
            for ($i = 1; $i <= $count; $i++) {
                $tpl = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);
                $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);

                /* ON THE PAGE THE PARENT SIGNED, WHERE THERE IS ROOM FOR IT.

                   Anthony, 2026-09-22: the counter-signature belongs beside the
                   signature it answers, not on a page of its own.

                   The reason it was a separate page is still true though - this PDF's
                   layout is not ours, and writing into a fixed spot on somebody else's
                   form means printing over their medical notes when the guess is wrong.
                   So the space is MEASURED rather than assumed: the last page is
                   rendered and scanned from the bottom up for a band of clear white. If
                   there is room the block goes there; if there is not, it falls back to
                   its own page rather than overprinting the document. */
                if ($i === $count) {
                    $free = self::blankBandAtBottom($flat, $i, (float) $size['height'], (float) $size['width']);
                    if ($free >= self::INLINE_BLOCK_H) {
                        self::inlineBlock($pdf, (float) $size['height'] - $free + 6,
                            (float) $size['width'], $counterName, $counterRole, $sigPng, $note, $agencyName);
                        $stampedInline = true;
                    }
                }
            }

            /* The extra page is still added when the block could not fit, and when there
               are changes to list - a record of what the agency altered does not belong
               squeezed into a margin. */
            if (! $stampedInline || (is_array($annotations) && $annotations !== [])) {
                self::certificatePage($pdf, $formTitle, $signerName, $signerSignedAt,
                    $counterName, $counterRole, $sigPng, $note, $agencyName, $annotations);
            }

            $out = $tmpDir . '/cs-out-' . bin2hex(random_bytes(6)) . '.pdf';
            $pdf->Output('F', $out);

            return is_file($out) && filesize($out) > 500 ? $out : null;
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('countersign stamp failed', ['err' => $e->getMessage()]);

            return null;
        } finally {
            /* The downgraded copy and the decoded signature are scratch. Leaving them
               behind would slowly fill /tmp with other people's signatures. */
            if (is_file($flat)) { @unlink($flat); }
            if ($sigPng && is_file($sigPng)) { @unlink($sigPng); }
        }
    }

    /** Height the inline block needs, in points. */
    private const INLINE_BLOCK_H = 104.0;

    /**
     * How many points of clear white sit at the bottom of a page.
     *
     * Rendered at 72dpi (1px = 1pt, so no scaling arithmetic to get wrong) in greyscale,
     * then scanned upward from the last row until a row contains a pixel dark enough to
     * be ink. Anti-aliasing puts near-white greys around real content, so the threshold
     * is deliberately loose - a faint scanner background should not read as "occupied"
     * and push the block onto its own page every time.
     *
     * Returns 0 on any failure, which makes the caller fall back to a separate page. A
     * wrong answer here prints over somebody's form, so silence has to mean "don't".
     */
    private static function blankBandAtBottom(string $pdfPath, int $pageNo, float $pageH, float $pageW): float
    {
        $png = sys_get_temp_dir() . '/cs-scan-' . bin2hex(random_bytes(5)) . '.png';
        try {
            $p = new Process([
                self::GS, '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER',
                '-sDEVICE=pnggray', '-r72',
                '-dFirstPage=' . $pageNo, '-dLastPage=' . $pageNo,
                '-sOutputFile=' . $png, $pdfPath,
            ], null, null, null, 60);
            $p->run();
            if (! is_file($png) || ! function_exists('imagecreatefrompng')) {
                return 0.0;
            }
            $im = @imagecreatefrompng($png);
            if (! $im) {
                return 0.0;
            }
            $w = imagesx($im);
            $h = imagesy($im);

            /* Ignore a thin margin each side: a page border or a scan edge runs the full
               height and would report every page as full. */
            $x0 = (int) max(1, $w * 0.04);
            $x1 = (int) min($w - 2, $w * 0.96);
            $step = max(1, (int) floor(($x1 - $x0) / 160));   // sample, don't read every pixel

            $blankRows = 0;
            for ($y = $h - 1; $y >= 0; $y--) {
                $ink = false;
                for ($x = $x0; $x <= $x1; $x += $step) {
                    if ((imagecolorat($im, $x, $y) & 0xFF) < 200) { $ink = true; break; }
                }
                if ($ink) { break; }
                $blankRows++;
            }
            imagedestroy($im);

            /* The render is 1px per point only if the page really is $pageH tall; scale
               back through the actual bitmap height so a non-A4 page is not misjudged. */
            return $h > 0 ? ($blankRows * ($pageH / $h)) : 0.0;
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('countersign: page scan failed', ['err' => $e->getMessage()]);

            return 0.0;
        } finally {
            if (is_file($png)) { @unlink($png); }
        }
    }

    /** The compact block that sits under whatever the signer put on the page. */
    private static function inlineBlock(Fpdi $pdf, float $top, float $pageW,
        string $counterName, string $counterRole, ?string $sigPng, ?string $note, string $agencyName): void
    {
        $m = 56;
        $w = $pageW - ($m * 2);

        $pdf->SetDrawColor(180, 190, 200);
        $pdf->Line($m, $top, $m + $w, $top);

        $pdf->SetXY($m, $top + 6);
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetTextColor(90, 100, 115);
        $pdf->Cell($w, 11, self::t('COUNTER-SIGNED BY ' . mb_strtoupper($agencyName)), 0, 1);

        $y = $top + 19;
        $drew = false;
        if ($sigPng) {
            try {
                /* Same degrade-do-not-throw rule as the certificate page: a picture must
                   not cost the document. */
                $pdf->Image($sigPng, $m, $y, 0, 34, 'PNG');
                $drew = true;
            } catch (Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('countersign: inline signature unusable', ['err' => $e->getMessage()]);
            }
        }
        if (! $drew) {
            $pdf->SetXY($m, $y + 10);
            $pdf->SetFont('Helvetica', 'I', 8);
            $pdf->SetTextColor(150, 160, 175);
            $pdf->Cell(200, 12, self::t('(signature image unavailable)'), 0, 1);
        }

        $pdf->SetDrawColor(200, 208, 216);
        $pdf->Line($m, $y + 38, $m + 230, $y + 38);

        $pdf->SetXY($m, $y + 40);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(230, 12, self::t(trim($counterName . ($counterRole ? ' (' . $counterRole . ')' : ''))), 0, 1);

        $pdf->SetX($m);
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->SetTextColor(90, 100, 115);
        $pdf->Cell(230, 11, self::t('Counter-signed ' . self::stamp(now()->toDateTimeString())), 0, 1);

        /* A short note rides along; a long one would not fit the band that was measured,
           and silently clipping a reviewer's words is worse than putting them overleaf. */
        if ($note !== null && trim($note) !== '' && mb_strlen(trim($note)) <= 110) {
            $pdf->SetXY($m + 250, $y + 40);
            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->MultiCell($w - 250, 11, self::t(trim($note)), 0, 'L');
        }
        $pdf->SetTextColor(15, 23, 42);
    }

    /** The counter-signature page itself. Plain, dated, and legible without the app. */
    private static function certificatePage(
        Fpdi $pdf, string $formTitle, string $signerName, ?string $signerSignedAt,
        string $counterName, string $counterRole, ?string $sigPng, ?string $note, string $agencyName,
        ?array $annotations = null
    ): void {
        $pdf->AddPage('P', [595.28, 841.89]);   // A4 in points
        $m = 56;
        $w = 595.28 - ($m * 2);

        $pdf->SetFont('Helvetica', 'B', 15);
        $pdf->SetXY($m, 64);
        $pdf->Cell($w, 20, self::t('Counter-signature'), 0, 1);

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(90, 100, 115);
        $pdf->SetX($m);
        $pdf->MultiCell($w, 14, self::t(
        /* THIS SENTENCE HAS TO TELL THE TRUTH.

           It used to promise the document was unaltered, which held while the only
           addition was this page. Once the agency can write onto the form during review
           that promise is false, and a certificate that misdescribes its own document is
           worse than no certificate. Say which it is. */
            'This page records the review by ' . $agencyName . ' of the completed form on the '
            . 'preceding pages. '
            . (is_array($annotations) && $annotations !== []
                ? 'During that review the agency changed or completed information on the form. '
                  . 'Every such change is listed below. Everything else the signer entered, and '
                  . 'their signature, are unchanged.'
                : 'Nothing the signer submitted has been altered.')
        ), 0, 'L');

        $pdf->Ln(14);
        $pdf->SetTextColor(15, 23, 42);

        $line = function (string $k, string $v) use ($pdf, $m, $w) {
            $pdf->SetX($m);
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->Cell(130, 18, self::t($k), 0, 0);
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->MultiCell($w - 130, 18, self::t($v), 0, 'L');
        };

        $line('Form', $formTitle);
        $line('Completed by', $signerName !== '' ? $signerName : 'Not recorded');
        $line('Completed on', $signerSignedAt ? self::stamp($signerSignedAt) : 'Not recorded');
        $line('Reviewed by', trim($counterName . ($counterRole ? ' (' . $counterRole . ')' : '')));
        $line('Reviewed on', self::stamp(now()->toDateTimeString()));

        /* WHAT THE AGENCY WROTE ONTO THE FORM, LISTED IN FULL.
 
           The additions are visible on the pages themselves, but only in place - a
           reader checking whether the document was altered after signing should not have
           to hunt through every page to find out. Listing them here, beside who added
           them and when, makes the change part of the record rather than something
           discovered later. */
        if (is_array($annotations) && $annotations !== []) {
            $pdf->Ln(6);
            $pdf->SetX($m);
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->Cell($w, 16, self::t('Changes made to the form during review'), 0, 1);
            $pdf->SetFont('Helvetica', '', 10);
            foreach ($annotations as $a) {
                $pdf->SetX($m);
                $pdf->MultiCell($w, 14, self::t('Page ' . (int) ($a['page'] ?? 0) . ': ' . (string) ($a['text'] ?? '')), 0, 'L');
            }
        }

        if ($note !== null && trim($note) !== '') {
            $pdf->Ln(6);
            $pdf->SetX($m);
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->Cell($w, 16, self::t('Note from the reviewer'), 0, 1);
            $pdf->SetX($m);
            $pdf->SetFont('Helvetica', '', 10);
            $pdf->MultiCell($w, 14, self::t($note), 0, 'L');
        }

        $pdf->Ln(22);
        $pdf->SetX($m);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell($w, 16, self::t('Signature'), 0, 1);

        $y = $pdf->GetY() + 4;
        $drew = false;
        if ($sigPng) {
            try {
                /* Fitted inside a fixed box rather than scaled to a width: a wide scrawl
                   and a tall one must not produce wildly different page layouts. */
                $pdf->Image($sigPng, $m, $y, 0, 46, 'PNG');
                $drew = true;
            } catch (Throwable $e) {
                /* A SIGNATURE IMAGE MUST NOT COST THE WHOLE DOCUMENT.

                   FPDF throws on anything it cannot parse ("Unexpected end of stream"),
                   and a canvas can hand us a truncated or odd PNG for reasons the signer
                   will never know about. Losing the entire counter-signed PDF over a
                   picture would be the wrong trade: the names, the dates and the note are
                   the record, and they still print. */
                \Illuminate\Support\Facades\Log::warning('countersign: signature image unusable', [
                    'err' => $e->getMessage(),
                ]);
            }
        }
        if (! $drew) {
            $pdf->SetFont('Helvetica', 'I', 9);
            $pdf->SetTextColor(150, 160, 175);
            $pdf->SetXY($m, $y + 16);
            $pdf->Cell(220, 14, self::t('(signature image unavailable)'), 0, 1);
            $pdf->SetTextColor(15, 23, 42);
        }

        $pdf->SetDrawColor(180, 190, 200);
        $pdf->Line($m, $y + 52, $m + 240, $y + 52);
        $pdf->SetXY($m, $y + 54);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(90, 100, 115);
        $pdf->Cell(240, 12, self::t($counterName), 0, 1);
    }

    private static function signatureToFile(string $dataUrl, string $dir): ?string
    {
        if (! preg_match('#^data:image/(png|jpe?g);base64,#i', $dataUrl, $m)) {
            return null;
        }
        $bin = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
        if ($bin === false || strlen($bin) < 64) {
            return null;
        }
        $ext = stripos($m[1], 'png') === 0 ? 'png' : 'jpg';
        $path = $dir . '/cs-sig-' . bin2hex(random_bytes(6)) . '.' . $ext;

        return file_put_contents($path, $bin) ? $path : null;
    }

    /** In the AGENCY's day, not the server's UTC one. */
    private static function stamp(string $ts): string
    {
        try {
            return \Illuminate\Support\Carbon::parse($ts)
                ->setTimezone(config('app.display_timezone', 'America/Toronto'))
                ->format('j M Y, g:ia');
        } catch (Throwable $e) {
            return $ts;
        }
    }

    /**
     * FPDF core fonts are Latin-1 only. An unconverted curly quote or accented name comes
     * out as mojibake in the finished record, which is worse than a plain substitute.
     */
    private static function t(string $s): string
    {
        $s = strtr($s, [
            "\u{2019}" => "'", "\u{2018}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2014}" => '-', "\u{2013}" => '-', "\u{2026}" => '...', "\u{00A0}" => ' ',
        ]);

        return (string) @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
    }
}
