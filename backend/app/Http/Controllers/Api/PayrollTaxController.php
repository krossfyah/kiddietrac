<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\Payroll\CaCalculator;
use App\Support\Payroll\PayrollTax;
use App\Support\Payroll\TaxTables;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 1 (2026-11-27): employee tax setup + TD1 e-sign. Routes under hr/ (the HR add-on
 * MAP owns them) and every one also needs the agency's payroll engine switched on (PayrollTax::engineOn, off for every
 * agency until Phase 2 adds the switch), so nothing changes for anyone while it is off.
 *  - Tax setup (province, pay frequency, CPP / EI / QPIP exemptions, vacation pay %): agency admins, inside the active
 *    agency, never their own. A paper TD1 can be recorded by an admin (never their own).
 *  - My HR > Tax forms: each person fills in their own TD1 (federal + their province, or Quebec TP-1015.3); it becomes
 *    a Managed Forms PDF they e-sign (signature stamp: date, time, IP, approximate place). Signed = in force.
 * The SIN is never collected or shown here (hr_staff_sin, admins only).
 */
class PayrollTaxController extends Controller
{
    use HrAccess;

    private function agency(Request $request): int
    {
        $aid = $this->hrAgency($request);
        abort_unless(PayrollTax::engineOn($aid), 403, 'Payroll is not switched on for your agency yet.');

        return $aid;
    }

    private function year(int $aid): int
    {
        return (int) AgencyTime::now($aid)->format('Y');
    }

    private function today(int $aid): string
    {
        return AgencyTime::now($aid)->toDateString();
    }

    /** GET /hr/payroll/tables: the loaded CRA / Revenu Quebec editions and whether next year's are published yet. */
    public function tables(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $this->hrNeedManager($request);
        $y = $this->year($aid);

        return response()->json(['editions' => array_map(fn ($e) => array_diff_key($e, ['path' => 1]), TaxTables::editions('CA')),
            'this_year' => ['year' => $y, 'loaded' => TaxTables::hasYear('CA', $y), 'quebec' => TaxTables::hasQuebec($y)],
            'next_year' => ['year' => $y + 1, 'loaded' => TaxTables::hasYear('CA', $y + 1), 'quebec' => TaxTables::hasQuebec($y + 1)]]);
    }

    /** GET /hr/payroll/tax/people/{id}: one person's tax setup + forms (agency admins). */
    public function show(Request $request, int $id): JsonResponse
    {
        // Engine off = {enabled:false} (not an error), so the HR profile simply draws no Tax setup card.
        if (! PayrollTax::engineOn($this->hrAgency($request))) {
            return response()->json(['enabled' => false]);
        }
        $aid = $this->agency($request);
        $this->hrNeedAdmin($request);
        $this->hrNeedPerson($request, $aid, $id);
        $p = PayrollTax::profile($aid, $id);
        $y = $this->year($aid);
        $sin = DB::table('hr_staff_sin')->where('agency_id', $aid)->where('user_id', $id)->value('sin_hint');

        return response()->json(['enabled' => true, 'profile' => $p, 'forms' => PayrollTax::forms($aid, $id), 'in_force' => PayrollTax::claimsFor($aid, $id, $y),
            'year' => $y, 'own' => $id === (int) $request->user()->id, 'sin_on_file' => $sin ? (string) $sin : null,
            'jurisdictions' => CaCalculator::NAMES, 'frequencies' => PayrollTax::FREQUENCIES, 'cpp_reasons' => PayrollTax::CPP_EXEMPT_REASONS,
            'basics' => $p['jurisdiction'] ? PayrollTax::basics($p['jurisdiction'], $y, $this->today($aid)) : null]);
    }

