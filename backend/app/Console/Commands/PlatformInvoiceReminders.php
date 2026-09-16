<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\EmailTemplate;
use App\Services\PlatformInvoicePdf;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Chase overdue platform invoices (2026-08-25).
 *
 * DRY RUN BY DEFAULT and deliberately NOT scheduled, matching platform:raise-invoices.
 * A reminder run that starts chasing customers the moment it deploys is not something to
 * switch on by accident.
 *
 * --to redirects every reminder to one address for review. Without it, reminders go to
 * each agency's own contact — so the redirect is how this gets tested without a customer
 * receiving anything.
 *
 * Only ISSUED invoices are chased. A draft has never been sent, so chasing payment for it
 * would be asking for money against a document the customer has never seen.
 *
 * A cadence guard stops the same invoice being chased daily: reminders go out at 1, 7,
 * 14 and 30 days overdue and then stop. Fifty identical emails is how a sender ends up
 * in a spam folder, and it is not more likely to get the invoice paid.
 */
class PlatformInvoiceReminders extends Command
{
    protected $signature = 'platform:invoice-reminders
        {--commit : actually send (default is a dry run)}
        {--to= : redirect every reminder to this address for review}
        {--force : ignore the cadence guard}';

    protected $description = 'Email a reminder for each overdue platform invoice';

    /** Days overdue at which a reminder is sent. Silence between and after. */
    private const CADENCE = [1, 7, 14, 30];

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $redirect = trim((string) $this->option('to'));
        $force = (bool) $this->option('force');

        if ($redirect !== '' && ! filter_var($redirect, FILTER_VALIDATE_EMAIL)) {
            $this->error('--to must be a valid address.');

            return self::FAILURE;
        }

        $today = Carbon::now()->startOfDay();

        $rows = DB::table('platform_invoices as pi')
            ->leftJoin('agencies as a', 'a.id', '=', 'pi.agency_id')
            /* Issued only. Chasing a draft asks for money against a document the
               customer has never received. */
            ->where('pi.status', 'issued')
            ->whereNotNull('pi.due_at')
            ->whereDate('pi.due_at', '<', $today->toDateString())
            ->orderBy('pi.due_at')
            ->get([
                'pi.id', 'pi.number', 'pi.due_at', 'pi.amount_cents', 'pi.amount_paid_cents',
                'pi.currency', 'a.name as agency_name', 'a.contact_email as agency_email',
            ]);

        $this->line(($commit ? 'COMMIT' : 'DRY RUN')
            . ($redirect !== '' ? ' — ALL redirected to ' . $redirect : '')
            . ' — ' . $rows->count() . ' overdue invoice(s)');
        $this->line('');

        $sent = 0;
        $skipped = 0;

        foreach ($rows as $r) {
            $days = (int) $today->diffInDays(Carbon::parse($r->due_at)->startOfDay());
            $due = (int) $r->amount_cents - (int) $r->amount_paid_cents;
            $name = mb_substr((string) $r->agency_name, 0, 24);

            if ($due <= 0) {
                $this->line(sprintf('  skip  %-26s %s  nothing outstanding', $name, $r->number));
                $skipped++;
                continue;
            }
            if (! $force && ! in_array($days, self::CADENCE, true)) {
                $this->line(sprintf('  skip  %-26s %s  %dd overdue, not a reminder day', $name, $r->number, $days));
                $skipped++;
                continue;
            }

            $to = $redirect !== '' ? $redirect : trim((string) $r->agency_email);
            if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $this->warn(sprintf('  skip  %-26s %s  no usable contact address', $name, $r->number));
                $skipped++;
                continue;
            }

            $money = \App\Support\PlatformBilling::money($due, $r->currency);
            $this->line(sprintf('  send  %-26s %s  %dd overdue  %s  -> %s', $name, $r->number, $days, $money, $to));

            if (! $commit) {
                $sent++;
                continue;
            }

            try {
                $pdf = app(PlatformInvoicePdf::class)->render((string) $r->number);

                $body = '<p style="margin:0 0 14px;">Hello ' . e((string) $r->agency_name) . ',</p>'
                    . '<p style="margin:0 0 14px;line-height:1.6;">Invoice <strong>' . e((string) $r->number)
                    . '</strong> was due on ' . e(Carbon::parse($r->due_at)->format('j M Y'))
                    . ' and is still showing as unpaid.</p>'
                    . EmailTemplate::calloutBox(
                        '<strong>Outstanding:</strong> ' . e($money) . '<br>'
                        . '<strong>Days overdue:</strong> ' . $days,
                        'warning'
                    )
                    . '<p style="margin:16px 0 0;font-size:13px;color:#64748B;line-height:1.6;">'
                    . 'If this has already been paid, please ignore this note — or reply with the '
                    . 'payment reference and we will match it up.</p>';

                $html = EmailTemplate::wrap(null, $body, [
                    'eyebrow' => 'PAYMENT REMINDER',
                    'title' => 'Invoice ' . $r->number,
                    'subtitle' => $money . ' outstanding',
                    'preheader' => 'Invoice ' . $r->number . ' is ' . $days . ' days overdue.',
                ]);

                Mail::html($html, function ($m) use ($to, $r, $pdf) {
                    $m->to($to)
                      ->from('noreply@kiddietrac.com', 'KiddieTrac')
                      ->replyTo('sales@kiddietrac.com', 'KiddieTrac')
                      ->subject('Reminder: invoice ' . $r->number . ' is overdue');
                    $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                    if ($pdf) {
                        $m->attachData($pdf, $r->number . '.pdf', ['mime' => 'application/pdf']);
                    }
                });

                $sent++;
            } catch (Throwable $e) {
                $this->warn('  FAILED ' . $r->number . ': ' . $e->getMessage());
                $skipped++;
            }
        }

        $this->line('');
        $this->info(sprintf(
            '%s%d reminder(s) %s, %d skipped.',
            $commit ? '' : '[dry run] ',
            $sent,
            $commit ? 'sent' : 'would be sent',
            $skipped
        ));

        return self::SUCCESS;
    }
}
