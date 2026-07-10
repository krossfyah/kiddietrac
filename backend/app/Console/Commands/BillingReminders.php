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
            if (! $this->option('force')) {
                $sendHour = (int) explode(':', $cfg['send_time'])[0];
                if ($sendHour !== $hour) {
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
                $target = $kind === 'upcoming' ? $today->copy()->addDays($d) : $today->copy()->subDays($d);
                $invoices = DB::table('invoices')
                    ->whereDate('due_at', $target->toDateString())
                    ->whereNotIn('status', ['paid', 'void', 'cancelled', 'refunded', 'draft'])
                    ->where('balance_due', '>', 0)
                    ->get();

                foreach ($invoices as $inv) {
                    $emails = DB::table('guardians')->where('family_id', $inv->family_id)
                        ->whereNotNull('email')->pluck('email')->unique()->values()->all();
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
                            $am->mailer()->raw($body, function ($m) use ($to, $subject) {
                                $m->to($to)->subject($subject);
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
        $b = "Hello,\n\nA friendly reminder from {$ag->name}.\n\n";
        $b .= "You have an invoice ({$when}) with a balance of \${$amt}, due {$due}.\n\n";
        if (! empty($cfg['custom_message'])) {
            $b .= $cfg['custom_message'] . "\n\n";
        }
        $b .= "Please sign in to your parent portal to review and pay.\n\n— {$ag->name}\n";
        return $b;
    }
}