    /** PUT /hr/payroll/tax/people/{id}: save the employer side of the tax setup. Never your own. */
    public function save(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $this->hrNeedAdmin($request);
        $this->hrNeedPerson($request, $aid, $id);
        abort_if($id === (int) $request->user()->id, 403, 'Another admin has to set up your own tax details.');
        $d = $request->validate([
            'jurisdiction' => 'nullable|string|in:' . implode(',', CaCalculator::JURISDICTIONS),
            'pay_frequency' => 'nullable|integer|in:52,26,24,12',
            'cpp_exempt_reason' => 'nullable|string|in:' . implode(',', array_keys(PayrollTax::CPP_EXEMPT_REASONS)),
            'ei_exempt' => 'boolean', 'qpip_exempt' => 'boolean',
            'vacation_pay_pct' => 'nullable|numeric|min:0|max:20',
            'annual_zone_deduction' => 'nullable|numeric|min:0|max:100000',
            'notes' => 'nullable|string|max:500',
        ]);
        $row = ['country' => 'CA', 'jurisdiction' => $d['jurisdiction'] ?? null, 'pay_frequency' => $d['pay_frequency'] ?? null,
            'cpp_exempt_reason' => $d['cpp_exempt_reason'] ?? null, 'ei_exempt' => (bool) ($d['ei_exempt'] ?? false), 'qpip_exempt' => (bool) ($d['qpip_exempt'] ?? false),
            'vacation_pay_pct' => $d['vacation_pay_pct'] ?? null, 'annual_zone_deduction' => $d['annual_zone_deduction'] ?? null,
            'notes' => isset($d['notes']) ? trim((string) $d['notes']) : null, 'updated_by_id' => (int) $request->user()->id, 'updated_at' => now()];
        $was = DB::table('payroll_tax_profiles')->where('agency_id', $aid)->where('user_id', $id)->first();
        if ($was) {
            DB::table('payroll_tax_profiles')->where('id', $was->id)->update($row);
        } else {
            DB::table('payroll_tax_profiles')->insert($row + ['agency_id' => $aid, 'user_id' => $id, 'created_at' => now()]);
        }
        $changed = [];
        foreach (['jurisdiction', 'pay_frequency', 'cpp_exempt_reason', 'ei_exempt', 'qpip_exempt', 'vacation_pay_pct', 'annual_zone_deduction'] as $k) {
            if ((string) ($was->$k ?? '') !== (string) $row[$k]) {
                $changed[] = $k;
            }
        }
        $this->hrNote($aid, 'payroll.tax_profile_saved', 'user', $id, ['changed' => $changed]);

        return response()->json(['ok' => true, 'profile' => PayrollTax::profile($aid, $id)]);
    }

