<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\EmailTemplate;

/**
 * Emails whose words an agency can edit on Settings → Email templates, built in ONE place
 * that both the real sender and the editor's Preview / Send test call (2026-10-06).
 *
 * Anthony: "email templates add a function to preview and send test to a defined address
 * and update email templates to anything that is missing".
 *
 * How the defaults stay exactly as they were: every editable piece below is written as
 *
 *     EmailTemplates::piece($agencyId, KEY, BLOCK, $data, $draft) ?? <the original expression>
 *
 * piece() returns null unless the agency (or the editor's unsaved draft) has actually
 * changed that block from its default, so an agency that never customises gets the
 * original HTML byte for byte. The non-editable parts — the figures table, the button
 * markup, the closure's When/Why box — are copied unchanged from the senders that used
 * to compose them inline.
 *
 * Each builder returns ['subject' => string, 'html' => string].
 */
final class TemplatedEmails
{
    private static function p(string $t): string
    {
        return '<p style="margin:0 0 12px;font-size:15px;line-height:1.6;color:#2A3D5F;">' . $t . '</p>';
    }

    private static function row(string $k, string $v): string
    {
        return '<tr><td style="padding:3px 14px 3px 0;color:#64748B;white-space:nowrap;">' . e($k)
            . '</td><td style="padding:3px 0;">' . $v . '</td></tr>';
    }

    private static function button(string $label): string
    {
        return '<p style="margin:22px 0 0;"><a href="https://app.kiddietrac.com/dashboard.html#billing" '
            . 'style="display:inline-block;background:#1F6FB2;color:#fff;text-decoration:none;font-weight:700;'
            . 'font-size:15px;padding:13px 26px;border-radius:10px;">' . $label . '</a></p>';
    }

    private static function signoff(string $inner): string
    {
        return '<p style="margin:26px 0 0;font-size:15px;line-height:1.6;color:#2A3D5F;">' . $inner . '</p>';
    }

