<?php

declare(strict_types=1);

namespace App\Support\Payroll;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 3 (2026-11-27): the CRA Internet file (T619 electronic transmittal + T4 or T4A return)
 * built from FINAL slips only, and validated against CRA's own XSD before anyone can download it.
 *
 * Schema: CRA "XML schema for information returns", package xmlschm1-27-1 (version 1.27, last updated 2026-09-23) - the
 * package CRA publishes for returns filed in 2027, i.e. the 2026 tax year. Downloaded unchanged from
 * https://www.canada.ca/content/dam/cra-arc/serv-info/eservices/xmlschm1-27-1.zip (listed on
 * https://www.canada.ca/en/revenue-agency/services/e-services/filing-information-returns-electronically-t4-t5-other-types-returns-overview/filing-information-returns-electronically-t4-t5-other-types-returns-what-you-should-know-before.html)
 * and kept in resources/payroll/ca/xsd/1-27-1 (T619_T4.xsd / T619_T4A.xsd are the roots). Field rules: CRA T619 2026V4,
 * T4 2026V4, T4A 2026V2 specifications (canada.ca ... /t619-2026.html, /t619-2026/t4-2026.html, /t619-2026/t4a-2026.html).
 * A tax year whose filing-year package is not here gets no file (never a guess at a newer schema).
 *
 * One file = one return type and one report type: originals (O) never mix with amendments / cancellations (A / C).
 * The file holds full SINs, so it is NEVER stored: payroll_xml_exports keeps who / when / which slips / the SHA-256, and
 * the file is rebuilt from the final slips on each download. KiddieTrac never sends it: the agency uploads it to CRA
 * Internet File Transfer (or My Business Account / Represent a Client).
 */
final class YearEndXml
{
    /** Filing year => schema package folder. */
    public const SCHEMAS = [2027 => '1-27-1'];

    public static function schemaFor(int $taxYear): ?string
    {
        return self::SCHEMAS[$taxYear + 1] ?? null;
    }

    public static function schemaDir(string $v): string
    {
        return resource_path('payroll/ca/xsd/' . $v);
    }

    private static function money(float $v): string
    {
        return number_format(round(max(0, $v), 2), 2, '.', '');
    }

    /** CRA name character set: letters (accents allowed), space, hyphen, apostrophe, period; cut to $n. */
    private static function nm(?string $s, int $n): string
    {
        $s = preg_replace("/[^\\p{L} '\\-.]/u", '', trim((string) $s)) ?? '';
        $s = preg_replace('/\s+/', ' ', $s) ?? '';

        return mb_substr(trim($s), 0, $n);
    }

    private static function txt(?string $s, int $n): string
    {
        $s = preg_replace('/[^\p{L}\p{N} &\'\-.,#\/()]/u', '', trim((string) $s)) ?? '';

        return mb_substr(trim(preg_replace('/\s+/', ' ', $s) ?? ''), 0, $n);
    }

    /** [area code, "ddd-dddd"] from a phone number, or null. */
    public static function phone(?string $p): ?array
    {
        $d = preg_replace('/\D/', '', (string) $p);
        if (strlen($d) === 11 && $d[0] === '1') {
            $d = substr($d, 1);
        }

        return strlen($d) === 10 ? [substr($d, 0, 3), substr($d, 3, 3) . '-' . substr($d, 6, 4)] : null;
    }

    private static function el(DOMDocument $x, DOMElement $p, string $name, ?string $value = null): DOMElement
    {
        $e = $x->createElement($name);
        if ($value !== null) {
            $e->appendChild($x->createTextNode($value));
        }
        $p->appendChild($e);

        return $e;
    }

