<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Split family billing.
 *
 * v1 (2026-09-29): separated parents each pay a PERCENTAGE of every invoice.
 * v2 (2026-10-30, Anthony: "do we have split payment setup today with multiple guardians paying an invoice, fees etc"
 *     and "add configuration within families to setup the split payment settings"):
 *   single   - one payer (staff pick who)
 *   even     - equal shares between the chosen guardians
 *   percent  - a percentage each (must total 100)
 *   fixed    - named guardians pay a fixed amount per invoice, one guardian pays the rest
 *   by_type  - one guardian pays tuition (incl. the subsidy co-pay), another pays fees & extras (late fees, trips,
 *              supplies, registration...) - each type can also be "split" by the percentages
 *   by_child - each child's charges go to one guardian (families with several children)
 * Each setting is kept in family_billing_splits with the date it applies from, so history is visible and an invoice is
 * always split by the rules in force on its issue date. The rules are frozen onto an issued invoice
 * (invoices.split_snapshot = {"v":2,"cfg":...}); the AMOUNTS are worked out from the invoice's current lines, so a fee
 * added later follows the rules too. A line with invoice_lines.payer_guardian_id belongs to that guardian outright (a
 * late fee charged on one guardian's overdue share).
 *
 * THE INVOICE STAYS ONE FAMILY INVOICE (totals, CWELCC reports, ledgers, statuses unchanged). On top of it each payer
 * has a share; paid = their own payments (payments.payer_guardian_id) + their proportion of payments nobody was
 * attributed to (family autopay card, older payments), less any refunds of those payments.
 */
final class BillingSplit
{
    public const MODES = ['single', 'even', 'percent', 'fixed', 'by_type', 'by_child'];

    /** Line types that are care itself (and its subsidy / discounts). Everything else is "fees & extras". */
    public const TUITION_TYPES = ['tuition', 'care', 'childcare', 'base', 'subsidy', 'copay', 'co_pay', 'discount', 'credit', 'sibling_discount', 'cwelcc'];

    // ---------------------------------------------------------------------------------------------------------------
    // Settings

    /** The split rules in force for a family on a date (default today), or null when the family pays as one. */
    public static function config(int $familyId, ?string $asOf = null): ?array
    {
        $asOf = $asOf ?: AgencyTime::todayDate()->toDateString();
        $row = DB::table('family_billing_splits')->where('family_id', $familyId)->where('effective_from', '<=', substr($asOf, 0, 10))
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
        if ($row) {
            $cfg = json_decode((string) $row->config, true);
            return is_array($cfg) ? self::normalise($cfg) : null;
        }
        // No v2 setting in force on that date: the v1 percentage setting, if any.
        return self::legacy($familyId);
    }

    /** v1: families.billing_split + guardians.billing_share_pct. */
    private static function legacy(int $familyId): ?array
    {
        $mode = (string) DB::table('families')->where('id', $familyId)->value('billing_split');
        if (! in_array($mode, ['split_50_50', 'custom'], true)) return null;
        $rows = DB::table('guardians')->where('family_id', $familyId)->where('can_receive_billing', 1)->where('billing_share_pct', '>', 0)
            ->orderByDesc('is_primary')->orderBy('id')->get(['id', 'billing_share_pct']);
        if ($rows->count() < 2) return null;
        return self::normalise(['mode' => 'percent', 'shares' => $rows->map(fn ($r) => ['guardian_id' => (int) $r->id, 'pct' => (float) $r->billing_share_pct])->all()]);
    }

    /** Clean a config: known keys only, ints/floats, and the guardian list every mode needs. */
    public static function normalise(array $c): array
    {
        $mode = in_array($c['mode'] ?? '', self::MODES, true) ? $c['mode'] : 'single';
        $out = ['mode' => $mode];
        $gid = fn ($v) => ($v === 'split' ? 'split' : (int) $v);
        switch ($mode) {
            case 'single':
                $out['payer'] = (int) ($c['payer'] ?? 0);
                break;
            case 'even':
                $out['guardians'] = array_values(array_unique(array_map('intval', $c['guardians'] ?? array_column($c['shares'] ?? [], 'guardian_id'))));
                break;
            case 'percent':
                $out['shares'] = array_values(array_map(fn ($s) => ['guardian_id' => (int) $s['guardian_id'], 'pct' => round((float) $s['pct'], 2)], array_filter($c['shares'] ?? [], fn ($s) => (float) ($s['pct'] ?? 0) > 0)));
                break;
            case 'fixed':
                $out['fixed'] = array_values(array_map(fn ($s) => ['guardian_id' => (int) $s['guardian_id'], 'amount' => round((float) $s['amount'], 2)], array_filter($c['fixed'] ?? [], fn ($s) => (float) ($s['amount'] ?? 0) > 0)));
                $out['rest'] = (int) ($c['rest'] ?? 0);
                break;
            case 'by_type':
                $out['tuition'] = $gid($c['tuition'] ?? 0);
                $out['fees'] = $gid($c['fees'] ?? 0);
                if (($out['tuition'] === 'split' || $out['fees'] === 'split')) {
                    $out['shares'] = array_values(array_map(fn ($s) => ['guardian_id' => (int) $s['guardian_id'], 'pct' => round((float) $s['pct'], 2)], array_filter($c['shares'] ?? [], fn ($s) => (float) ($s['pct'] ?? 0) > 0)));
                }
                break;
            case 'by_child':
                $map = [];
                foreach ((array) ($c['children'] ?? []) as $child => $g) { if ((int) $g > 0) $map[(string) (int) $child] = (int) $g; }
                $out['children'] = $map;
                $out['default'] = (int) ($c['default'] ?? 0);
                break;
        }
        return $out;
    }

    /** Every guardian a config names. */
    public static function guardiansOf(array $cfg): array
    {
        $ids = [];
        switch ($cfg['mode']) {
            case 'single': $ids = [$cfg['payer'] ?? 0]; break;
            case 'even': $ids = $cfg['guardians'] ?? []; break;
            case 'percent': $ids = array_column($cfg['shares'] ?? [], 'guardian_id'); break;
            case 'fixed': $ids = array_merge(array_column($cfg['fixed'] ?? [], 'guardian_id'), [$cfg['rest'] ?? 0]); break;
            case 'by_type':
                $ids = array_merge(array_filter([$cfg['tuition'] ?? 0, $cfg['fees'] ?? 0], 'is_int'), array_column($cfg['shares'] ?? [], 'guardian_id'));
                break;
            case 'by_child': $ids = array_merge(array_values($cfg['children'] ?? []), [$cfg['default'] ?? 0]); break;
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * Check a config against the family. Returns an error sentence, or null when it is valid.
     * $children = the family's child ids (by_child).
     */
    public static function validate(array $cfg, array $guardianIds, array $childIds = []): ?string
    {
        $named = self::guardiansOf($cfg);
        foreach ($named as $g) { if (! in_array($g, $guardianIds, true)) return 'That guardian is not part of this family.'; }
        switch ($cfg['mode']) {
            case 'single':
                return ($cfg['payer'] ?? 0) ? null : 'Choose who pays.';
            case 'even':
                return count($cfg['guardians'] ?? []) >= 2 ? null : 'Choose at least two guardians to share evenly.';
            case 'percent':
                if (count($cfg['shares'] ?? []) < 2) return 'A split needs at least two guardians with a share.';
                $sum = round(array_sum(array_column($cfg['shares'], 'pct')), 2);
                return abs($sum - 100) <= 0.01 ? null : 'The shares must add up to 100%. They add up to ' . $sum . '%.';
            case 'fixed':
                if (! ($cfg['rest'] ?? 0)) return 'Choose who pays the rest of each invoice.';
                if (! count($cfg['fixed'] ?? [])) return 'Enter the fixed amount at least one guardian pays.';
                foreach ($cfg['fixed'] as $f) { if ((int) $f['guardian_id'] === (int) $cfg['rest']) return 'The guardian who pays the rest cannot also have a fixed amount.'; }
                return null;
            case 'by_type':
                if (! $cfg['tuition'] || ! $cfg['fees']) return 'Choose who pays tuition and who pays fees & extras.';
                if ($cfg['tuition'] === 'split' || $cfg['fees'] === 'split') {
                    if (count($cfg['shares'] ?? []) < 2) return 'Enter the percentages for the charges you split.';
                    $sum = round(array_sum(array_column($cfg['shares'], 'pct')), 2);
                    if (abs($sum - 100) > 0.01) return 'The percentages must add up to 100%. They add up to ' . $sum . '%.';
                }
                return count($named) >= 2 ? null : 'Choose two different guardians (or split one of the types) - otherwise it is one payer.';
            case 'by_child':
                if (! ($cfg['default'] ?? 0)) return 'Choose who pays charges that are not for one child.';
                foreach (array_keys($cfg['children'] ?? []) as $c) { if (! in_array((int) $c, $childIds, true)) return 'That child is not part of this family.'; }
                return count($named) >= 2 ? null : 'Give at least one child a different payer - otherwise it is one payer.';
        }
        return 'Unknown split.';
    }

    /** Save a setting (history row) and keep the v1 columns in step. */
    public static function save(int $familyId, array $cfg, string $effectiveFrom, ?int $userId): int
    {
        $cfg = self::normalise($cfg);
        return DB::transaction(function () use ($familyId, $cfg, $effectiveFrom, $userId) {
            $id = DB::table('family_billing_splits')->insertGetId([
                'family_id' => $familyId, 'mode' => $cfg['mode'], 'config' => json_encode($cfg),
                'effective_from' => $effectiveFrom, 'created_by' => $userId, 'created_at' => now(),
            ]);
            // The chip on the family list shows what is in force today.
            $now = self::config($familyId);
            DB::table('families')->where('id', $familyId)->update(['billing_split' => $now ? $now['mode'] : 'single', 'updated_at' => now()]);
            // v1 columns, which older screens and the billing-email list still read.
            $pcts = [];
            if ($now && $now['mode'] === 'percent') foreach ($now['shares'] as $s) $pcts[$s['guardian_id']] = $s['pct'];
            if ($now && $now['mode'] === 'even') foreach ($now['guardians'] as $g) $pcts[$g] = round(100 / max(1, count($now['guardians'])), 2);
            if ($now && $now['mode'] === 'single') $pcts[$now['payer']] = 100;
            if ($pcts) {
                DB::table('guardians')->where('family_id', $familyId)->update(['billing_share_pct' => 0]);
                foreach ($pcts as $g => $p) DB::table('guardians')->where('id', $g)->where('family_id', $familyId)->update(['billing_share_pct' => $p]);
            }
            foreach ($now ? self::guardiansOf($now) : [] as $g) {
                DB::table('guardians')->where('id', $g)->where('family_id', $familyId)->update(['can_receive_billing' => 1]);
            }
            return $id;
        });
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Shares

    /** Is this family split today? */
    public static function isSplit(int $familyId): bool
    {
        $c = self::config($familyId);
        return $c && $c['mode'] !== 'single' && count(self::guardiansOf($c)) >= 2;
    }

    /** Did any invoice issued in the period carry a split (receipts must stay per payer after a family stops splitting)? */
    public static function hadSplit(int $familyId, string $from, string $to): bool
    {
        if (self::isSplit($familyId)) return true;
        return DB::table('invoices')->where('family_id', $familyId)->whereNotNull('split_snapshot')
            ->whereBetween('issued_at', [substr($from, 0, 10), substr($to, 0, 10)])->exists();
    }

    /** Names of guardians, keyed by guardian id. */
    public static function names(array $ids): array
    {
        if (! $ids) return [];
        return DB::table('guardians as g')->leftJoin('users as u', 'u.id', '=', 'g.user_id')->whereIn('g.id', $ids)
            ->get(['g.id', 'u.first_name', 'u.last_name', 'u.email'])
            ->mapWithKeys(fn ($r) => [(int) $r->id => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: ($r->email ?: 'Guardian #' . $r->id)])->all();
    }

    /** Current payers: [{guardian_id, user_id, name, email, pct}] (empty when the family pays as one). */
    public static function payers(int $familyId): array
    {
        $c = self::config($familyId);
        if (! $c || $c['mode'] === 'single') return [];
        $ids = self::guardiansOf($c);
        if (count($ids) < 2) return [];
        $pct = self::pctOf($c);
        $rows = DB::table('guardians as g')->leftJoin('users as u', 'u.id', '=', 'g.user_id')->whereIn('g.id', $ids)
            ->get(['g.id', 'g.user_id', 'u.first_name', 'u.last_name', 'u.email'])->keyBy('id');
        $out = [];
        foreach ($ids as $g) {
            $r = $rows[$g] ?? null;
            if (! $r) continue;
            $out[] = ['guardian_id' => $g, 'user_id' => $r->user_id ? (int) $r->user_id : null,
                'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: ($r->email ?: 'Guardian #' . $g),
                'email' => $r->email, 'pct' => $pct[$g] ?? null];
        }
        return $out;
    }

    /** Percentages where the mode has them (even / percent), else []. */
    private static function pctOf(array $c): array
    {
        $out = [];
        if ($c['mode'] === 'percent') foreach ($c['shares'] as $s) $out[$s['guardian_id']] = (float) $s['pct'];
        if ($c['mode'] === 'even') { $n = max(1, count($c['guardians'])); foreach ($c['guardians'] as $g) $out[$g] = round(100 / $n, 2); }
        return $out;
    }

    /**
     * Split a total by the rules. $lines = [{amount, child_id, line_type, payer_guardian_id}].
     * Returns [{guardian_id, share}] in the config's guardian order, cents exact (the last payer takes the odd cent).
     */
    public static function allocate(float $total, array $lines, array $cfg): array
    {
        $ids = self::guardiansOf($cfg);
        if (! $ids) return [];
        $raw = array_fill_keys($ids, 0.0);
        $rawCare = array_fill_keys($ids, 0.0);   // the part of $raw that is care (tuition-class lines)
        $direct = array_fill_keys($ids, 0.0);
        $pctW = function () use ($cfg, $ids) {
            $w = [];
            foreach ($cfg['shares'] ?? [] as $s) $w[(int) $s['guardian_id']] = (float) $s['pct'];
            $sum = array_sum($w);
            if ($sum <= 0) { foreach ($ids as $g) $w[$g] = 1; $sum = count($ids); }
            return array_map(fn ($v) => $v / $sum, $w);
        };
        // The base weights for the whole invoice (used when there are no lines, and for invoice-level money).
        $base = [];
        switch ($cfg['mode']) {
            case 'single': $base = [$cfg['payer'] => 1.0]; break;
            case 'even': foreach ($cfg['guardians'] as $g) $base[$g] = 1 / count($cfg['guardians']); break;
            case 'percent': $base = $pctW(); break;
        }

        $directTotal = 0.0; $ruleLines = 0.0;
        foreach ($lines as $l) {
            $amt = round((float) ($l['amount'] ?? 0), 2);
            $pg = (int) ($l['payer_guardian_id'] ?? 0);
            if ($pg && isset($direct[$pg])) { $direct[$pg] += $amt; $directTotal += $amt; continue; }
            $ruleLines += $amt;
            $w = $base;
            if ($cfg['mode'] === 'by_type') {
                $who = in_array((string) ($l['line_type'] ?? ''), self::TUITION_TYPES, true) ? $cfg['tuition'] : $cfg['fees'];
                $w = $who === 'split' ? $pctW() : [(int) $who => 1.0];
            } elseif ($cfg['mode'] === 'by_child') {
                $c = (string) (int) ($l['child_id'] ?? 0);
                $w = [(int) ($cfg['children'][$c] ?? $cfg['default']) => 1.0];
            }
            if ($cfg['mode'] === 'fixed') continue;   // fixed works on the invoice total below
            $care = in_array((string) ($l['line_type'] ?? ''), self::TUITION_TYPES, true);
            foreach ($w as $g => $f) { if (isset($raw[$g])) { $raw[$g] += $amt * $f; if ($care) $rawCare[$g] += $amt * $f; } }
        }

        $rulesTotal = round($total - $directTotal, 2);
        $out = array_fill_keys($ids, 0.0);
        if ($cfg['mode'] === 'fixed') {
            $left = $rulesTotal;
            foreach ($cfg['fixed'] as $f) {
                $a = round(max(0, min((float) $f['amount'], $left)), 2);
                $out[(int) $f['guardian_id']] += $a; $left = round($left - $a, 2);
            }
            $out[(int) $cfg['rest']] += $left;
        } else {
            $rawSum = array_sum($raw);
            $careSum = array_sum($rawCare);
            if (abs($rawSum) > 0.004) {
                /* Invoice-level money not on a line: a REDUCTION (subsidy, discount) comes off care - the subsidy co-pay
                   is the tuition payer's - while anything added (tax) follows all the lines in proportion. */
                $adj = $rulesTotal - $rawSum;
                $by = ($adj < 0 && $careSum > 0.004) ? $rawCare : $raw;
                $bySum = array_sum($by);
                foreach ($raw as $g => $v) $out[$g] = $v + $adj * ($by[$g] / $bySum);
            } else {
                $w = $base ?: array_fill_keys($ids, 1 / count($ids));
                foreach ($w as $g => $f) { if (isset($out[$g])) $out[$g] = $rulesTotal * $f; }
            }
        }
        foreach ($direct as $g => $v) $out[$g] += $v;

        // Cents: round each, the last guardian with a share takes what is left.
        $res = []; $run = 0.0; $last = null;
        foreach ($out as $g => $v) { if (abs($v) > 0.0001) $last = $g; }
        foreach ($out as $g => $v) {
            $s = $g === $last ? round($total - $run, 2) : round($v, 2);
            if ($g !== $last) $run = round($run + $s, 2);
            $res[] = ['guardian_id' => (int) $g, 'share' => $s];
        }
        if ($last === null) { foreach ($res as $i => $r) $res[$i]['share'] = 0.0; }
        return $res;
    }

    /** The rules that apply to an invoice: frozen on it once issued, else the family's on its issue date. */
    public static function rulesFor(object $invoice): ?array
    {
        if (! empty($invoice->split_snapshot)) {
            $snap = json_decode((string) $invoice->split_snapshot, true);
            if (is_array($snap) && ($snap['v'] ?? 0) === 2) return is_array($snap['cfg'] ?? null) ? self::normalise($snap['cfg']) : null;
            if (is_array($snap) && count($snap) >= 2 && isset($snap[0]['pct'])) {   // v1 snapshot: percentages
                return self::normalise(['mode' => 'percent', 'shares' => array_map(fn ($p) => ['guardian_id' => $p['guardian_id'], 'pct' => $p['pct']], $snap)]);
            }
        }
        $issued = ! empty($invoice->issued_at) ? substr((string) $invoice->issued_at, 0, 10) : null;
        $cfg = self::config((int) $invoice->family_id, $issued);
        if ($cfg && ($invoice->status ?? '') !== 'draft' && ! empty($invoice->id)) {
            DB::table('invoices')->where('id', $invoice->id)->whereNull('split_snapshot')
                ->update(['split_snapshot' => json_encode(['v' => 2, 'cfg' => $cfg])]);
        }
        return $cfg;
    }

    /**
     * Shares for one invoice, or null when the family pays as one.
     * [{guardian_id, name, pct, share, paid, balance}]
     */
    public static function shares(object $invoice): ?array
    {
        if (empty($invoice->family_id)) return null;
        $cfg = self::rulesFor($invoice);
        if (! $cfg || $cfg['mode'] === 'single' || count(self::guardiansOf($cfg)) < 2) return null;

        $total = round((float) $invoice->total, 2);
        $lines = empty($invoice->id) ? [] : DB::table('invoice_lines')->where('invoice_id', $invoice->id)
            ->get(['amount', 'child_id', 'line_type', 'payer_guardian_id'])->map(fn ($r) => (array) $r)->all();
        $alloc = self::allocate($total, $lines, $cfg);
        $names = self::names(array_column($alloc, 'guardian_id'));

        // Money in: each payment net of its refunds; unattributed money is shared in proportion to the shares.
        $pays = empty($invoice->id) ? collect() : DB::table('payments')->where('invoice_id', $invoice->id)->whereNotIn('status', ['failed', 'refunded', 'void'])
            ->get(['id', 'amount', 'payer_guardian_id']);
        $refunds = $pays->isEmpty() ? [] : DB::table('payment_refunds')->whereIn('payment_id', $pays->pluck('id'))->where('status', 'succeeded')
            ->groupBy('payment_id')->selectRaw('payment_id, SUM(amount) a')->pluck('a', 'payment_id')->all();
        $net = fn ($p) => round((float) $p->amount - (float) ($refunds[$p->id] ?? 0), 2);
        $unassigned = round($pays->whereNull('payer_guardian_id')->sum($net), 2);

        /* Unattributed money (the family card, older payments) goes to whoever still owes, in proportion to what they
           still owe - it never makes a guardian who has paid in full look overpaid while the other still owes. Anything
           beyond what is owed is shared by the shares. */
        $own = []; $owing = [];
        foreach ($alloc as $a) {
            $own[$a['guardian_id']] = round($pays->where('payer_guardian_id', $a['guardian_id'])->sum($net), 2);
            $owing[$a['guardian_id']] = max(0, round($a['share'] - $own[$a['guardian_id']], 2));
        }
        $unPart = self::spread($unassigned, $owing, array_column($alloc, 'share', 'guardian_id'));
        $out = [];
        foreach ($alloc as $a) {
            $g = $a['guardian_id'];
            $paid = round($own[$g] + ($unPart[$g] ?? 0), 2);
            $out[] = ['guardian_id' => $g, 'name' => $names[$g] ?? ('Guardian #' . $g),
                'pct' => $total > 0 ? round($a['share'] / $total * 100, 2) : 0.0,
                'share' => $a['share'], 'paid' => $paid, 'balance' => round(max(0, $a['share'] - $paid), 2),
                'unassigned_part' => round($unPart[$g] ?? 0, 2)];
        }
        return $out;
    }

    /** Spread $amount over $owing (by what each owes, capped), the rest by $weights. Cents exact. */
    private static function spread(float $amount, array $owing, array $weights): array
    {
        $out = array_fill_keys(array_keys($weights), 0.0);
        if (abs($amount) < 0.005) return $out;
        $owed = array_sum($owing);
        $first = round(min($amount, $owed), 2);
        if ($first > 0 && $owed > 0) foreach ($owing as $g => $o) $out[$g] += $first * $o / $owed;
        $rest = round($amount - $first, 2);
        $wsum = array_sum($weights) ?: count($weights);
        if (abs($rest) >= 0.005) foreach ($weights as $g => $w) $out[$g] += $rest * (array_sum($weights) ? $w / $wsum : 1 / $wsum);
        // cents: the largest part takes the odd cent
        $r = array_map(fn ($v) => round($v, 2), $out);
        $diff = round($amount - array_sum($r), 2);
        if (abs($diff) >= 0.005) { arsort($out); $k = array_key_first($out); $r[$k] = round($r[$k] + $diff, 2); }
        return $r;
    }

    /** The guardian row a signed-in user pays as, in this family. */
    public static function guardianFor(int $userId, int $familyId): ?int
    {
        $id = DB::table('guardians')->where('user_id', $userId)->where('family_id', $familyId)->value('id');
        return $id ? (int) $id : null;
    }

    /** One payer's slice of an invoice (or null). */
    public static function shareFor(object $invoice, int $guardianId): ?array
    {
        foreach (self::shares($invoice) ?? [] as $s) {
            if ($s['guardian_id'] === $guardianId) return $s;
        }
        return null;
    }

    /**
     * What a guardian paid toward a family in a period (for the annual receipt): their own payments plus their
     * proportion of payments nobody was attributed to; refunds taken off.
     */
    public static function paidByGuardian(int $familyId, int $guardianId, string $from, string $to): array
    {
        $rows = DB::table('payments as p')->join('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->where('i.family_id', $familyId)->whereBetween('p.paid_at', [$from, $to])->whereNotIn('p.status', ['failed', 'refunded', 'void'])
            ->orderBy('p.paid_at')->get(['p.id', 'p.paid_at', 'p.amount', 'p.payer_guardian_id', 'i.invoice_number', 'i.id as invoice_id']);
        $refunds = $rows->isEmpty() ? [] : DB::table('payment_refunds')->whereIn('payment_id', $rows->pluck('id'))->where('status', 'succeeded')
            ->groupBy('payment_id')->selectRaw('payment_id, SUM(amount) a')->pluck('a', 'payment_id')->all();
        $weights = [];
        $out = [];
        foreach ($rows as $r) {
            $amt = round((float) $r->amount - (float) ($refunds[$r->id] ?? 0), 2);
            if ($amt <= 0.004) continue;
            if ((int) $r->payer_guardian_id === $guardianId) {
                $out[] = ['paid_at' => $r->paid_at, 'amount' => $amt, 'invoice_number' => $r->invoice_number];
            } elseif (! $r->payer_guardian_id) {
                if (! array_key_exists($r->invoice_id, $weights)) {
                    $inv = DB::table('invoices')->where('id', $r->invoice_id)->first();
                    $sh = $inv ? self::shares($inv) : null;
                    // The guardian's part of the invoice's unattributed money, as shares() spreads it.
                    $un = $sh ? array_sum(array_column($sh, 'unassigned_part')) : 0;
                    $mine = $sh ? (collect($sh)->firstWhere('guardian_id', $guardianId)['unassigned_part'] ?? 0) : null;
                    $weights[$r->invoice_id] = $sh === null ? 1.0 : (abs($un) > 0.004 ? $mine / $un : 0.0);
                }
                $w = $weights[$r->invoice_id];
                if ($w > 0) $out[] = ['paid_at' => $r->paid_at, 'amount' => round($amt * $w, 2), 'invoice_number' => $r->invoice_number];
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Words

    public static function label(?array $cfg): string
    {
        if (! $cfg) return 'One payer';
        return [
            'single' => 'One payer', 'even' => 'Shared evenly', 'percent' => 'Percentage split', 'fixed' => 'Fixed amount + rest',
            'by_type' => 'By charge type', 'by_child' => 'By child',
        ][$cfg['mode']] ?? 'Split';
    }

    /** One plain sentence describing the rules (staff + parent). $you = the reader's guardian id. */
    public static function describe(?array $cfg, ?int $you = null): string
    {
        if (! $cfg) return 'The family pays as one.';
        $n = self::names(self::guardiansOf($cfg));
        $who = fn ($g) => $g === $you ? 'You' : ($n[$g] ?? 'Guardian');
        $first = fn ($g) => $g === $you ? 'you' : (explode(' ', $n[$g] ?? 'Guardian')[0]);
        switch ($cfg['mode']) {
            case 'single': return $who($cfg['payer']) . ($you === $cfg['payer'] ? ' pay' : ' pays') . ' the whole bill.';
            case 'even': return 'Shared evenly between ' . implode(' and ', array_map($first, $cfg['guardians'])) . '.';
            case 'percent': return implode(', ', array_map(fn ($s) => $who($s['guardian_id']) . ' ' . rtrim(rtrim(number_format($s['pct'], 2), '0'), '.') . '%', $cfg['shares'])) . '.';
            case 'fixed':
                return implode(', ', array_map(fn ($f) => $who($f['guardian_id']) . ' $' . number_format($f['amount'], 2) . ' per invoice', $cfg['fixed']))
                    . '; ' . $first($cfg['rest']) . ' the rest.';
            case 'by_type':
                $t = fn ($g) => $g === 'split' ? 'split by percentage' : $first($g);
                return 'Tuition: ' . $t($cfg['tuition']) . ' · Fees & extras: ' . $t($cfg['fees']) . '.';
            case 'by_child':
                $kids = DB::table('children')->whereIn('id', array_map('intval', array_keys($cfg['children'])))->pluck('first_name', 'id')->all();
                $parts = [];
                foreach ($cfg['children'] as $c => $g) $parts[] = ($kids[(int) $c] ?? 'Child') . ': ' . $first($g);
                return implode(' · ', $parts) . ' · Other charges: ' . $first($cfg['default']) . '.';
        }
        return '';
    }
}
