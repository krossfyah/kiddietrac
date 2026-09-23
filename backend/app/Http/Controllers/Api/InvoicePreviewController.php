<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Services\InvoicePdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * v15: Invoice preview endpoints.
 *
 * These exist so you can SEE the white-label rendering without
 * having to wire InvoicePdfRenderer into your existing invoice flow.
 *
 *   GET /api/v1/invoices/{id}/preview      — render existing invoice
 *   GET /api/v1/invoices/preview-sample    — render a fake invoice with
 *                                            optional ?agency_id= to test
 *                                            different agency brands
 *
 * Both return text/html so you can open the URL directly in a browser
 * tab. Use the browser's print → save as PDF for now; we'll wire a
 * real PDF library in a later release if you decide you need one.
 */
final class InvoicePreviewController extends Controller
{
    use ResolvesCentreContext;

    public function previewExisting(Request $request, int $id): Response
    {
        $user = $request->user();
        if (! $user) abort(401);

        // SECURITY (v22p94): the invoice must belong to a centre the caller can
        // access — otherwise any authenticated user could read any agency's
        // invoices (bill-to PII, amounts) by enumerating ids.
        $invoice = DB::table('invoices')->where('id', $id)->first();
        if (! $invoice) abort(404, 'Invoice not found');
        abort_unless($this->authorizeCentreAccess($user, (int) $invoice->centre_id), 403);

        /* THE AGENCY'S CHOSEN TEMPLATE, not whichever renderer this line names. This is
           what "View invoice" opens from Accounting, and it was drawing the default
           KiddieTrac document for an agency that had chosen iLearn's. (2026-09-17) */
        $html = \App\Services\InvoiceDocument::html($id, false);
        if ($html === null) abort(404, 'Invoice not found');

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /* THE SAME DOCUMENT, AS A FILE (2026-09-17).

       "Download invoice" in the schedule kebab. previewExisting() hands back HTML for an
       iframe; this hands back the identical document as a PDF, drawn from the agency's
       CHOSEN template so the file a director saves is the file the family received.

       InvoiceDocument::pdf() returns null rather than guessing when it cannot produce a
       real PDF - the renderers historically returned HTML from a method called "pdf", and
       an HTML file wearing a .pdf extension is worse than an honest error, because the
       parent it is forwarded to simply cannot open it. */
    public function pdfExisting(Request $request, int $id): Response
    {
        $user = $request->user();
        if (! $user) abort(401);

        $invoice = DB::table('invoices')->where('id', $id)->first();
        if (! $invoice) abort(404, 'Invoice not found');
        abort_unless($this->authorizeCentreAccess($user, (int) $invoice->centre_id), 403);

        $pdf = \App\Services\InvoiceDocument::pdf($id);
        if ($pdf === null) abort(422, 'That invoice could not be rendered as a PDF.');

        $name = 'invoice-' . ($invoice->invoice_number ?: $id) . '.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }

    /**
     * Render a sample invoice with the current agency's branding —
     * great for showing prospective resellers what their branded
     * invoices will look like.
     */
    public function previewSample(Request $request): Response
    {
        $user = $request->user();
        if (! $user) abort(401);

        $agencyId = $request->query('agency_id');
        $agency = null;
        if ($agencyId) {
            $agency = DB::table('agencies')->where('id', $agencyId)->first();
        } elseif (isset($user->agency_id)) {
            $agency = DB::table('agencies')->where('id', $user->agency_id)->first();
        }

        $sampleInvoice = (object) [
            'number'        => 'PREVIEW-' . date('Ymd'),
            'issued_at'     => date('Y-m-d'),
            'due_at'        => date('Y-m-d', strtotime('+14 days')),
            'bill_to_name'  => 'Sarah Thompson',
            'bill_to_email' => 'sarah.thompson@example.com',
            'child_name'    => 'Liam Thompson',
            'currency'      => 'CAD',
            'status'        => 'unpaid',
            'notes'         => 'Sibling discount applied for second child.',
            'items'         => [
                ['description' => 'Full-time childcare — June 2026',                 'quantity' => 1,  'unit_cents' => 165000, 'total_cents' => 165000],
                ['description' => 'Late pickup fee (June 8)',                        'quantity' => 1,  'unit_cents' =>   2500, 'total_cents' =>   2500],
                ['description' => 'Field trip — Royal Botanical Gardens',           'quantity' => 1,  'unit_cents' =>   3500, 'total_cents' =>   3500],
                ['description' => 'Subsidy — CWELCC',                                'quantity' => 1,  'unit_cents' => -38500, 'total_cents' => -38500],
            ],
            'total_cents'   => 132500,
        ];

        /* THE PREVIEW SHOWS THE STYLE THIS AGENCY HAS CHOSEN.

           The Branding screen renders this panel directly beside the invoice-style
           dropdown, and a preview showing the other style is worse than no preview at
           all. `?template=` lets the screen preview a choice BEFORE it is saved — it is
           read-only and validated against the catalogue, so it can only ever show one of
           the real templates. (2026-09-17) */
        $requested = (string) $request->query('template', '');
        $template = array_key_exists($requested, \App\Services\InvoiceDocument::templates())
            ? $requested
            : \App\Services\InvoiceDocument::templateFor($agency ? (int) $agency->id : null);

        if ($template === 'ilearn') {
            $html = app(\App\Services\IlearnInvoiceRenderer::class)->renderSample($agency, false);
        } elseif (array_key_exists($template, \App\Services\InvoiceStyleRenderer::STYLES)) {
            $html = app(\App\Services\InvoiceStyleRenderer::class)->renderSample($agency, $template, false);
        } else {
            $html = app(InvoicePdfRenderer::class)->renderHtml($sampleInvoice, $agency);
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
