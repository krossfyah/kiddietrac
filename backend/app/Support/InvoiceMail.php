<?php

namespace App\Support;

use App\Services\EmailTemplate;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * PARENT INVOICE EMAILS IN THE INVOICE HEADER (2026-10-31, Anthony: "when invoices are sent out include agency logo and
 * info in the email like how we have it for other emails ... use the invoice header that we created for kiddietrac and
 * not our standard header").
 *
 * The header is KiddieTrac's own billing hero - the gradient band from the invoices KiddieTrac sends agencies
 * (PlatformAccounts::heroHtml): status chip, "Invoice INV-…", the amount in a frosted pill, the gradient line under it.
 * EmailTemplate::wrap draws it from the 'invoice_hero' option with the SENDING agency's logo on a white tile, its name and
 * the centre's address/phone/email (a white-label agency gets its own colours and logo, never KiddieTrac's mark), so the
 * card, the agency footer, the dark-mode palette and the per-agency wording overrides all stay exactly as other emails.
 *
 * issued() is the one "here is your invoice" letter used by every path that sends a family its invoice: issue, resend,
 * the monthly batch, a parent's own copy.
 */
final class InvoiceMail
{
    /**
     * The 'invoice_hero' option for EmailTemplate::wrap.
     * $kind: invoice | resend | copy | share | paid | before | due | overdue | receipt.
     */
    public static function hero(string $kind, string $number, string $amount, string $sub, ?int $centreId = null): array
    {
        [$chip, $col, $doc] = match ($kind) {
            'before' => ['DUE SOON', '#FBBF24', 'Payment reminder'],
            'due' => ['DUE TODAY', '#FBBF24', 'Payment due today'],
            'overdue' => ['OVERDUE', '#F87171', 'Overdue notice'],
            'resend', 'copy' => ['YOUR INVOICE', '#5EEAD4', 'Invoice'],
            'share' => ['YOUR SHARE', '#5EEAD4', 'Invoice'],
            'paid' => ['PAID IN FULL', '#4ADE80', 'Invoice'],
            'receipt' => ['PAYMENT RECEIVED', '#4ADE80', 'Payment receipt'],
            'refund' => ['REFUND', '#5EEAD4', 'Refund'],
            'failed' => ['NOT PAID', '#F87171', 'Payment unsuccessful'],
            default => ['NEW INVOICE', '#5EEAD4', 'Invoice'],
        };
        return ['chip' => $chip, 'chip_col' => $col, 'doc_title' => $doc, 'number' => $number, 'amount' => $amount,
            'amount_sub' => $sub, 'centre_id' => $centreId];
    }

    /** "$1,240.00" */
    public static function money($v): string
    {
        return '$' . number_format((float) $v, 2);
    }

    /** The invoice facts under the letter: [[label, html], ...]. kt-panel so dark mode turns it dark with its text. */
    public static function details(array $rows): string
    {
        $out = '<table role="presentation" class="kt-panel" cellpadding="0" cellspacing="0" border="0" width="100%" '
            . 'style="width:100%;border-collapse:separate;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:12px;margin:16px 0 18px;">'
            . '<tr><td style="padding:6px 18px;"><table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;">';
        $n = count($rows);
        foreach (array_values($rows) as $i => [$k, $v]) {
            $line = $i < $n - 1 ? 'border-bottom:1px solid rgba(148,163,184,.28);' : '';
            $out .= '<tr><td class="kt-muted" style="padding:9px 0;' . $line . 'font-size:13px;color:#64748B;">' . htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:9px 0;' . $line . 'font-size:14px;color:#0F172A;font-weight:600;text-align:right;">' . $v . '</td></tr>';
        }
        return $out . '</table></td></tr></table>';
    }

    /** The one button, in the agency's colours (KiddieTrac's billing gradient when it is KiddieTrac-branded). */
    public static function payButton(?int $agencyId, string $href, string $label): string
    {
        $c = EmailTemplate::brandColours($agencyId);
        $bg = $c['white_label'] ? $c['primary'] : '#2563EB';
        $img = $c['white_label'] ? ($c['hero_css'] ?: 'none') : 'linear-gradient(135deg,#1DB9C3,#2563EB 55%,#7C3AED)';
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 18px;"><tr>'
            . '<td class="kt-btn" bgcolor="' . $bg . '" style="background:' . $bg . ';background-image:' . $img . ';border-radius:999px;">'
            . '<a class="kt-btn" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:13px 30px;font-size:15px;'
            . 'font-weight:800;color:#FFFFFF;text-decoration:none;border-radius:999px;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ' &rarr;</a>'
            . '</td></tr></table>';
    }

    /** The Interac line, when the agency takes e-Transfers. */
    public static function interac(?string $email, string $amount, string $number): string
    {
        if (! $email) return '';
        return EmailTemplate::calloutBox('<strong>Prefer Interac e-Transfer?</strong> Send ' . e($amount) . ' to <strong>' . e($email) . '</strong>'
            . ($number !== '' ? ' and put <strong>' . e($number) . '</strong> in the message' : '') . '.', 'info');
    }

