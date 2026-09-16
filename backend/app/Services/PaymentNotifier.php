<?php

namespace App\Services;

use App\Support\ZumRails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Telling people what happened to their money.
 *
 * Everything about a payment worked except the part where anybody found out. A
 * charge settled, the ledger was written, the invoice went to `partial` — and no
 * email, no notification, no push went anywhere. A parent had no receipt, and a
 * declined payment told NOBODY at all: the fee simply stayed unpaid and the office
 * discovered it whenever they next looked.
 *
 * Three audiences, deliberately on different channels:
 *
 *   PAID, to the payer          an email receipt. People expect one, and it is the
 *                               document they keep for a subsidy claim or their tax
 *                               return.
 *
 *   PAID, to the office         a NOTIFICATION, not an email. A centre taking forty
 *                               payments a day does not want forty emails, and the
 *                               money is already on the payments report. The bell is
 *                               the right weight for "this happened".
 *
 *   FAILED, to both             email, both ways. This one is exceptional, low
 *                               volume, and someone has to act: the family to fix
 *                               their card, the office to chase the fee. A bell
 *                               notification for a failed payment is a bell nobody
 *                               reads in time.
 *
 * Every path is wrapped so that notifying cannot break settling. The money is the
 * record; the message is a courtesy, and a courtesy must never roll back a payment.
 */
class PaymentNotifier
{
    /** A payment reached its end state. Called after the DB transaction commits. */
    public static function settled(object $txn, ?array $credited): void
    {
        try {
            if ($txn->direction !== 'in') {
                return;   // money going OUT is a payout; not this.
            }
            $ctx = self::context($txn);
            if (! $ctx) {
                return;
            }
            self::receiptToPayer($txn, $ctx, $credited);
            self::bellToOffice($txn, $ctx, $credited);
        } catch (\Throwable $e) {
            Log::warning('payment settled notify failed', ['txn' => $txn->id, 'error' => $e->getMessage()]);
        }
    }

    /** A payment failed. The one everybody needs to hear about. */
    public static function failed(object $txn, string $why = ''): void
    {
        try {
            if ($txn->direction !== 'in') {
                return;
            }
            $ctx = self::context($txn);
            if (! $ctx) {
                return;
            }
            self::failureToPayer($txn, $ctx, $why);
            self::failureToOffice($txn, $ctx, $why);
        } catch (\Throwable $e) {
            Log::warning('payment failed notify failed', ['txn' => $txn->id, 'error' => $e->getMessage()]);
        }
    }

    /* ── who and where ──────────────────────────────────────────────────────── */

    private static function context(object $txn): ?array
    {
        $user = DB::table('users')->where('id', $txn->user_id)->first();
        if (! $user) {
            return null;
        }

        $agencyId = (int) ($txn->agency_id ?: 0) ?: (int) (ZumRails::agencyOf((int) $txn->user_id) ?: 0);
        if (! $agencyId) {
            return null;
        }

        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $invoice = $txn->invoice_id ? DB::table('invoices')->where('id', $txn->invoice_id)->first() : null;

        /* The family's centre decides which directors hear about it — an agency admin
           hears about all of them, a director only about their own. */
        $centreId = $invoice
            ? DB::table('families')->where('id', $invoice->family_id)->value('centre_id')
            : null;

        return [
            'user' => $user,
            'agency_id' => $agencyId,
            'agency' => $agency,
            'agency_name' => trim((string) ($agency->name ?? '')) ?: 'Your childcare centre',
            'invoice' => $invoice,
            'centre_id' => $centreId,
        ];
    }

