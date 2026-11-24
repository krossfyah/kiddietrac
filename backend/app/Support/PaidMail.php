<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\InvoiceDocument;
use App\Services\PaymentReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * THE PAYER HEARS ABOUT THEIR PAYMENT (2026-10-31, Anthony: "once the invoices are paid you need to send out the
 * invoices with watermark paid and receipt with the new receipt format").
 *
 * Every recorded payment (manual "Record payment", an e-Transfer request marked paid, Helcim, Stripe autopay) sends
 * its payer ONE email:
 *   - the receipt as a PDF (App\Services\PaymentReceipt - the till-roll receipt with the agency letterhead and barcode);
 *   - when that payment settles the invoice (or the payer's share of a split invoice), the invoice itself as a PDF
 *     stamped PAID with the date.
 * A part payment gets the receipt only; its invoice still shows a balance.
 *
 * The payer is the guardian named on the payment; an unattributed payment goes to the family's billing guardians
 * (InvoiceReminders::guardians - the same people invoices go to). One email per payment per address
 * (payment_paid_mails). Agency email switched off = nothing. Never throws: the money is the record, the email is a
 * courtesy.
 */
final class PaidMail
{
    /**
     * "Invoice X paid in full" on the bell of every guardian of the family, plus an acknowledgement to the staff member
     * who recorded it (none for a payment a partner system recorded). Moved here unchanged from
     * InvoiceController::recordPayment (2026-11-02) so ExternalPaymentSync sends the very same notice.
     */
    public static function paidInFullNotice(object $invoice, int $invoiceId, ?int $recorderUserId): void
    {
        $guardianIds = DB::table('guardians')->where('family_id', $invoice->family_id)->pluck('user_id')->all();
        $rows = [];
        $title = 'Invoice ' . $invoice->invoice_number . ' paid in full';
        $bodyPreview = '$' . number_format((float) $invoice->total, 2) . ' · receipt available in your billing tab';
        $now = now();
        foreach ($guardianIds as $gid) {
            $rows[] = [
                'user_id' => (int) $gid,
                'type' => 'payment',
                'title' => $title,
                'body' => $bodyPreview,
                'data' => json_encode([
                    'url' => '/dashboard.html#billing',
                    'invoice_id' => (int) $invoiceId,
                ]),
                'created_at' => $now,
            ];
        }
        if ($recorderUserId) {
            // Also notify the staff member who recorded the payment
            $rows[] = [
                'user_id' => (int) $recorderUserId,
                'type' => 'invoice',
                'title' => 'Payment recorded · ' . $invoice->invoice_number,
                'body' => 'Marked paid in full. ' . $bodyPreview,
                'data' => json_encode([
                    'url' => '/dashboard.html#admin-billing',
                    'invoice_id' => (int) $invoiceId,
                ]),
                'created_at' => $now,
            ];
        }
        if (! empty($rows)) Notify::write($rows);
    }

    /** Call after the payment row is committed. */
    public static function afterPayment(int $paymentId): void
    {
        try {
            self::send($paymentId, null);
        } catch (Throwable $e) {
            Log::warning('paid mail failed', ['payment' => $paymentId, 'error' => $e->getMessage()]);
        }
    }

    /** The newest succeeded payment on a native or external invoice (for callers that insert without an id). */
    public static function afterLatest(?int $invoiceId, ?int $externalInvoiceId = null): void
    {
        try {
            $q = DB::table('payments')->where('status', 'succeeded');
            $invoiceId ? $q->where('invoice_id', $invoiceId) : $q->where('external_invoice_id', (int) $externalInvoiceId);
            $id = $q->max('id');
            if ($id) self::afterPayment((int) $id);
        } catch (Throwable $e) {
            Log::warning('paid mail (latest) failed', ['invoice' => $invoiceId, 'external' => $externalInvoiceId, 'error' => $e->getMessage()]);
        }
    }

    /* ── WHEN THE MONEY DID NOT MOVE, OR CAME BACK (2026-11-06) ──────────────────────────────────────────────────

       Anthony's billing sweep: a failed auto-pay told the office (PaymentAlerts) and, only when Stripe threw, put a bell
       notice on one guardian; a refund - staff-made or the automatic overpay refund - was a bell notice only. Somebody
       who never opens the app heard nothing about their own money. Both now email the payer (the guardian on the
       charge, else the billing guardians), billing-stamped so an invite not yet accepted does not stop them. */

    /** Auto-pay could not take an invoice. $c is an AutopayRunner candidate; $why is Stripe's status or message. Once a day per invoice per address. */
    public static function failed(object $c, string $why): void
    {
        try {
            $aid = (int) ($c->agency_id ?? 0);
            if (! $aid || ! Suppression::agencyNotificationsEnabled($aid)) return;
            $inv = DB::table('invoices')->where('id', $c->id)->first();
            if (! $inv) return;
            $people = InvoiceReminders::guardians($inv, 'native');
            if (! empty($c->payer_guardian_id)) {
                $named = array_values(array_filter($people, fn ($g) => (int) $g['guardian_id'] === (int) $c->payer_guardian_id));
                if ($named) $people = $named;
            }
            if (! $people) return;
            $tz = DB::table('agencies')->where('id', $aid)->value('timezone') ?: 'America/Toronto';
            $today = now($tz)->toDateString();
            $brand = (string) ($c->autopay_card_brand ?? '');
            $brand = ['visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'Amex', 'discover' => 'Discover'][strtolower($brand)] ?? ($brand !== '' ? ucfirst($brand) : '');
            $how = trim(($brand !== '' ? $brand : 'saved card') . (! empty($c->autopay_card_last4) ? ' ending ' . $c->autopay_card_last4 : ''));
            // The bank wants the parent to approve it themselves: nothing is wrong with the card, so say how to pay instead.
            $draft = preg_match('/requires_action|authenticat/i', $why)
                ? ['advice' => 'Your bank asked for this payment to be approved by you, which auto-pay cannot do. Open your billing page and pay the invoice there - it only takes a moment.']
                : null;
            $agency = (string) (DB::table('agencies')->where('id', $aid)->value('name') ?: 'your childcare provider');
            $am = \App\Services\AgencyMailer::forAgency($aid);
            foreach ($people as $g) {
                $to = (string) $g['email'];
                if ($to === '' || InvoiceReminders::bounced($to)) continue;
                if (DB::table('invoice_reminder_log')->where('invoice_kind', 'native')->where('invoice_id', $inv->id)
                    ->where('recipient', $to)->where('reason', 'payfailed')->where('sent_on', $today)->exists()) continue;
                $mail = TemplatedEmails::paymentFailed($aid, ['name' => (string) ($g['first_name'] ?: 'there'),
                    'amount' => '$' . number_format((float) ($g['pct'] !== null ? $g['balance'] : $c->balance_due), 2), 'how' => $how,
                    'invoice_number' => (string) $c->invoice_number, 'agency_name' => $agency], $draft);
                $am->html($mail['html'], function ($m) use ($to, $g, $mail, $c) {
                    MailScope::billing($m);
                    $m->to($to, $g['name'] ?: null)->subject($mail['subject'] . ' — invoice ' . $c->invoice_number);
                });
                InvoiceReminders::log($aid, 'native', (int) $inv->id, $to, $g['guardian_id'], 'payfailed', 0, $today, [], null);
            }
        } catch (Throwable $e) {
            Log::warning('payment failed email failed', ['invoice' => $c->id ?? null, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * Money given back on a payment. $status is the refund's: 'succeeded' / 'pending' (on its way to the card) or
     * 'manual' (approved; the centre pays it by other means). $reason is shown to the family as written, or nothing.
     */
    public static function refunded(int $paymentId, float $amount, ?string $reason, string $status): void
    {
        try {
            $p = DB::table('payments')->where('id', $paymentId)->first();
            $f = $p ? PaymentReceipt::facts($paymentId) : null;
            if (! $p || ! $f || $amount < 0.01) return;
            $aid = (int) $f['agency_id'];
            if (! Suppression::agencyNotificationsEnabled($aid)) return;
            $native = (bool) $p->invoice_id;
            $inv = $native ? DB::table('invoices')->where('id', $p->invoice_id)->first()
                : DB::table('external_invoices')->where('id', $p->external_invoice_id)->first();
            if (! $inv) return;
            $people = InvoiceReminders::guardians($inv, $native ? 'native' : 'external');
            if ($p->payer_guardian_id) {
                $named = array_values(array_filter($people, fn ($g) => (int) $g['guardian_id'] === (int) $p->payer_guardian_id));
                if ($named) $people = $named;
            }
            if (! $people) return;
            $money = InvoiceMail::money($amount);
            $number = (string) ($f['number'] ?? '');
            $method = trim((string) ($f['method'] ?? '')) ?: 'card';
            if (strcasecmp($method, 'card') === 0) $method = 'card';   // "to your card", but "to your Apple Pay"
            $back = in_array($status, ['succeeded', 'pending'], true);
            $lead = $back
                ? 'We have refunded <strong>' . e($money) . '</strong> to your ' . e($method)
                    . '. Banks usually take 5 to 10 business days to show it on your statement.'
                : 'A refund of <strong>' . e($money) . '</strong> has been approved. <strong>' . e($f['agency']) . '</strong> will arrange the payment with you.';
            $rows = [['Refund', e($money)], ['Original payment', e((string) $f['amount']) . ' &middot; ' . e((string) $f['when'])], ['Receipt', e((string) $f['receipt_no'])]];
            if ($number !== '') $rows[] = ['Invoice', e($number)];
            $why = trim((string) $reason);
            $am = \App\Services\AgencyMailer::forAgency($aid);
            foreach ($people as $g) {
                $to = (string) $g['email'];
                if ($to === '' || InvoiceReminders::bounced($to)) continue;
                $body = '<p style="margin:0 0 8px;">Hi ' . e((string) ($g['first_name'] ?: 'there')) . ',</p>'
                    . '<p style="margin:0;">' . $lead . '</p>'
                    . ($why !== '' ? '<p style="margin:12px 0 0;"><strong>Reason:</strong> ' . e($why) . '</p>' : '')
                    . InvoiceMail::details($rows)
                    . '<p class="kt-muted" style="font-size:13px;color:#64748B;margin:0;">Questions about this refund? Reply to this email or contact '
                    . e($f['agency']) . '.</p>';
                $html = \App\Services\EmailTemplate::wrap($aid, $body, [
                    'eyebrow' => 'REFUND',
                    'title' => $back ? 'Your refund is on its way' : 'Your refund has been approved',
                    'subtitle' => $f['agency'],
                    'preheader' => ($back ? $money . ' is on its way back to you' : 'A refund of ' . $money . ' has been approved') . ' from ' . $f['agency'] . '.',
                    'invoice_hero' => InvoiceMail::hero('refund', $number, $money, $back ? 'back to your ' . $method : 'approved',
                        ! empty($inv->centre_id) ? (int) $inv->centre_id : null),
                ]);
                $subject = ($back ? 'Refund of ' . $money : 'Refund approved: ' . $money) . ($number !== '' ? ' — invoice ' . $number : '');
                $am->html($html, function ($m) use ($to, $g, $subject) {
                    MailScope::billing($m);
                    $m->to($to, $g['name'] ?: null)->subject($subject);
                });
            }
        } catch (Throwable $e) {
            Log::warning('refund email failed', ['payment' => $paymentId, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * Build (and, unless $dryTo === '', send) the email for one payment. $testTo sends to that one address instead of
     * the family, with "[TEST]" in the subject, and is not recorded as the payer's email.
     *
     * @return list<array{to:string,subject:string,attachments:array}>
     */
    public static function send(int $paymentId, ?string $testTo, bool $buildOnly = false): array
    {
        $p = DB::table('payments')->where('id', $paymentId)->first();
        if (! $p || (string) $p->status !== 'succeeded' || (float) $p->amount <= 0.004) return [];
        $f = PaymentReceipt::facts($paymentId);
        if (! $f) return [];
        $agencyId = (int) $f['agency_id'];
        if ($testTo === null && ! Suppression::agencyNotificationsEnabled($agencyId)) return [];

        $native = (bool) $p->invoice_id;
        $inv = $native ? DB::table('invoices')->where('id', $p->invoice_id)->first()
            : DB::table('external_invoices')->where('id', $p->external_invoice_id)->first();
        if (! $inv) return [];
        $kind = $native ? 'native' : 'external';

        // Who: the payer named on the payment, else the family's billing guardians.
        $people = InvoiceReminders::guardians($inv, $kind);
        if ($p->payer_guardian_id) {
            $named = array_values(array_filter($people, fn ($g) => (int) $g['guardian_id'] === (int) $p->payer_guardian_id));
            if ($named) $people = $named;
        }
        if ($testTo !== null) {
            $people = $people ? [$people[0]] : [['guardian_id' => null, 'first_name' => 'Jordan', 'name' => 'Jordan', 'email' => $testTo, 'balance' => (float) ($inv->balance_due ?? 0), 'pct' => null]];
        }
        if (! $people) return [];

        $number = (string) ($f['number'] ?? '');
        $receiptPdf = PaymentReceipt::pdf($paymentId);
        $out = [];
        foreach ($people as $g) {
            $to = $testTo ?? (string) $g['email'];
            if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
            if ($testTo === null) {
                if (InvoiceReminders::bounced($to)) continue;
                if (DB::table('payment_paid_mails')->where('payment_id', $paymentId)->where('email', $to)->exists()) continue;
            }
            $share = $native && $g['pct'] !== null && (float) $g['pct'] < 99.99;
            $balance = (float) ($g['balance'] ?? ($inv->balance_due ?? 0));
            $settled = $share ? $balance <= 0.004 : ($native ? InvoiceDocument::isSettled((int) $inv->id)
                : ((float) ($inv->balance_due ?? 1) <= 0.004 && (float) ($inv->amount_paid ?? 0) > 0.004));

            $attach = [];
            if ($receiptPdf) $attach[] = ['data' => $receiptPdf, 'name' => 'Receipt ' . $f['receipt_no'] . '.pdf'];
            if ($settled) {
                $paidOn = $p->paid_at ? substr((string) $p->paid_at, 0, 10) : null;
                $pdf = self::paidInvoicePdf($inv, $kind, $share ? (int) $g['guardian_id'] : null, $paidOn);
                if ($pdf) $attach[] = ['data' => $pdf, 'name' => 'Invoice ' . self::fileSafe($number) . ' - PAID.pdf'];
            }

            $note = ($receiptPdf ? 'Your receipt is attached as a PDF' : 'Your receipt is in the app under Billing')
                . ($settled ? ($attach && count($attach) > 1 ? ', along with your invoice marked <strong>PAID</strong>.' : '. Your invoice is now paid in full.') : '.')
                . ' Keep them for your records.';
            $mail = TemplatedEmails::paymentReceipt($agencyId, [
                'name' => (string) ($g['first_name'] ?: 'there'),
                'amount' => $f['amount'],
                'how' => $f['method'],
                'payment_method' => $f['method'],
                'date' => $f['when'],
                'reference' => $f['receipt_no'] . ($f['reference'] !== '' ? ' · ' . $f['reference'] : ''),
                'invoice_number' => $number,
                'balance_now' => $settled ? 0.0 : $balance,
                'balance_text' => '$' . number_format($balance, 2) . ($share ? ' (your share)' : ''),
                'agency_name' => $f['agency'],
                'centre_id' => $inv->centre_id ?? null,
                'attach_note' => $note,
                'paid_in_full' => $settled,
            ]);
            $subject = ($testTo !== null ? '[TEST] ' : '') . ($settled ? 'Paid in full — receipt for invoice ' . $number : $mail['subject']);

            if ($buildOnly) { $out[] = ['to' => $to, 'subject' => $subject, 'html' => $mail['html'], 'attach' => $attach]; continue; }
            $send = function ($m) use ($to, $g, $subject, $attach, $testTo) {
                if ($testTo !== null) MailScope::platform($m); else MailScope::billing($m);
                $m->to($to, $testTo !== null ? null : ($g['name'] ?: null))->subject($subject);
                foreach ($attach as $a) $m->attachData($a['data'], $a['name'], ['mime' => 'application/pdf']);
            };
            if ($testTo !== null) {
                Mail::html($mail['html'], $send);
            } else {
                \App\Services\AgencyMailer::forAgency($agencyId)->html($mail['html'], $send);
                DB::table('payment_paid_mails')->insertOrIgnore(['payment_id' => $paymentId, 'email' => $to,
                    'settled' => $settled ? 1 : 0, 'attachments' => count($attach), 'created_at' => now()]);
            }
            $out[] = ['to' => $to, 'subject' => $subject, 'attachments' => array_map(fn ($a) => [$a['name'], strlen($a['data'])], $attach)];
        }
        return $out;
    }

    /** The invoice PDF stamped PAID: the guardian's own copy for a split share, the agency's document otherwise. */
    public static function paidInvoicePdf(object $inv, string $kind, ?int $guardianId, ?string $paidOn): ?string
    {
        try {
            if ($kind === 'external') return \App\Http\Controllers\Api\InvoiceController::externalPdfFor($inv, true, $paidOn);
            if ($guardianId) {
                $html = SplitInvoiceCopy::html((int) $inv->id, $guardianId);
                if (! $html) return null;
                return self::render(InvoiceDocument::paidStamp($html, $paidOn));
            }
            return InvoiceDocument::pdf((int) $inv->id);      // stamps PAID itself once settled
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    /** The invoice PDF for an unpaid invoice email (reminders): the guardian's copy when split, else the document. */
    public static function invoicePdf(object $inv, string $kind, ?int $guardianId): ?string
    {
        try {
            if ($kind === 'external') return \App\Http\Controllers\Api\InvoiceController::externalPdfFor($inv, false, null);
            if ($guardianId) return SplitInvoiceCopy::pdf((int) $inv->id, $guardianId);
            return InvoiceDocument::pdf((int) $inv->id);
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    public static function fileSafe(string $s): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $s), '-') ?: 'invoice';
    }

    private static function render(string $html): ?string
    {
        $dompdf = new KtDompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        $out = $dompdf->output();
        return (is_string($out) && str_starts_with($out, '%PDF')) ? $out : null;
    }
}
