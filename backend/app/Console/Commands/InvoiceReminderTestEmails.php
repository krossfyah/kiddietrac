<?php

namespace App\Console\Commands;

use App\Support\InvoiceMail;
use App\Support\InvoiceReminders;
use App\Support\ParentBilling;
use App\Support\SplitInvoiceCopy;
use App\Support\TemplatedEmails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Samples of the parent invoice emails, to ONE address you name (2026-10-31). A fictional family and amounts, "[TEST]"
 * in every subject; nothing is logged against a real invoice and no family is emailed. There is no default recipient.
 *
 * Each sample is drawn with a real agency's branding (logo, name, first centre's contact) when --agency is given, in
 * the invoice header (App\Support\InvoiceMail).
 *
 *   php artisan billing:reminder-test-emails you@example.com [--agency=6,2] [--only=issued,split,resend,before,due,overdue,receipt]
 */
class InvoiceReminderTestEmails extends Command
{
    protected $signature = 'billing:reminder-test-emails {to : the one address to send the samples to}
        {--agency= : agency ids whose branding to use, comma separated (none = KiddieTrac platform look)}
        {--only= : issued,split,resend,before,due,overdue,receipt}';
    protected $description = 'Send sample parent invoice emails (fictional family, [TEST] subjects) to one address.';

    public function handle(): int
    {
        $to = trim((string) $this->argument('to'));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('Give one valid address.');
            return self::FAILURE;
        }
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $agencies = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('agency')))));
        if (! $agencies) $agencies = [0];

        foreach ($agencies as $agencyId) {
            $agency = $agencyId ? DB::table('agencies')->where('id', $agencyId)->first(['id', 'name']) : null;
            if ($agencyId && ! $agency) { $this->warn("agency {$agencyId} not found"); continue; }
            $aid = $agency ? (int) $agency->id : null;
            $agName = $agency->name ?? 'Maple Grove Child Care';
            $centreId = $aid ? DB::table('centres')->where('agency_id', $aid)->whereNull('deleted_at')->orderBy('id')->value('id') : null;
            $interac = null;
            if ($aid) { try { $interac = ParentBilling::payInfo($aid)['interac']['email'] ?? null; } catch (\Throwable $e) {} }
            $interac = $interac ?: 'billing@maplegrove.example';
            $link = $aid ? InvoiceReminders::payLink($aid) : 'https://app.kiddietrac.com/dashboard.html#billing';

            $base = [
                'agency_name' => $agName, 'number' => 'INV-2026-0418', 'total' => 1240.00, 'first_name' => 'Jordan',
                'custom_message' => '', 'interac' => $interac, 'pay_link' => $link, 'test' => true, 'centre_id' => $centreId,
                'copies' => ['Priya (Agency admin)', 'Marcus (Director)'],
            ];
            $mails = [];
            if (! $only || in_array('issued', $only, true)) {
                $mails[] = InvoiceMail::issued($aid, ['number' => 'INV-2026-0418', 'total' => 1240.00, 'balance' => 1240.00,
                    'due' => now()->addDays(14)->toDateString(), 'first_name' => 'Jordan', 'agency_name' => $agName, 'centre_id' => $centreId,
                    'pay_link' => $link, 'interac' => $interac, 'kind' => 'invoice', 'test' => true]);
            }
            if (! $only || in_array('split', $only, true)) {
                $c = ['number' => 'INV-2026-0418-A', 'family_number' => 'INV-2026-0418', 'balance' => 744.00, 'share' => 744.00, 'first_name' => 'Jordan'];
                $mails[] = ['[TEST] Your invoice INV-2026-0418-A', SplitInvoiceCopy::emailHtml($aid, $agName, $c, now()->addDays(14)->toDateString(), $centreId, true)];
            }
            $samples = [
                'resend'  => ['resend', 0, ['due_date' => now()->addDays(10)->toDateString(), 'amount' => 1240.00, 'share_pct' => null]],
                'before'  => ['before', 3, ['due_date' => now()->addDays(3)->toDateString(), 'amount' => 620.00, 'share_pct' => 50]],
                'due'     => ['due', 0, ['due_date' => now()->toDateString(), 'amount' => 1240.00, 'share_pct' => null]],
                'overdue' => ['overdue', 3, ['due_date' => now()->subDays(3)->toDateString(), 'amount' => 1240.00, 'share_pct' => null]],
            ];
            foreach ($samples as $k => [$reason, $days, $o]) {
                if ($only && ! in_array($k, $only, true)) continue;
                $mails[] = InvoiceReminders::email($aid, $reason, $days, array_merge($base, $o));
            }
            if (! $only || in_array('receipt', $only, true)) {
                $r = TemplatedEmails::paymentReceipt($aid ?: 0, [
                    'name' => 'Jordan', 'amount' => '$620.00', 'how' => 'Interac e-Transfer', 'date' => now()->format('M j, Y'),
                    'reference' => 'RCPT-20261031-0042', 'invoice_number' => 'INV-2026-0418', 'balance_now' => 620.00,
                    'balance_text' => '$620.00', 'agency_name' => $agName, 'centre_id' => $centreId,
                ]);
                $mails[] = ['[TEST] ' . $r['subject'], $r['html']];
            }
            foreach ($mails as [$subject, $html]) {
                $subject = str_starts_with($subject, '[TEST]') ? $subject : '[TEST] ' . $subject;
                $subject .= $aid ? ' (' . $agName . ')' : '';
                Mail::html($html, function ($m) use ($to, $subject) {
                    \App\Support\MailScope::platform($m);
                    $m->to($to)->subject($subject);
                });
                $this->line('sent: ' . $subject);
            }
        }
        return self::SUCCESS;
    }
}
