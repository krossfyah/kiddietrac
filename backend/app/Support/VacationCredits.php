<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * VACATION HOLD CREDITS THAT REACH THE INVOICE (2026-11-28).
 *
 * The agency's rule lives in Billing setup (BillingRules::vacationCredit): none (default) | percent of the daily fee |
 * flat dollars, for each SCHEDULED care day of each child inside the hold. Closed days (centre_closures that affect
 * billing) are not counted; a child with no schedule on file counts Monday to Friday. A child's daily fee is their
 * monthly tuition x 12 / 52 / their days a week.
 *
 *  - Approving a hold works the credit out from the rule (or takes the office's own amount) and stores it on the hold
 *    with how it was reached: credit_state 'pending', or 'none' when there is nothing to give.
 *  - When the hold has ENDED (the agency's own today), the next invoice raised by the shared builder (FamilyInvoice::raise:
 *    the monthly batch and the automated billing schedules) carries it as a "Vacation hold credit" line. A credit is
 *    never more than that invoice: what does not fit waits for the following one.
 *  - Never twice: the hold rows are locked inside the invoice's transaction, what is already on live invoices is
 *    subtracted (vacation_hold_credit_lines; a voided invoice no longer counts, so its credit comes back), and
 *    (hold, invoice) is UNIQUE.
 *  - Cancelling a hold before its credit is on an invoice reverses it (credit_state 'reversed'). Once it is on an
 *    invoice, the hold can't be cancelled here: the office voids or adjusts that invoice.
 *  - Holds decided before this existed have credit_state NULL and are never invoiced by themselves.
 */
final class VacationCredits
{
    private const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri'];

    /** The hold's family's agency, from its centre. */
    public static function agencyOfFamily(int $familyId): int
    {
        return (int) DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('f.id', $familyId)->value('c.agency_id');
    }

    /**
     * What the agency's rule gives for a family away from $start to $end (Y-m-d, inclusive).
     *
     * @return array{mode:string, rate:float, days:int, amount:float, children:array<int,array{name:string,days:int,daily:float,amount:float}>}
     */
    public static function compute(int $familyId, string $start, string $end): array
    {
        $agencyId = self::agencyOfFamily($familyId);
        $rule = BillingRules::vacationCredit($agencyId);
        $out = ['mode' => $rule['mode'], 'rate' => (float) $rule['value'], 'days' => 0, 'amount' => 0.0, 'children' => []];
        $centreId = (int) DB::table('families')->where('id', $familyId)->value('centre_id');
        $kids = FamilyInvoice::enrollments(fn ($q) => $q->where('families.id', $familyId))->get($familyId) ?? collect();
        if (! $kids->count()) return $out;

        $s = Carbon::parse(substr($start, 0, 10))->startOfDay();
        $e = Carbon::parse(substr($end, 0, 10))->startOfDay();
        if ($e->lt($s)) return $out;
        if ($s->diffInDays($e) > 366) $e = $s->copy()->addDays(366);
        $closed = self::closedDays($centreId, $s->toDateString(), $e->toDateString());

        foreach ($kids as $k) {
            $raw = trim((string) ($k->schedule ?? ''));
            $sched = ($raw === '' || $raw === '[]' || $raw === 'null') ? self::WEEKDAYS : CareSchedule::daysOf($raw);
            $days = 0;
            for ($d = $s->copy(); $d->lte($e); $d->addDay()) {
                if (in_array(strtolower($d->format('D')), $sched, true) && ! isset($closed[$d->toDateString()])) $days++;
            }
            $daily = round((float) $k->monthly_fee * 12 / 52 / max(1, count($sched)), 2);
            $amt = match ($rule['mode']) {
                'percent' => round($daily * $rule['value'] / 100 * $days, 2),
                'flat' => round($rule['value'] * $days, 2),
                default => 0.0,
            };
            $out['days'] += $days;
            $out['amount'] = round($out['amount'] + $amt, 2);
            $out['children'][] = ['name' => trim($k->first_name . ' ' . $k->last_name), 'days' => $days, 'daily' => $daily, 'amount' => $amt];
        }

        return $out;
    }

    /** Y-m-d => true for the days the centre is closed with billing affected. */
    private static function closedDays(int $centreId, string $from, string $to): array
    {
        $out = [];
        if (! $centreId) return $out;
        $rows = DB::table('centre_closures')->where('centre_id', $centreId)->where('affects_billing', 1)
            ->whereDate('closure_date', '<=', $to)
            ->where(fn ($q) => $q->whereDate('end_date', '>=', $from)->orWhere(fn ($w) => $w->whereNull('end_date')->whereDate('closure_date', '>=', $from)))
            ->get(['closure_date', 'end_date']);
        foreach ($rows as $r) {
            $a = Carbon::parse(max(substr((string) $r->closure_date, 0, 10), $from));
            $b = Carbon::parse(min(substr((string) ($r->end_date ?: $r->closure_date), 0, 10), $to));
            for ($d = $a->copy(); $d->lte($b); $d->addDay()) $out[$d->toDateString()] = true;
        }

        return $out;
    }

    /** The rule in words, for staff screens. */
    public static function ruleText(string $mode, float $rate): string
    {
        return match ($mode) {
            'percent' => rtrim(rtrim(number_format($rate, 2), '0'), '.') . '% of the daily fee for each scheduled day',
            'flat' => BillingRules::money($rate) . ' for each scheduled day',
            default => 'No credit',
        };
    }

    /** "Jul 21 – Jul 28, 2026" */
    public static function span(string $start, string $end): string
    {
        $a = Carbon::parse(substr($start, 0, 10));
        $b = Carbon::parse(substr($end, 0, 10));

        return $a->format($a->year === $b->year ? 'M j' : 'M j, Y') . ' – ' . $b->format('M j, Y');
    }

    /** What is already on live (not voided) invoices, by hold id. */
    public static function appliedFor(array $holdIds): array
    {
        $holdIds = array_values(array_filter(array_map('intval', $holdIds)));
        if (! $holdIds) return [];
        $out = [];
        foreach (DB::table('vacation_hold_credit_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->whereIn('l.hold_id', $holdIds)->where('i.status', '<>', 'void')
            ->orderBy('l.id')->get(['l.hold_id', 'l.amount', 'i.id as invoice_id', 'i.invoice_number']) as $r) {
            $h = (int) $r->hold_id;
            $out[$h] ??= ['amount' => 0.0, 'invoices' => []];
            $out[$h]['amount'] = round($out[$h]['amount'] + (float) $r->amount, 2);
            $out[$h]['invoices'][] = ['id' => (int) $r->invoice_id, 'number' => (string) $r->invoice_number];
        }

        return $out;
    }

    /**
     * The credit lines for the family's next invoice. Called by FamilyInvoice::raise INSIDE its transaction, with what
     * the invoice comes to before them ($room): the lines never take an invoice below zero. Each line records itself
     * (on_insert) once its invoice line exists.
     */
    public static function linesFor(int $familyId, int $centreId, float $room): array
    {
        $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        if (! $agencyId || $room < 0.01) return [];
        $today = AgencyTime::today($agencyId);
        $holds = DB::table('vacation_holds')->where('family_id', $familyId)->where('status', 'approved')
            ->whereIn('credit_state', ['pending', 'applied'])->where('credit_amount', '>', 0)
            ->where('end_date', '<', $today)->orderBy('end_date')->orderBy('id')->lockForUpdate()->get();
        if ($holds->isEmpty()) return [];
        $applied = self::appliedFor($holds->pluck('id')->all());
        $lines = [];
        foreach ($holds as $h) {
            $left = round((float) $h->credit_amount - ($applied[(int) $h->id]['amount'] ?? 0.0), 2);
            if ($left < 0.01) continue;
            $amt = round(min($left, $room), 2);
            if ($amt < 0.01) break;
            $room = round($room - $amt, 2);
            $holdId = (int) $h->id;
            $rest = round($left - $amt, 2);
            $lines[] = [
                'description' => 'Vacation hold credit — ' . self::span((string) $h->start_date, (string) $h->end_date) . ($rest >= 0.01 ? ' (part; ' . BillingRules::money($rest) . ' on your next invoice)' : ''),
                'amount' => -$amt,
                'line_type' => 'adjustment',
                'on_insert' => function (int $invoiceId, int $lineId) use ($holdId, $familyId, $amt, $rest, $agencyId) {
                    DB::table('vacation_hold_credit_lines')->insert([
                        'hold_id' => $holdId, 'family_id' => $familyId, 'invoice_id' => $invoiceId, 'invoice_line_id' => $lineId,
                        'amount' => $amt, 'created_at' => now(),
                    ]);
                    DB::table('vacation_holds')->where('id', $holdId)->update(['credit_state' => $rest >= 0.01 ? 'pending' : 'applied', 'updated_at' => now()]);
                    Audit::write(['user_id' => null, 'agency_id' => $agencyId, 'action' => 'vacation_hold.credited', 'entity_type' => 'vacation_hold', 'entity_id' => $holdId,
                        'payload' => json_encode(['summary' => 'Vacation hold credit of ' . BillingRules::money($amt) . ' put on invoice #' . $invoiceId, 'invoice_id' => $invoiceId, 'amount' => $amt], JSON_UNESCAPED_UNICODE),
                        'created_at' => now()]);
                },
            ];
            if ($room < 0.01) break;
        }

        return $lines;
    }

    /**
     * Cancel a hold (any status but denied / cancelled). A credit not yet on an invoice is reversed; one already on an
     * invoice refuses. @return array{0:bool,1:string}
     */
    public static function cancel(int $holdId, ?int $byUserId): array
    {
        return DB::transaction(function () use ($holdId, $byUserId) {
            $h = DB::table('vacation_holds')->where('id', $holdId)->lockForUpdate()->first();
            if (! $h) return [false, 'That vacation hold no longer exists.'];
            if (in_array($h->status, ['denied', 'cancelled'], true)) return [false, 'This hold is already ' . $h->status . '.'];
            $applied = self::appliedFor([$holdId])[$holdId] ?? null;
            if ($applied && $applied['amount'] >= 0.01) {
                $nums = implode(', ', array_map(fn ($i) => $i['number'] ?: ('#' . $i['id']), $applied['invoices']));

                return [false, 'Its credit of ' . BillingRules::money($applied['amount']) . ' is already on invoice ' . $nums . '. Void or adjust that invoice instead.'];
            }
            DB::table('vacation_holds')->where('id', $holdId)->update([
                'status' => 'cancelled',
                'credit_state' => $h->credit_state === 'pending' ? 'reversed' : $h->credit_state,
                'cancelled_at' => now(), 'cancelled_by_id' => $byUserId, 'updated_at' => now(),
            ]);

            return [true, 'Hold cancelled.'];
        });
    }
}
