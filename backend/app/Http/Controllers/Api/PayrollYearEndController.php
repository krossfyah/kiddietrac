<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\Features;
use App\Support\HrLeave;
use App\Support\Payroll\PayrollEmployer;
use App\Support\Payroll\PayrollTax;
use App\Support\Payroll\YearEnd;
use App\Support\Payroll\YearEndPdf;
use App\Support\Payroll\YearEndXml;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 3 (2026-11-27): Canadian year end - T4 / T4A / RL-1 slips, summaries, the CRA XML file,
 * amendments and My HR copies. Routes under hr/payroll/year-end (the `hr` add-on MAP) and hr/me/tax-slips.
 *
 * Who: agency admins generate, edit drafts, finalize, amend / cancel, download the summaries and the CRA file. Directors
 * (with the payroll feature) see the slips of people at their own centres, read-only, with the SIN masked. Everyone sees
 * their own FINAL slips in My HR. Nobody edits, amends or finalizes their own slip.
 * SIN in full: only the slip PDF (for the person, or an agency admin) and the XML file. Every generate / finalize / edit /
 * amend / cancel / download is audited. Engine off = {enabled:false} (reads) / 403 (writes).
 */
class PayrollYearEndController extends Controller
{
    use HrAccess;

    private function on(Request $request): int
    {
        $aid = $this->hrAgency($request);
        abort_unless(PayrollTax::engineOn($aid), 403, 'Payroll is not switched on for your agency.');

        return $aid;
    }

    private function needPay(Request $request, int $aid): void
    {
        $this->hrNeedManager($request);
        abort_unless(Features::on($aid, 'payroll'), 403, 'Payroll is not included in your agency\'s plan.');
    }

    private function me(Request $request): int
    {
        return (int) $request->user()->id;
    }

    /** The tax year asked for, or the one year-end work is about right now (Jan - Apr: last year). */
    private function year(Request $request, int $aid): int
    {
        $y = (int) $request->input('year', 0);
        if ($y >= 2026 && $y <= 2100) {
            return $y;
        }
        $now = AgencyTime::now($aid);

        return (int) $now->format('n') <= 4 ? (int) $now->format('Y') - 1 : (int) $now->format('Y');
    }

    /** A slip this caller may see (admin: agency; director: their centres' people), else 404. */
    private function slip(Request $request, int $aid, int $id): object
    {
        $s = DB::table('payroll_slips')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($s, 404, 'Not found.');
        if (! $this->hrIsAdmin($request)) {
            abort_unless($this->directorSees($request, $aid, $s), 404, 'Not found.');
        }

        return $s;
    }

    private function directorSees(Request $request, int $aid, object $s): bool
    {
        $scope = CentreScope::ids($request, $aid) ?? [];

        return ($s->centre_id && in_array((int) $s->centre_id, $scope, true)) || in_array((int) $s->user_id, $this->hrPeople($request, $aid), true);
    }

    private function notOwn(Request $request, object $s): void
    {
        abort_if((int) $s->user_id === $this->me($request), 403, 'Another admin has to change your own tax slip.');
    }

