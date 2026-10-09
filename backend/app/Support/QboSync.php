<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * QUICKBOOKS ONLINE — pushing KiddieTrac billing into the agency's company (rebuilt
 * 2026-10-09; the same rules as XeroSync, so the two behave alike):
 *
 *  · A FAMILY becomes a customer, found again through the link table (QuickBooks has no
 *    free "contact number", so the KT-{id} marker goes in Notes and, on a name clash, in
 *    the display name).
 *  · An ISSUED INVOICE becomes an invoice with the same number, lines on the chosen
 *    service item; subsidy and discount are negative lines so the total matches. A re-sync
 *    updates it (sparse update with its SyncToken); unchanged ones are skipped.
 *  · A VOIDED invoice is voided in QuickBooks unless payments are applied to it there.
 *  · A SUCCEEDED PAYMENT is received against the invoice (into the chosen account, or
 *    Undeposited Funds); a refunded one is deleted. Bookkeeping only — no money moves.
 */
final class QboSync
{
    public const ISSUED = ['sent', 'partial', 'paid', 'overdue'];

    private static function log(int $agencyId, string $kind, ?int $localId, string $action, string $message, ?int $userId): void
    {
        try {
            if (in_array($action, ['skipped', 'failed'], true) && DB::table('qbo_sync_log')->where(['agency_id' => $agencyId, 'kind' => $kind, 'local_id' => $localId, 'action' => $action])
                    ->where('message', mb_substr($message, 0, 2000))->where('created_at', '>=', now()->subDay())->exists()) return;
            DB::table('qbo_sync_log')->insert(['agency_id' => $agencyId, 'kind' => $kind, 'local_id' => $localId, 'action' => $action,
                'message' => mb_substr($message, 0, 2000), 'user_id' => $userId, 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }

    private static function link(int $agencyId, string $realm, string $kind, int $localId): ?object
    {
        return DB::table('qbo_links')->where(['agency_id' => $agencyId, 'realm_id' => $realm, 'kind' => $kind, 'local_id' => $localId])->first();
    }

    private static function saveLink(int $agencyId, string $realm, string $kind, int $localId, string $qboId, ?string $number, ?string $fp, ?string $status): void
    {
        DB::table('qbo_links')->updateOrInsert(['agency_id' => $agencyId, 'realm_id' => $realm, 'kind' => $kind, 'local_id' => $localId],
            ['qbo_id' => $qboId, 'qbo_number' => $number, 'fingerprint' => $fp, 'qbo_status' => $status, 'synced_at' => now()]);
    }

    private static function localDate(?string $ts, string $tz): ?string
    {
        if (! $ts) return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ts)) return $ts;
        return Carbon::parse($ts, 'UTC')->setTimezone($tz)->toDateString();
    }

    /* ───────────── customers ───────────── */

    public static function customer(int $agencyId, object $s, int $familyId, ?int $userId): string
    {
        $realm = (string) $s->realm_id;
        $f = DB::table('families')->where('id', $familyId)->first();
        if (! $f) throw new AccountingSyncException('The family on this invoice no longer exists.');
        $marker = 'KT-' . $familyId;
        $name = mb_substr(trim((string) $f->family_name) !== '' ? trim((string) $f->family_name) : ('Family ' . $marker), 0, 90);
        $email = trim((string) ($f->primary_email ?? ''));
        if ($email === '') {
            $email = (string) (DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')->where('g.family_id', $familyId)->orderBy('g.id')->value('u.email') ?? '');
        }
        if (ChatAudience::isServiceEmail($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
        $body = array_filter([
            'Notes' => 'KiddieTrac family ' . $marker,
            'PrimaryEmailAddr' => $email !== '' ? ['Address' => $email] : null,
            'PrimaryPhone' => ! empty($f->primary_phone) ? ['FreeFormNumber' => (string) $f->primary_phone] : null,
            'BillAddr' => ! empty($f->address_line1) ? array_filter(['Line1' => (string) $f->address_line1, 'Line2' => (string) ($f->address_line2 ?? ''),
                'City' => (string) ($f->city ?? ''), 'CountrySubDivisionCode' => (string) ($f->province ?? ''), 'PostalCode' => (string) ($f->postal_code ?? '')]) : null,
        ], fn ($v) => $v !== null);
        $fp = hash('sha256', $name . json_encode($body));

        $l = self::link($agencyId, $realm, 'customer', $familyId);
        if ($l) {
            if ($l->fingerprint !== $fp) {
                try {
                    $cur = QboClient::call($agencyId, 'GET', 'customer/' . $l->qbo_id)['Customer'] ?? [];
                    QboClient::call($agencyId, 'POST', 'customer', array_merge($body, ['Id' => $l->qbo_id, 'SyncToken' => $cur['SyncToken'] ?? '0', 'sparse' => true]));
                    self::saveLink($agencyId, $realm, 'customer', $familyId, $l->qbo_id, $l->qbo_number, $fp, null);
                } catch (AccountingSyncException $e) {
                    self::log($agencyId, 'customer', $familyId, 'failed', 'Customer details not updated: ' . $e->getMessage(), $userId);
                }
            }
            return (string) $l->qbo_id;
        }

        // Adopt our own earlier customer (its Notes carry the marker) before creating one.
        foreach ([$name, $name . ' (' . $marker . ')'] as $dn) {
            foreach (QboClient::query($agencyId, "select * from Customer where DisplayName = '" . QboClient::q($dn) . "'", 'Customer') as $c) {
                if (str_contains((string) ($c['Notes'] ?? ''), $marker)) {
                    self::saveLink($agencyId, $realm, 'customer', $familyId, (string) $c['Id'], $dn, null, null);
                    return (string) $c['Id'];
                }
            }
        }
        try {
            $res = QboClient::call($agencyId, 'POST', 'customer', array_merge(['DisplayName' => $name], $body));
        } catch (AccountingSyncException $e) {
            if ($e->oauthError !== '6240' && stripos($e->getMessage(), 'Duplicate Name') === false) throw $e;
            $name = mb_substr($name, 0, 80) . ' (' . $marker . ')';   // the name belongs to someone else
            $res = QboClient::call($agencyId, 'POST', 'customer', array_merge(['DisplayName' => $name], $body));
        }
        $id = (string) ($res['Customer']['Id'] ?? '');
        if ($id === '') throw new AccountingSyncException('QuickBooks did not return the new customer.');
        self::saveLink($agencyId, $realm, 'customer', $familyId, $id, $name, $fp, null);
        self::log($agencyId, 'customer', $familyId, 'created', 'Customer "' . $name . '" created.', $userId);
        return $id;
    }

    /* ───────────── invoices ───────────── */

    public static function invoiceBody(object $inv, object $s, string $customerId, string $tz): array
    {
        if (empty($s->income_item_id)) throw new AccountingSyncException('Choose the QuickBooks product/service for tuition in Settings → QuickBooks first.');
        $tax = (float) $inv->tax_amount > 0.004;
        $nonUs = strtoupper((string) $s->country) !== 'US';
        if ($tax && empty($s->tax_code_taxable)) throw new AccountingSyncException('This invoice charges tax — choose the QuickBooks tax code for taxable lines in Settings → QuickBooks.');
        $code = $tax ? $s->tax_code_taxable : ($s->tax_code_exempt ?: null);
        $mk = function (string $desc, float $qty, float $unit, string $item, ?string $taxCode) {
            $amount = round($qty * $unit, 2);
            $d = ['ItemRef' => ['value' => $item], 'Qty' => round($qty, 4), 'UnitPrice' => round($unit, 4)];
            if ($taxCode) $d['TaxCodeRef'] = ['value' => $taxCode];
            return ['DetailType' => 'SalesItemLineDetail', 'Amount' => $amount, 'Description' => mb_substr($desc, 0, 4000), 'SalesItemLineDetail' => $d];
        };
        $lines = [];
        $children = [];
        foreach (DB::table('invoice_lines')->where('invoice_id', $inv->id)->orderBy('id')->get() as $l) {
            if ($l->child_id && ! isset($children[$l->child_id])) {
                $children[$l->child_id] = trim((string) DB::table('children')->where('id', $l->child_id)->value(DB::raw("CONCAT_WS(' ', first_name, last_name)")));
            }
            $qty = (float) ($l->quantity ?: 1);
            $unit = $l->unit_amount !== null ? (float) $l->unit_amount : ((float) $l->amount / ($qty ?: 1));
            if (abs(round($qty * $unit, 2) - (float) $l->amount) > 0.009) { $qty = 1; $unit = (float) $l->amount; }
            $desc = trim((string) ($l->description ?: ucfirst(str_replace('_', ' ', (string) $l->line_type))));
            if ($l->child_id && ! empty($children[$l->child_id]) && stripos($desc, $children[$l->child_id]) === false) $desc .= ' — ' . $children[$l->child_id];
            $lines[] = $mk($desc ?: 'Child care', $qty, $unit, $s->income_item_id, $code);
        }
        if (! $lines) $lines[] = $mk('Child care', 1, (float) $inv->subtotal, $s->income_item_id, $code);
        if ((float) $inv->subsidy_amount > 0.004) $lines[] = $mk('Subsidy', 1, -(float) $inv->subsidy_amount, $s->subsidy_item_id ?: $s->income_item_id, $s->tax_code_exempt ?: ($tax ? null : $code));
        if ((float) $inv->discount_amount > 0.004) $lines[] = $mk('Discount', 1, -(float) $inv->discount_amount, $s->income_item_id, $code);

        $issued = self::localDate($inv->issued_at ?: $inv->created_at, $tz);
        $period = ($inv->period_start && $inv->period_end) ? (Carbon::parse($inv->period_start)->format('M j') . '–' . Carbon::parse($inv->period_end)->format('M j, Y')) : '';
        $b = [
            'CustomerRef' => ['value' => $customerId],
            'DocNumber' => mb_substr((string) ($inv->invoice_number ?: ('KT-' . $inv->id)), 0, 21),
            'TxnDate' => $issued,
            'DueDate' => self::localDate($inv->due_at, $tz) ?: $issued,
            'PrivateNote' => trim('KiddieTrac invoice ' . ($inv->invoice_number ?: $inv->id) . ' ' . $period),
            'Line' => $lines,
        ];
        if ($nonUs) $b['GlobalTaxCalculation'] = $tax ? 'TaxExcluded' : 'NotApplicable';
        return $b;
    }

    public static function invoice(int $agencyId, int $invoiceId, ?int $userId = null): string
    {
        $s = QboClient::row($agencyId);
        if (! QboClient::connected($s)) throw new AccountingSyncException('QuickBooks is not connected for this agency.');
        $realm = (string) $s->realm_id;
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        if (! $inv || XeroSync::agencyOfInvoice($invoiceId) !== $agencyId) throw new AccountingSyncException('That invoice is not in this agency.');
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $l = self::link($agencyId, $realm, 'invoice', $invoiceId);

        try {
            if ($inv->status === 'void') {
                if (! $l) return 'skipped';
                if (in_array($l->qbo_status, ['voided', 'deleted'], true)) return 'unchanged';
                $cur = QboClient::call($agencyId, 'GET', 'invoice/' . $l->qbo_id)['Invoice'] ?? [];
                if ((float) ($cur['Balance'] ?? 0) + 0.004 < (float) ($cur['TotalAmt'] ?? 0)) {
                    self::log($agencyId, 'invoice', $invoiceId, 'skipped', 'Voided in KiddieTrac, but QuickBooks invoice ' . $l->qbo_number . ' has payments applied — remove them in QuickBooks, then sync again.', $userId);
                    return 'skipped';
                }
                QboClient::call($agencyId, 'POST', 'invoice', ['Id' => $l->qbo_id, 'SyncToken' => $cur['SyncToken'] ?? '0'], ['operation' => 'void']);
                self::saveLink($agencyId, $realm, 'invoice', $invoiceId, $l->qbo_id, $l->qbo_number, $l->fingerprint, 'voided');
                self::log($agencyId, 'invoice', $invoiceId, 'voided', 'Invoice ' . $l->qbo_number . ' voided in QuickBooks.', $userId);
                return 'voided';
            }
            if (! in_array($inv->status, self::ISSUED, true)) return 'skipped';
            if ($s->sync_from && self::localDate($inv->issued_at ?: $inv->created_at, $tz) < $s->sync_from) return 'skipped';

            $customerId = self::customer($agencyId, $s, (int) $inv->family_id, $userId);
            $body = self::invoiceBody($inv, $s, $customerId, $tz);
            $fp = hash('sha256', json_encode($body));
            $action = 'unchanged';

            if ($l && $l->fingerprint === $fp) {
                // unchanged
            } elseif ($l) {
                $cur = QboClient::call($agencyId, 'GET', 'invoice/' . $l->qbo_id)['Invoice'] ?? [];
                if ((float) ($cur['TotalAmt'] ?? 0) > 0 && (float) ($cur['Balance'] ?? 1) <= 0.004) {
                    self::saveLink($agencyId, $realm, 'invoice', $invoiceId, $l->qbo_id, $l->qbo_number, $l->fingerprint, 'paid');
                    self::log($agencyId, 'invoice', $invoiceId, 'skipped', 'Changed in KiddieTrac, but invoice ' . $l->qbo_number . ' is fully paid in QuickBooks, so it was left as it is.', $userId);
                } else {
                    $res = QboClient::call($agencyId, 'POST', 'invoice', array_merge($body, ['Id' => $l->qbo_id, 'SyncToken' => $cur['SyncToken'] ?? '0', 'sparse' => true]));
                    $x = $res['Invoice'] ?? [];
                    self::saveLink($agencyId, $realm, 'invoice', $invoiceId, $l->qbo_id, $x['DocNumber'] ?? $l->qbo_number, $fp, 'open');
                    self::log($agencyId, 'invoice', $invoiceId, 'updated', 'Invoice ' . ($x['DocNumber'] ?? $l->qbo_number) . ' updated in QuickBooks (total ' . ($x['TotalAmt'] ?? '?') . ').', $userId);
                    $action = 'updated';
                }
            } else {
                $found = QboClient::query($agencyId, "select * from Invoice where DocNumber = '" . QboClient::q($body['DocNumber']) . "'", 'Invoice');
                foreach ($found as $cand) {
                    if ((string) ($cand['CustomerRef']['value'] ?? '') === $customerId) {
                        self::saveLink($agencyId, $realm, 'invoice', $invoiceId, (string) $cand['Id'], $cand['DocNumber'] ?? null, null, 'open');
                        return self::invoice($agencyId, $invoiceId, $userId);
                    }
                }
                $res = QboClient::call($agencyId, 'POST', 'invoice', $body, ['requestid' => substr('kt' . $agencyId . 'i' . $invoiceId . substr($fp, 0, 20), 0, 36)]);
                $x = $res['Invoice'] ?? [];
                if (empty($x['Id'])) throw new AccountingSyncException('QuickBooks did not return the new invoice.');
                self::saveLink($agencyId, $realm, 'invoice', $invoiceId, (string) $x['Id'], $x['DocNumber'] ?? $body['DocNumber'], $fp, 'open');
                $note = abs((float) ($x['TotalAmt'] ?? $inv->total) - (float) $inv->total) > 0.01
                    ? ' Note: QuickBooks total ' . $x['TotalAmt'] . ' differs from KiddieTrac total ' . number_format((float) $inv->total, 2) . ' (tax rounding or rate).' : '';
                self::log($agencyId, 'invoice', $invoiceId, 'created', 'Invoice ' . ($x['DocNumber'] ?? $body['DocNumber']) . ' created in QuickBooks.' . $note, $userId);
                $action = 'created';
            }

            if ($s->sync_payments) self::payments($agencyId, $invoiceId, $userId);
            return $action;
        } catch (AccountingSyncException $e) {
            self::log($agencyId, 'invoice', $invoiceId, 'failed', 'Invoice ' . ($inv->invoice_number ?: $invoiceId) . ': ' . $e->getMessage(), $userId);
            throw $e;
        }
    }

    /* ───────────── payments ───────────── */

    public static function payments(int $agencyId, int $invoiceId, ?int $userId = null): void
    {
        $s = QboClient::row($agencyId);
        $realm = (string) $s->realm_id;
        $il = self::link($agencyId, $realm, 'invoice', $invoiceId);
        if (! $il || in_array($il->qbo_status, ['voided', 'deleted'], true)) return;
        $cust = self::link($agencyId, $realm, 'customer', (int) DB::table('invoices')->where('id', $invoiceId)->value('family_id'));
        if (! $cust) return;
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        foreach (DB::table('payments')->where('invoice_id', $invoiceId)->where('status', 'succeeded')->orderBy('id')->get() as $p) {
            $pl = self::link($agencyId, $realm, 'payment', (int) $p->id);
            try {
                if ($p->refunded_at) {
                    if ($pl && $pl->qbo_status !== 'deleted') {
                        $cur = QboClient::call($agencyId, 'GET', 'payment/' . $pl->qbo_id)['Payment'] ?? [];
                        QboClient::call($agencyId, 'POST', 'payment', ['Id' => $pl->qbo_id, 'SyncToken' => $cur['SyncToken'] ?? '0'], ['operation' => 'delete']);
                        self::saveLink($agencyId, $realm, 'payment', (int) $p->id, $pl->qbo_id, null, null, 'deleted');
                        self::log($agencyId, 'payment', (int) $p->id, 'deleted', 'Refunded in KiddieTrac — payment removed from invoice ' . $il->qbo_number . ' in QuickBooks.', $userId);
                    }
                    continue;
                }
                if ($pl) continue;
                $b = [
                    'CustomerRef' => ['value' => $cust->qbo_id],
                    'TotalAmt' => round((float) $p->amount, 2),
                    'TxnDate' => self::localDate($p->paid_at ?: $p->created_at, $tz),
                    'PaymentRefNum' => mb_substr((string) ($p->reference_number ?: ('KT-' . $p->id)), 0, 21),
                    'PrivateNote' => 'KiddieTrac ' . str_replace('_', ' ', (string) $p->method) . ' payment #' . $p->id,
                    'Line' => [['Amount' => round((float) $p->amount, 2), 'LinkedTxn' => [['TxnId' => $il->qbo_id, 'TxnType' => 'Invoice']]]],
                ];
                if (! empty($s->deposit_account_id)) $b['DepositToAccountRef'] = ['value' => $s->deposit_account_id];
                $res = QboClient::call($agencyId, 'POST', 'payment', $b, ['requestid' => 'kt' . $agencyId . 'p' . $p->id]);
                $x = $res['Payment'] ?? [];
                if (empty($x['Id'])) throw new AccountingSyncException('QuickBooks did not return the payment.');
                self::saveLink($agencyId, $realm, 'payment', (int) $p->id, (string) $x['Id'], null, null, 'open');
                self::log($agencyId, 'payment', (int) $p->id, 'created', 'Payment of ' . number_format((float) $p->amount, 2) . ' received on invoice ' . $il->qbo_number . '.', $userId);
            } catch (AccountingSyncException $e) {
                self::log($agencyId, 'payment', (int) $p->id, 'failed', 'Payment ' . number_format((float) $p->amount, 2) . ' on invoice ' . $il->qbo_number . ': ' . $e->getMessage(), $userId);
            }
        }
    }

    /* ───────────── the whole agency ───────────── */

    public static function pendingIds(int $agencyId, int $limit = 500): array
    {
        $s = QboClient::row($agencyId);
        if (! QboClient::connected($s)) return [];
        $realm = $s->realm_id;
        $q = DB::table('invoices as i')->join('centres as c', 'c.id', '=', 'i.centre_id')
            ->leftJoin('qbo_links as l', function ($j) use ($agencyId, $realm) {
                $j->on('l.local_id', '=', 'i.id')->where('l.kind', 'invoice')->where('l.agency_id', $agencyId)->where('l.realm_id', $realm);
            })
            ->where('c.agency_id', $agencyId)
            ->where(function ($w) {
                $w->where(function ($a) { $a->whereIn('i.status', self::ISSUED)->where(function ($b) { $b->whereNull('l.id')->orWhereColumn('i.updated_at', '>', 'l.synced_at'); }); })
                  ->orWhere(function ($a) { $a->where('i.status', 'void')->whereNotNull('l.id')->whereNotIn('l.qbo_status', ['voided', 'deleted']); });
            });
        if ($s->sync_from) $q->whereRaw('DATE(COALESCE(i.issued_at, i.created_at)) >= ?', [$s->sync_from]);
        $ids = $q->orderBy('i.id')->limit($limit)->pluck('i.id')->all();
        if ($s->sync_payments) {
            $ids = array_merge($ids, DB::table('payments as p')->join('qbo_links as l', function ($j) use ($agencyId, $realm) {
                    $j->on('l.local_id', '=', 'p.invoice_id')->where('l.kind', 'invoice')->where('l.agency_id', $agencyId)->where('l.realm_id', $realm);
                })
                ->leftJoin('qbo_links as pl', function ($j) use ($agencyId, $realm) {
                    $j->on('pl.local_id', '=', 'p.id')->where('pl.kind', 'payment')->where('pl.agency_id', $agencyId)->where('pl.realm_id', $realm);
                })
                ->where('p.status', 'succeeded')->whereNotIn('l.qbo_status', ['voided', 'deleted'])
                ->where(function ($w) { $w->where(function ($a) { $a->whereNull('p.refunded_at')->whereNull('pl.id'); })->orWhere(function ($a) { $a->whereNotNull('p.refunded_at')->whereNotNull('pl.id')->where('pl.qbo_status', '!=', 'deleted'); }); })
                ->pluck('p.invoice_id')->all());
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

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
                DB::table('qbo_links')->where(['agency_id' => $agencyId, 'kind' => 'invoice', 'local_id' => $id])->update(['synced_at' => now()]);
            } catch (AccountingSyncException $e) {
                $out['failed']++;
                $out['error'] = $e->getMessage();
                $m = $e->getMessage();
                $setup = in_array($e->getCode(), [401, 403, 429], true);
                foreach (['not connected', 'connect again', 'product/service', 'AuthenticationFailed', 'AuthorizationFailed'] as $needle) { if (stripos($m, $needle) !== false) $setup = true; }
                if ($setup) { $out['remaining'] = count($ids) - $i - 1; break; }
            }
        }
        DB::table('agency_qbo')->where('agency_id', $agencyId)->update(['last_sync_at' => now(), 'last_error' => $out['error'], 'updated_at' => now()]);
        return $out;
    }
}
