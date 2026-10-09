<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SAGE ACCOUNTING (cloud) — pushing KiddieTrac billing into the agency's Sage business
 * (2026-10-09; the same rules as XeroSync / QboSync):
 *   · a family → a CUSTOMER contact with reference "KT-{family id}" (found again by it);
 *   · an issued invoice → a sales invoice whose reference is the KiddieTrac number (Sage
 *     numbers its own invoices), subsidy and discount as negative lines; re-syncs update;
 *   · a voided invoice → voided in Sage (unless payments are allocated to it there);
 *   · a succeeded payment → a customer receipt allocated to the invoice; a refund deletes it.
 */
final class SageSync
{
    public const ISSUED = ['sent', 'partial', 'paid', 'overdue'];

    private static function log(int $agencyId, string $kind, ?int $localId, string $action, string $message, ?int $userId): void
    {
        try {
            if (in_array($action, ['skipped', 'failed'], true) && DB::table('sage_sync_log')->where(['agency_id' => $agencyId, 'kind' => $kind, 'local_id' => $localId, 'action' => $action])
                    ->where('message', mb_substr($message, 0, 2000))->where('created_at', '>=', now()->subDay())->exists()) return;
            DB::table('sage_sync_log')->insert(['agency_id' => $agencyId, 'kind' => $kind, 'local_id' => $localId, 'action' => $action,
                'message' => mb_substr($message, 0, 2000), 'user_id' => $userId, 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }

    private static function link(int $a, string $b, string $kind, int $id): ?object
    {
        return DB::table('sage_links')->where(['agency_id' => $a, 'business_id' => $b, 'kind' => $kind, 'local_id' => $id])->first();
    }

    private static function saveLink(int $a, string $b, string $kind, int $id, string $sageId, ?string $num, ?string $fp, ?string $status): void
    {
        DB::table('sage_links')->updateOrInsert(['agency_id' => $a, 'business_id' => $b, 'kind' => $kind, 'local_id' => $id],
            ['sage_id' => $sageId, 'sage_number' => $num, 'fingerprint' => $fp, 'sage_status' => $status, 'synced_at' => now()]);
    }

    public static function localDate(?string $ts, string $tz): ?string
    {
        if (! $ts) return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ts)) return $ts;
        return Carbon::parse($ts, 'UTC')->setTimezone($tz)->toDateString();
    }

    private static function status(array $x): ?string
    {
        return isset($x['status']['id']) ? strtoupper((string) $x['status']['id']) : null;
    }

    /* ───────────── contacts ───────────── */

    public static function contact(int $agencyId, object $s, int $familyId, ?int $userId): string
    {
        $biz = (string) $s->business_id;
        $f = DB::table('families')->where('id', $familyId)->first();
        if (! $f) throw new AccountingSyncException('The family on this invoice no longer exists.');
        $ref = 'KT-' . $familyId;
        $name = trim((string) $f->family_name) !== '' ? trim((string) $f->family_name) : ('Family ' . $ref);
        $email = trim((string) ($f->primary_email ?? ''));
        if ($email === '') $email = (string) (DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')->where('g.family_id', $familyId)->orderBy('g.id')->value('u.email') ?? '');
        if (ChatAudience::isServiceEmail($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
        $body = array_filter([
            'name' => $name,
            'contact_type_ids' => ['CUSTOMER'],
            'reference' => $ref,
            'email' => $email ?: null,
            'telephone' => ! empty($f->primary_phone) ? (string) $f->primary_phone : null,
            'main_address' => ! empty($f->address_line1) ? array_filter(['address_line_1' => (string) $f->address_line1, 'address_line_2' => (string) ($f->address_line2 ?? ''),
                'city' => (string) ($f->city ?? ''), 'region' => (string) ($f->province ?? ''), 'postal_code' => (string) ($f->postal_code ?? '')]) : null,
        ], fn ($v) => $v !== null);
        $fp = hash('sha256', json_encode($body));

        $l = self::link($agencyId, $biz, 'contact', $familyId);
        if ($l) {
            if ($l->fingerprint !== $fp) {
                try {
                    $upd = $body; unset($upd['contact_type_ids']);
                    SageClient::call($agencyId, 'PUT', 'contacts/' . $l->sage_id, ['contact' => $upd]);
                    self::saveLink($agencyId, $biz, 'contact', $familyId, $l->sage_id, $ref, $fp, null);
                } catch (AccountingSyncException $e) {
                    self::log($agencyId, 'contact', $familyId, 'failed', 'Customer details not updated: ' . $e->getMessage(), $userId);
                }
            }
            return (string) $l->sage_id;
        }
        foreach (SageClient::call($agencyId, 'GET', 'contacts', null, ['search' => $ref, 'contact_type_id' => 'CUSTOMER', 'attributes' => 'reference'])['$items'] ?? [] as $c) {
            if (strcasecmp((string) ($c['reference'] ?? ''), $ref) === 0) {
                self::saveLink($agencyId, $biz, 'contact', $familyId, (string) $c['id'], $ref, null, null);
                return (string) $c['id'];
            }
        }
        $res = SageClient::call($agencyId, 'POST', 'contacts', ['contact' => $body]);
        $id = (string) ($res['id'] ?? '');
        if ($id === '') throw new AccountingSyncException('Sage did not return the new customer.');
        self::saveLink($agencyId, $biz, 'contact', $familyId, $id, $ref, $fp, null);
        self::log($agencyId, 'contact', $familyId, 'created', 'Customer "' . $name . '" (' . $ref . ') created.', $userId);
        return $id;
    }

    /* ───────────── invoices ───────────── */

    public static function invoiceBody(object $inv, object $s, string $contactId, string $tz): array
    {
        if (empty($s->ledger_account_id)) throw new AccountingSyncException('Choose the Sage sales ledger account for tuition in Settings → Sage first.');
        $tax = (float) $inv->tax_amount > 0.004;
        if ($tax && empty($s->tax_rate_taxable)) throw new AccountingSyncException('This invoice charges tax — choose the Sage tax rate for taxed lines in Settings → Sage.');
        $rate = $tax ? $s->tax_rate_taxable : ($s->tax_rate_exempt ?: null);
        $mk = function (string $desc, float $qty, float $unit, string $ledger, ?string $taxRate) {
            $l = ['description' => mb_substr($desc, 0, 500), 'ledger_account_id' => $ledger, 'quantity' => round($qty, 4), 'unit_price' => round($unit, 4)];
            if ($taxRate) $l['tax_rate_id'] = $taxRate;
            return $l;
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
            $lines[] = $mk($desc ?: 'Child care', $qty, $unit, $s->ledger_account_id, $rate);
        }
        if (! $lines) $lines[] = $mk('Child care', 1, (float) $inv->subtotal, $s->ledger_account_id, $rate);
        if ((float) $inv->subsidy_amount > 0.004) $lines[] = $mk('Subsidy', 1, -(float) $inv->subsidy_amount, $s->subsidy_ledger_account_id ?: $s->ledger_account_id, $s->tax_rate_exempt ?: ($tax ? null : $rate));
        if ((float) $inv->discount_amount > 0.004) $lines[] = $mk('Discount', 1, -(float) $inv->discount_amount, $s->ledger_account_id, $rate);
        $issued = self::localDate($inv->issued_at ?: $inv->created_at, $tz);
        $period = ($inv->period_start && $inv->period_end) ? (Carbon::parse($inv->period_start)->format('M j') . '–' . Carbon::parse($inv->period_end)->format('M j, Y')) : '';
        return ['sales_invoice' => [
            'contact_id' => $contactId,
            'date' => $issued,
            'due_date' => self::localDate($inv->due_at, $tz) ?: $issued,
            'reference' => mb_substr((string) ($inv->invoice_number ?: ('KT-' . $inv->id)), 0, 25),
            'notes' => trim('KiddieTrac invoice ' . ($inv->invoice_number ?: $inv->id) . ' ' . $period),
            'invoice_lines' => $lines,
        ]];
    }

    public static function invoice(int $agencyId, int $invoiceId, ?int $userId = null): string
    {
        $s = SageClient::row($agencyId);
        if (! SageClient::connected($s)) throw new AccountingSyncException('Sage is not connected for this agency.');
        $biz = (string) $s->business_id;
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        if (! $inv || XeroSync::agencyOfInvoice($invoiceId) !== $agencyId) throw new AccountingSyncException('That invoice is not in this agency.');
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $l = self::link($agencyId, $biz, 'invoice', $invoiceId);

        try {
            if ($inv->status === 'void') {
                if (! $l) return 'skipped';
                if (in_array($l->sage_status, ['VOID', 'DELETED'], true)) return 'unchanged';
                $cur = SageClient::call($agencyId, 'GET', 'sales_invoices/' . $l->sage_id);
                if ((float) ($cur['outstanding_amount'] ?? $cur['total_amount'] ?? 0) + 0.004 < (float) ($cur['total_amount'] ?? 0)) {
                    self::log($agencyId, 'invoice', $invoiceId, 'skipped', 'Voided in KiddieTrac, but Sage invoice ' . $l->sage_number . ' has payments allocated — remove them in Sage, then sync again.', $userId);
                    return 'skipped';
                }
                SageClient::call($agencyId, 'DELETE', 'sales_invoices/' . $l->sage_id, ['void_reason' => 'Voided in KiddieTrac']);
                self::saveLink($agencyId, $biz, 'invoice', $invoiceId, $l->sage_id, $l->sage_number, $l->fingerprint, 'VOID');
                self::log($agencyId, 'invoice', $invoiceId, 'voided', 'Invoice ' . $l->sage_number . ' voided in Sage.', $userId);
                return 'voided';
            }
            if (! in_array($inv->status, self::ISSUED, true)) return 'skipped';
            if ($s->sync_from && self::localDate($inv->issued_at ?: $inv->created_at, $tz) < $s->sync_from) return 'skipped';

            $contactId = self::contact($agencyId, $s, (int) $inv->family_id, $userId);
            $body = self::invoiceBody($inv, $s, $contactId, $tz);
            $fp = hash('sha256', json_encode($body));
            $action = 'unchanged';

            if ($l && $l->fingerprint === $fp) {
            } elseif ($l) {
                if (in_array($l->sage_status, ['PAID', 'VOID', 'DELETED'], true)) {
                    self::log($agencyId, 'invoice', $invoiceId, 'skipped', 'Changed in KiddieTrac, but invoice ' . $l->sage_number . ' is ' . strtolower((string) $l->sage_status) . ' in Sage, so it was left as it is.', $userId);
                } else {
                    $x = SageClient::call($agencyId, 'PUT', 'sales_invoices/' . $l->sage_id, $body);
                    self::saveLink($agencyId, $biz, 'invoice', $invoiceId, $l->sage_id, $x['invoice_number'] ?? $l->sage_number, $fp, self::status($x) ?? $l->sage_status);
                    self::log($agencyId, 'invoice', $invoiceId, 'updated', 'Invoice ' . ($x['invoice_number'] ?? $l->sage_number) . ' updated in Sage (total ' . ($x['total_amount'] ?? '?') . ').', $userId);
                    $action = 'updated';
                }
            } else {
                $ref = $body['sales_invoice']['reference'];
                foreach (SageClient::call($agencyId, 'GET', 'sales_invoices', null, ['search' => $ref, 'contact_id' => $contactId, 'attributes' => 'reference,status'])['$items'] ?? [] as $cand) {
                    if ((string) ($cand['reference'] ?? '') === $ref && ! in_array(self::status($cand), ['VOID', 'DELETED'], true)) {
                        self::saveLink($agencyId, $biz, 'invoice', $invoiceId, (string) $cand['id'], $cand['displayed_as'] ?? null, null, self::status($cand));
                        return self::invoice($agencyId, $invoiceId, $userId);
                    }
                }
                $x = SageClient::call($agencyId, 'POST', 'sales_invoices', $body, [], ['Idempotency-Key' => 'kt-' . $agencyId . '-inv-' . $invoiceId . '-' . substr($fp, 0, 16)]);
                if (empty($x['id'])) throw new AccountingSyncException('Sage did not return the new invoice.');
                $num = $x['invoice_number'] ?? $x['displayed_as'] ?? $ref;
                self::saveLink($agencyId, $biz, 'invoice', $invoiceId, (string) $x['id'], $num, $fp, self::status($x) ?? 'UNPAID');
                $note = isset($x['total_amount']) && abs((float) $x['total_amount'] - (float) $inv->total) > 0.01
                    ? ' Note: Sage total ' . $x['total_amount'] . ' differs from KiddieTrac total ' . number_format((float) $inv->total, 2) . ' (tax rounding or rate).' : '';
                self::log($agencyId, 'invoice', $invoiceId, 'created', 'Invoice ' . $num . ' (KiddieTrac ' . $ref . ') created in Sage.' . $note, $userId);
                $action = 'created';
            }
            if ($s->sync_payments) self::payments($agencyId, $invoiceId, $userId);
            return $action;
        } catch (AccountingSyncException $e) {
            self::log($agencyId, 'invoice', $invoiceId, 'failed', 'Invoice ' . ($inv->invoice_number ?: $invoiceId) . ': ' . $e->getMessage(), $userId);
            throw $e;
        }
    }

    public static function payments(int $agencyId, int $invoiceId, ?int $userId = null): void
    {
        $s = SageClient::row($agencyId);
        $biz = (string) $s->business_id;
        $il = self::link($agencyId, $biz, 'invoice', $invoiceId);
        if (! $il || in_array($il->sage_status, ['VOID', 'DELETED'], true)) return;
        $cust = self::link($agencyId, $biz, 'contact', (int) DB::table('invoices')->where('id', $invoiceId)->value('family_id'));
        if (! $cust) return;
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        foreach (DB::table('payments')->where('invoice_id', $invoiceId)->where('status', 'succeeded')->orderBy('id')->get() as $p) {
            $pl = self::link($agencyId, $biz, 'payment', (int) $p->id);
            try {
                if ($p->refunded_at) {
                    if ($pl && $pl->sage_status !== 'DELETED') {
                        SageClient::call($agencyId, 'DELETE', 'contact_payments/' . $pl->sage_id);
                        self::saveLink($agencyId, $biz, 'payment', (int) $p->id, $pl->sage_id, null, null, 'DELETED');
                        self::log($agencyId, 'payment', (int) $p->id, 'deleted', 'Refunded in KiddieTrac — receipt removed from invoice ' . $il->sage_number . ' in Sage.', $userId);
                    }
                    continue;
                }
                if ($pl) continue;
                if (empty($s->bank_account_id)) {
                    self::log($agencyId, 'payment', (int) $p->id, 'skipped', 'Payment not recorded: choose the Sage bank account for payments in Settings → Sage.', $userId);
                    continue;
                }
                $amt = round((float) $p->amount, 2);
                $x = SageClient::call($agencyId, 'POST', 'contact_payments', ['contact_payment' => [
                    'transaction_type_id' => 'CUSTOMER_RECEIPT',
                    'contact_id' => $cust->sage_id,
                    'bank_account_id' => $s->bank_account_id,
                    'date' => self::localDate($p->paid_at ?: $p->created_at, $tz),
                    'total_amount' => $amt,
                    'reference' => mb_substr('KT ' . str_replace('_', ' ', (string) $p->method) . ' ' . ($p->reference_number ?: ('#' . $p->id)), 0, 25),
                    'allocated_artefacts' => [['artefact_id' => $il->sage_id, 'amount' => $amt]],
                ]], [], ['Idempotency-Key' => 'kt-' . $agencyId . '-pay-' . $p->id]);
                if (empty($x['id'])) throw new AccountingSyncException('Sage did not return the receipt.');
                self::saveLink($agencyId, $biz, 'payment', (int) $p->id, (string) $x['id'], null, null, 'POSTED');
                self::log($agencyId, 'payment', (int) $p->id, 'created', 'Receipt of ' . number_format($amt, 2) . ' allocated to invoice ' . $il->sage_number . '.', $userId);
            } catch (AccountingSyncException $e) {
                self::log($agencyId, 'payment', (int) $p->id, 'failed', 'Payment ' . number_format((float) $p->amount, 2) . ' on invoice ' . $il->sage_number . ': ' . $e->getMessage(), $userId);
            }
        }
    }

    public static function pendingIds(int $agencyId, int $limit = 500): array
    {
        $s = SageClient::row($agencyId);
        if (! SageClient::connected($s)) return [];
        $biz = $s->business_id;
        $q = DB::table('invoices as i')->join('centres as c', 'c.id', '=', 'i.centre_id')
            ->leftJoin('sage_links as l', function ($j) use ($agencyId, $biz) {
                $j->on('l.local_id', '=', 'i.id')->where('l.kind', 'invoice')->where('l.agency_id', $agencyId)->where('l.business_id', $biz);
            })
            ->where('c.agency_id', $agencyId)
            ->where(function ($w) {
                $w->where(function ($a) { $a->whereIn('i.status', self::ISSUED)->where(function ($b) { $b->whereNull('l.id')->orWhereColumn('i.updated_at', '>', 'l.synced_at'); }); })
                  ->orWhere(function ($a) { $a->where('i.status', 'void')->whereNotNull('l.id')->whereNotIn('l.sage_status', ['VOID', 'DELETED']); });
            });
        if ($s->sync_from) $q->whereRaw('DATE(COALESCE(i.issued_at, i.created_at)) >= ?', [$s->sync_from]);
        $ids = $q->orderBy('i.id')->limit($limit)->pluck('i.id')->all();
        if ($s->sync_payments) {
            $ids = array_merge($ids, DB::table('payments as p')->join('sage_links as l', function ($j) use ($agencyId, $biz) {
                    $j->on('l.local_id', '=', 'p.invoice_id')->where('l.kind', 'invoice')->where('l.agency_id', $agencyId)->where('l.business_id', $biz);
                })
                ->leftJoin('sage_links as pl', function ($j) use ($agencyId, $biz) {
                    $j->on('pl.local_id', '=', 'p.id')->where('pl.kind', 'payment')->where('pl.agency_id', $agencyId)->where('pl.business_id', $biz);
                })
                ->where('p.status', 'succeeded')->whereNotIn('l.sage_status', ['VOID', 'DELETED'])
                ->where(function ($w) { $w->where(function ($a) { $a->whereNull('p.refunded_at')->whereNull('pl.id'); })->orWhere(function ($a) { $a->whereNotNull('p.refunded_at')->whereNotNull('pl.id')->where('pl.sage_status', '!=', 'DELETED'); }); })
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
                DB::table('sage_links')->where(['agency_id' => $agencyId, 'kind' => 'invoice', 'local_id' => $id])->update(['synced_at' => now()]);
            } catch (AccountingSyncException $e) {
                $out['failed']++;
                $out['error'] = $e->getMessage();
                $setup = in_array($e->getCode(), [401, 403, 429], true);
                foreach (['not connected', 'connect again', 'ledger account for tuition'] as $n) { if (stripos($e->getMessage(), $n) !== false) $setup = true; }
                if ($setup) { $out['remaining'] = count($ids) - $i - 1; break; }
            }
        }
        DB::table('agency_sage')->where('agency_id', $agencyId)->update(['last_sync_at' => now(), 'last_error' => $out['error'], 'updated_at' => now()]);
        return $out;
    }
}
