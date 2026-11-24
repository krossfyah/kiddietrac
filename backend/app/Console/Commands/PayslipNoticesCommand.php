<?php

namespace App\Console\Commands;

use App\Support\PayslipNotices;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * kt:payslip-notices — email staff their new payslips (App\Support\PayslipNotices). Scheduled every 2 minutes.
 *
 *   kt:payslip-notices                                   the scheduled pass (payslips since payroll.payslip_email_since)
 *   kt:payslip-notices --agency=2 --since="2026-10-30 19:51" --dry    list an older batch without sending
 *   kt:payslip-notices --agency=2 --since="2026-10-30 19:51" --apply  send that batch (still once per payslip)
 *   kt:payslip-notices --doc=123 --to=someone@example.com --test      one [TEST] copy to an address you name, nothing recorded
 */
class PayslipNoticesCommand extends Command
{
    protected $signature = 'kt:payslip-notices {--agency=} {--since=} {--doc=*} {--apply} {--dry} {--to=} {--test}';
    protected $description = 'Email staff a notice (with the PDF) when a new payslip arrives';

    public function handle(): int
    {
        $agency = $this->option('agency') ? (int) $this->option('agency') : null;

        if ($this->option('test')) {
            $to = (string) $this->option('to');
            $ids = array_map('intval', (array) $this->option('doc'));
            if (! filter_var($to, FILTER_VALIDATE_EMAIL) || count($ids) !== 1) {
                $this->error('--test needs exactly one --doc and an explicit --to address.');
                return self::FAILURE;
            }
            $doc = DB::table('payroll_documents')->where('id', $ids[0])->first();
            if (! $doc) { $this->error('No such payslip.'); return self::FAILURE; }
            $r = PayslipNotices::send($doc, PayslipNotices::settings((int) $doc->agency_id), 'new', 'test', $to, true);
            $this->line(json_encode(['test' => $r, 'to' => $to]));
            return self::SUCCESS;
        }

        $since = $this->option('since') ?: null;
        if ($since && ! $this->option('apply') && ! $this->option('dry')) {
            $this->error('An older batch (--since) needs --dry to list it or --apply to send it.');
            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry');
        $r = PayslipNotices::run($dry, $agency, $since, array_map('intval', (array) $this->option('doc')));
        $this->line(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }
}
