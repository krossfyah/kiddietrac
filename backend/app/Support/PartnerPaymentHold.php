<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * DON'T NAG A FAMILY THAT HAS PAID (2026-11-03).
 *
 * Samantha Kicks-Ward paid KiddieTrac invoice iL-INV-202610-0448-01 ($154.52) by Interac e-Transfer into iLearn on
 * Oct 23 with no number in the message. iLearn recorded it on a receipt shell, nothing tied it to our invoice, and
 * InvoiceReminders emailed her and Chase "Overdue (8/9/10/11 days)" every day from Oct 31 to Nov 3 - while staff
 * had already said in team chat that it was paid.
 *
 * KiddieTrac already holds what it needs to know better: every receipt shell a partner pushes carries its payments
 * (external_invoices.payments). IntegrationController::upsertInvoice now marks each one after ExternalPaymentSync has
 * run (annotate()): `unlinked` = on a receipt shell, not applied to any invoice of ours, and not marked by the
 * partner's staff as "not for a KiddieTrac invoice". From that, with no call to the partner:
 *
 *   - InvoiceReminders holds every automatic reminder for a native invoice of a family holding an unlinked partner
 *     payment received on or after that invoice was issued (heldBy()), and tells the agency's staff;
 *   - a newly unlinked payment that could be for one of the family's open invoices puts a bell notice in front of
 *     the agency admins and billing contacts (alert()), once, and again at most weekly while it holds reminders.
 *
 * Nothing here sends a parent anything, links anything or changes an invoice. Linking stays a person's decision in
 * the partner (it sends the family a receipt).
 */
