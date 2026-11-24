<?php

namespace App\Support;

use App\Http\Controllers\Api\SalesController;
use App\Models\SalesActivity;
use App\Models\SalesLead;
use App\Models\SalesQuote;
use Illuminate\Support\Facades\DB;

/**
 * KiddieTrac's customers (2026-11-10, Anthony: "when I change lead to won it should create a customer account with
 * customer id based on the details of the services etc ... tie this to the account AR/AP").
 *
 * His answers: Won creates the Customer at once (KT-CUS-0001) with the services and pricing of the winning proposal;
 * the KiddieTrac agency is still set up through Approve (which keeps the signed-contract check) and the customer is
 * linked to it then, which is what ties it to AR (platform_invoices.agency_id). Won without an accepted or signed
 * proposal still creates the customer, flagged "no services yet". Leads already Won before this were left alone.
 *
 * Every way a lead becomes Won goes through the SalesLead model (stage dropdown, countersign, Approve), whose saved
 * hook calls ensure(); a signed or accepted proposal on an already-won lead refreshes the services (SalesQuote hook).
 * sweep() catches anything written without the model. Never throws into the caller's save.
 */
final class SalesCustomers
{
    public const STATUSES = ['onboarding' => 'Onboarding', 'active' => 'Active', 'paused' => 'Paused', 'cancelled' => 'Cancelled'];

    /** Leads already Won when Customers went live (2026-11-10): "No, start fresh". Only the sweep skips them. */
    private const BEFORE = [5, 6, 7, 16];

