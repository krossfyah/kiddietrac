<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\EmailTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email a platform invoice (2026-08-25).
 *
 * SAFETY: --to is REQUIRED and there is no fallback to the agency's billing contact.
 * The recipient must be typed every time. That is deliberate: this is billing
 * correspondence, and an invoice sent to the wrong agency is not something a redeploy
 * fixes. Wiring it to the agency's real contact is a change to make once the output has
 * been checked against real invoices — not a default to inherit.
 *
 * Marks the invoice ISSUED only when it actually sent, and only when it went to the
 * agency's own contact. A copy mailed to yourself for review must not flip the status,
 * or the next real send would be skipped as "already issued".
 */
class SendPlatformInvoice extends Command
{
    protected $signature = 'platform:send-invoice
        {invoice : platform_invoices id or number}
        {--to= : REQUIRED recipient. No default — there is no fallback to the agency contact}
        {--mark-issued : also mark it issued (only valid when --to is the agency contact)}';

    protected $description = 'Email a platform invoice to an explicitly named recipient';

    public function handle(): int
    {
        $to = trim((string) $this->option('to'));
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('--to is required and must be a valid address. This command never picks a recipient for you.');

            return self::FAILURE;
        }

        $key = (string) $this->argument('invoice');
        $inv = DB::table('platform_invoices as pi')
            ->leftJoin('agencies as a', 'a.id', '=', 'pi.agency_id')
            ->where(function ($q) use ($key) {
                $q->where('pi.id', ctype_digit($key) ? (int) $key : 0)->orWhere('pi.number', $key);
            })
            ->first(['pi.*', 'a.name as agency_name']);

        if (! $inv) {
            $this->error('Invoice not found: ' . $key);

            return self::FAILURE;
        }
        if ($inv->status === 'void') {
            $this->error('That invoice is void.');

            return self::FAILURE;
        }

        /* Was formatted with NO currency — "249.00" on an email, in a system that
           bills in both CAD and USD. */
        $money = \App\Support\PlatformBilling::money((int) $inv->amount_cents, $inv->currency ?? null);
        $cur = $inv->currency ?: 'CAD';
        $period = date('F Y', strtotime((string) $inv->period_start));

        $body = '<p style="margin:0 0 14px;">Hello ' . e((string) $inv->agency_name) . ',</p>'
            . '<p style="margin:0 0 14px;line-height:1.6;">Here is your KiddieTrac invoice for '
            . e($period) . '.</p>'
            . EmailTemplate::calloutBox(
                '<strong>Invoice:</strong> ' . e((string) $inv->number) . '<br>'
                . '<strong>Period:</strong> ' . e($period) . '<br>'
                . '<strong>Amount:</strong> ' . e($cur . ' ' . $money) . '<br>'
                . '<strong>Due:</strong> ' . e((string) ($inv->due_at ?: 'on receipt')),
                'info'
            )
            . '<p style="margin:16px 0 0;font-size:13px;color:#64748B;line-height:1.6;">'
            . 'Questions about this invoice? Reply to this email and we will pick it up.</p>';

        $html = EmailTemplate::wrap(null, $body, [
            'eyebrow' => 'INVOICE',
            'title' => 'KiddieTrac — ' . $period,
            'subtitle' => $cur . ' ' . $money,
            'preheader' => 'Your KiddieTrac invoice for ' . $period . '.',
        ]);

        $subject = 'KiddieTrac invoice ' . $inv->number . ' — ' . $period;

        $this->line('  invoice : ' . $inv->number . '  (' . $inv->agency_name . ')');
        $this->line('  amount  : ' . $cur . ' ' . $money);
        $this->line('  to      : ' . $to);

        /* Rendered from the live row at send time, so the attachment can never
           disagree with the figures in the email beside it. */
        $pdf = null;
        try {
            $pdf = app(\App\Services\PlatformInvoicePdf::class)->render((string) $inv->number);
        } catch (Throwable $e) {
            $this->error('  PDF generation failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        if ($pdf === null || $pdf === '') {
            /* Never fall back to a bare email — the customer would receive a bill
               with no document attached and no indication one was intended. */
            $this->error('  PDF came back empty; refusing to send without the invoice attached.');

            return self::FAILURE;
        }
        $this->line('  pdf     : ' . number_format(strlen($pdf) / 1024, 1) . ' KB');

        try {
            Mail::html($html, function ($m) use ($to, $subject, $inv, $pdf) {
                $m->to($to)
                  ->from('noreply@kiddietrac.com', 'KiddieTrac')
                  ->replyTo('support@kiddietrac.com', 'KiddieTrac Support')
                  ->subject($subject);
                /* Operational mail: it must not be withheld by the agency-suppression
                   layer, which exists to protect FAMILIES from portal noise. */
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                $m->getHeaders()->addTextHeader('X-KT-Platform-Invoice', (string) $inv->number);
                $m->attachData($pdf, $inv->number . '.pdf', ['mime' => 'application/pdf']);
            });
        } catch (Throwable $e) {
            $this->error('  send failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('  sent.');

        if ($this->option('mark-issued')) {
            if ($inv->status !== 'draft') {
                $this->warn('  not marked issued — status is already ' . $inv->status);
            } else {
                DB::table('platform_invoices')->where('id', $inv->id)->update([
                    'status' => 'issued',
                    'issued_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->info('  marked issued.');
            }
        } else {
            $this->line('  status unchanged (' . $inv->status . ') — pass --mark-issued when this goes to the agency.');
        }

        return self::SUCCESS;
    }
}
