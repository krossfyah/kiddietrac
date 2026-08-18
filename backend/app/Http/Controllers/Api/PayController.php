<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmailTemplate;
use Dompdf\Dompdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Staff pay / payslips (2026-07-21).
 *
 * KiddieTrac holds HOURS (time_punches) and VISITS (home_visit_reports) but no
 * stored payslips. This computes gross pay on the fly per weekly pay period from
 * those records × the staff member's pay_rate:
 *   - hourly  (educators/directors) → gross = hours worked × rate
 *   - per_visit (home visitors)     → gross = visits logged × rate
 * Gross only — no tax/deduction rules are modelled. Admins set the rate per staff.
 */
class PayController extends Controller
{
    private const TZ = 'America/Toronto';

    /** Resolve a user's pay profile (rate + type, with a sensible default type). */
    private function profile(int $userId): array
    {
        // Delegated: the stored payroll ledger reads the same profile, and two
        // copies of a pay rule is two answers to one question.
        return \App\Support\Payroll::profile($userId);
    }

    /** Fetch an agency logo and inline it as a data: URI (avoids Dompdf remote
     *  fetching + is reliable in the PDF). Returns '' if unavailable. */
    private function logoDataUri(?string $url): string
    {
        if (!$url || !preg_match('#^https?://#i', $url) || !function_exists('curl_init')) return '';
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,   // host has no IPv6 route
            ]);
            $data = curl_exec($ch);
            $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($data === false || $code >= 400 || strlen($data) > 3000000) return '';
            if (!str_starts_with($type, 'image/')) return '';
            return 'data:' . $type . ';base64,' . base64_encode($data);
        } catch (\Throwable $e) { return ''; }
    }

    /** The caller's active agency id (0 if none). */
    private function agencyIdFor(int $userId): int
    {
        return (int) (DB::table('role_assignments')->where('user_id', $userId)->where('active', true)->whereNotNull('agency_id')->value('agency_id') ?? 0);
    }

    /** Units worked in a [start,end] local-date window for a pay type. */
    private function units(int $userId, string $type, string $start, string $end): float
    {
        return \App\Support\Payroll::units($userId, $type, $start, $end);
    }

    /** GET /api/v1/me/payslips — the caller's own weekly payslips (last 12 weeks with activity). */
    public function myPayslips(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $p = $this->profile($uid);
        $monday = Carbon::now(self::TZ)->startOfWeek(Carbon::MONDAY);
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $s = $monday->copy()->subWeeks($i);
            $start = $s->toDateString();
            $end = $s->copy()->addDays(6)->toDateString();
            $units = $this->units($uid, $p['type'], $start, $end);
            if ($units <= 0) continue;
            $rows[] = [
                'period_start' => $start,
                'period_end' => $end,
                'units' => $units,
                'unit_label' => $p['unit_label'],
                'rate' => $p['rate'],
                'gross' => round($units * $p['rate'], 2),
            ];
        }
        return response()->json([
            'rate' => $p['rate'],
            'pay_type' => $p['type'],
            'unit_label' => $p['unit_label'],
            'rate_set' => $p['rate'] > 0,
            'payslips' => $rows,
        ]);
    }

    /** GET /api/v1/me/payslips/{start}/pdf — a downloadable PDF payslip for one week. */
    public function payslipPdf(Request $request, string $start)
    {
        $uid = (int) $request->user()->id;
        $p = $this->profile($uid);
        try { $s = Carbon::parse($start, self::TZ)->startOfWeek(Carbon::MONDAY); } catch (\Throwable $e) { abort(400, 'Bad date'); }
        $start = $s->toDateString();
        $end = $s->copy()->addDays(6)->toDateString();
        $units = $this->units($uid, $p['type'], $start, $end);
        $gross = round($units * $p['rate'], 2);
        $name = trim(($p['user']->first_name ?? '') . ' ' . ($p['user']->last_name ?? '')) ?: 'Staff member';

        $agencyId = $this->agencyIdFor($uid);
        $agency = $agencyId ? DB::table('agencies')->where('id', $agencyId)->first() : null;
        $agencyName = $agency->name ?? 'KiddieTrac';
        $settings = ($agency && $agency->settings) ? (json_decode($agency->settings, true) ?: []) : [];
        $agencyAddr = trim((string) ($settings['brand_address'] ?? ''));
        $agencyContact = trim(implode(' · ', array_filter([$agency->contact_email ?? '', $agency->contact_phone ?? ''])));
        $note = trim((string) ($settings['payslip_note'] ?? ''));
        $confidential = trim((string) ($settings['payslip_confidential'] ?? '')) ?: 'Private & Confidential — this pay statement contains personal information intended solely for the named employee. Please store or dispose of it securely.';
        $logo = $this->logoDataUri($agency->brand_logo_url ?? ($agency->logo_url ?? null));

        $ex = $p['extras'];
        $addrParts = array_values(array_filter([
            trim((string) ($ex['address_line1'] ?? ($ex['address'] ?? ''))),
            trim(implode(', ', array_filter([($ex['city'] ?? ''), ($ex['province'] ?? ($ex['state'] ?? ''))]))),
            trim((string) ($ex['postal_code'] ?? ($ex['postal'] ?? ''))),
        ], fn ($s) => $s !== ''));
        $e = fn ($s) => htmlspecialchars((string) $s);
        $empAddr = implode('<br>', array_map($e, $addrParts));
        $empPhone = trim((string) ($p['user']->phone ?? ''));

        $money = fn ($n) => '$' . number_format((float) $n, 2);
        $period = Carbon::parse($start)->format('M j') . ' – ' . Carbon::parse($end)->format('M j, Y');
        $issued = now(self::TZ)->format('M j, Y');
        $rateLabel = $p['type'] === 'per_visit' ? ($money($p['rate']) . ' / visit')
            : ($p['type'] === 'salary' ? ($money($p['rate']) . ' / period') : ($money($p['rate']) . ' / hour'));
        $descr = $p['type'] === 'per_visit' ? 'Home visits' : ($p['type'] === 'salary' ? 'Salary (this period)' : 'Hours worked');
        $unitsDisplay = $p['type'] === 'salary' ? '1 period' : ($units . ' ' . $p['unit_label']);
        $payTypeLabel = $p['type'] === 'per_visit' ? 'Per visit' : ($p['type'] === 'salary' ? 'Salary' : 'Hourly');

        $html = '<html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,Arial,sans-serif;color:#0D1B2A;font-size:12px;line-height:1.5;}'
            . '.hdr{width:100%;border-collapse:collapse;border-bottom:3px solid #1F6080;padding-bottom:6px;}'
            . '.hdr td{border:none;padding:0 0 10px;vertical-align:top;}'
            . '.logo{width:64px;} .logo img{width:56px;height:56px;object-fit:contain;border-radius:8px;}'
            . '.co .nm{font-size:19px;font-weight:800;color:#1F6080;} .co .ad{color:#6B7280;font-size:10.5px;line-height:1.4;}'
            . '.meta{text-align:right;color:#6B7280;font-size:10.5px;}'
            . '.conf{margin:12px 0;padding:7px 12px;background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;color:#92400E;font-size:10px;font-weight:700;letter-spacing:.3px;}'
            . '.grid{width:100%;border-collapse:collapse;margin-top:4px;} .grid td{border:none;width:50%;vertical-align:top;padding:0 16px 0 0;}'
            . '.lbl{font-size:9px;color:#94A3B8;text-transform:uppercase;letter-spacing:.6px;font-weight:700;margin-bottom:3px;}'
            . 'table.earn{width:100%;border-collapse:collapse;margin-top:16px;}'
            . 'table.earn th{background:#1F6080;color:#fff;font-size:10px;text-transform:uppercase;padding:8px 10px;text-align:left;}'
            . 'table.earn td{padding:9px 10px;border-bottom:1px solid #E5E7EB;text-align:left;}'
            . '.tot{font-size:18px;font-weight:800;color:#1F6080;}'
            . '.note{margin-top:16px;padding:11px 14px;background:#F1F6F9;border-left:3px solid #1F6080;border-radius:6px;color:#334155;font-size:11.5px;}'
            . '.foot{margin-top:20px;color:#94A3B8;font-size:10px;line-height:1.5;border-top:1px solid #E5E7EB;padding-top:10px;}'
            . '</style></head><body>'
            . '<table class="hdr"><tr>'
            . ($logo ? '<td class="logo"><img src="' . $logo . '"></td>' : '')
            . '<td class="co"><div class="nm">' . $e($agencyName) . '</div>'
            . ($agencyAddr ? '<div class="ad">' . nl2br($e($agencyAddr)) . '</div>' : '')
            . ($agencyContact ? '<div class="ad">' . $e($agencyContact) . '</div>' : '') . '</td>'
            . '<td class="meta"><div style="font-size:11px;letter-spacing:1px;color:#1F6080;font-weight:800;">PAY STATEMENT</div>'
            . '<div style="margin-top:5px;">Pay period</div><strong style="color:#0D1B2A;font-size:12px;">' . $e($period) . '</strong>'
            . '<div style="margin-top:5px;">Issued ' . $e($issued) . '</div></td>'
            . '</tr></table>'
            . '<div class="conf">' . $e($confidential) . '</div>'
            . '<table class="grid"><tr>'
            . '<td><div class="lbl">Employee</div><strong>' . $e($name) . '</strong><br>'
            . ($empAddr ? $empAddr . '<br>' : '')
            . '<span style="color:#6B7280;">' . $e($p['user']->email ?? '') . '</span>'
            . ($empPhone ? '<br><span style="color:#6B7280;">' . $e($empPhone) . '</span>' : '') . '</td>'
            . '<td><div class="lbl">Pay details</div>'
            . 'Employee ID: <strong>' . $uid . '</strong><br>'
            . 'Pay type: ' . $e($payTypeLabel) . '<br>'
            . 'Pay period: ' . $e($period) . '<br>'
            . 'Pay date: ' . $e($issued) . '</td>'
            . '</tr></table>'
            . '<table class="earn"><thead><tr><th>Description</th><th>Units</th><th>Rate</th><th style="text-align:right;">Amount</th></tr></thead><tbody>'
            . '<tr><td>' . $e($descr) . '</td><td>' . $e($unitsDisplay) . '</td><td>' . $e($rateLabel) . '</td>'
            . '<td style="text-align:right;">' . $money($gross) . '</td></tr>'
            . '</tbody></table>'
            . '<table style="width:100%;border-collapse:collapse;margin-top:2px;"><tr><td style="border:none;text-align:right;color:#6B7280;">Gross pay</td>'
            . '<td style="border:none;text-align:right;width:130px;" class="tot">' . $money($gross) . '</td></tr></table>'
            . ($note ? '<div class="note">' . nl2br($e($note)) . '</div>' : '')
            . '<div class="foot">This statement shows <strong>gross pay before applicable taxes and statutory deductions</strong>. '
            . $e($confidential) . ' Generated by ' . $e($agencyName) . ' via KiddieTrac on ' . $e($issued) . '. Questions about your pay? Contact your administrator.</div>'
            . '</body></html>';

        $pdf = new Dompdf();
        $pdf->loadHtml($html);
        $pdf->setPaper('letter');
        $pdf->render();
        $filename = 'payslip-' . $start . '.pdf';
        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** PATCH /api/v1/admin/users/{user}/pay — set a staff member's pay rate + type. */
    public function adminSetPay(Request $request, int $user): JsonResponse
    {
        $data = $request->validate([
            'pay_rate' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'pay_type' => ['nullable', 'in:hourly,per_visit,salary'],
        ]);
        // Tenant guard: the target must be a member of an agency the caller admins.
        $caller = $request->user()->id;
        $isPlatform = DB::table('role_assignments')->where('user_id', $caller)->where('role', 'platform_admin')->where('active', true)->exists();
        if (! $isPlatform) {
            $callerAgencies = DB::table('role_assignments')->where('user_id', $caller)
                ->whereIn('role', ['agency_admin', 'centre_director'])->where('active', true)
                ->whereNotNull('agency_id')->pluck('agency_id')->all();
            $targetInAgency = DB::table('role_assignments')->where('user_id', $user)
                ->whereIn('agency_id', $callerAgencies ?: [0])->where('active', true)->exists();
            if (! $targetInAgency) return response()->json(['message' => 'Forbidden'], 403);
        }
        DB::table('users')->where('id', $user)->update([
            'pay_rate' => $data['pay_rate'] ?? null,
            'pay_type' => $data['pay_type'] ?? null,
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Pay updated']);
    }

    /** Can this caller manage agency-wide payslip settings? platform_admin, or an
     *  agency_admin / centre_director of their own agency. Returns the agency id
     *  they manage (0 if not allowed / none). */
    private function payAdminAgency(int $userId): int
    {
        $isPlatform = DB::table('role_assignments')->where('user_id', $userId)->where('role', 'platform_admin')->where('active', true)->exists();
        $agencyId = $this->agencyIdFor($userId);
        if ($isPlatform) return $agencyId;
        $ok = DB::table('role_assignments')->where('user_id', $userId)
            ->whereIn('role', ['agency_admin', 'centre_director'])->where('active', true)
            ->whereNotNull('agency_id')->exists();
        return $ok ? $agencyId : 0;
    }

    private const CONFIDENTIAL_DEFAULT = 'Private & Confidential — this pay statement contains personal information intended solely for the named employee. Please store or dispose of it securely.';

    /** GET /api/v1/agency/payslip-settings — the configurable payslip wording (admin/director). */
    public function payslipSettings(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $agencyId = $this->payAdminAgency($uid);
        if (! $agencyId) return response()->json(['message' => 'Forbidden'], 403);
        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $settings = ($agency && $agency->settings) ? (json_decode($agency->settings, true) ?: []) : [];
        return response()->json([
            'note' => (string) ($settings['payslip_note'] ?? ''),
            'confidential' => (string) ($settings['payslip_confidential'] ?? ''),
            'confidential_default' => self::CONFIDENTIAL_DEFAULT,
        ]);
    }

    /** POST /api/v1/agency/payslip-settings — save the note + confidential wording (admin/director). */
    public function savePayslipSettings(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $agencyId = $this->payAdminAgency($uid);
        if (! $agencyId) return response()->json(['message' => 'Forbidden'], 403);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'confidential' => ['nullable', 'string', 'max:600'],
        ]);
        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $settings = ($agency && $agency->settings) ? (json_decode($agency->settings, true) ?: []) : [];
        $settings['payslip_note'] = trim((string) ($data['note'] ?? ''));
        $settings['payslip_confidential'] = trim((string) ($data['confidential'] ?? ''));
        DB::table('agencies')->where('id', $agencyId)->update([
            'settings' => json_encode($settings),
            'updated_at' => now(),
        ]);
        return response()->json(['ok' => true]);
    }
}