    /** Admins of the agency, plus directors of that centre. */
    private static function office(int $agencyId, $centreId): array
    {
        return DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', true)
            ->whereNull('u.deleted_at')
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('ra.agency_id', $agencyId)->where('ra.role', 'agency_admin'))
                ->orWhere(fn ($w) => $w->where('ra.centre_id', $centreId)->where('ra.role', 'centre_director')))
            ->get(['u.id', 'u.email', 'u.first_name'])
            ->unique('id')->values()->all();
    }

    private static function money($v): string
    {
        return '$' . number_format((float) $v, 2);
    }

    /** "Mastercard ending 0077", or just the rail when there is no card. */
    private static function how(object $txn): string
    {
        if ($txn->method !== 'CreditCard') {
            return $txn->method === 'Eft' ? 'direct debit' : 'Interac e-Transfer';
        }
        try {
            $on = ZumRails::methodsOnFile((int) $txn->user_id);
            if (! empty($on['card_hint'])) {
                $brand = ! empty($on['card_brand']) && function_exists('ucfirst')
                    ? ucfirst((string) $on['card_brand']) : 'Card';
                return $brand . ' ending ' . $on['card_hint'];
            }
        } catch (\Throwable $e) { /* the receipt is worth more than the brand name */ }

        return 'card';
    }

    /* ── the messages ───────────────────────────────────────────────────────── */

    private static function receiptToPayer(object $txn, array $ctx, ?array $credited): void
    {
        $to = trim((string) ($ctx['user']->email ?? ''));
        if ($to === '') {
            return;
        }

        $name = trim((string) ($ctx['user']->preferred_name ?: $ctx['user']->first_name ?? ''));
        $inv = $ctx['invoice'];
        $p = fn ($t) => '<p style="margin:0 0 12px;font-size:15px;line-height:1.6;color:#2A3D5F;">' . $t . '</p>';

        $rows = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:6px 0 0;font-size:14px;color:#2A3D5F;">'
            . self::row('Amount', '<strong>' . self::money($txn->amount) . '</strong>')
            . self::row('Paid with', e(self::how($txn)))
            . self::row('Date', now()->format('j M Y, g:i A'))
            . self::row('Reference', 'ZUM-' . substr((string) $txn->zum_transaction_id, 0, 8));
        if ($inv) {
            $rows .= self::row('Invoice', e((string) ($inv->invoice_number ?: $inv->id)));
        }
        if ($credited && isset($credited['balance_now'])) {
            $rows .= self::row('Still outstanding', $credited['balance_now'] > 0.005
                ? '<strong>' . self::money($credited['balance_now']) . '</strong>'
                : 'Nothing — this invoice is paid in full');
        }
        $rows .= '</table>';

        $body = $p('Hello ' . e($name) . ',')
            . $p('Thank you — your payment has gone through. Here are the details for your records.')
            . $rows
            . ($credited && ($credited['balance_now'] ?? 0) > 0.005
                ? $p('<span style="color:#92400E;">This was a part payment, so '
                    . self::money($credited['balance_now']) . ' is still outstanding on this invoice.</span>')
                : '')
            . $p('You can see all your invoices and payments any time in your portal.')
            . '<p style="margin:22px 0 0;"><a href="https://app.kiddietrac.com/dashboard.html#billing" '
            . 'style="display:inline-block;background:#1F6FB2;color:#fff;text-decoration:none;font-weight:700;'
            . 'font-size:15px;padding:13px 26px;border-radius:10px;">View your billing</a></p>'
            . '<p style="margin:26px 0 0;font-size:15px;line-height:1.6;color:#2A3D5F;">Kind regards,<br>'
            . '<strong style="color:#0B1A33;">' . e($ctx['agency_name']) . ' Team</strong></p>';

        self::send($ctx['agency_id'], $to, 'Payment received — ' . self::money($txn->amount), $body, [
            'eyebrow' => 'PAYMENT RECEIPT',
            'title' => 'Thank you — payment received',
            'subtitle' => self::money($txn->amount) . ' · ' . self::how($txn),
            'preheader' => 'Your payment of ' . self::money($txn->amount) . ' has gone through.',
        ]);
    }

    private static function bellToOffice(object $txn, array $ctx, ?array $credited): void
    {
        $who = trim(($ctx['user']->first_name ?? '') . ' ' . ($ctx['user']->last_name ?? '')) ?: 'A family';
        $inv = $ctx['invoice'];
        $title = '💳 ' . self::money($txn->amount) . ' received';
        $body = $who . ' paid ' . self::money($txn->amount) . ' by ' . self::how($txn)
            . ($inv ? ' against ' . ($inv->invoice_number ?: 'invoice ' . $inv->id) : '')
            . (($credited['balance_now'] ?? 0) > 0.005
                ? '. ' . self::money($credited['balance_now']) . ' still outstanding.' : '.');

        foreach (self::office($ctx['agency_id'], $ctx['centre_id']) as $s) {
            try {
                \App\Support\Notify::write([
                    'user_id' => $s->id,
                    'type' => 'payment',
                    'title' => $title,
                    'body' => $body,
                    'data' => json_encode(['link' => '#billing', 'invoice_id' => $inv->id ?? null]),
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) { /* one recipient's bell is not worth the rest */ }
        }
    }

    private static function failureToPayer(object $txn, array $ctx, string $why): void
    {
        $to = trim((string) ($ctx['user']->email ?? ''));
        if ($to === '') {
            return;
        }
        $name = trim((string) ($ctx['user']->preferred_name ?: $ctx['user']->first_name ?? ''));
        $inv = $ctx['invoice'];
        $p = fn ($t) => '<p style="margin:0 0 12px;font-size:15px;line-height:1.6;color:#2A3D5F;">' . $t . '</p>';

        $body = $p('Hello ' . e($name) . ',')
            . $p('Your payment of <strong>' . self::money($txn->amount) . '</strong> did not go through, so '
                . 'nothing has been taken from your ' . e(self::how($txn)) . '.')
            . ($inv ? $p('Invoice <strong>' . e((string) ($inv->invoice_number ?: $inv->id))
                . '</strong> is still outstanding.') : '')
            . $p('This usually means the card has expired, has been replaced, or the bank declined it. '
                . 'Adding a different card in your portal is the quickest fix.')
            . '<p style="margin:22px 0 0;"><a href="https://app.kiddietrac.com/dashboard.html#billing" '
            . 'style="display:inline-block;background:#1F6FB2;color:#fff;text-decoration:none;font-weight:700;'
            . 'font-size:15px;padding:13px 26px;border-radius:10px;">Update your payment method</a></p>'
            . $p('<span style="font-size:13.5px;color:#64748B;">If you think this is a mistake, please contact '
                . e($ctx['agency_name']) . ' — we would rather sort it out with you than have it sit unpaid.</span>')
            . '<p style="margin:26px 0 0;font-size:15px;line-height:1.6;color:#2A3D5F;">Kind regards,<br>'
            . '<strong style="color:#0B1A33;">' . e($ctx['agency_name']) . ' Team</strong></p>';

        self::send($ctx['agency_id'], $to, 'Your payment did not go through', $body, [
            'eyebrow' => 'PAYMENT UNSUCCESSFUL',
            'title' => 'That payment did not go through',
            'subtitle' => self::money($txn->amount) . ' · nothing has been taken',
            'preheader' => 'Your payment of ' . self::money($txn->amount) . ' was not completed.',
        ]);
    }

    private static function failureToOffice(object $txn, array $ctx, string $why): void
    {
        $who = trim(($ctx['user']->first_name ?? '') . ' ' . ($ctx['user']->last_name ?? '')) ?: 'A family';
        $inv = $ctx['invoice'];
        $p = fn ($t) => '<p style="margin:0 0 12px;font-size:15px;line-height:1.6;color:#2A3D5F;">' . $t . '</p>';

        $body = $p('<strong>' . e($who) . '</strong> tried to pay <strong>' . self::money($txn->amount)
                . '</strong> by ' . e(self::how($txn)) . ' and it did not go through. '
                . 'No money has moved.')
            . ($inv ? $p('Invoice <strong>' . e((string) ($inv->invoice_number ?: $inv->id))
                . '</strong> is still outstanding.') : '')
            . ($why !== '' ? $p('<span style="color:#64748B;font-size:13.5px;">Provider said: '
                . e($why) . '</span>') : '')
            . $p('The family has been emailed and asked to update their payment method. '
                . 'This is here so nobody has to notice it on a report next month.')
            . '<p style="margin:22px 0 0;"><a href="https://app.kiddietrac.com/dashboard.html#billing" '
            . 'style="display:inline-block;background:#1F6FB2;color:#fff;text-decoration:none;font-weight:700;'
            . 'font-size:15px;padding:13px 26px;border-radius:10px;">Open billing</a></p>';

        foreach (self::office($ctx['agency_id'], $ctx['centre_id']) as $s) {
            $to = trim((string) ($s->email ?? ''));
            if ($to === '') {
                continue;
            }
            self::send($ctx['agency_id'], $to, 'Payment failed — ' . $who . ' · ' . self::money($txn->amount), $body, [
                'eyebrow' => 'ACTION NEEDED',
                'title' => 'A payment did not go through',
                'subtitle' => $who . ' · ' . self::money($txn->amount),
                'preheader' => $who . '’s payment of ' . self::money($txn->amount) . ' failed.',
            ]);

            try {
                \App\Support\Notify::write([
                    'user_id' => $s->id,
                    'type' => 'payment',
                    'title' => '⚠️ Payment failed — ' . self::money($txn->amount),
                    'body' => $who . '’s payment did not go through. The invoice is still outstanding.',
                    'data' => json_encode(['link' => '#billing', 'invoice_id' => $inv->id ?? null]),
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) { /* the email is the record */ }
        }
    }

    /* ── plumbing ───────────────────────────────────────────────────────────── */

    private static function row(string $k, string $v): string
    {
        return '<tr><td style="padding:3px 14px 3px 0;color:#64748B;white-space:nowrap;">' . e($k)
            . '</td><td style="padding:3px 0;">' . $v . '</td></tr>';
    }

    private static function send(int $agencyId, string $to, string $subject, string $body, array $opts): void
    {
        try {
            $html = EmailTemplate::wrap($agencyId, $body, $opts);
            AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $subject) {
                $m->from(config('mail.from.address', 'noreply@kiddietrac.com'),
                         config('mail.from.name', 'KiddieTrac'));
                $m->to($to)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::warning('payment email failed', ['to' => $to, 'error' => $e->getMessage()]);
        }
    }
}
