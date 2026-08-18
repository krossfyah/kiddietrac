<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Renders the admin digest.
 *
 * The charts are TABLES with background colours, not images and not SVG. Gmail strips
 * <svg>, Outlook ignores most of CSS, and a chart served as an <img> from our own host is
 * blocked until the reader clicks "show images" — which they will not. A table with a
 * coloured cell is the one bar chart that renders everywhere, including in the dark-mode
 * palette the shell paints.
 *
 * Every block also states the number in text beside the bar. A chart nobody can see must
 * still be readable, and that includes a screen reader.
 */
final class AdminDigestHtml
{
    private const ACCENTS = [
        'late' => '#DC2626', 'timeoff' => '#F59E0B', 'tasks' => '#7C3AED', 'tours' => '#0EA5E9',
        'incidents' => '#DC2626', 'immunisations' => '#F59E0B', 'tickets' => '#64748B',
        'welcome' => '#16A34A', 'week' => '#1F6FB2', 'reportCards' => '#7C3AED',
    ];

    public static function render(array $s, string $periodLabel): string
    {
        if (! $s) {
            return '<p style="font-size:15px;line-height:1.6;color:#334155;">'
                . 'Nothing needs your attention ' . e($periodLabel) . '. Everything logged is up to date.</p>';
        }

        $html = '<p style="font-size:15px;line-height:1.6;color:#334155;margin:0 0 6px;">'
            . 'Here is what needs a decision or a nudge ' . e($periodLabel) . '.</p>';

        // The overview bar is the whole digest in one glance: if a director reads only
        // this, they still know where the pressure is today.
        $counts = array_filter([
            'Late pick-ups' => $s['late']['count'] ?? 0,
            'Time off' => $s['timeoff']['count'] ?? 0,
            'Open tasks' => $s['tasks']['count'] ?? 0,
            'Incidents' => $s['incidents']['count'] ?? 0,
            'Support' => $s['tickets']['count'] ?? 0,
            'To invite' => $s['welcome']['count'] ?? 0,
        ]);
        if ($counts) {
            $html .= self::barChart('Where things stand', $counts, '#1F6FB2');
        }

        $html .= self::section('⏰ Late pick-ups awaiting your decision', $s['late'] ?? null, 'late',
            'Approve with a fee, waive, or decline — nothing is charged until you do.');
        $html .= self::section('🌴 Time off waiting on approval', $s['timeoff'] ?? null, 'timeoff');
        $html .= self::section('📋 Tasks still open', $s['tasks'] ?? null, 'tasks');
        $html .= self::section('🚸 New tour bookings', $s['tours'] ?? null, 'tours');
        $html .= self::section('⚠️ Incidents recorded', $s['incidents'] ?? null, 'incidents');
        $html .= self::section('💉 Children with no immunisation record', $s['immunisations'] ?? null, 'immunisations');
        $html .= self::section('📅 The week ahead', $s['week'] ?? null, 'week');
        $html .= self::section('✉️ Families still to invite', $s['welcome'] ?? null, 'welcome',
            'These families have no guardian account yet, so they cannot see anything you post.');
        $html .= self::section('📝 Report cards due', $s['reportCards'] ?? null, 'reportCards',
            'These children leave within the month and have no report card written.');
        $html .= self::section('🎫 Open support tickets', $s['tickets'] ?? null, 'tickets');

        // Billing
        if (! empty($s['invoicing'])) {
            $inv = $s['invoicing'];
            $body = '';
            if (! empty($inv['plans'])) {
                $labels = [];
                foreach ($inv['plans'] as $freq => $n) { $labels[ucfirst((string) $freq)] = $n; }
                $body .= self::barChart('Fee plans by billing cycle', $labels, '#16A34A');
            }
            if (! empty($inv['unbilled_families'])) {
                $body .= '<div style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:9px;padding:11px 13px;font-size:13.5px;color:#92400E;margin-top:8px;">'
                    . '<strong>' . (int) $inv['unbilled_families'] . ' famil' . ($inv['unbilled_families'] === 1 ? 'y has' : 'ies have')
                    . ' had no invoice raised this period.</strong> Worth a bulk run before the cycle closes.</div>';
            }
            if ($body !== '') {
                $html .= self::wrap('💸 Billing', $body);
            }
        }

        // Forms completed, and by whom
        if (! empty($s['forms'])) {
            $f = $s['forms'];
            $body = '<div style="font-size:14px;color:#334155;margin:0 0 8px;"><strong>' . (int) $f['count']
                . '</strong> form' . ($f['count'] === 1 ? '' : 's') . ' completed.</div>';
            if (! empty($f['people'])) {
                $body .= self::barChart('Who completed them', $f['people'], '#0E7C90');
            }
            $html .= self::wrap('🗂️ Forms completed', $body);
        }

        // Educators — the only section that offers advice rather than a list of work.
        if (! empty($s['educators']['rows'])) {
            $body = '';
            foreach ($s['educators']['rows'] as $r) {
                $score = (int) $r['score'];
                $colour = $score < 40 ? '#DC2626' : ($score < 60 ? '#F59E0B' : ($score < 75 ? '#0EA5E9' : '#16A34A'));
                $body .= '<div style="border-left:3px solid ' . $colour . ';padding:8px 12px;margin-bottom:8px;background:#F8FAFC;border-radius:0 9px 9px 0;">'
                    . '<div style="font-size:14px;font-weight:800;color:#0F172A;">' . e($r['who'])
                    . ' <span style="color:' . $colour . ';">' . $score . '</span></div>'
                    . self::bar($score, 100, $colour)
                    . '<div style="font-size:13px;color:#475569;line-height:1.5;margin-top:5px;">' . e($r['tip']) . '</div></div>';
            }
            $html .= self::wrap('🌱 Educators who could use a hand', $body);
        }

        return $html;
    }

