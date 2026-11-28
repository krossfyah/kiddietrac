<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ONE FAMILY'S TUITION INVOICE (2026-11-28). The body of InvoiceController::generateBatch's per-family transaction,
 * moved here unchanged so the monthly batch and the automated billing schedules (App\Support\BillingSchedules) raise
 * invoices the same way: one tuition line per child, subsidies once per child (SubsidyAmount), sibling discounts from
 * the agency's tiers, the agency's invoice numbering, and the family's bell + push.
 *
 * $factor scales a monthly amount to the period: 1 for a month, 12/52 for a week, 24/52 for two weeks. The monthly
 * batch passes 1 and $periodLabel null, which gives exactly the lines and totals it always produced.
 */
final class FamilyInvoice
{
    /** Open enrolments of enrolled children, ONE row per child (the enrolment with the most care days), grouped by family. */
    public static function enrollments(callable $scope)
    {
        $q = DB::table('enrollments')
            ->join('children', 'children.id', '=', 'enrollments.child_id')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->leftJoin('rooms', 'rooms.id', '=', 'enrollments.room_id')
            ->whereNull('enrollments.end_date')
            ->where('children.enrollment_status', 'enrolled');
        $scope($q);
        return $q->select(
                'families.id as family_id',
                'children.id as child_id',
                'children.first_name',
                'children.last_name',
                'enrollments.monthly_fee',
                'enrollments.schedule',
                'rooms.name as room_name',
            )
            ->get()
            /* ONE LINE PER CHILD, not per enrolment (2026-08-27): a child with a split week holds one open enrolment per
               provider, and billing per enrolment charged the family twice. Kept: the enrolment covering the most days. */
            ->groupBy('child_id')
            ->map(function ($rows) {
                if ($rows->count() === 1) return $rows->first();
                return $rows->sortByDesc(fn ($r) => count(CareSchedule::daysOf($r->schedule ?? null)))->first();
            })
            ->values()
            ->groupBy('family_id');
    }

    /** The agency's sibling-discount tiers, by rank. */
    public static function siblingTiers(?int $agencyId): array
    {
        if (! $agencyId) return [];
        $settings = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        return collect($settings['sibling_discounts'] ?? [])->sortBy('rank')->values()->all();
    }

