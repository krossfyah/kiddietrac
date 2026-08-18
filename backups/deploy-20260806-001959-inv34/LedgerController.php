<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p58 — Family ledger with running balance + statement PDF on demand.
 * Computed on-the-fly from invoices, payments, and refunds.
 */
final class LedgerController extends Controller
{
    use ResolvesCentreContext;

    public function familyLedger(Request $request, int $familyId): JsonResponse
    {
        $this->assertAccess($request, $familyId);
        $family = DB::table('families')->where('id', $familyId)->first();
        abort_unless($family, 404);

        $entries = collect();

        // Invoices = debits
        DB::table('invoices')->where('family_id', $familyId)
            ->orderBy('issued_at')
            ->select('id', 'invoice_number', 'issued_at', 'due_at', 'total', 'status', 'period_start', 'period_end')
            ->get()->each(function ($inv) use ($entries) {
                $daysLate = 0;
                $unpaid = ! in_array(strtolower((string) $inv->status), ['paid', 'void', 'cancelled', 'refunded']);
                if ($unpaid && $inv->due_at && Carbon::parse($inv->due_at)->isPast()) {
                    $daysLate = (int) Carbon::parse($inv->due_at)->startOfDay()->diffInDays(now()->startOfDay());
                }
                $entries->push([
                    'date' => $inv->issued_at,
                    'type' => 'invoice',
                    'invoice_id' => $inv->id,
                    'reference' => $inv->invoice_number,
                    'description' => 'Invoice ' . $inv->invoice_number
                        . ($inv->period_start ? ' (' . Carbon::parse($inv->period_start)->format('M j') . ' - ' . Carbon::parse($inv->period_end)->format('M j') . ')' : ''),
                    'debit' => (float) $inv->total,
                    'credit' => 0,
                    'status' => $inv->status,
                    'due_at' => $inv->due_at,
                    'days_late' => $daysLate,
                ]);
            });

        // Payments = credits
        DB::table('payments')->where('family_id', $familyId)
            ->whereNotNull('paid_at')
            ->orderBy('paid_at')
            ->select('id', 'paid_at', 'amount', 'method', 'reference_number')
            ->get()->each(function ($p) use ($entries) {
                $entries->push([
                    'date' => $p->paid_at,
                    'type' => 'payment',
                    'reference' => $p->reference_number,
                    'description' => 'Payment via ' . ($p->method ?: 'manual'),
                    'debit' => 0,
                    'credit' => (float) $p->amount,
                    'status' => 'received',
                ]);
            });

        // Refunds = debits (negative credit)
        DB::table('payment_refunds as pr')
            ->join('payments as p', 'p.id', '=', 'pr.payment_id')
            ->where('p.family_id', $familyId)
            ->whereIn('pr.status', ['succeeded', 'pending', 'manual'])
            ->orderBy('pr.refunded_at')
            ->select('pr.refunded_at', 'pr.amount', 'pr.reason')
            ->get()->each(function ($r) use ($entries) {
                $entries->push([
                    'date' => $r->refunded_at,
                    'type' => 'refund',
                    'reference' => null,
                    'description' => 'Refund: ' . ($r->reason ?: 'other'),
                    'debit' => (float) $r->amount,
                    'credit' => 0,
                    'status' => 'refunded',
                ]);
            });

        // Sort + running balance
        $sorted = $entries->sortBy('date')->values();
        $balance = 0;
        $sorted = $sorted->map(function ($e) use (&$balance) {
            $balance += $e['debit'] - $e['credit'];
            $e['running_balance'] = round($balance, 2);
            return $e;
        });

        return response()->json([
            'family' => [
                'id' => $family->id,
                'family_name' => $family->family_name,
                'primary_email' => $family->primary_email,
            ],
            'data' => $sorted,
            'current_balance' => round($balance, 2),
            'total_invoiced' => round($sorted->sum('debit'), 2),
            'total_paid' => round($sorted->where('type', 'payment')->sum('credit'), 2),
            'total_refunded' => round($sorted->where('type', 'refund')->sum('debit'), 2),
            'days_overdue' => (int) ($sorted->where('type', 'invoice')->max('days_late') ?: 0),
        ]);
    }

    public function familyLedgerPdf(Request $request, ?int $familyId = null): \Symfony\Component\HttpFoundation\Response
    {
        // v22p98: the /parent/ledger/pdf route passes no familyId — resolve the
        // signed-in guardian's own family (was a "too few arguments" 500).
        $familyId = $familyId ?: (int) DB::table('guardians')->where('user_id', $request->user()->id)->value('family_id');
        abort_unless($familyId, 404);
        $this->assertAccess($request, (int) $familyId);
        $resp = $this->familyLedger($request, $familyId);
        $payload = json_decode($resp->getContent(), true);
        $family = $payload['family'];
        $rows = $payload['data'];

        $agencyId = (int) DB::table('families')->where('families.id', $familyId)
            ->join('centres', 'centres.id', '=', 'families.centre_id')->value('centres.agency_id');
        $agency = DB::table('agencies')->where('id', $agencyId)->first();

        $html = view('pdf.ledger', [
            'agency' => $agency,
            'family' => (object) $family,
            'rows' => $rows,
            'balance' => $payload['current_balance'],
            'totals' => $payload,
        ])->render();

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        return new \Symfony\Component\HttpFoundation\Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Statement-' . preg_replace('/[^A-Za-z0-9]/', '-', $family['family_name']) . '-' . now()->format('Y-m-d') . '.pdf"',
        ]);
    }

    public function myLedger(Request $request): JsonResponse
    {
        $u = $request->user();
        $famId = DB::table('guardians')->where('user_id', $u->id)->value('family_id');
        abort_unless($famId, 404, 'No family linked');
        return $this->familyLedger($request, (int) $famId);
    }

    private function assertAccess(Request $request, int $familyId): void
    {
        // SECURITY (v22p96): guardian of THIS family, staff of its centre, OR a
        // platform_admin SCOPED to the agency they've switched into. The prior
        // unconditional `if (isPlatformAdminUser) return;` let a super-admin read
        // any family's ledger in any tenant regardless of the active agency.
        abort_unless($this->canAccessFamilyScoped($request, $familyId), 403);
    }
}
