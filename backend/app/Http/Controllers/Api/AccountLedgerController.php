<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One ledger per ACCOUNT, whoever they are.
 *
 * The finance screens each answered a different half of the question. Accounting
 * showed invoices owed BY families; Payroll showed money paid TO staff; a family
 * ledger existed but only for families. Nobody could sit down and ask "what is the
 * financial position of this person, whatever their role" — which is the question an
 * accountant actually asks, and the one that spans both directions.
 *
 * So: every account the agency has, with what they owe and what they were paid, and a
 * per-account statement behind it.
 *
 * THE TWO DIRECTIONS ARE ATTACHED DIFFERENTLY, which is the whole reason this needs
 * its own controller rather than another view over invoices:
 *
 *   owed to the agency  -> attached to a FAMILY (invoices, external_invoices,
 *                          payments, payment_refunds)
 *   paid by the agency  -> attached to a USER   (payroll_documents, payee_invoices)
 *
 * A person can be both. A parent who also works here has receivables through their
 * family and payables through their user id, and until now those lived on screens
 * that never met. The ledger keys on the USER and reaches sideways to the family.
 *
 * SCOPE. Agency-scoped through resolveAgencyId() like every other finance endpoint,
 * and restricted to agency_admin / platform_admin rather than the wider finance group
 * — this exposes what every colleague is paid, which a centre director has no reason
 * to see.
 */
class AccountLedgerController extends Controller
{
    use ResolvesCentreContext;

    private const ROLE_LABELS = [
        'guardian' => 'Parent', 'educator' => 'Educator', 'centre_director' => 'Director',
        'agency_admin' => 'Admin', 'platform_admin' => 'Platform admin',
        'home_visitor' => 'Home visitor', 'auditor' => 'Auditor', 'sales_rep' => 'Sales',
    ];