    /** POST /hr/payroll/tax/people/{id}/paper: record a TD1 the person signed on paper (admins; never your own). */
    public function paper(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $this->hrNeedAdmin($request);
        $this->hrNeedPerson($request, $aid, $id);
        abort_if($id === (int) $request->user()->id, 403, 'Another admin has to record your own TD1.');
        $p = PayrollTax::profile($aid, $id);
        abort_unless($p['jurisdiction'], 422, 'Set the province of employment first.');
        $claims = $this->claims($request, $p['jurisdiction']);
        $d = $request->validate(['tax_year' => 'required|integer|min:2026|max:2100', 'signed_on' => 'required|date_format:Y-m-d']);
        $fid = (int) DB::table('payroll_tax_forms')->insertGetId(['agency_id' => $aid, 'user_id' => $id, 'country' => 'CA',
            'form' => $p['jurisdiction'] === 'QC' ? 'TP-1015.3' : 'TD1', 'tax_year' => (int) $d['tax_year'], 'jurisdiction' => $p['jurisdiction'],
            'claims' => json_encode($claims), 'source' => 'paper', 'status' => 'signed', 'submitted_by_id' => (int) $request->user()->id,
            'signed_at' => $d['signed_on'] . ' 12:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'payroll.td1_paper_recorded', 'user', $id, ['form_id' => $fid, 'tax_year' => (int) $d['tax_year']]);

        return response()->json(['ok' => true, 'id' => $fid], 201);
    }

    /** POST /hr/payroll/tax/people/{id}/preview: what one pay of this amount would deduct (nothing is saved). */
    public function preview(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $this->hrNeedAdmin($request);
        $this->hrNeedPerson($request, $aid, $id);
        $d = $request->validate(['gross' => 'required|numeric|min:0|max:1000000', 'pay_date' => 'nullable|date_format:Y-m-d']);
        $date = $d['pay_date'] ?? $this->today($aid);
        $x = PayrollTax::inputs($aid, $id, $date);
        $in = $x['inputs'];
        abort_unless($in['jurisdiction'] && $in['periods'], 422, implode(' ', $x['warnings']) ?: 'Finish the tax setup first.');
        $p = PayrollTax::profile($aid, $id);
        $in['salary'] = (float) $d['gross'];
        $in['vacation_pay'] = $p['vacation_pay_pct'] ? CaCalculator::r((float) $d['gross'] * $p['vacation_pay_pct'] / 100) : 0;
        try {
            $r = CaCalculator::calculate($in);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['result' => ['employee' => $r['employee'], 'employer' => $r['employer'], 'plan' => $r['plan'],
            'table_version' => $r['table_version'], 'edition' => $r['edition'], 'quebec_edition' => $r['quebec_edition']],
            'warnings' => array_values(array_unique(array_merge($x['warnings'], $r['warnings']))), 'note' => 'An example only: no year-to-date amounts are counted and nothing is saved.']);
    }

    /** POST /hr/payroll/tax/forms/{id}/withdraw: cancel a form still waiting for its signature (the person or an admin). */
    public function withdraw(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $f = DB::table('payroll_tax_forms')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($f, 404, 'That form was not found.');
        $me = (int) $request->user()->id;
        abort_unless((int) $f->user_id === $me || ($this->hrIsAdmin($request) && in_array((int) $f->user_id, $this->hrPeople($request, $aid), true)), 404, 'That form was not found.');
        $signed = $f->source === 'paper' || ($f->managed_form_id && DB::table('managed_form_signoffs')->where('managed_form_id', $f->managed_form_id)->where('user_id', $f->user_id)->whereNotNull('signed_at')->exists());
        abort_if($signed, 422, 'A signed TD1 stays on file. Fill in a new one to change your claim.');
        DB::table('payroll_tax_forms')->where('id', $id)->update(['status' => 'withdrawn', 'updated_at' => now()]);
        if ($f->managed_form_id) {
            DB::table('managed_forms')->where('id', $f->managed_form_id)->where('agency_id', $aid)->update(['active' => 0, 'updated_at' => now()]);
        }
        $this->hrNote($aid, 'payroll.tax_form_withdrawn', 'user', (int) $f->user_id, ['form_id' => $id]);

        return response()->json(['ok' => true]);
    }

    /** GET /hr/me/tax-forms: My HR > Tax forms. */
    public function mine(Request $request): JsonResponse
    {
        if (! PayrollTax::engineOn($this->hrAgency($request))) {
            return response()->json(['enabled' => false]);   // My HR draws no Tax forms section
        }
        $aid = $this->agency($request);
        $me = (int) $request->user()->id;
        abort_unless($this->hrCanSee($request, $aid, $me), 404, 'Not found.');
        $p = PayrollTax::profile($aid, $me);
        $y = $this->year($aid);
        $years = array_values(array_filter([$y, $y + 1], fn ($yy) => TaxTables::hasYear('CA', $yy)));

        return response()->json(['enabled' => true, 'jurisdiction' => $p['jurisdiction'], 'jurisdiction_name' => $p['jurisdiction'] ? (CaCalculator::NAMES[$p['jurisdiction']] ?? $p['jurisdiction']) : null,
            'country' => $p['country'], 'year' => $y, 'years' => $years,
            'basics' => $p['jurisdiction'] ? PayrollTax::basics($p['jurisdiction'], $y, $this->today($aid)) : null,
            'next_basics' => ($p['jurisdiction'] && TaxTables::hasYear('CA', $y + 1)) ? PayrollTax::basics($p['jurisdiction'], $y + 1, $this->today($aid)) : null,
            'in_force' => PayrollTax::claimsFor($aid, $me, $y), 'forms' => PayrollTax::forms($aid, $me)]);
    }

    /** POST /hr/me/tax-forms: fill in my TD1; it becomes a PDF in My forms for me to e-sign. */
    public function submit(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $me = (int) $request->user()->id;
        abort_unless($this->hrCanSee($request, $aid, $me), 404, 'Not found.');
        $p = PayrollTax::profile($aid, $me);
        abort_unless($p['country'] === 'CA' && $p['jurisdiction'], 422, 'Your employer has not set your province of employment yet. Ask your HR admin.');
        $d = $request->validate(['tax_year' => 'required|integer', 'certify' => 'accepted']);
        $y = (int) $d['tax_year'];
        abort_unless(in_array($y, [$this->year($aid), $this->year($aid) + 1], true) && TaxTables::hasYear('CA', $y), 422,
            'The CRA has not published the ' . $y . ' payroll tables yet, so a ' . $y . ' TD1 cannot be filled in here yet.');
        $j = $p['jurisdiction'];
        abort_if($j === 'QC' && ! TaxTables::hasQuebec($y), 422, 'Revenu Québec has not published the ' . $y . ' formulas yet, so a ' . $y . ' TP-1015.3 cannot be filled in here yet.');
        $claims = $this->claims($request, $j);
        $form = $j === 'QC' ? 'TP-1015.3' : 'TD1';
        $u = DB::table('users')->where('id', $me)->first(['first_name', 'last_name', 'date_of_birth']);
        $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
        $agency = DB::table('agencies')->where('id', $aid)->first(['name', 'brand_logo_url', 'logo_url', 'brand_primary_color']);
        $logo = $agency->brand_logo_url ?: ($agency->logo_url ?? null);
        if ($logo && str_starts_with((string) $logo, '/')) {
            $logo = 'https://api.kiddietrac.com' . $logo;
        }
        $basics = PayrollTax::basics($j, $y, $this->today($aid));
        $html = view('pdf.payroll_td1', ['agency' => $agency->name, 'logo' => $logo, 'colour' => $agency->brand_primary_color ?: '#1F6080',
            'person' => $name, 'year' => $y, 'jurisdiction' => $j, 'province' => CaCalculator::NAMES[$j] ?? $j, 'form' => $form,
            'claims' => $claims, 'basics' => $basics, 'today' => AgencyTime::now($aid)->format('F j, Y'),
            'sin' => DB::table('hr_staff_sin')->where('agency_id', $aid)->where('user_id', $me)->value('sin_hint')])->render();
        $opts = new Options();
        $opts->set('isRemoteEnabled', true);
        $opts->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($opts);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('letter', 'portrait');
        $pdf->render();
        $bytes = $pdf->output();
        // Managed Forms reads its files under an unguessable name (ProtectedMedia keeps the managed-forms folder private).
        $path = 'managed-forms/' . $aid . '/payroll-td1-' . Str::random(40) . '.pdf';
        Storage::disk('public')->put($path, $bytes);
        $centre = (int) DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $me)->value('primary_centre_id') ?: null;
        $title = ($j === 'QC' ? 'TP-1015.3 Source deductions return ' : 'TD1 Personal tax credits return ') . $y . ' — ' . $name;
        $res = DB::transaction(function () use ($aid, $me, $y, $j, $form, $claims, $path, $bytes, $title, $centre) {
            // An older TD1 for the same year that was never signed is replaced by this one.
            $old = DB::table('payroll_tax_forms')->where('agency_id', $aid)->where('user_id', $me)->where('tax_year', $y)->where('source', 'esign')->where('status', 'sent')->get(['id', 'managed_form_id']);
            foreach ($old as $o) {
                $signed = $o->managed_form_id && DB::table('managed_form_signoffs')->where('managed_form_id', $o->managed_form_id)->where('user_id', $me)->whereNotNull('signed_at')->exists();
                if (! $signed) {
                    DB::table('payroll_tax_forms')->where('id', $o->id)->update(['status' => 'withdrawn', 'updated_at' => now()]);
                    if ($o->managed_form_id) {
                        DB::table('managed_forms')->where('id', $o->managed_form_id)->update(['active' => 0, 'updated_at' => now()]);
                    }
                }
            }
            $mf = (int) DB::table('managed_forms')->insertGetId(['agency_id' => $aid, 'centre_id' => $centre, 'title' => mb_substr($title, 0, 190),
                'description' => 'Your tax claim for payroll: check the amounts, then sign. Your employer uses it to work out the tax taken off your pay.',
                'file_url' => '/storage/' . $path, 'file_type' => 'application/pdf', 'file_size' => strlen($bytes),
                'fillable' => 0, 'reusable' => 0, 'requires_countersign' => 0, 'audiences' => json_encode([]), 'active' => 1,
                'created_by_id' => $me, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('managed_form_recipients')->insertOrIgnore(['managed_form_id' => $mf, 'user_id' => $me, 'created_at' => now()]);
            $fid = (int) DB::table('payroll_tax_forms')->insertGetId(['agency_id' => $aid, 'user_id' => $me, 'country' => 'CA', 'form' => $form,
                'tax_year' => $y, 'jurisdiction' => $j, 'claims' => json_encode($claims), 'source' => 'esign', 'status' => 'sent',
                'managed_form_id' => $mf, 'submitted_by_id' => $me, 'created_at' => now(), 'updated_at' => now()]);

            return ['form_id' => $fid, 'managed_form_id' => $mf, 'replaced' => count($old)];
        });
        $this->hrNote($aid, 'payroll.td1_submitted', 'user', $me, ['form_id' => $res['form_id'], 'tax_year' => $y, 'form' => $form]);

        return response()->json(['ok' => true] + $res + ['link' => '#my-forms'], 201);
    }

    /** The claim amounts from a TD1 request (federal + provincial, or Quebec TP-1015.3), validated and normalised. */
    private function claims(Request $request, string $j): array
    {
        $money = 'nullable|numeric|min:0|max:1000000';
        if ($j === 'QC') {
            $d = $request->validate(['federal' => 'array', 'federal.total_claim' => $money, 'federal.additional_tax' => 'nullable|numeric|min:0|max:100000',
                'federal.exempt' => 'boolean', 'federal.more_than_one_employer' => 'boolean', 'federal.zone_deduction' => $money,
                'quebec' => 'array', 'quebec.line7' => $money, 'quebec.line9' => $money, 'quebec.line19' => $money,
                'quebec.additional_tax' => 'nullable|numeric|min:0|max:100000', 'quebec.exempt' => 'boolean']);
        } else {
            $d = $request->validate(['federal' => 'array', 'federal.total_claim' => $money, 'federal.additional_tax' => 'nullable|numeric|min:0|max:100000',
                'federal.exempt' => 'boolean', 'federal.more_than_one_employer' => 'boolean', 'federal.zone_deduction' => $money,
                'provincial' => 'array', 'provincial.total_claim' => $money, 'provincial.exempt' => 'boolean']);
        }
        $f = $d['federal'] ?? [];
        $out = ['federal' => array_filter([
            'total_claim' => ! empty($f['more_than_one_employer']) ? 0.0 : (isset($f['total_claim']) ? round((float) $f['total_claim'], 2) : null),
            'additional_tax' => isset($f['additional_tax']) ? round((float) $f['additional_tax'], 2) : null,
            'exempt' => ! empty($f['exempt']) ?: null, 'more_than_one_employer' => ! empty($f['more_than_one_employer']) ?: null,
            'zone_deduction' => isset($f['zone_deduction']) ? round((float) $f['zone_deduction'], 2) : null,
        ], fn ($v) => $v !== null)];
        if ($j === 'QC') {
            $q = $d['quebec'] ?? [];
            $out['quebec'] = array_filter([
                'line7' => isset($q['line7']) ? round((float) $q['line7'], 2) : null, 'line9' => isset($q['line9']) ? round((float) $q['line9'], 2) : null,
                'line19' => isset($q['line19']) ? round((float) $q['line19'], 2) : null,
                'additional_tax' => isset($q['additional_tax']) ? round((float) $q['additional_tax'], 2) : null, 'exempt' => ! empty($q['exempt']) ?: null,
            ], fn ($v) => $v !== null);
        } else {
            $pv = $d['provincial'] ?? [];
            $out['provincial'] = array_filter([
                'total_claim' => ! empty($f['more_than_one_employer']) ? 0.0 : (isset($pv['total_claim']) ? round((float) $pv['total_claim'], 2) : null),
                'exempt' => ! empty($pv['exempt']) ?: null,
            ], fn ($v) => $v !== null);
        }

        return $out;
    }
}
