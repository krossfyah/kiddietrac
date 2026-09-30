<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Split family billing (2026-09-29).
 *
 * After comparing with ChildCarePro, Anthony asked for it: separated parents each pay their
 * share. The pieces existed but did nothing -- families.billing_split and
 * guardians.billing_share_pct were stored and never used in any calculation.
 *
 * THE INVOICE STAYS ONE FAMILY INVOICE (totals, CWELCC reports, ledgers and statuses are
 * unchanged). On top of it each payer has a SHARE: total x their percentage, the last payer
 * taking the rounding cent. Percentages are snapshotted onto the invoice
 * (invoices.split_snapshot) the first time shares are computed, so changing the split later
 * never rewrites an old invoice.
 *
 * Paid per payer = payments recorded against that guardian (payments.payer_guardian_id)
 * + their percentage of payments with no payer (older payments, autopay on the family card,
 * processors that do not say who paid).
 */
final class BillingSplit
{
    /** Is this family split? Anything but 'single' with at least two paying guardians. */
    public static function isSplit(int $familyId): bool
    {
        return count(self::payers($familyId)) >= 2;
    }

    /** Current payers: [{guardian_id, user_id, name, email, pct}] (empty when single). */
    public static function payers(int $familyId): array
    {
        $mode = (string) DB::table('families')->where('id', $familyId)->value('billing_split');
        if ($mode === '' || $mode === 'single') return [];
        $rows = DB::table('guardians as g')->leftJoin('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $familyId)->where('g.can_receive_billing', 1)->where('g.billing_share_pct', '>', 0)
            ->orderByDesc('g.is_primary')->orderBy('g.id')
            ->get(['g.id', 'g.user_id', 'u.first_name', 'u.last_name', 'u.email', 'g.billing_share_pct']);
        return $rows->map(fn ($r) => ['guardian_id' => (int) $r->id, 'user_id' => $r->user_id ? (int) $r->user_id : null,
            'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: ($r->email ?: 'Guardian #' . $r->id),
            'email' => $r->email, 'pct' => round((float) $r->billing_share_pct, 2)])->values()->all();
    }

    /**
     * Shares for one invoice, or null when the family pays as one.
     * [{guardian_id, name, pct, share, paid, balance}]
     */
    public static function shares(object $invoice): ?array
    {
        $snap = null;
        if (! empty($invoice->split_snapshot)) {
            $snap = json_decode((string) $invoice->split_snapshot, true);
        }
        if (! is_array($snap) || count($snap) < 2) {
            $payers = self::payers((int) $invoice->family_id);
            if (count($payers) < 2) return null;
            $snap = array_map(fn ($p) => ['guardian_id' => $p['guardian_id'], 'name' => $p['name'], 'pct' => $p['pct']], $payers);
            // Freeze it once the invoice has been issued; a draft follows the family's setting.
            if (($invoice->status ?? '') !== 'draft' && ! empty($invoice->id)) {
                DB::table('invoices')->where('id', $invoice->id)->whereNull('split_snapshot')
                    ->update(['split_snapshot' => json_encode($snap, JSON_UNESCAPED_UNICODE)]);
            }
        }
        $pctSum = array_sum(array_column($snap, 'pct')) ?: 100;
        $total = round((float) $invoice->total, 2);
        $pays = DB::table('payments')->where('invoice_id', $invoice->id)->whereNotIn('status', ['failed', 'refunded', 'void'])
            ->get(['amount', 'payer_guardian_id']);
        $unassigned = round((float) $pays->whereNull('payer_guardian_id')->sum('amount'), 2);

        $out = [];
        $running = 0.0; $runningUn = 0.0;
        $n = count($snap);
        foreach (array_values($snap) as $i => $p) {
            $w = (float) $p['pct'] / $pctSum;
            $last = $i === $n - 1;
            $share = $last ? round($total - $running, 2) : round($total * $w, 2);
            $unShare = $last ? round($unassigned - $runningUn, 2) : round($unassigned * $w, 2);
            $running += $share; $runningUn += $unShare;
            $own = round((float) $pays->where('payer_guardian_id', $p['guardian_id'])->sum('amount'), 2);
            $paid = round($own + $unShare, 2);
            $out[] = ['guardian_id' => (int) $p['guardian_id'], 'name' => $p['name'], 'pct' => (float) $p['pct'],
                'share' => $share, 'paid' => $paid, 'balance' => round(max(0, $share - $paid), 2)];
        }
        return $out;
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
     * What a guardian paid toward a family in a period (for the annual receipt): their own
     * payments plus their percentage of payments nobody was attributed to.
     */
    public static function paidByGuardian(int $familyId, int $guardianId, string $from, string $to): array
    {
        $rows = DB::table('payments as p')->join('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->where('i.family_id', $familyId)->whereBetween('p.paid_at', [$from, $to])->whereNotIn('p.status', ['failed', 'refunded', 'void'])
            ->orderBy('p.paid_at')->get(['p.paid_at', 'p.amount', 'p.payer_guardian_id', 'i.invoice_number', 'i.id as invoice_id', 'i.total', 'i.status', 'i.split_snapshot', 'i.family_id']);
        $out = [];
        foreach ($rows as $r) {
            if ((int) $r->payer_guardian_id === $guardianId) {
                $out[] = ['paid_at' => $r->paid_at, 'amount' => round((float) $r->amount, 2), 'invoice_number' => $r->invoice_number];
            } elseif (! $r->payer_guardian_id) {
                $inv = (object) ['id' => $r->invoice_id, 'family_id' => $r->family_id, 'total' => $r->total, 'status' => $r->status, 'split_snapshot' => $r->split_snapshot];
                $snap = json_decode((string) $inv->split_snapshot, true);
                if (! is_array($snap)) { self::shares($inv); $snap = json_decode((string) DB::table('invoices')->where('id', $r->invoice_id)->value('split_snapshot'), true); }
                if (! is_array($snap) || count($snap) < 2) {
                    $out[] = ['paid_at' => $r->paid_at, 'amount' => round((float) $r->amount, 2), 'invoice_number' => $r->invoice_number];
                    continue;
                }
                $sum = array_sum(array_column($snap, 'pct')) ?: 100;
                foreach ($snap as $p) {
                    if ((int) $p['guardian_id'] === $guardianId) {
                        $out[] = ['paid_at' => $r->paid_at, 'amount' => round((float) $r->amount * (float) $p['pct'] / $sum, 2), 'invoice_number' => $r->invoice_number];
                    }
                }
            }
        }
        return $out;
    }
}