    /** What stops a file from being made (missing employer details), as plain sentences. */
    public static function missing(int $agencyId): array
    {
        $emp = PayrollEmployer::settings($agencyId);
        $ye = YearEnd::settings($agencyId);
        $out = [];
        if (! $emp['rp_number']) {
            $out[] = 'the CRA payroll account number (HR settings › Payroll engine)';
        }
        if (! trim((string) $ye['contact_name'])) {
            $out[] = 'a filing contact name';
        }
        if (! self::phone($ye['contact_phone'])) {
            $out[] = 'a 10-digit filing contact phone number';
        }
        if (! filter_var((string) $ye['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $out[] = 'a filing contact email';
        }

        return $out;
    }

    /** The final slips a file of this kind + report type carries. */
    public static function slipsFor(int $agencyId, int $year, string $kind, string $type): array
    {
        $q = DB::table('payroll_slips')->where('agency_id', $agencyId)->where('tax_year', $year)->where('kind', $kind)->orderBy('surname')->orderBy('given_name')->orderBy('id');
        if ($type === 'O') {
            // The originals as first finalized (a replaced / cancelled original was still filed as it was).
            return $q->where('report_type', 'O')->whereIn('status', ['final', 'replaced', 'cancelled'])->get()->all();
        }

        // Amendments / cancellations not already handed out in an earlier amended file (else all of them again).
        $all = $q->whereIn('report_type', ['A', 'C'])->whereIn('status', ['final', 'replaced', 'cancelled'])->get();
        $done = [];
        foreach (DB::table('payroll_xml_exports')->where('agency_id', $agencyId)->where('tax_year', $year)->where('kind', $kind)->where('report_type', 'A')->pluck('slip_ids') as $j) {
            foreach (json_decode((string) $j, true) ?: [] as $id) {
                $done[(int) $id] = true;
            }
        }
        $new = $all->filter(fn ($s) => ! isset($done[(int) $s->id]));

        return ($new->isNotEmpty() ? $new : $all)->values()->all();
    }

    /**
     * Build the file. @return array{xml:string, valid:bool, errors:string[], slips:int[], sbmt_ref_id:string, schema:string}
     */
    public static function build(int $agencyId, int $year, string $kind, string $type, string $refId, ?string $amendNote = null): array
    {
        abort_unless(in_array($kind, ['T4', 'T4A'], true) && in_array($type, ['O', 'A'], true), 422, 'Choose T4 or T4A, original or amended.');
        $schema = self::schemaFor($year);
        abort_unless($schema, 422, 'CRA has not published (or KiddieTrac does not have) the XML schema for filing ' . $year . ' returns yet.');
        $miss = self::missing($agencyId);
        abort_if($miss, 422, 'Add ' . implode(', ', $miss) . ' first.');
        $slips = self::slipsFor($agencyId, $year, $kind, $type);
        abort_unless($slips, 422, $type === 'O' ? 'There are no finalized ' . $kind . ' slips for ' . $year . '.' : 'There are no finalized ' . $kind . ' amendments or cancellations for ' . $year . '.');
        $emp = PayrollEmployer::settings($agencyId);
        $ye = YearEnd::settings($agencyId);
        $ag = DB::table('agencies')->where('id', $agencyId)->first(['name', 'legal_name', 'address_line1', 'address_line2', 'city', 'province', 'postal_code', 'country', 'locale']);
        $bn = strtoupper((string) $emp['rp_number']);
        [$area, $num] = self::phone($ye['contact_phone']);

        $x = new DOMDocument('1.0', 'UTF-8');
        $x->formatOutput = true;
        $root = $x->createElement('Submission');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $x->appendChild($root);
        $t619 = self::el($x, $root, 'T619');
        $acct = self::el($x, $t619, 'TransmitterAccountNumber');
        self::el($x, $acct, 'bn15', $bn);
        self::el($x, $t619, 'sbmt_ref_id', $refId);
        self::el($x, $t619, 'summ_cnt', '1');
        self::el($x, $t619, 'lang_cd', str_starts_with((string) ($ag->locale ?? ''), 'fr') ? 'F' : 'E');
        $tn = self::el($x, $t619, 'TransmitterName');
        self::el($x, $tn, 'l1_nm', self::txt($ag->legal_name ?: $ag->name, 35));
        self::el($x, $t619, 'TransmitterCountryCode', 'CAN');
        $c = self::el($x, $t619, 'CNTC');
        self::el($x, $c, 'cntc_nm', self::nm($ye['contact_name'], 35));
        self::el($x, $c, 'cntc_area_cd', $area);
        self::el($x, $c, 'cntc_phn_nbr', $num);
        self::el($x, $c, 'cntc_email_area', mb_substr((string) $ye['contact_email'], 0, 60));

        $ret = self::el($x, $root, 'Return');
        $box = self::el($x, $ret, $kind);
        $tot = [];
        $ids = [];
        foreach ($slips as $s) {
            $ids[] = (int) $s->id;
            $b = json_decode((string) $s->boxes, true) ?: [];
            $codes = json_decode((string) $s->codes, true) ?: [];
            $man = json_decode((string) $s->manual, true) ?: [];
            $sin = YearEnd::sinFor($agencyId, (int) $s->user_id) ?? '000000000';   // CRA: all zeros when the SIN was never provided
            $rt = $type === 'O' ? 'O' : $s->report_type;
            if ($kind === 'T4') {
                $qc = $s->jurisdiction === 'QC';
                $sl = self::el($x, $box, 'T4Slip');
                $n = self::el($x, $sl, 'EMPE_NM');
                self::el($x, $n, 'snm', self::nm($s->surname, 20) ?: 'UNKNOWN');
                if (self::nm($s->given_name, 12) !== '') {
                    self::el($x, $n, 'gvn_nm', self::nm($s->given_name, 12));
                }
                self::el($x, $sl, 'sin', $sin);
                self::el($x, $sl, 'empe_nbr', (string) $s->user_id);
                self::el($x, $sl, 'bn', $bn);
                if (! empty($codes['50']) && preg_match('/^\d{7}$/', (string) $codes['50'])) {
                    self::el($x, $sl, 'rpp_dpsp_rgst_nbr', (string) $codes['50']);
                }
                self::el($x, $sl, 'cpp_qpp_xmpt_cd', (string) (int) ($codes['28']['cpp'] ?? 0));
                self::el($x, $sl, 'ei_xmpt_cd', (string) (int) ($codes['28']['ei'] ?? 0));
                if ($qc) {
                    self::el($x, $sl, 'prov_pip_xmpt_cd', (string) (int) ($codes['28']['ppip'] ?? 0));
                }
                if (! empty($codes['29'])) {
                    self::el($x, $sl, 'empt_cd', (string) (int) $codes['29']);
                }
                self::el($x, $sl, 'rpt_tcd', $rt);
                self::el($x, $sl, 'empt_prov_cd', (string) $s->jurisdiction);
                self::el($x, $sl, 'empr_dntl_ben_rpt_cd', (string) (int) ($codes['45'] ?? 1));
                $a = self::el($x, $sl, 'T4_AMT');
                $opt = function (string $tag, string $k) use ($x, $a, $b) {
                    if ((float) ($b[$k] ?? 0) > 0) {
                        self::el($x, $a, $tag, self::money((float) $b[$k]));
                    }
                };
                $opt('empt_incamt', '14');
                if ($qc) {
                    if ((float) ($b['17'] ?? 0) > 0) {
                        self::el($x, $a, 'qpp_cntrb_amt', self::money((float) $b['17']));
                        self::el($x, $a, 'qppe_cntrb_amt', self::money((float) ($b['17A'] ?? 0)));   // 17A must go with 17
                    }
                } elseif ((float) ($b['16'] ?? 0) > 0) {
                    self::el($x, $a, 'cpp_cntrb_amt', self::money((float) $b['16']));
                    self::el($x, $a, 'cppe_cntrb_amt', self::money((float) ($b['16A'] ?? 0)));     // 16A must go with 16
                }
                $opt('empe_eip_amt', '18');
                $opt('rpp_cntrb_amt', '20');
                $opt('itx_ddct_amt', '22');
                self::el($x, $a, 'ei_insu_ern_amt', self::money((float) ($b['24'] ?? 0)));      // required: 0.00 when exempt
                self::el($x, $a, 'cpp_qpp_ern_amt', self::money((float) ($b['26'] ?? 0)));      // required: 0.00 when exempt
                $opt('unn_dues_amt', '44');
                $opt('chrty_dons_amt', '46');
                $opt('padj_amt', '52');
                if ($qc) {
                    $opt('prov_pip_amt', '55');
                    $opt('prov_insu_ern_amt', '56');
                }
                $oi = array_values(array_filter(json_decode((string) $s->other_info, true) ?: [], fn ($o) => (float) $o['amount'] > 0));
                if ($oi) {
                    $o = self::el($x, $sl, 'OTH_INFO');
                    foreach (array_slice($oi, 0, 6) as $it) {
                        if ((string) $it['code'] === '40') {
                            self::el($x, $o, 'oth_tx_ben_amt', self::money((float) $it['amount']));
                        }
                    }
                }
                $er = json_decode((string) $s->employer, true) ?: [];
                if ($rt !== 'C') {
                    foreach (['14' => 'tot_empt_incamt', '22' => 'tot_itx_ddct_amt', '18' => 'tot_empe_eip_amt', '20' => 'tot_rpp_cntrb_amt', '52' => 'tot_padj_amt'] as $k => $tk) {
                        $tot[$tk] = ($tot[$tk] ?? 0) + (float) ($b[$k] ?? 0);
                    }
                    if (! $qc) {
                        $tot['tot_empe_cpp_amt'] = ($tot['tot_empe_cpp_amt'] ?? 0) + (float) ($b['16'] ?? 0);
                        $tot['tot_empe_cppe_amt'] = ($tot['tot_empe_cppe_amt'] ?? 0) + (float) ($b['16A'] ?? 0);
                        $tot['tot_empr_cpp_amt'] = ($tot['tot_empr_cpp_amt'] ?? 0) + (float) ($er['cpp'] ?? 0);
                        $tot['tot_empr_cppe_amt'] = ($tot['tot_empr_cppe_amt'] ?? 0) + (float) ($er['cpp2'] ?? 0);
                    }
                    $tot['tot_empr_eip_amt'] = ($tot['tot_empr_eip_amt'] ?? 0) + (float) ($er['ei'] ?? 0);
                }
            } else {
                $sl = self::el($x, $box, 'T4ASlip');
                $n = self::el($x, $sl, 'RCPNT_NM');
                self::el($x, $n, 'snm', self::nm($s->surname, 20) ?: 'UNKNOWN');
                if (self::nm($s->given_name, 12) !== '') {
                    self::el($x, $n, 'gvn_nm', self::nm($s->given_name, 12));
                }
                self::el($x, $sl, 'sin', $sin);
                $rbn = strtoupper(preg_replace('/\s/', '', (string) ($man['rcpnt_bn'] ?? '')));
                self::el($x, $sl, 'rcpnt_bn', preg_match('/^\d{9}[A-Z]{2}\d{4}$/', $rbn) ? $rbn : '000000000RT0000');   // CRA placeholder when none was given
                self::el($x, $sl, 'rcpnt_nbr', (string) $s->user_id);
                self::el($x, $sl, 'bn', $bn);
                self::el($x, $sl, 'rpt_tcd', $rt);
                $a = self::el($x, $sl, 'T4A_AMT');
                if ((float) ($b['022'] ?? 0) > 0) {
                    self::el($x, $a, 'itx_ddct_amt', self::money((float) $b['022']));
                }
                self::el($x, $a, 'fee_or_oth_srvc_amt', self::money((float) ($b['048'] ?? 0)));
                if ($rt !== 'C') {
                    $tot['rpt_tot_fee_srvc_amt'] = ($tot['rpt_tot_fee_srvc_amt'] ?? 0) + (float) ($b['048'] ?? 0);
                    $tot['tot_itx_dedn_amt'] = ($tot['tot_itx_dedn_amt'] ?? 0) + (float) ($b['022'] ?? 0);
                }
            }
        }
        $sum = self::el($x, $box, $kind . 'Summary');
        self::el($x, $sum, 'bn', $bn);
        $en = self::el($x, $sum, $kind === 'T4' ? 'EMPR_NM' : 'PAYR_NM');
        self::el($x, $en, 'l1_nm', self::txt($ag->legal_name ?: $ag->name, 30));
        if (trim((string) $ag->address_line1) !== '' && trim((string) $ag->city) !== '') {
            $ad = self::el($x, $sum, $kind === 'T4' ? 'EMPR_ADDR' : 'PAYR_ADDR');
            self::el($x, $ad, 'addr_l1_txt', self::txt($ag->address_line1, 30));
            if (trim((string) $ag->address_line2) !== '') {
                self::el($x, $ad, 'addr_l2_txt', self::txt($ag->address_line2, 30));
            }
            self::el($x, $ad, 'cty_nm', self::txt($ag->city, 28));
            $ctry = strtoupper((string) $ag->country);
            $iso = in_array($ctry, ['CA', 'CAN', 'CANADA'], true) ? 'CAN' : (in_array($ctry, ['US', 'USA', 'UNITED STATES'], true) ? 'USA' : null);
            $prov = $iso === 'CAN' ? PayrollTax::provinceCode((string) $ag->province, 'CA') : ($iso === 'USA' ? strtoupper(substr((string) $ag->province, 0, 2)) : null);
            if ($prov && preg_match('/^[A-Z]{2}$/', $prov)) {
                self::el($x, $ad, 'prov_cd', $prov);
            }
            if ($iso) {
                self::el($x, $ad, 'cntry_cd', $iso);
            }
            $pc = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string) $ag->postal_code));
            if ($pc !== '') {
                self::el($x, $ad, 'pstl_cd', mb_substr($pc, 0, 10));
            }
        }
        $sc = self::el($x, $sum, 'CNTC');
        self::el($x, $sc, 'cntc_nm', self::nm($ye['contact_name'], 22));
        self::el($x, $sc, 'cntc_area_cd', $area);
        self::el($x, $sc, 'cntc_phn_nbr', $num);
        self::el($x, $sum, 'tx_yr', (string) $year);
        self::el($x, $sum, 'slp_cnt', (string) count($slips));
        self::el($x, $sum, 'rpt_tcd', $type);
        if ($type === 'A') {
            self::el($x, $sum, 'fileramendmentnote', mb_substr(self::txt($amendNote ?: 'Amended and cancelled slips', 1309), 0, 1309));
        }
        $tt = self::el($x, $sum, $kind . '_TAMT');
        $order = $kind === 'T4'
            ? ['tot_empt_incamt', 'tot_empe_cpp_amt', 'tot_empe_cppe_amt', 'tot_empe_eip_amt', 'tot_rpp_cntrb_amt', 'tot_itx_ddct_amt', 'tot_padj_amt', 'tot_empr_cpp_amt', 'tot_empr_cppe_amt', 'tot_empr_eip_amt']
            : ['tot_itx_dedn_amt', 'rpt_tot_fee_srvc_amt'];
        foreach ($order as $k) {
            if (isset($tot[$k]) || in_array($k, ['tot_empt_incamt', 'rpt_tot_fee_srvc_amt'], true)) {
                self::el($x, $tt, $k, self::money((float) ($tot[$k] ?? 0)));
            }
        }
        $xml = $x->saveXML();
        [$valid, $errors] = self::validate($xml, $kind, $schema);

        return ['xml' => $xml, 'valid' => $valid, 'errors' => $errors, 'slips' => $ids, 'sbmt_ref_id' => $refId, 'schema' => $schema];
    }

    /** Validate against CRA's XSD. @return array{0:bool,1:string[]} */
    public static function validate(string $xml, string $kind, string $schema): array
    {
        $root = self::schemaDir($schema) . '/T619_' . $kind . '.xsd';
        if (! is_file($root)) {
            return [false, ['The CRA schema file ' . basename($root) . ' is missing.']];
        }
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $d = new DOMDocument();
        $ok = $d->loadXML($xml, LIBXML_NONET) && $d->schemaValidate($root);
        $errs = array_map(fn ($e) => 'Line ' . $e->line . ': ' . trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return [$ok && ! $errs, $errs];
    }

    /** A submission reference: KT + 2-digit year + 4-digit sequence (8 characters, letters and digits only). */
    public static function nextRef(int $agencyId, int $year): string
    {
        $n = DB::table('payroll_xml_exports')->where('agency_id', $agencyId)->where('tax_year', $year)->count() + 1;

        return 'KT' . substr((string) $year, -2) . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