    /* ── Payment received (was PaymentNotifier::receiptToPayer) ─────────────────────
       $d: name, amount (formatted), how, date, reference, invoice_number|null,
           balance_now|null (float), agency_name */
    public static function paymentReceipt(int $agencyId, array $d, ?array $draft = null): array
    {
        $K = 'payment-receipt';
        $T = fn (string $b) => EmailTemplates::piece($agencyId, $K, $b, $d, $draft);
        $balance = $d['balance_now'] ?? null;

        $rows = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:6px 0 0;font-size:14px;color:#2A3D5F;">'
            . self::row('Amount', '<strong>' . $d['amount'] . '</strong>')
            . self::row('Paid with', e($d['how']))
            . self::row('Date', $d['date'])
            . self::row('Reference', $d['reference']);
        if (! empty($d['invoice_number'])) {
            $rows .= self::row('Invoice', e((string) $d['invoice_number']));
        }
        if ($balance !== null) {
            $rows .= self::row('Still outstanding', $balance > 0.005
                ? '<strong>' . $d['balance_text'] . '</strong>'
                : 'Nothing — this invoice is paid in full');
        }
        $rows .= '</table>';

        $body = self::p($T('greeting') ?? ('Hello ' . e($d['name']) . ','))
            . self::p($T('intro') ?? 'Thank you — your payment has gone through. Here are the details for your records.')
            . $rows
            . ($balance !== null && $balance > 0.005
                ? self::p('<span style="color:#92400E;">This was a part payment, so '
                    . $d['balance_text'] . ' is still outstanding on this invoice.</span>')
                : '')
            . self::p($T('outro') ?? 'You can see all your invoices and payments any time in your portal.')
            . self::button($T('button_label') ?? 'View your billing')
            . self::signoff($T('signoff') ?? ('Kind regards,<br>'
                . '<strong style="color:#0B1A33;">' . e($d['agency_name']) . ' Team</strong>'));

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => 'PAYMENT RECEIPT',
            'title' => EmailTemplates::subjectPiece($agencyId, $K, 'title', $d, $draft) ?? 'Thank you — payment received',
            'subtitle' => $d['amount'] . ' · ' . $d['how'],
            'preheader' => 'Your payment of ' . $d['amount'] . ' has gone through.',
        ]);

        return [
            'subject' => EmailTemplates::subjectPiece($agencyId, $K, 'subject', $d, $draft) ?? ('Payment received — ' . $d['amount']),
            'html' => $html,
        ];
    }

    /* ── Payment did not go through (was PaymentNotifier::failureToPayer) ───────────
       $d: name, amount, how, invoice_number|null, agency_name */
    public static function paymentFailed(int $agencyId, array $d, ?array $draft = null): array
    {
        $K = 'payment-failed';
        $T = fn (string $b) => EmailTemplates::piece($agencyId, $K, $b, $d, $draft);

        $body = self::p($T('greeting') ?? ('Hello ' . e($d['name']) . ','))
            . self::p($T('intro') ?? ('Your payment of <strong>' . $d['amount'] . '</strong> did not go through, so '
                . 'nothing has been taken from your ' . e($d['how']) . '.'))
            . (! empty($d['invoice_number']) ? self::p('Invoice <strong>' . e((string) $d['invoice_number'])
                . '</strong> is still outstanding.') : '')
            . self::p($T('advice') ?? ('This usually means the card has expired, has been replaced, or the bank declined it. '
                . 'Adding a different card in your portal is the quickest fix.'))
            . self::button($T('button_label') ?? 'Update your payment method')
            . self::p('<span style="font-size:13.5px;color:#64748B;">' . ($T('contact') ?? ('If you think this is a mistake, please contact '
                . e($d['agency_name']) . ' — we would rather sort it out with you than have it sit unpaid.')) . '</span>')
            . self::signoff($T('signoff') ?? ('Kind regards,<br>'
                . '<strong style="color:#0B1A33;">' . e($d['agency_name']) . ' Team</strong>'));

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => 'PAYMENT UNSUCCESSFUL',
            'title' => EmailTemplates::subjectPiece($agencyId, $K, 'title', $d, $draft) ?? 'That payment did not go through',
            'subtitle' => $d['amount'] . ' · nothing has been taken',
            'preheader' => 'Your payment of ' . $d['amount'] . ' was not completed.',
        ]);

        return [
            'subject' => EmailTemplates::subjectPiece($agencyId, $K, 'subject', $d, $draft) ?? 'Your payment did not go through',
            'html' => $html,
        ];
    }

    /* ── Centre closure announcement (was OperationsV2Controller::mailClosure) ─────
       Sent to the centre's families AND staff. $phase: upcoming | current | past. */
    public static function closureNotice(?int $agencyId, string $centreName, string $dates, string $reason, string $phase = 'upcoming', ?array $draft = null): array
    {
        $K = 'closure-notice';
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $phase = in_array($phase, ['upcoming', 'current', 'past'], true) ? $phase : 'upcoming';
        $verb = $phase === 'past' ? 'was closed' : 'is closed';
        $d = ['centre_name' => $centreName, 'dates' => $dates, 'reason' => $reason, 'closed_verb' => $verb];
        $T = fn (string $b) => $agencyId ? EmailTemplates::piece($agencyId, $K, $b, $d, $draft) : null;

        /* Three tenses, because a closure can be entered at any point relative to itself.
           `past` is deliberately worded as a record rather than a notice - telling a
           parent about a closure that has already finished is a correction to the
           calendar, not news, and pretending otherwise is worse than saying so. */
        [$opening, $closing, $title] = [
            'upcoming' => [
                'We are letting you know that <strong>' . $e($centreName) . '</strong> will be closed.',
                'There is nothing you need to do. Sign-in is switched off for those days, and we will see you when we reopen.',
                $centreName . ' will be closed',
            ],
            'current' => [
                '<strong>' . $e($centreName) . '</strong> is closed at the moment. Apologies for the '
                    . 'late notice &mdash; this has only just been added to the calendar.',
                'There is nothing you need to do. Sign-in is switched off for these days, and we will see you when we reopen.',
                $centreName . ' is closed',
            ],
            'past' => [
                'For your records: <strong>' . $e($centreName) . '</strong> was closed on the dates below. '
                    . 'This has just been added to the calendar, so it may be the first you have heard of it.',
                'Nothing is needed from you &mdash; this is a record of a closure that has already passed.',
                $centreName . ' was closed',
            ],
        ][$phase];
        $opening = $T('opening_' . $phase) ?? $opening;
        $closing = $T('closing_' . $phase) ?? $closing;

        $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            . '<tr><td style="font-size:15px;line-height:1.6;color:#334155;padding:0 0 12px;">'
            . $opening . '</td></tr>'
            . '<tr><td style="padding:6px 0;"><div style="background:#F1F5F9;border-radius:10px;padding:14px 16px;">'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748B;">When</div>'
            . '<div style="font-size:16px;font-weight:700;color:#0F172A;margin:2px 0 10px;">' . $e($dates) . '</div>'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748B;">Why</div>'
            . '<div style="font-size:15px;color:#0F172A;margin-top:2px;">' . $e($reason) . '</div></div></td></tr>'
            . '<tr><td style="padding:14px 0 0;font-size:14px;line-height:1.6;color:#64748B;">'
            . $closing . '</td></tr></table>';

        $html = EmailTemplate::wrap($agencyId, $body, [
            'eyebrow' => 'CENTRE CLOSURE',
            'title' => $title,
            'subtitle' => $dates . ' · ' . $reason,
            'preheader' => $centreName . ' closed ' . $dates . ' — ' . $reason,
        ]);

        $subject = ($agencyId ? EmailTemplates::subjectPiece($agencyId, $K, 'subject', $d, $draft) : null)
            ?? ($centreName . ' ' . $verb . ' ' . $dates);

        return ['subject' => $subject, 'html' => $html];
    }

    /* ── Invoice cancelled, to the family (was InvoiceController::voidLetters 'parent') ─
       $d: number, money, due|null (formatted), agency_name. $facts is the shared figures
       box, built by the caller exactly as before. */
    public static function invoiceCancelled(int $agencyId, array $d, string $facts, ?array $draft = null): array
    {
        $K = 'invoice-cancelled';
        $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        $T = fn (string $b) => EmailTemplates::piece($agencyId, $K, $b, $d, $draft);

        $parentBody = '<table width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td style="padding:0 0 14px;font-size:15px;line-height:1.6;color:#334155;">'
            . ($T('intro') ?? ('We are writing to let you know that the invoice below has been <strong>cancelled</strong> by '
                . $esc($d['agency_name']) . '.')) . '</td></tr>'
            . $facts
            . '<tr><td style="padding:14px 0 0;font-size:15px;line-height:1.6;color:#334155;">'
            . ($T('nothing_to_do') ?? ('<strong>There is nothing you need to do.</strong> This amount is no longer owed and has been '
                . 'removed from your balance. If you have already had a reminder about it, please disregard that '
                . 'reminder, and if you were about to pay it, there is no longer any need.'))
            . '</td></tr>'
            . '<tr><td style="padding:12px 0 0;font-size:14px;line-height:1.6;color:#64748B;">'
            . ($T('already_paid') ?? ('If you have already paid this invoice, the amount will be returned to you or applied to another '
                . 'invoice, and we will be in touch. If a replacement invoice is needed you will receive it '
                . 'separately. Any questions, just reply to this email.')) . '</td></tr>'
            . '</table>';

        $html = EmailTemplate::wrap($agencyId, $parentBody, [
            'eyebrow' => 'INVOICE CANCELLED',
            'title' => 'Invoice ' . $d['number'] . ' has been cancelled',
            'subtitle' => $d['money'] . ' is no longer owed',
            'preheader' => 'Invoice ' . $d['number'] . ' for ' . $d['money'] . ' has been cancelled. Nothing to do.',
        ]);

        return [
            'subject' => EmailTemplates::subjectPiece($agencyId, $K, 'subject', $d, $draft)
                ?? ('Invoice ' . $d['number'] . ' has been cancelled - nothing to do'),
            'html' => $html,
        ];
    }

    /** The figures box the invoice-cancelled letter shows, as InvoiceController builds it. */
    public static function invoiceFacts(string $number, string $money, ?string $due): string
    {
        $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        $cap = 'font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748B;';
        $val = 'font-size:16px;font-weight:700;color:#0F172A;margin:2px 0 10px;';

        return '<tr><td style="padding:6px 0;"><div style="background:#F1F5F9;border-radius:10px;padding:14px 16px;">'
            . '<div style="' . $cap . '">Invoice</div><div style="' . $val . '">' . $esc($number) . '</div>'
            . '<div style="' . $cap . '">Amount</div>'
            . '<div style="font-size:16px;font-weight:700;color:#0F172A;margin:2px 0 ' . ($due ? '10px' : '0') . ';">' . $esc($money) . '</div>'
            . ($due ? '<div style="' . $cap . '">Was due</div><div style="font-size:15px;color:#0F172A;margin-top:2px;">' . $esc($due) . '</div>' : '')
            . '</div></td></tr>';
    }
}
