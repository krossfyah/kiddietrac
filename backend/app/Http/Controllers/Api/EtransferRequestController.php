<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\CentreScope;
use App\Support\Notify;
use App\Support\ParentBilling;
use App\Support\PublicDemo;
use App\Support\WebDemoPayments;
use App\Support\WebDemoPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "SEND ME AN e-TRANSFER REQUEST" (2026-10-30, Anthony: "when parent is paying an invoice is there a button that also
 * allows them to pay by etransfer which can popup the etransfer instructions and or send a request to have the
 * etransfer link sent to them by the service provider").
 *
 * The Interac sheet already shows where to SEND an e-Transfer. Some families would rather the centre REQUEST the money
 * (Interac "Request money"): the bank then shows them a ready-made payment with the amount and memo filled in. So:
 *   parent  POST /parent/etransfer-requests          - asks, choosing which of their OWN contacts it goes to
 *   staff   GET  /billing/etransfer-requests          - the list (agency, centre-director scope)
 *           POST /billing/etransfer-requests/{id}/sent - they sent it from their bank; the parent is told to look
 *           POST /billing/etransfer-requests/{id}/paid - the money arrived: payments recorded through the normal
 *                                                        record-payment code (InvoiceController), invoice by invoice
 *           POST /billing/etransfer-requests/{id}/cancel
 * KiddieTrac never sends the request itself - the centre does, from its own bank; this is the to-do list and the loop.
 *
 * On the website demo (PublicDemo agencies) the bank is simulated: "sent" rings the demo parent's phone with a bank-style
 * request (App\Support\WebDemoPhone, kind "etransfer"), and paying it records a simulated payment through
 * App\Support\WebDemoPayments, which the nightly reset undoes. Nothing reaches a bank, an inbox or a phone.
 */
class EtransferRequestController extends Controller
{
    use ResolvesCentreContext;

    private const OPEN = ['requested', 'sent'];
    // A family's "I've sent it" (kind 'report'), waiting for a person to check the bank (2026-11-11).
    private const PENDING = ['reported'];

    // ───────────────────────────── parent ─────────────────────────────

    /** GET /parent/etransfer-requests - this family's requests, and the contacts a request can go to. */
    public function mine(Request $request): JsonResponse
    {
        $u = $request->user();
        $fams = $this->families((int) $u->id);
        $rows = $fams ? DB::table('etransfer_requests')->whereIn('family_id', $fams)->orderByDesc('id')->limit(30)->get() : collect();
        return response()->json(['requests' => $rows->map(fn ($r) => $this->present($r, false))->values(), 'contacts' => $this->contacts($u)]);
    }

    /** POST /parent/etransfer-requests {items:[{kind,id}], contact_type, contact} */
    public function create(Request $request): JsonResponse
    {
        $u = $request->user();
        $d = $request->validate([
            'items' => 'required|array|min:1|max:20',
            'items.*.kind' => 'required|in:inv,ext',
            'items.*.id' => 'required|integer|min:1',
            'contact_type' => 'required|in:email,phone',
            'contact' => 'required|string|max:190',
        ]);
        $fams = $this->families((int) $u->id);
        abort_unless($fams, 403, 'Only a parent or guardian can ask for an e-Transfer request.');

        $items = []; $agencyId = null; $familyId = null; $centreId = null;
        foreach ($d['items'] as $it) {
            if ($it['kind'] === 'inv') {
                $inv = DB::table('invoices as i')->join('centres as c', 'c.id', '=', 'i.centre_id')->where('i.id', $it['id'])
                    ->first(['i.id', 'i.family_id', 'i.centre_id', 'i.invoice_number', 'i.balance_due', 'i.status', 'c.agency_id']);
                if (! $inv || ! in_array((int) $inv->family_id, $fams, true)) abort(404, 'That invoice could not be found.');
                if ((float) $inv->balance_due < 0.005 || in_array($inv->status, ['paid', 'void', 'draft', 'refunded'], true)) continue;
                $amt = round((float) $inv->balance_due, 2);
                // Split billing: the requester's own share of the invoice.
                if ($myG = \App\Support\BillingSplit::guardianFor((int) $u->id, (int) $inv->family_id)) {
                    $full = DB::table('invoices')->where('id', $inv->id)->first();
                    if ($full && ($mine = \App\Support\BillingSplit::shareFor($full, $myG))) $amt = round(min($amt, (float) $mine['balance']), 2);
                }
                if ($amt < 0.005) continue;
                $items[] = ['kind' => 'inv', 'id' => (int) $inv->id, 'number' => (string) $inv->invoice_number, 'amount' => $amt];
                $a = (int) $inv->agency_id; $f = (int) $inv->family_id; $centreId = $centreId ?: (int) $inv->centre_id;
            } else {
                $ext = DB::table('external_invoices')->where('id', $it['id'])->first(['id', 'agency_id', 'family_id', 'number', 'balance_due', 'status']);
                if (! $ext || ! in_array((int) $ext->family_id, $fams, true)) abort(404, 'That invoice could not be found.');
                if ((float) $ext->balance_due < 0.005 || (string) $ext->status === 'void') continue;
                $items[] = ['kind' => 'ext', 'id' => (int) $ext->id, 'number' => (string) $ext->number, 'amount' => round((float) $ext->balance_due, 2)];
                $a = (int) $ext->agency_id; $f = (int) $ext->family_id;
            }
            if ($agencyId !== null && $agencyId !== $a) abort(422, 'Ask for one centre at a time.');
            $agencyId = $a; $familyId = $familyId ?: $f;
        }
        if (! $items) return response()->json(['message' => 'Nothing is owing on those invoices.'], 422);

        $pay = ParentBilling::payInfo($agencyId);
        if (! ($pay['interac']['requests'] ?? false)) return response()->json(['message' => 'Your centre does not take e-Transfer requests. Use the e-Transfer details instead.'], 422);

        // Only one of the parent's OWN contacts on file - never an address typed in to send money requests to.
        $contact = trim($d['contact']);
        $ok = collect($this->contacts($u))->first(fn ($c) => $c['type'] === $d['contact_type'] && strcasecmp($c['value'], $contact) === 0);
        if (! $ok) return response()->json(['message' => 'Choose one of the contacts on your profile. You can update them in My settings.'], 422);

        // One open request per invoice: asking twice would have the centre send two requests for the same money.
        $keys = array_map(fn ($i) => $i['kind'] . ':' . $i['id'], $items);
        $myGid = DB::table('guardians')->where('user_id', $u->id)->where('family_id', $familyId)->value('id');
        $splitFam = \App\Support\BillingSplit::isSplit((int) $familyId);
        foreach (DB::table('etransfer_requests')->where('family_id', $familyId)->whereIn('status', self::OPEN)->get() as $open) {
            // A split invoice is paid by each guardian for their own share: only the SAME guardian asking twice clashes.
            if ($splitFam && (int) $open->guardian_id !== (int) $myGid) continue;
            foreach ((array) json_decode((string) $open->items, true) as $oi) {
                if (in_array(($oi['kind'] ?? '') . ':' . ($oi['id'] ?? ''), $keys, true)) {
                    return response()->json(['message' => 'You already asked for an e-Transfer request for invoice ' . ($oi['number'] ?? '') . '.', 'request' => $this->present($open, false)], 409);
                }
            }
        }

        if (! $centreId) $centreId = (int) DB::table('families')->where('id', $familyId)->value('centre_id') ?: null;
        $total = round(array_sum(array_column($items, 'amount')), 2);
        $numbers = implode(', ', array_filter(array_column($items, 'number')));
        $id = DB::table('etransfer_requests')->insertGetId([
            'agency_id' => $agencyId, 'centre_id' => $centreId, 'family_id' => $familyId,
            'guardian_id' => DB::table('guardians')->where('user_id', $u->id)->where('family_id', $familyId)->value('id'),
            'user_id' => $u->id, 'items' => json_encode($items), 'numbers' => mb_substr($numbers, 0, 255), 'amount' => $total,
            'contact_type' => $d['contact_type'], 'contact' => $contact, 'status' => 'requested', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('etransfer_requests')->find($id);

        $who = $this->personName($u);
        $this->tellStaff($row, '📨 e-Transfer request wanted · $' . number_format($total, 2),
            ($who ?: 'A family') . ' asked you to send an Interac e-Transfer request for ' . $numbers . ' to ' . $contact . '.');

        return response()->json(['request' => $this->present($row, false), 'message' => 'Requested'], 201);
    }

    /** POST /parent/etransfer-requests/{id}/cancel - the parent changed their mind (or paid another way). */
    public function cancelMine(Request $request, int $id): JsonResponse
    {
        $u = $request->user();
        $row = DB::table('etransfer_requests')->where('id', $id)->whereIn('family_id', $this->families((int) $u->id) ?: [0])->first();
        abort_unless($row, 404);
        if (! in_array($row->status, array_merge(self::OPEN, self::PENDING), true)) return response()->json(['message' => 'That request is already ' . $row->status . '.'], 422);
        DB::table('etransfer_requests')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $u->id, 'updated_at' => now()]);
        \App\Support\EtransferPending::forget();
        if (($row->kind ?? 'request') === 'report') {
            $this->tellStaff($row, 'e-Transfer withdrawn', ($this->personName($u) ?: 'The family') . ' withdrew the e-Transfer they reported for ' . $row->numbers
                . ' (reference ' . $row->reference . '). Nothing to confirm.', self::confirmLink((int) $row->id));
        } else {
            $this->tellStaff($row, 'e-Transfer request cancelled', ($this->personName($u) ?: 'The family') . ' no longer needs the e-Transfer request for ' . $row->numbers . '.');
        }
        return response()->json(['request' => $this->present(DB::table('etransfer_requests')->find($id), false)]);
    }

    /* "I'VE SENT IT" (2026-11-11, Anthony: "add ability to add reference number when parent processes a payment through
       interac etransfer and mark that invoice paid which will trigger an alert/email to admin/director to confirm/approve
       and change statuses on the open invoices accordingly"). He chose:
         - pending until approved: the invoice stays open, labelled "e-Transfer sent, to confirm", and no money is booked
           on the family's word; overdue reminders and late fees pause meanwhile (App\Support\EtransferPending);
         - the full amount or part of it;
         - approved from the alert and email, from the invoice's row in Accounting, and from Accounting -> e-Transfers
           to confirm.
       A report is a row of this table with kind 'report': reported -> paid (approve: the normal record-payment code,
       the bank's reference as the payment reference) | rejected (a reason; the invoice is open again) | cancelled
       (the family withdrew it). */

    /** POST /parent/etransfer-reports {items:[{kind,id}], reference, amount} */
    public function report(Request $request): JsonResponse
    {
        $u = $request->user();
        $d = $request->validate([
            'items' => 'required|array|min:1|max:20',
            'items.*.kind' => 'required|in:inv,ext',
            'items.*.id' => 'required|integer|min:1',
            'reference' => 'required|string|max:64',
            'amount' => 'required|numeric|min:0.01|max:1000000',
        ]);
        $ref = preg_replace('/\s+/', '', (string) $d['reference']);
        if (! preg_match('/^[A-Za-z0-9-]{4,40}$/', $ref)) {
            return response()->json(['message' => 'Enter the reference number exactly as your bank shows it (letters and numbers).',
                'errors' => ['reference' => ['Check the reference number.']]], 422);
        }
        abort_unless($this->families((int) $u->id), 403, 'Only a parent or guardian can report a payment.');
        [$items, $agencyId, $familyId, $centreId] = $this->parentItems($u, $d['items']);
        if (! $items) return response()->json(['message' => 'Nothing is owing on those invoices.'], 422);
        $pay = ParentBilling::payInfo($agencyId);
        if (empty($pay['interac'])) return response()->json(['message' => 'Your centre does not take Interac e-Transfer.'], 422);

        $owing = round(array_sum(array_column($items, 'amount')), 2);
        $amount = round((float) $d['amount'], 2);
        if ($amount > $owing + 0.004) {
            return response()->json(['message' => 'That is more than the $' . number_format($owing, 2) . ' owing on ' . (count($items) > 1 ? 'these invoices' : 'this invoice') . '.',
                'errors' => ['amount' => ['At most $' . number_format($owing, 2) . '.']]], 422);
        }
        $items = self::allocate($items, $amount);

        // One waiting report per invoice (per guardian when the bill is split).
        $myGid = DB::table('guardians')->where('user_id', $u->id)->where('family_id', $familyId)->value('id');
        $splitFam = \App\Support\BillingSplit::isSplit((int) $familyId);
        $keys = array_map(fn ($i) => $i['kind'] . ':' . $i['id'], $items);
        foreach (DB::table('etransfer_requests')->where('family_id', $familyId)->whereIn('status', self::PENDING)->get() as $open) {
            if ($splitFam && (int) $open->guardian_id !== (int) $myGid) continue;
            foreach ((array) json_decode((string) $open->items, true) as $oi) {
                if (in_array(($oi['kind'] ?? '') . ':' . ($oi['id'] ?? ''), $keys, true)) {
                    return response()->json(['message' => 'You already told us about an e-Transfer for invoice ' . ($oi['number'] ?? '') . '. It is waiting to be confirmed.',
                        'request' => $this->present($open, false)], 409);
                }
            }
        }
        // The same e-Transfer sent in twice would be booked twice.
        if (DB::table('etransfer_requests')->where('agency_id', $agencyId)->where('reference', $ref)->whereIn('status', ['reported', 'paid'])->exists()) {
            return response()->json(['message' => 'That reference number was already sent in. If this is a different e-Transfer, check the number in your bank app.',
                'errors' => ['reference' => ['Already sent in.']]], 409);
        }

        if (! $centreId) $centreId = (int) DB::table('families')->where('id', $familyId)->value('centre_id') ?: null;
        $numbers = implode(', ', array_filter(array_column($items, 'number')));
        $id = DB::table('etransfer_requests')->insertGetId([
            'kind' => 'report', 'agency_id' => $agencyId, 'centre_id' => $centreId, 'family_id' => $familyId, 'guardian_id' => $myGid,
            'user_id' => $u->id, 'items' => json_encode($items), 'numbers' => mb_substr($numbers, 0, 255), 'amount' => $amount,
            'contact_type' => 'email', 'contact' => mb_substr((string) ($u->email ?? ''), 0, 190), 'reference' => $ref,
            'status' => 'reported', 'reported_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        \App\Support\EtransferPending::forget();
        $row = DB::table('etransfer_requests')->find($id);

        $money = '$' . number_format($amount, 2);
        $title = '💸 e-Transfer to confirm · ' . $money;
        $tail = ' sent an Interac e-Transfer of ' . $money . ' for ' . $numbers . ' (reference ' . $ref . '). Check your bank, then approve or reject it.';
        $this->tellStaff($row, $title, ($this->personName($u) ?: 'A family') . ' says they' . $tail, self::confirmLink($id));
        // Email: first names only (standing rule).
        $this->emailStaff($row, $title, (trim((string) ($u->first_name ?? '')) ?: 'A parent') . ' says they' . $tail, self::confirmLink($id), 'Review the e-Transfer');

        try {
            \App\Support\Audit::write([
                'agency_id' => $agencyId, 'user_id' => $u->id, 'action' => 'etransfer.reported',
                'entity_type' => 'family', 'entity_id' => (int) $familyId,
                'payload' => json_encode(['report_id' => $id, 'reference' => $ref, 'amount' => $amount, 'numbers' => $numbers,
                    'summary' => 'Reported sending an Interac e-Transfer of ' . $money . ' for ' . $numbers . ' (reference ' . $ref . '), waiting for the centre to confirm it.']),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* never fail the report over its own audit row */ }

        return response()->json(['request' => $this->present($row, false), 'message' => 'Sent to ' . $this->centreName($row) . ' to confirm'], 201);
    }

    /** POST /parent/etransfer-requests/{id}/demo-pay - the website demo's simulated bank: the parent accepts the request. */
    public function demoPay(Request $request, int $id): JsonResponse
    {
        $u = $request->user();
        abort_unless(PublicDemo::isUser((int) $u->id), 404);
        $row = DB::table('etransfer_requests')->where('id', $id)->where('user_id', $u->id)->first();
        abort_unless($row && PublicDemo::isAgency((int) $row->agency_id), 404);
        if ($row->status === 'paid') return response()->json(['message' => 'This request is already paid.'], 422);
        if ($row->status !== 'sent') return response()->json(['message' => 'The centre has not sent this request yet.'], 422);
        $receipt = $this->demoRecord($row, $u);
        DB::table('etransfer_requests')->where('id', $id)->update(['status' => 'paid', 'paid_at' => now(), 'paid_by' => $u->id, 'updated_at' => now()]);
        return response()->json(['request' => $this->present(DB::table('etransfer_requests')->find($id), false), 'receipt' => $receipt]);
    }

    // ───────────────────────────── staff ─────────────────────────────

    /** GET /billing/etransfer-requests?status=open|all */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        abort_unless($agencyId, 400, 'Select an agency first.');
        $scope = CentreScope::ids($request, $agencyId);
        $q = DB::table('etransfer_requests as r')->leftJoin('families as f', 'f.id', '=', 'r.family_id')->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('centres as c', 'c.id', '=', 'r.centre_id')->where('r.agency_id', $agencyId)->where('r.kind', '!=', 'report');
        if ($scope !== null) $q->whereIn('r.centre_id', $scope ?: [0]);
        $base = clone $q;
        if ($request->query('status', 'open') === 'open') $q->whereIn('r.status', self::OPEN);
        $rows = $q->orderByRaw("FIELD(r.status,'requested','sent','paid','cancelled')")->orderByDesc('r.id')->limit(300)
            ->get(['r.*', 'f.family_name', 'u.first_name', 'u.last_name', 'c.name as centre_name']);
        $counts = $base->selectRaw('r.status, COUNT(*) n')->groupBy('r.status')->pluck('n', 'status');
        return response()->json(['requests' => $rows->map(fn ($r) => $this->present($r, true))->values(), 'counts' => $counts,
            'interac_email' => ParentBilling::payInfo($agencyId)['interac']['email'] ?? null]);
    }

    /** POST /billing/etransfer-requests/{id}/sent */
    public function markSent(Request $request, int $id): JsonResponse
    {
        $row = $this->staffRow($request, $id);
        if ($row->status !== 'requested') return response()->json(['message' => 'That request is already ' . $row->status . '.'], 422);
        DB::table('etransfer_requests')->where('id', $id)->update(['status' => 'sent', 'sent_at' => now(), 'sent_by' => $request->user()->id, 'updated_at' => now()]);
        $this->notifySent($row);
        return response()->json(['request' => $this->present(DB::table('etransfer_requests')->find($id), true)]);
    }

    /** The family is told to look in their bank app; on the website demo, the demo phone gets the bank's request. */
    private function notifySent(object $row): void
    {
        $centre = $this->centreName($row);
        $title = '📨 Your e-Transfer request is on its way';
        $body = 'Check your bank app or ' . ($row->contact_type === 'email' ? 'email' : 'text messages') . ' for the Interac e-Transfer request from ' . $centre
            . ' ($' . number_format((float) $row->amount, 2) . ' for ' . $row->numbers . ').';
        $this->tellParent($row, $title, $body);
        // The website demo: the demo parent's phone gets the bank's request (simulated - see WebDemoPhone).
        if (PublicDemo::isAgency((int) $row->agency_id) && WebDemoPhone::applies((int) $row->agency_id, (int) $row->user_id)) {
            WebDemoPhone::push((int) $row->user_id, 'etransfer', ['request_id' => (int) $row->id, 'from' => $centre, 'amount' => (float) $row->amount,
                'memo' => (string) $row->numbers, 'contact' => (string) $row->contact], 4);
        }
    }

    /** POST /billing/etransfer-requests/{id}/paid - the e-Transfer arrived: record it against each invoice. */
    public function markPaid(Request $request, int $id): JsonResponse
    {
        $row = $this->staffRow($request, $id);
        if (! in_array($row->status, self::OPEN, true)) return response()->json(['message' => 'That request is already ' . $row->status . '.'], 422);
        $recorded = [];
        if (PublicDemo::isAgency((int) $row->agency_id)) {
            $parent = DB::table('users')->where('id', $row->user_id)->first();
            if ($parent) $this->demoRecord($row, $parent);
        } else {
            $res = $this->recordItems($request, $row, (array) json_decode((string) $row->items, true), 'e-Transfer request #' . $row->id,
                'Interac e-Transfer requested by the family (' . $row->contact . ')');
            if ($res instanceof JsonResponse) return $res;
            $recorded = $res;
            $this->tellParent($row, '✅ e-Transfer received - thank you!', $this->centreName($row) . ' received your e-Transfer of $' . number_format((float) $row->amount, 2) . ' for ' . $row->numbers . '.');
        }
        DB::table('etransfer_requests')->where('id', $id)->update(['status' => 'paid', 'paid_at' => now(), 'paid_by' => $request->user()->id, 'updated_at' => now()]);
        return response()->json(['request' => $this->present(DB::table('etransfer_requests')->find($id), true), 'recorded' => $recorded]);
    }

    /** GET /billing/etransfer-reports?status=reported|paid|rejected|cancelled|all&id= - the families' "I've sent it"s. */
    public function reports(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        abort_unless($agencyId, 400, 'Select an agency first.');
        $scope = CentreScope::ids($request, $agencyId);
        $q = DB::table('etransfer_requests as r')->leftJoin('families as f', 'f.id', '=', 'r.family_id')->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->leftJoin('centres as c', 'c.id', '=', 'r.centre_id')->leftJoin('users as pb', 'pb.id', '=', 'r.paid_by')->leftJoin('users as rb', 'rb.id', '=', 'r.rejected_by')
            ->where('r.agency_id', $agencyId)->where('r.kind', 'report');
        if ($scope !== null) $q->whereIn('r.centre_id', $scope ?: [0]);
        $counts = (clone $q)->selectRaw('r.status, COUNT(*) n')->groupBy('r.status')->pluck('n', 'status');
        if ($request->filled('id')) {
            $q->where('r.id', (int) $request->query('id'));
        } elseif (($st = (string) $request->query('status', 'reported')) !== 'all') {
            $q->where('r.status', in_array($st, ['reported', 'paid', 'rejected', 'cancelled'], true) ? $st : 'reported');
        }
        $rows = $q->orderByRaw("FIELD(r.status,'reported','paid','rejected','cancelled')")->orderByDesc('r.id')->limit(300)
            ->get(['r.*', 'f.family_name', 'u.first_name', 'u.last_name', 'c.name as centre_name', 'pb.first_name as paid_by_name', 'rb.first_name as rejected_by_name']);
        return response()->json(['reports' => $rows->map(fn ($r) => $this->present($r, true))->values(), 'counts' => $counts]);
    }

    /** POST /billing/etransfer-reports/{id}/approve {amount?} - the money is in the bank: record it on each invoice. */
    public function approve(Request $request, int $id): JsonResponse
    {
        $row = $this->staffRow($request, $id);
        abort_unless(($row->kind ?? 'request') === 'report', 404, 'That e-Transfer no longer exists.');
        if ($row->status !== 'reported') return response()->json(['message' => 'That e-Transfer is already ' . ($row->status === 'paid' ? 'approved' : $row->status) . '.'], 422);
        $d = $request->validate(['amount' => 'nullable|numeric|min:0.01|max:1000000']);
        $amount = round((float) ($d['amount'] ?? $row->amount), 2);

        // What is owing on those invoices NOW (a payment may have come in another way since).
        $items = [];
        foreach ((array) json_decode((string) $row->items, true) as $it) {
            $isExt = ($it['kind'] ?? '') === 'ext';
            $cur = round((float) ($isExt ? DB::table('external_invoices')->where('id', $it['id'])->value('balance_due') : DB::table('invoices')->where('id', $it['id'])->value('balance_due')), 2);
            if ($cur >= 0.01) $items[] = ['kind' => $it['kind'], 'id' => (int) $it['id'], 'number' => $it['number'] ?? null, 'amount' => $cur];
        }
        $owing = round(array_sum(array_column($items, 'amount')), 2);
        if ($owing < 0.01) return response()->json(['message' => 'Nothing is owing on ' . $row->numbers . ' any more. If it was paid another way, reject this one with a note.'], 422);
        if ($amount > $owing + 0.004) return response()->json(['message' => 'Only $' . number_format($owing, 2) . ' is owing on ' . $row->numbers . ' now. Approve that much, or record the rest as a credit.'], 422);

        $recorded = [];
        if (PublicDemo::isAgency((int) $row->agency_id)) {
            $parent = DB::table('users')->where('id', $row->user_id)->first();
            if ($parent) $this->demoRecord($row, $parent);
        } else {
            $res = $this->recordItems($request, $row, self::allocate($items, $amount), 'Interac ' . $row->reference,
                'Interac e-Transfer reported by the family (reference ' . $row->reference . '), confirmed in KiddieTrac');
            if ($res instanceof JsonResponse) return $res;
            $recorded = $res;
        }
        DB::table('etransfer_requests')->where('id', $id)->update(['status' => 'paid', 'paid_at' => now(), 'paid_by' => $request->user()->id,
            'approved_amount' => $amount, 'updated_at' => now()]);
        \App\Support\EtransferPending::forget();
        $this->tellParent($row, '✅ e-Transfer confirmed - thank you!', $this->centreName($row) . ' received your e-Transfer of $' . number_format($amount, 2)
            . ' for ' . $row->numbers . ' (reference ' . $row->reference . ').');
        try {
            \App\Support\Audit::write([
                'agency_id' => (int) $row->agency_id, 'user_id' => $request->user()->id, 'action' => 'etransfer.approved',
                'entity_type' => 'family', 'entity_id' => (int) $row->family_id,
                'payload' => json_encode(['report_id' => $id, 'reference' => $row->reference, 'amount' => $amount, 'recorded' => $recorded,
                    'summary' => 'Approved the family\'s Interac e-Transfer of $' . number_format($amount, 2) . ' for ' . $row->numbers . ' (reference ' . $row->reference . ') and recorded the payment.']),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {}
        return response()->json(['report' => $this->present(DB::table('etransfer_requests')->find($id), true), 'recorded' => $recorded]);
    }

    /** POST /billing/etransfer-reports/{id}/reject {reason} - not in the bank: the invoice is open again; the family is told why. */
    public function reject(Request $request, int $id): JsonResponse
    {
        $row = $this->staffRow($request, $id);
        abort_unless(($row->kind ?? 'request') === 'report', 404, 'That e-Transfer no longer exists.');
        if ($row->status !== 'reported') return response()->json(['message' => 'That e-Transfer is already ' . ($row->status === 'paid' ? 'approved' : $row->status) . '.'], 422);
        $d = $request->validate(['reason' => 'required|string|max:255']);
        $reason = trim(strip_tags($d['reason']));
        DB::table('etransfer_requests')->where('id', $id)->update(['status' => 'rejected', 'rejected_at' => now(), 'rejected_by' => $request->user()->id,
            'reject_reason' => $reason, 'updated_at' => now()]);
        \App\Support\EtransferPending::forget();
        $centre = $this->centreName($row);
        $title = 'e-Transfer not confirmed';
        $body = $centre . ' could not confirm your e-Transfer of $' . number_format((float) $row->amount, 2) . ' for ' . $row->numbers
            . ' (reference ' . $row->reference . '): ' . rtrim($reason, '.') . '. The invoice is open again - please check with your bank or contact ' . $centre . '.';
        $this->tellParent($row, '⚠️ ' . $title, $body);
        $this->emailParent($row, $title, $body);
        try {
            \App\Support\Audit::write([
                'agency_id' => (int) $row->agency_id, 'user_id' => $request->user()->id, 'action' => 'etransfer.rejected',
                'entity_type' => 'family', 'entity_id' => (int) $row->family_id,
                'payload' => json_encode(['report_id' => $id, 'reference' => $row->reference, 'amount' => (float) $row->amount, 'reason' => $reason,
                    'summary' => 'Rejected the family\'s Interac e-Transfer of $' . number_format((float) $row->amount, 2) . ' for ' . $row->numbers . ' (reference ' . $row->reference . '): ' . $reason]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {}
        return response()->json(['report' => $this->present(DB::table('etransfer_requests')->find($id), true)]);
    }

    /** POST /billing/etransfer-requests/{id}/cancel */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $row = $this->staffRow($request, $id);
        if (! in_array($row->status, self::OPEN, true)) return response()->json(['message' => 'That request is already ' . $row->status . '.'], 422);
        DB::table('etransfer_requests')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $request->user()->id, 'updated_at' => now()]);
        $this->tellParent($row, 'e-Transfer request cancelled', $this->centreName($row) . ' cancelled the e-Transfer request for ' . $row->numbers . '. You can still pay with the e-Transfer details on your invoice.');
        return response()->json(['request' => $this->present(DB::table('etransfer_requests')->find($id), true)]);
    }

    // ─────────────────── staff, from the invoice (Accounting ⋮ menu) ───────────────────
    /* SENDING ONE IS AN ACT ON AN INVOICE (2026-11-02, Anthony chose "remove the section, add a kebab action").
       The "e-Transfer requests" list screen is gone; Accounting's invoice row menu has "Send e-Transfer request".
       The popup shows what the bank's "Request money" form asks for (who, where, how much, the memo), and
       "Mark as requested" records it here, so the row reads "e-Transfer requested Nov 2 by Safia". A family's own
       ask (parent POST above) is the same row: marking the invoice settles it as sent.
         GET  /billing/etransfer/{kind}/{id}             kind = kt (a KiddieTrac invoice) | ext (a synced one)
         POST /billing/etransfer/{kind}/{id}/requested  {guardian_id, contact_type, contact} */

    public function forInvoice(Request $request, string $kind, int $id): JsonResponse
    {
        [$agencyId, $inv] = $this->staffInvoice($request, $kind, $id);
        $pay = ParentBilling::payInfo($agencyId);
        $rows = $this->rowsFor($agencyId, $kind, $id)->sortByDesc('id')->values();
        return response()->json([
            'invoice' => ['kind' => $kind, 'id' => (int) $inv->id, 'number' => (string) $inv->number, 'family' => $inv->family_name,
                'total' => (float) $inv->total, 'balance_due' => round((float) $inv->balance_due, 2), 'status' => (string) $inv->status],
            'guardians' => $this->billingGuardians((int) $inv->family_id),
            'interac' => $pay['interac'] ?? null,
            'note' => self::notes($agencyId, $kind === 'kt' ? [$id] : [], $kind === 'ext' ? [$id] : [])[$kind . ':' . $id] ?? null,
            'requests' => $rows->map(fn ($r) => $this->present($r, false))->values(),
        ]);
    }

    public function markInvoiceRequested(Request $request, string $kind, int $id): JsonResponse
    {
        [$agencyId, $inv] = $this->staffInvoice($request, $kind, $id);
        $d = $request->validate([
            'guardian_id' => 'required|integer|min:1',
            'contact_type' => 'required|in:email,phone',
            'contact' => 'required|string|max:190',
        ]);
        if ((float) $inv->balance_due < 0.005 || in_array((string) $inv->status, ['paid', 'void', 'draft', 'refunded'], true)) {
            return response()->json(['message' => 'Nothing is owing on ' . $inv->number . '.'], 422);
        }
        // Only a billing guardian of THIS family, and only a contact on their profile - never a typed-in address.
        $g = collect($this->billingGuardians((int) $inv->family_id))->firstWhere('guardian_id', (int) $d['guardian_id']);
        abort_unless($g, 422, 'Choose one of the family\'s billing contacts.');
        $contact = trim($d['contact']);
        $ok = collect($g['contacts'])->first(fn ($c) => $c['type'] === $d['contact_type'] && strcasecmp($c['value'], $contact) === 0);
        if (! $ok) return response()->json(['message' => 'Choose one of the contacts on file for ' . $g['name'] . '.'], 422);

        // A split invoice is owed in shares: the request is for this guardian's own share.
        $amt = round((float) $inv->balance_due, 2);
        if ($kind === 'kt' && \App\Support\BillingSplit::isSplit((int) $inv->family_id)) {
            $full = DB::table('invoices')->where('id', $inv->id)->first();
            if ($full && ($mine = \App\Support\BillingSplit::shareFor($full, (int) $g['guardian_id']))) $amt = round(min($amt, (float) $mine['balance']), 2);
            if ($amt < 0.005) return response()->json(['message' => $g['name'] . '\'s share of ' . $inv->number . ' is paid.'], 422);
        }

        $me = $request->user();
        $open = $this->rowsFor($agencyId, $kind, $id)->filter(fn ($r) => in_array($r->status, self::OPEN, true))
            ->filter(fn ($r) => ! \App\Support\BillingSplit::isSplit((int) $inv->family_id) || (int) $r->guardian_id === (int) $g['guardian_id']);
        if ($sent = $open->firstWhere('status', 'sent')) {
            return response()->json(['message' => 'Already marked as requested. ' . (self::notes($agencyId, $kind === 'kt' ? [$id] : [], $kind === 'ext' ? [$id] : [])[$kind . ':' . $id] ?? '')], 409);
        }
        $fields = ['status' => 'sent', 'sent_at' => now(), 'sent_by' => $me->id, 'contact_type' => $d['contact_type'], 'contact' => $contact, 'updated_at' => now()];
        if ($asked = $open->firstWhere('status', 'requested')) {
            // The family asked first: this is that request, now sent.
            DB::table('etransfer_requests')->where('id', $asked->id)->update($fields);
            $rid = (int) $asked->id;
        } else {
            $centreId = $inv->centre_id ?: (DB::table('families')->where('id', $inv->family_id)->value('centre_id') ?: null);
            $rid = DB::table('etransfer_requests')->insertGetId($fields + [
                'agency_id' => $agencyId, 'centre_id' => $centreId, 'family_id' => (int) $inv->family_id,
                'guardian_id' => (int) $g['guardian_id'], 'user_id' => (int) $g['user_id'],
                'items' => json_encode([['kind' => $kind === 'kt' ? 'inv' : 'ext', 'id' => (int) $inv->id, 'number' => (string) $inv->number, 'amount' => $amt]]),
                'numbers' => mb_substr((string) $inv->number, 0, 255), 'amount' => $amt, 'created_at' => now(),
            ]);
        }
        $row = DB::table('etransfer_requests')->find($rid);
        $this->notifySent($row);

        try {
            \App\Support\Audit::write([
                'agency_id' => $agencyId, 'user_id' => $me->id, 'action' => 'etransfer.request_sent',
                'entity_type' => $kind === 'kt' ? 'invoice' : 'external_invoice', 'entity_id' => (int) $inv->id,
                'payload' => json_encode([
                    'number' => (string) $inv->number, 'family_id' => (int) $inv->family_id, 'amount' => (float) $row->amount,
                    'guardian' => $g['name'], 'contact' => $contact, 'family_asked' => (bool) $asked,
                    'summary' => 'Sent an Interac e-Transfer request for ' . $inv->number . ' ($' . number_format((float) $row->amount, 2) . ') to '
                        . $g['name'] . ' at ' . $contact . ($asked ? ', which the family had asked for' : '') . '. Sent from the centre\'s bank; KiddieTrac records it.',
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* never fail the request over its own audit row */ }

        return response()->json(['request' => $this->present($row, false),
            'note' => self::notes($agencyId, $kind === 'kt' ? [$id] : [], $kind === 'ext' ? [$id] : [])[$kind . ':' . $id] ?? null]);
    }

    /**
     * The line an invoice shows under its status, keyed "kt:{id}" / "ext:{id}":
     *   "e-Transfer requested Nov 2 by Safia"   (sent from the centre's bank)
     *   "Family asked for an e-Transfer request Nov 2"   (waiting on the centre)
     * Open requests only - once paid or cancelled the invoice's own status says the rest.
     */
    public static function notes(int $agencyId, array $ktIds, array $extIds): array
    {
        $want = [];
        foreach ($ktIds as $i) $want['inv:' . (int) $i] = 'kt:' . (int) $i;
        foreach ($extIds as $i) $want['ext:' . (int) $i] = 'ext:' . (int) $i;
        if (! $want || ! Schema::hasTable('etransfer_requests')) return [];
        $hasRef = Schema::hasColumn('etransfer_requests', 'reference');
        $rows = DB::table('etransfer_requests as r')->leftJoin('users as u', 'u.id', '=', 'r.sent_by')
            ->where('r.agency_id', $agencyId)->whereIn('r.status', $hasRef ? array_merge(self::OPEN, self::PENDING) : self::OPEN)->orderBy('r.id')
            ->get(array_merge(['r.id', 'r.status', 'r.items', 'r.created_at', 'r.sent_at', 'u.first_name'], $hasRef ? ['r.reference'] : []));
        if ($rows->isEmpty()) return [];
        $tz = (string) DB::table('agencies')->where('id', $agencyId)->value('timezone') ?: 'America/Toronto';
        $day = fn ($v) => \Carbon\Carbon::parse($v, 'UTC')->setTimezone($tz)->format('M j');
        $out = [];
        foreach ($rows as $r) {
            foreach ((array) json_decode((string) $r->items, true) as $it) {
                $k = $want[($it['kind'] ?? '') . ':' . (int) ($it['id'] ?? 0)] ?? null;
                if (! $k) continue;
                // The family says they paid (2026-11-11): that is what staff need to act on, above any request.
                if ($r->status === 'reported') {
                    $out[$k] = ['status' => 'reported', 'report_id' => (int) $r->id,
                        'label' => 'e-Transfer sent ' . $day($r->created_at) . ' · ref ' . ($r->reference ?? '') . ' · to confirm'];
                    continue;
                }
                if (($out[$k]['status'] ?? null) === 'reported') continue;
                // A sent request outranks a family's ask on the same invoice (split billing can have both).
                if ($r->status === 'sent') {
                    $out[$k] = ['status' => 'sent', 'label' => 'e-Transfer requested ' . $day($r->sent_at ?: $r->created_at)
                        . ($r->first_name ? ' by ' . $r->first_name : '')];
                } elseif (($out[$k]['status'] ?? null) !== 'sent') {
                    $out[$k] = ['status' => 'requested', 'label' => 'Family asked for an e-Transfer request ' . $day($r->created_at)];
                }
            }
        }
        return $out;
    }

    /** [agencyId, invoice] for staff - the invoice must be in the ACTIVE agency and the caller's centres, or 404. */
    private function staffInvoice(Request $request, string $kind, int $id): array
    {
        $agencyId = $this->resolveAgencyId($request);
        abort_unless($agencyId, 400, 'Select an agency first.');
        if ($kind === 'kt') {
            $inv = DB::table('invoices as i')->join('centres as c', 'c.id', '=', 'i.centre_id')->leftJoin('families as f', 'f.id', '=', 'i.family_id')
                ->where('i.id', $id)->where('c.agency_id', $agencyId)
                ->first(['i.id', 'i.family_id', 'i.centre_id', 'i.invoice_number as number', 'i.total', 'i.balance_due', 'i.status', 'f.family_name']);
        } else {
            $inv = DB::table('external_invoices as e')->leftJoin('families as f', 'f.id', '=', 'e.family_id')
                ->where('e.id', $id)->where('e.agency_id', $agencyId)
                ->first(['e.id', 'e.family_id', 'f.centre_id', 'e.number', 'e.total', 'e.balance_due', 'e.status', 'f.family_name']);
        }
        abort_unless($inv && $inv->family_id, 404, 'That invoice no longer exists.');
        abort_unless(CentreScope::canRead(CentreScope::ids($request, $agencyId), $inv->centre_id), 404, 'That invoice no longer exists.');
        return [(int) $agencyId, $inv];
    }

    /** This agency's requests that include the invoice. */
    private function rowsFor(int $agencyId, string $kind, int $id)
    {
        $key = ($kind === 'kt' ? 'inv' : 'ext') . ':' . $id;
        return DB::table('etransfer_requests')->where('agency_id', $agencyId)->where('items', 'like', '%"id":' . $id . '%')->get()
            ->filter(fn ($r) => collect((array) json_decode((string) $r->items, true))->contains(fn ($it) => (($it['kind'] ?? '') . ':' . (int) ($it['id'] ?? 0)) === $key))
            ->values();
    }

    /** The family's guardians who receive billing, each with the email and phones on their profile. */
    private function billingGuardians(int $familyId): array
    {
        $q = fn () => DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')->where('g.family_id', $familyId)
            ->where(fn ($w) => $w->whereNull('g.billing_hidden')->orWhere('g.billing_hidden', 0))->orderByDesc('g.is_primary')->orderBy('g.id');
        $rows = $q()->where('g.can_receive_billing', 1)->get(['g.id', 'g.user_id', 'g.relationship', 'g.is_primary', 'u.first_name', 'u.last_name', 'u.email', 'u.phone', 'u.phone2']);
        if ($rows->isEmpty()) $rows = $q()->where('g.is_primary', 1)->get(['g.id', 'g.user_id', 'g.relationship', 'g.is_primary', 'u.first_name', 'u.last_name', 'u.email', 'u.phone', 'u.phone2']);
        return $rows->map(fn ($r) => ['guardian_id' => (int) $r->id, 'user_id' => (int) $r->user_id, 'name' => $this->personName($r) ?: 'Guardian',
            'relationship' => $r->relationship, 'primary' => (bool) $r->is_primary, 'contacts' => $this->contacts($r)])->values()->all();
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /** @return int[] the families this person is a guardian of */
    private function families(int $userId): array
    {
        return DB::table('guardians')->where('user_id', $userId)->pluck('family_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** The parent's own email and mobile numbers - the only places a request may go. */
    private function contacts(object $u): array
    {
        $out = [];
        $e = trim((string) ($u->email ?? ''));
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = ['type' => 'email', 'value' => $e, 'label' => $e];
        foreach (['phone', 'phone2'] as $k) {
            $p = trim((string) ($u->{$k} ?? ''));
            if (strlen(preg_replace('/\D/', '', $p)) >= 10 && ! collect($out)->contains(fn ($c) => $c['value'] === $p)) $out[] = ['type' => 'phone', 'value' => $p, 'label' => $p];
        }
        return $out;
    }

    private function personName(object $u): string
    {
        return trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
    }

    private function centreName(object $row): string
    {
        return (string) (($row->centre_id ? DB::table('centres')->where('id', $row->centre_id)->value('name') : null)
            ?: DB::table('agencies')->where('id', $row->agency_id)->value('name') ?: 'your centre');
    }

    private function staffRow(Request $request, int $id): object
    {
        $agencyId = $this->resolveAgencyId($request);
        abort_unless($agencyId, 400, 'Select an agency first.');
        $row = DB::table('etransfer_requests')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($row, 404, 'That request no longer exists.');
        abort_unless(CentreScope::canRead(CentreScope::ids($request, $agencyId), $row->centre_id), 404, 'That request no longer exists.');
        return $row;
    }

    /** Agency admins and the family's centre director (the website demo's people only, on the demo). */
    private function staffIds(object $row)
    {
        $ids = DB::table('role_assignments')->where('agency_id', $row->agency_id)->where('active', 1)
            ->where(fn ($q) => $q->where('role', 'agency_admin')->orWhere(fn ($w) => $w->where('role', 'centre_director')->where('centre_id', $row->centre_id)))
            ->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values();
        if (PublicDemo::isAgency((int) $row->agency_id)) $ids = $ids->filter(fn ($uid) => PublicDemo::isUser($uid))->values();
        return $ids;
    }

    /** Bell + push to staffIds(). */
    private function tellStaff(object $row, string $title, string $body, ?string $link = null): void
    {
        $ids = $this->staffIds($row);
        // Opens Accounting with that invoice's "Send e-Transfer request" popup (the old list screen is gone, 2026-11-02).
        $first = ((array) json_decode((string) $row->items, true))[0] ?? [];
        $link = $link ?: '#external-billing' . (! empty($first['id']) ? (($first['kind'] ?? '') === 'ext' ? '?ext=' : '?invoice=') . (int) $first['id'] : '');
        $rows = [];
        foreach ($ids as $uid) {
            $rows[] = ['user_id' => $uid, 'type' => 'payment', 'title' => $title, 'body' => $body,
                'data' => json_encode(['link' => $link, 'etransfer_request_id' => (int) $row->id]), 'created_at' => now()];
        }
        try { if ($rows) Notify::write($rows); } catch (\Throwable $e) {}
        foreach ($ids as $uid) {
            try { app(\App\Services\FcmService::class)->sendToUser($uid, $title, $body, $link); } catch (\Throwable $e) {}
        }
    }

    private function tellParent(object $row, string $title, string $body): void
    {
        try {
            Notify::write(['user_id' => (int) $row->user_id, 'type' => 'payment', 'title' => $title, 'body' => $body,
                'data' => json_encode(['link' => '#billing', 'etransfer_request_id' => (int) $row->id]), 'created_at' => now()]);
        } catch (\Throwable $e) {}
        try { app(\App\Services\FcmService::class)->sendToUser((int) $row->user_id, $title, $body, '#billing'); } catch (\Throwable $e) {}
    }

    /** Accounting -> e-Transfers to confirm, with this one's popup open. */
    private static function confirmLink(int $id): string
    {
        return '#external-billing?etconfirm=' . $id;
    }

    /** The app's address for this agency (its own brand's), opening $hash. */
    private static function appUrl(int $agencyId, string $hash): string
    {
        return preg_replace('/#.*$/', '', \App\Support\InvoiceReminders::payLink($agencyId)) . $hash;
    }

    /** The same people as tellStaff, by email (Anthony: "an alert/email to admin/director"). First names only. */
    private function emailStaff(object $row, string $title, string $text, string $hash, string $button): void
    {
        if (PublicDemo::isAgency((int) $row->agency_id)) return;   // the website demo's people have no inboxes
        $agencyId = (int) $row->agency_id;
        $url = self::appUrl($agencyId, $hash);
        foreach (DB::table('users')->whereIn('id', $this->staffIds($row)->all() ?: [0])->whereNull('deleted_at')->get(['email', 'first_name']) as $s) {
            if (! filter_var((string) $s->email, FILTER_VALIDATE_EMAIL)) continue;
            $first = trim((string) $s->first_name);
            $html = \App\Services\EmailTemplate::wrap($agencyId,
                '<p style="margin:0 0 12px;font-size:15px;line-height:1.6;">' . ($first !== '' ? 'Hi ' . e($first) . ',' : 'Hello,') . '</p>'
                . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;">' . e($text) . '</p>'
                . \App\Services\EmailTemplate::button($button, $url),
                ['eyebrow' => 'BILLING', 'title' => $title, 'subtitle' => 'Interac e-Transfer', 'preheader' => $text]);
            $to = (string) $s->email;
            dispatch(function () use ($agencyId, $to, $first, $html, $title) {
                \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($agencyId, $to, $first, $title) {
                    \App\Support\MailScope::agency($m, $agencyId);
                    $m->to($to, $first ?: null)->subject($title);
                });
            })->onQueue('mail');
        }
    }

    /** The family member who reported it, by email too: a rejection is money they still owe. */
    private function emailParent(object $row, string $title, string $text): void
    {
        if (PublicDemo::isAgency((int) $row->agency_id)) return;
        $u = DB::table('users')->where('id', $row->user_id)->whereNull('deleted_at')->first(['email', 'first_name']);
        if (! $u || ! filter_var((string) $u->email, FILTER_VALIDATE_EMAIL)) return;
        $agencyId = (int) $row->agency_id;
        $first = trim((string) $u->first_name);
        $html = \App\Services\EmailTemplate::wrap($agencyId,
            '<p style="margin:0 0 12px;font-size:15px;line-height:1.6;">' . ($first !== '' ? 'Hi ' . e($first) . ',' : 'Hello,') . '</p>'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;">' . e($text) . '</p>'
            . \App\Services\EmailTemplate::button('Open Billing', \App\Support\InvoiceReminders::payLink($agencyId)),
            ['eyebrow' => 'BILLING', 'title' => $title, 'subtitle' => 'Interac e-Transfer', 'preheader' => $text]);
        $to = (string) $u->email;
        dispatch(function () use ($agencyId, $to, $first, $html, $title) {
            \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $first, $title) {
                \App\Support\MailScope::billing($m);
                $m->to($to, $first ?: null)->subject($title);
            });
        })->onQueue('mail');
    }

    /** The parent's own unpaid invoices among $in: [items, agencyId, familyId, centreId] (the same checks as create()). */
    private function parentItems(object $u, array $in): array
    {
        $fams = $this->families((int) $u->id);
        $items = []; $agencyId = null; $familyId = null; $centreId = null;
        foreach ($in as $it) {
            if ($it['kind'] === 'inv') {
                $inv = DB::table('invoices as i')->join('centres as c', 'c.id', '=', 'i.centre_id')->where('i.id', $it['id'])
                    ->first(['i.id', 'i.family_id', 'i.centre_id', 'i.invoice_number', 'i.balance_due', 'i.status', 'c.agency_id']);
                if (! $inv || ! in_array((int) $inv->family_id, $fams, true)) abort(404, 'That invoice could not be found.');
                if ((float) $inv->balance_due < 0.005 || in_array($inv->status, ['paid', 'void', 'draft', 'refunded'], true)) continue;
                $amt = round((float) $inv->balance_due, 2);
                // Split billing: the parent's own share of the invoice.
                if ($myG = \App\Support\BillingSplit::guardianFor((int) $u->id, (int) $inv->family_id)) {
                    $full = DB::table('invoices')->where('id', $inv->id)->first();
                    if ($full && ($mine = \App\Support\BillingSplit::shareFor($full, $myG))) $amt = round(min($amt, (float) $mine['balance']), 2);
                }
                if ($amt < 0.005) continue;
                $items[] = ['kind' => 'inv', 'id' => (int) $inv->id, 'number' => (string) $inv->invoice_number, 'amount' => $amt];
                $a = (int) $inv->agency_id; $f = (int) $inv->family_id; $centreId = $centreId ?: (int) $inv->centre_id;
            } else {
                $ext = DB::table('external_invoices')->where('id', $it['id'])->first(['id', 'agency_id', 'family_id', 'number', 'balance_due', 'status']);
                if (! $ext || ! in_array((int) $ext->family_id, $fams, true)) abort(404, 'That invoice could not be found.');
                if ((float) $ext->balance_due < 0.005 || in_array((string) $ext->status, ['void', 'paid'], true)) continue;
                $items[] = ['kind' => 'ext', 'id' => (int) $ext->id, 'number' => (string) $ext->number, 'amount' => round((float) $ext->balance_due, 2)];
                $a = (int) $ext->agency_id; $f = (int) $ext->family_id;
            }
            if ($agencyId !== null && $agencyId !== $a) abort(422, 'One centre at a time, please.');
            $agencyId = $a; $familyId = $familyId ?: $f;
        }
        return [$items, $agencyId, $familyId, $centreId];
    }

    /** $amount spread over the invoices in order, each up to what it owes; invoices it does not reach drop out. */
    private static function allocate(array $items, float $amount): array
    {
        $left = round($amount, 2);
        $out = [];
        foreach ($items as $it) {
            if ($left < 0.01) break;
            $take = round(min($left, (float) $it['amount']), 2);
            $out[] = array_merge($it, ['amount' => $take]);
            $left = round($left - $take, 2);
        }
        return $out;
    }

    /**
     * Record each item through the normal record-payment code (InvoiceController), as if typed into "Record payment":
     * totals, status, receipts, notices. Never more than the invoice owes now. What was recorded, or the error response.
     */
    private function recordItems(Request $request, object $row, array $items, string $reference, string $notes)
    {
        $recorded = [];
        $ctl = app(InvoiceController::class);
        $me = $request->user();
        foreach ($items as $it) {
            $isExt = ($it['kind'] ?? '') === 'ext';
            $cur = $isExt ? DB::table('external_invoices')->where('id', $it['id'])->value('balance_due') : DB::table('invoices')->where('id', $it['id'])->value('balance_due');
            $amt = round(min((float) $cur, (float) ($it['amount'] ?? $cur)), 2);
            if ($amt < 0.01) continue;
            $sub = Request::create('/', 'POST', ['amount' => $amt, 'method' => 'e_transfer', 'reference' => $reference,
                'payer_guardian_id' => $isExt ? null : ($row->guardian_id ?: null), 'notes' => $notes]);
            $sub->headers->set('X-Active-Agency-Id', (string) $row->agency_id);
            $sub->setUserResolver(fn () => $request->user());
            // The access checks read the bound request (request()->header) as well as the one passed in.
            $outer = app('request'); app()->instance('request', $sub);
            $sub->setUserResolver(fn () => $request->user());   // rebinding 'request' resets the resolver to the guard's
            try { $res = $isExt ? $ctl->recordExternalPayment($sub, (int) $it['id']) : $ctl->recordPayment($sub, (int) $it['id']); }
            finally { app()->instance('request', $outer); $outer->setUserResolver(fn () => $me); $request->setUserResolver(fn () => $me); }
            if ($res->getStatusCode() >= 300) {
                return response()->json(['message' => 'Invoice ' . ($it['number'] ?? '') . ': ' . ($res->getData(true)['message'] ?? 'could not record the payment.'), 'recorded' => $recorded], 422);
            }
            $recorded[] = ['number' => $it['number'] ?? null, 'amount' => $amt];
        }
        return $recorded;
    }

    /** The website demo: the request's own invoices paid through the simulated payment (undone nightly). */
    private function demoRecord(object $row, object $parent): ?array
    {
        $ids = [];
        foreach ((array) json_decode((string) $row->items, true) as $it) {
            if (($it['kind'] ?? '') === 'inv' && (float) DB::table('invoices')->where('id', $it['id'])->value('balance_due') > 0.004) $ids[] = (int) $it['id'];
        }
        if (! $ids) return null;
        return WebDemoPayments::record((int) $row->family_id, $ids, null, 'interac', (int) $parent->id);
    }

    private array $tzs = [];
    private function tzOf(int $agencyId): string
    {
        return $this->tzs[$agencyId] ??= ((string) DB::table('agencies')->where('id', $agencyId)->value('timezone') ?: 'America/Toronto');
    }

    private function present(object $r, bool $staff): array
    {
        $o = [
            'id' => (int) $r->id, 'status' => $r->status, 'amount' => (float) $r->amount, 'numbers' => $r->numbers,
            'items' => json_decode((string) $r->items, true) ?: [], 'contact_type' => $r->contact_type, 'contact' => $r->contact,
            'requested_at' => (string) $r->created_at, 'sent_at' => $r->sent_at, 'paid_at' => $r->paid_at, 'cancelled_at' => $r->cancelled_at,
            // A family's "I've sent it" (2026-11-11).
            'kind' => $r->kind ?? 'request', 'reference' => $r->reference ?? null, 'reject_reason' => $r->reject_reason ?? null,
            'approved_amount' => isset($r->approved_amount) ? (float) $r->approved_amount : null,
        ];
        // Shown on the AGENCY's clock (stored UTC), never the viewer's browser.
        $tz = $this->tzOf((int) $r->agency_id);
        foreach (['requested' => $r->created_at, 'sent' => $r->sent_at, 'paid' => $r->paid_at, 'cancelled' => $r->cancelled_at,
            'reported' => $r->reported_at ?? null, 'rejected' => $r->rejected_at ?? null] as $k => $v) {
            $o[$k . '_label'] = $v ? \Carbon\Carbon::parse($v, 'UTC')->setTimezone($tz)->format('M j, g:i A') : null;
        }
        if ($staff) {
            $o['family'] = $r->family_name ?? null;
            $o['parent'] = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: null;
            $o['centre'] = $r->centre_name ?? null;
            $o['family_id'] = (int) $r->family_id;
            $o['paid_by_name'] = $r->paid_by_name ?? null;
            $o['rejected_by_name'] = $r->rejected_by_name ?? null;
        }
        return $o;
    }
}
