<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EACH GUARDIAN'S OWN INVOICE (2026-10-30, Anthony: "issue split invoices to guardians based on the split invoice +
 * payment").
 *
 * A split family still has ONE family invoice (INV-1234). Each paying guardian gets a COPY that shows only their share:
 * numbered INV-1234-A, -B... (letters in the split's guardian order, so a copy keeps its letter), their portion of each
 * line, the subsidy/adjustments that fall to them, what they have paid, what they still owe, and how to pay - with the
 * family invoice number and total as the reference. Copies are made on the fly from the family invoice and its split
 * rules (BillingSplit), so they always agree with it; every email of a copy is logged in split_invoice_sends.
 *
 *   copies($invoice)                  every guardian's copy (data)
 *   copyFor($invoice, $guardianId)    one copy, or null
 *   html / pdf($invoiceId, $guardianId)
 *   send($invoiceId, $guardianId, ?$to, ?$byUserId, $cc = [])   email one guardian their copy (PDF attached) + bell
 *   sendAll($invoiceId, ?$byUserId)   every guardian with an email
 *   notifyIssued($invoiceId)          bell + push to each guardian with THEIR amount (no email)
 *   pdfForUser($inv, $userId)         the signed-in parent's own copy, or null when the invoice is not split for them
 */
final class SplitInvoiceCopy
{
    /** A, B, C... */
    public static function letter(int $i): string
    {
        $s = '';
        $i++;
        while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); }
        return $s;
    }

    public static function familyNumber(object $inv): string
    {
        return (string) ($inv->invoice_number ?: ('INV-' . $inv->id));
    }

    /** The copy number (INV-1234-B) for one guardian, or null when the invoice is not split for them. */
    public static function numberFor(object $inv, int $guardianId): ?string
    {
        $base = DB::table('invoices')->where('id', $inv->id)->value('invoice_number') ?: ('INV-' . $inv->id);   // never an already-suffixed number
        foreach (BillingSplit::shares($inv) ?? [] as $i => $s) if ($s['guardian_id'] === $guardianId) return $base . '-' . self::letter($i);
        return null;
    }

    /** Is this (native) invoice split? Cheap for the many invoices of families that never split. */
    public static function isSplitInvoice(int $invoiceId): bool
    {
        static $fams = null;
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        if (! $inv) return false;
        if (empty($inv->split_snapshot)) {
            if ($fams === null) {
                $fams = DB::table('family_billing_splits')->distinct()->pluck('family_id')->map(fn ($v) => (int) $v)->flip()->all()
                    + DB::table('families')->whereIn('billing_split', ['split_50_50', 'custom'])->pluck('id')->map(fn ($v) => (int) $v)->flip()->all();
            }
            if (! isset($fams[(int) $inv->family_id])) return false;
        }
        return BillingSplit::shares($inv) !== null;
    }

    /** @return array<int, array> keyed by nothing; one entry per guardian with a share */
    public static function copies(object $inv): array
    {
        $shares = BillingSplit::shares($inv);
        if (! $shares) return [];
        $cfg = BillingSplit::rulesFor($inv);
        $lines = DB::table('invoice_lines')->where('invoice_id', $inv->id)->orderBy('id')
            ->get(['id', 'description', 'amount', 'child_id', 'line_type', 'payer_guardian_id'])->map(fn ($r) => (array) $r)->all();
        $people = DB::table('guardians as g')->leftJoin('users as u', 'u.id', '=', 'g.user_id')->whereIn('g.id', array_column($shares, 'guardian_id'))
            ->get(['g.id', 'g.user_id', 'u.first_name', 'u.last_name', 'u.email', 'u.status', 'u.deleted_at'])->keyBy('id');
        $out = [];
        foreach ($shares as $i => $s) {
            $g = $people[$s['guardian_id']] ?? null;
            $portions = self::portions($lines, $cfg, $s, $shares);
            $out[] = $s + [
                'letter' => self::letter($i),
                'number' => self::familyNumber($inv) . '-' . self::letter($i),
                'family_number' => self::familyNumber($inv),
                'family_total' => round((float) $inv->total, 2),
                'user_id' => $g && $g->user_id ? (int) $g->user_id : null,
                'first_name' => $g->first_name ?? null,
                'email' => ($g && $g->email && ! $g->deleted_at) ? $g->email : null,
                'lines' => $portions['lines'],
                'adjustment' => $portions['adjustment'],
            ];
        }
        return $out;
    }

    public static function copyFor(object $inv, int $guardianId): ?array
    {
        foreach (self::copies($inv) as $c) if ($c['guardian_id'] === $guardianId) return $c;
        return null;
    }

    /**
     * This guardian's portion of each line under the rules, plus one "subsidy / adjustments" figure so the copy adds up
     * exactly to their share (invoice-level subsidy, discounts, tax and cents).
     */
    private static function portions(array $lines, ?array $cfg, array $share, array $all): array
    {
        $gid = $share['guardian_id'];
        $total = array_sum(array_column($all, 'share'));
        $out = [];
        foreach ($lines as $l) {
            $amt = round((float) $l['amount'], 2);
            $w = 0.0;
            $pg = (int) ($l['payer_guardian_id'] ?? 0);
            if ($pg) $w = $pg === $gid ? 1.0 : 0.0;
            elseif (! $cfg) $w = 0.0;
            else switch ($cfg['mode']) {
                case 'even': $w = in_array($gid, $cfg['guardians'], true) ? 1 / count($cfg['guardians']) : 0.0; break;
                case 'percent':
                    $sum = array_sum(array_column($cfg['shares'], 'pct')) ?: 100;
                    foreach ($cfg['shares'] as $sh) if ((int) $sh['guardian_id'] === $gid) $w = $sh['pct'] / $sum;
                    break;
                case 'by_type':
                    $who = in_array((string) $l['line_type'], BillingSplit::TUITION_TYPES, true) ? $cfg['tuition'] : $cfg['fees'];
                    if ($who === 'split') { $sum = array_sum(array_column($cfg['shares'] ?? [], 'pct')) ?: 100; foreach ($cfg['shares'] ?? [] as $sh) if ((int) $sh['guardian_id'] === $gid) $w = $sh['pct'] / $sum; }
                    else $w = (int) $who === $gid ? 1.0 : 0.0;
                    break;
                case 'by_child':
                    $w = (int) ($cfg['children'][(string) (int) ($l['child_id'] ?? 0)] ?? $cfg['default']) === $gid ? 1.0 : 0.0;
                    break;
                default:   // fixed: every line in proportion to the share
                    $w = $total > 0 ? $share['share'] / $total : 0.0;
            }
            $mine = round($amt * $w, 2);
            if (abs($mine) >= 0.005) $out[] = ['description' => (string) $l['description'], 'family_amount' => $amt, 'amount' => $mine];
        }
        $adj = round($share['share'] - array_sum(array_column($out, 'amount')), 2);
        return ['lines' => $out, 'adjustment' => abs($adj) >= 0.01 ? $adj : 0.0];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The document

    public static function html(int $invoiceId, int $guardianId): ?string
    {
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        if (! $inv) return null;
        $c = self::copyFor($inv, $guardianId);
        if (! $c) return null;
        $agencyId = (int) DB::table('centres')->where('id', $inv->centre_id)->value('agency_id');
        $agency = DB::table('agencies')->where('id', $agencyId)->first(['name', 'brand_logo_url', 'logo_url', 'brand_primary_color', 'brand_support_email', 'contact_email', 'contact_phone', 'settings']);
        $agSet = json_decode((string) ($agency->settings ?? ''), true) ?: [];
        $centre = DB::table('centres')->where('id', $inv->centre_id)->first(['name', 'phone', 'address_line1', 'city', 'province', 'postal_code']);
        $fam = DB::table('families')->where('id', $inv->family_id)->first(['family_name']);
        $kids = DB::table('children')->where('family_id', $inv->family_id)->whereNull('deleted_at')->orderBy('first_name')->pluck('first_name')->all();
        $pay = ParentBilling::payInfo($agencyId);
        $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($agency->brand_primary_color ?? '')) ? $agency->brand_primary_color : '#1F6080';
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m = fn ($v) => ((float) $v < 0 ? '-$' : '$') . number_format(abs((float) $v), 2);
        $phone = function ($p) { $dg = preg_replace('/\D/', '', (string) $p); if (strlen($dg) === 11 && $dg[0] === '1') $dg = substr($dg, 1);
            return strlen($dg) === 10 ? '(' . substr($dg, 0, 3) . ') ' . substr($dg, 3, 3) . '-' . substr($dg, 6) : (string) $p; };
        $d = fn ($v) => $v ? \Illuminate\Support\Carbon::parse(substr((string) $v, 0, 10))->format('F j, Y') : '—';
        $who = trim(($c['name'] ?? '') ?: 'Guardian');
        $rule = BillingSplit::describe(BillingSplit::rulesFor($inv), $guardianId);

        $rows = '';
        foreach ($c['lines'] as $l) {
            $rows .= '<tr><td>' . $e($l['description']) . '</td><td class="n muted">' . $m($l['family_amount']) . '</td><td class="n">' . $m($l['amount']) . '</td></tr>';
        }
        if ($c['adjustment']) $rows .= '<tr><td>Subsidy, discounts &amp; adjustments (your portion)</td><td class="n muted">—</td><td class="n">' . $m($c['adjustment']) . '</td></tr>';

        $howPay = '<li>In the app: <strong>Billing</strong> → this invoice → <strong>Pay</strong>. You pay your share only.</li>';
        if (! empty($pay['interac']['email'])) {
            $howPay .= '<li>Interac e-Transfer to <strong>' . $e($pay['interac']['email']) . '</strong> - message: <strong>' . $e($c['number']) . '</strong></li>';
        }
        $logoSrc = app(\App\Services\IlearnInvoiceRenderer::class)->logoData($agency->brand_logo_url ?? $agency->logo_url ?? null);
        $logo = $logoSrc ? '<img src="' . $e($logoSrc) . '" style="max-height:46px;max-width:200px;">' : '<div class="ag">' . $e($agency->name ?? 'Your childcare provider') . '</div>';
        $status = $c['balance'] <= 0.004 ? '<div class="paid">PAID</div>' : '';
        $pct = rtrim(rtrim(number_format($c['pct'], 2), '0'), '.');
        $payBlock = InvoicePayInfo::pdfHtml($agencyId, (string) $c['number'], (int) $inv->family_id, $guardianId, (float) $c['balance']);

        /* THE AGENCY'S OWN INVOICE TEMPLATE (2026-10-31, Anthony: "why are you adding the provider's address at the top,
           we should be using the iLearn template for the header and colour branding and formatting"). An agency on the
           iLearn template gets its guardian copies in that same page and header - agency logo, name, address, contact. */
        if (\App\Services\InvoiceDocument::templateFor($agencyId) === 'ilearn') {
            $body = '<table class="meta" style="width:100%;border-collapse:collapse"><tr>'
                . '<td style="vertical-align:top;width:58%;line-height:1.7"><span class="k">Bill to:</span> <strong>' . $e($who) . '</strong> · ' . $e($fam->family_name ?? '')
                . ($kids ? '<br><span class="k">For:</span> ' . $e(implode(', ', $kids)) : '')
                . '<br><span class="k">Date:</span> ' . $d($inv->issued_at)
                . ($inv->period_start ? '<br><span class="k">Period:</span> ' . $d($inv->period_start) . ' – ' . $d($inv->period_end) : '') . '</td>'
                . '<td style="vertical-align:top;line-height:1.7"><span class="k">Status:</span> ' . ($c['balance'] <= 0.004 ? 'Paid' : 'Your share due')
                . '<br><span class="k">Due:</span> ' . $d($inv->due_at) . '</td></tr></table>'
                . '<div style="margin:0 0 6px;padding:10px 14px;background:#faf9ff;border:1px solid #e3e3ee;border-radius:8px;font-size:.84rem;color:#334155;line-height:1.55">'
                . 'This is your share of family invoice <strong>' . $e($c['family_number']) . '</strong> (family total ' . $m($c['family_total']) . '). '
                . 'Your family splits the bill: ' . $e($rule) . ' Each payer receives their own copy.</div>'
                . '<table class="lines"><tr><th>Description</th><th class="num">Family amount</th><th class="num">Your portion</th></tr>'
                . str_replace(['<td class="n muted">', '<td class="n">'], ['<td class="num" style="color:#6b7280">', '<td class="num">'], $rows) . '</table>'
                . '<table class="totals"><tr><td>Your share (' . $pct . '%)</td><td>' . $m($c['share']) . '</td></tr>'
                . '<tr><td>Paid</td><td>' . $m($c['paid']) . '</td></tr>'
                . '<tr class="grand"><td>Balance due</td><td>' . $m($c['balance']) . '</td></tr></table>'
                . $payBlock
                . ($c['balance'] <= 0.004 ? '<div class="watermark paid">PAID IN FULL</div>' : '');

            return app(\App\Services\IlearnInvoiceRenderer::class)->wrapForAgency($agencyId, $body, 'Invoice', $c['number'] . ' · your share',
                'Invoice ' . $c['number'] . ' · Generated ' . \App\Support\AgencyTime::now()->format('M j, Y g:i A'), true);
        }

        return '<!doctype html><html><head><meta charset="utf-8"><title>Invoice ' . $e($c['number']) . '</title><style>'
            . 'body{font-family:DejaVu Sans,Arial,sans-serif;color:#0F172A;font-size:12px;margin:28px 34px;}'
            . '.top{display:table;width:100%;border-bottom:3px solid ' . $accent . ';padding-bottom:10px;margin-bottom:14px;}'
            . '.top>div{display:table-cell;vertical-align:top;}.r{text-align:right;}'
            . '.ag{font-size:18px;font-weight:bold;color:' . $accent . ';}'
            . 'h1{font-size:20px;margin:0;color:' . $accent . ';white-space:nowrap;}.muted{color:#64748B;}.small{font-size:10.5px;}'
            . '.box{border:1px solid #E2E8F0;border-radius:6px;padding:10px 12px;margin:0 0 12px;}'
            . '.ref{background:#F1F5F9;border-radius:6px;padding:8px 12px;margin:0 0 12px;}'
            . 'table.l{width:100%;border-collapse:collapse;margin:0 0 10px;}table.l th{text-align:left;font-size:10px;text-transform:uppercase;color:#64748B;border-bottom:1px solid #CBD5E1;padding:5px 6px;}'
            . 'table.l td{padding:6px;border-bottom:1px solid #F1F5F9;}.n{text-align:right;white-space:nowrap;}'
            . 'table.t{width:46%;margin-left:54%;border-collapse:collapse;}table.t td{padding:4px 6px;}table.t tr.b td{font-weight:bold;font-size:14px;border-top:2px solid ' . $accent . ';}'
            . '.paid{display:inline-block;border:2px solid #16A34A;color:#16A34A;font-weight:bold;padding:2px 10px;border-radius:4px;margin-top:6px;}'
            . 'ul{margin:4px 0 0 16px;padding:0;}'
            . '</style></head><body>'
            . '<div class="top"><div>' . $logo . '<div class="muted small">' . $e(trim(implode(' · ', array_filter([$agency->name ?? null, str_replace(["\r", "\n"], [' ', ', '], trim((string) ($agSet['brand_address'] ?? ''))) ?: null,
                ! empty($agency->contact_phone) ? $phone($agency->contact_phone) : null, ($agency->contact_email ?? '') ?: ($agency->brand_support_email ?? null)]))))
            . '</div></div>'
            . '<div class="r"><h1>Invoice ' . $e($c['number']) . '</h1><div class="muted">Your share</div>' . $status . '</div></div>'
            . '<div class="box"><strong>Bill to:</strong> ' . $e($who) . ' · ' . $e($fam->family_name ?? '') . ($kids ? ' (' . $e(implode(', ', $kids)) . ')' : '')
            . '<br><strong>Issued:</strong> ' . $d($inv->issued_at) . ' &nbsp; <strong>Due:</strong> ' . $d($inv->due_at)
            . ($inv->period_start ? ' &nbsp; <strong>Period:</strong> ' . $d($inv->period_start) . ' – ' . $d($inv->period_end) : '') . '</div>'
            . '<div class="ref small">This is your share of family invoice <strong>' . $e($c['family_number']) . '</strong> (family total ' . $m($c['family_total']) . '). '
            . 'Your family splits the bill: ' . $e($rule) . ' Each payer receives their own copy.</div>'
            . '<table class="l"><tr><th>Description</th><th class="n">Family amount</th><th class="n">Your portion</th></tr>' . $rows . '</table>'
            . '<table class="t"><tr><td>Your share (' . rtrim(rtrim(number_format($c['pct'], 2), '0'), '.') . '%)</td><td class="n">' . $m($c['share']) . '</td></tr>'
            . '<tr><td>Paid</td><td class="n">' . $m($c['paid']) . '</td></tr>'
            . '<tr class="b"><td>Balance due</td><td class="n">' . $m($c['balance']) . '</td></tr></table>'
            . $payBlock
            . '<div class="muted small" style="margin-top:18px;">Questions about this invoice? Contact ' . $e($agency->name ?? 'your centre') . (! empty($agency->brand_support_email) ? ' at ' . $e($agency->brand_support_email) : '') . '.</div>'
            . '</body></html>';
    }

    public static function pdf(int $invoiceId, int $guardianId): ?string
    {
        try {
            $html = self::html($invoiceId, $guardianId);
            if (! $html) return null;
            $dompdf = new KtDompdf(['isRemoteEnabled' => true]);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('letter', 'portrait');
            $dompdf->render();
            $out = $dompdf->output();
            return (is_string($out) && str_starts_with($out, '%PDF')) ? $out : null;
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    /** The signed-in parent's own copy of a NATIVE invoice, or null (not split, or not one of its payers). */
    public static function pdfForUser(?object $inv, int $userId): ?string
    {
        if (! $inv || ! property_exists($inv, 'invoice_number') || empty($inv->family_id)) return null;
        $gid = BillingSplit::guardianFor($userId, (int) $inv->family_id);
        if (! $gid) return null;
        $full = DB::table('invoices')->where('id', $inv->id)->first();
        if (! $full || ! self::copyFor($full, $gid)) return null;
        return self::pdf((int) $inv->id, $gid);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Telling people

    /** Bell + push to each guardian with THEIR amount. Returns how many were told. */
    public static function notifyIssued(int $invoiceId): int
    {
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        if (! $inv) return 0;
        $n = 0;
        foreach (self::copies($inv) as $c) {
            if (! $c['user_id']) continue;
            $title = 'New invoice: ' . $c['number'];
            $body = 'Your share is $' . number_format($c['share'], 2) . ($inv->due_at ? ', due ' . \Illuminate\Support\Carbon::parse(substr((string) $inv->due_at, 0, 10))->format('M j, Y') : '') . '.';
            try {
                Notify::write(['user_id' => $c['user_id'], 'type' => 'invoice', 'title' => $title, 'body' => $body,
                    'data' => json_encode(['link' => '#billing', 'invoice_id' => $invoiceId]), 'created_at' => now()]);
                try { app(\App\Services\FcmService::class)->sendToUser($c['user_id'], $title, $body, '#billing'); } catch (\Throwable $e) {}
                $n++;
            } catch (\Throwable $e) {
                Log::warning('split invoice notify failed', ['invoice' => $invoiceId, 'guardian' => $c['guardian_id'], 'error' => $e->getMessage()]);
            }
        }
        return $n;
    }

    /**
     * Email one guardian their copy (PDF attached). $to defaults to the guardian's own address; $cc = extra addresses
     * (e.g. the office). Returns ['sent' => bool, 'to' => ?string, 'number' => string, 'reason' => ?string].
     */
    public static function send(int $invoiceId, int $guardianId, ?string $to = null, ?int $byUserId = null, array $cc = [], array $bcc = []): array
    {
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        $c = $inv ? self::copyFor($inv, $guardianId) : null;
        if (! $c) return ['sent' => false, 'to' => null, 'number' => null, 'reason' => 'This invoice is not split for that guardian.'];
        $to = trim((string) ($to ?: $c['email']));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) return ['sent' => false, 'to' => null, 'number' => $c['number'], 'reason' => 'No email address for ' . $c['name'] . '.'];
        $agencyId = (int) DB::table('centres')->where('id', $inv->centre_id)->value('agency_id');
        if (! Suppression::agencyNotificationsEnabled($agencyId)) {
            return ['sent' => false, 'to' => $to, 'number' => $c['number'], 'reason' => 'Email is switched off for this agency (Settings → "Send notifications and emails").'];
        }
        $agencyName = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'your childcare provider');
        $pdf = self::pdf($invoiceId, $guardianId);
        $html = self::emailHtml($agencyId, $agencyName, $c, $inv->due_at ?? null, (int) ($inv->centre_id ?? 0) ?: null);
        $cc = array_values(array_filter(array_unique(array_map('trim', $cc)), fn ($x) => filter_var($x, FILTER_VALIDATE_EMAIL) && strcasecmp($x, $to) !== 0));
        try {
            $bcc = array_values(array_filter(array_unique(array_map('trim', $bcc)), fn ($x) => filter_var($x, FILTER_VALIDATE_EMAIL) && strcasecmp($x, $to) !== 0));
            \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $cc, $bcc, $c, $pdf, $inv) {
                MailScope::centre($m, (int) $inv->centre_id ?: null);
                $m->to($to)->subject('Your invoice ' . $c['number']);
                foreach ($cc as $x) $m->cc($x);
                foreach ($bcc as $x) $m->bcc($x);   // staff copies from the resend popup (2026-10-31)
                if ($pdf) $m->attachData($pdf, 'Invoice-' . preg_replace('/[^A-Za-z0-9-]/', '-', $c['number']) . '.pdf', ['mime' => 'application/pdf']);
            });
        } catch (\Throwable $ex) {
            Log::warning('split invoice email failed', ['invoice' => $invoiceId, 'guardian' => $guardianId, 'error' => $ex->getMessage()]);
            return ['sent' => false, 'to' => $to, 'number' => $c['number'], 'reason' => 'The email could not be sent.'];
        }
        DB::table('split_invoice_sends')->insert(['invoice_id' => $invoiceId, 'guardian_id' => $guardianId, 'number' => $c['number'],
            'to_email' => $to, 'cc' => $cc ? implode(', ', $cc) : null, 'amount' => $c['balance'], 'sent_by' => $byUserId, 'attached' => $pdf ? 1 : 0, 'created_at' => now()]);
        return ['sent' => true, 'to' => $to, 'number' => $c['number'], 'reason' => null, 'attached' => (bool) $pdf];
    }

    /**
     * The email for one guardian's copy, in KiddieTrac's invoice header with the agency's logo and centre (2026-10-31).
     * $c: a copyFor() result (number, family_number, balance, first_name...). Used by send() and the test samples.
     */
    public static function emailHtml(?int $agencyId, string $agencyName, array $c, $dueAt, ?int $centreId, bool $test = false): string
    {
        $first = trim((string) ($c['first_name'] ?? ''));
        $num = (string) $c['number'];
        $bal = (float) $c['balance'];
        $due = $dueAt ? \Illuminate\Support\Carbon::parse((string) $dueAt) : null;
        $payLink = $agencyId ? InvoiceReminders::payLink($agencyId) : rtrim((string) (config('app.portal_url') ?: 'https://app.kiddietrac.com'), '/') . '/dashboard.html#billing';
        $rows = [['Your invoice', e($num)], ['Family invoice', e((string) $c['family_number'])]];
        if (isset($c['share'])) $rows[] = ['Your share', e(InvoiceMail::money($c['share']))];
        $rows[] = $bal > 0.004 ? ['Balance due', '<strong>' . e(InvoiceMail::money($bal)) . '</strong>'] : ['Balance', 'Paid in full &mdash; thank you'];
        if ($due && $bal > 0.004) $rows[] = ['Due', e($due->format('M j, Y'))];
        $body = '<p style="margin:0 0 8px;">Hi ' . ($first !== '' ? e($first) : 'there') . ',</p>'
            . '<p style="margin:0;">Your invoice <strong>' . e($num) . '</strong> from <strong>' . e($agencyName) . '</strong> is attached as a PDF. '
            . 'It is your share of family invoice <strong>' . e((string) $c['family_number']) . '</strong>.</p>'
            . InvoiceMail::details($rows)
            . ($bal > 0.004 ? InvoiceMail::payButton($agencyId, $payLink, 'Pay my share') : '')
            . InvoiceMail::slot($agencyId, ['kind' => 'share', 'number' => $num, 'balance' => $bal, 'centre_id' => $centreId,
                'invoice_id' => $c['invoice_id'] ?? null, 'kind_of_invoice' => 'native', 'family_id' => $c['family_id'] ?? null,
                'guardian_id' => $c['guardian_id'] ?? null])
            ;
        return \App\Services\EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => 'YOUR INVOICE', 'title' => 'Invoice ' . $num, 'subtitle' => $agencyName,
            'preheader' => 'Your share of invoice ' . $c['family_number'] . ' is attached.',
            'invoice_hero' => InvoiceMail::hero($bal > 0.004 ? 'share' : 'paid', $num, InvoiceMail::money($bal > 0.004 ? $bal : ($c['share'] ?? 0)),
                $bal > 0.004 ? ($due ? 'your share · due ' . $due->format('M j, Y') : 'your share') : 'paid in full · thank you', $centreId),
        ]);
    }

    /** Email every guardian of a split invoice their copy. Returns the per-guardian results. */
    public static function sendAll(int $invoiceId, ?int $byUserId = null): array
    {
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        $out = [];
        foreach ($inv ? self::copies($inv) : [] as $c) {
            $out[] = ['guardian_id' => $c['guardian_id'], 'name' => $c['name']] + self::send($invoiceId, $c['guardian_id'], null, $byUserId);
        }
        return $out;
    }
}
