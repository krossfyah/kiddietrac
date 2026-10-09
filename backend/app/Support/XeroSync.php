<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * XERO — pushing KiddieTrac's billing into the agency's Xero organisation (2026-10-09).
 *
 *  · A FAMILY becomes a Xero contact, tagged with ContactNumber "KT-{family id}" so it is
 *    found again rather than duplicated (a Xero contact name must be unique; a clash with a
 *    different contact gets "(KT-{id})" appended).
 *  · An ISSUED INVOICE (sent / partial / paid / overdue — never a KiddieTrac draft) becomes
 *    a sales invoice (ACCREC) with the same number. Subsidy and discount are their own
 *    negative lines so the total matches. Re-syncing UPDATES the same Xero invoice; an
 *    unchanged invoice is skipped by fingerprint. A voided KiddieTrac invoice is voided
 *    (or, if still a Xero draft, deleted) in Xero.
 *  · A SUCCEEDED PAYMENT is recorded against that invoice into the chosen bank account; a
 *    refunded one has its Xero payment deleted. Bookkeeping only — nothing here moves money.
 *
 * Every outcome is written to xero_sync_log, which the settings screen shows.
 */
final class XeroSync
{
    public const ISSUED = ['sent', 'partial', 'paid', 'overdue'];

    private static function log(int $agencyId, string $kind, ?int $localId, string $action, string $message, ?int $userId): void
    {
        try {
            // The same skip or failure is noted once a day, not every 30 minutes.
            if (in_array($action, ['skipped', 'failed'], true) && DB::table('xero_sync_log')->where(['agency_id' => $agencyId, 'kind' => $kind, 'local_id' => $localId, 'action' => $action])
                    ->where('message', mb_substr($message, 0, 2000))->where('created_at', '>=', now()->subDay())->exists()) return;
            DB::table('xero_sync_log')->insert(['agency_id' => $agencyId, 'kind' => $kind, 'local_id' => $localId, 'action' => $action,
                'message' => mb_substr($message, 0, 2000), 'user_id' => $userId, 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }

    private static function link(int $agencyId, string $tenant, string $kind, int $localId): ?object
    {
        return DB::table('xero_links')->where(['agency_id' => $agencyId, 'tenant_id' => $tenant, 'kind' => $kind, 'local_id' => $localId])->first();
    }

    private static function saveLink(int $agencyId, string $tenant, string $kind, int $localId, string $xeroId, ?string $number, ?string $fp, ?string $status): void
    {
        DB::table('xero_links')->updateOrInsert(
            ['agency_id' => $agencyId, 'tenant_id' => $tenant, 'kind' => $kind, 'local_id' => $localId],
            ['xero_id' => $xeroId, 'xero_number' => $number, 'fingerprint' => $fp, 'xero_status' => $status, 'synced_at' => now()]);
    }

    /** The agency an invoice belongs to (invoices carry centre_id, not agency_id). */
    public static function agencyOfInvoice(int $invoiceId): ?int
    {
        $id = DB::table('invoices as i')->join('centres as c', 'c.id', '=', 'i.centre_id')->where('i.id', $invoiceId)->value('c.agency_id');
        return $id ? (int) $id : null;
    }

    private static function localDate(?string $ts, string $tz): ?string
    {
        if (! $ts) return null;
        // Plain dates stay as they are; timestamps are stored UTC and shown in agency time.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ts)) return $ts;
        return Carbon::parse($ts, 'UTC')->setTimezone($tz)->toDateString();
    }

    private static function q(string $s): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $s);
    }

    /* ───────────── contacts ───────────── */

    public static function contact(int $agencyId, object $s, int $familyId, ?int $userId): string
    {
        $tenant = (string) $s->tenant_id;
        $f = DB::table('families')->where('id', $familyId)->first();
        if (! $f) throw new XeroException('The family on this invoice no longer exists.');

        $number = 'KT-' . $familyId;
        $name = trim((string) $f->family_name) !== '' ? trim((string) $f->family_name) : ('Family ' . $number);
        $email = trim((string) ($f->primary_email ?? ''));
        if ($email === '') {
            $email = (string) (DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')->where('g.family_id', $familyId)
                ->orderBy('g.id')->value('u.email') ?? '');
        }
        if (ChatAudience::isServiceEmail($email)) $email = '';
        $body = array_filter([
            'Name' => $name,
            'ContactNumber' => $number,
            'EmailAddress' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'Phones' => ! empty($f->primary_phone) ? [['PhoneType' => 'DEFAULT', 'PhoneNumber' => (string) $f->primary_phone]] : null,
            'Addresses' => ! empty($f->address_line1) ? [['AddressType' => 'POBOX', 'AddressLine1' => (string) $f->address_line1,
                'AddressLine2' => (string) ($f->address_line2 ?? ''), 'City' => (string) ($f->city ?? ''), 'Region' => (string) ($f->province ?? ''),
                'PostalCode' => (string) ($f->postal_code ?? '')]] : null,
        ], fn ($v) => $v !== null);
        $fp = hash('sha256', json_encode($body));

        $l = self::link($agencyId, $tenant, 'contact', $familyId);
        if ($l) {
            if ($l->fingerprint !== $fp) {
                try {
                    $upd = $body;
                    unset($upd['Name']);   // never rename an existing contact: its name may have been de-duplicated
                    XeroClient::call($agencyId, 'POST', 'Contacts/' . $l->xero_id, ['Contacts' => [array_merge(['ContactID' => $l->xero_id], $upd)]]);
                    self::saveLink($agencyId, $tenant, 'contact', $familyId, $l->xero_id, $number, $fp, null);
                } catch (XeroException $e) {
                    self::log($agencyId, 'contact', $familyId, 'failed', 'Contact details not updated: ' . $e->getMessage(), $userId);
                }
            }
            return (string) $l->xero_id;
        }

        // Already in Xero (an earlier sync whose link was lost, or a re-connect)?
        $found = XeroClient::call($agencyId, 'GET', 'Contacts', null, ['where' => 'ContactNumber=="' . self::q($number) . '"']);
        $c = $found['Contacts'][0] ?? null;
        if ($c && ! empty($c['ContactID'])) {
            self::saveLink($agencyId, $tenant, 'contact', $familyId, $c['ContactID'], $number, null, null);
            return (string) $c['ContactID'];
        }

        try {
            $res = XeroClient::call($agencyId, 'POST', 'Contacts', ['Contacts' => [$body]]);
        } catch (XeroException $e) {
            if (stripos($e->getMessage(), 'already') === false) throw $e;
            // The name belongs to another Xero contact: keep both, distinguishable.
            $body['Name'] = $name . ' (' . $number . ')';
            $res = XeroClient::call($agencyId, 'POST', 'Contacts', ['Contacts' => [$body]]);
        }
        $id = (string) ($res['Contacts'][0]['ContactID'] ?? '');
        if ($id === '') throw new XeroException('Xero did not return the new contact.');
        self::saveLink($agencyId, $tenant, 'contact', $familyId, $id, $number, $fp, null);
        self::log($agencyId, 'contact', $familyId, 'created', 'Contact "' . $body['Name'] . '" created.', $userId);
        return $id;
    }

    /* ───────────── invoices ───────────── */

    /** Build the Xero invoice for a KiddieTrac invoice. Throws when settings are missing. */
    public static function invoiceBody(object $inv, object $s, string $contactId, string $tz): array
    {
        if (empty($s->revenue_account_code)) throw new XeroException('Choose the Xero sales account for tuition in Settings → Xero first.');
        $tax = (float) $inv->tax_amount > 0.004;
        if ($tax && empty($s->tax_type)) {
            throw new XeroException('This invoice charges tax — choose the Xero tax rate to use in Settings → Xero.');
        }
        $lines = [];
        $children = [];
        foreach (DB::table('invoice_lines')->where('invoice_id', $inv->id)->orderBy('id')->get() as $l) {
            if (! isset($children[$l->child_id]) && $l->child_id) {
                $children[$l->child_id] = trim((string) DB::table('children')->where('id', $l->child_id)->value(DB::raw("CONCAT_WS(' ', first_name, last_name)")));
            }
            $qty = (float) ($l->quantity ?: 1);
            $unit = $l->unit_amount !== null ? (float) $l->unit_amount : ((float) $l->amount / ($qty ?: 1));
            if (abs(round($qty * $unit, 2) - (float) $l->amount) > 0.009) { $qty = 1; $unit = (float) $l->amount; }
            $desc = trim((string) ($l->description ?: ucfirst(str_replace('_', ' ', (string) $l->line_type))));
            if ($l->child_id && ! empty($children[$l->child_id]) && stripos($desc, $children[$l->child_id]) === false) $desc .= ' — ' . $children[$l->child_id];
            $line = ['Description' => $desc ?: 'Child care', 'Quantity' => round($qty, 4), 'UnitAmount' => round($unit, 4), 'AccountCode' => $s->revenue_account_code];
            $line['TaxType'] = $tax ? $s->tax_type : 'NONE';
            $lines[] = $line;
        }
        if (! $lines) {
            $lines[] = ['Description' => 'Child care', 'Quantity' => 1, 'UnitAmount' => round((float) $inv->subtotal, 2), 'AccountCode' => $s->revenue_account_code, 'TaxType' => $tax ? $s->tax_type : 'NONE'];
        }
        if ((float) $inv->subsidy_amount > 0.004) {
            $lines[] = ['Description' => 'Subsidy', 'Quantity' => 1, 'UnitAmount' => -round((float) $inv->subsidy_amount, 2),
                'AccountCode' => $s->subsidy_account_code ?: $s->revenue_account_code, 'TaxType' => 'NONE'];
        }
        if ((float) $inv->discount_amount > 0.004) {
            $lines[] = ['Description' => 'Discount', 'Quantity' => 1, 'UnitAmount' => -round((float) $inv->discount_amount, 2),
                'AccountCode' => $s->revenue_account_code, 'TaxType' => $tax ? $s->tax_type : 'NONE'];
        }
        $issued = self::localDate($inv->issued_at ?: $inv->created_at, $tz);
        $period = ($inv->period_start && $inv->period_end) ? (Carbon::parse($inv->period_start)->format('M j') . '–' . Carbon::parse($inv->period_end)->format('M j, Y')) : '';
        return [
            'Type' => 'ACCREC',
            'Contact' => ['ContactID' => $contactId],
            'InvoiceNumber' => (string) ($inv->invoice_number ?: ('KT-INV-' . $inv->id)),
            'Reference' => trim('KiddieTrac ' . $period),
            'Date' => $issued,
            'DueDate' => self::localDate($inv->due_at, $tz) ?: $issued,
            'LineAmountTypes' => $tax ? 'Exclusive' : 'NoTax',
            'Status' => $s->invoice_status === 'DRAFT' ? 'DRAFT' : 'AUTHORISED',
            'LineItems' => $lines,
        ];
    }

    /** Sync one invoice (and, when enabled, its payments). Returns the action taken. */
    public static function invoice(int $agencyId, int $invoiceId, ?int $userId = null): string
    {
        $s = XeroClient::row($agencyId);
        if (! XeroClient::connected($s)) throw new XeroException('Xero is not connected for this agency.');
        $tenant = (string) $s->tenant_id;
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        if (! $inv || self::agencyOfInvoice($invoiceId) !== $agencyId) throw new XeroException('That invoice is not in this agency.');
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $l = self::link($agencyId, $tenant, 'invoice', $invoiceId);

        try {
            // VOID in KiddieTrac → void (or delete a draft) in Xero; never pushed → nothing to do.
            if ($inv->status === 'void') {
                if (! $l) return 'skipped';
                if (in_array($l->xero_status, ['VOIDED', 'DELETED'], true)) return 'unchanged';
                $x = XeroClient::call($agencyId, 'GET', 'Invoices/' . $l->xero_id);
                $cur = $x['Invoices'][0] ?? [];
                if (! empty($cur['Payments'])) {
                    self::log($agencyId, 'invoice', $invoiceId, 'skipped', 'Voided in KiddieTrac, but the Xero invoice ' . $l->xero_number . ' has payments — remove them in Xero, then sync again.', $userId);
                    return 'skipped';
                }
                $to = in_array($cur['Status'] ?? '', ['DRAFT', 'SUBMITTED'], true) ? 'DELETED' : 'VOIDED';
                XeroClient::call($agencyId, 'POST', 'Invoices/' . $l->xero_id, ['Invoices' => [['InvoiceID' => $l->xero_id, 'Status' => $to]]]);
                self::saveLink($agencyId, $tenant, 'invoice', $invoiceId, $l->xero_id, $l->xero_number, $l->fingerprint, $to);
                self::log($agencyId, 'invoice', $invoiceId, 'voided', 'Invoice ' . $l->xero_number . ($to === 'DELETED' ? ' deleted (it was a draft)' : ' voided') . ' in Xero.', $userId);
                return 'voided';
            }
            if (! in_array($inv->status, self::ISSUED, true)) return 'skipped';   // KiddieTrac drafts stay here
            if ($s->sync_from && self::localDate($inv->issued_at ?: $inv->created_at, $tz) < $s->sync_from) return 'skipped';

            $contactId = self::contact($agencyId, $s, (int) $inv->family_id, $userId);
            $body = self::invoiceBody($inv, $s, $contactId, $tz);
            $fp = hash('sha256', json_encode($body));
            $action = 'unchanged';

            if ($l && $l->fingerprint === $fp) {
                // nothing changed on the invoice itself
            } elseif ($l) {
                if (in_array($l->xero_status, ['PAID', 'VOIDED', 'DELETED'], true)) {
                    self::log($agencyId, 'invoice', $invoiceId, 'skipped', 'Changed in KiddieTrac, but invoice ' . $l->xero_number . ' is ' . strtolower((string) $l->xero_status) . ' in Xero, so it was left as it is.', $userId);
                } else {
                    // An approved invoice keeps its status; asking for DRAFT again would be refused.
                    if (($l->xero_status ?? '') === 'AUTHORISED') $body['Status'] = 'AUTHORISED';
                    $res = XeroClient::call($agencyId, 'POST', 'Invoices/' . $l->xero_id, ['Invoices' => [array_merge(['InvoiceID' => $l->xero_id], $body)]]);
                    $x = $res['Invoices'][0] ?? [];
                    self::saveLink($agencyId, $tenant, 'invoice', $invoiceId, $l->xero_id, $x['InvoiceNumber'] ?? $l->xero_number, $fp, $x['Status'] ?? $l->xero_status);
                    self::log($agencyId, 'invoice', $invoiceId, 'updated', 'Invoice ' . ($x['InvoiceNumber'] ?? $l->xero_number) . ' updated in Xero (total ' . ($x['Total'] ?? '?') . ').', $userId);
                    $action = 'updated';
                }
            } else {
                // Adopt an invoice this agency already pushed (same number, same KT contact) instead of duplicating it.
                $existing = XeroClient::call($agencyId, 'GET', 'Invoices', null, ['InvoiceNumbers' => $body['InvoiceNumber'], 'ContactIDs' => $contactId]);
                $x = null;
                foreach ((array) ($existing['Invoices'] ?? []) as $cand) {
                    if (($cand['Type'] ?? '') === 'ACCREC' && ! in_array($cand['Status'] ?? '', ['DELETED', 'VOIDED'], true)) { $x = $cand; break; }
                }
                if ($x) {
                    self::saveLink($agencyId, $tenant, 'invoice', $invoiceId, $x['InvoiceID'], $x['InvoiceNumber'] ?? null, null, $x['Status'] ?? null);
                    return self::invoice($agencyId, $invoiceId, $userId);   // now linked: update it
                }
                $res = XeroClient::call($agencyId, 'POST', 'Invoices', ['Invoices' => [$body]], [], ['Idempotency-Key' => 'kt-' . $agencyId . '-inv-' . $invoiceId . '-' . substr($fp, 0, 16)]);
                $x = $res['Invoices'][0] ?? [];
                if (empty($x['InvoiceID'])) throw new XeroException('Xero did not return the new invoice.');
                self::saveLink($agencyId, $tenant, 'invoice', $invoiceId, $x['InvoiceID'], $x['InvoiceNumber'] ?? $body['InvoiceNumber'], $fp, $x['Status'] ?? $body['Status']);
                $note = abs((float) ($x['Total'] ?? $inv->total) - (float) $inv->total) > 0.01
                    ? ' Note: Xero total ' . $x['Total'] . ' differs from KiddieTrac total ' . number_format((float) $inv->total, 2) . ' (tax rounding or rate).' : '';
                self::log($agencyId, 'invoice', $invoiceId, 'created', 'Invoice ' . ($x['InvoiceNumber'] ?? $body['InvoiceNumber']) . ' created in Xero as ' . strtolower((string) ($x['Status'] ?? $body['Status'])) . '.' . $note, $userId);
                $action = 'created';
            }

            if ($s->sync_payments) self::payments($agencyId, $invoiceId, $userId);
            return $action;
        } catch (XeroException $e) {
            self::log($agencyId, 'invoice', $invoiceId, 'failed', 'Invoice ' . ($inv->invoice_number ?: $invoiceId) . ': ' . $e->getMessage(), $userId);
            throw $e;
        }
    }

    /* ───────────── payments ───────────── */

    public static function payments(int $agencyId, int $invoiceId, ?int $userId = null): void
    {
        $s = XeroClient::row($agencyId);
        $tenant = (string) $s->tenant_id;
        $il = self::link($agencyId, $tenant, 'invoice', $invoiceId);
        if (! $il) return;
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $pays = DB::table('payments')->where('invoice_id', $invoiceId)->where('status', 'succeeded')->orderBy('id')->get();
        foreach ($pays as $p) {
            $pl = self::link($agencyId, $tenant, 'payment', (int) $p->id);
            try {
                if ($p->refunded_at) {
                    if ($pl && $pl->xero_status !== 'DELETED') {
                        XeroClient::call($agencyId, 'POST', 'Payments/' . $pl->xero_id, ['Payments' => [['PaymentID' => $pl->xero_id, 'Status' => 'DELETED']]]);
                        self::saveLink($agencyId, $tenant, 'payment', (int) $p->id, $pl->xero_id, null, null, 'DELETED');
                        self::log($agencyId, 'payment', (int) $p->id, 'deleted', 'Refunded in KiddieTrac — payment removed from invoice ' . $il->xero_number . ' in Xero.', $userId);
                    }
                    continue;
                }
                if ($pl) continue;
                if (empty($s->payment_account_code)) {
                    self::log($agencyId, 'payment', (int) $p->id, 'skipped', 'Payment not recorded: choose the Xero bank account for payments in Settings → Xero.', $userId);
                    continue;
                }
                if (($il->xero_status ?? '') !== 'AUTHORISED') {
                    self::log($agencyId, 'payment', (int) $p->id, 'skipped', 'Payment not recorded: invoice ' . $il->xero_number . ' is a draft in Xero (approve it there, or set invoices to go in as Approved).', $userId);
                    continue;
                }
                $res = XeroClient::call($agencyId, 'PUT', 'Payments', ['Payments' => [[
                    'Invoice' => ['InvoiceID' => $il->xero_id],
                    'Account' => ['Code' => $s->payment_account_code],
                    'Date' => self::localDate($p->paid_at ?: $p->created_at, $tz),
                    'Amount' => round((float) $p->amount, 2),
                    'Reference' => trim('KiddieTrac ' . str_replace('_', ' ', (string) $p->method) . ' ' . ($p->reference_number ?: ('#' . $p->id))),
                ]]], [], ['Idempotency-Key' => 'kt-' . $agencyId . '-pay-' . $p->id]);
                $x = $res['Payments'][0] ?? [];
                if (empty($x['PaymentID'])) throw new XeroException('Xero did not return the payment.');
                self::saveLink($agencyId, $tenant, 'payment', (int) $p->id, $x['PaymentID'], null, null, 'AUTHORISED');
                self::log($agencyId, 'payment', (int) $p->id, 'created', 'Payment of ' . number_format((float) $p->amount, 2) . ' recorded on invoice ' . $il->xero_number . '.', $userId);
                if (isset($x['Invoice']['Status'])) DB::table('xero_links')->where('id', $il->id)->update(['xero_status' => $x['Invoice']['Status']]);
            } catch (XeroException $e) {
                self::log($agencyId, 'payment', (int) $p->id, 'failed', 'Payment ' . number_format((float) $p->amount, 2) . ' on invoice ' . $il->xero_number . ': ' . $e->getMessage(), $userId);
            }
        }
    }

    /* ───────────── the whole agency ───────────── */

    /** Invoices that need a sync: issued and never linked, changed since their last sync, or voided after being pushed. */
    public static function pendingIds(int $agencyId, int $limit = 500): array
    {
        $s = XeroClient::row($agencyId);
        if (! XeroClient::connected($s)) return [];
        $q = DB::table('invoices as i')->join('centres as c', 'c.id', '=', 'i.centre_id')
            ->leftJoin('xero_links as l', function ($j) use ($agencyId, $s) {
                $j->on('l.local_id', '=', 'i.id')->where('l.kind', 'invoice')->where('l.agency_id', $agencyId)->where('l.tenant_id', $s->tenant_id);
            })
            ->where('c.agency_id', $agencyId)
            ->where(function ($w) {
                $w->where(function ($a) { $a->whereIn('i.status', self::ISSUED)->where(function ($b) { $b->whereNull('l.id')->orWhereColumn('i.updated_at', '>', 'l.synced_at'); }); })
                  ->orWhere(function ($a) { $a->where('i.status', 'void')->whereNotNull('l.id')->whereNotIn('l.xero_status', ['VOIDED', 'DELETED']); });
            });
        if ($s->sync_from) $q->whereRaw('DATE(COALESCE(i.issued_at, i.created_at)) >= ?', [$s->sync_from]);
        // Payments added or refunded since the invoice was last synced.
        $withPayments = DB::table('payments as p')->join('xero_links as l', function ($j) use ($agencyId, $s) {
                $j->on('l.local_id', '=', 'p.invoice_id')->where('l.kind', 'invoice')->where('l.agency_id', $agencyId)->where('l.tenant_id', $s->tenant_id);
            })
            ->leftJoin('xero_links as pl', function ($j) use ($agencyId, $s) {
                $j->on('pl.local_id', '=', 'p.id')->where('pl.kind', 'payment')->where('pl.agency_id', $agencyId)->where('pl.tenant_id', $s->tenant_id);
            })
            ->where('p.status', 'succeeded')
            ->where(function ($w) { $w->where(function ($a) { $a->whereNull('p.refunded_at')->whereNull('pl.id'); })->orWhere(function ($a) { $a->whereNotNull('p.refunded_at')->whereNotNull('pl.id')->where('pl.xero_status', '!=', 'DELETED'); }); })
            ->pluck('p.invoice_id')->all();
        $ids = $q->orderBy('i.id')->limit($limit)->pluck('i.id')->all();
        if ($s->sync_payments) $ids = array_merge($ids, $withPayments);
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** Sync what is pending, within a time budget. Returns counts. */
    public static function agency(int $agencyId, ?int $userId = null, int $budgetSeconds = 50): array
    {
        $out = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'voided' => 0, 'skipped' => 0, 'failed' => 0, 'remaining' => 0, 'error' => null];
        $started = microtime(true);
        $ids = self::pendingIds($agencyId);
        foreach ($ids as $i => $id) {
            if (microtime(true) - $started > $budgetSeconds) { $out['remaining'] = count($ids) - $i; break; }
            try {
                $a = self::invoice($agencyId, (int) $id, $userId);
                $out[$a] = ($out[$a] ?? 0) + 1;
                DB::table('xero_links')->where(['agency_id' => $agencyId, 'kind' => 'invoice', 'local_id' => $id])->update(['synced_at' => now()]);
            } catch (XeroException $e) {
                $out['failed']++;
                $out['error'] = $e->getMessage();
                $m = $e->getMessage();
                $setup = in_array($e->getCode(), [401, 403, 429], true);
                foreach (['not connected', 'connect again', 'rate limit', 'sales account'] as $needle) { if (stripos($m, $needle) !== false) $setup = true; }
                if ($setup) {
                    $out['remaining'] = count($ids) - $i - 1;
                    break;   // a setup problem fails every invoice the same way: stop and say so once
                }
            }
        }
        DB::table('agency_xero')->where('agency_id', $agencyId)->update(['last_sync_at' => now(), 'last_error' => $out['error'], 'updated_at' => now()]);
        return $out;
    }
}
