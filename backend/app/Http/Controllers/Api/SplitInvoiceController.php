<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\SplitInvoiceCopy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Each guardian's copy of a split invoice (App\Support\SplitInvoiceCopy), for the office.
 *   GET  /invoices/{id}/split-copies                       the copies: number (INV-1234-A), guardian, share, paid, balance, sends
 *   GET  /invoices/{id}/split-copies/{guardian}/pdf        one copy as a PDF (office, or that guardian)
 *   POST /invoices/{id}/split-copies/{guardian}/send       email it {to?, cc?[]} (default: the guardian's own address)
 *   POST /invoices/{id}/split-copies/send-all              email every guardian their copy
 */
class SplitInvoiceController extends Controller
{
    use ResolvesCentreContext;

    public function index(Request $request, int $id): JsonResponse
    {
        $inv = $this->staffInvoice($request, $id);
        $copies = SplitInvoiceCopy::copies($inv);
        $sends = DB::table('split_invoice_sends as s')->leftJoin('users as u', 'u.id', '=', 's.sent_by')->where('s.invoice_id', $id)
            ->orderByDesc('s.id')->get(['s.guardian_id', 's.to_email', 's.cc', 's.created_at', 's.attached', 'u.first_name'])->groupBy('guardian_id');
        foreach ($copies as &$c) {
            $c['sends'] = ($sends[$c['guardian_id']] ?? collect())->take(5)->map(fn ($s) => ['to' => $s->to_email, 'cc' => $s->cc, 'at' => $s->created_at,
                'by' => $s->first_name, 'attached' => (bool) $s->attached])->values();
            unset($c['user_id']);
        }
        unset($c);
        return response()->json(['invoice' => ['id' => (int) $inv->id, 'number' => SplitInvoiceCopy::familyNumber($inv), 'total' => (float) $inv->total,
            'status' => $inv->status], 'copies' => $copies]);
    }

    public function pdf(Request $request, int $id, int $guardian)
    {
        $inv = DB::table('invoices')->where('id', $id)->first();
        abort_unless($inv, 404);
        $self = DB::table('guardians')->where('id', $guardian)->where('family_id', $inv->family_id)->value('user_id');
        if (! $self || (int) $self !== (int) $request->user()->id) $this->staffInvoice($request, $id);
        $c = SplitInvoiceCopy::copyFor($inv, $guardian);
        abort_unless($c, 404, 'This invoice is not split for that guardian.');
        $pdf = SplitInvoiceCopy::pdf($id, $guardian);
        abort_unless($pdf, 422, 'That copy could not be rendered as a PDF.');
        return response($pdf, 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Invoice-' . preg_replace('/[^A-Za-z0-9-]/', '-', $c['number']) . '.pdf"']);
    }

    public function send(Request $request, int $id, int $guardian): JsonResponse
    {
        $this->staffInvoice($request, $id);
        $d = $request->validate(['to' => ['nullable', 'email', 'max:180'], 'cc' => ['nullable', 'array', 'max:10'], 'cc.*' => ['email', 'max:180']]);
        $r = SplitInvoiceCopy::send($id, $guardian, $d['to'] ?? null, (int) $request->user()->id, $d['cc'] ?? []);
        $this->audit($request, $id, 'invoice.split_copy_sent', ($r['sent'] ? 'Emailed ' : 'Could not email ') . ($r['number'] ?? 'a guardian copy') . ($r['to'] ? ' to ' . $r['to'] : '') . ($r['reason'] ? ' - ' . $r['reason'] : ''));
        return response()->json($r, $r['sent'] ? 200 : 409);
    }

    public function sendAll(Request $request, int $id): JsonResponse
    {
        $this->staffInvoice($request, $id);
        $res = SplitInvoiceCopy::sendAll($id, (int) $request->user()->id);
        $ok = count(array_filter($res, fn ($r) => $r['sent']));
        $this->audit($request, $id, 'invoice.split_copy_sent', 'Emailed ' . $ok . ' of ' . count($res) . ' guardian copies');
        return response()->json(['results' => $res, 'sent' => $ok]);
    }

    private function staffInvoice(Request $request, int $id): object
    {
        $inv = DB::table('invoices')->where('id', $id)->first();
        abort_unless($inv, 404);
        abort_unless($this->canAccessFamilyScoped($request, (int) $inv->family_id), 403);
        $staff = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['platform_admin', 'agency_admin', 'centre_director'])->exists();
        abort_unless($staff, 403);
        /* Office of THIS family's centre only (2026-11-05 leak sweep): the two checks above
           were separate, so a parent of this family (or an educator at its centre) who is a
           director at some other centre passed both, and could read and email the guardian
           copies. Now: an admin of the family's agency, or a director of the family's centre. */
        $famCentre = (int) DB::table('families')->where('id', $inv->family_id)->value('centre_id');
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($famCentre > 0 && $agencyId > 0
            && (int) DB::table('centres')->where('id', $famCentre)->value('agency_id') === $agencyId, 403);
        $directorHere = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)
            ->where('role', 'centre_director')->where('centre_id', $famCentre)->exists();
        abort_unless(\App\Support\CentreScope::ids($request, $agencyId) === null || $directorHere, 403);
        return $inv;
    }

    private function audit(Request $request, int $invoiceId, string $action, string $summary): void
    {
        try {
            $inv = DB::table('invoices')->where('id', $invoiceId)->first(['centre_id']);
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => DB::table('centres')->where('id', $inv->centre_id ?? 0)->value('agency_id'),
                'action' => $action, 'entity_type' => 'invoice', 'entity_id' => $invoiceId, 'payload' => json_encode(['summary' => $summary]), 'created_at' => now()]);
        } catch (\Throwable $e) {
        }
    }
}
