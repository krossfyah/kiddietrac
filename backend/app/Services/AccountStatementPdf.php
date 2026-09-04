<?php

declare(strict_types=1);

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The account statement as a document.
 *
 * The email is a summary a parent reads on a phone; this is the thing they forward to
 * a bookkeeper, print, or keep. So it differs from the email in one deliberate way:
 * the history is COMPLETE. Truncating a statement to its most recent forty lines is
 * fine in a message that links back to the portal and useless in a file that is the
 * record — the whole point of the attachment is that it stands alone.
 *
 * BRANDED AS THE AGENCY, not as KiddieTrac. The agency is who invoiced the money and
 * who a question about it goes to; the platform's mark belongs in the footer. Details
 * are printed only when the agency has actually entered them — an address or phone
 * number nobody set would be a fabricated detail on a financial document.
 *
 * dompdf renders it, so: tables for layout, inline styles, no flexbox and no grid.
 * DejaVu Sans, because the statement is full of en dashes and minus signs and the
 * default font drops them.
 */
final class AccountStatementPdf
{
    /** Raw PDF bytes for a statement array as built by AccountLedgerController::statement(). */
    public function render(array $st): string
    {
        $options = new Options();
        /* The agency logo is a URL on our own host. Enabled deliberately and the only
           remote reference the document contains. */
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($st), 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** A filename someone can find again in a downloads folder six months later. */
    public function filename(array $st): string
    {
        $who = preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($st['account']['name'] ?? 'account'));

        return trim('statement-' . strtolower(trim((string) $who, '-')) . '-' . ($st['summary']['as_at'] ?? ''), '-') . '.pdf';
    }

