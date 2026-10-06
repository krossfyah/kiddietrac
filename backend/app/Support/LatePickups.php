<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ONE LATE-PICKUP FLOW (2026-10-06): log → review → charge.
 *
 * Anthony: "there are 2 late pick up sections in the portal pls combine into one going
 * forward". There were two: #late-pickups charged a per-minute fee onto the invoice the
 * moment it was logged (late_pickup_charges), and #late-events let an educator record a
 * late arrival/pickup for a director to decide on (late_events). Now late_events is THE
 * record: staff log it (with a suggested fee worked out from the agency's per-minute rate
 * and grace), and a director or admin approves it — which is when the fee goes on the
 * invoice, a late_pickup_charges row is written and the parents are told — or waives or
 * declines it. A director/admin can log and approve in one step.
 *
 * late_pickup_charges stays as the CHARGE record (parent daily summary, history API), now
 * linked back to its event and carrying who approved it.
 */
final class LatePickups
{
    use \App\Http\Concerns\ReportAlerts;

    /** @return array{per_minute: float, grace: int} */
    public static function policy(int $agencyId): array
    {
        $a = DB::table('agencies')->where('id', $agencyId)->first(['late_pickup_per_minute', 'late_pickup_grace_minutes']);
        return [
            'per_minute' => (float) ($a->late_pickup_per_minute ?? 1.00),
            'grace' => (int) ($a->late_pickup_grace_minutes ?? 5),
        ];
    }

    /** The suggested fee for a late pickup of $minutes: per-minute after the grace period. */
    public static function suggest(int $agencyId, int $minutes): float
    {
        $p = self::policy($agencyId);
        return round(max(0, $minutes - $p['grace']) * $p['per_minute'], 2);
    }

