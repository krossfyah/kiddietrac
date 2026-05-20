<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AgencyMailer;
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
                        $m->to($r->email, ($r->first_name . ' ' . $r->last_name))->subject($subject);
                    });
                } catch (\Throwable $e) {
                    $this->warn('Send failed for ' . $r->email . ': ' . $e->getMessage());
                }
            }
            DB::table('audit_logs')->insert([
                'user_id' => null, 'action' => 'digest.weekly_sent',
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
        if (empty($centreIds)) return ['new_enrolments' => 0, 'withdrawn' => 0, 'billed' => 0.0, 'paid' => 0.0, 'incidents' => 0, 'observations' => 0];

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
        $incidents = DB::table('incidents')->whereIn('centre_id', $centreIds)
            ->whereBetween('created_at', [$start, $end])->count();
        return compact('new', 'withdrawn', 'billed', 'paid', 'obs', 'incidents')
            + ['new_enrolments' => $new, 'observations' => $obs];
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
        $brand = $agency->brand_primary_color ?: '#7C3AED';
        $name = htmlspecialchars($agency->name);
        $weekLabel = $start->format('M j') . ' – ' . $end->copy()->subDay()->format('M j');
        $net = $s['new_enrolments'] - $s['withdrawn'];
        $netColor = $net >= 0 ? '#16A34A' : '#DC2626';
        $billedFmt = '$' . number_format($s['billed'], 2);
        $paidFmt = '$' . number_format($s['paid'], 2);

        $html  = '<div style="font-family: -apple-system, system-ui, Segoe UI, Roboto, sans-serif; max-width:620px; margin:0 auto; color:#0F172A;">';
        $html .= '<div style="background:linear-gradient(135deg,' . $brand . ' 0%, #16637A 100%); color:white; padding:24px 28px; border-radius:14px 14px 0 0;">';
        $html .= '<div style="font-size:11px; font-weight:700; letter-spacing:2px;">WEEKLY DIGEST</div>';
        $html .= '<div style="font-size:22px; font-weight:800; margin-top:4px;">' . $name . '</div>';
        $html .= '<div style="font-size:13px; opacity:.8;">Week of ' . $weekLabel . '</div>';
        $html .= '</div>';
        $html .= '<div style="background:white; padding:24px 28px; border:1px solid #E5E7EB; border-top:none; border-radius:0 0 14px 14px;">';
        $html .= '<h3 style="margin:0 0 14px; font-size:14px; color:#6B7280; text-transform:uppercase; letter-spacing:1.2px;">Last week\'s movement</h3>';
        $html .= '<table cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse;">';
        $html .= '<tr><td>' . $this->stat('Net enrolment', ($net >= 0 ? '+' : '') . $net, $s['new_enrolments'] . ' added · ' . $s['withdrawn'] . ' withdrew', $netColor) . '</td>';
        $html .=    '<td style="width:14px;"></td>';
        $html .=    '<td>' . $this->stat('Billed last week', $billedFmt, $paidFmt . ' paid so far', '#16A34A') . '</td></tr>';
        $html .= '<tr><td style="height:12px;"></td></tr>';
        $html .= '<tr><td>' . $this->stat('Observations', (string) $s['observations'], 'logged in HDLH / ELECT', '#7C3AED') . '</td>';
        $html .=    '<td style="width:14px;"></td>';
        $html .=    '<td>' . $this->stat('Incidents', (string) $s['incidents'], 'recorded by educators', $s['incidents'] > 0 ? '#F59E0B' : '#16A34A') . '</td></tr>';
        $html .= '</table>';
        $html .= '<p style="font-size:12px; color:#9CA3AF; margin-top:24px;">You\'re receiving this because you are a director or admin at ' . $name . '. <a href="https://app.kiddietrac.com/#help" style="color:' . $brand . '; text-decoration:none;">Manage notifications</a> in the portal.</p>';
        $html .= '</div></div>';
        return $html;
    }

    private function stat(string $label, string $value, string $hint, string $accent): string
    {
        return '<table cellpadding="0" cellspacing="0" style="width:100%; background:#F8FAFC; border-radius:12px; border-left:4px solid ' . $accent . ';">'
            . '<tr><td style="padding:14px 16px;">'
            . '<div style="font-size:11px; font-weight:700; color:#6B7280; letter-spacing:1px; text-transform:uppercase;">' . $label . '</div>'
            . '<div style="font-size:24px; font-weight:800; color:#0F172A; margin-top:4px;">' . $value . '</div>'
            . '<div style="font-size:12px; color:#6B7280;">' . $hint . '</div>'
            . '</td></tr></table>';
    }
}
