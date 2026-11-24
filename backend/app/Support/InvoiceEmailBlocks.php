<?php

namespace App\Support;

use Throwable;

/**
 * The invoice-email content slot (InvoiceMail::slot): "How to pay", the parent app, "Not registered yet?".
 * Wording and rules live in InvoicePayInfo, shared with the invoice PDF. Never throws - a failure returns ''.
 * Dark mode: .kt-panel / .kt-muted, which EmailTemplate restyles for dark clients ([data-ogsc] included).
 *
 * $ctx: kind (invoice|resend|copy|share|before|due|overdue|receipt|paid), number, invoice_id, kind_of_invoice,
 *       family_id, guardian_id, balance, centre_id.
 */
final class InvoiceEmailBlocks
{
    public static function html(?int $agencyId, array $ctx): string
    {
        try {
            $kind = (string) ($ctx['kind'] ?? 'invoice');
            $number = trim((string) ($ctx['number'] ?? ''));
            $balance = (float) ($ctx['balance'] ?? 0);
            $familyId = ! empty($ctx['family_id']) ? (int) $ctx['family_id'] : null;
            if (! $familyId && ! empty($ctx['invoice_id']) && ($ctx['kind_of_invoice'] ?? 'native') !== 'external') {
                $familyId = (int) (\Illuminate\Support\Facades\DB::table('invoices')->where('id', (int) $ctx['invoice_id'])->value('family_id') ?: 0) ?: null;
            }
            $guardianId = ! empty($ctx['guardian_id']) ? (int) $ctx['guardian_id'] : null;
            $c = InvoicePayInfo::context($agencyId, $familyId, $guardianId);
            $font = 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;';
            $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $panel = function (string $title, string $inner, string $bg = '#F8FAFC', string $border = '#E2E8F0') use ($font, $e) {
                return '<table role="presentation" class="kt-panel" cellpadding="0" cellspacing="0" border="0" width="100%" '
                    . 'style="width:100%;border-collapse:separate;background:' . $bg . ';border:1px solid ' . $border . ';border-radius:12px;margin:14px 0 0;">'
                    . '<tr><td style="padding:14px 18px;' . $font . 'font-size:13.5px;line-height:1.6;color:#334155;">'
                    . '<div style="font-size:14px;font-weight:800;color:#0F172A;margin:0 0 6px;">' . $e($title) . '</div>' . $inner . '</td></tr></table>';
            };

            $out = '';
            $paidOrReceipt = $balance <= 0.004 || in_array($kind, ['receipt', 'paid'], true);
            $m = $paidOrReceipt ? [] : InvoicePayInfo::methods($c, $number);
            if ($m) {
                $rows = '';
                foreach ($m as $i => [$t, $d]) {
                    $line = $i < count($m) - 1 ? 'border-bottom:1px solid rgba(148,163,184,.28);' : '';
                    $rows .= '<tr><td style="padding:8px 0;' . $line . '">'
                        . '<div style="font-weight:700;color:#0F172A;">' . $e($t) . '</div>'
                        . '<div class="kt-muted" style="color:#475569;">' . $d . '</div></td></tr>';
                }
                $out .= $panel('How to pay', '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;">' . $rows . '</table>');
            }

            [$intro, $reg] = InvoicePayInfo::appLines($c);
            $out .= $panel('Your account, anytime', '<div>' . $intro . '</div>');

            if ($c['register']) {
                $get = InvoicePayInfo::getIt($c, true);
                $out .= $panel('Not registered yet?', '<div>' . $reg . '</div><div style="margin-top:6px;">' . $get . '</div>', '#FFFFFF', '#CBD5E1');
            }

            return $out;
        } catch (Throwable $e) {
            report($e);

            return '';
        }
    }
}
