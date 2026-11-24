<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PlatformBilling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC'S OWN SALES (2026-10-24). Platform only — never an agency's figures.
 *  - quotes(): every proposal, with its lead, tracking and outcome, plus stats and trends
 *    (Anthony: "something under sales to look at all of the quotes/proposals - full table with
 *    kebab and stats cards/graphs").
 *  - platform(): sales reporting by part number, services, plans, add-ons, total revenue
 *    (Anthony: "under reports add sales reports based on part#, services, total revenue etc
 *    (this is for kiddietrac platform only and not for agencies)").
 */
class SalesReportsController extends Controller
{
    private function months(int $n): array
    {
        $out = [];
        for ($i = $n - 1; $i >= 0; $i--) $out[] = now()->startOfMonth()->subMonths($i)->format('Y-m');
        return $out;
    }

    /** GET /sales/quotes-overview */
    public function quotes(Request $r): JsonResponse
    {
        $rows = DB::table('sales_quotes as q')
            ->leftJoin('sales_leads as l', 'l.id', '=', 'q.lead_id')
            ->leftJoin('users as u', 'u.id', '=', 'q.created_by')
            ->leftJoin('agencies as a', 'a.id', '=', 'q.converted_agency_id')
            ->whereNull('q.deleted_at')
            ->orderByDesc('q.created_at')->limit(2000)
            ->get(['q.id', 'q.lead_id', 'q.number', 'q.title', 'q.status', 'q.approval_status', 'q.total', 'q.subtotal', 'q.currency', 'q.billing_period',
                'q.sent_at', 'q.email_sent_at', 'q.email_open_count', 'q.email_opened_at', 'q.sign_viewed_at', 'q.client_signed_at', 'q.countersigned_at',
                'q.declined_at', 'q.valid_until', 'q.created_at', 'q.contract_name', 'q.converted_agency_id', 'q.followup_next_at',
                'l.name as lead_name', 'l.company as lead_company', 'l.stage as lead_stage',
                'u.first_name as by_first', 'u.last_name as by_last', 'a.name as agency_name']);

        $today = now()->toDateString();
        $state = function ($q) use ($today) {
            if ($q->converted_agency_id) return 'won';
            if ($q->status === 'declined' || $q->approval_status === 'rejected') return 'declined';
            if ($q->countersigned_at || $q->status === 'accepted') return 'signed';
            if ($q->client_signed_at) return 'awaiting_countersign';
            if ($q->status === 'draft') return 'draft';
            if ($q->valid_until && (string) $q->valid_until < $today) return 'expired';
            if ($q->sign_viewed_at || (int) $q->email_open_count > 0) return 'opened';
            return 'sent';
        };
        $list = $rows->map(function ($q) use ($state) {
            return [
                'id' => (int) $q->id, 'lead_id' => (int) $q->lead_id, 'number' => $q->number, 'title' => $q->title,
                'lead' => $q->lead_company ?: $q->lead_name, 'contact' => $q->lead_company ? $q->lead_name : null, 'lead_stage' => $q->lead_stage,
                'state' => $state($q), 'status' => $q->status, 'total' => (float) $q->total, 'currency' => $q->currency ?: 'CAD', 'billing_period' => $q->billing_period,
                'sent_at' => $q->email_sent_at ?: $q->sent_at, 'opens' => (int) $q->email_open_count, 'viewed_at' => $q->sign_viewed_at,
                'signed_at' => $q->client_signed_at, 'countersigned_at' => $q->countersigned_at, 'declined_at' => $q->declined_at,
                'valid_until' => $q->valid_until, 'created_at' => $q->created_at, 'contract' => (bool) $q->contract_name,
                'agency' => $q->agency_name, 'by' => trim(($q->by_first ?? '') . ' ' . ($q->by_last ?? '')) ?: null,
                'followup_next_at' => $q->followup_next_at,
            ];
        })->values();

        $count = fn ($s) => $list->filter(fn ($x) => in_array($x['state'], (array) $s, true))->count();
        $sum = fn ($s) => round($list->filter(fn ($x) => in_array($x['state'], (array) $s, true))->sum('total'), 2);
        $decided = $count(['won', 'signed', 'declined']);
        $months = $this->months(12);
        $byMonth = [];
        foreach ($months as $m) $byMonth[$m] = ['month' => $m, 'created' => 0, 'won' => 0, 'value_won' => 0.0];
        foreach ($list as $x) {
            $m = substr((string) $x['created_at'], 0, 7);
            if (isset($byMonth[$m])) { $byMonth[$m]['created']++; if (in_array($x['state'], ['won', 'signed'], true)) { $byMonth[$m]['won']++; $byMonth[$m]['value_won'] += $x['total']; } }
        }
        return response()->json([
            'quotes' => $list,
            'stats' => [
                'total' => $list->count(), 'open' => $count(['sent', 'opened', 'awaiting_countersign']), 'open_value' => $sum(['sent', 'opened', 'awaiting_countersign']),
                'won' => $count(['won', 'signed']), 'won_value' => $sum(['won', 'signed']), 'declined' => $count('declined'), 'drafts' => $count('draft'), 'expired' => $count('expired'),
                'win_rate' => $decided ? round($count(['won', 'signed']) / $decided * 100) : null,
                'avg_value' => $list->count() ? round($list->avg('total'), 2) : 0,
                'open_rate' => ($s = $list->filter(fn ($x) => $x['sent_at'])->count()) ? round($list->filter(fn ($x) => $x['sent_at'] && ($x['opens'] > 0 || $x['viewed_at']))->count() / $s * 100) : null,
                'awaiting_countersign' => $count('awaiting_countersign'),
            ],
            'by_state' => collect(['draft', 'sent', 'opened', 'awaiting_countersign', 'signed', 'won', 'declined', 'expired'])->map(fn ($s) => ['state' => $s, 'count' => $count($s), 'value' => $sum($s)])->values(),
            'by_month' => array_values($byMonth),
        ]);
    }

    /** GET /platform/sales-report?from=&to= — KiddieTrac's revenue by part number, kind, month and customer. */
    public function platform(Request $r): JsonResponse
    {
        $from = $r->query('from') ?: now()->startOfYear()->toDateString();
        $to = $r->query('to') ?: now()->toDateString();
        $hasLines = Schema::hasTable('platform_invoice_lines');
        $products = DB::table('sales_products')->get()->keyBy('part_number');

        $invoices = DB::table('platform_invoices as pi')->leftJoin('agencies as a', 'a.id', '=', 'pi.agency_id')
            ->where('pi.status', '!=', 'void')->whereNotNull('pi.status')
            ->whereBetween(DB::raw('DATE(COALESCE(pi.issued_at, pi.created_at))'), [$from, $to])
            ->get(['pi.id', 'pi.agency_id', 'pi.number', 'pi.status', 'pi.plan_code', 'pi.subtotal_cents', 'pi.tax_cents', 'pi.amount_cents', 'pi.amount_paid_cents', 'pi.currency',
                'pi.issued_at', 'pi.created_at', 'pi.paid_at', 'a.name as agency_name']);
        $lines = $hasLines && $invoices->count() ? DB::table('platform_invoice_lines')->whereIn('invoice_id', $invoices->pluck('id'))->get()->groupBy('invoice_id') : collect();

        $byPart = []; $byKind = ['plan' => 0, 'addon' => 0, 'service' => 0, 'other' => 0]; $byCustomer = []; $byMonth = [];
        foreach ($this->months(12) as $m) $byMonth[$m] = ['month' => $m, 'invoiced' => 0, 'collected' => 0];
        $tot = ['invoiced' => 0, 'tax' => 0, 'collected' => 0, 'outstanding' => 0, 'count' => 0];
        $addPart = function ($pn, $name, $kind, $cents, $qty = 1) use (&$byPart, &$byKind) {
            $k = $pn ?: '—';
            $byPart[$k] = $byPart[$k] ?? ['part_number' => $pn, 'name' => $name, 'kind' => $kind, 'qty' => 0, 'cents' => 0];
            $byPart[$k]['qty'] += $qty; $byPart[$k]['cents'] += $cents;
            $byKind[$kind ?: 'other'] = ($byKind[$kind ?: 'other'] ?? 0) + $cents;
        };
        foreach ($invoices as $inv) {
            $tot['count']++;
            $sub = (int) ($inv->subtotal_cents ?: ((int) $inv->amount_cents - (int) $inv->tax_cents));
            $tot['invoiced'] += $sub; $tot['tax'] += (int) $inv->tax_cents;
            $tot['collected'] += (int) $inv->amount_paid_cents;
            if ($inv->status !== 'paid') $tot['outstanding'] += max(0, (int) $inv->amount_cents - (int) $inv->amount_paid_cents);
            $c = $inv->agency_name ?: ('Agency #' . $inv->agency_id);
            $byCustomer[$c] = ($byCustomer[$c] ?? 0) + $sub;
            $m = substr((string) ($inv->issued_at ?: $inv->created_at), 0, 7);
            if (isset($byMonth[$m])) { $byMonth[$m]['invoiced'] += $sub; $byMonth[$m]['collected'] += (int) $inv->amount_paid_cents; }
            $ls = $lines->get($inv->id);
            if ($ls && $ls->count()) {
                foreach ($ls as $ln) { $p = $ln->part_number ? $products->get($ln->part_number) : null; $addPart($ln->part_number, $p->name ?? $ln->description, $p->kind ?? 'other', (int) $ln->amount_cents, (float) $ln->qty); }
            } else {
                // Before itemised invoices: the subscription as one line, under its plan's part number when known.
                $p = $products->first(fn ($x) => $x->kind === 'plan' && $x->plan_code === $inv->plan_code);
                $addPart($p->part_number ?? null, $p ? $p->name . ' (subscription)' : 'Subscription (' . ($inv->plan_code ?: 'plan') . ')', 'plan', $sub);
            }
        }

        // One-time services and plans SOLD on approved proposals (booked, whether or not invoiced yet).
        $booked = DB::table('sales_quote_items as i')->join('sales_quotes as q', 'q.id', '=', 'i.quote_id')->leftJoin('sales_leads as l', 'l.id', '=', 'q.lead_id')
            ->whereNull('q.deleted_at')->where(function ($w) { $w->whereNotNull('q.converted_agency_id')->orWhereNotNull('q.countersigned_at')->orWhere('q.status', 'accepted'); })
            ->whereBetween(DB::raw('DATE(COALESCE(q.approved_at, q.countersigned_at, q.updated_at))'), [$from, $to])
            ->get(['i.part_number', 'i.description', 'i.qty', 'i.line_total', 'q.number', 'q.currency', 'l.company', 'l.name']);
        $bookedBy = [];
        foreach ($booked as $b) {
            $p = $b->part_number ? $products->get($b->part_number) : null;
            $k = $b->part_number ?: '—';
            $bookedBy[$k] = $bookedBy[$k] ?? ['part_number' => $b->part_number, 'name' => $p->name ?? explode(' — ', $b->description)[0], 'kind' => $p->kind ?? 'other', 'qty' => 0, 'amount' => 0.0];
            $bookedBy[$k]['qty'] += (float) $b->qty; $bookedBy[$k]['amount'] += (float) $b->line_total;
        }

        // Recurring now: live licences, plus agencies on legacy (non-licence) recurring billing.
        $mrr = 0; $licensed = [];
        if (Schema::hasTable('agency_licences')) {
            foreach (DB::table('agency_licences')->whereIn('status', ['active', 'grace'])->get() as $l) {
                $mrr += PlatformBilling::monthlyCents((int) round((float) $l->qty * (int) $l->unit_price_cents), $l->interval);
                $licensed[$l->agency_id] = true;
            }
        }
        foreach (DB::table('agencies')->whereNull('deleted_at')->where('billing_status', 'active')->whereNotNull('next_invoice_at')->get(['id', 'plan_amount_cents', 'billing_interval']) as $a) {
            if (! isset($licensed[$a->id])) $mrr += PlatformBilling::monthlyCents((int) $a->plan_amount_cents, $a->billing_interval);
        }

        arsort($byCustomer);
        uasort($byPart, fn ($a, $b) => $b['cents'] <=> $a['cents']);
        uasort($bookedBy, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        return response()->json([
            'from' => $from, 'to' => $to, 'currency' => 'CAD',
            'totals' => $tot + ['mrr_cents' => $mrr, 'arr_cents' => $mrr * 12, 'customers' => count($byCustomer),
                'booked_cents' => (int) round(array_sum(array_column($bookedBy, 'amount')) * 100)],
            'by_part' => array_values($byPart),
            'by_kind' => $byKind,
            'by_month' => array_values($byMonth),
            'by_customer' => array_map(fn ($k, $v) => ['customer' => $k, 'cents' => $v], array_keys(array_slice($byCustomer, 0, 15, true)), array_slice($byCustomer, 0, 15, true)),
            'booked' => array_values($bookedBy),
        ]);
    }
}
