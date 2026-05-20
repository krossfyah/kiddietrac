<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p36 — Daily digest for directors + agency admins.
 *
 * Runs once per morning (07:00 in routes/console.php). For each non-suspended
 * agency, gathers today's headline numbers and sends one email per
 * director / agency_admin in that agency, via that agency's own SMTP
 * settings when configured (AgencyMailer).
 *
 * --dry-run prints the digest content to stdout and skips sending — used
 * for first-shot verification before pointing cron at it.
 */
final class DailyDigestCommand extends Command
{
    protected $signature = 'kiddietrac:digest-daily {--dry-run : Print to console, do not send}';
    protected $description = 'Send the daily morning digest to directors + agency admins';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $today = Carbon::now()->startOfDay();
        $tomorrow = (clone $today)->addDay();

        $agencies = DB::table('agencies')
            ->whereNull('deleted_at')
            ->where('billing_status', '!=', 'suspended')
            ->get();
        $this->info("Running daily digest for {$agencies->count()} agencies (dry=" . ($dry ? 'yes' : 'no') . ')');

        foreach ($agencies as $a) {
            $stats = $this->statsForAgency($a, $today, $tomorrow);
            $recipients = $this->recipientsForAgency($a);
            if (empty($recipients)) continue;

            $subject = '[' . $a->name . '] Daily summary · ' . $today->format('D, M j');
            $body = $this->renderHtml($a, $stats, $today);

            if ($dry) {
                $this->line("--- {$a->name} ({$a->id}) → " . count($recipients) . ' recipients ---');
                $this->line($subject);
                $this->line(strip_tags(str_replace('</', "\n</", $body)));
                continue;
            }

            $mailer = AgencyMailer::forAgency((int) $a->id);
            foreach ($recipients as $rcpt) {
                try {
                    $mailer->mailer()->html($body, function ($m) use ($subject, $rcpt) {
                        $m->to($rcpt->email, ($rcpt->first_name . ' ' . $rcpt->last_name))->subject($subject);
                    });
                } catch (\Throwable $e) {
                    $this->warn('Send failed for ' . $rcpt->email . ': ' . $e->getMessage());
                }
            }
            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'digest.daily_sent',
                'entity_type' => 'agency',
                'entity_id' => (int) $a->id,
                'payload' => json_encode(['recipients' => count($recipients), 'date' => $today->toDateString()]),
                'created_at' => now(),
            ]);
        }

        return self::SUCCESS;
    }

    private function statsForAgency(object $agency, Carbon $today, Carbon $tomorrow): array
    {
        $centreIds = DB::table('centres')->where('agency_id', $agency->id)->whereNull('deleted_at')->pluck('id')->all();
        if (empty($centreIds)) return [
            'enrolled' => 0, 'checked_in' => 0, 'open_invoices' => 0,
            'open_balance' => 0.0, 'meds_today' => 0, 'observations_yesterday' => 0,
            'mfa_not_enrolled' => 0,
        ];

        $enrolled = DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->where('c.enrollment_status', 'enrolled')
            ->whereNull('c.deleted_at')
            ->count();

        $checkedIn = DB::table('check_events as ce1')
            ->join('children as c', 'c.id', '=', 'ce1.child_id')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->where('ce1.occurred_at', '>=', $today)
            ->where('ce1.event_type', 'check_in')
            ->distinct()->count('ce1.child_id');

        $openBalance = (float) DB::table('invoices')->whereIn('centre_id', $centreIds)
            ->whereIn('status', ['sent', 'partial', 'overdue'])->sum('balance_due');
        $openInvoices = DB::table('invoices')->whereIn('centre_id', $centreIds)
            ->whereIn('status', ['sent', 'partial', 'overdue'])->count();

        $medsToday = DB::table('medication_administrations as ma')
            ->join('children as c', 'c.id', '=', 'ma.child_id')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->whereBetween('ma.administered_at', [$today, $tomorrow])->count();

        $yesterday = $today->copy()->subDay();
        $obsYday = DB::table('observations as o')
            ->join('children as c', 'c.id', '=', 'o.child_id')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->where('o.created_at', '>=', $yesterday)
            ->where('o.created_at', '<', $today)
            ->count();

        $mfaNotEnrolled = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->whereIn('ra.role', ['agency_admin', 'centre_director'])
            ->where('ra.active', true)
            ->where(function ($q) use ($agency, $centreIds) {
                $q->where('ra.agency_id', $agency->id)->orWhereIn('ra.centre_id', $centreIds);
            })
            ->whereNull('u.two_factor_secret')
            ->distinct()->count('u.id');

        return compact('enrolled', 'checkedIn', 'openBalance', 'openInvoices', 'medsToday', 'obsYday', 'mfaNotEnrolled')
            + ['checked_in' => $checkedIn, 'open_invoices' => $openInvoices, 'open_balance' => $openBalance,
               'meds_today' => $medsToday, 'observations_yesterday' => $obsYday, 'mfa_not_enrolled' => $mfaNotEnrolled];
    }

    private function recipientsForAgency(object $agency): array
    {
        $centreIds = DB::table('centres')->where('agency_id', $agency->id)->whereNull('deleted_at')->pluck('id')->all();
        return DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->whereIn('ra.role', ['agency_admin', 'centre_director'])
            ->where('ra.active', true)
            ->where(function ($q) use ($agency, $centreIds) {
                $q->where('ra.agency_id', $agency->id)->orWhereIn('ra.centre_id', $centreIds);
            })
            ->whereNull('u.deleted_at')
            ->whereNotNull('u.email')
            ->distinct()
            ->select('u.id', 'u.email', 'u.first_name', 'u.last_name')
            ->get()
            ->all();
    }

    private function renderHtml(object $agency, array $s, Carbon $today): string
    {
        // v22p38: render via the EmailTemplate service so every agency gets
        // its own logo + brand colours + white-label-aware footer.
        $pct = $s['enrolled'] > 0 ? round(($s['checked_in'] / $s['enrolled']) * 100) : 0;
        $bal = number_format($s['open_balance'], 2);

        $body  = '<h3 style="margin:0 0 14px;font-size:13px;font-weight:700;color:#64748B;letter-spacing:1px;text-transform:uppercase;">Today\'s headline</h3>';

        $body .= EmailTemplate::statRow(
            EmailTemplate::statTile('Signed in', $s['checked_in'] . ' / ' . $s['enrolled'], $pct . '% present', '#16A34A'),
            EmailTemplate::statTile('Open invoices', '$' . $bal, $s['open_invoices'] . ' open', '#DC2626')
        );
        $body .= EmailTemplate::statRow(
            EmailTemplate::statTile('Meds today', (string) $s['meds_today'], 'doses logged so far', '#F59E0B'),
            EmailTemplate::statTile('Observations yesterday', (string) $s['observations_yesterday'], 'notes + photos', '#7C3AED')
        );

        if ($s['mfa_not_enrolled'] > 0) {
            $body .= EmailTemplate::calloutBox(
                '<strong>' . $s['mfa_not_enrolled'] . ' staff still need to set up MFA.</strong><br>Visit Settings → Two-factor (MFA) to enable it.',
                'warning'
            );
        }

        $body .= EmailTemplate::button('Open dashboard', 'https://app.kiddietrac.com/dashboard.html#dashboard', $agency->brand_primary_color ?: '#1F6080');

        return EmailTemplate::wrap((int) $agency->id, $body, [
            'eyebrow'   => 'DAILY DIGEST',
            'title'     => $agency->name,
            'subtitle'  => $today->format('l, F j, Y'),
            'preheader' => $s['checked_in'] . ' of ' . $s['enrolled'] . ' children signed in · $' . $bal . ' outstanding · ' . $s['meds_today'] . ' meds today',
            'footer_note' => 'Daily summary sent every morning at 7:00 AM. ' .
                'You are receiving this because you have a director or admin role at this agency.',
        ]);
    }
}