    public static function number(int $id): string
    {
        return 'KT-CUS-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    /* THE CUSTOMER NUMBER ON EVERY INVOICE (2026-11-11, Anthony: "for customers of KiddieTrac we need to show their
       customer invoice on the invoices produced" - he chose the customer number, on the PDF, the billing emails, the
       AR screens and the agency's own billing page, old invoices included). Read live from sales_customers by agency,
       so an invoice issued before the customer record existed shows it too; the amounts never change. An agency that
       is not a customer yet gets nothing (no row = no line). A live customer wins over a cancelled one. */
    public static function numbersForAgencies(array $agencyIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $agencyIds))));
        if (! $ids) return [];
        $out = [];
        foreach (DB::table('sales_customers')->whereIn('agency_id', $ids)->orderByRaw("status = 'cancelled'")->orderBy('id')->get(['id', 'agency_id']) as $c) {
            $out[(int) $c->agency_id] ??= self::number((int) $c->id);
        }
        return $out;
    }

    public static function numberForAgency(?int $agencyId): ?string
    {
        return $agencyId ? (self::numbersForAgencies([$agencyId])[$agencyId] ?? null) : null;
    }

    /** The proposal the customer bought: countersigned, else signed by them, else marked accepted. Null = none yet. */
    public static function winningQuote(SalesLead $l): ?SalesQuote
    {
        $qs = SalesQuote::with('items')->where('lead_id', $l->id)->get();
        $pick = fn ($f) => $qs->filter($f)->sortByDesc(fn ($q) => (string) ($q->countersigned_at ?: $q->client_signed_at ?: $q->updated_at))->first();

        return $pick(fn ($q) => $q->countersigned_at)
            ?: $pick(fn ($q) => $q->client_signed_at && $q->status !== 'declined')
            ?: $pick(fn ($q) => in_array($q->status, ['accepted', 'signed'], true))
            ?: ($l->converted_agency_id ? $pick(fn ($q) => (int) ($q->converted_agency_id ?? 0) === (int) $l->converted_agency_id) : null);
    }

    /** What they bought, from that proposal: per-month recurring, one-time, first-year value and the lines. */
    public static function snapshot(?SalesQuote $q): array
    {
        if (! $q) return ['quote_id' => null, 'services' => json_encode([]), 'mrr' => 0, 'one_time' => 0, 'annual_value' => 0, 'billing_period' => null];
        $s = SalesController::splitTotals($q);
        $mrr = $q->billing_period === 'year' ? (float) $s['recurring_net'] / 12 : (float) $s['recurring_net'];
        $prods = DB::table('sales_products')->whereIn('id', $q->items->pluck('product_id')->filter()->all())->get(['id', 'kind', 'unit'])->keyBy('id');
        $lines = $q->items->sortBy('sort')->map(function ($i) use ($prods) {
            $p = $i->product_id ? ($prods[$i->product_id] ?? null) : null;
            return ['part_number' => $i->part_number, 'description' => mb_strimwidth(trim((string) strtok((string) $i->description, "\n")), 0, 200, '…'),
                'qty' => (float) $i->qty, 'unit_price' => (float) $i->unit_price, 'line_total' => (float) $i->line_total,
                'kind' => $p->kind ?? null, 'unit' => $p->unit ?? null];
        })->values()->all();

        return ['quote_id' => $q->id, 'services' => json_encode($lines), 'mrr' => round($mrr, 2), 'one_time' => round((float) $s['one_time_net'], 2),
            'annual_value' => round($mrr * 12 + (float) $s['one_time_net'], 2), 'billing_period' => $q->billing_period, 'currency' => $q->currency ?: 'CAD'];
    }

    /**
     * The customer's price, line by line, with every discount and the totals before and after (2026-11-11, Anthony:
     * "the table should show the discounts as well given to a customer and the intial total amount before discounts").
     * Read live from the winning proposal so a discount's rate and what it applies to are exact; a customer with no
     * proposal (iLearn, set up before Sales) uses its stored plan lines. Amounts are per billing period + one-time.
     */
    public static function pricing(object $c): array
    {
        $lines = []; $gross = ['recurring' => 0.0, 'one_time' => 0.0]; $discounts = [];
        $net = null; $tax = ['recurring' => 0.0, 'one_time' => 0.0]; $taxLabel = null; $period = $c->billing_period;
        $q = $c->quote_id ? SalesQuote::with('items')->find($c->quote_id) : null;
        $rateText = function ($type, $val) {
            if (! $type || $val === null) return null;
            return $type === 'percent' ? rtrim(rtrim(number_format((float) $val, 2, '.', ''), '0'), '.') . '% off' : '$' . number_format((float) $val, 2) . ' off';
        };
        if ($q) {
            $period = $q->billing_period ?: $period;
            $prods = DB::table('sales_products')->whereIn('id', $q->items->pluck('product_id')->filter()->all())
                ->get(['id', 'kind', 'unit', 'applies_to', 'discount_type', 'price'])->keyBy('id');
            $pend = [];
            foreach ($q->items->sortBy('sort') as $i) {
                $p = $i->product_id ? ($prods[$i->product_id] ?? null) : null;
                $isD = ($p->kind ?? '') === 'discount';
                $to = $isD ? (in_array($p->applies_to ?? 'all', ['recurring', 'one-time'], true) ? $p->applies_to : 'all') : null;
                $once = $p && ! $isD && $p->unit === 'one-time';
                $type = $isD ? ($i->discount_type ?: ($p->discount_type ?? null)) : null;
                $val = $isD ? ($i->discount_value !== null ? (float) $i->discount_value : (float) ($p->price ?? 0)) : null;
                $line = ['part_number' => $i->part_number, 'description' => mb_strimwidth(trim((string) strtok((string) $i->description, "\n")), 0, 200, '…'),
                    'qty' => (float) $i->qty, 'unit_price' => (float) $i->unit_price, 'line_total' => (float) $i->line_total,
                    'kind' => $p->kind ?? null, 'unit' => $isD ? null : ($p->unit ?? null),
                    'rate' => $isD ? $rateText($type, $val) : null,
                    'applies' => $isD ? ['all' => 'everything', 'recurring' => 'the ' . ($period === 'year' ? 'yearly' : 'monthly') . ' services', 'one-time' => 'one-time fees'][$to] : null];
                $lines[] = $line;
                if ($isD) $pend[] = [$line, $to, (float) $i->line_total];
                else $gross[$once ? 'one_time' : 'recurring'] += (float) $i->line_total;
            }
            // A discount on everything is shared between monthly and one-time the way splitTotals shares it.
            $base = max(0.0, $gross['recurring']) + max(0.0, $gross['one_time']);
            $share = $base > 0 ? max(0.0, $gross['recurring']) / $base : 1.0;
            foreach ($pend as [$line, $to, $amt]) {
                $r = $to === 'one-time' ? 0.0 : ($to === 'recurring' ? $amt : round($amt * $share, 2));
                $discounts[] = ['label' => $line['description'], 'rate' => $line['rate'], 'applies' => $line['applies'],
                    'on' => $to === 'one-time' ? 'one_time' : ($to === 'recurring' ? 'recurring' : 'all'),
                    'recurring' => round($r, 2), 'one_time' => round($amt - $r, 2), 'amount' => round($amt, 2)];
            }
            $s = SalesController::splitTotals($q);
            if ((float) $s['discount'] > 0) {
                $discounts[] = ['label' => 'Proposal discount', 'rate' => '$' . number_format((float) $s['discount'], 2) . ' off', 'applies' => 'the ' . ($period === 'year' ? 'yearly' : 'monthly') . ' services',
                    'on' => 'recurring', 'recurring' => -round((float) $s['discount'], 2), 'one_time' => 0.0, 'amount' => -round((float) $s['discount'], 2)];
            }
            $net = ['recurring' => (float) $s['recurring_net'], 'one_time' => (float) $s['one_time_net']];
            $tax = ['recurring' => round((float) $s['recurring_total'] - (float) $s['recurring_net'], 2), 'one_time' => round((float) ($s['one_time_total'] ?? $s['one_time_net']) - (float) $s['one_time_net'], 2)];
            $taxLabel = collect($s['recurring_taxes'] ?? [])->map(fn ($t) => $t['label'] . ' ' . rtrim(rtrim(number_format((float) $t['rate'], 2, '.', ''), '0'), '.') . '%')->implode(' + ') ?: null;
        } else {
            // No proposal (an agency set up outside Sales): what it is actually billed for, from its latest invoice.
            if ($c->agency_id && ($inv = self::invoiceLines((int) $c->agency_id))) {
                $c = (object) array_merge((array) $c, ['services' => json_encode($inv['lines'])]);
                $period = $inv['period'] ?: $period;
            }
            foreach (json_decode((string) $c->services, true) ?: [] as $l) {
                $isD = ($l['kind'] ?? '') === 'discount';
                $lines[] = $l + ['rate' => null, 'applies' => null];
                if ($isD && ($l['unit'] ?? '') === 'one-time') { $discounts[] = ['label' => $l['description'] ?? 'Discount', 'rate' => $l['rate'] ?? null, 'applies' => $l['applies'] ?? null, 'on' => 'one_time', 'recurring' => 0.0, 'one_time' => (float) ($l['line_total'] ?? 0), 'amount' => (float) ($l['line_total'] ?? 0)]; continue; }
                if ($isD) $discounts[] = ['label' => $l['description'] ?? 'Discount', 'rate' => $l['rate'] ?? null, 'applies' => $l['applies'] ?? null, 'on' => ($l['unit'] ?? '') === 'one-time' ? 'one_time' : 'recurring',
                    'recurring' => (float) ($l['line_total'] ?? 0), 'one_time' => 0.0, 'amount' => (float) ($l['line_total'] ?? 0)];
                else $gross[($l['unit'] ?? '') === 'one-time' ? 'one_time' : 'recurring'] += (float) ($l['line_total'] ?? 0);
            }
        }
        $dr = array_sum(array_column($discounts, 'recurring')); $do = array_sum(array_column($discounts, 'one_time'));
        $net = $net ?: ['recurring' => round(max(0, $gross['recurring'] + $dr), 2), 'one_time' => round(max(0, $gross['one_time'] + $do), 2)];

        return ['lines' => $lines, 'gross' => ['recurring' => round($gross['recurring'], 2), 'one_time' => round($gross['one_time'], 2)],
            'discounts' => $discounts, 'discount_total' => round($dr + $do, 2), 'net' => $net, 'tax' => $tax, 'tax_label' => $taxLabel,
            'period' => $period, 'from_quote' => (bool) $q, 'source_invoice' => isset($inv) && $inv ? $inv['invoice'] : null];
    }

    /**
     * The lines of an agency's latest real invoice (not void), as service lines: plans and add-ons at list price, the
     * complimentary / discount lines as discounts (2026-11-11). iLearn's invoice is Agency + Whitelabel + SMS + AI at
     * list price with a 100% complimentary line, which is exactly "the initial total before discounts" and the discount.
     */
    public static function invoiceLines(int $agencyId): ?array
    {
        try {
            $inv = DB::table('platform_invoices')->where('agency_id', $agencyId)->where('status', '!=', 'void')
                ->orderByDesc('period_start')->orderByDesc('issued_at')->orderByDesc('id')->first(['id', 'number', 'period_start', 'period_end']);
            if (! $inv) return null;
            $rows = DB::table('platform_invoice_lines')->where('invoice_id', $inv->id)->whereNull('payment_id')->orderBy('id')->get();
            if ($rows->isEmpty()) return null;
            $days = $inv->period_start && $inv->period_end ? \Illuminate\Support\Carbon::parse($inv->period_start)->diffInDays(\Illuminate\Support\Carbon::parse($inv->period_end)) : 30;
            $lines = $rows->map(function ($r) {
                $amt = round(((int) $r->amount_cents) / 100, 2);
                $isD = $r->kind === 'comp' || $r->kind === 'discount' || $amt < 0;
                $pct = $isD && preg_match('/\((\d+(?:\.\d+)?)%\)/', (string) $r->description, $m) ? $m[1] . '% off' : null;
                return ['part_number' => $r->part_number, 'description' => preg_replace('/\s*·\s*value .*$/u', '', (string) $r->description),
                    'qty' => (float) $r->qty, 'unit_price' => round(((int) $r->unit_cents) / 100, 2), 'line_total' => $amt,
                    'kind' => $isD ? 'discount' : 'plan', 'unit' => $r->extra_id ? 'one-time' : 'month',
                    'rate' => $isD ? ($pct ?: '$' . number_format(abs($amt), 2) . ' off') : null, 'applies' => $isD ? 'everything' : null];
            })->values()->all();
            return ['lines' => $lines, 'invoice' => $inv->number, 'period' => $days > 300 ? 'year' : 'month'];
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    /** Make sure a won lead has its customer, with the latest winning proposal and its agency. Returns the row. */
    public static function ensure(SalesLead $l, ?int $actor = null): ?object
    {
        try {
            if ($l->stage !== 'won' && ! $l->converted_agency_id) return null;
            $q = self::winningQuote($l);
            $c = DB::table('sales_customers')->where('lead_id', $l->id)->first();
            if (! $c) {
                $snap = self::snapshot($q);
                $id = DB::table('sales_customers')->insertGetId($snap + [
                    'lead_id' => $l->id, 'agency_id' => $l->converted_agency_id ?: null,
                    'company' => mb_substr((string) ($l->company ?: $l->name), 0, 190), 'contact_name' => mb_substr((string) $l->name, 0, 190),
                    'email' => $l->email, 'phone' => $l->phone, 'city' => $l->city, 'province' => $l->province, 'country' => $l->country,
                    'status' => $l->converted_agency_id ? 'active' : 'onboarding', 'currency' => $snap['currency'] ?? 'CAD',
                    'won_at' => now(), 'won_by_user_id' => $actor, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $num = self::number($id);
                DB::table('sales_customers')->where('id', $id)->update(['customer_number' => $num]);
                self::log($l, '🏆 Customer ' . $num . ' created' . ($q ? ' from proposal ' . $q->number . ': ' . self::moneyLine($snap) : ' — no accepted or signed proposal yet, so no services are listed') . '.', $actor);
                Audit::write(['user_id' => $actor, 'agency_id' => null, 'action' => 'sales_customer.created', 'entity_type' => 'sales_customer', 'entity_id' => $id,
                    'payload' => json_encode(['summary' => 'Created customer ' . $num . ' for ' . ($l->company ?: $l->name) . ($q ? ' from ' . $q->number : ' (no services yet)')]), 'created_at' => now()]);

                return DB::table('sales_customers')->find($id);
            }
            $upd = [];
            // The services follow the winning proposal (a later signature, a new accepted proposal).
            if ($q && (int) $c->quote_id !== (int) $q->id) {
                $upd = self::snapshot($q);
                self::log($l, '🏆 Customer ' . $c->customer_number . ': services now from proposal ' . $q->number . ' — ' . self::moneyLine($upd) . '.', $actor);
            }
            if ($l->converted_agency_id && (int) $c->agency_id !== (int) $l->converted_agency_id) {
                $upd['agency_id'] = $l->converted_agency_id;
                if ($c->status === 'onboarding') $upd['status'] = 'active';
                $name = DB::table('agencies')->where('id', $l->converted_agency_id)->value('name');
                self::log($l, '🏆 Customer ' . $c->customer_number . ' linked to its KiddieTrac account' . ($name ? ' (' . $name . ')' : '') . ': invoices and payments now show on the customer.', $actor);
            }
            if ($upd) {
                DB::table('sales_customers')->where('id', $c->id)->update($upd + ['updated_at' => now()]);
            }

            return DB::table('sales_customers')->find($c->id);
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    /** Won leads with no customer (written around the model), and customers still waiting for services. */
    public static function sweep(): void
    {
        try {
            $ids = SalesLead::query()->whereNull('deleted_at')
                ->where(fn ($w) => $w->where('stage', 'won')->orWhereNotNull('converted_agency_id'))
                ->whereNotIn('id', self::BEFORE)
                ->whereNotIn('id', DB::table('sales_customers')->whereNotNull('lead_id')->select('lead_id'))   // a NULL here would empty NOT IN
                ->pluck('id')->all();
            foreach (DB::table('sales_customers')->whereNull('quote_id')->whereNotNull('lead_id')->pluck('lead_id') as $lid) $ids[] = (int) $lid;
            foreach (array_unique($ids) as $lid) {
                if ($l = SalesLead::find($lid)) self::ensure($l);
            }
            // A customer with no proposal follows what its agency is invoiced for (services + monthly amount after discounts).
            foreach (DB::table('sales_customers')->whereNull('quote_id')->whereNotNull('agency_id')->get(['id', 'agency_id', 'services', 'mrr']) as $c) {
                if (! ($inv = self::invoiceLines((int) $c->agency_id))) continue;
                $rec = array_sum(array_map(fn ($l) => ($l['unit'] ?? '') === 'one-time' ? 0 : (float) $l['line_total'], $inv['lines']));
                $mrr = round(max(0, $inv['period'] === 'year' ? $rec / 12 : $rec), 2);
                $json = json_encode($inv['lines'], JSON_UNESCAPED_UNICODE);
                if ($json !== $c->services || (float) $c->mrr !== $mrr) {
                    DB::table('sales_customers')->where('id', $c->id)->update(['services' => $json, 'mrr' => $mrr, 'billing_period' => $inv['period'], 'annual_value' => round($mrr * 12, 2), 'updated_at' => now()]);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private static function moneyLine(array $s): string
    {
        $cur = $s['currency'] ?? 'CAD';
        return '$' . number_format((float) $s['mrr'], 2) . ' ' . $cur . ' a month' . ((float) $s['one_time'] ? ' + $' . number_format((float) $s['one_time'], 2) . ' one-time' : '');
    }

    private static function log(SalesLead $l, string $body, ?int $actor): void
    {
        try {
            SalesActivity::create(['lead_id' => $l->id, 'user_id' => $actor, 'type' => 'note', 'body' => $body, 'done' => true]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