    /**
     * THE CONTENT SLOT (2026-10-31): the invoice-content blocks (line descriptions, "How to pay", the parent-app section,
     * the app-download note) are another job's shared partial. When App\Support\InvoiceEmailBlocks exists with
     * html(?int $agencyId, array $ctx): string, its HTML goes here, under the facts and the button, in every invoice
     * email. $ctx: kind (invoice|resend|copy|share|before|due|overdue), number, invoice_id, kind_of_invoice, family_id,
     * guardian_id, balance, centre_id. Absent or failing -> nothing, the email still goes.
     */
    public static function slot(?int $agencyId, array $ctx): string
    {
        $cls = '\App\Support\InvoiceEmailBlocks';
        if (! class_exists($cls) || ! method_exists($cls, 'html')) return '';
        try {
            return (string) $cls::html($agencyId, $ctx);
        } catch (Throwable $e) {
            report($e);
            return '';
        }
    }

    /**
     * "Here is your invoice" for one family. Returns [subject, html].
     * $o: number, total, balance (null = total), due (Y-m-d|null), first_name, agency_name, note, attached (bool, default
     *     true), centre_id, pay_link, interac, kind (invoice | resend | copy), test (bool).
     */
    public static function issued(?int $agencyId, array $o): array
    {
        $num = trim((string) ($o['number'] ?? ''));
        $ag = trim((string) ($o['agency_name'] ?? '')) ?: 'your childcare provider';
        $total = (float) ($o['total'] ?? 0);
        $bal = array_key_exists('balance', $o) && $o['balance'] !== null ? (float) $o['balance'] : $total;
        $due = null;
        try { $due = ! empty($o['due']) ? Carbon::parse((string) $o['due']) : null; } catch (Throwable $e) {}
        $kind = (string) ($o['kind'] ?? 'invoice');
        $first = trim((string) ($o['first_name'] ?? ''));
        $paid = $bal <= 0.005;
        $link = (string) ($o['pay_link'] ?? '') ?: ($agencyId ? InvoiceReminders::payLink($agencyId) : 'https://app.kiddietrac.com/dashboard.html#billing');

        $lead = $kind === 'copy'
            ? 'As requested, here is a copy of invoice <strong>' . e($num) . '</strong> from <strong>' . e($ag) . '</strong>.'
            : 'Your invoice <strong>' . e($num) . '</strong> from <strong>' . e($ag) . '</strong> for <strong>' . e(self::money($total)) . '</strong>'
                . ($due && ! $paid ? ' is due on <strong>' . e($due->format('l, F j, Y')) . '</strong>.' : ' is ready.');
        $attached = ! array_key_exists('attached', $o) || ! empty($o['attached']);
        $lead .= $attached ? ' The invoice is attached as a PDF.' : '';

        $rows = [['Invoice', e($num)], ['Amount', e(self::money($total))]];
        $rows[] = $paid ? ['Balance', 'Paid in full &mdash; thank you'] : ['Balance due', e(self::money($bal))];
        if ($due && ! $paid) $rows[] = ['Due', e($due->format('M j, Y'))];

        $note = trim((string) ($o['note'] ?? ''));
        $body = '<p style="margin:0 0 8px;">Hi ' . ($first !== '' ? e($first) : 'there') . ',</p>'
            . '<p style="margin:0;">' . $lead . '</p>'
            . ($note !== '' ? '<p style="margin:12px 0 0;">' . nl2br(e($note)) . '</p>' : '')
            . self::details($rows);
        if (! $paid) {
            // Interac, cheque, cash and online pay are listed by the content slot below (InvoiceEmailBlocks).
            $body .= self::payButton($agencyId, $link, 'View invoice & pay');
        }
        $body .= self::slot($agencyId, ['kind' => $kind, 'number' => $num, 'balance' => $bal, 'centre_id' => $o['centre_id'] ?? null,
            'invoice_id' => $o['invoice_id'] ?? null, 'kind_of_invoice' => $o['kind_of_invoice'] ?? null, 'family_id' => $o['family_id'] ?? null,
            'guardian_id' => $o['guardian_id'] ?? null]);
        if (! $attached) {
            $body .= '<p style="margin:12px 0 0;font-size:13px;color:#92400E;">The PDF could not be attached &mdash; you can open the invoice in the app, or ask us for a copy.</p>';
        }
        if ($kind === 'copy') {
            $body .= '<p class="kt-muted" style="font-size:12.5px;color:#64748B;margin:16px 0 0;">You asked for this copy from your account; if you didn\'t, you can ignore it.</p>';
        }

        $heroKind = $paid ? 'paid' : $kind;
        $sub = $paid ? 'paid in full · thank you' : ($due ? 'due ' . $due->format('M j, Y') : 'balance due');
        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => 'INVOICE',
            'title' => 'Invoice ' . $num,
            'subtitle' => $ag,
            'preheader' => 'Invoice ' . $num . ' from ' . $ag . ($paid ? ' — paid in full.' : ' — ' . self::money($bal) . ($due ? ' due ' . $due->format('M j') : '') . '.'),
            'invoice_hero' => self::hero($heroKind, $num, self::money($paid ? $total : $bal), $sub, isset($o['centre_id']) ? (int) $o['centre_id'] : null),
        ]);
        $subject = $kind === 'copy' ? 'Your invoice ' . $num : 'Invoice ' . $num . ' — ' . $ag;
        if (! empty($o['test'])) $subject = '[TEST] ' . $subject;
        return [$subject, $html];
    }
}
