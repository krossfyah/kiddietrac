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
    /* A PAID INVOICE SAYS SO ACROSS THE PAGE (2026-09-17).

       Anthony: "resend the invoice as paid (water marked diagonal) with the receipt."

       A settled invoice and an outstanding one were the same piece of paper apart from a
       balance line, which is easy to miss on a forwarded PDF and is exactly what somebody
       squints at when they are not sure whether they still owe money. The watermark
       answers that from across the room.

       Applied HERE rather than in each renderer: there are twelve of them (iLearn, the
       ten styles, the default) and a watermark added to one is a watermark missing from
       eleven. Wrapping the finished document keeps them all in step.

       ONLY WHEN IT IS ACTUALLY SETTLED - balance at or below zero with money against it.
       A $0 invoice nobody has paid is not "PAID"; it is an invoice for nothing. */
    public static function html(int $invoiceId, bool $forPdf = true): ?string
    {
        $doc = self::render($invoiceId, $forPdf);

        return $doc === null ? null : self::withPaidMark($invoiceId, self::withPayInfo($invoiceId, $doc));
    }

    /* HOW TO PAY + THE PARENT APP ON EVERY TEMPLATE (2026-10-31, Anthony). Added here, like the PAID mark, so the
       twelve renderers stay in step: App\Support\InvoicePayInfo decides what is offered and writes it. */
    private static function withPayInfo(int $invoiceId, string $html): string
    {
        try {
            $i = \Illuminate\Support\Facades\DB::table('invoices')->where('id', $invoiceId)->first(['invoice_number', 'family_id', 'balance_due', 'status']);
            if (! $i || (string) $i->status === 'void') {
                return $html;
            }
            $block = \App\Support\InvoicePayInfo::pdfHtml(self::agencyOfInvoice($invoiceId), (string) ($i->invoice_number ?? ''),
                $i->family_id ? (int) $i->family_id : null, null, (float) ($i->balance_due ?? 0));
            if ($block === '') {
                return $html;
            }
            /* Inside the page card where the template has one: before the iLearn footer line, else before the card's
               closing tag, else before </body>. */
            foreach (['<div class="foot">', '</div></body>', '</body>'] as $mark) {
                $pos = strripos($html, $mark);
                if ($pos !== false) {
                    return substr($html, 0, $pos) . $block . substr($html, $pos);
                }
            }

            return $html . $block;
        } catch (Throwable $e) {
            report($e);

            return $html;
        }
    }

    /** True once the balance is cleared and something was actually paid. */
    public static function isSettled(int $invoiceId): bool
    {
        $i = \Illuminate\Support\Facades\DB::table('invoices')->where('id', $invoiceId)
            ->first(['status', 'balance_due', 'amount_paid']);
        if (! $i) { return false; }
        if ((string) $i->status === 'void') { return false; }

        $paid = (float) ($i->amount_paid ?? 0);
        $balance = (float) ($i->balance_due ?? 0);

        return $paid > 0.005 && $balance <= 0.005;
    }

    /**
     * Stamp a finished invoice document with a diagonal PAID mark.
     *
     * `position:fixed` so dompdf repeats it on every page, and a rotate transform for the
     * diagonal. Behind the content in z-order and heavily transparent, because a
     * watermark that obscures the figures it is stamped on defeats the document.
     */
    private static function withPaidMark(int $invoiceId, string $html): string
    {
        if (! self::isSettled($invoiceId)) {
            return $html;
        }
        $paidOn = \Illuminate\Support\Facades\DB::table('payments')->where('invoice_id', $invoiceId)
            ->where('status', 'succeeded')->max('paid_at');

        return self::paidStamp($html, $paidOn ? substr((string) $paidOn, 0, 10) : null);
    }

    /**
     * The diagonal PAID stamp, with the date it was paid (2026-10-31: "invoices with watermark PAID"). Public so the
     * split guardian copy and the iLearn-synced invoices get exactly the same mark.
     */
    public static function paidStamp(string $html, ?string $paidOn = null): string
    {
        $date = '';
        if ($paidOn) {
            try { $date = \Illuminate\Support\Carbon::parse($paidOn)->format('M j, Y'); } catch (Throwable $e) { $date = ''; }
        }
        $mark = '<div style="position:fixed;top:36%;left:0;width:100%;text-align:center;'
            . 'z-index:0;transform:rotate(-28deg);-webkit-transform:rotate(-28deg);'
            . 'pointer-events:none;">'
            . '<div style="display:inline-block;color:#16A34A;opacity:.22;border:7px solid #16A34A;border-radius:18px;'
            . 'padding:10px 42px;text-align:center;">'
            . '<div style="font-size:96px;font-weight:800;letter-spacing:14px;line-height:1;">PAID</div>'
            . ($date !== '' ? '<div style="font-size:22px;font-weight:700;letter-spacing:3px;margin-top:6px;">' . e($date) . '</div>' : '')
            . '</div></div>';

        /* Injected after <body> where there is one, so it sits in the page rather than
           before the document starts; appended otherwise, which still renders because it
           is fixed-position. */
        $pos = stripos($html, '<body');
        if ($pos !== false) {
            $end = strpos($html, '>', $pos);
            if ($end !== false) {
                return substr($html, 0, $end + 1) . $mark . substr($html, $end + 1);
            }
        }

        return $html . $mark;
    }

    /** The document itself, before anything is stamped on it. */
    private static function render(int $invoiceId, bool $forPdf): ?string
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
        /* A parent's document in the parent's language (2026-10-22): when it is made to be
           SENT (an email, the billing run), the family's guardians' language; when it is
           viewed, the viewer's (KtDompdf / MessageTranslator::pdfLocale). */
        return \App\Support\MessageTranslator::forDocument(
            (int) (\Illuminate\Support\Facades\DB::table('invoices')->where('id', $invoiceId)->value('family_id') ?: 0),
            fn () => self::renderPdf($invoiceId)
        );
    }

    private static function renderPdf(int $invoiceId): ?string
    {
        try {
            $html = self::html($invoiceId, true);
            if (! $html) {
                return null;
            }
            // Remote images enabled for the agency's own logo in the letterhead.
            $dompdf = new \App\Support\KtDompdf(['isRemoteEnabled' => true]);
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