    // ── rendering ───────────────────────────────────────────────────────
    private function html(array $st): string
    {
        $a = $st['account'];
        $s = $st['summary'];
        $tz = $a['timezone'] ?: config('app.timezone', 'UTC');

        $agency = null;
        try {
            $agency = DB::table('agencies')->where('id', $a['agency_id'])
                ->first(['name', 'legal_name', 'brand_logo_url', 'logo_url', 'brand_primary_color',
                         'contact_email', 'contact_phone', 'address_line1', 'address_line2',
                         'city', 'province', 'postal_code', 'country', 'website',
                         'tax_label', 'tax_registration', 'settings']);
        } catch (Throwable $e) { /* the document is still worth producing without it */ }

        $brand = $this->safeColour($agency->brand_primary_color ?? null);
        $logo = $this->absolute($agency->brand_logo_url ?? ($agency->logo_url ?? null));
        $agencyName = (string) ($agency->name ?? $a['agency'] ?? 'Your childcare provider');

        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $m = fn ($v) => '$' . number_format((float) $v, 2);
        $d = $this->dateFormatter($tz);

        $css = '
      @page { margin: 108px 44px 68px 44px; }
      body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #1F2937; margin: 0; }
      h2 { font-size: 11pt; margin: 18px 0 6px; color: #0F172A; }
      table { width: 100%; border-collapse: collapse; }
      .head { position: fixed; top: -84px; left: 0; right: 0; height: 72px; }
      .foot { position: fixed; bottom: -46px; left: 0; right: 0; font-size: 7.5pt; color: #94A3B8; }
      .band { background: ' . $brand . '; color: #FFFFFF; padding: 12px 16px; }
      .eyebrow { font-size: 7.5pt; letter-spacing: 1.2px; text-transform: uppercase; opacity: .85; }
      .agency { font-size: 13pt; font-weight: bold; }
      th { text-align: left; font-size: 7.5pt; letter-spacing: .5px; text-transform: uppercase;
           color: #64748B; border-bottom: 1px solid #CBD5E1; padding: 5px 6px; }
      td { padding: 6px; border-bottom: 1px solid #EEF2F6; vertical-align: top; }
      .r { text-align: right; }
      .muted { color: #64748B; font-size: 8pt; }
      .strike { color: #94A3B8; text-decoration: line-through; }
      .pos { background: #F8FAFC; border: 1px solid #E2E8F0; padding: 12px 14px; }
      .big { font-size: 20pt; font-weight: bold; }
      .late { color: #B91C1C; font-weight: bold; }
      .cred { color: #15803D; }
      .note { color: #64748B; font-size: 8pt; }
      .lh { font-size: 8.5pt; color: #475569; line-height: 1.45; }
      .lhname { font-size: 10.5pt; font-weight: bold; color: #0F172A; }
      .label { font-size: 7pt; letter-spacing: .9px; text-transform: uppercase; color: #94A3B8; }
      .card { border: 1px solid #E2E8F0; padding: 11px 13px; }
    ';

        // ── running header, repeated on every page ──────────────────────
        $head = '<div class="head"><table><tr>'
            . '<td class="band" style="width:62%;">'
            . '<div class="eyebrow">Account statement</div>'
            . '<div class="agency">' . $e($agencyName) . '</div>'
            . '</td>'
            . '<td class="band" style="width:38%;text-align:right;">'
            . ($logo
                ? '<img src="' . $e($logo) . '" style="height:38px;">'
                : '<div style="font-size:8pt;opacity:.9;">as at ' . $e($d($s['as_at'])) . '</div>')
            . '</td>'
            . '</tr></table></div>';

        /* ── the issuer, in full ────────────────────────────────────────
           Every line printed only when the field has a value. A business address or
           tax number nobody entered would be a fabricated detail on a financial
           document, which is worse than an absent one. */
        $agencyLines = [];
        foreach ([$agency->address_line1 ?? null, $agency->address_line2 ?? null] as $line) {
            $line = $this->clean($line);
            if ($line !== null) { $agencyLines[] = $e($line); }
        }
        $cityLine = trim(implode(' ', array_filter([
            $this->clean($agency->city ?? null),
            $this->clean($agency->province ?? null),
            $this->clean($agency->postal_code ?? null),
        ])));
        if ($cityLine !== '') { $agencyLines[] = $e($cityLine); }
        if ($this->clean($agency->country ?? null)) { $agencyLines[] = $e($agency->country); }

        $agencyMeta = [];
        if ($this->clean($agency->contact_phone ?? null)) { $agencyMeta[] = $e($this->phone($agency->contact_phone)); }
        if ($this->clean($agency->contact_email ?? null)) { $agencyMeta[] = $e($agency->contact_email); }
        if ($this->clean($agency->website ?? null)) { $agencyMeta[] = $e($agency->website); }
        if ($this->clean($agency->tax_registration ?? null)) {
            $agencyMeta[] = $e(($this->clean($agency->tax_label ?? null) ?: 'Tax reg.') . ' ' . $agency->tax_registration);
        }

        $footBits = array_filter([
            $this->clean($agency->contact_phone ?? null) ? $this->phone($agency->contact_phone) : null,
            $this->clean($agency->contact_email ?? null),
        ]);
        $foot = '<div class="foot">'
            . ($footBits ? $e($agencyName . ' · ' . implode(' · ', $footBits)) . '<br>' : '')
            . 'Generated ' . $e(Carbon::now($tz)->format('j M Y, g:ia')) . ' · KiddieTrac'
            . '</div>';

        // ── the letterhead: who issued this, and when ───────────────────
        $body = '<table style="margin-top:2px;"><tr>'
            . '<td style="border:0;padding:0;width:64%;">'
            . '<div class="lhname">' . $e($this->clean($agency->legal_name ?? null) ?: $agencyName) . '</div>'
            . ($agencyLines ? '<div class="lh">' . implode('<br>', $agencyLines) . '</div>' : '')
            . ($agencyMeta ? '<div class="lh" style="margin-top:3px;">' . implode('<br>', $agencyMeta) . '</div>' : '')
            . '</td>'
            . '<td style="border:0;padding:0;width:36%;text-align:right;vertical-align:top;">'
            . '<div class="label">Statement date</div>'
            . '<div style="font-size:11pt;font-weight:bold;color:#0F172A;">' . $e($d($s['as_at'])) . '</div>'
            . '<div class="label" style="margin-top:8px;">Account</div>'
            . '<div class="lh">#' . (int) $a['user_id'] . '</div>'
            . '</td>'
            . '</tr></table>';

        // ── and who it is for, in full ──────────────────────────────────
        $accLines = [];
        $c = $a['contact'] ?? [];
        foreach (($c['address'] ?? []) as $line) { $accLines[] = $e($line); }
        $reach = [];
        if (! empty($c['email'])) { $reach[] = $e($c['email']); }
        if (! empty($c['phone'])) { $reach[] = $e($this->phone($c['phone'])); }

        $body .= '<div class="card" style="margin-top:14px;"><table><tr>'
            . '<td style="border:0;padding:0;width:62%;">'
            . '<div class="label">Statement for</div>'
            . '<div style="font-size:12pt;font-weight:bold;color:#0F172A;margin-top:2px;">' . $e($a['name']) . '</div>'
            . ($accLines ? '<div class="lh" style="margin-top:3px;">' . implode('<br>', $accLines) . '</div>' : '')
            . ($reach ? '<div class="lh" style="margin-top:3px;">' . implode('<br>', $reach) . '</div>' : '')
            . '</td>'
            . '<td style="border:0;padding:0;width:38%;vertical-align:top;">'
            . ((($a['roles'] ?? []) !== [])
                ? '<div class="label">Role</div><div class="lh">' . $e(implode(', ', $a['roles'])) . '</div>' : '')
            . ((($a['children'] ?? []) !== [])
                ? '<div class="label" style="margin-top:7px;">Children</div><div class="lh">'
                  . $e(implode(', ', array_column($a['children'], 'name'))) . '</div>' : '')
            /* Only an ADVERSE state is red. "Invited" means the person has not signed
               in yet, which is ordinary, and colouring it like a suspension puts a
               warning on a statement that has nothing wrong with it. */
            . ((! empty($a['status']) && strtolower((string) $a['status']) !== 'active')
                ? '<div class="label" style="margin-top:7px;">Account status</div>'
                  . '<div class="lh"'
                  . (in_array(strtolower((string) $a['status']), ['suspended', 'closed', 'inactive', 'deactivated'], true)
                      ? ' style="color:#B91C1C;font-weight:bold;"' : '')
                  . '>' . $e(ucfirst((string) $a['status'])) . '</div>' : '')
            . '</td>'
            . '</tr></table></div>';

        // ── the position ────────────────────────────────────────────────
        $bal = (float) $s['balance'];
        $word = $bal > 0.005 ? 'Balance outstanding' : ($bal < -0.005 ? 'Credit on account' : 'Nothing outstanding');
        $body .= '<div class="pos" style="margin-top:14px;">'
            . '<div class="eyebrow" style="color:#64748B;">' . $word . '</div>'
            . '<div class="big" style="color:' . ($bal > 0.005 ? '#B45309' : '#15803D') . ';">' . $m(abs($bal)) . '</div>'
            . ((float) $s['overdue'] > 0.005
                ? '<div class="late" style="margin-top:5px;">' . $m($s['overdue']) . ' of this is past its due date.</div>' : '')
            . ($s['last_payment']
                ? '<div class="muted" style="margin-top:5px;">Last payment: ' . $m($s['last_payment']['amount'])
                  . ' on ' . $e($d($s['last_payment']['date'])) . '</div>' : '')
            . ($s['next_due']
                ? '<div class="muted">Next due: '
                  . ($s['next_due']['amount'] !== null ? $m($s['next_due']['amount']) . ' on ' : '')
                  . $e($d($s['next_due']['date'])) . '</div>' : '')
            . '</div>';

        // ── summary ─────────────────────────────────────────────────────
        $lines = [['Invoiced', $m($s['billed'])], ['Payments received', $m($s['credited'])]];
        if ((float) $s['refunded'] > 0.005) { $lines[] = ['Refunded', $m($s['refunded'])]; }
        if ((float) ($s['overpaid'] ?? 0) > 0.005) {
            $lines[] = ['Received above the amount invoiced', $m($s['overpaid'])];
        }
        if ((float) $s['voided'] > 0.005) { $lines[] = ['Voided — no longer owed', $m($s['voided'])]; }
        $lines[] = ['<b>Balance</b>', '<b>' . $m($s['balance']) . '</b>'];

        $body .= '<h2>Summary</h2><table>';
        foreach ($lines as $l) {
            $body .= '<tr><td>' . $l[0] . '</td><td class="r">' . $l[1] . '</td></tr>';
        }
        $body .= '</table>';

        // ── still outstanding ───────────────────────────────────────────
        if ($st['open_items']) {
            $body .= '<h2>Still outstanding</h2><table>'
                . '<tr><th>Invoice</th><th>Issued</th><th>Due</th><th class="r">Invoiced</th><th class="r">Outstanding</th></tr>';
            foreach ($st['open_items'] as $o) {
                $body .= '<tr><td>' . $e($o['reference']) . '</td>'
                    . '<td>' . $e($d($o['issued_at'])) . '</td>'
                    . '<td>' . $e($d($o['due_at']))
                    . ($o['days_overdue'] > 0 ? '<div class="late">' . (int) $o['days_overdue'] . ' days late</div>' : '')
                    . '</td>'
                    . '<td class="r">' . $m($o['total']) . '</td>'
                    . '<td class="r"><b>' . $m($o['outstanding']) . '</b></td></tr>';
            }
            $body .= '</table>';
        }

        // ── coming up ───────────────────────────────────────────────────
        if ($st['upcoming']) {
            $body .= '<h2>Coming up</h2><table>'
                . '<tr><th>What</th><th>When</th><th class="r">Amount</th></tr>';
            foreach ($st['upcoming'] as $u) {
                $body .= '<tr><td>' . $e($u['description'])
                    . ($u['detail'] ? '<div class="note">' . $e($u['detail']) . '</div>' : '') . '</td>'
                    . '<td>' . ($u['overdue']
                        ? '<span class="late">' . $e($d($u['date'])) . ' — missed</span>'
                        : $e($d($u['date']))) . '</td>'
                    . '<td class="r">' . ($u['amount'] !== null ? $m($u['amount']) : '—') . '</td></tr>';
            }
            $body .= '</table>';
        }

        /* ── the history, COMPLETE ───────────────────────────────────────
           The email shows the most recent forty and links back to the portal. This
           document has no portal to link to, so it carries every line — that is what
           makes it usable as the record. */
        $owed = array_values(array_filter($st['entries'], fn ($x) => ($x['direction'] ?? '') === 'owed'));
        if ($owed) {
            $body .= '<h2>Account history</h2>'
                . '<div class="muted" style="margin-bottom:4px;">Oldest first. '
                . count($owed) . ' entries.</div>'
                . '<table><tr><th>Date</th><th>Detail</th><th class="r">Charge</th><th class="r">Payment</th><th class="r">Balance</th></tr>';
            foreach ($owed as $x) {
                $charge = $x['kind'] === 'void'
                    ? '<span class="strike">' . $m($x['original'] ?? 0) . '</span>'
                    : ((float) ($x['debit'] ?? 0) > 0.005 ? $m($x['debit']) : '');
                $paid = (float) ($x['credit'] ?? 0) > 0.005 ? '<span class="cred">' . $m($x['credit']) . '</span>' : '';
                $body .= '<tr><td style="white-space:nowrap;">' . $e($d($x['date'])) . '</td>'
                    . '<td>' . $e($x['description'])
                    . (! empty($x['note']) ? '<div class="note">' . $e($x['note']) . '</div>' : '') . '</td>'
                    . '<td class="r">' . $charge . '</td>'
                    . '<td class="r">' . $paid . '</td>'
                    . '<td class="r">' . (isset($x['running_balance']) ? $m($x['running_balance']) : '') . '</td></tr>';
            }
            $body .= '</table>';
        }

        // ── the other direction, only when there is one ─────────────────
        $out = array_values(array_filter($st['entries'], fn ($x) => ($x['direction'] ?? '') === 'paid_out'));
        if ($out) {
            $body .= '<h2>Paid to this account</h2>'
                . '<div class="muted" style="margin-bottom:4px;">Kept separate from the balance above — '
                . 'money paid to someone is not a credit against fees they owe.</div>'
                . '<table><tr><th>Date</th><th>Detail</th><th>Reference</th><th class="r">Amount</th></tr>';
            foreach ($out as $x) {
                $body .= '<tr><td style="white-space:nowrap;">' . $e($d($x['date'])) . '</td>'
                    . '<td>' . $e($x['description']) . '</td>'
                    . '<td>' . $e($x['reference']) . '</td>'
                    . '<td class="r">' . $m($x['net'] ?? 0) . '</td></tr>';
            }
            $body .= '</table>'
                . '<table style="margin-top:4px;"><tr><td style="border:0;"><b>Total paid out</b></td>'
                . '<td class="r" style="border:0;"><b>' . $m($s['paid_out']) . '</b></td></tr></table>';
        }

        if (! $owed && ! $out) {
            $body .= '<div class="muted" style="margin-top:20px;">Nothing has been recorded against this account.</div>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>'
            . $head . $foot . $body . '</body></html>';
    }

    /**
     * One formatter, two kinds of value, told apart by shape — the same rule the
     * screen and the email follow. A bare YYYY-MM-DD is a wall-clock day and must not
     * be converted; anything with a time is a UTC instant and must be.
     */
    private function dateFormatter(string $tz): callable
    {
        return function ($v) use ($tz) {
            if (! $v) { return '—'; }
            try {
                $raw = (string) $v;

                return preg_match('/\d{2}:\d{2}/', $raw)
                    ? Carbon::parse($raw, 'UTC')->setTimezone($tz)->format('j M Y')
                    : Carbon::parse(substr($raw, 0, 10))->format('j M Y');
            } catch (Throwable $e) {
                return (string) $v;
            }
        };
    }

    /**
     * The house phone mask, matching what the portal shows everywhere else.
     *
     * Never destructive: a plain 10- or 11-digit North American number is formatted,
     * and ANYTHING else — an extension, an international number, a note somebody typed
     * into the field — is returned exactly as entered. Reformatting what we do not
     * understand loses information on a document people rely on.
     */
    private function phone(?string $v): string
    {
        $raw = trim((string) $v);
        $digits = preg_replace('/\D+/', '', $raw);

        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) !== 10) {
            return $raw;
        }

        return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
    }

    /** A stored value that is present and meaningful, or null. */
    private function clean($v): ?string
    {
        $v = trim((string) $v);

        return ($v === '' || strtolower($v) === 'null') ? null : $v;
    }

    /** Never let a stored value become a CSS injection, and never render an empty colour. */
    private function safeColour(?string $c): string
    {
        $c = trim((string) $c);

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $c) ? $c : '#1F6080';
    }

    /** Stored logo paths are relative to the app host; a PDF needs an absolute one. */
    private function absolute(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') { return null; }
        if (preg_match('~^https?://~i', $url)) { return $url; }

        return 'https://app.kiddietrac.com/' . ltrim($url, '/');
    }
}
