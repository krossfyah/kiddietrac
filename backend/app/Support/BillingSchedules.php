<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AUTOMATED BILLING SCHEDULES (2026-11-28). A family's saved schedule (billing_schedules: weekly / biweekly / monthly)
 * raises its tuition invoice on its own day, in the agency's timezone, through App\Support\FamilyInvoice (the same
 * invoice the monthly batch makes). If the family pays by autopay, the invoice is charged at once through
 * AutopayRunner (the one charge path; its Stripe idempotency key and payment_runs log apply).
 *
 * What each run covers:
 *   weekly    the 7 days from the invoice date      (monthly tuition x 12 / 52)
 *   biweekly  the 14 days from the invoice date     (monthly tuition x 24 / 52), every second week from anchor_date
 *   monthly   the calendar month: on the 1st, that month; on any later day, the NEXT month (billed in advance)
 *
 * Guards:
 *   - billing_schedule_runs is UNIQUE (family_id, period_start): the row is claimed BEFORE the invoice is raised,
 *     so two runs, a crash and a re-run, or the hourly job overlapping cannot bill a period twice.
 *   - A period already billed (monthly batch, a manual invoice, the partner's billing) is skipped, not doubled.
 *   - A period the centre is closed for every day of (centre_closures with affects_billing) is skipped.
 *   - Only schedules saved since this existed run (auto_since). A missed date more than CATCH_UP_DAYS old is not
 *     billed late; it is logged as missed and the schedule moves on.
 *   - Mail goes through the normal invoice email (MailScope billing gate; test agencies send nothing).
 */
final class BillingSchedules
{
    public const CATCH_UP_DAYS = 3;

    /** The first billing date on or after $from (Y-m-d), for this schedule. Pure date maths, no timezone. */
    public static function nextDate(object|array $s, string $from): ?string
    {
        $s = (object) $s;
        $d = Carbon::parse($from)->startOfDay();
        switch ($s->frequency ?? '') {
            case 'weekly':
                $dow = (int) ($s->day_of_week ?? 1);
                while ($d->dayOfWeek !== $dow) $d->addDay();
                return $d->toDateString();
            case 'biweekly':
                $dow = (int) ($s->day_of_week ?? 1);
                $anchor = ! empty($s->anchor_date) ? Carbon::parse($s->anchor_date)->startOfDay() : null;
                if (! $anchor || $anchor->dayOfWeek !== $dow) {
                    while ($d->dayOfWeek !== $dow) $d->addDay();
                    return $d->toDateString();
                }
                if ($d->lte($anchor)) return $anchor->toDateString();
                $weeks = intdiv((int) $anchor->diffInDays($d) + 13, 14) * 2;
                return $anchor->copy()->addWeeks($weeks)->toDateString();
            case 'monthly':
                $dom = max(1, min(31, (int) ($s->day_of_month ?? 1)));
                $m = $d->copy()->startOfMonth();
                for ($i = 0; $i < 2; $i++) {
                    $c = $m->copy()->day(min($dom, $m->daysInMonth));
                    if ($c->gte($d)) return $c->toDateString();
                    $m->addMonthNoOverflow();
                }
                return $m->day(min($dom, $m->daysInMonth))->toDateString();
        }
        return null;
    }

    /** [period_start, period_end, factor, label] billed by a run on $runOn. */
    public static function periodFor(object $s, string $runOn): array
    {
        $r = Carbon::parse($runOn)->startOfDay();
        switch ($s->frequency) {
            case 'weekly':
                return [$r->toDateString(), $r->copy()->addDays(6)->toDateString(), 12 / 52, 'week of ' . $r->format('M j, Y')];
            case 'biweekly':
                return [$r->toDateString(), $r->copy()->addDays(13)->toDateString(), 24 / 52, $r->format('M j') . ' – ' . $r->copy()->addDays(13)->format('M j, Y')];
            default:
                $start = $r->day === 1 ? $r->copy() : $r->copy()->startOfMonth()->addMonthNoOverflow();
                return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString(), 1.0, null];
        }
    }

    /** Days after the invoice date it is due: 15 for a month (as the monthly batch), 7 for a week or two. */
    public static function dueDays(object $s): int
    {
        return $s->frequency === 'monthly' ? 15 : 7;
    }

    /** Active, automated schedules of live families in this agency. */
    private static function schedules(int $agencyId)
    {
        return DB::table('billing_schedules as s')
            ->join('families as f', 'f.id', '=', 's.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)->where('s.active', 1)->whereNotNull('s.auto_since')->whereNull('f.deleted_at')
            ->get(['s.*', 'f.centre_id', 'f.family_name', 'f.autopay_enabled']);
    }

    /** Agencies that have at least one automated schedule. */
    public static function agencies(): array
    {
        return DB::table('billing_schedules as s')->join('families as f', 'f.id', '=', 's.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('s.active', 1)->whereNotNull('s.auto_since')->distinct()->pluck('c.agency_id')->map(fn ($v) => (int) $v)->all();
    }

    /** One agency's due schedules. Returns a list of [family, run_on, status, message, invoice_id]. */
    public static function runAgency(int $agencyId, bool $dry = false, ?array $onlyFamilies = null): array
    {
        $today = AgencyTime::today($agencyId);
        $out = [];
        foreach (self::schedules($agencyId) as $s) {
            if ($onlyFamilies !== null && ! in_array((int) $s->family_id, $onlyFamilies, true)) continue;
            $runOn = $s->next_charge_at ? substr((string) $s->next_charge_at, 0, 10) : self::nextDate($s, $today);
            $guard = 0;
            while ($runOn && $runOn <= $today && $guard++ < 6) {
                if (Carbon::parse($runOn)->diffInDays(Carbon::parse($today)) > self::CATCH_UP_DAYS) {
                    $out[] = self::log($agencyId, $s, $runOn, 'skipped', 'Missed: this date passed more than ' . self::CATCH_UP_DAYS . ' days ago, so it was not billed late.', null, null, $dry);
                } else {
                    $out[] = self::runOne($agencyId, $s, $runOn, $dry);
                }
                $runOn = self::nextDate($s, Carbon::parse($runOn)->addDay()->toDateString());
            }
            if (! $dry && $runOn && $runOn !== ($s->next_charge_at ? substr((string) $s->next_charge_at, 0, 10) : null)) {
                DB::table('billing_schedules')->where('id', $s->id)->update(['next_charge_at' => $runOn . ' 00:00:00', 'updated_at' => now()]);
            }
        }
        return $out;
    }

    private static function log(int $agencyId, object $s, string $runOn, string $status, string $message, ?int $invoiceId, ?float $amount, bool $dry): array
    {
        [$ps, $pe] = self::periodFor($s, $runOn);
        $row = ['family' => $s->family_name, 'family_id' => (int) $s->family_id, 'run_on' => $runOn, 'period_start' => $ps, 'period_end' => $pe,
            'status' => $status, 'message' => $message, 'invoice_id' => $invoiceId];
        if ($dry) return $row;
        DB::table('billing_schedule_runs')->insertOrIgnore([
            'agency_id' => $agencyId, 'family_id' => $s->family_id, 'schedule_id' => $s->id, 'run_on' => $runOn,
            'period_start' => $ps, 'period_end' => $pe, 'status' => $status, 'invoice_id' => $invoiceId, 'amount' => $amount,
            'message' => mb_substr($message, 0, 500), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $row;
    }

    /** Why this period must not be billed, or null. */
    private static function blocker(int $agencyId, object $s, string $ps, string $pe): ?string
    {
        if (! Live::centreOpen((int) $s->centre_id)) return 'The centre is closed, so nothing was billed.';
        $already = BilledFamilies::forMonth([(int) $s->family_id], $ps, $pe, false);
        if ($already['native']) return 'Already billed for this period (another invoice covers it).';
        if ($already['partner']) return 'Already billed for this period by the partner\'s billing.';
        $days = (int) Carbon::parse($ps)->diffInDays(Carbon::parse($pe)) + 1;
        $rows = DB::table('centre_closures')->where('centre_id', $s->centre_id)->where('affects_billing', 1)
            ->whereDate('closure_date', '<=', $pe)->where(fn ($q) => $q->whereDate('end_date', '>=', $ps)->orWhere(fn ($w) => $w->whereNull('end_date')->whereDate('closure_date', '>=', $ps)))
            ->get(['closure_date', 'end_date']);
        $closed = [];
        foreach ($rows as $r) {
            $a = Carbon::parse(max((string) $r->closure_date, $ps));
            $b = Carbon::parse(min((string) ($r->end_date ?: $r->closure_date), $pe));
            for ($d = $a->copy(); $d->lte($b); $d->addDay()) $closed[$d->toDateString()] = 1;
        }
        $closedDays = count($closed);
        if ($days > 0 && $closedDays >= $days) return 'The centre is closed for the whole period, so nothing was billed.';
        return null;
    }

    private static function runOne(int $agencyId, object $s, string $runOn, bool $dry): array
    {
        [$ps, $pe, $factor, $label] = self::periodFor($s, $runOn);
        if (DB::table('billing_schedule_runs')->where('family_id', $s->family_id)->where('period_start', $ps)->exists()) {
            return ['family' => $s->family_name, 'family_id' => (int) $s->family_id, 'run_on' => $runOn, 'period_start' => $ps, 'status' => 'done', 'message' => 'Already run', 'invoice_id' => null];
        }
        if ($why = self::blocker($agencyId, $s, $ps, $pe)) return self::log($agencyId, $s, $runOn, 'skipped', $why, null, null, $dry);
        $kids = FamilyInvoice::enrollments(fn ($q) => $q->where('families.id', $s->family_id))->get((int) $s->family_id);
        $kids = $kids ? $kids->filter(fn ($k) => (float) $k->monthly_fee > 0)->values() : collect();
        if ($kids->isEmpty()) return self::log($agencyId, $s, $runOn, 'skipped', 'No enrolled children with a tuition fee, so nothing was billed.', null, null, $dry);
        if ($dry) return ['family' => $s->family_name, 'family_id' => (int) $s->family_id, 'run_on' => $runOn, 'period_start' => $ps, 'period_end' => $pe, 'status' => 'would_invoice', 'message' => $kids->count() . ' child(ren)', 'invoice_id' => null];

        // Claim the period first: the UNIQUE key is the guard against billing it twice.
        $claimed = DB::table('billing_schedule_runs')->insertOrIgnore([
            'agency_id' => $agencyId, 'family_id' => $s->family_id, 'schedule_id' => $s->id, 'run_on' => $runOn,
            'period_start' => $ps, 'period_end' => $pe, 'status' => 'running', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $claimed) return ['family' => $s->family_name, 'family_id' => (int) $s->family_id, 'run_on' => $runOn, 'period_start' => $ps, 'status' => 'done', 'message' => 'Already run', 'invoice_id' => null];
        $runRow = fn () => DB::table('billing_schedule_runs')->where('family_id', $s->family_id)->where('period_start', $ps);

        try {
            $issue = Carbon::parse($runOn);
            $due = $issue->copy()->addDays(self::dueDays($s));
            $invoiceId = DB::transaction(fn () => FamilyInvoice::raise((int) $s->family_id, (int) $s->centre_id, $agencyId, $kids, $issue, $due,
                FamilyInvoice::siblingTiers($agencyId), Carbon::parse($ps), Carbon::parse($pe), (float) $factor, $label));
        } catch (\Throwable $e) {
            Log::error('billing schedule invoice failed', ['family' => $s->family_id, 'run_on' => $runOn, 'error' => $e->getMessage()]);
            $runRow()->update(['status' => 'failed', 'message' => 'The invoice could not be raised: ' . mb_substr($e->getMessage(), 0, 300), 'updated_at' => now()]);
            self::alert($agencyId, $s, 'The scheduled invoice for ' . $s->family_name . ' could not be raised. Open Billing schedule to see why.');
            return ['family' => $s->family_name, 'family_id' => (int) $s->family_id, 'run_on' => $runOn, 'period_start' => $ps, 'status' => 'failed', 'message' => $e->getMessage(), 'invoice_id' => null];
        }
        $amount = (float) DB::table('invoices')->where('id', $invoiceId)->value('total');
        $status = 'invoiced';
        $message = 'Invoice raised.';
        $paymentRunId = null;

        try { \App\Http\Controllers\Api\InvoiceController::emailInvoiceToFamily($invoiceId); }
        catch (\Throwable $e) { Log::warning('scheduled invoice email failed', ['invoice' => $invoiceId, 'error' => $e->getMessage()]); }

        $autopay = (int) $s->autopay_enabled === 1
            || DB::table('guardians')->where('family_id', $s->family_id)->where('autopay_enabled', 1)->exists();
        if ($autopay && $amount > 0) {
            if (Features::skips($agencyId, 'autopay', 'scheduled invoice autopay')) {
                $message = 'Invoice raised. Autopay is not in the plan, so the card was not charged.';
            } else {
                try {
                    $r = AutopayRunner::run($agencyId, 'schedule', null, [$invoiceId]);
                    $paymentRunId = $r['run_id'] ?? null;
                    $res = collect($r['results'] ?? [])->where('invoice_id', $invoiceId);
                    if ($res->contains(fn ($x) => ($x['result'] ?? '') === 'failed')) {
                        $status = 'charge_failed';
                        $message = 'Invoice raised; the autopay charge failed: ' . ($res->firstWhere('result', 'failed')['message'] ?? 'card declined');
                    } elseif ($res->contains(fn ($x) => in_array($x['result'] ?? '', ['charged', 'succeeded'], true))) {
                        $status = 'charged';
                        $message = 'Invoice raised and charged by autopay.';
                    } else {
                        $message = 'Invoice raised. Autopay did not charge it now (' . (($res->first()['message'] ?? null) ?: 'no payment processor or card') . '); it stays open.';
                    }
                } catch (\Throwable $e) {
                    $status = 'charge_failed';
                    $message = 'Invoice raised; the autopay charge could not run: ' . mb_substr($e->getMessage(), 0, 200);
                }
            }
        }
        $runRow()->update(['status' => $status, 'invoice_id' => $invoiceId, 'amount' => $amount, 'payment_run_id' => $paymentRunId,
            'message' => mb_substr($message, 0, 500), 'updated_at' => now()]);
        Audit::write(['user_id' => null, 'agency_id' => $agencyId, 'action' => 'billing_schedule.' . $status,
            'entity_type' => 'invoice', 'entity_id' => $invoiceId,
            'payload' => json_encode(['family_id' => (int) $s->family_id, 'period_start' => $ps, 'period_end' => $pe, 'amount' => $amount, 'message' => $message]),
            'created_at' => now()]);
        if ($status === 'charge_failed') self::alert($agencyId, $s, 'The autopay charge for ' . $s->family_name . '\'s scheduled invoice failed. The invoice is open.');

        return ['family' => $s->family_name, 'family_id' => (int) $s->family_id, 'run_on' => $runOn, 'period_start' => $ps, 'period_end' => $pe, 'status' => $status, 'message' => $message, 'invoice_id' => $invoiceId];
    }

    /** Bell to the agency's admins and the centre's directors (never an outsider). */
    private static function alert(int $agencyId, object $s, string $body): void
    {
        foreach (CentreScope::managers($agencyId, [(int) $s->centre_id])->pluck('u.id')->unique() as $uid) {
            Notify::write(['user_id' => (int) $uid, 'agency_id' => $agencyId, 'type' => 'billing_schedule_failed',
                'title' => 'Scheduled billing needs attention', 'body' => $body,
                'data' => json_encode(['link' => '#billing-schedule', 'family_id' => (int) $s->family_id]), 'created_at' => now()]);
        }
    }
}
