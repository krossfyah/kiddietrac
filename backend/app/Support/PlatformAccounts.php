<?php

namespace App\Support;

use App\Support\KtDompdf as Dompdf;
use Carbon\Carbon;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * KIDDIETRAC ACCOUNTS (2026-10-25, Anthony: "show the terms and conditions on payment that is due on
 * the due date (usually invoices should be sent out in advance) and new agencies should prepay their
 * invoices to ensure active services. Wire up the fact that they can prepay invoices, prepay for the
 * entire year, partial payment etc ... When an invoice is paid, you can send another email stating
 * invoice paid with a water mark and a receipt. when invoices are due and overdue send emails out
 * (wire this up) and make this configurable. The email template for invoices, use a different header
 * which is unique to accounting related emails").
 *
 *   - A payments ledger (platform_invoice_payments): every payment, full or part, with method,
 *     reference and a receipt number. The invoice's amount_paid is the sum of confirmed payments.
 *     Agency admins can report a payment (pending until KiddieTrac confirms it).
 *   - Prepay: pay any invoice early or in part; or ask for a 12-month prepayment invoice, with the
 *     prepayment discount (a setting) and every licence extended by the year.
 *   - Emails with their own ACCOUNTS header: new invoice, due soon, due today, overdue, payment
 *     received (receipt), paid in full (PAID invoice + receipt), prepayment invoice. Configurable:
 *     platform_settings 'billing.accounts' (JSON), defaults below.
 *   - A $0 invoice is never chased (it settles itself on its due date, App\Support\Licences::daily).
 */
final class PlatformAccounts
{
    public const METHODS = ['card' => 'Card', 'eft' => 'EFT / bank transfer', 'etransfer' => 'Interac e-Transfer', 'pad' => 'Pre-authorised debit',
        'cheque' => 'Cheque', 'credit' => 'Account credit', 'other' => 'Other'];

    public const DEFAULTS = [
        'reminders_enabled' => true,
        'before_days' => [7, 1],          // "due soon" emails this many days before the due date
        'on_due' => true,                 // "due today"
        'overdue_days' => [1, 7, 14, 30], // "overdue" emails this many days after
        'recipients' => 'contact_and_admins', // contact | admins | contact_and_admins
        'cc' => '',                       // extra addresses, comma-separated (e.g. your accounts mailbox)
        'paid_email' => true,             // paid-in-full email with the PAID invoice and receipt
        'payment_email' => true,          // receipt email for a part payment
        'prepay_discount_pct' => 10,      // 12-month prepayment discount (the sales sheets' 10%)
        // Late fees on overdue invoices (2026-10-25, Anthony's choice: % monthly after grace, a line on
        // the same invoice). Off until switched on in Sales -> Invoices -> Billing emails.
        'late_fee_enabled' => false,
        'late_fee_pct' => 2,              // % of the unpaid balance
        'late_fee_grace_days' => 14,      // days after the due date before the first fee
        'late_fee_repeat_days' => 30,     // then again every N days while unpaid
        'late_fee_max' => 3,              // at most this many fees per invoice
        'late_fee_min_cents' => 0,        // never charge less than this (0 = no minimum)
        /* NSF (2026-10-25): a payment that comes back (cheque returned, debit reversed) is marked
           returned in Sales -> Invoices; it stops counting, and this fee is added to the invoice. */
        'nsf_enabled' => true,
        'nsf_fee_cents' => 4500,
        /* SURCHARGES by how the agency pays (2026-10-25). Off until switched on. Each method: a % of the
           amount paid plus a flat amount. Added as a line on the invoice when the payment is confirmed,
           split out of the amount received. Card surcharges in Canada are capped at 2.4% (and not on
           debit); taxed at the invoice's rate when surcharge_taxable. */
        'surcharge_enabled' => false,
        'surcharges' => ['card' => ['pct' => 2.4, 'flat_cents' => 0]],
        'surcharge_taxable' => true,
        // BCC on emails to agencies: none | superadmins | sales | both; each agency can override (agencies.invoice_bcc).
        'bcc_default' => 'none',
        'prepay_due_days' => 14,
        'terms' => "Invoices are issued in advance of each service period and are payable in full on or before the due date shown.\nNew agencies prepay their first invoice before services are activated.\nAn invoice unpaid 14 days after its due date may pause your KiddieTrac services until the balance is settled; your records are always kept.\nYou can pay early, pay in part, or prepay a full year (with a prepayment discount) from the Licence page in your portal.",
        'payment_instructions' => "Card: Visa, Mastercard, American Express, Discover, Visa Debit and Debit Mastercard (call 1-855-400-9996).\nEFT / bank transfer, pre-authorised debit or Interac e-Transfer to sales@kiddietrac.com.\nPlease quote your invoice number with every payment.",
    ];

    public static function settings(): array
    {
        $raw = PlatformSettings::get('billing.accounts', '');
        $s = json_decode((string) $raw, true);
        $s = array_merge(self::DEFAULTS, is_array($s) ? $s : []);
        $s['surcharges'] = self::cleanSurcharges($s['surcharges'] ?? []);
        $extra = array_filter([
            $s['late_fee_enabled'] ? self::lateFeeSentence($s) : '',
            ($s['nsf_enabled'] && (int) $s['nsf_fee_cents'] > 0) ? 'A payment returned unpaid by your bank (NSF, a stopped cheque or a reversed debit) is charged a fee of ' . PlatformBilling::money((int) $s['nsf_fee_cents'], 'CAD') . '.' : '',
            self::surchargeSentence($s),
        ]);
        // The PDF's terms: everything.
        $s['terms_full'] = $s['terms'] . ($extra ? "\n" . implode("\n", $extra) : '');
        /* Emails and the portal leave out the new-agency prepayment line (Anthony, 2026-10-25: "why are you
           showing the new agencies prepay their first invoice clause in the email? - not required here"):
           whoever reads them is already a customer. It stays on the PDF terms. */
        $s['terms_email'] = implode("\n", array_filter(explode("\n", $s['terms_full']), fn ($l) => ! preg_match('/^\s*New agencies prepay/i', $l)));
        return $s;
    }

    /** Only methods we know, only sensible numbers. */
    public static function cleanSurcharges($in): array
    {
        $out = [];
        foreach ((array) $in as $k => $v) {
            if (! isset(self::METHODS[$k]) || $k === 'credit') continue;
            $pct = max(0, min(10, (float) ($v['pct'] ?? 0)));
            $flat = max(0, min(10000, (int) ($v['flat_cents'] ?? 0)));
            if ($pct > 0 || $flat > 0) $out[$k] = ['pct' => $pct, 'flat_cents' => $flat];
        }
        return $out;
    }

    public static function surchargeSentence(array $s): string
    {
        if (empty($s['surcharge_enabled']) || empty($s['surcharges'])) return '';
        $bits = [];
        foreach ($s['surcharges'] as $k => $v) {
            $p = rtrim(rtrim(number_format((float) $v['pct'], 2), '0'), '.');
            $bits[] = self::METHODS[$k] . ' ' . trim(($v['pct'] > 0 ? $p . '%' : '') . ($v['pct'] > 0 && $v['flat_cents'] > 0 ? ' + ' : '') . ($v['flat_cents'] > 0 ? PlatformBilling::money((int) $v['flat_cents'], 'CAD') : ''));
        }
        return 'Payments by ' . implode('; ', $bits) . ' surcharge, added to the invoice when the payment is received.';
    }

    /**
     * Split an amount received by a surcharged method into [applied to the balance, surcharge, tax on it].
     * Received = applied + surcharge + tax, exactly: any rounding cent goes to the applied part.
     */
    public static function splitReceived(int $received, string $method, int $bps, ?array $s = null): array
    {
        $s = $s ?: self::settings();
        $sc = ! empty($s['surcharge_enabled']) ? ($s['surcharges'][$method] ?? null) : null;
        if (! $sc || $received <= 0) return [$received, 0, 0];
        $p = (float) $sc['pct'] / 100; $f = (int) $sc['flat_cents'];
        $r = ! empty($s['surcharge_taxable']) ? $bps / 10000 : 0.0;
        $applied = (int) floor(($received - $f * (1 + $r)) / (1 + $p * (1 + $r)));
        if ($applied <= 0) return [$received, 0, 0];
        $sur = (int) round($applied * $p) + $f;
        $tax = (int) round($sur * $r);
        return [$received - $sur - $tax, $sur, $tax];
    }

    /** What to pay to clear $balance by $method, surcharge included. */
    public static function grossFor(int $balance, string $method, int $bps, ?array $s = null): int
    {
        $s = $s ?: self::settings();
        $sc = ! empty($s['surcharge_enabled']) ? ($s['surcharges'][$method] ?? null) : null;
        if (! $sc || $balance <= 0) return $balance;
        $sur = (int) round($balance * (float) $sc['pct'] / 100) + (int) $sc['flat_cents'];
        return $balance + $sur + (int) round($sur * (! empty($s['surcharge_taxable']) ? $bps / 10000 : 0));
    }

    /* ── lines added after an invoice is raised (late, NSF, surcharge, extra, manual) ── */

    /**
     * Add a line and keep the invoice's totals in step: subtotal + amount, tax at the invoice's own rate
     * when taxable. An invoice billed as one sum gets that sum written out as a line first.
     */
    public static function addLine(int $invoiceId, array $ln): int
    {
        $inv = DB::table('platform_invoices')->where('id', $invoiceId)->first();
        abort_unless($inv, 404, 'Invoice not found.');
        if (! DB::table('platform_invoice_lines')->where('invoice_id', $invoiceId)->exists() && (int) $inv->subtotal_cents !== 0) {
            DB::table('platform_invoice_lines')->insert(['invoice_id' => $invoiceId, 'description' => 'KiddieTrac subscription', 'qty' => 1,
                'unit_cents' => (int) $inv->subtotal_cents, 'amount_cents' => (int) $inv->subtotal_cents, 'tax_cents' => (int) $inv->tax_cents,
                'period_start' => $inv->period_start, 'period_end' => $inv->period_end, 'created_at' => now()]);
        }
        $qty = (float) ($ln['qty'] ?? 1);
        $amount = array_key_exists('amount_cents', $ln) ? (int) $ln['amount_cents'] : (int) round($qty * (int) $ln['unit_cents']);
        $tax = array_key_exists('tax_cents', $ln) ? (int) $ln['tax_cents'] : (! empty($ln['taxable']) ? PlatformBilling::taxCents($amount, (int) $inv->tax_rate_bps) : 0);
        $id = DB::table('platform_invoice_lines')->insertGetId([
            'invoice_id' => $invoiceId, 'licence_id' => null, 'payment_id' => $ln['payment_id'] ?? null, 'extra_id' => $ln['extra_id'] ?? null,
            'part_number' => $ln['part_number'] ?? null, 'kind' => $ln['kind'] ?? 'manual', 'description' => mb_substr((string) $ln['description'], 0, 255),
            'qty' => $qty, 'unit_cents' => (int) ($ln['unit_cents'] ?? $amount), 'amount_cents' => $amount, 'tax_cents' => $tax,
            'period_start' => $ln['period_start'] ?? AgencyTime::todayDate()->toDateString(), 'period_end' => $ln['period_end'] ?? AgencyTime::todayDate()->toDateString(), 'created_at' => now(),
        ]);
        DB::table('platform_invoices')->where('id', $invoiceId)->update(['subtotal_cents' => (int) $inv->subtotal_cents + $amount, 'tax_cents' => (int) $inv->tax_cents + $tax,
            'amount_cents' => (int) $inv->amount_cents + $amount + $tax, 'updated_at' => now()]);
        return $id;
    }

    /** Take a line back off (its amount and its tax). */
    public static function removeLine(int $lineId): ?object
    {
        $ln = DB::table('platform_invoice_lines')->find($lineId);
        if (! $ln) return null;
        $inv = DB::table('platform_invoices')->where('id', $ln->invoice_id)->first();
        DB::table('platform_invoice_lines')->where('id', $lineId)->delete();
        DB::table('platform_invoices')->where('id', $ln->invoice_id)->update(['subtotal_cents' => (int) $inv->subtotal_cents - (int) $ln->amount_cents,
            'tax_cents' => max(0, (int) $inv->tax_cents - (int) $ln->tax_cents), 'amount_cents' => max(0, (int) $inv->amount_cents - (int) $ln->amount_cents - (int) $ln->tax_cents), 'updated_at' => now()]);
        return $ln;
    }

    /* ── extra line items: per agency, on every invoice or just the next one ─────────── */

    /** Put an agency's live extras on a freshly raised invoice. Returns how many were added. */
    public static function applyExtras(int $invoiceId, int $agencyId): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('platform_invoice_extras')) return 0;
        $today = AgencyTime::todayDate()->toDateString();
        $inv = DB::table('platform_invoices')->where('id', $invoiceId)->first();
        $n = 0;
        foreach (DB::table('platform_invoice_extras')->where('agency_id', $agencyId)->where('active', 1)->orderBy('id')->get() as $x) {
            if ($x->starts_on && (string) $x->starts_on > $today) continue;
            if ($x->ends_on && (string) $x->ends_on < $today) { DB::table('platform_invoice_extras')->where('id', $x->id)->update(['active' => 0, 'updated_at' => now()]); continue; }
            if (! $x->recurring && (int) $x->applied_count > 0) continue;
            self::addLine($invoiceId, ['kind' => 'extra', 'extra_id' => (int) $x->id, 'part_number' => $x->part_number, 'description' => $x->description,
                'qty' => (float) $x->qty, 'unit_cents' => (int) $x->unit_cents, 'taxable' => (bool) $x->taxable,
                'period_start' => $inv->period_start, 'period_end' => $inv->period_end]);
            DB::table('platform_invoice_extras')->where('id', $x->id)->update(['applied_count' => (int) $x->applied_count + 1, 'last_invoice_id' => $invoiceId,
                'active' => $x->recurring ? 1 : 0, 'updated_at' => now()]);
            $n++;
        }
        return $n;
    }

    /** The extras waiting for an agency's next invoice, for the raise preview. */
    public static function pendingExtras(int $agencyId): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('platform_invoice_extras')) return [];
        $today = AgencyTime::todayDate()->toDateString();
        return DB::table('platform_invoice_extras')->where('agency_id', $agencyId)->where('active', 1)
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->where(fn ($q) => $q->where('recurring', 1)->orWhere('applied_count', 0))
            ->orderBy('id')->get(['id', 'description', 'qty', 'unit_cents', 'taxable', 'recurring'])->all();
    }

    public static function lateFeeSentence(array $s): string
    {
        $pct = rtrim(rtrim(number_format((float) $s['late_fee_pct'], 2), '0'), '.');
        return 'Overdue balances are charged a late fee of ' . $pct . '% of the unpaid amount once an invoice is ' . (int) $s['late_fee_grace_days']
            . ' days past due, and again every ' . (int) $s['late_fee_repeat_days'] . ' days it stays unpaid (up to ' . (int) $s['late_fee_max'] . ' times).';
    }

    public static function saveSettings(array $in): array
    {
        $cur = self::settings();
        $list = fn ($v) => array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : preg_split('/[\s,]+/', (string) $v)), fn ($n) => $n > 0 && $n <= 365)));
        foreach (['reminders_enabled', 'on_due', 'paid_email', 'payment_email'] as $k) if (array_key_exists($k, $in)) $cur[$k] = (bool) $in[$k];
        foreach (['before_days', 'overdue_days'] as $k) if (array_key_exists($k, $in)) { $l = $list($in[$k]); sort($l); $cur[$k] = $l; }
        if (isset($in['recipients']) && in_array($in['recipients'], ['contact', 'admins', 'contact_and_admins'], true)) $cur['recipients'] = $in['recipients'];
        if (isset($in['bcc_default']) && in_array($in['bcc_default'], ['none', 'superadmins', 'sales', 'both'], true)) $cur['bcc_default'] = $in['bcc_default'];
        if (array_key_exists('cc', $in)) $cur['cc'] = implode(', ', array_filter(array_map('trim', explode(',', (string) $in['cc'])), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        if (isset($in['prepay_discount_pct'])) $cur['prepay_discount_pct'] = max(0, min(50, (float) $in['prepay_discount_pct']));
        if (isset($in['prepay_due_days'])) $cur['prepay_due_days'] = max(1, min(90, (int) $in['prepay_due_days']));
        foreach (['terms', 'payment_instructions'] as $k) if (array_key_exists($k, $in)) $cur[$k] = trim((string) $in[$k]) ?: self::DEFAULTS[$k];
        if (array_key_exists('late_fee_enabled', $in)) $cur['late_fee_enabled'] = (bool) $in['late_fee_enabled'];
        if (isset($in['late_fee_pct'])) $cur['late_fee_pct'] = max(0, min(10, (float) $in['late_fee_pct']));
        if (isset($in['late_fee_grace_days'])) $cur['late_fee_grace_days'] = max(0, min(90, (int) $in['late_fee_grace_days']));
        if (isset($in['late_fee_repeat_days'])) $cur['late_fee_repeat_days'] = max(7, min(90, (int) $in['late_fee_repeat_days']));
        if (isset($in['late_fee_max'])) $cur['late_fee_max'] = max(1, min(12, (int) $in['late_fee_max']));
        if (isset($in['late_fee_min'])) $cur['late_fee_min_cents'] = max(0, (int) round((float) $in['late_fee_min'] * 100));
        if (array_key_exists('nsf_enabled', $in)) $cur['nsf_enabled'] = (bool) $in['nsf_enabled'];
        if (isset($in['nsf_fee'])) $cur['nsf_fee_cents'] = max(0, min(50000, (int) round((float) $in['nsf_fee'] * 100)));
        if (array_key_exists('surcharge_enabled', $in)) $cur['surcharge_enabled'] = (bool) $in['surcharge_enabled'];
        if (array_key_exists('surcharge_taxable', $in)) $cur['surcharge_taxable'] = (bool) $in['surcharge_taxable'];
        if (isset($in['surcharges']) && is_array($in['surcharges'])) {
            $sc = [];
            foreach ($in['surcharges'] as $k => $v) $sc[$k] = ['pct' => (float) ($v['pct'] ?? 0), 'flat_cents' => (int) round((float) ($v['flat'] ?? 0) * 100)];
            $cur['surcharges'] = self::cleanSurcharges($sc);
        }
        unset($cur['terms_full'], $cur['terms_email']);
        PlatformSettings::set('billing.accounts', json_encode($cur));
        return self::settings();
    }

    /** The invoice with its agency, by id or number. */
    public static function invoice($idOrNumber): ?object
    {
        $q = DB::table('platform_invoices as pi')->leftJoin('agencies as a', 'a.id', '=', 'pi.agency_id');
        is_numeric($idOrNumber) ? $q->where('pi.id', (int) $idOrNumber) : $q->where('pi.number', (string) $idOrNumber);
        return $q->first(['pi.*', 'a.name as agency_name', 'a.contact_email as agency_email']);
    }

    public static function balance(object $inv): int
    {
        return max(0, (int) $inv->amount_cents - (int) $inv->amount_paid_cents);
    }

    /** Who gets accounting email for an agency (never guessed: no address, no email). */
    public static function recipients(int $agencyId): array
    {
        $s = self::settings();
        $out = [];
        if ($s['recipients'] !== 'admins') {
            /* The addresses saved from the Email invoice popup take the place of the contact email (2026-10-25). */
            $ag = DB::table('agencies')->where('id', $agencyId)->first(['contact_email', 'invoice_emails']);
            $saved = array_filter(array_map('trim', explode(',', (string) ($ag->invoice_emails ?? ''))));
            foreach ($saved ?: [trim((string) ($ag->contact_email ?? ''))] as $c) {
                if (filter_var($c, FILTER_VALIDATE_EMAIL)) $out[] = strtolower($c);
            }
        }
        if ($s['recipients'] !== 'contact') {
            foreach (DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')->where('ra.agency_id', $agencyId)
                ->where('ra.role', 'agency_admin')->where('ra.active', 1)->whereNull('u.deleted_at')->pluck('u.email') as $e) {
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = strtolower($e);
            }
        }
        return array_values(array_unique($out));
    }

    /* ── the ledger ──────────────────────────────────────────────────────────────── */

    public static function payments(int $invoiceId): array
    {
        return DB::table('platform_invoice_payments')->where('invoice_id', $invoiceId)->orderBy('paid_on')->orderBy('id')->get()->all();
    }

    /** Re-add the invoice from its confirmed payments; returns the fresh invoice. */
    public static function recompute(int $invoiceId): ?object
    {
        $inv = DB::table('platform_invoices')->where('id', $invoiceId)->first();
        if (! $inv) return null;
        $paid = (int) DB::table('platform_invoice_payments')->where('invoice_id', $invoiceId)->where('status', 'confirmed')->sum('amount_cents')
            - (int) DB::table('platform_invoice_payments')->where('invoice_id', $invoiceId)->where('status', 'refund')->sum('amount_cents');
        $settled = $paid >= (int) $inv->amount_cents && $inv->status !== 'void';
        $last = DB::table('platform_invoice_payments')->where('invoice_id', $invoiceId)->where('status', 'confirmed')->orderByDesc('id')->first();
        $upd = ['amount_paid_cents' => $paid, 'updated_at' => now()];
        if ($inv->status !== 'void') {
            if ($settled && $paid > 0) { $upd['status'] = 'paid'; $upd['paid_at'] = $inv->paid_at ?: now(); }
            elseif (! $settled && $inv->status === 'paid') { $upd['status'] = 'issued'; $upd['paid_at'] = null; }
        }
        if ($last) $upd['payment_reference'] = $last->reference ?: (self::METHODS[$last->method] ?? $last->method);
        if ($inv->status === 'draft' && $paid > 0) { $upd['issued_at'] = $inv->issued_at ?: now(); if (empty($upd['status'])) $upd['status'] = 'issued'; }
        DB::table('platform_invoices')->where('id', $invoiceId)->update($upd);
        return DB::table('platform_invoices')->where('id', $invoiceId)->first();
    }

    /**
     * Record a payment. status 'confirmed' (KiddieTrac recorded it) or 'pending' (an agency admin
     * reported it; confirmed later). Returns ['payment' => row, 'invoice' => row, 'sent' => n].
     */
    public static function record(int $invoiceId, int $cents, string $method, ?string $reference, ?string $paidOn, ?int $byUserId, string $status = 'confirmed', bool $notify = true, ?string $note = null): array
    {
        $inv = DB::table('platform_invoices')->where('id', $invoiceId)->first();
        abort_unless($inv, 404, 'Invoice not found.');
        abort_if($inv->status === 'void', 422, 'A void invoice cannot be paid.');
        abort_if($cents <= 0, 422, 'Enter an amount above zero.');
        $owing = max(0, (int) $inv->amount_cents - (int) DB::table('platform_invoice_payments')->where('invoice_id', $invoiceId)->whereIn('status', ['confirmed', 'pending'])->sum('amount_cents')
            + (int) DB::table('platform_invoice_payments')->where('invoice_id', $invoiceId)->where('status', 'refund')->sum('amount_cents'));
        $gross = self::grossFor($owing, $method, (int) $inv->tax_rate_bps);
        abort_if($cents > $gross, 422, $owing ? 'That is more than the ' . PlatformBilling::money($gross, $inv->currency) . ' that clears this invoice' . ($gross > $owing ? ' (' . PlatformBilling::money($owing, $inv->currency) . ' plus the ' . (self::METHODS[$method] ?? $method) . ' surcharge)' : '') . '. To pay ahead, prepay the year instead.' : 'Nothing is owing on this invoice.');
        $id = DB::table('platform_invoice_payments')->insertGetId([
            'invoice_id' => $invoiceId, 'agency_id' => $inv->agency_id, 'amount_cents' => $cents, 'currency' => $inv->currency ?: 'CAD',
            'method' => isset(self::METHODS[$method]) ? $method : 'other', 'reference' => $reference ? mb_substr($reference, 0, 120) : null,
            'paid_on' => $paidOn ?: AgencyTime::todayDate()->toDateString(), 'status' => $status,
            'reported_by' => $status === 'pending' ? $byUserId : null, 'recorded_by' => $status === 'confirmed' ? $byUserId : null,
            'note' => $note ? mb_substr($note, 0, 500) : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $status === 'confirmed' ? self::confirm($id, $byUserId, $notify) : ['payment' => DB::table('platform_invoice_payments')->find($id), 'invoice' => $inv, 'sent' => 0];
    }

    /** Confirm a payment: receipt number, invoice re-added, receipt / paid email. */
    public static function confirm(int $paymentId, ?int $byUserId, bool $notify = true, ?string $testTo = null): array
    {
        $p = DB::table('platform_invoice_payments')->find($paymentId);
        abort_unless($p, 404, 'Payment not found.');
        if ($p->status !== 'confirmed' || ! $p->receipt_number) {
            DB::table('platform_invoice_payments')->where('id', $paymentId)->update(['status' => 'confirmed', 'recorded_by' => $p->recorded_by ?: $byUserId,
                'receipt_number' => $p->receipt_number ?: 'R-' . Carbon::parse($p->paid_on)->format('Ymd') . '-' . str_pad((string) $paymentId, 4, '0', STR_PAD_LEFT),
                'confirmed_at' => now(), 'updated_at' => now()]);
        }
        /* SURCHARGE (2026-10-25): part of what a surcharged method paid is the surcharge (and its tax).
           It becomes a line on the invoice, tied to this payment, so the balance drops by the rest. */
        if (! DB::table('platform_invoice_lines')->where('payment_id', $paymentId)->where('kind', 'surcharge')->exists()) {
            $invRow = DB::table('platform_invoices')->where('id', $p->invoice_id)->first();
            [$applied, $sur, $stax] = self::splitReceived((int) $p->amount_cents, (string) $p->method, (int) $invRow->tax_rate_bps);
            if ($sur > 0) {
                $sc = self::settings()['surcharges'][$p->method];
                $pct = rtrim(rtrim(number_format((float) $sc['pct'], 2), '0'), '.');
                self::addLine((int) $p->invoice_id, ['kind' => 'surcharge', 'part_number' => 'KT-SUR', 'payment_id' => $paymentId,
                    'description' => (self::METHODS[$p->method] ?? $p->method) . ' surcharge' . ($sc['pct'] > 0 ? ' ' . $pct . '%' : '') . ' on ' . PlatformBilling::money($applied, $invRow->currency) . ' paid ' . Carbon::parse($p->paid_on)->format('M j, Y'),
                    'qty' => 1, 'unit_cents' => $sur, 'amount_cents' => $sur, 'tax_cents' => $stax]);
            }
        }
        $inv = self::recompute((int) $p->invoice_id);
        $p = DB::table('platform_invoice_payments')->find($paymentId);
        $sent = 0;
        $s = self::settings();
        if ($notify && $inv) {
            $kind = $inv->status === 'paid' ? 'paid' : 'payment';
            if (($kind === 'paid' && $s['paid_email']) || ($kind === 'payment' && $s['payment_email'])) $sent = self::send(self::invoice((int) $inv->id), $kind, ['payment' => $p], $testTo);
        }
        return ['payment' => $p, 'invoice' => $inv, 'sent' => $sent];
    }

    /* ── the accounting emails ───────────────────────────────────────────────────── */

    /**
     * Send one accounting email for an invoice. Returns how many addresses it went to.
     * $testTo sends ONE copy there and to nobody else (subject marked [TEST]).
     */
    public static function send(?object $inv, string $kind, array $extra = [], ?string $testTo = null): int
    {
        if (! $inv || ! $inv->agency_id) return 0;
        $to = $testTo ? [$testTo] : self::recipients((int) $inv->agency_id);
        if (! $to) return 0;
        $s = self::settings();
        $cc = (! $testTo && $s['cc']) ? array_filter(array_map('trim', explode(',', $s['cc']))) : [];
        // BCC: superadmins and/or the account's salesperson, never on a test (2026-10-25).
        $bcc = $testTo ? [] : array_values(array_diff(PlatformBilling::invoiceBcc((int) $inv->agency_id), array_map('strtolower', array_merge($to, $cc))));
        [$subject, $html] = self::compose($inv, $kind, $extra);
        $att = [];
        try {
            if (in_array($kind, ['invoice', 'upcoming', 'due', 'overdue', 'prepay', 'paid', 'latefee', 'nsf', 'refund'], true)) {
                $pdf = app(\App\Services\PlatformInvoicePdf::class)->render((string) $inv->number);
                if ($pdf) $att[] = [$pdf, $inv->number . ($kind === 'paid' ? '-PAID' : '') . '.pdf'];
            }
            /* The paper-receipt slip goes on the PAID and NSF emails only (Anthony, 2026-10-25). A part
               payment's receipt is in the portal under Licence -> Invoices & payments. */
            if (in_array($kind, ['paid', 'nsf', 'refund'], true) && ! empty($extra['payment'])) {
                $r = self::receiptPdf((int) $extra['payment']->id);
                if ($r) $att[] = [$r, ($extra['payment']->receipt_number ?: 'receipt') . ($kind === 'nsf' ? '-RETURNED' : '') . '.pdf'];
            }
            Mail::html($html, function ($m) use ($to, $cc, $bcc, $subject, $att, $testTo) {
                MailScope::platform($m);
                $m->to($to)->from('noreply@kiddietrac.com', 'KiddieTrac Billing')->replyTo('sales@kiddietrac.com', 'KiddieTrac Billing')
                  ->subject(($testTo ? '[TEST] ' : '') . $subject);
                if ($cc) $m->cc($cc);
                if ($bcc) $m->bcc($bcc);
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                foreach ($att as [$bytes, $name]) $m->attachData($bytes, $name, ['mime' => 'application/pdf']);
            });
            return count($to);
        } catch (Throwable $e) {
            Log::warning('accounting email failed', ['invoice' => $inv->number, 'kind' => $kind, 'error' => $e->getMessage()]);
            return 0;
        }
    }

    /** [subject, html] for an accounting email. */
    public static function compose(object $inv, string $kind, array $extra = []): array
    {
        $cur = $inv->currency ?: 'CAD';
        $m = fn ($c) => PlatformBilling::money((int) $c, $cur);
        $d = fn ($x) => $x ? Carbon::parse($x)->format('M j, Y') : '—';
        $bal = self::balance($inv);
        $due = $inv->due_at ? Carbon::parse($inv->due_at) : null;
        $today = AgencyTime::todayDate();
        $days = $due ? (int) $today->copy()->startOfDay()->diffInDays($due->copy()->startOfDay(), false) : null;
        $p = $extra['payment'] ?? null;
        $s = self::settings();
        $who = e((string) $inv->agency_name);

        [$subject, $chip, $chipCol, $lead] = match ($kind) {
            'upcoming' => ['Invoice ' . $inv->number . ' is due ' . ($days === 1 ? 'tomorrow' : 'in ' . $days . ' days'), 'DUE SOON', '#D97706',
                'A friendly reminder that invoice <b>' . e($inv->number) . '</b> is due on <b>' . e($d($inv->due_at)) . '</b>.'],
            'due' => ['Invoice ' . $inv->number . ' is due today', 'DUE TODAY', '#D97706',
                'Invoice <b>' . e($inv->number) . '</b> is due <b>today</b>.'],
            'overdue' => ['Overdue: invoice ' . $inv->number . ' (' . abs((int) $days) . ' day' . (abs((int) $days) === 1 ? '' : 's') . ')', 'OVERDUE', '#DC2626',
                'Invoice <b>' . e($inv->number) . '</b> was due on ' . e($d($inv->due_at)) . ' and is still unpaid.'
                . ($due ? ' To keep your KiddieTrac services running, please pay by <b>' . e($d($due->copy()->addDays(Licences::GRACE_DAYS))) . '</b>, when the grace period ends.' : '')],
            'payment' => ['Payment received for invoice ' . $inv->number . ' — thank you', 'PAYMENT RECEIVED', '#16A34A',
                'Thank you — we have received a payment towards invoice <b>' . e($inv->number) . '</b>. '
                . ($bal ? 'The rest is due ' . e($d($inv->due_at)) . '.' : '') . ' Your receipt is attached.'],
            'paid' => ['Invoice ' . $inv->number . ' is paid in full — thank you', 'PAID IN FULL', '#16A34A',
                'Thank you — invoice <b>' . e($inv->number) . '</b> is <b>paid in full</b>. The paid invoice and your receipt are attached for your records.'],
            'latefee' => ['Late fee added to invoice ' . $inv->number, 'LATE FEE ADDED', '#DC2626',
                'Invoice <b>' . e($inv->number) . '</b> is still unpaid ' . abs((int) $days) . ' days after its due date, so a late fee has been added under our payment terms. The updated invoice is attached.'],
            'refund' => ['Refund issued for invoice ' . $inv->number, 'REFUND ISSUED', '#7C3AED',
                'We have issued a refund on invoice <b>' . e($inv->number) . '</b>'
                . (! empty($p->method) ? ' by ' . e(strtolower(self::METHODS[$p->method] ?? $p->method)) : '') . (! empty($p->note) ? ' (' . e($p->note) . ')' : '')
                . '. Please allow a few business days for it to reach you. Your refund receipt is attached.'],
            'nsf' => ['Payment returned: invoice ' . $inv->number, 'PAYMENT RETURNED', '#DC2626',
                'The payment made on ' . e($d($p->paid_on ?? null)) . ' towards invoice <b>' . e($inv->number) . '</b> was returned unpaid'
                . (! empty($p->return_reason) ? ' (' . e($p->return_reason) . ')' : '') . ', so it has been taken off your account'
                . (! empty($extra['fee_cents']) ? ' and a returned-payment fee has been added under our payment terms' : '')
                . '. The updated invoice is attached.'],
            'prepay' => ['Your 12-month prepayment invoice ' . $inv->number, 'PREPAYMENT', '#7C3AED',
                'As requested, here is your invoice to prepay KiddieTrac for the next 12 months'
                . ($inv->due_at ? ', due <b>' . e($d($inv->due_at)) . '</b>' : '') . '. Your services are extended for the year as soon as it is paid.'],
            default => ['Invoice ' . $inv->number . ' from KiddieTrac', 'NEW INVOICE', '#1DB9C3',
                'Your KiddieTrac invoice <b>' . e($inv->number) . '</b> for the period ' . e($d($inv->period_start)) . ' – ' . e($d($inv->period_end))
                . ' is attached' . ((int) $inv->amount_cents > 0 ? ', due <b>' . e($d($inv->due_at)) . '</b>' : '') . '.'],
        };
        if ((int) $inv->amount_cents === 0 && in_array($kind, ['invoice'], true)) $lead .= ' No payment is needed: it settles automatically on its due date.';

        $docTitle = ['invoice' => 'Invoice', 'latefee' => 'Late fee notice', 'nsf' => 'Returned payment', 'refund' => 'Refund', 'upcoming' => 'Payment reminder', 'due' => 'Payment due today', 'overdue' => 'Overdue notice',
            'payment' => 'Payment receipt', 'paid' => 'Paid in full', 'prepay' => 'Prepayment invoice'][$kind] ?? 'Invoice';
        // the document strip under the header: what this is about, at a glance
        $cells = [];
        // The customer number under the invoice number (2026-11-11, SalesCustomers::numbersForAgencies).
        $cusNo = SalesCustomers::numberForAgency(! empty($inv->agency_id) ? (int) $inv->agency_id : null);
        if ($cusNo) $cells[] = ['Customer #', e($cusNo)];
        if ($inv->period_start) $cells[] = ['Period', e($d($inv->period_start) . ($inv->period_end ? ' – ' . $d($inv->period_end) : ''))];
        $plan = self::planDetails((int) $inv->id);
        if ($plan) $cells[] = ['Service plan', $plan];
        if (in_array($kind, ['payment', 'paid', 'refund'], true) && $p) $cells[] = ['Receipt', e((string) $p->receipt_number)];
        else $cells[] = ['Due', e($d($inv->due_at))];
        $word = ['invoice' => 'Issued', 'upcoming' => 'Due soon', 'due' => 'Due today', 'overdue' => 'Overdue', 'payment' => 'Part paid', 'paid' => 'Paid', 'prepay' => 'Issued'][$kind] ?? 'Issued';

        $body0 = '<p style="margin:0 0 10px;">Hello ' . $who . ',</p><p style="margin:0;line-height:1.65;">' . $lead . '</p>';
        $body = '';
        if (in_array($kind, ['invoice', 'upcoming', 'due', 'overdue', 'prepay', 'latefee', 'nsf'], true) && ($bal > 0 || $kind === 'prepay')) {
            $body .= '<div style="--kt-edge:#1DB9C3;background:#F3F9FC;padding:12px 14px;margin:0 0 16px;font-size:13.5px;line-height:1.6;color:#334155;">'
                . '<b style="color:#0A1E55;">How to pay</b><br>' . nl2br(e($s['payment_instructions']))
                . (self::surchargeSentence($s) ? '<br><span style="color:#64748B;">' . e(self::surchargeSentence($s)) . '</span>' : '') . '</div>';
        }
        if (in_array($kind, ['invoice', 'prepay', 'upcoming'], true)) {
            $body .= '<p style="margin:0 0 16px;font-size:13.5px;color:#334155;line-height:1.6;">You can pay early, pay in part, or prepay a full year'
                . ($s['prepay_discount_pct'] > 0 ? ' and save ' . rtrim(rtrim(number_format((float) $s['prepay_discount_pct'], 1), '0'), '.') . '%' : '') . ' from the <b>Licence</b> page in your KiddieTrac portal.</p>';
        }
        $body .= '<div style="font-size:12px;color:#64748B;line-height:1.6;border-top:1px solid #E3EAF2;padding-top:12px;"><b style="color:#475569;">Payment terms.</b> ' . nl2br(e($s['terms_email'])) . '</div>';
        $body1 = $body;

        /* ONE FORMAT FOR EVERY BILLING EMAIL (2026-10-25, Anthony: "for the paid, NSF and refunds use the same email
           format as well"): the gradient hero. The paper-receipt look lives in the attached slip (paid, NSF, refund). */
        // The pill in the hero carries the date that matters, not an amount (the amounts are in the attached PDF).
        $heroAmount = match ($kind) {
            'payment' => 'Payment received',
            'paid' => 'Paid in full',
            'refund' => 'Refund issued',
            'overdue' => 'Overdue',
            default => ((int) $inv->amount_cents > 0 && $inv->due_at ? 'Due ' . $d($inv->due_at) : 'No payment needed'),
        };
        $heroSub = match ($kind) {
            'paid', 'payment' => 'thank you',
            'refund' => $p ? 'by ' . strtolower(self::METHODS[$p->method] ?? $p->method) : '',
            'overdue' => 'since ' . $d($inv->due_at),
            default => 'details in the attached PDF',
        };
        return [$subject, self::heroHtml($subject, $lead, $chip, $chipCol, $docTitle, (string) $inv->number, $heroAmount, $heroSub, $body0, $cells, $body1, $who, $kind)];

    }

    /** What the invoice is for, one line per service, without prices: "Agency · Monthly", plus "Complimentary". */
    private static function planDetails(int $invoiceId): string
    {
        $out = []; $comp = false;
        foreach (DB::table('platform_invoice_lines')->where('invoice_id', $invoiceId)->orderBy('id')->get(['part_number', 'kind', 'description']) as $ln) {
            if ((string) $ln->kind === 'comp' || (string) $ln->part_number === Licences::COMP_PART) { $comp = true; continue; }
            $t = trim(str_replace(' (list price)', '', (string) $ln->description));
            if ($t !== '') $out[] = e($t);
        }
        if ($comp) $out[] = 'Complimentary';
        return implode('<br>', array_slice($out, 0, 12));
    }

    /**
     * THE GRADIENT HERO (2026-10-25, Anthony picked it for every billing email except paid and NSF):
     * a teal -> blue -> purple hero (a hosted image, so every mail app shows it; solid blue if images are
     * off) with the logo on a white chip, the document and its number, the amount in a glowing pill,
     * the gradient line under it, then the letter, the details, one button, how to pay and the terms.
     */
    private static function heroHtml(string $subject, string $lead, string $chip, string $chipCol, string $docTitle, string $number, string $amount,
                                     string $amountSub, string $body0, array $cells, string $body1, string $who, string $kind): string
    {
        $F = "font-family:'Segoe UI',Helvetica,Arial,sans-serif;";
        $rows = '';
        foreach ($cells as [$k, $v]) {
            $rows .= '<tr><td style="' . $F . 'padding:9px 0;border-bottom:1px solid #EEF2F7;font-size:13px;color:#64748B;">' . e($k) . '</td>'
                . '<td style="' . $F . 'padding:9px 0;border-bottom:1px solid #EEF2F7;font-size:14px;color:#0F172A;font-weight:600;text-align:right;">' . $v . '</td></tr>';
        }
        $cta = in_array($kind, ['payment', 'paid', 'refund'], true) ? 'View receipts &amp; balance' : ($kind === 'invoice' || $kind === 'prepay' ? 'View invoice &amp; pay' : 'Pay or view invoice');
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light">'
            . '<title>' . e($subject) . '</title><style>@media (max-width:600px){.kh-pad{padding-left:22px!important;padding-right:22px!important}.kh-title{font-size:23px!important}.kh-amt{font-size:22px!important}}</style></head>'
            . '<body style="margin:0;padding:0;background:#EEF2F7;">'
            . '<div style="display:none;max-height:0;overflow:hidden;">' . e(strip_tags($lead)) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF2F7;"><tr><td align="center" style="padding:26px 12px;">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#FFFFFF;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px -18px rgba(15,23,42,.45);">'
            // hero
            . '<tr><td class="kh-pad" bgcolor="#2563EB" background="https://api.kiddietrac.com/email/billing-hero.png" style="background:#2563EB url(https://api.kiddietrac.com/email/billing-hero.png) center/cover no-repeat;padding:24px 34px 28px;' . $F . '">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            // The logo in white, straight on the gradient - no white chip (it stood out, 2026-10-25).
            . '<td valign="middle"><img src="https://api.kiddietrac.com/email/logo-white.png" width="136" alt="KiddieTrac" style="display:block;width:136px;height:auto;border:0;">'
            . '<div style="' . $F . 'font-size:11px;letter-spacing:.4px;color:#FFFFFF;opacity:.88;margin-top:4px;">Smart Childcare Management Platform</div></td>'
            . '<td valign="middle" align="right" style="' . $F . 'font-size:11px;font-weight:700;letter-spacing:2.5px;color:#FFFFFF;opacity:.9;">BILLING</td></tr></table>'
            . '<div style="margin-top:22px;"><span style="display:inline-block;' . $F . 'font-size:11px;font-weight:800;letter-spacing:1.4px;color:#FFFFFF;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.5);padding:4px 10px;border-radius:999px;"><span style="color:' . $chipCol . ';">&#9679;</span>&nbsp;' . e($chip) . '</span></div>'
            . '<div class="kh-title" style="' . $F . 'font-size:27px;line-height:1.2;font-weight:800;color:#FFFFFF;margin-top:10px;">' . e($docTitle) . ' <span style="font-weight:600;opacity:.9;">' . e($number) . '</span></div>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:16px;"><tr>'
            // Frosted glass rather than a white box, so the amount sits in the hero instead of on top of it.
            . '<td style="background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.45);border-radius:14px;padding:9px 18px;"><span class="kh-amt" style="' . $F . 'font-size:25px;font-weight:800;color:#FFFFFF;">' . e($amount) . '</span></td>'
            . '<td style="' . $F . 'padding-left:14px;font-size:14px;color:#FFFFFF;font-weight:600;">' . e($amountSub) . '</td></tr></table>'
            . '</td></tr>'
            . '<tr><td style="font-size:0;line-height:0;"><img src="https://api.kiddietrac.com/email/billing-line.png" width="600" height="5" alt="" style="display:block;width:100%;height:5px;border:0;"></td></tr>'
            // letter
            . '<tr><td class="kh-pad" style="padding:26px 34px 6px;' . $F . 'font-size:15px;line-height:1.65;color:#1E293B;">' . $body0 . '</td></tr>'
            . '<tr><td class="kh-pad" style="padding:10px 34px 4px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr><td style="' . $F . 'padding:9px 0;border-bottom:1px solid #EEF2F7;font-size:13px;color:#64748B;">Invoice</td><td style="' . $F . 'padding:9px 0;border-bottom:1px solid #EEF2F7;font-size:14px;color:#0F172A;font-weight:600;text-align:right;">' . e($number) . '</td></tr>'
            . $rows . '</table></td></tr>'
            . '<tr><td align="center" style="padding:22px 34px 8px;"><table role="presentation" cellpadding="0" cellspacing="0"><tr>'
            . '<td bgcolor="#2563EB" style="background:#2563EB;background-image:linear-gradient(135deg,#1DB9C3,#2563EB 55%,#7C3AED);border-radius:999px;">'
            . '<a href="https://app.kiddietrac.com/dashboard.html#licence" style="display:inline-block;padding:13px 30px;' . $F . 'font-size:14.5px;font-weight:800;color:#FFFFFF;text-decoration:none;">' . $cta . ' &rarr;</a></td></tr></table></td></tr>'
            . '<tr><td class="kh-pad" style="padding:18px 34px 8px;' . $F . '">' . $body1 . '</td></tr>'
            . '<tr><td class="kh-pad" style="padding:14px 34px 26px;' . $F . 'font-size:11.5px;line-height:1.6;color:#94A3B8;border-top:1px solid #EEF2F7;">'
            . '<b style="color:#475569;">' . e(Company::legalName()) . '</b> &middot; Billing &middot; ' . e(Company::addressLine()) . ' &middot; ' . e(Company::phone()) . ' &middot; <a href="mailto:sales@kiddietrac.com" style="color:#2563EB;text-decoration:none;">sales@kiddietrac.com</a><br>'
            . 'You are receiving this because you are a billing contact for ' . $who . '.<br>'
            . '<a href="https://www.kiddietrac.com/privacy/" style="color:#2563EB;text-decoration:none;">Privacy Policy</a> &middot; '
            . '<a href="https://www.kiddietrac.com/terms/" style="color:#2563EB;text-decoration:none;">Terms of Service</a></td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /* ── the receipt ─────────────────────────────────────────────────────────────── */

    /* THE PAPER RECEIPT (2026-10-25, Anthony: "make the receipt for invoices into looking like a real paper
       receipt"). A till slip on a grey page: torn edges, monospaced type, the shop header, every line,
       dotted totals, how it was paid, a barcode of the receipt number and THANK YOU. */
    public static function receiptPdf(int $paymentId): ?string
    {
        $p = DB::table('platform_invoice_payments')->find($paymentId);
        if (! $p || ! in_array($p->status, ['confirmed', 'returned', 'refund'], true)) return null;
        $refund = $p->status === 'refund';
        $inv = self::invoice((int) $p->invoice_id);
        if (! $inv) return null;
        // A RETURNED payment gets the same slip, marked returned, with the NSF fee (2026-10-25).
        $returned = $p->status === 'returned';
        $nsf = $returned ? (int) DB::table('platform_invoice_lines')->where('payment_id', $paymentId)->where('kind', 'nsf')->sum('amount_cents') : 0;
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m = fn ($c) => PlatformBilling::money((int) $c, $inv->currency ?: 'CAD');
        $logo = self::dataUri(base_path('public/email/logo-ink.png'));
        $top = self::dataUri(base_path('public/email/receipt-edge-top.png'));
        $bot = self::dataUri(base_path('public/email/receipt-edge-bottom.png'));
        $at = Carbon::parse($p->confirmed_at ?: $p->created_at, 'UTC')->tz(BookingMail::TZ);
        $bal = self::balance($inv);
        $mono = 'font-family:Courier,monospace;';
        $dash = '<div style="border-top:1.5px dashed #94A3B8;margin:9px 0;"></div>';
        $row = fn ($k, $v, $b = false) => '<tr><td style="padding:2px 0;' . ($b ? 'font-weight:bold;font-size:12px;' : '') . '">' . $e($k) . '</td><td style="padding:2px 0;text-align:right;' . ($b ? 'font-weight:bold;font-size:12px;' : '') . '">' . $e($v) . '</td></tr>';
        $items = '';
        foreach (DB::table('platform_invoice_lines')->where('invoice_id', $inv->id)->orderBy('id')->get() as $ln) {
            $q = rtrim(rtrim(number_format((float) $ln->qty, 2), '0'), '.');
            $items .= '<tr><td style="padding:3px 0 0;">' . $e(mb_strimwidth((string) $ln->description, 0, 34, '…')) . '</td><td style="padding:3px 0 0;text-align:right;">' . $e($m((int) $ln->amount_cents)) . '</td></tr>'
                . '<tr><td colspan="2" style="padding:0 0 3px;color:#64748B;font-size:9px;">' . $e($q . ' x ' . $m((int) $ln->unit_cents) . ($ln->part_number ? '   ' . $ln->part_number : '')) . '</td></tr>';
        }
        if ($items === '') $items = $row('KiddieTrac subscription', $m((int) ($inv->subtotal_cents ?: $inv->amount_cents)));
        // a barcode of the receipt number: bar widths from its characters (decorative, but it is the number)
        $bars = '';
        $code = (string) $p->receipt_number;
        for ($i = 0, $n = strlen($code); $i < $n; $i++) {
            $o = ord($code[$i]);
            foreach ([($o % 3) + 1, (($o >> 2) % 2) + 1, (($o >> 3) % 3) + 1, (($o >> 1) % 2) + 1] as $k => $w) {
                $bars .= '<td style="width:' . $w * 1.4 . 'px;height:38px;background:' . ($k % 2 ? '#FFFFFF' : '#111827') . ';"></td>';
            }
        }
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>@page{margin:0}body{margin:0;background:#E5E9EF;font-family:Courier,monospace;color:#1F2937;font-size:10.5px;}table{border-collapse:collapse}</style></head><body>'
            . '<div style="padding:46px 0;">'
            . '<table style="width:330px;margin:0 auto;"><tr><td>'
            . ($top ? '<img src="' . $top . '" style="display:block;width:330px;height:8px;">' : '')
            . '<div style="background:#FFFFFF;padding:16px 22px 18px;' . $mono . '">'
            . '<div style="text-align:center;">' . ($logo ? '<img src="' . $logo . '" style="width:130px;">' : '<b>KIDDIETRAC</b>') . '</div>'
            . '<div style="text-align:center;font-size:10px;line-height:1.5;margin-top:4px;">KIDDIETRAC BILLING<br>246372 HOCKLEY ROAD<br>MONO, ONTARIO L9W 6K4<br>1-855-400-9996 &middot; KIDDIETRAC.COM</div>'
            . $dash
            . '<div style="text-align:center;font-size:14px;font-weight:bold;letter-spacing:3px;">' . ($refund ? 'REFUND RECEIPT' : ($returned ? 'RETURNED PAYMENT' : 'PAYMENT RECEIPT')) . '</div>'
            . '<table style="width:100%;margin-top:6px;">' . $row('RECEIPT', $p->receipt_number) . $row('DATE', $at->format('M j, Y')) . $row('TIME', $at->format('g:i A T')) . $row('INVOICE', $inv->number) . '</table>'
            . $dash
            . '<div style="font-size:9.5px;color:#64748B;">RECEIVED FROM</div><div style="font-weight:bold;">' . $e(mb_strtoupper((string) $inv->agency_name)) . '</div>'
            . $dash
            . '<table style="width:100%;">' . $items . '</table>'
            . $dash
            . '<table style="width:100%;">'
            . $row('SUBTOTAL', $m((int) ($inv->subtotal_cents ?: $inv->amount_cents)))
            . ((int) $inv->tax_rate_bps > 0 ? $row(($inv->tax_label ?: 'TAX') . ' ' . PlatformBilling::ratePercent((int) $inv->tax_rate_bps), $m((int) $inv->tax_cents)) : '')
            . $row('INVOICE TOTAL', $m((int) $inv->amount_cents), true)
            . '</table>'
            . $dash
            . '<table style="width:100%;">'
            . $row($refund ? 'REFUNDED' : ($returned ? 'RETURNED' : 'PAID NOW'), ($returned || $refund ? '-' : '') . $m((int) $p->amount_cents), true)
            . ($returned && $p->return_reason ? $row('REASON', mb_strtoupper((string) $p->return_reason)) : '')
            . ($nsf ? $row('NSF FEE ADDED', $m($nsf)) : '')
            . $row('METHOD', mb_strtoupper(self::METHODS[$p->method] ?? $p->method))
            . ($p->reference ? $row('REF', $p->reference) : '')
            . $row('PAID TO DATE', $m((int) $inv->amount_paid_cents))
            . $row('BALANCE', $bal ? $m($bal) : '0.00', true)
            . '</table>'
            . '<div style="border-top:3px double #475569;margin:9px 0;"></div>'
            . ($refund ? '<div style="text-align:center;font-weight:bold;font-size:12px;letter-spacing:2px;margin:4px 0 8px;">*** REFUND ISSUED ***</div>' : '')
            . ($returned ? '<div style="text-align:center;font-weight:bold;font-size:12px;letter-spacing:2px;margin:4px 0 8px;color:#B91C1C;">*** RETURNED UNPAID ***</div>'
                : (($bal || $refund) ? '' : '<div style="text-align:center;font-weight:bold;font-size:12px;letter-spacing:2px;margin:4px 0 8px;">*** PAID IN FULL ***</div>'))
            . '<table style="margin:6px auto 0;"><tr>' . $bars . '</tr></table>'
            . '<div style="text-align:center;font-size:9.5px;letter-spacing:2px;margin-top:3px;">' . $e($p->receipt_number) . '</div>'
            . '<div style="text-align:center;font-size:13px;font-weight:bold;letter-spacing:3px;margin-top:12px;">THANK YOU</div>'
            . '<div style="text-align:center;font-size:9px;color:#64748B;margin-top:4px;line-height:1.5;">PLEASE KEEP THIS RECEIPT<br>FOR YOUR RECORDS<br>SALES@KIDDIETRAC.COM</div>'
            . '</div>'
            . ($bot ? '<img src="' . $bot . '" style="display:block;width:330px;height:8px;">' : '')
            . '</td></tr></table></div></body></html>';
        try {
            $o = new Options(); $o->set('isRemoteEnabled', false); $o->set('defaultFont', 'Courier');
            $pdf = new Dompdf($o); $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('letter', 'portrait'); $pdf->render();
            return $pdf->output();
        } catch (Throwable $e) {
            Log::warning('receipt pdf failed', ['payment' => $paymentId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public static function dataUri(string $path): ?string
    {
        return is_file($path) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($path)) : null;
    }

    /* ── late fees (run before the notices each day) ──────────────────────────────
       A line on the same invoice (part number KT-LATE): a % of the unpaid balance once the invoice is
       late_fee_grace_days past due, then every late_fee_repeat_days while unpaid, up to late_fee_max.
       Late fees are not taxed (they are a charge for late payment, not a supply). The agency gets the
       updated invoice by email. Waived from Sales -> Invoices. */
    public const LATE_PART = 'KT-LATE';

    public static function lateFees(bool $dry = false, ?string $redirect = null): array
    {
        $s = self::settings();
        if (! $s['late_fee_enabled'] || (float) $s['late_fee_pct'] <= 0) return ['late fees are switched off'];
        $today = AgencyTime::todayDate()->startOfDay();
        $out = [];
        foreach (DB::table('platform_invoices')->whereIn('status', ['issued', 'sent'])->whereNotNull('due_at')->whereNotNull('agency_id')->where('amount_cents', '>', 0)->get() as $r) {
            $bal = (int) $r->amount_cents - (int) $r->amount_paid_cents;
            if ($bal <= 0) continue;
            $late = (int) Carbon::parse($r->due_at)->startOfDay()->diffInDays($today, false);
            if ($late < (int) $s['late_fee_grace_days']) continue;
            $had = (int) DB::table('platform_invoice_lines')->where('invoice_id', $r->id)->where('part_number', self::LATE_PART)->count();
            if ($had >= (int) $s['late_fee_max']) continue;
            $nextAt = (int) $s['late_fee_grace_days'] + $had * (int) $s['late_fee_repeat_days'];
            if ($late < $nextAt) continue;
            $fee = max((int) $s['late_fee_min_cents'], (int) round($bal * (float) $s['late_fee_pct'] / 100));
            if ($fee <= 0) continue;
            $out[] = "{$r->number}: late fee " . PlatformBilling::money($fee, $r->currency) . ' (' . ($had + 1) . ' of ' . (int) $s['late_fee_max'] . ', ' . $late . ' days late)';
            if ($dry) continue;
            DB::transaction(function () use ($r, $fee, $bal, $s, $had, $late) {
                $pct = rtrim(rtrim(number_format((float) $s['late_fee_pct'], 2), '0'), '.');
                self::addLine((int) $r->id, ['kind' => 'late', 'part_number' => self::LATE_PART,
                    'description' => 'Late fee ' . ($had + 1) . ': ' . $pct . '% of ' . PlatformBilling::money($bal, $r->currency) . ' unpaid, ' . $late . ' days past due (not taxed)',
                    'qty' => 1, 'unit_cents' => $fee, 'amount_cents' => $fee, 'tax_cents' => 0]);
                Audit::write(['user_id' => null, 'agency_id' => $r->agency_id, 'action' => 'platform_invoice.late_fee', 'entity_type' => 'platform_invoice', 'entity_id' => $r->id,
                    'payload' => json_encode(['summary' => 'Late fee ' . PlatformBilling::money($fee, $r->currency) . ' added to ' . $r->number . ' (' . $late . ' days past due)']), 'created_at' => now()]);
            });
            self::send(self::invoice((int) $r->id), 'latefee', ['fee_cents' => $fee], $redirect);
        }
        return $out;
    }

    /** Remove every late fee from an invoice (Sales -> Invoices). Returns the amount taken off. */
    public static function waiveLateFees(int $invoiceId, ?int $byUserId): int
    {
        $inv = DB::table('platform_invoices')->where('id', $invoiceId)->first();
        abort_unless($inv, 404, 'Invoice not found.');
        $sum = (int) DB::table('platform_invoice_lines')->where('invoice_id', $invoiceId)->where('part_number', self::LATE_PART)->sum('amount_cents');
        if ($sum <= 0) return 0;
        DB::transaction(function () use ($inv, $sum, $invoiceId, $byUserId) {
            foreach (DB::table('platform_invoice_lines')->where('invoice_id', $invoiceId)->where('part_number', self::LATE_PART)->pluck('id') as $lid) self::removeLine((int) $lid);
            Audit::write(['user_id' => $byUserId, 'agency_id' => $inv->agency_id, 'action' => 'platform_invoice.late_fee_waived', 'entity_type' => 'platform_invoice', 'entity_id' => $invoiceId,
                'payload' => json_encode(['summary' => 'Waived ' . PlatformBilling::money($sum, $inv->currency) . ' of late fees on ' . $inv->number]), 'created_at' => now()]);
        });
        self::recompute($invoiceId);
        return $sum;
    }

    /* ── NSF: a payment that came back ────────────────────────────────────────────── */

    /**
     * Mark a confirmed payment returned (NSF, stopped cheque, reversed debit): it stops counting, any
     * surcharge it carried comes off, the NSF fee (a setting, untaxed) goes on as a line, the invoice
     * reopens, and the agency is told with the updated invoice.
     */
    public static function returnPayment(int $paymentId, ?int $byUserId, string $reason = '', bool $chargeFee = true, bool $notify = true, ?string $testTo = null): array
    {
        $p = DB::table('platform_invoice_payments')->find($paymentId);
        abort_unless($p, 404, 'Payment not found.');
        abort_unless($p->status === 'confirmed', 422, 'Only a confirmed payment can be marked returned.');
        $s = self::settings();
        $fee = ($chargeFee && $s['nsf_enabled']) ? (int) $s['nsf_fee_cents'] : 0;
        DB::transaction(function () use ($p, $paymentId, $reason, $fee, $byUserId) {
            DB::table('platform_invoice_payments')->where('id', $paymentId)->update(['status' => 'returned', 'returned_at' => now(),
                'return_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null, 'updated_at' => now()]);
            foreach (DB::table('platform_invoice_lines')->where('payment_id', $paymentId)->where('kind', 'surcharge')->pluck('id') as $lid) self::removeLine((int) $lid);
            if ($fee > 0) {
                $inv = DB::table('platform_invoices')->where('id', $p->invoice_id)->first();
                self::addLine((int) $p->invoice_id, ['kind' => 'nsf', 'part_number' => 'KT-NSF', 'payment_id' => $paymentId,
                    'description' => 'Returned payment fee (NSF): ' . PlatformBilling::money((int) $p->amount_cents, $inv->currency) . ' paid ' . Carbon::parse($p->paid_on)->format('M j, Y') . ($p->receipt_number ? ', receipt ' . $p->receipt_number : '') . ' (not taxed)',
                    'qty' => 1, 'unit_cents' => $fee, 'amount_cents' => $fee, 'tax_cents' => 0]);
            }
            Audit::write(['user_id' => $byUserId, 'agency_id' => $p->agency_id, 'action' => 'platform_invoice.payment_returned', 'entity_type' => 'platform_invoice_payment', 'entity_id' => $paymentId,
                'payload' => json_encode(['summary' => 'Payment ' . ($p->receipt_number ?: '#' . $paymentId) . ' marked returned' . ($reason !== '' ? ' (' . $reason . ')' : '') . ($fee ? '; NSF fee ' . PlatformBilling::money($fee, 'CAD') . ' added' : '')]), 'created_at' => now()]);
        });
        $inv = self::recompute((int) $p->invoice_id);
        $sent = $notify ? self::send(self::invoice((int) $p->invoice_id), 'nsf', ['payment' => DB::table('platform_invoice_payments')->find($paymentId), 'fee_cents' => $fee], $testTo) : 0;
        return ['invoice' => $inv, 'fee_cents' => $fee, 'sent' => $sent];
    }

    /* ── edit an issued invoice ─────────────────────────────────────────────────────── */

    /** Whether a line is taxed, for the editor: its own tax, or a priced licence/manual line on a taxed invoice. */
    public static function lineTaxable(object $ln, object $inv): bool
    {
        if ((int) $ln->tax_cents > 0) return true;
        if (in_array($ln->kind, ['comp', 'late', 'nsf', 'credit', 'surcharge'], true) || (int) $ln->amount_cents <= 0) return false;
        return (int) $inv->tax_rate_bps > 0 && in_array($ln->kind, [null, '', 'licence', 'manual', 'extra'], true);
    }

    /**
     * EDIT (2026-10-25): rewrite an unpaid invoice's lines, due date, tax and note. Lines kept by id keep their licence and
     * payment links; others are added; any not sent are removed. Each line's tax is worked out from its own Taxed tick.
     * Payments stay; the invoice is re-added against them.
     */
    public static function editInvoice(int $invoiceId, array $d, ?int $byUserId): object
    {
        $inv = DB::table('platform_invoices')->where('id', $invoiceId)->first();
        abort_unless($inv, 404, 'Invoice not found.');
        abort_if(in_array($inv->status, ['paid', 'void'], true), 422, $inv->status === 'paid' ? 'A paid invoice cannot be edited: refund it, or reissue after a refund.' : 'A void invoice cannot be edited.');
        $bps = array_key_exists('tax_rate_bps', $d) && $d['tax_rate_bps'] !== null ? (int) $d['tax_rate_bps'] : (int) $inv->tax_rate_bps;
        abort_if($bps > 0 && trim((string) ($d['tax_label'] ?? $inv->tax_label)) === '', 422, 'A tax rate needs a name (HST, GST).');
        $existing = DB::table('platform_invoice_lines')->where('invoice_id', $invoiceId)->get()->keyBy('id');
        $before = PlatformBilling::money((int) $inv->amount_cents, $inv->currency);
        // Never below what has been paid: refund the difference first (2026-10-25).
        $newSub = 0; $newTax = 0;
        foreach ($d['lines'] as $ln) { $amt = (int) round((float) ($ln['qty'] ?? 1) * (int) round(((float) $ln['unit']) * 100)); $newSub += $amt; if (! empty($ln['taxable']) && $amt > 0) $newTax += PlatformBilling::taxCents($amt, $bps); }
        abort_if($newSub + $newTax < (int) $inv->amount_paid_cents, 422, 'The new total (' . PlatformBilling::money($newSub + $newTax, $inv->currency) . ') is less than the '
            . PlatformBilling::money((int) $inv->amount_paid_cents, $inv->currency) . ' already paid. Refund the difference first, then edit.');
        DB::transaction(function () use ($inv, $invoiceId, $d, $bps, $existing, $byUserId, $before) {
            $keep = [];
            $sub = 0; $tax = 0;
            foreach ($d['lines'] as $ln) {
                $qty = (float) ($ln['qty'] ?? 1);
                $unit = (int) round(((float) $ln['unit']) * 100);
                $amt = (int) round($qty * $unit);
                $lt = ! empty($ln['taxable']) && $amt > 0 ? PlatformBilling::taxCents($amt, $bps) : 0;
                $row = ['description' => mb_substr((string) $ln['description'], 0, 255), 'qty' => $qty, 'unit_cents' => $unit, 'amount_cents' => $amt, 'tax_cents' => $lt,
                    'part_number' => $ln['part_number'] ?? null];
                $id = (int) ($ln['id'] ?? 0);
                if ($id && isset($existing[$id])) {
                    DB::table('platform_invoice_lines')->where('id', $id)->update($row);
                    $keep[] = $id;
                } else {
                    $keep[] = DB::table('platform_invoice_lines')->insertGetId($row + ['invoice_id' => $invoiceId, 'kind' => 'manual', 'period_start' => $inv->period_start, 'period_end' => $inv->period_end, 'created_at' => now()]);
                }
                $sub += $amt; $tax += $lt;
            }
            DB::table('platform_invoice_lines')->where('invoice_id', $invoiceId)->whereNotIn('id', $keep ?: [0])->delete();
            $upd = ['subtotal_cents' => $sub, 'tax_cents' => $tax, 'amount_cents' => $sub + $tax, 'tax_rate_bps' => $bps, 'updated_at' => now()];
            if (array_key_exists('tax_label', $d)) $upd['tax_label'] = $d['tax_label'] ?: null;
            if (! empty($d['due_at'])) $upd['due_at'] = \Carbon\Carbon::parse($d['due_at'])->toDateString();
            if (array_key_exists('notes', $d)) $upd['notes'] = $d['notes'];
            DB::table('platform_invoices')->where('id', $invoiceId)->update($upd);
            Audit::write(['user_id' => $byUserId, 'agency_id' => $inv->agency_id, 'action' => 'platform_invoice.edited', 'entity_type' => 'platform_invoice', 'entity_id' => $invoiceId,
                'payload' => json_encode(['summary' => 'Edited ' . $inv->number . ': ' . $before . ' to ' . PlatformBilling::money($sub + $tax, $inv->currency) . (! empty($d['reason']) ? ' (' . $d['reason'] . ')' : '') . ', ' . count($d['lines']) . ' line(s)']), 'created_at' => now()]);
        });
        self::recompute($invoiceId);
        return self::invoice($invoiceId);
    }

    /**
     * REFUND AN INVOICE (2026-10-25): full (everything paid, less refunds) or a partial amount, taken from the newest
     * confirmed payments first. Reason (shown to the agency) and notes (internal) go on each refund.
     */
    public static function refundInvoice(int $invoiceId, ?int $cents, string $reason, string $notes, bool $credit, bool $notify, ?int $byUserId): array
    {
        $inv = DB::table('platform_invoices')->where('id', $invoiceId)->first();
        abort_unless($inv, 404, 'Invoice not found.');
        $pays = DB::table('platform_invoice_payments')->where('invoice_id', $invoiceId)->where('status', 'confirmed')->orderByDesc('paid_on')->orderByDesc('id')->get();
        $left = [];
        foreach ($pays as $p) {
            $done = (int) DB::table('platform_invoice_payments')->where('invoice_id', $invoiceId)->where('status', 'refund')->where('note', 'like', '%[of #' . $p->id . ']%')->sum('amount_cents');
            if ((int) $p->amount_cents - $done > 0) $left[] = [$p, (int) $p->amount_cents - $done];
        }
        $max = array_sum(array_map(fn ($x) => $x[1], $left));
        abort_if($max <= 0, 422, 'Nothing has been paid on this invoice that can be refunded.');
        $want = $cents === null ? $max : $cents;
        abort_if($want <= 0 || $want > $max, 422, 'Refund between ' . PlatformBilling::money(1, $inv->currency) . ' and ' . PlatformBilling::money($max, $inv->currency) . '.');
        $why = trim($reason . ($notes !== '' ? ' — note: ' . $notes : ''));
        $out = []; $sent = 0;
        foreach ($left as [$p, $avail]) {
            if ($want <= 0) break;
            $take = min($avail, $want);
            $r = self::refund((int) $p->id, $take, $byUserId, $why, $credit, false);
            $out[] = $r['refund'];
            $want -= $take;
        }
        if ($notify && $out) $sent = self::send(self::invoice($invoiceId), 'refund', ['payment' => end($out)]);
        return ['refunds' => $out, 'invoice' => DB::table('platform_invoices')->find($invoiceId), 'sent' => $sent, 'max_cents' => $max];
    }

    /* ── reissue ────────────────────────────────────────────────────────────────────── */

    /** Open (not paid, not void) invoices that bill a licence, for "reissue it?" after the licence changes. */
    public static function openInvoicesFor(int $licenceId): array
    {
        return DB::table('platform_invoices as pi')->join('platform_invoice_lines as pl', 'pl.invoice_id', '=', 'pi.id')
            ->where('pl.licence_id', $licenceId)->whereNotIn('pi.status', ['paid', 'void'])
            ->select('pi.id', 'pi.number', 'pi.status', 'pi.amount_cents')->distinct()->get()->all();
    }

    /**
     * REISSUE (2026-10-25): void the invoice and raise a corrected copy for the same period from what is live now -
     * lines for cancelled licences drop off, the complimentary line is worked out again, one-off/extra/fee lines
     * carry over, and every payment moves across. Returns the new invoice. A paid invoice is refunded, not reissued.
     */
    public static function reissue(int $invoiceId, ?int $byUserId, string $reason = ''): object
    {
        $old = DB::table('platform_invoices')->where('id', $invoiceId)->first();
        abort_unless($old, 404, 'Invoice not found.');
        abort_if($old->status === 'void', 422, 'This invoice is already void.');
        abort_if($old->status === 'paid', 422, 'A paid invoice is not reissued: record a refund on it instead.');
        $base = preg_replace('/-R\d+$/', '', (string) $old->number);
        $n = 1;
        while (DB::table('platform_invoices')->where('number', $base . '-R' . $n)->exists()) $n++;
        $number = $base . '-R' . $n;

        $keep = []; $licenceAmt = 0; $extraTax = 0;
        foreach (DB::table('platform_invoice_lines')->where('invoice_id', $invoiceId)->orderBy('id')->get() as $ln) {
            if ($ln->kind === 'comp' || $ln->part_number === \App\Support\Licences::COMP_PART) continue;   // worked out again below
            if ($ln->licence_id) {
                $lic = DB::table('agency_licences')->find($ln->licence_id);
                if (! $lic || $lic->status === 'cancelled') continue;                                      // removed: drops off
                $keep[] = ['licence_id' => (int) $ln->licence_id, 'part_number' => $ln->part_number, 'kind' => $ln->kind ?: 'licence',
                    'description' => preg_replace('/ \(list price\)$/', '', (string) $ln->description), 'qty' => (float) $ln->qty,
                    'unit_cents' => (int) $ln->unit_cents, 'amount_cents' => (int) $ln->amount_cents, 'tax_cents' => 0, 'payment_id' => null,
                    'period_start' => $ln->period_start, 'period_end' => $ln->period_end];
                continue;
            }
            $keep[] = ['licence_id' => null, 'part_number' => $ln->part_number, 'kind' => $ln->kind, 'description' => $ln->description, 'qty' => (float) $ln->qty,
                'unit_cents' => (int) $ln->unit_cents, 'amount_cents' => (int) $ln->amount_cents, 'tax_cents' => (int) $ln->tax_cents, 'payment_id' => $ln->payment_id,
                'period_start' => $ln->period_start, 'period_end' => $ln->period_end];
        }
        // Complimentary lines at list price again (withComp resets the amounts of comp licences and adds KT-CMP-0001).
        $licLines = array_values(array_filter($keep, fn ($l) => $l['licence_id']));
        $other = array_values(array_filter($keep, fn ($l) => ! $l['licence_id']));
        $licLines = \App\Support\Licences::withComp($licLines, $old->currency ?: 'CAD');
        foreach ($licLines as $l) $licenceAmt += (int) $l['amount_cents'];
        foreach ($other as $l) $extraTax += (int) $l['tax_cents'];
        $lines = array_merge($licLines, $other);
        $sub = array_sum(array_map(fn ($l) => (int) $l['amount_cents'], $lines));
        $tax = PlatformBilling::taxCents(max(0, $licenceAmt), (int) $old->tax_rate_bps) + $extraTax;

        $newId = DB::transaction(function () use ($old, $number, $n, $lines, $sub, $tax, $byUserId, $reason, $invoiceId) {
            $newId = DB::table('platform_invoices')->insertGetId([
                'agency_id' => $old->agency_id, 'number' => $number, 'series' => 'reissue-' . $old->id . '-' . $n, 'period_start' => $old->period_start, 'period_end' => $old->period_end,
                'plan_code' => $old->plan_code, 'subtotal_cents' => $sub, 'tax_cents' => $tax, 'tax_rate_bps' => $old->tax_rate_bps, 'tax_label' => $old->tax_label,
                'amount_cents' => $sub + $tax, 'amount_paid_cents' => 0, 'currency' => $old->currency, 'status' => $old->status === 'draft' ? 'draft' : 'issued',
                'issued_at' => $old->status === 'draft' ? null : now(), 'due_at' => $old->due_at,
                'notes' => trim('Reissues ' . $old->number . ($reason !== '' ? ' (' . $reason . ')' : '') . '. ' . preg_replace('/^Reissues [^.]+\.\s*/', '', (string) $old->notes)),
                'created_by_id' => $byUserId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lines as $l) {
                DB::table('platform_invoice_lines')->insert(['invoice_id' => $newId, 'licence_id' => $l['licence_id'], 'payment_id' => $l['payment_id'] ?? null, 'part_number' => $l['part_number'],
                    'kind' => $l['kind'] ?? null, 'description' => $l['description'], 'qty' => $l['qty'], 'unit_cents' => $l['unit_cents'], 'amount_cents' => $l['amount_cents'],
                    'tax_cents' => (int) ($l['tax_cents'] ?? 0), 'period_start' => $l['period_start'], 'period_end' => $l['period_end'], 'created_at' => now()]);
            }
            DB::table('platform_invoice_payments')->where('invoice_id', $invoiceId)->update(['invoice_id' => $newId, 'updated_at' => now()]);
            DB::table('agency_licences')->where('last_invoice_id', $invoiceId)->update(['last_invoice_id' => $newId]);
            DB::table('platform_invoices')->where('id', $invoiceId)->update(['status' => 'void', 'amount_paid_cents' => 0, 'updated_at' => now(),
                'notes' => trim((string) $old->notes . ' [void: reissued as ' . $number . ' ' . AgencyTime::today() . ']')]);
            Audit::write(['user_id' => $byUserId, 'agency_id' => $old->agency_id, 'action' => 'platform_invoice.reissued', 'entity_type' => 'platform_invoice', 'entity_id' => $newId,
                'payload' => json_encode(['summary' => 'Reissued ' . $old->number . ' as ' . $number . ': ' . PlatformBilling::money($sub + $tax, $old->currency) . ($reason !== '' ? ' (' . $reason . ')' : '')]), 'created_at' => now()]);
            return $newId;
        });
        self::recompute($newId);
        return self::invoice($newId);
    }

    /* ── refunds ────────────────────────────────────────────────────────────────────── */

    /**
     * Refund all or part of a confirmed payment (2026-10-25). A refund row (receipt RF-...) comes off what was
     * paid; with $credit the invoice is credited by the same amount (a negative line), so a paid invoice stays
     * paid - without it, the refunded amount is owing again. The agency gets the refund email and slip.
     */
    public static function refund(int $paymentId, int $cents, ?int $byUserId, string $reason = '', bool $credit = true, bool $notify = true, ?string $testTo = null): array
    {
        $p = DB::table('platform_invoice_payments')->find($paymentId);
        abort_unless($p && $p->status === 'confirmed', 422, 'Only a confirmed payment can be refunded.');
        $already = (int) DB::table('platform_invoice_payments')->where('invoice_id', $p->invoice_id)->where('status', 'refund')->where('note', 'like', '%[of #' . $paymentId . ']%')->sum('amount_cents');
        abort_if($cents <= 0 || $cents > (int) $p->amount_cents - $already, 422, 'A refund must be above zero and no more than the ' . PlatformBilling::money((int) $p->amount_cents - $already, $p->currency) . ' left on that payment.');
        $id = DB::transaction(function () use ($p, $paymentId, $cents, $byUserId, $reason, $credit) {
            $id = DB::table('platform_invoice_payments')->insertGetId([
                'invoice_id' => $p->invoice_id, 'agency_id' => $p->agency_id, 'amount_cents' => $cents, 'currency' => $p->currency, 'method' => $p->method,
                'reference' => $p->reference, 'paid_on' => AgencyTime::todayDate()->toDateString(), 'status' => 'refund', 'recorded_by' => $byUserId,
                'note' => trim(($reason !== '' ? mb_substr($reason, 0, 400) . ' ' : '') . '[of #' . $paymentId . ']'), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('platform_invoice_payments')->where('id', $id)->update(['receipt_number' => 'RF-' . AgencyTime::todayDate()->format('Ymd') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT)]);
            if ($credit) {
                self::addLine((int) $p->invoice_id, ['kind' => 'credit', 'part_number' => 'KT-CRD', 'payment_id' => $id,
                    'description' => 'Refund credit' . ($reason !== '' ? ': ' . mb_substr($reason, 0, 120) : '') . ' (receipt ' . ($p->receipt_number ?: '#' . $paymentId) . ')',
                    'qty' => 1, 'unit_cents' => -$cents, 'amount_cents' => -$cents, 'tax_cents' => 0]);
            }
            Audit::write(['user_id' => $byUserId, 'agency_id' => $p->agency_id, 'action' => 'platform_invoice.refund', 'entity_type' => 'platform_invoice_payment', 'entity_id' => $id,
                'payload' => json_encode(['summary' => 'Refunded ' . PlatformBilling::money($cents, $p->currency) . ' of payment ' . ($p->receipt_number ?: '#' . $paymentId) . ($credit ? ', invoice credited' : '') . ($reason !== '' ? ' (' . $reason . ')' : '')]), 'created_at' => now()]);
            return $id;
        });
        $inv = self::recompute((int) $p->invoice_id);
        $row = DB::table('platform_invoice_payments')->find($id);
        $row->note = trim(preg_replace('/\s*\[of #\d+\]/', '', (string) $row->note));
        $sent = $notify ? self::send(self::invoice((int) $p->invoice_id), 'refund', ['payment' => $row], $testTo) : 0;
        return ['refund' => $row, 'invoice' => $inv, 'sent' => $sent];
    }

    /* ── due / overdue notices (platform:invoice-notices, daily) ─────────────────── */

    public static function notices(bool $dry = false, ?string $redirect = null): array
    {
        $s = self::settings();
        if (! $s['reminders_enabled']) return ['reminders are switched off'];
        $today = AgencyTime::todayDate()->startOfDay();
        $out = [];
        $rows = DB::table('platform_invoices')->whereIn('status', ['issued', 'sent'])->whereNotNull('due_at')->whereNotNull('agency_id')->where('amount_cents', '>', 0)->get();
        foreach ($rows as $r) {
            if ((int) $r->amount_cents - (int) $r->amount_paid_cents <= 0) continue;
            $days = (int) $today->diffInDays(Carbon::parse($r->due_at)->startOfDay(), false);   // >0 before, 0 today, <0 after
            $key = null; $kind = null;
            if ($days > 0 && in_array($days, $s['before_days'], true)) { $key = 'before:' . $days; $kind = 'upcoming'; }
            elseif ($days === 0 && $s['on_due']) { $key = 'due'; $kind = 'due'; }
            elseif ($days < 0 && in_array(-$days, $s['overdue_days'], true)) { $key = 'overdue:' . (-$days); $kind = 'overdue'; }
            if (! $key) continue;
            if (DB::table('platform_invoice_notices')->where('invoice_id', $r->id)->where('notice_key', $key)->exists()) continue;
            $out[] = "{$r->number}: {$kind} ({$key})";
            if ($dry) continue;
            $n = self::send(self::invoice((int) $r->id), $kind, [], $redirect);
            if ($n) DB::table('platform_invoice_notices')->insert(['invoice_id' => $r->id, 'notice_key' => $key, 'kind' => $kind, 'recipients' => $n, 'sent_at' => now()]);
        }
        return $out;
    }

    /* ── prepay the year ─────────────────────────────────────────────────────────── */

    /**
     * A 12-month prepayment invoice for every live licence, starting where each one's current
     * term ends, with the prepayment discount; each licence is extended by the year. Issued.
     */
    public static function prepayYear(int $agencyId, ?int $byUserId): array
    {
        $open = DB::table('platform_invoices')->where('agency_id', $agencyId)->where('number', 'like', '%-PY')->whereIn('status', ['draft', 'issued', 'sent'])->first();
        abort_if($open, 422, 'There is already a prepayment invoice waiting to be paid (' . ($open->number ?? '') . ').');
        $lic = DB::table('agency_licences')->where('agency_id', $agencyId)->whereIn('status', Licences::LIVE)->orderByRaw("FIELD(kind,'plan','addon')")->get();
        abort_if($lic->isEmpty(), 422, 'There is no live licence to prepay.');
        $a = DB::table('agencies')->where('id', $agencyId)->first();
        $s = self::settings();
        $per = ['monthly' => 12, 'quarterly' => 4, 'annual' => 1];
        $lines = []; $sub = 0; $start = null; $end = null;
        foreach ($lic as $l) {
            $from = $l->expires_at && (string) $l->expires_at > AgencyTime::todayDate()->toDateString() ? (string) $l->expires_at : AgencyTime::todayDate()->toDateString();
            $to = Carbon::parse($from)->addMonthsNoOverflow(12)->subDay()->toDateString();
            $n = $per[$l->interval] ?? 12;
            $amount = (int) round((float) $l->qty * (int) $l->unit_price_cents * $n);
            $lines[] = ['licence_id' => (int) $l->id, 'part_number' => $l->part_number, 'description' => $l->name . ' · 12 months prepaid', 'qty' => (float) $l->qty * $n,
                'unit_cents' => (int) $l->unit_price_cents, 'amount_cents' => $amount, 'period_start' => $from, 'period_end' => $to, 'next' => Carbon::parse($to)->addDay()->toDateString()];
            $sub += $amount;
            $start = $start === null || $from < $start ? $from : $start;
            $end = $end === null || $to > $end ? $to : $end;
        }
        // Complimentary lines at list price for the 12 months, zeroed by KT-CMP-0001 (2026-10-25).
        $lines = Licences::withComp($lines, $a->plan_currency ?: 'CAD', 12);
        $sub = array_sum(array_map(fn ($ln) => (int) $ln['amount_cents'], $lines));
        $pct = (float) $s['prepay_discount_pct'];
        $disc = $sub > 0 && $pct > 0 ? (int) round($sub * $pct / 100) : 0;
        if ($disc) $lines[] = ['licence_id' => null, 'part_number' => 'KT-DSC-0001', 'description' => 'Prepayment discount (' . rtrim(rtrim(number_format($pct, 1), '0'), '.') . '% for paying the year upfront)',
            'qty' => 1, 'unit_cents' => -$disc, 'amount_cents' => -$disc, 'period_start' => $start, 'period_end' => $end, 'next' => null];
        $net = $sub - $disc;
        $bps = (int) ($a->tax_rate_bps ?? 0);
        $tax = PlatformBilling::taxCents($net, $bps);
        $number = 'KT-' . str_replace('-', '', AgencyTime::todayDate()->toDateString()) . '-' . str_pad((string) $agencyId, 4, '0', STR_PAD_LEFT) . '-PY';
        $id = DB::transaction(function () use ($agencyId, $a, $number, $start, $end, $net, $tax, $bps, $lines, $byUserId, $s, $pct) {
            $id = DB::table('platform_invoices')->insertGetId([
                'agency_id' => $agencyId, 'number' => $number, 'period_start' => $start, 'period_end' => $end, 'plan_code' => $a->plan_code,
                'subtotal_cents' => $net, 'tax_cents' => $tax, 'tax_rate_bps' => $bps, 'tax_label' => $a->tax_label, 'amount_cents' => $net + $tax, 'amount_paid_cents' => 0,
                'currency' => $a->plan_currency ?: 'CAD', 'status' => 'issued', 'issued_at' => now(), 'due_at' => AgencyTime::todayDate()->addDays((int) $s['prepay_due_days'])->toDateString(),
                'notes' => 'Prepayment for 12 months of KiddieTrac' . ($pct > 0 ? ', with the ' . rtrim(rtrim(number_format($pct, 1), '0'), '.') . '% prepayment discount' : '') . '. Your licences are extended to the end of the prepaid year.',
                'created_by_id' => $byUserId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($lines as $ln) {
                DB::table('platform_invoice_lines')->insert(['invoice_id' => $id, 'licence_id' => $ln['licence_id'], 'part_number' => $ln['part_number'], 'kind' => $ln['kind'] ?? null, 'description' => $ln['description'],
                    'qty' => $ln['qty'], 'unit_cents' => $ln['unit_cents'], 'amount_cents' => $ln['amount_cents'], 'period_start' => $ln['period_start'], 'period_end' => $ln['period_end'], 'created_at' => now()]);
                if (! empty($ln['licence_id']) && ! empty($ln['next'])) DB::table('agency_licences')->where('id', $ln['licence_id'])->update(['expires_at' => $ln['next'], 'last_invoice_id' => $id, 'last_renewed_at' => now(), 'updated_at' => now()]);
            }
            return $id;
        });
        Licences::sync($agencyId);
        Audit::write(['user_id' => $byUserId, 'agency_id' => $agencyId, 'action' => 'platform_invoice.prepay', 'entity_type' => 'platform_invoice', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Prepayment invoice ' . $number . ' for 12 months: ' . PlatformBilling::money($net + $tax, $a->plan_currency ?: 'CAD')]), 'created_at' => now()]);
        return ['invoice' => self::invoice($id)];
    }
}
