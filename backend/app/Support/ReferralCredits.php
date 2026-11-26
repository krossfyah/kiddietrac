<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * REFERRAL CREDITS (2026-11-26, Anthony: "wire up a way to apply a referral fee to a future invoice to one of our
 * customers (one time) - full sweep").
 *
 * His answers:
 *  - a CREDIT off the referring customer's KiddieTrac invoice;
 *  - a fixed $ amount or a % of that invoice, chosen by a super admin; never below $0 - what a fixed credit cannot
 *    use on that invoice carries to the following one(s) until it is used up (still the same one-time credit);
 *  - linked to the referred lead/customer in Sales, shown on both, one credit per referral;
 *  - for a chosen invoice month: when that month's invoice is raised it goes on automatically as a "Referral credit"
 *    line. If that month's invoice is already a draft it goes on now; issued, paid or void: refused.
 *
 * Tax: the credit comes off BEFORE tax, like the prepayment discount (tax on the net). The line carries the tax it
 * takes off as a negative tax_cents, so line taxes still add up to the invoice tax, and the invoice editor keeps it
 * (PlatformAccounts::lineTaxable / editInvoice treat a taxed referral line as reducing tax).
 *
 * The ONE place it is applied is PlatformInvoiceRaiser::commit (same transaction as the invoice), plus create() for
 * an existing draft. The raise preview reads preview() so what is shown is what is applied.
 */
final class ReferralCredits
{
    public const PART = 'KT-REF';

    /**
     * What a credit would take off, in cents (before tax). $leftCents = the invoice subtotal still to credit against
     * (the $0 floor); $grossCents = the invoice before any referral credit (what a % is a % of).
     */
    public static function amountFor(object $c, int $leftCents, ?int $grossCents = null): int
    {
        $subtotalCents = $leftCents;
        if ($subtotalCents <= 0) return 0;
        if ($c->type === 'percent') {
            $want = (int) round(max($grossCents ?? 0, $leftCents) * min(100.0, max(0.0, (float) $c->value)) / 100);
        } else {
            $want = (int) ($c->remaining_cents ?? round((float) $c->value * 100));
        }
        return max(0, min($want, $subtotalCents));
    }

    /** Pending credits that would apply to an invoice for this agency starting on $periodStart. */
    public static function due(int $agencyId, string $periodStart): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('referral_credits')) return [];
        return DB::table('referral_credits')->where('agency_id', $agencyId)
            ->whereIn('status', ['pending', 'partially_applied'])
            ->where('applies_from', '<=', substr($periodStart, 0, 10))
            ->orderBy('applies_from')->orderBy('id')->get()->all();
    }

    /** For the raise preview: what would come off an invoice of $subtotalCents for this period. */
    public static function preview(int $agencyId, string $periodStart, int $subtotalCents): array
    {
        $out = [];
        $left = $subtotalCents;
        foreach (self::due($agencyId, $periodStart) as $c) {
            $a = self::amountFor($c, $left, $subtotalCents);
            if ($a <= 0) continue;
            $out[] = ['id' => (int) $c->id, 'description' => self::description($c), 'amount_cents' => -$a];
            $left -= $a;
        }
        return $out;
    }

    /**
     * Put the due referral credits on a freshly raised (or draft) invoice. Call inside the invoice's transaction.
     * Returns how many were applied.
     */
    public static function applyToInvoice(int $invoiceId, ?int $byUserId = null, ?int $onlyCreditId = null): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('referral_credits')) return 0;
        $inv = DB::table('platform_invoices')->where('id', $invoiceId)->first();
        if (! $inv || $inv->status !== 'draft') return 0;   // only a draft: issued/paid/void invoices are never changed here
        $n = 0;
        foreach (self::due((int) $inv->agency_id, (string) $inv->period_start) as $c) {
            if ($onlyCreditId !== null && (int) $c->id !== $onlyCreditId) continue;
            $done = json_decode((string) ($c->applied_invoice_ids ?: '[]'), true) ?: [];
            if (in_array($invoiceId, $done, true)) continue;
            $inv = DB::table('platform_invoices')->where('id', $invoiceId)->first();
            $sub = (int) $inv->subtotal_cents;
            $gross = $sub - (int) DB::table('platform_invoice_lines')->where('invoice_id', $invoiceId)->where('kind', 'referral')->sum('amount_cents');
            $amt = self::amountFor($c, $sub, $gross);
            if ($amt <= 0) continue;   // a $0 invoice: the credit waits for the next one

            // Tax it takes off: the taxed base before and after the credit (credit before tax).
            $taxOff = 0;
            $bps = (int) $inv->tax_rate_bps;
            if ($bps > 0 && (int) $inv->tax_cents > 0) {
                $lines = DB::table('platform_invoice_lines')->where('invoice_id', $invoiceId)->get();
                /* Licence invoices tax the net subtotal at invoice level (their lines carry no tax); itemised
                   manual/extra lines carry their own. Use whichever this invoice does. */
                $taxedLines = $lines->where('tax_cents', '>', 0);
                $base = $taxedLines->count() ? (int) $taxedLines->sum('amount_cents') : $sub;
                $cut = min($amt, max(0, $base));
                $taxOff = min((int) $inv->tax_cents, PlatformBilling::taxCents($base, $bps) - PlatformBilling::taxCents($base - $cut, $bps));
            }

            PlatformAccounts::addLine($invoiceId, ['kind' => 'referral', 'part_number' => self::PART, 'description' => self::description($c),
                'qty' => 1, 'unit_cents' => -$amt, 'amount_cents' => -$amt, 'tax_cents' => -$taxOff,
                'period_start' => $inv->period_start, 'period_end' => $inv->period_end]);

            $done[] = $invoiceId;
            $applied = (int) $c->applied_cents + $amt;
            $upd = ['applied_cents' => $applied, 'applied_invoice_ids' => json_encode($done), 'updated_at' => now()];
            if ($c->type === 'fixed') {
                $rem = (int) ($c->remaining_cents ?? round((float) $c->value * 100)) - $amt;
                $upd['remaining_cents'] = max(0, $rem);
                $upd['status'] = $rem > 0 ? 'partially_applied' : 'applied';
            } else {
                $upd['status'] = 'applied';
            }
            DB::table('referral_credits')->where('id', $c->id)->update($upd);
            Audit::write(['user_id' => $byUserId, 'agency_id' => (int) $inv->agency_id, 'action' => 'referral_credit.applied', 'entity_type' => 'referral_credit', 'entity_id' => (int) $c->id,
                'payload' => json_encode(['summary' => 'Referral credit of ' . PlatformBilling::money($amt + $taxOff, $inv->currency) . ' (incl. ' . PlatformBilling::money($taxOff, $inv->currency) . ' tax) applied to invoice ' . $inv->number
                    . ($upd['status'] === 'partially_applied' ? '; ' . PlatformBilling::money((int) $upd['remaining_cents'], $inv->currency) . ' carries to the next invoice' : '')]), 'created_at' => now()]);
            $n++;
        }
        return $n;
    }

    /** "Referral credit: thank you for referring Sunny Meadows" */
    public static function description(object $c): string
    {
        $who = self::leadName((int) $c->referred_lead_id);
        return 'Referral credit' . ($c->type === 'percent' ? ' (' . rtrim(rtrim(number_format((float) $c->value, 2), '0'), '.') . '%)' : '')
            . ': thank you for referring ' . ($who ?: 'a new customer');
    }

    public static function leadName(int $leadId): ?string
    {
        $l = DB::table('sales_leads')->where('id', $leadId)->first(['company', 'name']);
        return $l ? (trim((string) ($l->company ?: $l->name)) ?: null) : null;
    }

    /**
     * Create a credit. Refuses a month whose invoice is already issued/paid/void, a past month with no invoice,
     * a referral already credited, and a lead that is the referrer's own.
     */
    public static function create(array $d, int $byUserId): object
    {
        $agencyId = (int) $d['agency_id'];
        $agency = DB::table('agencies')->where('id', $agencyId)->whereNull('deleted_at')->first();
        abort_unless($agency, 422, 'Pick the customer whose invoice gets the credit.');
        $lead = DB::table('sales_leads')->where('id', (int) $d['referred_lead_id'])->whereNull('deleted_at')->first();
        abort_unless($lead, 422, 'Pick the lead or customer they referred.');
        abort_if((int) ($lead->converted_agency_id ?? 0) === $agencyId, 422, 'A customer cannot be credited for referring themselves.');
        $own = DB::table('sales_customers')->where('agency_id', $agencyId)->pluck('lead_id')->filter()->map(fn ($v) => (int) $v)->all();
        abort_if(in_array((int) $lead->id, $own, true), 422, 'That lead is this customer\'s own record. Pick the one they referred.');
        $prev = DB::table('referral_credits')->where('referred_lead_id', $lead->id)->where('status', '!=', 'cancelled')->first();
        if ($prev) abort(422, 'This referral already has a credit (' . (DB::table('agencies')->where('id', $prev->agency_id)->value('name') ?: 'another customer') . '). One credit per referral.');

        $type = $d['type'] === 'percent' ? 'percent' : 'fixed';
        $value = round((float) $d['value'], 2);
        abort_if($value <= 0, 422, 'Enter an amount above zero.');
        abort_if($type === 'percent' && $value > 100, 422, 'A percentage can be at most 100%.');

        $month = substr((string) $d['month'], 0, 7);
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month), 422, 'Pick the invoice month.');
        $from = $month . '-01';
        $to = \Illuminate\Support\Carbon::parse($from)->endOfMonth()->toDateString();
        $thisMonth = AgencyTime::todayDate()->format('Y-m');
        $inMonth = DB::table('platform_invoices')->where('agency_id', $agencyId)->whereBetween('period_start', [$from, $to])->orderBy('period_start')->first();
        $draft = null;
        if ($inMonth) {
            abort_if($inMonth->status !== 'draft', 422, 'The ' . \Illuminate\Support\Carbon::parse($from)->format('F Y') . ' invoice (' . $inMonth->number . ') is already '
                . ($inMonth->status === 'paid' ? 'paid' : ($inMonth->status === 'void' ? 'void' : 'issued')) . '. Pick a later month, or edit that invoice.');
            $draft = $inMonth;
        } else {
            abort_if($month < $thisMonth, 422, 'That month has passed. Pick this month or a later one.');
        }

        $id = DB::transaction(function () use ($agencyId, $agency, $lead, $type, $value, $from, $d, $byUserId, $draft) {
            $id = DB::table('referral_credits')->insertGetId([
                'agency_id' => $agencyId, 'referred_lead_id' => (int) $lead->id, 'type' => $type, 'value' => $value, 'applies_from' => $from,
                'status' => 'pending', 'applied_cents' => 0, 'remaining_cents' => $type === 'fixed' ? (int) round($value * 100) : null,
                'currency' => PlatformBilling::normaliseCurrency($agency->plan_currency ?? null), 'notes' => isset($d['notes']) ? mb_substr(trim((string) $d['notes']), 0, 500) ?: null : null,
                'created_by_id' => $byUserId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $c = DB::table('referral_credits')->find($id);
            $what = $type === 'percent' ? rtrim(rtrim(number_format($value, 2), '0'), '.') . '% of the invoice' : PlatformBilling::money((int) round($value * 100), $c->currency);
            Audit::write(['user_id' => $byUserId, 'agency_id' => $agencyId, 'action' => 'referral_credit.created', 'entity_type' => 'referral_credit', 'entity_id' => $id,
                'payload' => json_encode(['summary' => 'Referral credit for ' . $agency->name . ': ' . $what . ' off the ' . \Illuminate\Support\Carbon::parse($from)->format('F Y') . ' invoice, for referring '
                    . (self::leadName((int) $lead->id) ?: 'lead #' . $lead->id)]), 'created_at' => now()]);
            // On both records in Sales: the referred lead, and the referrer's own lead if it has one.
            $by = self::leadName((int) $lead->id) ?: 'this lead';
            DB::table('sales_activities')->insert(['lead_id' => (int) $lead->id, 'user_id' => $byUserId, 'type' => 'note',
                'body' => '🤝 Referred by ' . $agency->name . '. They get a referral credit of ' . $what . ' on their ' . \Illuminate\Support\Carbon::parse($from)->format('F Y') . ' KiddieTrac invoice.',
                'done' => 1, 'created_at' => now(), 'updated_at' => now()]);
            foreach (DB::table('sales_customers')->where('agency_id', $agencyId)->whereNotNull('lead_id')->pluck('lead_id') as $own) {
                DB::table('sales_activities')->insert(['lead_id' => (int) $own, 'user_id' => $byUserId, 'type' => 'note',
                    'body' => '🤝 Referral credit for referring ' . $by . ': ' . $what . ' off the ' . \Illuminate\Support\Carbon::parse($from)->format('F Y') . ' invoice.',
                    'done' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($draft) self::applyToInvoice((int) $draft->id, $byUserId, $id);
            return $id;
        });
        return self::row(DB::table('referral_credits')->find($id));
    }

    public static function cancel(int $id, int $byUserId): object
    {
        $c = DB::table('referral_credits')->find($id);
        abort_unless($c, 404, 'Not found.');
        abort_if($c->status === 'cancelled', 422, 'Already cancelled.');
        abort_if($c->status === 'applied', 422, 'This credit is already on an invoice. To take it back, edit that invoice.');
        DB::table('referral_credits')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_by_id' => $byUserId, 'cancelled_at' => now(), 'updated_at' => now()]);
        Audit::write(['user_id' => $byUserId, 'agency_id' => (int) $c->agency_id, 'action' => 'referral_credit.cancelled', 'entity_type' => 'referral_credit', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Cancelled the referral credit for referring ' . (self::leadName((int) $c->referred_lead_id) ?: 'lead #' . $c->referred_lead_id)
                . ((int) $c->applied_cents > 0 ? ' (the ' . PlatformBilling::money((int) $c->applied_cents, $c->currency) . ' already applied stays on its invoice)' : '')]), 'created_at' => now()]);
        return self::row(DB::table('referral_credits')->find($id));
    }

    /** A row for the screens. $withMoney false = sales reps (names and dates only). */
    public static function row(object $c, bool $withMoney = true): object
    {
        $ids = json_decode((string) ($c->applied_invoice_ids ?: '[]'), true) ?: [];
        $out = [
            'id' => (int) $c->id, 'agency_id' => (int) $c->agency_id, 'agency_name' => DB::table('agencies')->where('id', $c->agency_id)->value('name'),
            'customer_number' => SalesCustomers::numberForAgency((int) $c->agency_id),
            'referred_lead_id' => (int) $c->referred_lead_id, 'referred_name' => self::leadName((int) $c->referred_lead_id),
            'month' => substr((string) $c->applies_from, 0, 7), 'status' => $c->status, 'created_at' => (string) $c->created_at,
        ];
        if ($withMoney) {
            $out += ['type' => $c->type, 'value' => (float) $c->value, 'currency' => $c->currency, 'applied_cents' => (int) $c->applied_cents,
                'remaining_cents' => $c->remaining_cents !== null ? (int) $c->remaining_cents : null, 'notes' => $c->notes,
                'invoices' => $ids ? DB::table('platform_invoices')->whereIn('id', $ids)->orderBy('period_start')->pluck('number')->all() : []];
        }
        return (object) $out;
    }

    /** Credits given to this agency (as referrer) and the one for the lead(s) it came from (as referred). */
    public static function forAgency(int $agencyId, ?int $ownLeadId, bool $withMoney): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('referral_credits')) return ['given' => [], 'referred_by' => null];
        $given = DB::table('referral_credits')->where('agency_id', $agencyId)->orderByDesc('id')->get()->map(fn ($c) => self::row($c, $withMoney))->all();
        $leadIds = array_filter([$ownLeadId, ...DB::table('sales_leads')->where('converted_agency_id', $agencyId)->pluck('id')->map(fn ($v) => (int) $v)->all()]);
        $by = $leadIds ? DB::table('referral_credits')->whereIn('referred_lead_id', $leadIds)->where('status', '!=', 'cancelled')->first() : null;
        return ['given' => $given, 'referred_by' => $by ? ['agency_id' => (int) $by->agency_id, 'agency_name' => DB::table('agencies')->where('id', $by->agency_id)->value('name'),
            'customer_number' => SalesCustomers::numberForAgency((int) $by->agency_id)] : null];
    }
}
