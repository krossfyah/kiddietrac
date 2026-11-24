<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PAYING AN INVOICE ON THE WEBSITE DEMO (2026-10-30, Anthony: "including the ability to pay invoices (simulate the full
 * experience)").
 *
 * A parent of a PUBLIC demo agency (App\Support\PublicDemo) gets the whole payment journey - choose a method, a real-
 * looking card or bank sheet, processing, success, a receipt, the balance going to zero, the office's bell - and no money
 * moves: this never calls Stripe, Zum, Helcim or anything else, and never receives card or bank details (the sheet is
 * prefilled with the published test number 4242 4242 4242 4242 and sends only the invoice ids and the method's name;
 * anything else in the request is ignored). PaymentProviders::configured stays false for these agencies, and
 * PublicDemoGuard keeps refusing the real charge routes.
 *
 * Every payment made here is written down (web_demo_payments: the invoice's figures before), so kt:reset-web-demo puts
 * the invoices back to unpaid each night and removes the payments.
 */
final class WebDemoPayments
{
    /** The methods the real app offers that agency's families: card + bank (ACH) in the US; card, Interac, pre-authorized debit in Canada. */
    public static function methods(int $agencyId): array
    {
        $country = strtoupper((string) DB::table('agencies')->where('id', $agencyId)->value('country'));
        return $country === 'CA'
            ? [['key' => 'card', 'label' => 'Credit or debit card'], ['key' => 'interac', 'label' => 'Interac e-Transfer'], ['key' => 'pad', 'label' => 'Pre-authorized debit']]
            : [['key' => 'card', 'label' => 'Credit or debit card'], ['key' => 'ach', 'label' => 'Bank account (ACH)']];
    }

    /** pay_info.demo_pay for a family's agency, or null when it is not the public demo. */
    public static function info(?int $agencyId): ?array
    {
        if (! $agencyId || ! PublicDemo::isAgency($agencyId)) return null;
        return ['methods' => self::methods($agencyId), 'test_card' => '4242 4242 4242 4242'];
    }

    private const STORED = ['card' => 'stripe_card', 'ach' => 'stripe_ach', 'interac' => 'interac', 'pad' => 'eft'];

    /**
     * Pay these invoices in full for a website demo parent (POST /parent/demo-pay). Returns the receipt, or throws
     * \DomainException with a message for the parent.
     */
    public static function pay(object $user, array $invoiceIds, string $method): array
    {
        if (! PublicDemo::isUser((int) $user->id)) throw new \DomainException('Online payments are not switched on for your centre.');
        $agencyId = PublicDemo::agencyOf((int) $user->id);
        if (! in_array($method, array_column(self::methods($agencyId), 'key'), true)) throw new \DomainException('Choose how you would like to pay.');
        $families = DB::table('guardians')->where('user_id', $user->id)->pluck('family_id')->map(fn ($v) => (int) $v)->all();
        $ids = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));
        if (! $ids || count($ids) > 12) throw new \DomainException('Choose the invoice to pay.');
        $fam = (int) DB::table('invoices')->whereIn('id', $ids)->value('family_id');
        if (! in_array($fam, $families, true)) throw new \DomainException('That invoice could not be found.');
        return self::record($fam, $ids, null, $method, (int) $user->id);
    }

    /**
     * RECORD A SIMULATED PAYMENT - also for other website-demo flows (e.g. a simulated Interac e-Transfer the office marks
     * paid). Public demo agencies only: refuses any invoice outside a public demo agency or not this family's.
     * $amount null = each invoice's whole balance; otherwise spread over the invoices, oldest due first (a part-paid
     * invoice is "partial"). $method: card | ach | interac | pad. $payerUserId: the guardian who paid (null = recorded by
     * the office; then the family's demo guardians get the receipt). Writes payments + web_demo_payments (undone nightly
     * by restore()), bells the office and the payer, returns the receipt. Throws \DomainException (reader-facing message).
     */
    public static function record(int $familyId, array $invoiceIds, ?float $amount, string $method, ?int $payerUserId = null): array
    {
        if (! isset(self::STORED[$method])) throw new \DomainException('Unknown payment method.');
        $ids = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));
        if (! $ids) throw new \DomainException('Choose the invoice to pay.');
        return DB::transaction(function () use ($familyId, $ids, $amount, $method, $payerUserId) {
            $invs = DB::table('invoices as i')->join('centres as c', 'c.id', '=', 'i.centre_id')->whereIn('i.id', $ids)
                ->orderBy('i.due_at')->orderBy('i.id')->lockForUpdate()->get(['i.*', 'c.agency_id', 'c.name as centre_name']);
            if ($invs->count() !== count($ids)) throw new \DomainException('That invoice could not be found.');
            $agencyId = (int) $invs->first()->agency_id;
            foreach ($invs as $inv) {
                if ((int) $inv->family_id !== $familyId || (int) $inv->agency_id !== $agencyId || ! PublicDemo::isAgency($agencyId)) throw new \DomainException('That invoice could not be found.');
                if ((float) $inv->balance_due < 0.005 || in_array($inv->status, ['paid', 'void', 'draft', 'refunded'], true)) throw new \DomainException('Invoice ' . $inv->invoice_number . ' is already paid.');
            }
            $guardianId = $payerUserId ? DB::table('guardians')->where('user_id', $payerUserId)->where('family_id', $familyId)->value('id') : null;
            $left = $amount === null ? null : round(max(0, $amount), 2);
            $ref = 'DEMO-PAY-' . strtoupper(Str::random(8));
            $total = 0.0; $lines = [];
            foreach ($invs as $inv) {
                $amt = round((float) $inv->balance_due, 2);
                if ($left !== null) { $amt = min($amt, $left); $left = round($left - $amt, 2); }
                if ($amt < 0.005) continue;
                $pid = DB::table('payments')->insertGetId(['invoice_id' => $inv->id, 'family_id' => $inv->family_id, 'payer_guardian_id' => $guardianId,
                    'amount' => $amt, 'method' => self::STORED[$method], 'status' => 'succeeded', 'reference_number' => $ref, 'paid_at' => now(),
                    'notes' => 'Paid online in the app (website demo - simulated, no money moved)', 'recorded_by_id' => null, 'created_at' => now(), 'updated_at' => now()]);
                if (Schema::hasTable('web_demo_payments')) {
                    DB::table('web_demo_payments')->insert(['invoice_id' => $inv->id, 'payment_id' => $pid, 'prev_amount_paid' => $inv->amount_paid,
                        'prev_balance_due' => $inv->balance_due, 'prev_status' => $inv->status, 'created_at' => now()]);
                }
                $bal = round((float) $inv->balance_due - $amt, 2);
                DB::table('invoices')->where('id', $inv->id)->update(['amount_paid' => round((float) $inv->amount_paid + $amt, 2), 'balance_due' => $bal,
                    'status' => $bal < 0.005 ? 'paid' : 'partial', 'updated_at' => now()]);
                $total += $amt; $lines[] = ['id' => (int) $inv->id, 'number' => $inv->invoice_number, 'amount' => $amt];
            }
            if (! $lines) throw new \DomainException('Nothing to pay.');
            $first = $invs->first();
            $agency = DB::table('agencies')->where('id', $agencyId)->first(['name', 'timezone']);
            $tz = $agency->timezone ?: 'America/Los_Angeles';
            $user = $payerUserId ? DB::table('users')->where('id', $payerUserId)->first(['id', 'first_name', 'last_name']) : null;
            $who = $user ? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) : ((string) DB::table('families')->where('id', $familyId)->value('family_name') . ' family');
            $how = ['card' => 'Visa ending 4242', 'ach' => 'Checking account ending 6789', 'interac' => 'Interac e-Transfer', 'pad' => 'Bank account ending 6789'][$method];
            $money = fn ($n) => '$' . number_format($n, 2);
            // The office hears about it the way it would for a real online payment; the parent gets the receipt in their inbox.
            $office = DB::table('role_assignments')->where('agency_id', $agencyId)->where('active', 1)
                ->where(fn ($q) => $q->where(fn ($w) => $w->where('role', 'centre_director')->where('centre_id', $first->centre_id))->orWhere('role', 'agency_admin'))
                ->pluck('user_id')->unique()->filter(fn ($id) => PublicDemo::isUser((int) $id));   // never a real person who also holds a role here
            foreach ($office as $uid) {
                try {
                    Notify::write(['user_id' => (int) $uid, 'type' => 'payment', 'title' => '💳 ' . $money($total) . ' received',
                        'body' => ($who ?: 'A family') . ' paid ' . $money($total) . ' by ' . $how . ' against ' . implode(', ', array_column($lines, 'number')) . '.',
                        'data' => json_encode(['link' => '#billing', 'invoice_id' => $first->id]), 'created_at' => now()]);
                } catch (\Throwable $e) {}
            }
            $payers = $payerUserId ? [$payerUserId] : DB::table('guardians')->where('family_id', $familyId)->pluck('user_id')->filter(fn ($id) => PublicDemo::isUser((int) $id))->all();
            foreach ($payers as $pu) {
                try {
                    Notify::write(['user_id' => (int) $pu, 'type' => 'payment', 'title' => '✅ Payment received - thank you!',
                        'body' => 'We received ' . $money($total) . ' (' . $how . ') for ' . implode(', ', array_column($lines, 'number')) . '. Receipt ' . $ref . '.',
                        'data' => json_encode(['link' => '#billing', 'invoice_id' => $first->id]), 'created_at' => now()]);
                } catch (\Throwable $e) {}
            }
            return ['ok' => true, 'demo' => true, 'reference' => $ref, 'total' => round($total, 2), 'method' => $method, 'method_label' => $how,
                'paid_at' => Carbon::now($tz)->format('M j, Y g:i A'), 'agency_name' => $agency->name, 'centre_name' => $first->centre_name,
                'payer' => $who, 'invoices' => $lines];
        });
    }

    /** Nightly: every demo payment undone - invoices back to their figures before, payments removed. */
    public static function restore(bool $dry = false): int
    {
        if (! Schema::hasTable('web_demo_payments')) return 0;
        $rows = DB::table('web_demo_payments')->orderByDesc('id')->get();
        if ($dry) return $rows->count();
        foreach ($rows as $r) {
            DB::table('invoices')->where('id', $r->invoice_id)->update(['amount_paid' => $r->prev_amount_paid, 'balance_due' => $r->prev_balance_due,
                'status' => $r->prev_status, 'updated_at' => now()]);
            DB::table('payments')->where('id', $r->payment_id)->delete();
        }
        DB::table('web_demo_payments')->whereIn('id', $rows->pluck('id'))->delete();
        return $rows->count();
    }
}
