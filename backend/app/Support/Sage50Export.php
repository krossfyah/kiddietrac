<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SAGE 50 (desktop) — import files (2026-10-09). Sage 50 has no online API; it imports
 * records from CSV and transactions from text files. Two files, both in layouts Sage
 * documents:
 *
 *  · CUSTOMERS — the Import Records wizard's Customers CSV (File → Import/Export → Import
 *    Records), columns in Sage's order, "Customer name" unique.
 *  · JOURNAL — "Import general journal entries" (File → Import/Export → Import Transactions):
 *        MM-DD-YY,"source","comment"      (source ≤ 13 chars, comment ≤ 39)
 *        account,amount                   (debit positive, credit negative, 2 decimals)
 *    Each entry balances, or Sage refuses the whole file.
 *
 * Sage 50 will not import journal lines on its LINKED Accounts Receivable account once
 * history is finished, so family balances go to a separate receivable account the agency
 * creates (e.g. 1210 "KiddieTrac family receivables"). Per issued invoice:
 *     Dr family receivable (total)   Dr subsidy (if mapped)   Dr discount (if mapped)
 *     Cr tuition revenue (subtotal, net of anything unmapped)   Cr tax payable (tax)
 * Per payment: Dr bank, Cr family receivable.
 * What was exported is remembered with its lines, so the next file carries only new items,
 * an adjusting entry for an invoice whose amounts changed, and a reversal for an exported
 * invoice that was voided or an exported payment that was refunded.
 */
final class Sage50Export
{
    public const ISSUED = ['sent', 'partial', 'paid', 'overdue'];

    private static function acct(?string $v): ?string
    {
        $v = trim((string) $v);
        return preg_match('/^\d{4,8}$/', $v) ? $v : null;
    }

    private static function clean(string $s, int $max): string
    {
        $s = preg_replace('/\s+/', ' ', str_replace(['"', "\r", "\n"], ["'", ' ', ' '], $s));
        return mb_substr(trim($s), 0, $max);
    }

    /** account => signed amount for an invoice, or [null, reason]. */
    public static function invoiceLines(object $inv, object $s): array
    {
        $recv = self::acct($s->s50_receivable); $rev = self::acct($s->s50_revenue);
        if (! $recv || ! $rev) return [null, 'Set the family receivable and tuition revenue account numbers first.'];
        $total = round((float) $inv->total, 2); $sub = round((float) $inv->subtotal, 2);
        $subsidy = round((float) $inv->subsidy_amount, 2); $disc = round((float) $inv->discount_amount, 2); $tax = round((float) $inv->tax_amount, 2);
        $lines = [];
        $add = function (string $a, float $amt) use (&$lines) { if (abs($amt) >= 0.005) $lines[$a] = round(($lines[$a] ?? 0) + $amt, 2); };
        $add($recv, $total);
        $revCredit = -$sub;
        if ($subsidy > 0) { if ($a = self::acct($s->s50_subsidy)) $add($a, $subsidy); else $revCredit += $subsidy; }
        if ($disc > 0) { if ($a = self::acct($s->s50_discount)) $add($a, $disc); else $revCredit += $disc; }
        if ($tax > 0) { if (! ($a = self::acct($s->s50_tax))) return [null, 'Invoice ' . $inv->invoice_number . ' charges tax — set the tax payable account number.']; $add($a, -$tax); }
        $add($rev, round($revCredit, 2));
        $sum = round(array_sum($lines), 2);
        if (abs($sum) >= 0.005) {
            // KiddieTrac's own total does not equal subtotal − subsidy − discount + tax: book the cents on revenue and say so.
            $add($rev, -$sum);
        }
        return [array_filter($lines, fn ($v) => abs($v) >= 0.005), null];
    }

    private static function entry(string $date, string $source, string $comment, array $lines): string
    {
        $out = Carbon::parse($date)->format('m-d-y') . ',"' . self::clean($source, 13) . '","' . self::clean($comment, 39) . "\"\r\n";
        foreach ($lines as $a => $amt) $out .= $a . ',' . number_format((float) $amt, 2, '.', '') . "\r\n";
        return $out;
    }

    /** Sage 50's source field holds 13 characters: a longer invoice number drops its dashes, then keeps its end. */
    private static function source(string $n): string
    {
        if (strlen($n) > 13) $n = str_replace(['-', ' ', '_'], '', $n);
        return substr($n, -13);
    }