    /** GET /hr/payroll/year-end?year= */
    public function overview(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        if (! PayrollTax::engineOn($aid)) {
            return response()->json(['enabled' => false]);
        }
        $this->needPay($request, $aid);
        $admin = $this->hrIsAdmin($request);
        $y = $this->year($request, $aid);
        $rows = DB::table('payroll_slips')->where('agency_id', $aid)->where('tax_year', $y)->where('status', '!=', 'discarded')
            ->orderBy('surname')->orderBy('given_name')->orderBy('kind')->orderBy('version')->get();
        if (! $admin) {
            $rows = $rows->filter(fn ($s) => $this->directorSees($request, $aid, $s))->values();
        }
        $names = $this->hrNames($rows->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all());
        $hints = DB::table('hr_staff_sin')->where('agency_id', $aid)->whereIn('user_id', $rows->pluck('user_id')->all() ?: [0])->pluck('sin_hint', 'user_id');
        $centres = DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id');
        $slips = $rows->map(function ($s) use ($names, $hints, $centres) {
            return YearEnd::shape($s, $names) + ['sin' => $hints[$s->user_id] ?? null, 'centre' => $s->centre_id ? ($centres[$s->centre_id] ?? null) : null];
        })->values();
        $ye = DB::table('payroll_year_ends')->where('agency_id', $aid)->where('tax_year', $y)->first();
        $today = AgencyTime::today($aid);
        $dl = YearEnd::deadline($y);
        $out = ['enabled' => true, 'year' => $y, 'years' => array_values(array_unique([$y - 1, $y, $y + 1])), 'today' => $today, 'deadline' => $dl,
            'days_left' => (int) Carbon::parse($today)->diffInDays(Carbon::parse($dl), false), 'year_ended' => YearEnd::yearEnded($aid, $y),
            'status' => $ye->status ?? 'none', 'generated_at' => $ye->generated_at ?? null, 'finalized_at' => $ye->finalized_at ?? null,
            'slips' => $slips, 'admin' => $admin, 'me' => $this->me($request),
            'outside_canada' => $this->outsideCanada($aid),
            'kinds' => YearEnd::KINDS, 'dental' => YearEnd::DENTAL, 'manual' => YearEnd::MANUAL];
        if ($admin) {
            $out['reconciliation'] = YearEnd::reconcile($aid, $y);
            $out['settings'] = YearEnd::settings($aid);
            $out['rp_number'] = PayrollEmployer::settings($aid)['rp_number'];
            $out['xml'] = ['schema' => YearEndXml::schemaFor($y), 'missing' => YearEndXml::missing($aid),
                'exports' => DB::table('payroll_xml_exports')->where('agency_id', $aid)->where('tax_year', $y)->orderByDesc('id')->limit(30)
                    ->get(['id', 'kind', 'report_type', 'sbmt_ref_id', 'schema_version', 'slip_ids', 'created_by_id', 'created_at'])
                    ->map(fn ($e) => ['id' => (int) $e->id, 'kind' => $e->kind, 'report_type' => $e->report_type, 'ref' => $e->sbmt_ref_id, 'schema' => $e->schema_version,
                        'slips' => count(json_decode((string) $e->slip_ids, true) ?: []), 'by' => $this->hrNames([(int) $e->created_by_id])[(int) $e->created_by_id] ?? null,
                        'at' => (string) $e->created_at])->all()];
            $out['summaries'] = ['T4' => YearEnd::summary($aid, $y, 'T4'), 'T4A' => YearEnd::summary($aid, $y, 'T4A'), 'RL1' => YearEnd::summary($aid, $y, 'RL1')];
        }

        return response()->json($out);
    }

    /** True when none of the agency's centres is in Canada (the payroll engine is Canadian only for now). */
    private function outsideCanada(int $aid): bool
    {
        $c = DB::table('centres')->where('agency_id', $aid)->pluck('country')->map(fn ($v) => strtoupper(trim((string) $v)))->all();

        return $c && ! array_intersect($c, ['CA', 'CAN', 'CANADA', '']);
    }

    /** PUT /hr/payroll/year-end/settings (admins): dental default (box 45), filing contact. */
    public function saveSettings(Request $request): JsonResponse
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $this->hrNeedAdmin($request);
        $d = $request->validate(['dental_code' => 'nullable|integer|min:1|max:5', 'contact_name' => 'nullable|string|max:35',
            'contact_phone' => 'nullable|string|max:30', 'contact_email' => 'nullable|email|max:60']);
        if (! empty($d['contact_phone'])) {
            abort_unless(YearEndXml::phone($d['contact_phone']), 422, 'Enter a 10-digit phone number, for example 416 555 0199.');
        }
        $s = YearEnd::saveSettings($aid, $d);
        YearEnd::note($aid, $this->me($request), 'payroll.year_end_settings', 'agency', $aid, 'Changed the year-end settings (dental benefits code, filing contact).');

