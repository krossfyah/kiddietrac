<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PlatformInvoicePdf;
use App\Services\PlatformInvoiceRaiser;
use App\Support\PlatformBilling;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The nightly billing run (2026-08-25).
 *
 * Raises whatever is due and — if enabled — issues and emails it. Anthony asked for
 * raise-and-send on a daily cron.
 *
 * IT SHIPS DISABLED. Two switches in platform_settings, both false:
 *
 *   billing.auto_raise   raise drafts for agencies whose date has arrived
 *   billing.auto_email   issue and email what was raised
 *
 * auto_email is meaningless without auto_raise, so it implies it.
 *
 * THE ISSUER GATE IS NOT A SWITCH. Even with auto_email true, this refuses to send while
 * invoice.issuer.address or invoice.issuer.tax_id is unset, because the PDF renders an
 * amber INCOMPLETE banner without them. A cron that mails a customer an invoice stamped
 * INCOMPLETE, unattended and daily, is worse than one that does nothing — so this is a
 * hard precondition rather than something a flag can override.
 *
 * The recipient is the agency's own contact_email. That is a real departure from
 * platform:send-invoice, which requires --to and refuses to guess: unattended sending has
 * nobody to confirm the address, so an agency with no usable contact is SKIPPED and left
 * as a draft rather than guessed at.
 */
class BillingRun extends Command
{
    protected $signature = 'platform:billing-run
        {--force-raise : raise even if billing.auto_raise is off (still never emails)}
        {--dry : report what would happen and write nothing}';

    protected $description = 'Nightly: raise due invoices and, if enabled, email them';

    public function handle(PlatformInvoiceRaiser $raiser): int
    {
        $dry = (bool) $this->option('dry');
        $setting = fn (string $k) => DB::table('platform_settings')->where('key', $k)->value('value');
        $on = fn (string $k) => in_array(strtolower((string) $setting($k)), ['1', 'true', 'yes', 'on'], true);

        $autoRaise = $on('billing.auto_raise') || (bool) $this->option('force-raise');
        $autoEmail = $on('billing.auto_email');

        if (! $autoRaise) {
            $this->line('billing.auto_raise is off — nothing to do.');

            return self::SUCCESS;
        }

        /* Hard precondition, deliberately not overridable by a flag. */
        $issuerReady = trim((string) $setting('invoice.issuer.address')) !== ''
            && trim((string) $setting('invoice.issuer.tax_id')) !== '';

        if ($autoEmail && ! $issuerReady) {
            $autoEmail = false;
            $this->warn('billing.auto_email is ON but invoice.issuer.address / tax_id are not set.');
            $this->warn('Refusing to email — the PDF would go out stamped INCOMPLETE. Raising drafts only.');
            Log::warning('platform:billing-run refused to auto-email: issuer details incomplete');
        }
        /* --force-raise is an operator poking the raiser by hand; it must never mail. */
        if ((bool) $this->option('force-raise') && ! $on('billing.auto_raise')) {
            $autoEmail = false;
        }

        $plan = $raiser->plan();
        $billable = $raiser->billable($plan);

        $this->line(($dry ? 'DRY ' : '') . 'billing run — ' . count($billable) . ' due, '
            . 'email ' . ($autoEmail ? 'ON' : 'off'));

        foreach ($billable as $p) {
            $this->line(sprintf('  %-26s %s', mb_substr((string) $p['agency_name'], 0, 26),
                PlatformBilling::money($p['amount_cents'], $p['currency'])));
        }

        if ($dry || ! $billable) {
            return self::SUCCESS;
        }

        $result = $raiser->commit($billable);
        $this->info(count($result['raised']) . ' draft(s) raised.');

        if (! $autoEmail || ! $result['raised']) {
            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($result['raised'] as $number) {
            $inv = DB::table('platform_invoices as pi')
                ->leftJoin('agencies as a', 'a.id', '=', 'pi.agency_id')
                ->where('pi.number', $number)
                ->first(['pi.*', 'a.name as agency_name', 'a.contact_email as agency_email']);

            $to = trim((string) ($inv->agency_email ?? ''));
            if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                /* Left as a draft on purpose. Unattended, there is nobody to confirm a
                   guessed address, and billing the wrong party is unrecoverable. */
                $this->warn('  ' . $number . ': no usable contact address — left as a draft.');
                continue;
            }

            try {
                $pdf = app(PlatformInvoicePdf::class)->render($number);
                if (! $pdf) {
                    $this->warn('  ' . $number . ': PDF failed to render — left as a draft.');
                    continue;
                }

                $money = PlatformBilling::money((int) $inv->amount_cents, $inv->currency);
                $body = '<p style="margin:0 0 14px;">Hello ' . e((string) $inv->agency_name) . ',</p>'
                    . '<p style="margin:0 0 14px;line-height:1.6;">Your invoice <strong>' . e($number)
                    . '</strong> for ' . e($money) . ' is attached'
                    . ($inv->due_at ? ', due ' . e(\Illuminate\Support\Carbon::parse($inv->due_at)->format('j M Y')) : '')
                    . '.</p>'
                    . '<p style="margin:16px 0 0;font-size:13px;color:#64748B;line-height:1.6;">'
                    . 'All major cards and EFT are accepted. Reply to this email with any questions.</p>';

                $html = \App\Services\EmailTemplate::wrap(null, $body, [
                    'eyebrow' => 'INVOICE',
                    'title' => 'Invoice ' . $number,
                    'subtitle' => $money,
                    'preheader' => 'Invoice ' . $number . ' for ' . $money . '.',
                ]);

                Mail::html($html, function ($m) use ($to, $number, $pdf) {
                    $m->to($to)
                      ->from('noreply@kiddietrac.com', 'KiddieTrac')
                      ->replyTo('sales@kiddietrac.com', 'KiddieTrac')
                      ->subject('Invoice ' . $number . ' from KiddieTrac');
                    $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                    $m->attachData($pdf, $number . '.pdf', ['mime' => 'application/pdf']);
                });

                DB::table('platform_invoices')->where('number', $number)->update([
                    'status' => 'issued',
                    'issued_at' => now(),
                    'sent_at' => now(),
                    'sent_to' => $to,
                    'updated_at' => now(),
                ]);
                $sent++;
                $this->line('  sent ' . $number . ' -> ' . $to);
            } catch (Throwable $e) {
                /* Stays a draft, so the next run retries rather than the invoice being
                   silently lost. */
                $this->warn('  ' . $number . ': send failed (' . $e->getMessage() . ') — left as a draft.');
                Log::error('platform:billing-run send failed for ' . $number, ['error' => $e->getMessage()]);
            }
        }

        $this->info($sent . ' invoice(s) emailed.');

        return self::SUCCESS;
    }
}
