<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

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
    public function previewExisting(Request $request, int $id): Response
    {
        $user = $request->user();
        if (! $user) abort(401);

        $renderer = app(InvoicePdfRenderer::class);
        $html = $renderer->renderFromInvoiceId($id);
        if ($html === null) abort(404, 'Invoice not found');

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
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

        $renderer = app(InvoicePdfRenderer::class);
        $html = $renderer->renderHtml($sampleInvoice, $agency);

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
