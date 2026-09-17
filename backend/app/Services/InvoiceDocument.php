<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * WHICH INVOICE TEMPLATE THIS AGENCY USES (2026-09-17).
 *
 * There are two now — KiddieTrac's own white-label document, and a faithful rebuild of
 * iLearn's, added because an agency migrating from another system wants the invoice its
 * families already recognise. Anthony: "add this in the invoice template selector where
 * things can be configured if required."
 *
 * ONE PLACE DECIDES. Every caller that wants an invoice document asks here rather than
 * naming a renderer, so the choice is made once, from
 * `agencies.settings.invoice_template`, and a third template later is a line in TEMPLATES
 * rather than a hunt through the controllers.
 *
 * DEFAULT IS KIDDIETRAC. An agency that has never chosen keeps exactly the document it
 * has been sending; the setting has to be changed deliberately for anything to look
 * different.
 */
final class InvoiceDocument
{
    /** The two bespoke documents. The ten style variants are merged in below. */
    private const BESPOKE = [
        'kiddietrac' => [
            'label' => 'KiddieTrac (default)',
            'blurb' => 'The standard KiddieTrac invoice, branded with your logo, colour and support address.',
        ],
        'ilearn' => [
            'label' => 'iLearn',
            'blurb' => 'The iLearn invoice layout — purple header rule, Bill-to block, Interac reference reminder and record-keeping note. For agencies whose families already receive this document.',
        ],
    ];

    /**
     * The selector's options. Key = stored value, and the label is what the UI shows.
     *
     * A METHOD RATHER THAN A CONSTANT (2026-09-17): the ten styles live in
     * InvoiceStyleRenderer beside the specs that draw them, so adding an eleventh is one
     * entry there and nothing here. Callers already ask this class, so the change is
     * invisible to them.
     *
     * @return array<string,array{label:string,blurb:string}>
     */
    public static function templates(): array
    {
        $out = self::BESPOKE;
        foreach (InvoiceStyleRenderer::STYLES as $key => $spec) {
            $out[$key] = ['label' => $spec['label'], 'blurb' => $spec['blurb']];
        }

        return $out;
    }

    public static function templateFor(?int $agencyId): string
    {
        if (! $agencyId) {
            return 'kiddietrac';
        }
        try {
            $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $t = (string) ($s['invoice_template'] ?? 'kiddietrac');

            return array_key_exists($t, self::templates()) ? $t : 'kiddietrac';
        } catch (Throwable $e) {
            return 'kiddietrac';
        }
    }

    /** The agency an invoice belongs to, through its centre. */
    public static function agencyOfInvoice(int $invoiceId): ?int
    {
        $centreId = DB::table('invoices')->where('id', $invoiceId)->value('centre_id');
        if (! $centreId) {
            return null;
        }
        $a = DB::table('centres')->where('id', $centreId)->value('agency_id');

        return $a ? (int) $a : null;
    }

    /** The document as HTML, in whichever template the agency has chosen. */
    public static function html(int $invoiceId, bool $forPdf = true): ?string
    {
        $agencyId = self::agencyOfInvoice($invoiceId);

        $template = self::templateFor($agencyId);

        if ($template === 'ilearn') {
            return app(IlearnInvoiceRenderer::class)->renderFromInvoiceId($invoiceId, $forPdf);
        }
        if (array_key_exists($template, InvoiceStyleRenderer::STYLES)) {
            return app(InvoiceStyleRenderer::class)->renderFromInvoiceId($invoiceId, $template, $forPdf);
        }

        return app(InvoicePdfRenderer::class)->renderFromInvoiceId($invoiceId);
    }

    /**
     * The document as a REAL PDF.
     *
     * Named for what it returns, because the class it replaces at the call sites was
     * not: InvoicePdfRenderer returns HTML, and a controller attached that as
     * application/pdf for months. Anything that is not a PDF comes back null here, so a
     * caller cannot repeat the mistake.
     */
    public static function pdf(int $invoiceId): ?string
    {
        try {
            $html = self::html($invoiceId, true);
            if (! $html) {
                return null;
            }
            // Remote images enabled for the agency's own logo in the letterhead.
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('letter', 'portrait');
            $dompdf->render();
            $out = $dompdf->output();

            return (is_string($out) && str_starts_with($out, '%PDF')) ? $out : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