    // ── Building blocks ─────────────────────────────────────────────────────

    private static function wrap(string $title, string $inner): string
    {
        return '<div class="kt-panel" style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:11px;padding:14px 16px;margin:16px 0 0;">'
            . '<div style="font-size:14.5px;font-weight:800;color:#0F172A;margin:0 0 8px;">' . e($title) . '</div>'
            . $inner . '</div>';
    }

    private static function section(string $title, ?array $data, string $key, string $blurb = ''): string
    {
        // Nothing outstanding means the section is not printed at all — a row of zeroes
        // teaches people to skim past the sections that do matter.
        if (! $data || empty($data['rows'])) { return ''; }
        $accent = self::ACCENTS[$key] ?? '#1F6FB2';

        $inner = '';
        if ($blurb !== '') {
            $inner .= '<div class="kt-muted" style="font-size:12.5px;color:#64748B;margin:0 0 8px;">' . e($blurb) . '</div>';
        }
        $inner .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">';
        foreach ($data['rows'] as $r) {
            $inner .= '<tr>'
                . '<td style="padding:5px 8px 5px 0;font-size:13.5px;color:#0F172A;font-weight:700;white-space:nowrap;vertical-align:top;">' . e((string) $r['who']) . '</td>'
                . '<td style="padding:5px 8px;font-size:13.5px;color:#334155;vertical-align:top;">' . e((string) $r['what']) . '</td>'
                . '<td class="kt-muted" style="padding:5px 0;font-size:12.5px;color:#64748B;text-align:right;white-space:nowrap;vertical-align:top;">' . e((string) $r['detail']) . '</td>'
                . '</tr>';
        }
        $inner .= '</table>';

        $count = (int) ($data['count'] ?? count($data['rows']));
        $shown = count($data['rows']);
        if ($count > $shown) {
            $inner .= '<div class="kt-muted" style="font-size:12.5px;color:#64748B;margin-top:6px;">and ' . ($count - $shown) . ' more.</div>';
        }

        return '<div class="kt-panel" style="background:#F8FAFC;border:1px solid #E2E8F0;border-left:4px solid ' . $accent . ';border-radius:0 11px 11px 0;padding:14px 16px;margin:16px 0 0;">'
            . '<div style="font-size:14.5px;font-weight:800;color:#0F172A;margin:0 0 8px;">' . e($title)
            . ' <span style="background:' . $accent . ';color:#fff;border-radius:999px;font-size:11.5px;padding:1px 8px;margin-left:4px;">' . $count . '</span></div>'
            . $inner . '</div>';
    }

    /** One bar. A table cell with a background is the only chart primitive email agrees on. */
    private static function bar(float $value, float $max, string $colour): string
    {
        $pct = $max > 0 ? max(2, min(100, round($value / $max * 100))) : 2;
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:3px;">'
            . '<tr><td style="background:#E2E8F0;border-radius:5px;padding:0;">'
            . '<table role="presentation" width="' . $pct . '%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
            . '<tr><td style="background:' . $colour . ';height:8px;line-height:8px;font-size:0;border-radius:5px;">&nbsp;</td></tr>'
            . '</table></td></tr></table>';
    }

    /** @param array<string,int|float> $data */
    private static function barChart(string $title, array $data, string $colour): string
    {
        if (! $data) { return ''; }
        $max = max(array_map('floatval', $data)) ?: 1;
        $rows = '';
        foreach ($data as $label => $value) {
            $rows .= '<tr>'
                . '<td style="padding:4px 10px 4px 0;font-size:12.5px;color:#334155;white-space:nowrap;width:38%;">' . e((string) $label) . '</td>'
                . '<td style="padding:4px 0;width:52%;">' . self::bar((float) $value, (float) $max, $colour) . '</td>'
                // The number in text beside the bar: a chart that does not render must
                // still be readable, and that includes to a screen reader.
                . '<td style="padding:4px 0 4px 8px;font-size:13px;font-weight:800;color:#0F172A;text-align:right;white-space:nowrap;">' . (int) $value . '</td>'
                . '</tr>';
        }
        return '<div style="margin:14px 0 0;">'
            . '<div class="kt-muted" style="font-size:11px;font-weight:800;letter-spacing:.8px;color:#64748B;text-transform:uppercase;margin:0 0 4px;">' . e($title) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">' . $rows . '</table></div>';
    }
}
