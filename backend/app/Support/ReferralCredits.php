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

    /**
     * The invoice line. With a referred party: "Referral credit: thank you for referring Sunny Meadows".
     * With none (a general one-time credit, 2026-11-26): "Credit: <reason>" - the reason is the point, so it leads.
     */
    public static function description(object $c): string
    {
        $pct = $c->type === 'percent' ? ' (' . rtrim(rtrim(number_format((float) $c->value, 2), '0'), '.') . '%)' : '';
        $who = self::referredName($c);
        if ($who === null && ! self::hasParty($c)) return mb_substr('Credit' . $pct . ': ' . (trim((string) $c->notes) ?: 'thank you'), 0, 250);
        return 'Referral credit' . $pct . ': thank you for referring ' . ($who ?: 'a new customer');
    }

    public static function hasParty(object $c): bool
    {
        return ! empty($c->referred_lead_id) || ! empty($c->referred_agency_id);
    }

    /** The referred lead's or customer's name, or null when the credit names nobody. */
    public static function referredName(object $c): ?string
    {
        if (! empty($c->referred_lead_id)) return self::leadName((int) $c->referred_lead_id);
        if (! empty($c->referred_agency_id)) return DB::table('agencies')->where('id', $c->referred_agency_id)->value('name') ?: null;
        return null;
    }

    public static function leadName(int $leadId): ?string
    {
        $l = DB::table('sales_leads')->where('id', $leadId)->first(['company', 'name']);
        return $l ? (trim((string) ($l->company ?: $l->name)) ?: null) : null;
    }

    /** Every lead id that stands for this agency (its Sales customer record's lead, and leads converted to it). */
    private static function leadsOfAgency(int $agencyId): array
    {
        return array_values(array_unique(array_merge(
            DB::table('sales_customers')->where('agency_id', $agencyId)->whereNotNull('lead_id')->pluck('lead_id')->map(fn ($v) => (int) $v)->all(),
            DB::table('sales_leads')->where('converted_agency_id', $agencyId)->pluck('id')->map(fn ($v) => (int) $v)->all())));
    }

    /** The live credit already given for this party (lead or agency, either way round), if any. */
    private static function liveFor(?int $leadId, ?int $agencyId): ?object
    {
        $leadIds = $leadId ? [$leadId] : [];
        $agencyIds = $agencyId ? [$agencyId] : [];
        if ($leadId && ($conv = DB::table('sales_leads')->where('id', $leadId)->value('converted_agency_id'))) $agencyIds[] = (int) $conv;
        if ($leadId && ($cust = DB::table('sales_customers')->where('lead_id', $leadId)->value('agency_id'))) $agencyIds[] = (int) $cust;
        foreach ($agencyIds as $a) $leadIds = array_merge($leadIds, self::leadsOfAgency($a));
        if (! $leadIds && ! $agencyIds) return null;
        return DB::table('referral_credits')->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereIn('referred_lead_id', $leadIds ?: [0])->orWhereIn('referred_agency_id', $agencyIds ?: [0]))->first();
    }

    /**
     * Create a credit. $d: agency_id, type, value, month, notes, and optionally referred = "L:<lead id>" | "A:<agency id>"
     * (or referred_lead_id). No referred party = a general one-time credit, and then the reason (notes) is required.
     * Refuses a month whose invoice is already issued/paid/void, a past month with no invoice, a party already
     * credited, and the customer itself.
     */
    public static function create(array $d, int $byUserId): object
    {
        $agencyId = (int) $d['agency_id'];
        $agency = DB::table('agencies')->where('id', $agencyId)->whereNull('deleted_at')->first();
        abort_unless($agency, 422, 'Pick the customer whose invoice gets the credit.');

        $ref = trim((string) ($d['referred'] ?? (! empty($d['referred_lead_id']) ? 'L:' . (int) $d['referred_lead_id'] : '')));
        $leadId = null; $refAgencyId = null;
        if ($ref !== '' && $ref !== 'none') {
            abort_unless(preg_match('/^([LA]):(\d+)$/', $ref, $m), 422, 'Pick who they referred, or "No specific referral".');
            if ($m[1] === 'L') {
                $lead = DB::table('sales_leads')->where('id', (int) $m[2])->whereNull('deleted_at')->first();
                abort_unless($lead, 422, 'That lead was not found. Pick it again.');
                abort_if((int) ($lead->converted_agency_id ?? 0) === $agencyId || in_array((int) $lead->id, self::leadsOfAgency($agencyId), true), 422,
                    'That is this customer\'s own record. Pick the one they referred.');
                $leadId = (int) $lead->id;
            } else {
                abort_unless(DB::table('agencies')->where('id', (int) $m[2])->whereNull('deleted_at')->exists(), 422, 'That customer was not found. Pick it again.');
                abort_if((int) $m[2] === $agencyId, 422, 'A customer cannot be credited for referring themselves.');
                $refAgencyId = (int) $m[2];
            }
            if ($prev = self::liveFor($leadId, $refAgencyId)) {
                abort(422, 'This referral already has a credit (' . (DB::table('agencies')->where('id', $prev->agency_id)->value('name') ?: 'another customer') . '). One credit per referral.');
            }
        }
        $notes = isset($d['notes']) ? (mb_substr(trim((string) $d['notes']), 0, 500) ?: null) : null;
        abort_if(! $leadId && ! $refAgencyId && ! $notes, 422, 'With no specific referral, say what the credit is for. The reason shows on the invoice.');

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

        $id = DB::transaction(function () use ($agencyId, $agency, $leadId, $refAgencyId, $type, $value, $from, $notes, $byUserId, $draft) {
            $id = DB::table('referral_credits')->insertGetId([
                'agency_id' => $agencyId, 'referred_lead_id' => $leadId, 'referred_agency_id' => $refAgencyId, 'type' => $type, 'value' => $value, 'applies_from' => $from,
                'status' => 'pending', 'applied_cents' => 0, 'remaining_cents' => $type === 'fixed' ? (int) round($value * 100) : null,
                'currency' => PlatformBilling::normaliseCurrency($agency->plan_currency ?? null), 'notes' => $notes,
                'created_by_id' => $byUserId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $c = DB::table('referral_credits')->find($id);
            $what = $type === 'percent' ? rtrim(rtrim(number_format($value, 2), '0'), '.') . '% of the invoice' : PlatformBilling::money((int) round($value * 100), $c->currency);
            $month = \Illuminate\Support\Carbon::parse($from)->format('F Y');
            $who = self::referredName($c);
            Audit::write(['user_id' => $byUserId, 'agency_id' => $agencyId, 'action' => 'referral_credit.created', 'entity_type' => 'referral_credit', 'entity_id' => $id,
                'payload' => json_encode(['summary' => ($leadId || $refAgencyId ? 'Referral credit' : 'One-time credit') . ' for ' . $agency->name . ': ' . $what . ' off the ' . $month . ' invoice, '
                    . ($leadId || $refAgencyId ? 'for referring ' . ($who ?: 'a new customer') : 'no specific referral (' . $notes . ')')]), 'created_at' => now()]);
            // On the Sales records: the referred lead (or the referred customer's lead), and the referrer's own lead.
            $note = fn ($lead, $body) => DB::table('sales_activities')->insert(['lead_id' => (int) $lead, 'user_id' => $byUserId, 'type' => 'note', 'body' => $body, 'done' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $referredLeads = $leadId ? [$leadId] : ($refAgencyId ? self::leadsOfAgency($refAgencyId) : []);
            foreach ($referredLeads as $l) $note($l, '🤝 Referred by ' . $agency->name . '. They get a referral credit of ' . $what . ' on their ' . $month . ' KiddieTrac invoice.');
            foreach (self::leadsOfAgency($agencyId) as $own) {
                $note($own, $leadId || $refAgencyId ? '🤝 Referral credit for referring ' . ($who ?: 'a new customer') . ': ' . $what . ' off the ' . $month . ' invoice.'
                    : '🤝 One-time credit: ' . $what . ' off the ' . $month . ' invoice (' . $notes . ').');
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
            'payload' => json_encode(['summary' => 'Cancelled the ' . (self::hasParty($c) ? 'referral credit for referring ' . (self::referredName($c) ?: 'a new customer') : 'one-time credit (' . $c->notes . ')')
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
            'referred_lead_id' => $c->referred_lead_id ? (int) $c->referred_lead_id : null, 'referred_agency_id' => ! empty($c->referred_agency_id) ? (int) $c->referred_agency_id : null,
            'referred_name' => self::referredName($c), 'general' => ! self::hasParty($c),
            'month' => substr((string) $c->applies_from, 0, 7), 'status' => $c->status, 'created_at' => (string) $c->created_at,
        ];
        if ($withMoney) {
            $out += ['type' => $c->type, 'value' => (float) $c->value, 'currency' => $c->currency, 'applied_cents' => (int) $c->applied_cents,
                'remaining_cents' => $c->remaining_cents !== null ? (int) $c->remaining_cents : null, 'notes' => $c->notes,
                'invoices' => $ids ? DB::table('platform_invoices')->whereIn('id', $ids)->orderBy('period_start')->pluck('number')->all() : []];
        }
        return (object) $out;
    }

    /** Credits given to this agency, and who referred it (by its lead(s) or by the agency itself). */
    public static function forAgency(int $agencyId, ?int $ownLeadId, bool $withMoney): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('referral_credits')) return ['given' => [], 'referred_by' => null];
        $given = DB::table('referral_credits')->where('agency_id', $agencyId)->orderByDesc('id')->get()->map(fn ($c) => self::row($c, $withMoney))->all();
        $by = self::liveFor($ownLeadId, $agencyId);
        return ['given' => $given, 'referred_by' => $by ? ['agency_id' => (int) $by->agency_id, 'agency_name' => DB::table('agencies')->where('id', $by->agency_id)->value('name'),
            'customer_number' => SalesCustomers::numberForAgency((int) $by->agency_id)] : null];
    }

    /**
     * The "They referred" picker: every Sales lead, plus every customer agency that has no lead (a customer with a
     * lead appears once, as its lead, labelled Customer). Test agencies are listed, tagged Test.
     */
    public static function parties(): array
    {
        $taken = DB::table('referral_credits')->where('status', '!=', 'cancelled')->get(['referred_lead_id', 'referred_agency_id']);
        $takenL = $taken->pluck('referred_lead_id')->filter()->map(fn ($v) => (int) $v)->all();
        $takenA = $taken->pluck('referred_agency_id')->filter()->map(fn ($v) => (int) $v)->all();
        $tests = TestAgency::ids();
        $custLead = DB::table('sales_customers')->whereNotNull('lead_id')->whereNotNull('agency_id')->pluck('agency_id', 'lead_id')->map(fn ($v) => (int) $v)->all();
        $out = []; $agenciesWithLead = [];
        foreach (DB::table('sales_leads')->whereNull('deleted_at')->orderByDesc('updated_at')->limit(2000)->get(['id', 'company', 'name', 'city', 'stage', 'converted_agency_id']) as $l) {
            $ag = $l->converted_agency_id ? (int) $l->converted_agency_id : ($custLead[(int) $l->id] ?? null);
            if ($ag) $agenciesWithLead[$ag] = true;
            $out[] = ['key' => 'L:' . $l->id, 'kind' => $ag ? 'customer' : 'lead', 'name' => trim((string) ($l->company ?: $l->name)) ?: ('Lead #' . $l->id),
                'city' => $l->city, 'stage' => $l->stage, 'agency_id' => $ag, 'test' => $ag && in_array($ag, $tests, true),
                'taken' => in_array((int) $l->id, $takenL, true) || ($ag && in_array($ag, $takenA, true))];
        }
        $nums = SalesCustomers::numbersForAgencies(DB::table('agencies')->whereNull('deleted_at')->pluck('id')->all());
        foreach (DB::table('agencies')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'city']) as $a) {
            if (isset($agenciesWithLead[(int) $a->id])) continue;
            $out[] = ['key' => 'A:' . $a->id, 'kind' => 'customer', 'name' => $a->name, 'city' => $a->city ?? null, 'stage' => null, 'agency_id' => (int) $a->id,
                'customer_number' => $nums[(int) $a->id] ?? null, 'test' => in_array((int) $a->id, $tests, true), 'taken' => in_array((int) $a->id, $takenA, true)];
        }
        // Customers first, then leads; real before test; by name.
        usort($out, fn ($x, $y) => [$x['kind'] !== 'customer', $x['test'], mb_strtolower($x['name'])] <=> [$y['kind'] !== 'customer', $y['test'], mb_strtolower($y['name'])]);
        return $out;
    }
}