    /**
     * Approve an event with a fee: invoice line on the family's newest open invoice, a
     * late_pickup_charges row for a late PICKUP, the event marked approved, the parents
     * told. Must only be called for a pending event (the caller checks, under a lock).
     *
     * @return array{charge_id: ?int, invoice_line_id: ?int, invoice: ?object}
     */
    public static function charge(object $ev, float $fee, int $approverId, ?string $note = null): array
    {
        $child = DB::table('children')->where('id', $ev->child_id)->first();
        $family = $child ? DB::table('families')->where('id', $child->family_id)->first() : null;
        $centre = DB::table('centres')->where('id', $ev->centre_id)->first();
        $isPickup = $ev->kind === 'departure';
        $what = $isPickup ? 'Late pickup' : 'Late arrival';
        $day = Carbon::parse($ev->pickup_at ?: $ev->occurred_on)->format('M j');

        $invoice = null;
        $lineId = null;
        if ($family && $fee > 0) {
            $invoice = DB::table('invoices')->where('family_id', $family->id)
                ->whereIn('status', ['draft', 'sent', 'partial', 'overdue'])
                ->orderByDesc('id')->first();
            if ($invoice) {
                $lineId = DB::table('invoice_lines')->insertGetId([
                    'invoice_id' => $invoice->id,
                    'child_id' => $child->id,
                    /* line_type is an ENUM (tuition, subsidy, late_fee, extra_day, field_trip,
                       meal, supply, adjustment, tax): 'late_pickup' was never one of its
                       values, so charging a family that HAD an open invoice failed with
                       "Data truncated for column 'line_type'" — in the old one-step flow too.
                       Not 'late_fee': that marks overdue-invoice interest, and
                       ApplyLateFeesCommand skips an invoice that already carries one. */
                    'line_type' => 'adjustment',
                    'description' => "{$what}: {$ev->minutes} min on {$day}",
                    'quantity' => 1,
                    'unit_amount' => $fee,
                    'amount' => $fee,
                ]);
                DB::table('invoices')->where('id', $invoice->id)->update([
                    'total' => DB::raw('total + ' . number_format($fee, 2, '.', '')),
                    'balance_due' => DB::raw('balance_due + ' . number_format($fee, 2, '.', '')),
                    'updated_at' => now(),
                ]);
            }
        }

        $chargeId = null;
        if ($isPickup && $fee > 0 && $centre) {
            $chargeId = DB::table('late_pickup_charges')->insertGetId([
                'agency_id' => $centre->agency_id,
                'centre_id' => $centre->id,
                'child_id' => $ev->child_id,
                'pickup_at' => $ev->pickup_at ?: ($ev->occurred_on . ' 00:00:00'),
                'close_time' => $ev->close_time ?: ($centre->close_time ?: '00:00:00'),
                'minutes_late' => (int) $ev->minutes,
                'fee_amount' => $fee,
                'invoice_line_id' => $lineId,
                'notes' => $ev->note,
                'logged_by_id' => $ev->recorded_by_id,
                'approved_by_id' => $approverId,
                'late_event_id' => $ev->id,
                'created_at' => now(),
            ]);
        }

        DB::table('late_events')->where('id', $ev->id)->update([
            'status' => 'approved',
            'fee_amount' => $fee,
            'decision_note' => $note,
            'decided_by_id' => $approverId,
            'decided_at' => now(),
            'charge_id' => $chargeId,
            'invoice_line_id' => $lineId,
            'updated_at' => now(),
        ]);

        // The parents hear about a fee only when one is actually charged.
        if ($family && $fee > 0) {
            $money = '$' . number_format($fee, 2);
            foreach (DB::table('guardians')->where('family_id', $family->id)->pluck('user_id') as $gid) {
                try {
                    Notify::write([
                        'user_id' => $gid, 'type' => 'late_pickup',
                        'title' => "{$what} fee {$money}",
                        'body' => "{$ev->minutes} minute(s) late on {$day}. " . ($invoice ? 'Added to your invoice.' : 'It will appear on your next invoice.'),
                        'data' => json_encode(['link' => '#billing', 'charge_id' => $chargeId, 'late_event_id' => $ev->id]),
                        'created_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('late fee notice failed', ['event' => $ev->id, 'error' => $e->getMessage()]);
                }
            }
        }

        return ['charge_id' => $chargeId, 'invoice_line_id' => $lineId, 'invoice' => $invoice];
    }

    /* ── emails to the centre's directors and the agency admins ── */

    private function line(string $k, $v): string
    {
        if ($v === null || $v === '') return '';
        return '<tr><td style="padding:6px 12px 6px 0;color:#64748b;font-size:13px;white-space:nowrap;">' . $k
            . '</td><td style="padding:6px 0;font-weight:700;color:#0f172a;font-size:13px;">' . htmlspecialchars((string) $v, ENT_QUOTES) . '</td></tr>';
    }

    /** "Logged and charged" (an admin's log-and-approve, or the old one-step endpoint). */
    public function emailCharged(object $ev, float $fee, ?object $invoice, object $by): void
    {
        $this->send($ev, $by, true, $fee, $invoice);
    }

    /** "Logged — needs a decision". */
    public function emailPending(object $ev, object $by): void
    {
        $this->send($ev, $by, false, (float) ($ev->suggested_fee ?? 0), null);
    }

    private function send(object $ev, object $by, bool $charged, float $fee, ?object $invoice): void
    {
        try {
            $centre = DB::table('centres')->where('id', $ev->centre_id)->first();
            if (! $centre) return;
            $agencyId = (int) $centre->agency_id;
            $recipients = $this->alertRecipients($agencyId, (int) $centre->id);
            if (! $recipients) return;
            $child = DB::table('children')->where('id', $ev->child_id)->first(['first_name', 'last_name', 'family_id']);
            $childName = trim(($child->first_name ?? '') . ' ' . ($child->last_name ?? ''));
            $family = $child ? DB::table('families')->where('id', $child->family_id)->value('family_name') : null;
            $byName = trim(($by->first_name ?? '') . ' ' . ($by->last_name ?? '')) ?: 'A staff member';
            $kind = $ev->kind === 'arrival' ? 'late arrival' : 'late pickup';
            $tz = AgencyTime::tz($agencyId);
            // pickup_at is the wall-clock time typed on the form; "logged" is now, in the agency's zone.
            $when = $ev->pickup_at ? Carbon::parse($ev->pickup_at)->format('D M j, Y \a\t g:i A') : Carbon::parse($ev->occurred_on)->format('D M j, Y');
            $money = '$' . number_format($fee, 2);
            $rows = $this->line('Child', $childName) . $this->line('Family', $family) . $this->line('Centre', $centre->name ?? null)
                . $this->line($ev->kind === 'arrival' ? 'Arrived' : 'Picked up', $when)
                . $this->line('Centre closed at', $ev->close_time ? Carbon::parse($ev->close_time)->format('g:i A') : null)
                . $this->line('Minutes late', $ev->minutes)
                . ($charged
                    ? $this->line('Fee charged', $money) . $this->line('Invoice', $invoice ? ('Added to invoice ' . ($invoice->invoice_number ?? ('#' . $invoice->id))) : 'No open invoice — not yet billed')
                    : $this->line('Suggested fee', $fee > 0 ? $money : 'None (within the grace period)'))
                . $this->line('Logged by', $byName) . $this->line('Logged at', now()->setTimezone($tz)->format('M j, Y g:i A'))
                . $this->line('Notes', $ev->note ?? null);
            $lead = $charged
                ? 'A ' . $kind . ' was just logged and the fee charged.'
                : 'A ' . $kind . ' was logged and is waiting for a decision — approve the fee, waive it, or decline.';
            $body = '<p style="margin:0 0 12px;font-size:14.5px;color:#243244;">' . $lead . '</p>'
                . '<table style="border-collapse:collapse;margin:8px 0 4px;">' . $rows . '</table>'
                . '<p style="margin:14px 0 0;font-size:12.5px;color:#64748b;">Open <strong>Late pickups</strong> in your portal to see it'
                . ($charged ? '' : ' and decide') . '.</p>';
            $html = \App\Services\EmailTemplate::wrap($agencyId, $body, [
                'eyebrow' => strtoupper($kind),
                'title' => $charged ? (ucfirst($kind) . ' — ' . $childName) : (ucfirst($kind) . ' needs a decision — ' . $childName),
                'subtitle' => $when . ($charged ? ' · ' . $money : ''),
                'preheader' => $byName . ' logged a ' . $kind . ' for ' . $childName . ($charged ? ' and charged ' . $money . '.' : '; it needs your decision.'),
            ]);
            $subject = $charged
                ? (ucfirst($kind) . ': ' . $childName . ' — ' . $ev->minutes . ' min, ' . $money)
                : (ucfirst($kind) . ' to review: ' . $childName . ' (' . $ev->minutes . ' min)');
            $this->sendReportAlert($agencyId, $recipients, $subject, $html);
        } catch (\Throwable $e) {
            Log::warning('late pickup alert failed', ['event' => $ev->id ?? null, 'error' => $e->getMessage()]);
        }
    }
}
