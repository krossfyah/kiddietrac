<?php

namespace App\Console\Commands;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use App\Support\UsageSpend;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The superadmin weekly summary (2026-10-20): every agency's texts, phone calls and AI
 * spend for the last seven days, with the agencies over their threshold at the top.
 * Platform admins only — agencies never see platform costs. Mondays 07:15 Toronto.
 *
 *   php artisan platform:usage-summary --test=you@example.com   (one address, nothing else)
 *   php artisan platform:usage-summary --dry                    (print, send nothing)
 */
class PlatformUsageSummaryCommand extends Command
{
    protected $signature = 'platform:usage-summary
        {--test= : Send only to this address}
        {--dry : Print the figures and send nothing}
        {--days=7 : How many days back}';

    protected $description = 'Weekly usage & spend summary (texts, calls, AI) to platform admins';

    public function handle(): int
    {
        $days = max(1, min(31, (int) $this->option('days')));
        $tz = 'America/Toronto';
        $end = Carbon::now($tz)->startOfDay();
        $start = $end->copy()->subDays($days);
        $rows = UsageSpend::period($start->copy()->utc()->format('Y-m-d H:i:s'), $end->copy()->utc()->subSecond()->format('Y-m-d H:i:s'));
        $over = array_values(array_filter($rows, fn ($r) => $r['over']));

        foreach ($rows as $r) {
            $this->line(sprintf('  %-34s texts $%7.2f  calls $%7.2f  AI $%7.2f%s', mb_substr($r['name'], 0, 34),
                $r['cost']['sms'], $r['cost']['voice'], $r['cost']['ai'], $r['over'] ? '  OVER: ' . implode(',', $r['over']) : ''));
        }
        if ($this->option('dry')) return self::SUCCESS;

        $label = $start->format('M j') . ' – ' . $end->copy()->subDay()->format('M j, Y');
        $html = EmailTemplate::wrap(null, self::body($rows, $over, $label), [
            'eyebrow' => 'PLATFORM WEEKLY SUMMARY',
            'title' => 'Usage & spend',
            'subtitle' => $label,
            'preheader' => $over ? count($over) . ' agenc' . (count($over) === 1 ? 'y is' : 'ies are') . ' over a usage threshold' : 'Texts, calls and AI spend across all agencies',
        ]);
        $subject = ($over ? '⚠️ ' . count($over) . ' over threshold · ' : '📊 ') . 'Platform weekly summary · ' . $label;

        $test = $this->option('test');
        $people = $test
            ? [(object) ['email' => $test, 'first_name' => 'Test', 'last_name' => '']]
            : DB::table('users as u')->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
                ->where('ra.role', 'platform_admin')->where('ra.active', 1)
                ->whereNull('u.deleted_at')->whereNotNull('u.email')
                ->distinct()->get(['u.id', 'u.email', 'u.first_name', 'u.last_name']);

        $sent = 0;
        foreach ($people as $p) {
            try {
                AgencyMailer::forAgency(null)->html($html, function ($m) use ($p, $subject, $test) {
                    $m->to($p->email, trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? '')))->subject($subject);
                    if ($test) { try { $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1'); } catch (\Throwable $e) {} }
                });
                $sent++;
                $this->line('  sent to ' . $p->email);
            } catch (\Throwable $e) {
                $this->warn('  failed ' . $p->email . ': ' . $e->getMessage());
            }
        }
        $this->info("Sent {$sent}");
        return self::SUCCESS;
    }

    private static function money(float $v): string { return '$' . number_format($v, 2); }

    public static function body(array $rows, array $over, string $label): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $cat = UsageSpend::CATEGORIES;
        $h = '<p style="margin:0 0 14px;font-size:14px;color:#334155;">Texts, phone calls and AI for every agency, ' . $e($label) . '. Costs use the rates set in Reseller &rarr; Usage &amp; spend.</p>';

        if ($over) {
            $h .= '<div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:12px;padding:12px 14px;margin:0 0 16px;">'
                . '<div style="font-weight:800;color:#991B1B;font-size:14px;margin-bottom:6px;">⚠️ Over threshold</div>';
            foreach ($over as $r) {
                $parts = array_map(fn ($c) => $e($cat[$c]) . ' ' . self::money($r['cost'][$c]) . ' <span style="color:#B91C1C;">(limit ' . self::money($r['threshold'][$c]) . ')</span>', $r['over']);
                $h .= '<div style="font-size:13.5px;color:#334155;padding:3px 0;"><b>' . $e($r['name']) . '</b>: ' . implode(' · ', $parts) . '</div>';
            }
            $h .= '</div>';
        } else {
            $h .= '<div style="background:#ECFDF5;border:1px solid #A7F3D0;border-radius:12px;padding:10px 14px;margin:0 0 16px;font-size:13.5px;color:#065F46;">✅ No agency went over a threshold this week.</div>';
        }

        if (! $rows) {
            return $h . '<p style="font-size:13.5px;color:#64748B;">No texts, calls or AI use this week.</p>';
        }
        $th = 'style="text-align:right;padding:7px 8px;font-size:11px;color:#64748B;text-transform:uppercase;border-bottom:1px solid #E2E8F0;"';
        $h .= '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:13px;">'
            . '<tr><th style="text-align:left;padding:7px 8px;font-size:11px;color:#64748B;text-transform:uppercase;border-bottom:1px solid #E2E8F0;">Agency</th>'
            . '<th ' . $th . '>Texts</th><th ' . $th . '>Calls</th><th ' . $th . '>AI</th><th ' . $th . '>Total</th></tr>';
        $tot = ['sms' => 0, 'voice' => 0, 'ai' => 0];
        foreach ($rows as $r) {
            $cell = function ($c, $detail) use ($r) {
                $red = in_array($c, $r['over'], true);
                return '<td style="text-align:right;padding:7px 8px;border-bottom:1px solid #F1F5F9;' . ($red ? 'color:#B91C1C;font-weight:800;' : 'color:#334155;') . '">'
                    . self::money($r['cost'][$c]) . '<div style="font-size:11px;color:#94A3B8;font-weight:400;">' . $detail . '</div></td>';
            };
            foreach ($tot as $k => $v) $tot[$k] += $r['cost'][$k];
            $h .= '<tr><td style="padding:7px 8px;border-bottom:1px solid #F1F5F9;font-weight:700;color:#0F172A;">' . $e($r['name']) . '</td>'
                . $cell('sms', number_format($r['texts']) . ' texts · ' . number_format($r['segments']) . ' seg')
                . $cell('voice', number_format($r['calls']) . ' calls · ' . number_format($r['minutes']) . ' min')
                . $cell('ai', number_format($r['ai_requests']) . ' requests')
                . '<td style="text-align:right;padding:7px 8px;border-bottom:1px solid #F1F5F9;font-weight:800;color:#0F172A;">' . self::money($r['total']) . '</td></tr>';
        }
        $h .= '<tr><td style="padding:8px;font-weight:800;">All agencies</td>'
            . '<td style="text-align:right;padding:8px;font-weight:800;">' . self::money($tot['sms']) . '</td>'
            . '<td style="text-align:right;padding:8px;font-weight:800;">' . self::money($tot['voice']) . '</td>'
            . '<td style="text-align:right;padding:8px;font-weight:800;">' . self::money($tot['ai']) . '</td>'
            . '<td style="text-align:right;padding:8px;font-weight:800;">' . self::money(array_sum($tot)) . '</td></tr></table>';
        return $h;
    }
}