    private static function negate(array $lines): array
    {
        return array_map(fn ($v) => round(-$v, 2), $lines);
    }

    private static function diff(array $new, array $old): array
    {
        $d = [];
        foreach (array_unique(array_merge(array_keys($new), array_keys($old))) as $a) {
            $v = round(($new[$a] ?? 0) - ($old[$a] ?? 0), 2);
            if (abs($v) >= 0.005) $d[$a] = $v;
        }
        return $d;
    }

    /**
     * Build the next journal file. $apply=false is a preview (nothing remembered).
     * Returns [content, summary, items-to-record].
     */
    public static function build(int $agencyId, ?string $from, ?string $to, bool $apply, ?int $userId = null): array
    {
        $s = DB::table('agency_sage')->where('agency_id', $agencyId)->first();
        if (! $s) return ['', ['entries' => 0, 'warnings' => ['Save your Sage 50 account numbers first.']], []];
        $tz = AgencyTime::tz($agencyId) ?: 'America/Toronto';
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $done = DB::table('sage50_exported')->where('agency_id', $agencyId)->get()->keyBy(fn ($r) => $r->kind . ':' . $r->local_id);
        $inRange = function (?string $d) use ($from, $to) { return $d && (! $from || $d >= $from) && (! $to || $d <= $to); };
        $content = ''; $sum = ['entries' => 0, 'invoices' => 0, 'adjustments' => 0, 'payments' => 0, 'reversals' => 0, 'debits' => 0.0, 'warnings' => []]; $record = [];
        $fam = fn ($id) => (string) DB::table('families')->where('id', $id)->value('family_name');
        $push = function (string $date, string $src, string $cmt, array $lines) use (&$content, &$sum) {
            if (! $lines) return;
            $content .= self::entry($date, $src, $cmt, $lines);
            $sum['entries']++;
            $sum['debits'] += array_sum(array_filter($lines, fn ($v) => $v > 0));
        };

        $invoices = DB::table('invoices')->whereIn('centre_id', $centreIds ?: [0])->whereIn('status', array_merge(self::ISSUED, ['void']))->orderBy('id')->get();
        foreach ($invoices as $inv) {
            $key = 'invoice:' . $inv->id;
            $prev = $done[$key] ?? null;
            $date = SageSync::localDate($inv->issued_at ?: $inv->created_at, $tz);
            $src = self::source((string) ($inv->invoice_number ?: ('KT' . $inv->id)));
            if ($inv->status === 'void') {
                if ($prev && ! $prev->reversed_export_id) {
                    $old = json_decode((string) $prev->lines_json, true) ?: [];
                    $push(AgencyTime::today($agencyId), $src, 'VOID ' . $inv->invoice_number . ' ' . $fam($inv->family_id), self::negate($old));
                    $sum['reversals']++;
                    $record[] = ['kind' => 'invoice', 'id' => $inv->id, 'reverse' => true];
                }
                continue;
            }
            [$lines, $why] = self::invoiceLines($inv, $s);
            if ($lines === null) { if ($why && ! in_array($why, $sum['warnings'], true)) $sum['warnings'][] = $why; continue; }
            if ($prev) {
                if ($prev->reversed_export_id) continue;
                $delta = self::diff($lines, json_decode((string) $prev->lines_json, true) ?: []);
                if ($delta) {
                    $push(AgencyTime::today($agencyId), $src, 'ADJ ' . $inv->invoice_number . ' ' . $fam($inv->family_id), $delta);
                    $sum['adjustments']++;
                    $record[] = ['kind' => 'invoice', 'id' => $inv->id, 'lines' => $lines];
                }
                continue;
            }
            if (! $inRange($date)) continue;
            $push($date, $src, 'Inv ' . $inv->invoice_number . ' ' . $fam($inv->family_id), $lines);
            $sum['invoices']++;
            $record[] = ['kind' => 'invoice', 'id' => $inv->id, 'lines' => $lines];
        }

        $recv = self::acct($s->s50_receivable); $bank = self::acct($s->s50_bank);
        $pays = DB::table('payments as p')->join('invoices as i', 'i.id', '=', 'p.invoice_id')->whereIn('i.centre_id', $centreIds ?: [0])
            ->where('p.status', 'succeeded')->select('p.*', 'i.invoice_number')->orderBy('p.id')->get();
        if ($pays->count() && (! $recv || ! $bank)) $sum['warnings'][] = 'Set the bank account number to include payments.';
        foreach ($pays as $p) {
            if (! $recv || ! $bank) break;
            $key = 'payment:' . $p->id;
            $prev = $done[$key] ?? null;
            $src = 'KTP' . $p->id;
            if ($p->refunded_at) {
                if ($prev && ! $prev->reversed_export_id) {
                    $push(SageSync::localDate($p->refunded_at, $tz), $src, 'REFUND ' . $p->invoice_number . ' ' . $fam($p->family_id), self::negate(json_decode((string) $prev->lines_json, true) ?: []));
                    $sum['reversals']++;
                    $record[] = ['kind' => 'payment', 'id' => $p->id, 'reverse' => true];
                }
                continue;
            }
            if ($prev) continue;
            $date = SageSync::localDate($p->paid_at ?: $p->created_at, $tz);
            if (! $inRange($date)) continue;
            $amt = round((float) $p->amount, 2);
            $lines = [$bank => $amt, $recv => -$amt];
            if ($bank === $recv) { $sum['warnings'][] = 'The bank and receivable accounts must differ.'; break; }
            $push($date, $src, 'Payment ' . $p->invoice_number . ' ' . $fam($p->family_id), $lines);
            $sum['payments']++;
            $record[] = ['kind' => 'payment', 'id' => $p->id, 'lines' => $lines];
        }
        $sum['debits'] = round($sum['debits'], 2);

        if ($apply && $sum['entries'] > 0) {
            DB::transaction(function () use ($agencyId, $userId, $from, $to, $content, $sum, $record, &$exportId) {
                $exportId = DB::table('sage50_exports')->insertGetId(['agency_id' => $agencyId, 'user_id' => $userId, 'date_from' => $from, 'date_to' => $to,
                    'entries' => $sum['entries'], 'total_debits' => $sum['debits'], 'content' => $content, 'created_at' => now()]);
                foreach ($record as $r) {
                    $w = ['agency_id' => $agencyId, 'kind' => $r['kind'], 'local_id' => $r['id']];
                    if (! empty($r['reverse'])) DB::table('sage50_exported')->where($w)->update(['reversed_export_id' => $exportId]);
                    else DB::table('sage50_exported')->updateOrInsert($w, ['export_id' => $exportId, 'lines_json' => json_encode($r['lines'])]);
                }
            });
            $sum['export_id'] = $exportId;
        }
        return [$content, $sum, $record];
    }