        return response()->json(['ok' => true, 'settings' => $s]);
    }

    /** POST /hr/payroll/year-end/generate {year} (admins): build / rebuild the draft slips. */
    public function generate(Request $request): JsonResponse
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $this->hrNeedAdmin($request);
        $y = $this->year($request, $aid);
        $r = YearEnd::generate($aid, $y, $this->me($request));
        YearEnd::note($aid, $this->me($request), 'payroll.slips_generated', 'agency', $aid, 'Generated the ' . $y . ' draft tax slips: ' . $r['made']['T4'] . ' T4, '
            . $r['made']['T4A'] . ' T4A, ' . $r['made']['RL1'] . ' RL-1. Reconciliation ' . ($r['reconciliation']['ok'] ? 'passes' : 'does not pass yet') . '.', ['year' => $y]);

        return response()->json($r);
    }

    /** POST /hr/payroll/year-end/finalize {year} (admins): refused unless the reconciliation passes. */
    public function finalize(Request $request): JsonResponse
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $this->hrNeedAdmin($request);
        $y = $this->year($request, $aid);
        $own = DB::table('payroll_slips')->where('agency_id', $aid)->where('tax_year', $y)->where('status', 'draft')->where('user_id', $this->me($request))->exists();
        abort_if($own, 403, 'Your own slip is in this set, so another admin has to finalize it.');
        try {
            $n = YearEnd::finalize($aid, $y, $this->me($request));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            YearEnd::note($aid, $this->me($request), 'payroll.slips_finalize_refused', 'agency', $aid, 'Tried to finalize the ' . $y . ' tax slips: refused (' . $e->getMessage() . ')', ['year' => $y]);
            throw $e;
        }
        YearEnd::note($aid, $this->me($request), 'payroll.slips_finalized', 'agency', $aid, 'Finalized the ' . $y . ' tax slips: ' . $n['final'] . ' slip(s) for ' . $n['people'] . ' people'
            . ($n['replaced'] ? ', ' . $n['replaced'] . ' amended' : '') . ($n['cancelled'] ? ', ' . $n['cancelled'] . ' cancelled' : '') . '.', ['year' => $y]);

        return response()->json(['ok' => true] + $n);
    }

    /** PUT /hr/payroll/year-end/slips/{id} (admins, drafts): the boxes with no payroll source (44, 46, 52, 20, 50, 45, 29, recipient BN). */
    public function saveSlip(Request $request, int $id): JsonResponse
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $this->hrNeedAdmin($request);
        $s = $this->slip($request, $aid, $id);
        $this->notOwn($request, $s);
        abort_unless($s->status === 'draft' && $s->report_type !== 'C', 422, 'A finalized slip is never changed: amend it instead.');
        $d = $request->validate(['20' => 'nullable|numeric|min:0|max:100000', '44' => 'nullable|numeric|min:0|max:100000', '46' => 'nullable|numeric|min:0|max:100000',
            '52' => 'nullable|numeric|min:0|max:100000', '50' => ['nullable', 'regex:/^\d{7}$/'], '45' => 'nullable|integer|min:1|max:5',
            '29' => 'nullable|integer|in:11,12,13,14,15,16,17', 'rcpnt_bn' => ['nullable', 'regex:/^\d{9}[A-Za-z]{2}\d{4}$/']]);
        $m = array_filter($d, fn ($v) => $v !== null && $v !== '');
        $boxes = json_decode((string) $s->boxes, true) ?: [];
        $codes = json_decode((string) $s->codes, true) ?: [];
        foreach (['20', '44', '46', '52'] as $k) {
            unset($boxes[$k]);
        }
        unset($codes['29'], $codes['50']);
        if ($s->kind === 'T4') {
            $codes['45'] = YearEnd::settings($aid)['dental_code'];
            [$boxes, $codes] = YearEnd::applyManual($boxes, $codes, $m);
        } elseif ($s->kind === 'RL1') {
            $m = array_intersect_key($m, ['45' => 1]);
        } else {
            $m = array_intersect_key($m, ['rcpnt_bn' => 1]);
        }
        DB::table('payroll_slips')->where('id', $id)->update(['manual' => json_encode($m ?: (object) []), 'boxes' => json_encode($boxes), 'codes' => json_encode($codes), 'updated_at' => now()]);
        YearEnd::note($aid, $this->me($request), 'payroll.slip_edited', 'payroll_slip', $id, 'Changed the typed boxes on a draft ' . $s->kind . ' ' . $s->tax_year . ' for '
            . ($this->hrNames([(int) $s->user_id])[(int) $s->user_id] ?? 'a staff member') . ' (' . (implode(', ', array_keys($m)) ?: 'cleared') . ').');

        return response()->json(['ok' => true, 'slip' => YearEnd::shape(DB::table('payroll_slips')->where('id', $id)->first())]);
    }

    /** POST /hr/payroll/year-end/slips/{id}/amend {reason} (admins). */
    public function amend(Request $request, int $id): JsonResponse
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $this->hrNeedAdmin($request);
        $s = $this->slip($request, $aid, $id);
        $this->notOwn($request, $s);
        $why = trim((string) $request->validate(['reason' => 'required|string|max:500'])['reason']);
        $new = YearEnd::amend($aid, $id, $this->me($request), $why);
        YearEnd::note($aid, $this->me($request), 'payroll.slip_amended', 'payroll_slip', $new, 'Started an amended ' . $s->kind . ' ' . $s->tax_year . ' for '
            . ($this->hrNames([(int) $s->user_id])[(int) $s->user_id] ?? 'a staff member') . ': ' . $why, ['replaces' => $id]);

        return response()->json(['ok' => true, 'id' => $new], 201);
    }

    /** POST /hr/payroll/year-end/slips/{id}/cancel {reason} (admins). */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $this->hrNeedAdmin($request);
        $s = $this->slip($request, $aid, $id);
        $this->notOwn($request, $s);
        $why = trim((string) $request->validate(['reason' => 'required|string|max:500'])['reason']);
        $new = YearEnd::cancel($aid, $id, $this->me($request), $why);
        YearEnd::note($aid, $this->me($request), 'payroll.slip_cancelled', 'payroll_slip', $new, 'Started cancelling the ' . $s->kind . ' ' . $s->tax_year . ' for '
            . ($this->hrNames([(int) $s->user_id])[(int) $s->user_id] ?? 'a staff member') . ': ' . $why, ['replaces' => $id]);

        return response()->json(['ok' => true, 'id' => $new], 201);
    }

    /** POST /hr/payroll/year-end/slips/{id}/withdraw (admins): drop a pending amendment / cancellation. */
    public function withdraw(Request $request, int $id): JsonResponse
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $this->hrNeedAdmin($request);
        $s = $this->slip($request, $aid, $id);
        YearEnd::discardPending($aid, $id);
        YearEnd::note($aid, $this->me($request), 'payroll.slip_amendment_withdrawn', 'payroll_slip', $id, 'Withdrew a pending ' . ($s->report_type === 'C' ? 'cancellation' : 'amendment')
            . ' of a ' . $s->kind . ' ' . $s->tax_year . '.');

        return response()->json(['ok' => true]);
    }

    /** GET /hr/payroll/year-end/slips/{id}/pdf: full SIN for agency admins, masked for directors. */
    public function slipPdf(Request $request, int $id)
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $s = $this->slip($request, $aid, $id);
        $full = $this->hrIsAdmin($request);
        YearEnd::note($aid, $this->me($request), 'payroll.slip_downloaded', 'payroll_slip', $id, 'Opened the ' . $s->kind . ' ' . $s->tax_year . ' of '
            . ($this->hrNames([(int) $s->user_id])[(int) $s->user_id] ?? 'a staff member') . ($full ? ' (SIN shown)' : ' (SIN masked)') . '.');

        // Locked with the person's payslip password, as every payslip is (PayslipLock; December 31 of the tax year stands in for a missing date of birth).
        return YearEndPdf::pdf(YearEndPdf::slipHtml($s, $full ? YearEnd::sinFor($aid, (int) $s->user_id) : YearEnd::sinHint($aid, (int) $s->user_id)),
            strtolower($s->kind) . '-' . $s->tax_year . '-' . $s->user_id, \App\Support\Payroll\PayslipLock::forPerson((int) $s->user_id, null, $s->tax_year . '-12-31', 'year'));
    }

    /** GET /hr/payroll/year-end/summary?year=&kind= (admins). */
    public function summaryPdf(Request $request)
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $this->hrNeedAdmin($request);
        $y = $this->year($request, $aid);
        $k = (string) $request->query('kind', 'T4');
        abort_unless(isset(YearEnd::KINDS[$k]), 422, 'Choose T4, T4A or RL1.');
        YearEnd::note($aid, $this->me($request), 'payroll.summary_downloaded', 'agency', $aid, 'Opened the ' . $y . ' ' . $k . ' Summary.', ['year' => $y]);

        return YearEndPdf::pdf(YearEndPdf::summaryHtml($aid, $y, $k), strtolower($k) . '-summary-' . $y);
    }

    /** GET /hr/payroll/year-end/xml?year=&kind=T4|T4A&type=O|A (admins): the CRA file, validated against CRA's XSD first. */
    public function xml(Request $request)
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $this->hrNeedAdmin($request);
        $y = $this->year($request, $aid);
        $k = strtoupper((string) $request->query('kind', 'T4'));
        $t = strtoupper((string) $request->query('type', 'O'));
        $ref = YearEndXml::nextRef($aid, $y);
        $r = YearEndXml::build($aid, $y, $k, $t, $ref, (string) $request->query('note', ''));
        if (! $r['valid']) {
            report(new \RuntimeException('Year-end XML failed CRA XSD validation for agency ' . $aid . ': ' . implode(' | ', array_slice($r['errors'], 0, 5))));
            YearEnd::note($aid, $this->me($request), 'payroll.xml_invalid', 'agency', $aid, 'The ' . $y . ' ' . $k . ' CRA file did not pass the CRA schema check, so it was not handed out.', ['errors' => array_slice($r['errors'], 0, 5)]);
            abort(422, 'The file did not pass the CRA schema check, so it was not created: ' . implode(' ', array_slice($r['errors'], 0, 2)));
        }
        $sha = hash('sha256', $r['xml']);
        DB::table('payroll_xml_exports')->insert(['agency_id' => $aid, 'tax_year' => $y, 'kind' => $k, 'report_type' => $t, 'sbmt_ref_id' => $ref, 'schema_version' => $r['schema'],
            'slip_ids' => json_encode($r['slips']), 'sha256' => $sha, 'created_by_id' => $this->me($request), 'created_at' => now(), 'updated_at' => now()]);
        YearEnd::note($aid, $this->me($request), 'payroll.xml_downloaded', 'agency', $aid, 'Downloaded the ' . $y . ' ' . $k . ($t === 'A' ? ' amended' : '') . ' CRA file ' . $ref
            . ' (' . count($r['slips']) . ' slips, schema ' . $r['schema'] . ', passed the CRA schema check).', ['year' => $y, 'sha256' => $sha]);

        return response($r['xml'], 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'no-store, private',
            'Content-Disposition' => 'attachment; filename="' . strtolower($k) . '-' . $y . ($t === 'A' ? '-amended' : '') . '-' . $ref . '.xml"', 'X-KT-Schema' => 'xmlschm' . $r['schema']]);
    }

    // ---- My HR ----------------------------------------------------------------------------------------------------

    /** GET /hr/me/tax-slips: my own final slips (newest version of each), all years. */
    public function mine(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = $this->me($request);
        abort_unless(in_array($me, HrLeave::staffIds($aid), true) || DB::table('payroll_slips')->where('agency_id', $aid)->where('user_id', $me)->exists(), 404, 'Not found.');
        $rows = DB::table('payroll_slips')->where('agency_id', $aid)->where('user_id', $me)->whereIn('status', ['final', 'replaced', 'cancelled'])
            ->orderByDesc('tax_year')->orderBy('kind')->orderByDesc('version')->get();
        if (! PayrollTax::engineOn($aid) && $rows->isEmpty()) {
            return response()->json(['enabled' => false]);
        }

        return response()->json(['enabled' => true, 'password_hint' => \App\Support\Payroll\PayslipLock::hintForUser($me, null, 'year'), 'slips' => $rows->map(fn ($s) => ['id' => (int) $s->id, 'tax_year' => (int) $s->tax_year, 'kind' => $s->kind,
            'jurisdiction' => $s->jurisdiction, 'status' => $s->status, 'report_type' => $s->report_type, 'version' => (int) $s->version,
            'finalized_at' => $s->finalized_at ? (string) $s->finalized_at : null, 'in_force' => $s->status === 'final' && $s->report_type !== 'C'])->values()]);
    }

    /** GET /hr/me/tax-slips/{id}/pdf: my own slip, with my SIN in full. */
    public function minePdf(Request $request, int $id)
    {
        $aid = $this->hrAgency($request);
        $me = $this->me($request);
        $s = DB::table('payroll_slips')->where('id', $id)->where('agency_id', $aid)->where('user_id', $me)->whereIn('status', ['final', 'replaced', 'cancelled'])->first();
        abort_unless($s, 404, 'Not found.');
        YearEnd::note($aid, $me, 'payroll.own_slip_downloaded', 'payroll_slip', $id, 'Opened their own ' . $s->kind . ' ' . $s->tax_year . '.');

        return YearEndPdf::pdf(YearEndPdf::slipHtml($s, YearEnd::sinFor($aid, $me)), strtolower($s->kind) . '-' . $s->tax_year,
            \App\Support\Payroll\PayslipLock::forPerson($me, null, $s->tax_year . '-12-31', 'year'));
    }
}