final class PartnerPaymentHold
{
    /**
     * Rebuild a pushed invoice's payments list with the link state of each payment.
     *
     * @param  array  $incoming    the payments[] the partner sent
     * @param  array  $appliedIds  partner payment ids ExternalPaymentSync applied to one of our invoices
     * @param  array  $previous    the stored list before this push (alerted_at is carried over by payment id)
     */
    public static function annotate(array $incoming, array $appliedIds, bool $autoCreated, bool $active, array $previous): array
    {
        $prevBy = [];
        foreach ($previous as $p) {
            if (is_array($p) && ! empty($p['id'])) $prevBy[(string) $p['id']] = $p;
        }
        $applied = array_flip(array_map('strval', $appliedIds));
        $out = [];
        foreach ($incoming as $p) {
            $id = trim((string) ($p['id'] ?? ''));
            $notKt = ! empty($p['not_for_invoice']);
            $row = [
                'date' => $p['date'] ?? null,
                'amount' => round((float) ($p['amount'] ?? 0), 2),
                'method' => $p['method'] ?? null,
                'reference' => $p['reference'] ?? null,
            ];
            if ($id !== '') {
                $row['id'] = $id;
                $row['applies_to'] = array_values((array) ($p['applies_to'] ?? []));
                $row['not_for_invoice'] = $notKt;
                $row['unlinked'] = $active && $autoCreated && ! $notKt && ! isset($applied[$id]) && $row['amount'] > 0.004;
                if (! empty($prevBy[$id]['alerted_at'])) $row['alerted_at'] = $prevBy[$id]['alerted_at'];
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Unlinked partner payments of a family received on or after $since (Y-m-d; null = any).
     *
     * @return list<array{external_invoice_id:int, id:string, date:?string, amount:float, reference:?string, label:string}>
     */
    public static function unlinked(int $agencyId, int $familyId, ?string $since): array
    {
        $rows = DB::table('external_invoices')->where('agency_id', $agencyId)->where('family_id', $familyId)
            ->where('status', '!=', 'void')->whereNotNull('payments')->get(['id', 'payments', 'source_label', 'external_source']);
        $out = [];
        foreach ($rows as $r) {
            $list = json_decode((string) $r->payments, true);
            if (! is_array($list)) continue;
            foreach ($list as $p) {
                if (! is_array($p) || empty($p['unlinked'])) continue;
                $d = ! empty($p['date']) ? substr((string) $p['date'], 0, 10) : null;
                if ($since !== null && ($d === null || $d < $since)) continue;
                $out[] = ['external_invoice_id' => (int) $r->id, 'id' => (string) $p['id'], 'date' => $d,
                    'amount' => (float) $p['amount'], 'reference' => $p['reference'] ?? null,
                    'alerted_at' => $p['alerted_at'] ?? null,
                    'label' => (string) ($r->source_label ?: ucfirst((string) $r->external_source))];
            }
        }

        return $out;
    }

    /** The unlinked partner payments that hold reminders for this native invoice ([] = send as normal). */
    public static function heldBy(int $agencyId, object $inv): array
    {
        try {
            $issued = $inv->issued_at ?? null;
            $since = $issued ? substr((string) $issued, 0, 10) : null;
            if ($since === null) {
                $c = DB::table('invoices')->where('id', $inv->id)->value('created_at');
                $since = $c ? substr((string) $c, 0, 10) : null;
            }

            return self::unlinked($agencyId, (int) $inv->family_id, $since);
        } catch (Throwable $e) {
            report($e);

            return [];   // a broken check must not stop reminders altogether
        }
    }

    /** Open native invoices of a family issued on or before a date: what an unlinked payment may be paying. */
    public static function openCovered(int $familyId, ?string $paidOn): array
    {
        return DB::table('invoices')->where('family_id', $familyId)
            ->whereNotIn('status', ['paid', 'void', 'draft', 'cancelled', 'refunded'])->where('balance_due', '>', 0)
            ->when($paidOn, fn ($q) => $q->where(fn ($w) => $w->whereNull('issued_at')->orWhereDate('issued_at', '<=', $paidOn)))
            ->orderBy('due_at')->pluck('invoice_number')->filter()->values()->all();
    }

    /**
     * Who hears about it: the agency admins, its billing contact (agencies.contact_email) and the staff it chose for
     * billing-reminder copies - people with an active role in THIS agency only, never a service login.
     */
    public static function recipients(int $agencyId): array
    {
        $cfg = InvoiceReminders::cfg($agencyId);
        $contact = mb_strtolower(trim((string) DB::table('agencies')->where('id', $agencyId)->value('contact_email')));
        $rows = DB::table('role_assignments as r')->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.agency_id', $agencyId)->where('r.active', 1)->whereNull('u.deleted_at')
            ->get(['u.id', 'u.email', 'r.role']);
        $ids = [];
        foreach ($rows as $r) {
            $email = mb_strtolower(trim((string) $r->email));
            if (preg_match('/^(integration|noreply|no-reply|system|api)[+@]/i', $email)) continue;
            $isAdmin = in_array((string) $r->role, ['agency_admin', 'admin'], true);
            $isContact = $contact !== '' && $email === $contact && $r->role !== 'guardian';
            $chosen = in_array((int) $r->id, $cfg['cc_staff'], true);
            if ($isAdmin || $isContact || $chosen) $ids[(int) $r->id] = true;
        }

        return array_keys($ids);
    }

    /**
     * Bell notice to the agency's billing people about one unlinked payment, and stamp it so it is not repeated
     * within $everyDays (0 = only if never alerted). $heldNumber names the invoice whose reminder was just held.
     */
    public static function alert(int $agencyId, int $familyId, array $pay, ?string $heldNumber, int $everyDays = 0): bool
    {
        try {
            if (! empty($pay['alerted_at'])) {
                if ($everyDays <= 0) return false;
                if (\Illuminate\Support\Carbon::parse($pay['alerted_at'])->diffInDays(now()) < $everyDays) return false;
            }
            $open = self::openCovered($familyId, $pay['date'] ?? null);
            if (! $open && ! $heldNumber) return false;      // nothing of ours it could be paying
            $users = self::recipients($agencyId);
            $family = (string) (DB::table('families')->where('id', $familyId)->value('family_name') ?: 'A family');
            $label = $pay['label'] ?: 'the partner system';
            $names = $heldNumber ?: implode(', ', array_slice($open, 0, 3));
            $amt = '$' . number_format((float) $pay['amount'], 2);
            $title = 'Payment received in ' . $label . ' may cover ' . $names . ' — link it';
            $body = $family . ': ' . $amt . ' received ' . ($pay['date'] ? \Illuminate\Support\Carbon::parse($pay['date'])->format('M j') : '')
                . ($pay['reference'] ? ' (ref ' . $pay['reference'] . ')' : '') . ' is not on any invoice here. '
                . 'In ' . $label . ', edit that payment and tick the invoice it pays. Reminders for this family are on hold until then.';
            $rows = [];
            foreach ($users as $uid) {
                $rows[] = ['user_id' => $uid, 'type' => 'invoice', 'title' => mb_substr($title, 0, 200), 'body' => $body,
                    'data' => json_encode(['url' => '/dashboard.html#external-billing', 'family_id' => $familyId,
                        'partner_payment' => $pay['id'], 'severity' => 'warning'])];
            }
            if ($rows) Notify::write($rows);
            self::stamp((int) $pay['external_invoice_id'], (string) $pay['id']);

            return (bool) $rows;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private static function stamp(int $externalInvoiceId, string $paymentId): void
    {
        $raw = DB::table('external_invoices')->where('id', $externalInvoiceId)->value('payments');
        $list = json_decode((string) $raw, true);
        if (! is_array($list)) return;
        foreach ($list as &$p) {
            if (is_array($p) && (string) ($p['id'] ?? '') === $paymentId) $p['alerted_at'] = now()->toDateTimeString();
        }
        unset($p);
        DB::table('external_invoices')->where('id', $externalInvoiceId)->update(['payments' => json_encode($list)]);
    }
}
