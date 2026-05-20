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
            'enrolled' => 0, 'checked_in' => 0, 'checked_pct' => 0,
            'open_invoices' => 0, 'open_balance' => 0.0,
            'meds_today' => 0, 'observations_yesterday' => 0,
            'mfa_not_enrolled' => 0,
            'centre_ratios' => [], 'expiring_certs' => [], 'upcoming_invoices' => [],
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

        // v22p48: extra signal — per-centre capacity rows for the ratio bars
        $centreRatios = DB::table('centres')->whereIn('id', $centreIds)->whereNull('deleted_at')->get()->map(function ($c) {
            $en = DB::table('children as cc')
                ->join('families as f', 'f.id', '=', 'cc.family_id')
                ->where('f.centre_id', $c->id)
                ->where('cc.enrollment_status', 'enrolled')
                ->whereNull('cc.deleted_at')
                ->count();
            $cap = (int) ($c->license_capacity ?? 0);
            return ['name' => $c->name, 'enrolled' => $en, 'capacity' => $cap,
                    'pct' => $cap > 0 ? round(($en / $cap) * 100) : 0];
        })->all();

        // v22p48: expiring certs in next 30d (incl. already expired)
        $expiringCerts = DB::table('staff_certifications as sc')
            ->join('users as u', 'u.id', '=', 'sc.user_id')
            ->whereIn('sc.user_id', function ($q) use ($agency, $centreIds) {
                $q->select('user_id')->from('role_assignments')->where('active', true)
                    ->where(function ($x) use ($agency, $centreIds) {
                        $x->where('agency_id', $agency->id)->orWhereIn('centre_id', $centreIds);
                    });
            })
            ->where('sc.active', true)
            ->whereNotNull('sc.expires_at')
            ->where('sc.expires_at', '<=', $today->copy()->addDays(30))
            ->orderBy('sc.expires_at')
            ->limit(5)
            ->get(['sc.cert_type', 'sc.expires_at', 'u.first_name', 'u.last_name']);

        // v22p48: invoices due in the next 7 days
        $upcomingInvoices = DB::table('invoices')->whereIn('centre_id', $centreIds)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->whereBetween('due_at', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])
            ->orderBy('due_at')
            ->limit(8)
            ->get(['invoice_number', 'due_at', 'balance_due', 'family_id']);

        // Today's signed-in vs enrolled percentage (rounded for display)
        $checkedPct = $enrolled > 0 ? round(($checkedIn / $enrolled) * 100) : 0;

        return [
            'enrolled' => $enrolled,
            'checked_in' => $checkedIn,
            'checked_pct' => $checkedPct,
            'open_invoices' => $openInvoices,
            'open_balance' => $openBalance,
            'meds_today' => $medsToday,
            'observations_yesterday' => $obsYday,
            'mfa_not_enrolled' => $mfaNotEnrolled,
            'centre_ratios' => $centreRatios,
            'expiring_certs' => $expiringCerts,
            'upcoming_invoices' => $upcomingInvoices,
        ];
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
        // v22p48: much richer daily summary — 4-tile KPI strip, per-centre
        // capacity bars, callouts for cert + MFA warnings, list of invoices
        // due in the next 7 days, two action buttons.
        $pct = $s['checked_pct'] ?? 0;
        $bal = number_format($s['open_balance'], 2);
        $brand = $agency->brand_primary_color ?: '#1F6080';

        $body = '';

        // Greeting line gives the email a human start
        $body .= '<p style="margin:0 0 18px;font-size:14px;color:#0F172A;line-height:1.5;">Good morning. Here is your snapshot for <strong>' . htmlspecialchars($agency->name) . '</strong> on ' . $today->format('l, F j') . '.</p>';

        // 4-tile KPI strip
        $body .= '<h3 style="margin:0 0 12px;font-size:11px;font-weight:700;color:#64748B;letter-spacing:1.5px;text-transform:uppercase;">Today at a glance</h3>';
        $body .= EmailTemplate::statRow(
            EmailTemplate::statTile('Signed in', $s['checked_in'] . ' / ' . $s['enrolled'],
                $pct . '% present · agency-wide', $pct >= 80 ? '#16A34A' : ($pct >= 50 ? '#F59E0B' : '#DC2626')),
            EmailTemplate::statTile('Open invoices', '$' . $bal,
                $s['open_invoices'] . ' open · sent / partial / overdue',
                $s['open_balance'] > 0 ? '#DC2626' : '#16A34A')
        );
        $body .= EmailTemplate::statRow(
            EmailTemplate::statTile('Meds today', (string) $s['meds_today'], 'doses logged so far', '#F59E0B'),
            EmailTemplate::statTile('Observations · yesterday', (string) $s['observations_yesterday'],
                'notes + photos by your team', '#7C3AED')
        );

        // Per-centre capacity bars
        if (!empty($s['centre_ratios'])) {
            $body .= '<h3 style="margin:22px 0 10px;font-size:11px;font-weight:700;color:#64748B;letter-spacing:1.5px;text-transform:uppercase;">Centre capacity</h3>';
            $body .= '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" style="background:#F8FAFC;border-radius:12px;padding:0;">';
            foreach ($s['centre_ratios'] as $cr) {
                $cPct = (int) $cr['pct'];
                $cColor = $cPct >= 95 ? '#DC2626' : ($cPct >= 80 ? '#F59E0B' : '#16A34A');
                $fillW = min(100, max(0, $cPct));
                $body .= '<tr><td style="padding:10px 14px;">'
                    . '<div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">'
                    . '<span style="font-weight:700;color:#0F172A;">' . htmlspecialchars($cr['name']) . '</span>'
                    . '<span style="font-weight:700;color:' . $cColor . ';">' . $cr['enrolled'] . ' / ' . $cr['capacity'] . ' · ' . $cPct . '%</span>'
                    . '</div>'
                    . '<div style="height:8px;background:#E5E7EB;border-radius:4px;overflow:hidden;">'
                    .   '<div style="height:100%;width:' . $fillW . '%;background:' . $cColor . ';"></div>'
                    . '</div></td></tr>';
            }
            $body .= '</table>';
        }

        // Warnings: MFA + expiring certs in one combined callout block
        $warnings = [];
        if (!empty($s['mfa_not_enrolled'])) {
            $warnings[] = '<strong>' . $s['mfa_not_enrolled'] . ' director/admin' . ($s['mfa_not_enrolled'] === 1 ? '' : 's') . '</strong> still need to enrol in MFA.';
        }
        if (!empty($s['expiring_certs']) && count($s['expiring_certs']) > 0) {
            $certLines = [];
            foreach ($s['expiring_certs'] as $c) {
                $name = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
                $when = Carbon::parse($c->expires_at);
                $rel = $when->isPast() ? 'expired ' . $when->diffForHumans()
                                       : 'expires ' . $when->diffForHumans();
                $certLines[] = htmlspecialchars($name . ' · ' . str_replace('_', ' ', (string) $c->cert_type) . ' · ' . $rel);
            }
            $warnings[] = '<strong>' . count($s['expiring_certs']) . ' cert' . (count($s['expiring_certs']) === 1 ? '' : 's')
                . ' expiring soon:</strong><br><span style="font-size:12px;">' . implode('<br>', $certLines) . '</span>';
        }
        if (!empty($warnings)) {
            $body .= EmailTemplate::calloutBox(implode('<br><br>', $warnings), 'warning');
        }

        // Upcoming invoices due in 7 days
        if (!empty($s['upcoming_invoices']) && count($s['upcoming_invoices']) > 0) {
            $body .= '<h3 style="margin:22px 0 10px;font-size:11px;font-weight:700;color:#64748B;letter-spacing:1.5px;text-transform:uppercase;">Invoices due in 7 days</h3>';
            $body .= '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" style="background:white;border:1px solid #E5E7EB;border-radius:12px;border-collapse:separate;border-spacing:0;">';
            $body .= '<tr style="background:#F8FAFC;"><th style="text-align:left;padding:8px 12px;font-size:11px;color:#64748B;letter-spacing:1px;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">Invoice</th>'
                .  '<th style="text-align:left;padding:8px 12px;font-size:11px;color:#64748B;letter-spacing:1px;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">Due</th>'
                .  '<th style="text-align:right;padding:8px 12px;font-size:11px;color:#64748B;letter-spacing:1px;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">Balance</th></tr>';
            foreach ($s['upcoming_invoices'] as $inv) {
                $body .= '<tr>'
                    . '<td style="padding:8px 12px;font-size:13px;color:#0F172A;border-top:1px solid #F3F4F6;font-family:ui-monospace,monospace;">' . htmlspecialchars((string) $inv->invoice_number) . '</td>'
                    . '<td style="padding:8px 12px;font-size:13px;color:#374151;border-top:1px solid #F3F4F6;">' . Carbon::parse($inv->due_at)->format('M j') . '</td>'
                    . '<td style="padding:8px 12px;font-size:13px;color:#DC2626;text-align:right;font-weight:700;border-top:1px solid #F3F4F6;">$' . number_format((float) $inv->balance_due, 2) . '</td>'
                    . '</tr>';
            }
            $body .= '</table>';
        }

        // Two CTA buttons side by side via a tiny table for email-client safety
        $body .= '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" style="margin-top:24px;">'
            . '<tr>'
            . '<td valign="top" width="50%" style="padding-right:6px;text-align:center;">'
            .   '<a href="https://app.kiddietrac.com/dashboard.html#dashboard" style="display:inline-block;background:' . $brand . ';color:#FFFFFF;padding:11px 22px;border-radius:8px;font-weight:700;font-size:13px;text-decoration:none;">Open dashboard</a>'
            . '</td>'
            . '<td valign="top" width="50%" style="padding-left:6px;text-align:center;">'
            .   '<a href="https://app.kiddietrac.com/dashboard.html#compliance" style="display:inline-block;background:#FFFFFF;color:' . $brand . ';border:1.5px solid ' . $brand . ';padding:9.5px 22px;border-radius:8px;font-weight:700;font-size:13px;text-decoration:none;">Compliance view</a>'
            . '</td>'
            . '</tr></table>';

        return EmailTemplate::wrap((int) $agency->id, $body, [
            'eyebrow'   => 'DAILY DIGEST · ' . strtoupper($today->format('D')),
            'title'     => $agency->name,
            'subtitle'  => $today->format('l, F j, Y'),
            'preheader' => $s['checked_in'] . ' of ' . $s['enrolled'] . ' children signed in (' . $pct . '%) · $' . $bal . ' outstanding · ' . $s['meds_today'] . ' meds today · ' . (count($s['expiring_certs'] ?? []) ?: 0) . ' certs expiring',
            'footer_note' => 'Daily digest from Kiddietrac. Sent every morning at 7:00 AM ET to directors + agency admins.',
        ]);
    }
}
