<?php

namespace App\Support\Pdf;

/**
 * Lock a Dompdf document with a password, AES-256 (2026-11-27). Two calls:
 *   new KtDompdf(PdfSeal::options() + [...your options]);   // before anything is drawn
 *   ...render()...; PdfSeal::seal($pdf, $password);         // before output()
 * The person opens it with the password; print and copy are allowed; the owner password is random and thrown away, so
 * nobody can lift the lock or change the document. Fails closed: if the writer is not the AES one, seal() throws rather
 * than hand out an unlocked file.
 */
final class PdfSeal
{
    public static function options(): array
    {
        class_exists(AesCpdfAdapter::class);

        return ['pdfBackend' => AesCpdfAdapter::class];
    }

    public static function seal(\Dompdf\Dompdf $pdf, string $password): void
    {
        $canvas = $pdf->getCanvas();
        if ($password === '' || ! $canvas instanceof AesCpdfAdapter) {
            throw new \RuntimeException('The PDF could not be password-protected.');
        }
        $canvas->get_cpdf()->setEncryption($password, bin2hex(random_bytes(24)), ['print', 'copy']);
    }

    /** True when $bytes is a PDF locked by seal() (AES-256, revision 6). */
    public static function isSealed(string $bytes): bool
    {
        return str_starts_with($bytes, '%PDF') && preg_match('#/Filter /Standard\s*/V 5\s*/R 6#', $bytes) === 1 && str_contains($bytes, '/Encrypt ');
    }
}