    /** Money that is still owed — everything except settled or cancelled. */
    /** A void was issued and then withdrawn: no balance, no revenue, still history. */
    private function isVoid(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), ['void', 'cancelled', 'canceled', 'deleted'], true);
    }

    private function isOpen(?string $status): bool
    {
        return ! in_array(strtolower((string) $status), ['paid', 'void', 'cancelled', 'refunded'], true);
    }

    /**
     * GET /admin/account-ledgers — every account, with both sides of its position.
     */
    public function index(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        if (! $agencyId) {
            return response()->json(['accounts' => [], 'totals' => [], 'meta' => ['page' => 1, 'pages' => 1, 'total' => 0]]);
        }

        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id');

        /* Who belongs to this agency. Two routes in, because they are recorded
           differently: staff carry agency_id on the role assignment, while a guardian
           may only be reachable through their family's centre. Taking one and not the
           other is how an account goes missing from a finance list. */
        $byRole = DB::table('role_assignments')->where('agency_id', $agencyId)
            ->where('active', 1)->pluck('user_id');
        $byFamily = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->whereIn('f.centre_id', $centreIds)->pluck('g.user_id');
        $userIds = $byRole->merge($byFamily)->unique()->values();

        if ($userIds->isEmpty()) {
            return response()->json(['accounts' => [], 'totals' => [], 'meta' => ['page' => 1, 'pages' => 1, 'total' => 0]]);
        }

        // ── roles, one query ────────────────────────────────────────────────
        $roles = [];
        foreach (DB::table('role_assignments')->whereIn('user_id', $userIds)->where('active', 1)
            ->get(['user_id', 'role']) as $r) {
            $roles[(int) $r->user_id][self::ROLE_LABELS[$r->role] ?? ucfirst(str_replace('_', ' ', $r->role))] = true;
        }

        /* Which family each user is a guardian of — WITHIN THIS AGENCY.
           Joined through families.centre_id rather than taken from guardians alone: a
           person can hold a role here and be a parent somewhere else entirely, and
           without this their other agency's money lands in these books. It did — a
           demo family at agency 6 was contributing $1,610 to iLearn's totals. */
        $famOf = [];
        foreach (DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->whereIn('g.user_id', $userIds)->whereIn('f.centre_id', $centreIds)
            ->get(['g.user_id', 'g.family_id']) as $g) {
            $famOf[(int) $g->user_id][] = (int) $g->family_id;
        }
        $allFamilyIds = collect($famOf)->flatten()->unique()->values()->all() ?: [0];

        /* ── RECEIVABLE, per family ──────────────────────────────────────────
           Computed by exactly the rules the statement uses, because opening a
           statement from this list must not change the numbers:

             billed       live invoices only — a void was issued and then withdrawn,
                          and was never revenue
             voided       its own figure, because 53 missing invoice numbers look
                          like a bug and counting them would invent revenue
             outstanding  what each invoice still owes (stillDue)
             collected    what it no longer owes — NOT amount_paid, which 35 invoices
                          here leave at 0 while marked paid at source, money that
                          then showed as neither outstanding nor collected

           So the four figures tie: billed − outstanding = collected. */
        $billed = []; $paidIn = []; $outstanding = []; $voided = [];

        foreach (DB::table('invoices')->whereIn('family_id', $allFamilyIds)
            ->get(['family_id', 'total', 'amount_paid', 'balance_due', 'status']) as $r) {
            $fid = (int) $r->family_id;
            if ($this->isVoid($r->status)) { $voided[$fid] = ($voided[$fid] ?? 0) + (float) $r->total; continue; }
            $due = $this->stillDue($r);
            $billed[$fid] = ($billed[$fid] ?? 0) + (float) $r->total;
            $outstanding[$fid] = ($outstanding[$fid] ?? 0) + $due;
            $paidIn[$fid] = ($paidIn[$fid] ?? 0) + ((float) $r->total - $due);
        }
        foreach (DB::table('external_invoices')->whereIn('family_id', $allFamilyIds)
            ->get(['family_id', 'total', 'amount_paid', 'balance_due', 'status']) as $r) {
            $fid = (int) $r->family_id;
            if ($this->isVoid($r->status)) { $voided[$fid] = ($voided[$fid] ?? 0) + (float) $r->total; continue; }
            $due = $this->stillDue($r);
            $billed[$fid] = ($billed[$fid] ?? 0) + (float) $r->total;
            $outstanding[$fid] = ($outstanding[$fid] ?? 0) + $due;
            $paidIn[$fid] = ($paidIn[$fid] ?? 0) + ((float) $r->total - $due);
        }

        /* A refund is money handed back, so it un-collects: the family paid it, the
           agency returned it, and the position is as if it had never been settled. */
        foreach (DB::table('payment_refunds as pr')->join('payments as p', 'p.id', '=', 'pr.payment_id')
            ->whereIn('p.family_id', $allFamilyIds)
            ->whereNotIn(DB::raw('LOWER(pr.status)'), ['failed', 'cancelled', 'void'])
            ->get(['p.family_id', 'pr.amount']) as $r) {
            $fid = (int) $r->family_id;
            $paidIn[$fid] = ($paidIn[$fid] ?? 0) - (float) $r->amount;
            $outstanding[$fid] = ($outstanding[$fid] ?? 0) + (float) $r->amount;
        }

        // ── PAYABLE, per user, two queries ──────────────────────────────────
        $paidOut = []; $payslips = [];
        foreach (DB::table('payroll_documents')->whereIn('user_id', $userIds)
            ->where('agency_id', $agencyId)->get(['user_id', 'net']) as $r) {
            $uid = (int) $r->user_id;
            $paidOut[$uid] = ($paidOut[$uid] ?? 0) + (float) $r->net;
            $payslips[$uid] = ($payslips[$uid] ?? 0) + 1;
        }
        foreach (DB::table('payee_invoices')->whereIn('payee_user_id', $userIds)
            ->where('agency_id', $agencyId)->get(['payee_user_id', 'amount']) as $r) {
            $uid = (int) $r->payee_user_id;
            $paidOut[$uid] = ($paidOut[$uid] ?? 0) + (float) $r->amount;
        }

        // ── assemble ────────────────────────────────────────────────────────
        $agencyName = DB::table('agencies')->where('id', $agencyId)->value('name');
        $rows = [];
        foreach (DB::table('users')->whereIn('id', $userIds)
            ->get(['id', 'first_name', 'last_name', 'email', 'status']) as $u) {
            $uid = (int) $u->id;
            $fams = $famOf[$uid] ?? [];
            $b = $p = $o = $v = 0.0;
            foreach ($fams as $fid) {
                $b += $billed[$fid] ?? 0; $p += $paidIn[$fid] ?? 0;
                $o += $outstanding[$fid] ?? 0; $v += $voided[$fid] ?? 0;
            }
            $rows[] = [
                'user_id'     => $uid,
                'name'        => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: '(no name)',
                'email'       => $u->email,
                'account'     => $u->status,
                'agency_id'   => $agencyId,
                'agency'      => $agencyName,
                'roles'       => array_keys($roles[$uid] ?? []),
                'family_ids'  => $fams,
                'billed'      => round($b, 2),
                'paid'        => round($p, 2),
                'outstanding' => round($o, 2),
                'voided'      => round($v, 2),
                'paid_out'    => round($paidOut[$uid] ?? 0, 2),
                'payslips'    => (int) ($payslips[$uid] ?? 0),
            ];
        }

        /* PEOPLE THE AGENCY PAYS WHO HAVE NO LOGIN.

           8 payroll documents here carry no user_id — Muib Khan Cpa, Raj Malhi, Snf
           Accounting and one of Lloydene King's — worth $10,780.86, which is exactly
           what the totals were short. They are contractors: paid by the agency, with
           no account to attach it to. Dropping them silently made the ledger disagree
           with payroll for no visible reason.

           Listed by payee name, with no user id, so the money is on the page and in
           the totals. No drill-through, because there is no account to drill into —
           the row says so rather than pretending otherwise. */
        foreach (DB::table('payroll_documents')->where('agency_id', $agencyId)->whereNull('user_id')
            ->select('payee_name', DB::raw('SUM(net) as net'), DB::raw('COUNT(*) as n'))
            ->groupBy('payee_name')->get() as $r) {
            $rows[] = [
                'user_id'     => null,
                'name'        => $r->payee_name ?: '(unnamed payee)',
                'email'       => null,
                'account'     => 'no account',
                'agency_id'   => $agencyId,
                'agency'      => $agencyName,
                'roles'       => ['Contractor'],
                'family_ids'  => [],
                'billed'      => 0.0, 'paid' => 0.0, 'outstanding' => 0.0, 'voided' => 0.0,
                'paid_out'    => round((float) $r->net, 2),
                'payslips'    => (int) $r->n,
            ];
        }

        // ── filter ──────────────────────────────────────────────────────────
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower($r['name']), $needle)
                || str_contains(mb_strtolower((string) $r['email']), $needle)));
        }
        if ($role = trim((string) $request->query('role', ''))) {
            $rows = array_values(array_filter($rows, fn ($r) => in_array($role, $r['roles'], true)));
        }
        if ($request->query('only') === 'owing') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['outstanding'] > 0.005));
        }
        if ($request->query('only') === 'paid_out') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['paid_out'] > 0.005));
        }

        // ── sort ────────────────────────────────────────────────────────────
        $sort = (string) $request->query('sort', 'name');
        $dir  = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? -1 : 1;
        usort($rows, function ($a, $b) use ($sort, $dir) {
            $x = $a[$sort] ?? null; $y = $b[$sort] ?? null;
            if (is_numeric($x) && is_numeric($y)) { return $dir * (($x <=> $y)); }

            return $dir * strcasecmp((string) (is_array($x) ? implode(',', $x) : $x),
                                     (string) (is_array($y) ? implode(',', $y) : $y));
        });

        $total = count($rows);
        $perPage = max(1, min(200, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));

        /* Totals are summed once per FAMILY, not once per account.

           A family with two guardians appears on two rows, each correctly showing what
           that family owes — but adding the rows up counts it twice. Five families here
           have two guardians, worth $6,437.76 of phantom debt in the agency total. The
           per-account figures were never wrong; only the sum over them was. */
        $seenFams = [];
        foreach ($rows as $r) { foreach ($r['family_ids'] as $fid) { $seenFams[$fid] = true; } }
        $famKeys = array_keys($seenFams);
        $sumBilled = $sumPaid = $sumOut = $sumVoid = 0.0;
        foreach ($famKeys as $fid) {
            $sumBilled += $billed[$fid] ?? 0;
            $sumPaid   += $paidIn[$fid] ?? 0;
            $sumOut     += $outstanding[$fid] ?? 0;
            $sumVoid   += $voided[$fid] ?? 0;
        }

        return response()->json([
            'accounts' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'totals'   => [
                'accounts'    => $total,
                'billed'      => round($sumBilled, 2),
                'paid'        => round($sumPaid, 2),
                'outstanding' => round($sumOut, 2),
                'voided'      => round($sumVoid, 2),
                'paid_out'    => round(array_sum(array_column($rows, 'paid_out')), 2),
            ],
            'roles' => array_values(array_unique(collect($rows)->pluck('roles')->flatten()->all())),
            'meta'  => ['page' => $page, 'per_page' => $perPage, 'total' => $total,
                        'pages' => max(1, (int) ceil($total / $perPage))],
        ]);
    }

    /**
     * GET /admin/account-ledgers/{userId} — the statement behind one account.
     *
     * Both directions on one running balance, oldest first: a debit is money the
     * account owes the agency, a credit is money that settled it. Payroll appears as
     * its own kind rather than being netted against fees — an educator's pay is not a
     * credit against their child's invoice, and showing it as one would be wrong in
     * every direction that matters.
     */
    public function show(Request $request, int $userId): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId, 403);

        return response()->json($this->statement($agencyId, $userId));
    }

    /**
     * GET /admin/account-ledgers/{userId}/statement.pdf — the same statement, as the
     * document that gets attached to the email. Inline so the browser can preview it;
     * the screen asks for it as a blob and saves it under a real filename.
     */
    public function statementPdf(Request $request, int $userId)
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId, 403);

        $st = $this->statement($agencyId, $userId);
        $pdf = app(\App\Services\AccountStatementPdf::class);

        return response($pdf->render($st), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $pdf->filename($st) . '"',
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
       THE STATEMENT.

       Everything recorded against one account, in both directions, as a dated
       narrative — invoices, receipts, refunds, voids, payroll — plus what is still
       owed and what is still coming.

       WHAT A VOID IS NOT. A voided invoice is not a deleted one and not a paid one:
       it was issued, it is part of the history, and it settles nothing. 53 of
       iLearn's invoices are void, worth $6,163.28. They are listed with their
       original amount showing and a zero effect on the balance — leaving them out
       would make the numbering look wrong, and counting them would invent debt.

       WHY THE CREDIT ON AN INVOICE LINE IS NOT amount_paid. 35 invoices here are
       marked paid at source while carrying amount_paid = 0 — iLearn recorded the
       settlement without recording the figure. Reading amount_paid literally would
       leave $8k of settled invoices sitting on the balance forever. So each invoice
       states what is still DUE (the same rule the accounting screen uses, so the two
       agree), and the credit line is whatever the difference is, described honestly
       as settled at source when that is what happened.
       ═══════════════════════════════════════════════════════════════════════ */
    private function statement(int $agencyId, int $userId): array
    {
        $user = DB::table('users')->where('id', $userId)
            ->first(['id', 'first_name', 'last_name', 'email', 'phone', 'status', 'created_at']);
        abort_unless($user, 404, 'No such account.');

        /* The account must belong to the agency being viewed. Checked here rather than
           trusted from the list that produced the id — a client can ask for any id. */
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id');
        $inAgency = DB::table('role_assignments')->where('user_id', $userId)
                ->where('agency_id', $agencyId)->where('active', 1)->exists()
            || DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
                ->where('g.user_id', $userId)->whereIn('f.centre_id', $centreIds)->exists();
        abort_unless($inAgency, 404, 'No such account in this agency.');

        /* Families restricted to THIS agency, for the same reason as the list: a
           person can hold a role here and be a parent somewhere else entirely, and
           their other agency's money must not appear on this statement. */
        $famIds = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->where('g.user_id', $userId)->whereIn('f.centre_id', $centreIds)
            ->pluck('g.family_id')->unique()->values()->all() ?: [0];

        $today = Carbon::today();
        $entries = [];
        $openItems = [];
        $billed = $credited = $refunded = $voidedTotal = $balance = $overdue = 0.0;
        $overpaid = 0.0;

        // ── INVOICES, both kinds ────────────────────────────────────────────
        $invoiceRows = [];
        foreach (DB::table('invoices')->whereIn('family_id', $famIds)
            ->get(['id', 'invoice_number', 'issued_at', 'due_at', 'total', 'amount_paid',
                   'balance_due', 'status', 'pdf_url']) as $r) {
            $invoiceRows[] = ['row' => $r, 'label' => 'Invoice ' . $r->invoice_number,
                'ref' => $r->invoice_number, 'doc' => $r->pdf_url];
        }
        foreach (DB::table('external_invoices')->whereIn('family_id', $famIds)
            ->get(['id', 'number', 'issued_at', 'due_at', 'total', 'amount_paid', 'balance_due',
                   'status', 'source_label', 'description', 'pdf_url', 'external_updated_at']) as $r) {
            $invoiceRows[] = ['row' => $r, 'label' => trim(($r->source_label ?: 'External') . ' invoice ' . $r->number),
                'ref' => $r->number, 'doc' => $r->pdf_url];
        }

        foreach ($invoiceRows as $iv) {
            $r = $iv['row'];
            $total = (float) $r->total;
            $status = strtolower((string) $r->status);
            $isVoid = $this->isVoid($status);
            $due = $isVoid ? 0.0 : $this->stillDue($r);
            $settled = $isVoid ? 0.0 : round($total - $due, 2);

            if ($isVoid) {
                $voidedTotal += $total;
                $entries[] = [
                    'date' => $r->issued_at, 'kind' => 'void', 'direction' => 'owed',
                    'reference' => $iv['ref'], 'description' => $iv['label'] . ' — voided',
                    'status' => $status, 'debit' => 0.0, 'credit' => 0.0,
                    'original' => $total, 'voided' => true, 'doc_url' => $iv['doc'],
                    'note' => 'Issued then voided. Carries no balance.',
                ];
                continue;
            }

            $billed += $total;
            $balance += $due;
            $entries[] = [
                'date' => $r->issued_at, 'kind' => 'invoice', 'direction' => 'owed',
                'reference' => $iv['ref'], 'description' => $iv['label'],
                'status' => $status, 'debit' => $total, 'credit' => 0.0,
                'due_at' => $r->due_at ?? null, 'outstanding' => $due, 'doc_url' => $iv['doc'],
                'note' => trim((string) ($r->description ?? '')) ?: null,
            ];

            $recorded = (float) ($r->amount_paid ?? 0);
            $over = round($recorded - $total, 2);

            if ($settled > 0.005) {
                $credited += $settled;

                /* The receipt says more came in than was ever invoiced. Stated on the
                   line and totalled on the account, never netted into the balance:
                   whether that is a credit the family can draw on or a payment
                   recorded twice at source is a decision for the person reading
                   this, and thirteen of these are an exact doubling. */
                $note = null;
                if ($recorded < 0.005) {
                    $note = 'Recorded as settled at source without a payment figure.';
                } elseif ($over > 0.005) {
                    $overpaid += $over;
                    $note = 'Receipt recorded as $' . number_format($recorded, 2) . ' against a $'
                        . number_format($total, 2) . ' invoice — $' . number_format($over, 2)
                        . ' more than was billed.';
                }

                $entries[] = [
                    'date' => $r->external_updated_at ?? $r->due_at ?? $r->issued_at,
                    'kind' => 'receipt', 'direction' => 'owed',
                    'reference' => $iv['ref'],
                    'description' => 'Payment received — ' . $iv['ref'],
                    'status' => 'received', 'debit' => 0.0, 'credit' => $settled,
                    'overpaid' => $over > 0.005 ? $over : null,
                    'note' => $note,
                ];
            }

            if ($due > 0.005) {
                $daysLate = $r->due_at ? $today->diffInDays(Carbon::parse($r->due_at), false) : null;
                $late = $daysLate !== null && $daysLate < 0 ? (int) abs($daysLate) : 0;
                if ($late > 0) { $overdue += $due; }
                $openItems[] = [
                    'reference' => $iv['ref'], 'description' => $iv['label'],
                    'issued_at' => $r->issued_at, 'due_at' => $r->due_at ?? null,
                    'total' => $total, 'outstanding' => $due, 'status' => $status,
                    'days_overdue' => $late, 'doc_url' => $iv['doc'],
                ];
            }
        }

        // ── RECEIPTS recorded natively ──────────────────────────────────────
        foreach (DB::table('payments as p')->leftJoin('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->whereIn('p.family_id', $famIds)
            ->get(['p.id', 'p.amount', 'p.method', 'p.status', 'p.paid_at', 'p.created_at',
                   'p.reference_number', 'p.notes', 'i.invoice_number']) as $r) {
            $ok = $r->status === null || strtolower((string) $r->status) === 'succeeded';
            $amt = (float) $r->amount;
            if ($ok) { $credited += $amt; $balance -= $amt; }
            $entries[] = [
                'date' => $r->paid_at ?: $r->created_at, 'kind' => 'receipt', 'direction' => 'owed',
                'reference' => $r->reference_number ?: ('RCPT-' . $r->id),
                'description' => 'Payment received' . ($r->method ? ' — ' . self::methodLabel($r->method) : '')
                    . ($r->invoice_number ? ' against ' . $r->invoice_number : ''),
                'status' => $r->status, 'debit' => 0.0, 'credit' => $ok ? $amt : 0.0,
                'original' => $ok ? null : $amt,
                'note' => $ok ? (trim((string) $r->notes) ?: null)
                    : 'Not collected — this payment ' . strtolower((string) $r->status) . '.',
            ];
        }

        /* ── REFUNDS ─────────────────────────────────────────────────────────
           Money going back OUT to the account holder, which is not the same event as
           the agency paying a supplier: it reverses a receipt, so it lands on the
           receivable side as a debit and the balance rises again by exactly what was
           handed back. */
        foreach (DB::table('payment_refunds as pr')->join('payments as p', 'p.id', '=', 'pr.payment_id')
            ->whereIn('p.family_id', $famIds)
            ->get(['pr.id', 'pr.amount', 'pr.reason', 'pr.refund_method', 'pr.status',
                   'pr.refunded_at', 'pr.created_at', 'pr.notes', 'p.reference_number']) as $r) {
            $ok = ! in_array(strtolower((string) $r->status), ['failed', 'cancelled', 'void'], true);
            $amt = (float) $r->amount;
            if ($ok) { $refunded += $amt; $balance += $amt; }
            $entries[] = [
                'date' => $r->refunded_at ?: $r->created_at, 'kind' => 'refund', 'direction' => 'owed',
                'reference' => $r->reference_number ?: ('REF-' . $r->id),
                'description' => 'Refund' . ($r->refund_method ? ' — ' . self::methodLabel($r->refund_method) : ''),
                'status' => $r->status, 'debit' => $ok ? $amt : 0.0, 'credit' => 0.0,
                'note' => trim((string) ($r->reason ?: $r->notes)) ?: null,
            ];
        }

        // ── PAID BY THE AGENCY — its own direction, never netted against fees ──
        $paidOut = 0.0; $payslips = 0;
        foreach (DB::table('payroll_documents')->where('user_id', $userId)->where('agency_id', $agencyId)
            ->get(['id', 'period_start', 'period_end', 'gross', 'net', 'kind', 'created_at']) as $r) {
            $paidOut += (float) $r->net; $payslips++;
            $entries[] = [
                'date' => $r->period_end ?: $r->created_at, 'kind' => 'payroll', 'direction' => 'paid_out',
                'reference' => ucfirst((string) ($r->kind ?: 'payslip')),
                'description' => 'Payroll' . ($r->period_start
                    ? ' ' . Carbon::parse($r->period_start)->format('j M') . '–' . Carbon::parse($r->period_end)->format('j M Y')
                    : ''),
                'status' => 'paid', 'debit' => 0.0, 'credit' => 0.0,
                'gross' => (float) $r->gross, 'net' => (float) $r->net,
            ];
        }
        foreach (DB::table('payee_invoices')->where('payee_user_id', $userId)->where('agency_id', $agencyId)
            ->get(['id', 'reference', 'amount', 'status', 'period_start', 'period_end', 'kind',
                   'paid_at', 'created_at']) as $r) {
            $paidOut += (float) $r->amount;
            $entries[] = [
                'date' => $r->paid_at ?: $r->period_end ?: $r->created_at,
                'kind' => 'payee_invoice', 'direction' => 'paid_out',
                'reference' => $r->reference ?: ('#' . $r->id),
                'description' => ucfirst((string) ($r->kind ?: 'Payee invoice')),
                'status' => $r->status, 'debit' => 0.0, 'credit' => 0.0, 'net' => (float) $r->amount,
            ];
        }

        /* ── WHAT IS STILL COMING ────────────────────────────────────────────
           Two different promises, kept apart because they answer different
           questions: an instalment is an agreed amount on an agreed date, while a
           billing schedule is only "we will raise something on the 1st". Unpaid
           instalments already past their date are included and flagged rather than
           dropped — a missed instalment is the single most useful thing on this
           part of the statement. */
        $upcoming = [];
        foreach (DB::table('payment_plan_installments as i')
            ->join('payment_plans as p', 'p.id', '=', 'i.payment_plan_id')
            ->whereIn('p.family_id', $famIds)
            ->whereNotIn(DB::raw('LOWER(i.status)'), ['paid', 'cancelled', 'void'])
            ->orderBy('i.due_date')
            ->get(['i.id', 'i.due_date', 'i.amount', 'i.status',
                   'p.id as plan_id', 'p.total_amount', 'p.installment_count']) as $r) {
            $d = Carbon::parse($r->due_date);
            $upcoming[] = [
                'kind' => 'installment', 'date' => $r->due_date, 'amount' => (float) $r->amount,
                'description' => 'Payment plan instalment',
                'detail' => 'Plan #' . $r->plan_id . ' — ' . (int) $r->installment_count
                    . ' instalments against a $' . number_format((float) $r->total_amount, 2) . ' total',
                'status' => $r->status,
                'days' => (int) $today->diffInDays($d, false),
                'overdue' => $d->lt($today),
            ];
        }
        foreach (DB::table('billing_schedules')->whereIn('family_id', $famIds)->where('active', 1)
            ->get(['id', 'frequency', 'next_charge_at']) as $r) {
            if (! $r->next_charge_at) { continue; }
            $d = Carbon::parse($r->next_charge_at);
            $upcoming[] = [
                'kind' => 'schedule', 'date' => $r->next_charge_at, 'amount' => null,
                'description' => 'Next ' . strtolower((string) ($r->frequency ?: 'scheduled')) . ' invoice',
                'detail' => 'Raised automatically — the amount is set when it is issued.',
                'status' => 'scheduled',
                'days' => (int) $today->diffInDays($d, false),
                'overdue' => false,
            ];
        }
        usort($upcoming, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        // ── order the narrative, then walk the running balance ──────────────
        usort($entries, function ($a, $b) {
            $c = strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));

            return $c !== 0 ? $c : strcmp((string) $a['kind'], (string) $b['kind']);
        });
        $run = 0.0;
        foreach ($entries as $i => $e) {
            if (($e['direction'] ?? '') === 'owed') {
                $run += ($e['debit'] ?? 0) - ($e['credit'] ?? 0);
                $entries[$i]['running_balance'] = round($run, 2);
            }
        }

        $lastReceipt = null;
        foreach (array_reverse($entries) as $e) {
            if ($e['kind'] === 'receipt' && ($e['credit'] ?? 0) > 0.005) {
                $lastReceipt = ['date' => $e['date'], 'amount' => $e['credit'], 'reference' => $e['reference']];
                break;
            }
        }
        $nextUp = null;
        foreach ($upcoming as $u) {
            if (! $u['overdue']) { $nextUp = $u; break; }
        }

        $roles = DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)
            ->where(function ($q) use ($agencyId) {
                $q->where('agency_id', $agencyId)->orWhereNull('agency_id');
            })
            ->pluck('role')
            ->map(fn ($r) => self::ROLE_LABELS[$r] ?? ucfirst(str_replace('_', ' ', $r)))
            ->unique()->values()->all();

        $agency = DB::table('agencies')->where('id', $agencyId)
            ->first(['id', 'name', 'legal_name', 'contact_email', 'contact_phone',
                     'brand_logo_url', 'brand_primary_color', 'timezone']);

        $families = DB::table('families as f')->leftJoin('centres as c', 'c.id', '=', 'f.centre_id')
            ->whereIn('f.id', $famIds)
            ->get(['f.id', 'f.family_name', 'c.name as centre', 'f.primary_phone', 'f.primary_email',
                   'f.address_line1', 'f.address_line2', 'f.city', 'f.province', 'f.postal_code']);

        /* HOW TO REACH THIS PERSON.

           The users table holds a name, an email and a phone that is usually null; a
           guardian's real phone and their address live on the FAMILY, which is also
           where a billing address belongs on a statement. So the person's own details
           win where they exist and the family's fill the gaps.

           Nothing is invented: a field nobody entered is left out entirely, so the
           document omits the line rather than printing an empty label. */
        $fam = $families->first();
        $addr = [];
        if ($fam) {
            foreach ([$fam->address_line1, $fam->address_line2] as $line) {
                $line = trim((string) $line);
                if ($line !== '' && strtolower($line) !== 'null') { $addr[] = $line; }
            }
            $cityLine = trim(implode(' ', array_filter([
                trim((string) $fam->city), trim((string) $fam->province), trim((string) $fam->postal_code),
            ], fn ($v) => $v !== '' && strtolower($v) !== 'null')));
            if ($cityLine !== '') { $addr[] = $cityLine; }
        }
        $contact = array_filter([
            'email'   => $user->email ?: ($fam->primary_email ?? null),
            'phone'   => $user->phone ?: ($fam->primary_phone ?? null),
            'address' => $addr ?: null,
        ], fn ($v) => $v !== null && $v !== '');

        $children = DB::table('children')->whereIn('family_id', $famIds)->whereNull('deleted_at')
            ->get(['id', 'first_name', 'last_name', 'enrollment_status'])
            ->map(fn ($c) => ['id' => (int) $c->id,
                'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                'status' => $c->enrollment_status])->all();

        return [
            'account' => [
                'user_id'    => (int) $user->id,
                'name'       => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: '(no name)',
                'email'      => $user->email,
                'phone'      => $user->phone ?? null,
                'status'     => $user->status,
                'since'      => $user->created_at,
                'roles'      => $roles,
                'agency'     => $agency->name ?? null,
                'agency_id'  => $agencyId,
                'timezone'   => $agency->timezone ?: config('app.timezone', 'UTC'),
                'family_ids' => array_values(array_filter($famIds)),
                'contact'    => $contact,
                'families'   => $families,
                'children'   => $children,
            ],
            'summary' => [
                'billed'         => round($billed, 2),
                'credited'       => round($credited, 2),
                'refunded'       => round($refunded, 2),
                'voided'         => round($voidedTotal, 2),
                'overpaid'       => round($overpaid, 2),
                'balance'        => round($balance, 2),
                'overdue'        => round($overdue, 2),
                'paid_out'       => round($paidOut, 2),
                'payslips'       => $payslips,
                'open_count'     => count($openItems),
                'upcoming_total' => round(array_sum(array_map(fn ($u) => (float) ($u['amount'] ?? 0), $upcoming)), 2),
                'upcoming_count' => count($upcoming),
                'last_payment'   => $lastReceipt,
                'next_due'       => $nextUp,
                'as_at'          => $today->toDateString(),
            ],
            'open_items' => $openItems,
            'upcoming'   => $upcoming,
            'entries'    => $entries,
        ];
    }

    /**
     * What an invoice still owes, by the same rule the accounting screen uses.
     *
     * balance_due when the row keeps one, and only for a status that is still open —
     * a paid invoice owes nothing regardless of what its columns say, which is what
     * keeps this figure equal to the one on the ledger list and on Accounting. 35
     * invoices here are marked paid while carrying amount_paid = 0, and reading the
     * column literally would leave every one of them owing its full value forever.
     */
    private function stillDue(object $r): float
    {
        if (! $this->isOpen($r->status)) {
            return 0.0;
        }
        $bal = $r->balance_due ?? null;
        if ($bal !== null) {
            return max(0.0, round((float) $bal, 2));
        }

        return max(0.0, round((float) $r->total - (float) ($r->amount_paid ?? 0), 2));
    }

    private static function methodLabel(?string $m): string
    {
        return [
            'stripe_card' => 'card', 'stripe_ach' => 'bank transfer', 'interac' => 'Interac',
            'eft' => 'EFT', 'card' => 'card', 'cash' => 'cash', 'cheque' => 'cheque',
            'manual' => 'recorded manually',
        ][strtolower((string) $m)] ?? str_replace('_', ' ', (string) $m);
    }

    /* ═══════════════════════════════════════════════════════════════════════
       POST /admin/account-ledgers/{userId}/email — send the statement out.

       The same statement the screen shows, wrapped in the agency's own branding
       rather than KiddieTrac's: EmailTemplate::wrap already resolves an agency's
       logo, primary colour, address and support address, and every other
       transactional email on the platform goes through it. A parent should see who
       is writing to them.

       AN AGENCY THAT HAS TURNED EMAIL OFF STAYS OFF. The switch is checked here and
       the caller is told plainly, rather than the send being handed to the mail
       layer to swallow silently — an admin pressing "Email statement" and seeing
       nothing happen is how a suppression toggle gets mistaken for a broken button.
       (This is a pre-check for the message, not a bypass: the listener still has the
       final say, and nothing here sets X-KT-Bypass-Suppression.)

       WHERE IT CAN GO. To the account holder by default. An explicit recipient is
       allowed because a statement is routinely sent to a bookkeeper rather than the
       parent, but it is an admin-only route and every send is audited with the
       address it went to.
       ═══════════════════════════════════════════════════════════════════════ */
    public function emailStatement(Request $request, int $userId): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId, 403);

        /* `to` is REQUIRED, deliberately.

           It used to be optional and fall back to the account holder's own address,
           which meant any request that lost the field — a body that failed to parse,
           a client that forgot it — silently re-addressed a statement to a real
           parent. The dialog prefills this with the account's own address, so
           requiring it costs nothing and removes the path entirely. */
        $data = $request->validate([
            'to'      => 'required|email|max:180',
            'message' => 'nullable|string|max:800',
            'copy_me' => 'nullable|boolean',
        ]);

        $st = $this->statement($agencyId, $userId);
        $acct = $st['account'];
        $to = $data['to'];

        if (! \App\Support\Suppression::agencyNotificationsEnabled($agencyId)) {
            return response()->json([
                'sent' => false,
                'reason' => 'Email is switched off for ' . ($acct['agency'] ?: 'this agency')
                    . '. Turn notifications back on in Settings to send statements.',
            ], 409);
        }

        $agency = DB::table('agencies')->where('id', $agencyId)->first(['name', 'contact_email', 'brand_support_email']);
        $agencyName = $agency->name ?? 'your childcare provider';
        $tz = $st['account']['timezone'] ?: config('app.timezone', 'UTC');
        $asAt = Carbon::parse($st['summary']['as_at'])->format('j F Y');

        $body = $this->statementEmailBody($st, $agencyName, trim((string) ($data['message'] ?? '')), $tz);

        $html = \App\Services\EmailTemplate::wrap($agencyId, $body, [
            'eyebrow'   => 'ACCOUNT STATEMENT',
            'title'     => $agencyName,
            'subtitle'  => 'Statement for ' . $acct['name'] . ' — as at ' . $asAt,
            'preheader' => $st['summary']['balance'] > 0.005
                ? 'Balance outstanding: $' . number_format($st['summary']['balance'], 2) . ' as at ' . $asAt
                : 'Your account is up to date as at ' . $asAt,
        ]);

        $subject = 'Your account statement — ' . $agencyName . ' (' . $asAt . ')';
        $copyTo = null;
        if (! empty($data['copy_me'])) {
            $copyTo = optional($request->user())->email;
        }

        /* The document. Built before the send so a failure here is a missing
           attachment rather than a half-sent message — and never fatal: the statement
           in the body is still worth delivering if dompdf trips over a font or an
           image. */
        $pdfBytes = null;
        $pdfName = null;
        try {
            $renderer = app(\App\Services\AccountStatementPdf::class);
            $pdfBytes = $renderer->render($st);
            $pdfName = $renderer->filename($st);
        } catch (\Throwable $e) {
            report($e);
        }

        $sentAt = now();

        try {
            \App\Services\AgencyMailer::forAgency($agencyId)->mailer()->html($html,
                function ($m) use ($to, $acct, $subject, $copyTo, $pdfBytes, $pdfName) {
                    $m->to($to, $acct['name'] !== '(no name)' ? $acct['name'] : null)->subject($subject);
                    if ($copyTo && strcasecmp($copyTo, $to) !== 0) {
                        $m->cc($copyTo);
                    }
                    if ($pdfBytes !== null) {
                        $m->attachData($pdfBytes, $pdfName, ['mime' => 'application/pdf']);
                    }
                });
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'sent' => false,
                'reason' => 'The mail server refused it: ' . $e->getMessage(),
            ], 502);
        }

        /* WHAT ACTUALLY HAPPENED, not what was attempted.

           Handing a message to the mailer is not delivery: the suppression listener
           sits on MessageSending and can block it on the agency switch, the centre or
           room switch, or the recipient's own account setting. Reporting sent:true
           regardless is the worst possible answer, because the admin then believes a
           family has their statement.

           The listener records its decision in email_logs during this same request,
           so that row is read back. Matched on the address and the moment rather than
           the subject, because a suppressed row has "[SUPPRESSED] " prepended to it. */
        $verdict = DB::table('email_logs')
            ->whereRaw('LOWER(to_email) = ?', [mb_strtolower($to)])
            ->where('created_at', '>=', $sentAt->copy()->subMinutes(2))
            ->orderByDesc('id')->first(['status', 'error']);
        $blocked = $verdict && strtolower((string) $verdict->status) === 'suppressed';

        try {
            \App\Support\Audit::write([
                'agency_id'   => $agencyId,
                'user_id'     => optional($request->user())->id,
                'action'      => 'account_statement.emailed',
                'entity_type' => 'user',
                'entity_id'   => $userId,
                'payload'     => json_encode([
                    'to'          => $to,
                    'cc'          => $copyTo,
                    'account'     => $acct['name'],
                    'as_at'       => $st['summary']['as_at'],
                    'balance'     => $st['summary']['balance'],
                    'overdue'     => $st['summary']['overdue'],
                    'entries'     => count($st['entries']),
                    'note_added'  => trim((string) ($data['message'] ?? '')) !== '',
                    'suppressed'  => $blocked,
                    'attachment'  => $pdfName,
                    'summary'     => ($blocked ? 'SUPPRESSED — the account statement for ' : 'Emailed the account statement for ')
                        . $acct['name'] . ' to ' . $to . ' — ' . count($st['entries'])
                        . ' entries, balance $' . number_format($st['summary']['balance'], 2) . '.'
                        . ($blocked ? ' The mail layer blocked it; nothing was delivered.' : ''),
                ]),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) { /* never fail a send over its own audit row */ }

        if ($blocked) {
            return response()->json([
                'sent'   => false,
                'to'     => $to,
                'reason' => 'Blocked before delivery — email is switched off for this recipient, '
                    . 'their centre or room, or the agency. Nothing was sent to ' . $to . '.',
            ], 409);
        }

        return response()->json([
            'sent' => true, 'to' => $to, 'cc' => $copyTo, 'subject' => $subject,
            'attachment' => $pdfName,
        ]);
    }

    /**
     * The covering note. Deliberately short.
     *
     * This used to reproduce the whole statement in the message — summary, every open
     * invoice, everything scheduled, forty lines of history — which on a phone is a
     * wall of numbers nobody scrolls, and which the attachment already says better and
     * in full. So the email answers one question, points at the document for the rest,
     * and gets out of the way.
     *
     * The balance stays, because a statement that does not say what is owed is a
     * covering note for nothing. Three lines is not a list.
     */
    private function statementEmailBody(array $st, string $agencyName, string $note, string $tz): string
    {
        $s = $st['summary'];
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m = fn ($v) => '$' . number_format((float) $v, 2);

        /* One formatter, two kinds of value, told apart by shape. A bare YYYY-MM-DD is
           a wall-clock day — an invoice issued on the 12th was issued on the 12th
           everywhere — and converting it would name the day before. Anything carrying
           a time is an instant stored in UTC, and NOT converting it dates a payment
           taken at 8pm Toronto to the following morning. */
        $d = function ($v) use ($tz) {
            if (! $v) { return '—'; }
            try {
                $raw = (string) $v;

                return preg_match('/\d{2}:\d{2}/', $raw)
                    ? Carbon::parse($raw, 'UTC')->setTimezone($tz)->format('j F Y')
                    : Carbon::parse(substr($raw, 0, 10))->format('j F Y');
            } catch (\Throwable $x) {
                return (string) $v;
            }
        };

        $bal = (float) $s['balance'];
        $asAt = $d($s['as_at']);

        $h = '<p style="margin:0 0 14px;">Hello ' . $e($st['account']['name']) . ',</p>';

        if ($bal > 0.005) {
            $h .= '<p style="margin:0 0 16px;">Your account statement with <strong>' . $e($agencyName)
                . '</strong> is attached. It sets out everything on your account as at ' . $e($asAt)
                . ' — what has been invoiced, what has been received, and what is still outstanding.</p>';
        } elseif ($bal < -0.005) {
            $h .= '<p style="margin:0 0 16px;">Your account statement with <strong>' . $e($agencyName)
                . '</strong> is attached. As at ' . $e($asAt) . ' your account is in credit, and the '
                . 'statement sets out how that stands.</p>';
        } else {
            $h .= '<p style="margin:0 0 16px;">Your account statement with <strong>' . $e($agencyName)
                . '</strong> is attached. As at ' . $e($asAt) . ' there is nothing outstanding — thank you. '
                . 'The statement is enclosed for your records.</p>';
        }

        if ($note !== '') {
            $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
                . 'style="margin:0 0 20px;"><tr><td style="background:#F1F5F9;border-left:3px solid #64748B;'
                . 'padding:14px 16px;border-radius:6px;color:#334155;font-size:14px;line-height:1.6;">'
                . nl2br($e($note)) . '</td></tr></table>';
        }

        /* The one number, and only the context that changes what someone does about it:
           whether any of it is late, and when the next amount falls due. */
        $balTint = $bal > 0.005 ? '#B45309' : '#16A34A';
        $balWord = $bal > 0.005 ? 'Balance outstanding' : ($bal < -0.005 ? 'Credit on account' : 'Nothing outstanding');
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">'
            . '<tr><td style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:20px 22px;">'
            . '<div style="font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;color:#64748B;">'
            . $e($balWord) . '</div>'
            . '<div style="font-size:30px;font-weight:800;color:' . $balTint . ';margin:4px 0 0;">'
            . $m(abs($bal)) . '</div>';

        if ($s['overdue'] > 0.005) {
            $h .= '<div style="margin-top:10px;font-size:13.5px;color:#B91C1C;font-weight:700;">'
                . $m($s['overdue']) . ' of this is past its due date.</div>';
        }
        if ($s['next_due']) {
            $h .= '<div style="margin-top:8px;font-size:13px;color:#475569;">Next due: '
                . ($s['next_due']['amount'] !== null ? '<strong>' . $m($s['next_due']['amount']) . '</strong> on ' : '')
                . $e($d($s['next_due']['date'])) . '.</div>';
        }
        if ($s['last_payment']) {
            $h .= '<div style="margin-top:4px;font-size:13px;color:#475569;">Last payment received: '
                . $m($s['last_payment']['amount']) . ' on ' . $e($d($s['last_payment']['date'])) . '.</div>';
        }
        $h .= '</td></tr></table>';

        $h .= '<p style="margin:0 0 16px;font-size:14px;color:#334155;">'
            . 'The attached PDF has the full detail — every invoice, payment and adjustment on the '
            . 'account, with a running balance.</p>';

        if ($s['paid_out'] > 0.005) {
            $h .= '<p style="margin:0 0 16px;font-size:13.5px;color:#475569;">'
                . 'It also lists the ' . $m($s['paid_out']) . ' paid to you across '
                . (int) $s['payslips'] . ' payroll document(s), kept separate from the balance above — '
                . 'money paid to you is not a credit against fees.</p>';
        }

        $h .= '<p style="margin:20px 0 0;font-size:13.5px;color:#475569;">'
            . 'If anything looks wrong, reply to this email or contact ' . $e($agencyName) . ' directly.</p>';

        return $h;
    }
}