    /** The Import Records "Customers" CSV: current families of the agency, Sage's column order. */
    public static function customersCsv(int $agencyId): string
    {
        $cols = ['Customer name', 'Contact name', 'Street 1', 'Street 2', 'City', 'Province', 'Country', 'Postal / Zip code', 'Phone 1', 'Phone 2', 'Fax', 'Email address', 'Web site', 'Currency Code', 'Customer type'];
        $q = DB::table('families as f')->whereIn('f.centre_id', Live::centreIds($agencyId) ?: [0]);
        Live::families($q, 'f');
        $rows = $q->orderBy('f.family_name')->get(['f.*']);
        $ag = DB::table('agencies')->where('id', $agencyId)->first(['currency', 'country']);
        $currency = (string) ($ag->currency ?? '');
        $country = (string) (($ag->country ?? '') ?: 'Canada');
        $seen = [];
        $esc = fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"';
        $out = implode(',', array_map($esc, $cols)) . "\r\n";
        foreach ($rows as $f) {
            $name = trim((string) $f->family_name) ?: ('Family KT-' . $f->id);
            if (isset($seen[mb_strtolower($name)])) $name .= ' (KT-' . $f->id . ')';
            $seen[mb_strtolower($name)] = true;
            $g = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')->where('g.family_id', $f->id)->orderBy('g.id')->first(['u.*']);
            $email = trim((string) ($f->primary_email ?: ($g->email ?? '')));
            if (ChatAudience::isServiceEmail($email)) $email = '';
            $out .= implode(',', array_map($esc, [
                mb_substr($name, 0, 52), $g ? trim($g->first_name . ' ' . $g->last_name) : '', $f->address_line1, $f->address_line2, $f->city, $f->province, $country,
                $f->postal_code, $f->primary_phone ?: ($g->phone ?? ''), '', '', $email, '', $currency, '',
            ])) . "\r\n";
        }
        return $out;
    }
}