    /**
     * Raise and announce one invoice. Call inside a transaction. Returns the invoice id.
     * $extraLines: more lines to add after tuition (each: description, amount (signed), line_type, child_id?), which
     * also change the total; used for vacation-hold credits.
     */
    public static function raise(int $familyId, int $centreId, ?int $numberingAgencyId, $childEnrollments, Carbon $issueDate, Carbon $dueDate,
                                 array $siblingTiers, Carbon $periodStart, Carbon $periodEnd, float $factor = 1.0, ?string $periodLabel = null,
                                 array $extraLines = []): int
    {
        $subtotal = 0;
        $subsidyTotal = 0;
        $lineItems = [];
        $fee = fn ($monthly) => $factor === 1.0 ? (float) $monthly : round((float) $monthly * $factor, 2);
        $suffix = $periodLabel ? ' · ' . $periodLabel : '';

        /* SUBSIDIES (2026-09-29): worked out ONCE PER CHILD by SubsidyAmount (monthly amount, or daily amount x
           scheduled care days this month), capped at that child's tuition, and spread across the child's lines. */
        $childFee = [];
        foreach ($childEnrollments as $en) {
            $childFee[$en->child_id] = ($childFee[$en->child_id] ?? 0) + $fee($en->monthly_fee);
        }
        $subsidyLeft = [];
        $subsidyLabel = [];
        foreach (array_keys($childFee) as $cid) {
            $sa = SubsidyAmount::forChildMonth((int) $cid, $periodStart->toDateString(), $periodStart->copy()->startOfMonth()->toDateString());
            $amt = $sa ? ($factor === 1.0 ? (float) $sa['amount'] : round((float) $sa['amount'] * $factor, 2)) : 0.0;
            $subsidyLeft[$cid] = $sa ? min($amt, $childFee[$cid]) : 0.0;
            $subsidyLabel[$cid] = $sa ? $sa['label'] : null;
        }

        foreach ($childEnrollments as $en) {
            $amount = $fee($en->monthly_fee);
            $subtotal += $amount;

            $subsidyAmount = round(min($subsidyLeft[$en->child_id] ?? 0, $amount), 2);
            $subsidyLeft[$en->child_id] = ($subsidyLeft[$en->child_id] ?? 0) - $subsidyAmount;
            $subsidyTotal += $subsidyAmount;

            $lineItems[] = [
                'description' => "Tuition — {$en->first_name} {$en->last_name} ({$en->room_name})" . $suffix,
                'amount' => $amount,
                'subsidy' => $subsidyAmount,
                'subsidy_label' => $subsidyAmount > 0 ? ($subsidyLabel[$en->child_id] ?? 'Subsidy') : null,
                'net' => $amount - $subsidyAmount,
            ];
        }

        // v22p9: sibling discounts — apply per child by enrollment rank.
        $discountTotal = 0.0;
        $discountLines = [];
        if (! empty($siblingTiers) && $childEnrollments->count() > 1) {
            // Rank by enrollment start_date asc (oldest = rank 1, no discount)
            $ranked = DB::table('enrollments')
                ->join('children', 'children.id', '=', 'enrollments.child_id')
                ->where('children.family_id', $familyId)
                ->whereNull('enrollments.end_date')
                ->orderBy('enrollments.start_date')
                ->select('children.id as child_id', 'children.first_name', 'enrollments.monthly_fee')
                ->get();
            $pos = 1;
            foreach ($ranked as $r) {
                $rank = $pos++;
                if ($rank <= 1) continue;
                $appliedTier = null;
                foreach ($siblingTiers as $t) {
                    if ((int) $t['rank'] <= $rank) $appliedTier = $t;
                }
                if (! $appliedTier) continue;
                $pct = (float) $appliedTier['percent'];
                $disc = round($fee($r->monthly_fee) * ($pct / 100), 2);
                $discountTotal += $disc;
                $discountLines[] = [
                    'child_id' => $r->child_id,
                    'description' => 'Sibling discount (' . $pct . '%) — ' . $r->first_name,
                    'amount' => -$disc,
                ];
            }
        }

        $extraTotal = 0.0;
        foreach ($extraLines as $x) $extraTotal += (float) $x['amount'];

        $total = $subtotal - $subsidyTotal - $discountTotal + $extraTotal;
        $invoiceNumber = \App\Services\InvoiceNumber::next($numberingAgencyId, ['date' => now(), 'family_id' => $familyId]);

        $invoiceId = DB::table('invoices')->insertGetId([
            'centre_id' => $centreId,
            'family_id' => $familyId,
            'invoice_number' => $invoiceNumber,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'issued_at' => $issueDate,
            'due_at' => $dueDate,
            'subtotal' => $subtotal,
            'subsidy_amount' => $subsidyTotal,
            'total' => $total,
            'balance_due' => $total,
            'status' => 'sent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lineItems as $line) {
            DB::table('invoice_lines')->insert([
                'invoice_id' => $invoiceId,
                'description' => $line['description'],
                'line_type'   => 'tuition',
                'quantity'    => 1,
                'unit_amount' => $line['amount'],
                'amount'      => $line['amount'],
            ]);
            if (! empty($line['subsidy']) && (float) $line['subsidy'] > 0) {
                DB::table('invoice_lines')->insert([
                    'invoice_id' => $invoiceId,
                    'description' => 'CWELCC subsidy — ' . $line['description'],
                    'line_type'   => 'subsidy',
                    'quantity'    => 1,
                    'unit_amount' => -1 * abs((float) $line['subsidy']),
                    'amount'      => -1 * abs((float) $line['subsidy']),
                ]);
            }
        }
        foreach ($discountLines as $dl) {
            DB::table('invoice_lines')->insert([
                'invoice_id' => $invoiceId,
                'child_id' => $dl['child_id'],
                'description' => $dl['description'],
                'line_type' => 'adjustment',
                'quantity' => 1,
                'unit_amount' => $dl['amount'],
                'amount' => $dl['amount'],
            ]);
        }
        foreach ($extraLines as $x) {
            $x['_line_id'] = DB::table('invoice_lines')->insertGetId([
                'invoice_id' => $invoiceId,
                'child_id' => $x['child_id'] ?? null,
                'description' => $x['description'],
                'line_type' => $x['line_type'] ?? 'adjustment',
                'quantity' => 1,
                'unit_amount' => $x['amount'],
                'amount' => $x['amount'],
            ]);
            if (isset($x['on_insert']) && is_callable($x['on_insert'])) ($x['on_insert'])($invoiceId, (int) $x['_line_id']);
        }

        // Each guardian hears a new invoice is ready (drives the parent app's Billing badge + inbox).
        $invTitle = 'New invoice: ' . $invoiceNumber;
        $invBody = 'Your invoice for $' . number_format($total, 2) . ' is ready. Due ' . $dueDate->format('M j, Y') . '.';
        if (SplitInvoiceCopy::notifyIssued((int) $invoiceId) === 0)
        foreach (DB::table('guardians')->where('family_id', $familyId)->pluck('user_id') as $gid) {
            Notify::write([
                'user_id' => $gid,
                'type' => 'invoice',
                'title' => $invTitle,
                'body' => $invBody,
                'data' => json_encode(['link' => '#billing', 'invoice_id' => $invoiceId]),
                'created_at' => now(),
            ]);
            try { app(\App\Services\FcmService::class)->sendToUser((int) $gid, $invTitle, $invBody, '#billing'); } catch (\Throwable $e) {}
        }

        return (int) $invoiceId;
    }
}
