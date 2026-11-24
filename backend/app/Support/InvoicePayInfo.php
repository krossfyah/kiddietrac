<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * HOW TO PAY, THE PARENT APP, AND "NOT REGISTERED YET?" ON EVERY INVOICE (2026-10-31, Anthony: "the method of payments
 * should show on the invoice including letting them know that they can use the parent app to pay and instructions can
 * also be found there ... add a note if you have not registered for the parent app please do so ... the app can be
 * found on the android google playstore and ios store or can be accessed by the url").
 *
 * One source for the invoice PDF (InvoiceDocument, every template), the split guardian copy (SplitInvoiceCopy) and the
 * emails (InvoiceEmailBlocks → InvoiceMail::slot).
 *
 * Only what the agency really offers (Billing setup → Accepted payment methods): online card / bank payment is listed
 * only when a payment processor is live for the agency (a sandbox or placeholder is not "pay online"); Interac only with
 * the agency's own e-Transfer address; cash and cheque at the centre. Nothing is guessed.
 * White-label agencies are named as their own app, at their own sign-in address, with no KiddieTrac mark or Play badge.
 * KiddieTrac's own app: Google Play for Android and, since 2026-11-06, the App Store for iPhone (PlayBadge::IOS_URL).
 */
final class InvoicePayInfo
{
    public const PORTAL = 'https://app.kiddietrac.com';

    /** Everything the blocks need, for an agency (+ optional family/guardian to decide the registration note). */
    public static function context(?int $agencyId, ?int $familyId = null, ?int $guardianId = null): array
    {
        $a = $agencyId ? DB::table('agencies')->where('id', $agencyId)->first(['id', 'name', 'legal_name', 'slug', 'custom_domain', 'powered_by_visible', 'country']) : null;
        $white = $a && (int) ($a->powered_by_visible ?? 1) === 0;
        $name = trim((string) ($a->name ?? '')) ?: 'your childcare centre';
        $bs = ParentBilling::setup($agencyId);
        $accepted = (array) ($bs['accepted_payment_methods'] ?? ['stripe_card', 'interac', 'cash', 'cheque']);
        $pay = ParentBilling::payInfo($agencyId) ?: [];
        $us = in_array(strtoupper((string) ($a->country ?? '')), ['US', 'USA', 'UNITED STATES'], true);

        $web = self::PORTAL;
        if ($white) {
            $dom = trim((string) ($a->custom_domain ?? ''));
            $web = $dom !== '' ? 'https://' . preg_replace('#^https?://#i', '', rtrim($dom, '/'))
                : (trim((string) ($a->slug ?? '')) !== '' ? self::PORTAL . '/' . rawurlencode((string) $a->slug) . '/' : self::PORTAL);
        }

        return [
            'agency_id' => $agencyId,
            'agency_name' => $name,
            'payee' => trim((string) ($a->legal_name ?? '')) !== '' && stripos((string) $a->legal_name, 'demo') === false ? (string) $a->legal_name : $name,
            'white_label' => $white,
            'app_name' => $white ? $name . ' app' : 'KiddieTrac parent app',
            'web' => $web,
            'web_label' => preg_replace('#^https?://#', '', rtrim($web, '/')),
            /* Card / bank in the app: only when the agency switched "Credit/debit card (shown on invoices)" on in
               Billing setup AND a processor is really live (2026-10-31, Anthony: "only add the interac for now; if we
               enable credit/debit add this as a configuration setting"). */
            'online' => ! empty($bs['invoice_show_card']) ? self::onlineMethods($agencyId, $accepted, $us, ! empty($pay['demo_pay'])) : [],
            'interac' => $pay['interac'] ?? null,
            // Cheque and cash are not offered on invoices (2026-10-31, Anthony: "remove the cheque and cash").
            'cash' => false,
            'cheque' => false,
            'register' => self::needsRegistration($familyId, $guardianId),
        ];
    }

    /** Online methods that actually work for this agency today, e.g. ['Credit or debit card', 'Pre-authorized debit']. */
    private static function onlineMethods(?int $agencyId, array $accepted, bool $us, bool $demo): array
    {
        $live = $demo;
        if (! $live && $agencyId) {
            try {
                $live = StripeConfig::configured()
                    || (Helcim::configured($agencyId) && Helcim::production($agencyId))
                    || (ZumRails::configured($agencyId) && PaymentProviders::mode($agencyId, PaymentProviders::ZUM) === 'production');
            } catch (Throwable $e) {
                $live = false;
            }
        }
        if (! $live) {
            return [];
        }
        $out = [];
        if (in_array('stripe_card', $accepted, true)) $out[] = 'credit or debit card';
        if (in_array('stripe_ach', $accepted, true)) $out[] = $us ? 'bank account (ACH)' : 'pre-authorized debit';

        return $out;
    }

    /**
     * Show "Not registered yet?" - for one guardian: when their account isn't activated; for the family: when any
     * billing guardian's isn't. Unknown → false (never nag someone already in the app).
     */
    public static function needsRegistration(?int $familyId, ?int $guardianId): bool
    {
        try {
            $q = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id');
            if ($guardianId) {
                $q->where(fn ($w) => $w->where('g.id', $guardianId)->orWhere('g.user_id', $guardianId));
                if ($familyId) $q->where('g.family_id', $familyId);
            } elseif ($familyId) {
                $q->where('g.family_id', $familyId);
            } else {
                return false;
            }
            return $q->where(fn ($w) => $w->whereIn('u.status', ['invited', 'not_invited'])->orWhereNull('u.last_login_at'))->exists();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** "How to pay" lines as [title, detail-html]. $number goes in the e-Transfer message. */
    public static function methods(array $c, string $number): array
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $out = [];
        if ($c['online']) {
            $out[] = ['Credit or debit card',
                'Pay securely in the ' . $e($c['app_name']) . ': open <strong>Billing</strong>, choose this invoice and tap <strong>Pay now</strong> &mdash; by ' . $e(implode(' or ', $c['online'])) . '. Your receipt is saved for you.'];
        }
        if (! empty($c['interac']['email'])) {
            $out[] = ['Interac e-Transfer',
                /* 2026-11-03: an e-Transfer with no number in the message (Kicks-Ward) could not be matched, and the
                   family was sent "Overdue" for an invoice they had paid. Say plainly that the number goes in the message. */
                'Send to <strong>' . $e($c['interac']['email']) . '</strong>.'
                . ($number !== '' ? ' In the e-Transfer message, type <strong>' . $e($number) . '</strong> exactly as shown &mdash; that&rsquo;s how we match your payment to this invoice.' : '')
                . (! empty($c['interac']['autodeposit']) ? ' Autodeposit is on, so there is no security question.' : '')
                . (! empty($c['interac']['question']) ? ' Security question: <strong>' . $e($c['interac']['question']) . '</strong> &mdash; answer: <strong>' . $e($c['interac']['answer']) . '</strong>.' : '')
                . (! empty($c['interac']['instructions']) ? '<br>' . nl2br($e($c['interac']['instructions'])) : '')
                . (! empty($c['interac']['requests']) ? ' Prefer a request instead? Ask for one in the app under <strong>Billing</strong> and we\'ll send it to you.' : '')];
        }
        if ($c['cheque']) {
            $out[] = ['Cheque', 'Payable to <strong>' . $e($c['payee']) . '</strong>' . ($number !== '' ? ', with <strong>' . $e($number) . '</strong> on the memo line' : '') . '.'];
        }
        if ($c['cash']) {
            $out[] = ['Cash', 'In person &mdash; please ask for a receipt.'];
        }

        return $out;
    }

    /** [account-intro html, registration html]. */
    public static function appLines(array $c): array
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $app = $e($c['app_name']);
        $intro = 'Everything about your account lives in the ' . $app . '. Open <strong>Billing</strong> any time to see this invoice, your receipts and statements, and your full <strong>account ledger</strong>'
            . ($c['online'] ? ' &mdash; and to pay in a few taps.' : ', along with these payment instructions.');
        $reg = 'Haven\'t set up your ' . $app . ' yet? It\'s free and takes a minute. Follow your child\'s day as it happens &mdash; photos, daily reports, meals and naps, '
            . 'messages with educators, Safe Arrival alerts, menus and centre news &mdash; all in one place. Sign in with the email address this invoice was sent to; '
            . 'no invitation yet? Just ask ' . $e($c['agency_name']) . ' to send you one.';

        return [$intro, $reg];
    }

    /** Where to get the app, as HTML (App Store + Google Play badges only for the KiddieTrac app). */
    public static function getIt(array $c, bool $badge): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $web = '<a href="' . $e($c['web']) . '" style="color:inherit;font-weight:700;">' . $e($c['web_label']) . '</a>';
        if ($c['white_label']) {
            return 'Open it on any phone, tablet or computer at ' . $web . '.';
        }
        if ($badge) {
            return '<div style="margin:8px 0 6px;">' . PlayBadge::badges(34) . '</div>' . 'Any device: ' . $web;
        }

        return 'iPhone: <a href="' . PlayBadge::IOS_URL . '" style="color:inherit;font-weight:700;">App Store</a> &nbsp;·&nbsp; '
            . 'Android: <a href="' . PlayBadge::URL . '" style="color:inherit;font-weight:700;">Google Play</a> &nbsp;·&nbsp; Any device: ' . $web;
    }

    /** The PDF block (InvoiceDocument and SplitInvoiceCopy). dompdf-safe: tables, inline styles, no flex. */
    public static function pdfHtml(?int $agencyId, string $number, ?int $familyId, ?int $guardianId, float $balance): string
    {
        try {
            $c = self::context($agencyId, $familyId, $guardianId);
            $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            [$intro, $reg] = self::appLines($c);
            // Compact (2026-10-31): the whole block fits under the totals, so an invoice stays one page.
            $out = '<div class="kt-payinfo" style="max-width:720px;margin:12px auto 0;font-family:DejaVu Sans,Arial,sans-serif;font-size:9.5px;line-height:1.4;color:#334155;page-break-inside:avoid;">';
            $m = $balance > 0.004 ? self::methods($c, $number) : [];
            if ($m) {
                $out .= '<div style="border:1px solid #E2E8F0;border-radius:6px;padding:6px 10px;margin:0 0 6px;">'
                    . '<div style="font-size:10.5px;font-weight:bold;color:#0F172A;margin:0 0 2px;">How to pay</div><table style="width:100%;border-collapse:collapse;">';
                foreach ($m as [$t, $d]) {
                    $out .= '<tr><td style="padding:1px 8px 1px 0;vertical-align:top;white-space:nowrap;font-weight:bold;color:#0F172A;width:1%;">' . $e($t) . '</td><td style="padding:1px 0;vertical-align:top;">' . $d . '</td></tr>';
                }
                $out .= '</table></div>';
            }
            $out .= '<div style="background:#F1F5F9;border-radius:6px;padding:6px 10px;"><strong style="color:#0F172A;">Your account, anytime.</strong> ' . $intro;
            if ($c['register']) {
                $out .= '<div style="border-top:1px dashed #94A3B8;margin-top:5px;padding-top:5px;"><strong style="color:#0F172A;">Not registered yet?</strong> ' . $reg
                    . '<br><span style="color:#0F172A;">' . self::getIt($c, false) . '</span></div>';
            }
            $out .= '</div>';

            return $out . '</div>';
        } catch (Throwable $e) {
            report($e);

            return '';
        }
    }

    /** Plain-text version (for text parts / logs). */
    public static function text(?int $agencyId, string $number, ?int $familyId, ?int $guardianId, float $balance): string
    {
        try {
            $c = self::context($agencyId, $familyId, $guardianId);
            $lines = [];
            if ($balance > 0.004) {
                $m = self::methods($c, $number);
                if ($m) {
                    $lines[] = 'HOW TO PAY';
                    foreach ($m as [$t, $d]) $lines[] = '- ' . $t . ': ' . html_entity_decode(strip_tags($d), ENT_QUOTES, 'UTF-8');
                }
            }
            [$intro, $reg] = self::appLines($c);
            $lines[] = '';
            $lines[] = html_entity_decode(strip_tags($intro), ENT_QUOTES, 'UTF-8');
            if ($c['register']) {
                $lines[] = '';
                $lines[] = html_entity_decode(strip_tags($reg), ENT_QUOTES, 'UTF-8');
                $lines[] = html_entity_decode(strip_tags(self::getIt($c, false)), ENT_QUOTES, 'UTF-8');
            }

            return trim(implode("\n", $lines));
        } catch (Throwable $e) {
            return '';
        }
    }
}
