<?php

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Send configured invoice & payment reminders to families.
 *
 * TWO gates before anything is emailed:
 *   1) the platform master switch  config('billing.reminders_enabled')  (OFF by default)
 *   2) the per-agency toggles in agencies.settings->billing_reminders
 *
 * Runs hourly and only processes an agency in its configured send-time hour.
 * Reminders fire on EXACT day offsets from due_at, so each rule sends once.
 *
 *   php artisan billing:reminders [--dry-run] [--force]
 */
class BillingReminders extends Command
{
    protected $signature = 'billing:reminders {--dry-run : log intended sends without emailing} {--force : ignore each agency\'s send-time hour}';
    protected $description = 'Send invoice/payment reminders to families (gated by the global switch + per-agency settings).';

    public function handle(): int
    {
        if (! config('billing.reminders_enabled')) {
            $this->info('billing:reminders — globally OFF (config billing.reminders_enabled=false). Nothing sent.');
            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $hour = (int) now()->format('G');
        $today = now()->startOfDay();
        $sent = 0;

        foreach (DB::table('agencies')->whereNull('deleted_at')->select('id', 'name', 'settings')->get() as $ag) {
            $cfg = $this->cfg($ag->settings);
            if ((! $cfg['invoice_enabled'] && ! $cfg['overdue_enabled']) || ! $cfg['channel_email']) {
                continue;
            }
            /* THE AGENCY'S OWN CLOCK. `$hour` is the server's, which runs UTC, so a
               setting of 09:00 fired at 5am in Toronto and at 2am on the west coast.
               Every other reminder in this codebase resolves the hour per agency; this
               one compared against `now()` and did not. (2026-09-17) */
            $tz = DB::table('agencies')->where('id', $ag->id)->value('timezone') ?: 'America/Toronto';
            try { $nowLocal = Carbon::now($tz); } catch (Throwable $e) { $nowLocal = Carbon::now('America/Toronto'); }

            if (! $this->option('force')) {
                $sendHour = (int) explode(':', $cfg['send_time'])[0];
                if ($sendHour !== (int) $nowLocal->format('G')) {
                    continue;
                }
            }

            $am = AgencyMailer::forAgency((int) $ag->id);
            $rules = [];
            if ($cfg['invoice_enabled']) {
                foreach ($this->days($cfg['invoice_days_before']) as $d) { $rules[] = ['upcoming', $d]; }
            }
            if ($cfg['overdue_enabled']) {
                foreach ($this->days($cfg['overdue_days_after']) as $d) { $rules[] = ['overdue', $d]; }
            }

            foreach ($rules as [$kind, $d]) {
                // The offset is from the agency's today, not the server's.
                $target = $kind === 'upcoming'
                    ? $nowLocal->copy()->startOfDay()->addDays($d)
                    : $nowLocal->copy()->startOfDay()->subDays($d);

                $invoices = $this->dueOn((int) $ag->id, $target->toDateString());

                foreach ($invoices as $inv) {
                    $emails = $this->billingEmails((int) $inv->family_id);
                    if (empty($emails)) {
                        continue;
                    }
                    $subject = $kind === 'upcoming' ? ('Upcoming payment — ' . $ag->name) : ('Payment overdue — ' . $ag->name);
                    $body = $this->body($kind, $d, $inv, $ag, $cfg);

                    foreach ($emails as $to) {
                        if ($dry) {
                            Log::info("[billing:reminders DRY] {$kind} d{$d} invoice#{$inv->id} family#{$inv->family_id} → {$to}");
                            $sent++;
                            continue;
                        }
                        try {
                            // cc_admin was configurable and never read. The office gets a
                            // blind copy, not a CC: a family must not be handed the
                            // agency's internal address list on a payment chase.
                            $adminTo = ! empty($cfg['cc_admin']) ? $this->adminCopy((int) $ag->id) : null;
                            $am->raw($body, function ($m) use ($to, $subject, $adminTo) {
                                $m->to($to)->subject($subject);
                                if ($adminTo) { $m->bcc($adminTo); }
                            });
                            $sent++;
                        } catch (Throwable $e) {
                            Log::warning('billing:reminders send failed: ' . $e->getMessage());
                        }
                    }
                }
            }
        }

        $this->info("billing:reminders — {$sent} reminder(s) " . ($dry ? 'would send (dry-run)' : 'sent') . '.');
        return self::SUCCESS;
    }

    /* EVERY INVOICE THAT FALLS DUE THAT DAY, FROM BOTH TABLES.

       This read `invoices` alone. iLearn's money is in `external_invoices` — 478 of them
       against 8 native, and those 8 are all drafts — so for the agency that actually has
       families being billed, the query could not match a single row. The same
       half-the-money mistake as the Accounting screen and the parent ledger before it.

       The status test is unchanged and it is the right one:
         • paid / void / cancelled / refunded / draft are excluded by name;
         • `balance_due > 0` catches the rest, which is what makes a PARTIALLY paid
           invoice behave correctly — it is still chased, for the remainder, and the
           email quotes balance_due rather than the total.
       A refunded invoice is left `paid` with a zero balance by the refund flow, so it
       fails both tests and nobody is chased for money they were given back. Verified
       against the two refunds on file. (2026-09-17)

       @return array<int,object> rows carrying family_id, balance_due, due_at, number */
    private function dueOn(int $agencyId, string $dueDate): array
    {
        $skip = ['paid', 'void', 'cancelled', 'refunded', 'draft'];

        $native = DB::table('invoices as i')
            ->join('centres as c', 'c.id', '=', 'i.centre_id')
            ->where('c.agency_id', $agencyId)
            ->whereDate('i.due_at', $dueDate)
            ->whereNotIn('i.status', $skip)
            ->where('i.balance_due', '>', 0)
            ->get(['i.id', 'i.family_id', 'i.balance_due', 'i.due_at',
                DB::raw('i.invoice_number as number')])
            ->all();

        $external = DB::table('external_invoices')
            ->where('agency_id', $agencyId)
            ->whereDate('due_at', $dueDate)
            ->whereNotIn('status', $skip)
            ->where('balance_due', '>', 0)
            ->whereNotNull('family_id')
            ->get(['id', 'family_id', 'balance_due', 'due_at', 'number'])
            ->all();

        return array_merge($native, $external);
    }

    /* WHO TO WRITE TO — through `users`, because `guardians` HAS NO EMAIL COLUMN.

       This did `DB::table('guardians')->whereNotNull('email')->pluck('email')`, and the
       column does not exist: SQLSTATE 42S22, "Unknown column 'email'". The query threw
       on the first invoice it ever reached. Together with the master switch being off,
       that is two independent reasons this feature has never sent anything — and the
       Settings screen happily let an agency switch on something that could not run.

       `can_receive_billing` is honoured where a family has said who handles the money;
       where nobody is flagged, every guardian who can sign in is written to rather than
       nobody. Accounts that never accepted an invite, or are switched off, are skipped —
       the mail layer would refuse them anyway, after the work was done.

       @return list<string> */
    private function billingEmails(int $familyId): array
    {
        $rows = DB::table('guardians as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $familyId)
            ->whereNull('u.deleted_at')
            ->whereNotNull('u.email')
            ->whereNotIn('u.status', ['invited', 'not_invited', 'deactivated', 'suspended'])
            ->get(['u.email', 'g.can_receive_billing']);

        if ($rows->isEmpty()) {
            return [];
        }

        $billing = $rows->filter(fn ($r) => (int) $r->can_receive_billing === 1);
        $use = $billing->isNotEmpty() ? $billing : $rows;

        return $use->pluck('email')->filter()->unique()->values()->all();
    }

    /** The agency's billing contact, for cc_admin — a setting that was stored and never read. */
    private function adminCopy(int $agencyId): ?string
    {
        $a = DB::table('agencies')->where('id', $agencyId)->first(['contact_email', 'brand_support_email']);
        $to = $a->contact_email ?? $a->brand_support_email ?? null;

        return $to ? (string) $to : null;
    }

    private function cfg($json): array
    {
        $DEF = ['invoice_enabled' => false, 'invoice_days_before' => '7,3', 'overdue_enabled' => false,
            'overdue_days_after' => '1,7,14', 'send_time' => '09:00', 'channel_email' => true, 'cc_admin' => false, 'custom_message' => ''];
        $s = $json ? (json_decode($json, true) ?: []) : [];
        $r = (isset($s['billing_reminders']) && is_array($s['billing_reminders'])) ? $s['billing_reminders'] : [];
        return array_merge($DEF, $r);
    }

    private function days($csv): array
    {
        return array_values(array_filter(
            array_map('intval', array_map('trim', explode(',', (string) $csv))),
            fn ($n) => $n >= 0 && $n <= 365
        ));
    }

    private function body(string $kind, int $d, $inv, $ag, array $cfg): string
    {
        $when = $kind === 'upcoming' ? "due in {$d} day(s)" : "{$d} day(s) overdue";
        $amt = number_format((float) $inv->balance_due, 2);
        $due = Carbon::parse($inv->due_at)->toFormattedDateString();
        /* NAME THE INVOICE. "You have an invoice" is unanswerable for a family with
           several on the go — and a chase they cannot match to a document is a phone
           call to the office, which is the opposite of what this is for. Both tables
           carry a number, so there is always one to quote. */
        $number = trim((string) ($inv->number ?? ''));
        $b = "Hello,\n\nA friendly reminder from {$ag->name}.\n\n";
        $b .= 'Invoice ' . ($number !== '' ? $number . ' ' : '')
            . "({$when}) has a balance of \${$amt}, due {$due}.\n\n";
        if (! empty($cfg['custom_message'])) {
            $b .= $cfg['custom_message'] . "\n\n";
        }
        $b .= "Please sign in to your parent portal to review and pay.\n\n— {$ag->name}\n";
        return $b;
    }
}
