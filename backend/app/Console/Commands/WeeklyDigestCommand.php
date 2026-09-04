<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p36 — Weekly digest. Runs Monday morning. Wider window than the daily:
 * enrolment delta vs last week, total billing run, top-3 observations,
 * pending compliance flags.
 */
final class WeeklyDigestCommand extends Command
{
    protected $signature = 'kiddietrac:digest-weekly {--dry-run : Print to console, do not send}';
    protected $description = 'Send the weekly Monday digest to directors + agency admins';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $now = Carbon::now();
        $weekStart = $now->copy()->startOfWeek();          // Monday this week
        $lastWeekStart = $weekStart->copy()->subWeek();
        $lastWeekEnd = $weekStart->copy();

        $agencies = DB::table('agencies')->whereNull('deleted_at')->where('billing_status', '!=', 'suspended')->get();
        $this->info("Running weekly digest for {$agencies->count()} agencies (dry=" . ($dry ? 'yes' : 'no') . ')');

        foreach ($agencies as $a) {
            $stats = $this->stats($a, $lastWeekStart, $lastWeekEnd);
            $rcpts = $this->recipients($a);
            if (empty($rcpts)) continue;

            $subject = '[' . $a->name . '] Weekly summary · week of ' . $lastWeekStart->format('M j');
            $body = $this->renderHtml($a, $stats, $lastWeekStart, $lastWeekEnd);

            if ($dry) {
                $this->line("--- {$a->name} ({$a->id}) → " . count($rcpts) . ' recipients ---');
                $this->line($subject);
                $this->line(strip_tags(str_replace('</', "\n</", $body)));
                continue;
            }

            $mailer = AgencyMailer::forAgency((int) $a->id);
            foreach ($rcpts as $r) {
                try {
                    $mailer->mailer()->html($body, function ($m) use ($subject, $r) {
                        // Engagement mail: withheld from accounts nobody has claimed.
                        try { $m->getHeaders()->addTextHeader('X-KT-Engagement', '1'); }
                        catch (\Throwable $e) {}
                        $m->to($r->email, ($r->first_name . ' ' . $r->last_name))->subject($subject);
                    });
                } catch (\Throwable $e) {
                    $this->warn('Send failed for ' . $r->email . ': ' . $e->getMessage());
                }
            }
            \App\Support\Audit::write([
                'user_id' => null, 'agency_id' => (int) $a->id,
                'action' => 'digest.weekly_sent',
                'entity_type' => 'agency', 'entity_id' => (int) $a->id,
                'payload' => json_encode(['recipients' => count($rcpts), 'week_of' => $lastWeekStart->toDateString()]),
                'created_at' => now(),
            ]);
        }
        return self::SUCCESS;
    }

    private function stats(object $agency, Carbon $start, Carbon $end): array
    {
        $centreIds = DB::table('centres')->where('agency_id', $agency->id)->whereNull('deleted_at')->pluck('id')->all();
        if (empty($centreIds)) return [
            'new_enrolments' => 0, 'withdrawn' => 0,
            'billed' => 0.0, 'paid' => 0.0,
            'incidents' => 0, 'observations' => 0,
            'check_events' => 0, 'centre_movement' => [],
            'top_recorders' => [], 'outstanding' => 0.0,
        ];

        $new = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->whereBetween('c.enrolled_at', [$start, $end])->count();
        $withdrawn = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->whereBetween('c.withdrawn_at', [$start, $end])->count();
        $billed = (float) DB::table('invoices')->whereIn('centre_id', $centreIds)
            ->whereBetween('issued_at', [$start, $end])->sum('total');
        $paid = (float) DB::table('invoices')->whereIn('centre_id', $centreIds)
            ->whereBetween('issued_at', [$start, $end])->sum('amount_paid');
        $obs = DB::table('observations as o')->join('children as c', 'c.id', '=', 'o.child_id')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->whereBetween('o.created_at', [$start, $end])->count();
        // v22p48: incidents has no centre_id — join through rooms.centre_id
        $incidents = DB::table('incidents as inc')
            ->join('rooms as rm', 'rm.id', '=', 'inc.room_id')
            ->whereIn('rm.centre_id', $centreIds)
            ->whereBetween('inc.created_at', [$start, $end])
            ->count();

        // v22p48: a few extra weekly signals
        $checkEvents = DB::table('check_events as ce')
            ->join('children as c', 'c.id', '=', 'ce.child_id')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->whereBetween('ce.occurred_at', [$start, $end])
            ->where('ce.event_type', 'check_in')
            ->distinct()
            ->count('ce.child_id');

        // Per-centre net enrolment movement (added vs withdrawn) over the week
        $centreMovement = DB::table('centres')->whereIn('id', $centreIds)->whereNull('deleted_at')->get()
            ->map(function ($c) use ($start, $end) {
                $added = DB::table('children as cc')->join('families as f', 'f.id', '=', 'cc.family_id')
                    ->where('f.centre_id', $c->id)
                    ->whereBetween('cc.enrolled_at', [$start, $end])->count();
                $left = DB::table('children as cc')->join('families as f', 'f.id', '=', 'cc.family_id')
                    ->where('f.centre_id', $c->id)
                    ->whereBetween('cc.withdrawn_at', [$start, $end])->count();
                return ['name' => $c->name, 'added' => $added, 'withdrew' => $left, 'net' => $added - $left];
            })->all();

        // Top staff by observations logged (volume signal — recognises effort)
        $topRecorders = DB::table('observations as o')
            ->join('users as u', 'u.id', '=', 'o.recorded_by_id')
            ->whereIn('o.child_id', function ($q) use ($centreIds) {
                $q->select('c.id')->from('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
                    ->whereIn('f.centre_id', $centreIds);
            })
            ->whereBetween('o.created_at', [$start, $end])
            ->groupBy('o.recorded_by_id', 'u.first_name', 'u.last_name')
            ->orderByRaw('COUNT(o.id) DESC')
            ->limit(3)
            ->get(['u.first_name', 'u.last_name', DB::raw('COUNT(o.id) as cnt')]);

        $outstanding = (float) DB::table('invoices')->whereIn('centre_id', $centreIds)
            ->whereIn('status', ['sent', 'partial', 'overdue'])->sum('balance_due');

        return [
            'new_enrolments' => $new, 'withdrawn' => $withdrawn,
            'billed' => $billed, 'paid' => $paid,
            'observations' => $obs, 'incidents' => $incidents,
            'check_events' => $checkEvents,
            'centre_movement' => $centreMovement,
            'top_recorders' => $topRecorders,
            'outstanding' => $outstanding,
        ];
    }

    private function recipients(object $agency): array
    {
        $centreIds = DB::table('centres')->where('agency_id', $agency->id)->whereNull('deleted_at')->pluck('id')->all();
        return DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->whereIn('ra.role', ['agency_admin', 'centre_director'])->where('ra.active', true)
            ->where(function ($q) use ($agency, $centreIds) {
                $q->where('ra.agency_id', $agency->id)->orWhereIn('ra.centre_id', $centreIds);
            })
            ->whereNull('u.deleted_at')->whereNotNull('u.email')
            ->distinct()->select('u.id', 'u.email', 'u.first_name', 'u.last_name')->get()->all();
    }

    private function renderHtml(object $agency, array $s, Carbon $start, Carbon $end): string
    {
        // v22p48: rich weekly summary — KPI tiles + per-centre movement
        // table + top recorders + collection summary, all branded.
        $weekLabel = $start->format('M j') . ' – ' . $end->copy()->subDay()->format('M j');
        $net = $s['new_enrolments'] - $s['withdrawn'];
        $netColor = $net >= 0 ? '#16A34A' : '#DC2626';
        $billedFmt = '$' . number_format($s['billed'], 2);
        $paidFmt = '$' . number_format($s['paid'], 2);
        $outstandingFmt = '$' . number_format($s['outstanding'] ?? 0, 2);
        $collectionPct = $s['billed'] > 0 ? round(($s['paid'] / $s['billed']) * 100) : 0;
        $brand = $agency->brand_primary_color ?: '#7C3AED';

        $body = '';

        // The agency's own logo at the top of the body (header/footer stay KiddieTrac-branded).
        if (!empty($agency->brand_logo_url)) {
            $lu = $agency->brand_logo_url;
            $abs = (strpos($lu, 'http') === 0) ? $lu : ('https://app.kiddietrac.com' . $lu);
            $body .= '<div style="text-align:center;margin:0 0 18px;"><img src="' . htmlspecialchars($abs) . '" alt="' . htmlspecialchars($agency->name) . '" style="max-height:64px;max-width:230px;height:auto;border:0;display:inline-block;"></div>';
        }

        // Friendly opener
        $body .= '<p style="margin:0 0 18px;font-size:14px;color:#0F172A;line-height:1.5;">Good morning. Here\'s how the week of <strong>' . $weekLabel . '</strong> shook out across <strong>' . htmlspecialchars($agency->name) . '</strong>.</p>';

        // 4-tile KPI strip
        $body .= '<h3 style="margin:0 0 12px;font-size:11px;font-weight:700;color:#64748B;letter-spacing:1.5px;text-transform:uppercase;">Last week at a glance</h3>';
        $body .= EmailTemplate::statRow(
            EmailTemplate::statTile('Net enrolment', ($net >= 0 ? '+' : '') . $net,
                $s['new_enrolments'] . ' added · ' . $s['withdrawn'] . ' withdrew', $netColor),
            EmailTemplate::statTile('Billed', $billedFmt,
                $paidFmt . ' paid so far · ' . $collectionPct . '% collected', '#16A34A')
        );
        $body .= EmailTemplate::statRow(
            EmailTemplate::statTile('Distinct children · day attended', (string) ($s['check_events'] ?? 0),
                'unique children signed in last week', '#1F6080'),
            EmailTemplate::statTile('Observations logged', (string) $s['observations'],
                'HDLH / ELECT / ELOF frameworks', '#7C3AED')
        );
        $body .= EmailTemplate::statRow(
            EmailTemplate::statTile('Collected', $paidFmt,
                $collectionPct . '% of ' . $billedFmt . ' billed', $collectionPct >= 80 ? '#16A34A' : '#F59E0B'),
            EmailTemplate::statTile('Incidents', (string) $s['incidents'],
                'reports filed last week', ($s['incidents'] > 0) ? '#DC2626' : '#16A34A')
        );

        // Per-centre movement table
        if (!empty($s['centre_movement'])) {
            $body .= '<h3 style="margin:22px 0 10px;font-size:11px;font-weight:700;color:#64748B;letter-spacing:1.5px;text-transform:uppercase;">Centre movement</h3>';
            $body .= '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" style="background:white;border:1px solid #E5E7EB;border-radius:12px;border-collapse:separate;border-spacing:0;">';
            $body .= '<tr style="background:#F8FAFC;">'
                .  '<th style="text-align:left;padding:8px 12px;font-size:11px;color:#64748B;letter-spacing:1px;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">Centre</th>'
                .  '<th style="text-align:right;padding:8px 12px;font-size:11px;color:#64748B;letter-spacing:1px;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">Added</th>'
                .  '<th style="text-align:right;padding:8px 12px;font-size:11px;color:#64748B;letter-spacing:1px;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">Withdrew</th>'
                .  '<th style="text-align:right;padding:8px 12px;font-size:11px;color:#64748B;letter-spacing:1px;text-transform:uppercase;border-bottom:1px solid #E5E7EB;">Net</th></tr>';
            foreach ($s['centre_movement'] as $row) {
                $rowNet = (int) $row['net'];
                $rowColor = $rowNet >= 0 ? '#16A34A' : '#DC2626';
                $body .= '<tr>'
                    . '<td style="padding:8px 12px;font-size:13px;color:#0F172A;border-top:1px solid #F3F4F6;">' . htmlspecialchars((string) $row['name']) . '</td>'
                    . '<td style="padding:8px 12px;font-size:13px;color:#16A34A;text-align:right;border-top:1px solid #F3F4F6;">' . (int) $row['added'] . '</td>'
                    . '<td style="padding:8px 12px;font-size:13px;color:#DC2626;text-align:right;border-top:1px solid #F3F4F6;">' . (int) $row['withdrew'] . '</td>'
                    . '<td style="padding:8px 12px;font-size:13px;text-align:right;font-weight:700;color:' . $rowColor . ';border-top:1px solid #F3F4F6;">' . ($rowNet >= 0 ? '+' : '') . $rowNet . '</td>'
                    . '</tr>';
            }
            $body .= '</table>';
        }

        // Top recorders shout-out
        if (!empty($s['top_recorders']) && count($s['top_recorders']) > 0) {
            $body .= '<h3 style="margin:22px 0 10px;font-size:11px;font-weight:700;color:#64748B;letter-spacing:1.5px;text-transform:uppercase;">🏆 Most observations logged</h3>';
            $body .= '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation">';
            foreach ($s['top_recorders'] as $i => $r) {
                $medals = ['🥇', '🥈', '🥉'];
                $medal = $medals[$i] ?? '·';
                $name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
                $body .= '<tr><td style="padding:6px 0;font-size:14px;color:#0F172A;">'
                    . '<span style="font-size:18px;margin-right:8px;">' . $medal . '</span>'
                    . '<strong>' . htmlspecialchars($name) . '</strong>'
                    . ' <span style="color:#64748B;">· ' . (int) $r->cnt . ' observation' . ((int) $r->cnt === 1 ? '' : 's') . '</span>'
                    . '</td></tr>';
            }
            $body .= '</table>';
        }

        // Outstanding callout if any
        if (($s['outstanding'] ?? 0) > 0) {
            $body .= EmailTemplate::calloutBox(
                '<strong>' . $outstandingFmt . ' outstanding</strong> across sent/partial/overdue invoices. Visit the Billing tab to chase the slow payers.',
                'warning'
            );
        }

        // Twin CTAs
        $body .= '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" style="margin-top:24px;">'
            . '<tr>'
            . '<td valign="top" width="50%" style="padding-right:6px;text-align:center;">'
            .   '<a href="https://app.kiddietrac.com/dashboard.html#dashboard" style="display:inline-block;background:' . $brand . ';color:#FFFFFF;padding:11px 22px;border-radius:8px;font-weight:700;font-size:13px;text-decoration:none;">Open dashboard</a>'
            . '</td>'
            . '<td valign="top" width="50%" style="padding-left:6px;text-align:center;">'
            .   '<a href="https://app.kiddietrac.com/dashboard.html#admin-billing" style="display:inline-block;background:#FFFFFF;color:' . $brand . ';border:1.5px solid ' . $brand . ';padding:9.5px 22px;border-radius:8px;font-weight:700;font-size:13px;text-decoration:none;">Billing</a>'
            . '</td>'
            . '</tr></table>';

        return EmailTemplate::wrap((int) $agency->id, $body, [
            'eyebrow'   => 'WEEKLY DIGEST',
            'title'     => $agency->name,
            'subtitle'  => 'Week of ' . $weekLabel,
            'preheader' => ($net >= 0 ? '+' : '') . $net . ' net enrolment · ' . $billedFmt . ' billed · ' . $collectionPct . '% collected · ' . $s['observations'] . ' observations',
            'footer_note' => 'Weekly digest from Kiddietrac. Sent every Monday at 7:05 AM ET, covering the prior week.',
        ]);
    }
}
