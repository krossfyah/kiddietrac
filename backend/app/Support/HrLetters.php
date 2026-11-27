<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC HR PHASE 3, ITEM 12: HR LETTER TEMPLATES (2026-11-26).
 *
 * Five letters per agency (offer, employment contract, contractor agreement, warning, termination), created from the
 * defaults below the first time HR > Letters opens and editable per agency. The defaults are a starting point only:
 * the editor shows "Draft – have an employment lawyer review before use" (never the sent letter), and NO template
 * sends until an agency admin ticks "Reviewed by our lawyer on <date>". Editing the text clears that tick.
 *
 * Merge fields ({{ name }}) come from the employee file, the user and the agency; a few are typed at send time.
 * Formal documents use FULL names. The signatory is agency staff (AgencySignatories::ownStaff, directors and admins),
 * never the platform super admin. A sent letter becomes a Managed Forms PDF assigned to the person (#my-forms), signed
 * with the existing e-sign and countersigned by a director or admin.
 */
final class HrLetters
{
    public const KINDS = ['offer' => 'Offer letter', 'contract' => 'Employment contract', 'contractor' => 'Contractor agreement',
        'warning' => 'Warning letter', 'termination' => 'Termination letter'];

    /** Merge fields people can use: name => what it is. */
    public const FIELDS = [
        'full_name' => 'Employee full name', 'first_name' => 'Employee first name', 'last_name' => 'Employee last name',
        'job_title' => 'Job title (employee file)', 'employment_type' => 'Employment type (employee file)',
        'start_date' => 'Start date (hire date)', 'pay' => 'Pay rate and type', 'centre' => 'Centre or home',
        'centre_address' => 'Centre address', 'agency' => 'Agency name', 'today' => 'Today\'s date',
        'signatory_name' => 'Signatory full name', 'signatory_title' => 'Signatory title',
        'effective_date' => 'Effective date (typed when sending)', 'details' => 'Details (typed when sending)',
    ];

    public const DEFAULTS = [
        'offer' => "Dear {{first_name}},\n\nWe are pleased to offer you the position of {{job_title}} at {{centre}}, part of {{agency}}.\n\nYour employment will start on {{start_date}}. This is a {{employment_type}} position, paid at {{pay}}.\n\n{{details}}\n\nThis offer depends on the checks your role requires, such as a vulnerable sector check, current first aid and CPR, and your immunization records.\n\nPlease sign below to accept this offer. We look forward to welcoming you to the team.\n\nSincerely,",
        'contract' => "This employment agreement is between {{agency}} (the employer) and {{full_name}} (the employee).\n\nPosition: {{job_title}} at {{centre}}, {{centre_address}}.\nStart date: {{start_date}}.\nEmployment type: {{employment_type}}.\nPay: {{pay}}, paid on the employer's regular pay schedule, less the deductions the law requires.\n\nHours, vacation, sick days and other leave follow the employer's policies and at least the minimum standards of the employment law that applies where you work.\n\nThe employee agrees to follow the employer's policies, the licensing rules for child care, and the confidentiality of children's and families' information, during and after employment.\n\nEither party may end this agreement as allowed by the employment law that applies, with the notice or pay in lieu of notice that it requires.\n\n{{details}}\n\nBy signing, both parties agree to these terms.",
        'contractor' => "This agreement is between {{agency}} (the client) and {{full_name}} (the contractor), starting {{effective_date}}.\n\nThe contractor will provide these services: {{details}}\n\nThe contractor is self-employed, not an employee. The contractor is responsible for their own taxes, insurance and equipment, and invoices the client for services provided at {{pay}}.\n\nThe contractor follows the client's child safety, licensing and confidentiality requirements while providing the services, and keeps children's and families' information confidential during and after this agreement.\n\nEither party may end this agreement with written notice.\n\nBy signing, both parties agree to these terms.",
        'warning' => "Dear {{first_name}},\n\nThis letter is a formal written warning about the following:\n\n{{details}}\n\nWe expect this to be corrected right away. If it continues, further steps may be taken, up to and including the end of your employment.\n\nThis letter will be kept in your employee file. You may add a written response, which will be kept with it.\n\nPlease sign below to confirm you received this letter. Signing does not mean you agree with it.\n\nSincerely,",
        'termination' => "Dear {{first_name}},\n\nThis letter confirms that your employment with {{agency}} as {{job_title}} ends on {{effective_date}}.\n\n{{details}}\n\nYou will receive your final pay, including any vacation pay owed, and your Record of Employment, as the employment law that applies requires.\n\nPlease return any keys, fobs and other property of {{agency}} by your last day.\n\nPlease sign below to confirm you received this letter.\n\nSincerely,",
    ];

    /** The agency's templates, creating any missing kind from the defaults. */
    public static function templates(int $agencyId): array
    {
        $have = DB::table('hr_letter_templates')->where('agency_id', $agencyId)->pluck('kind')->all();
        foreach (self::KINDS as $k => $title) {
            if (! in_array($k, $have, true)) {
                DB::table('hr_letter_templates')->insertOrIgnore(['agency_id' => $agencyId, 'kind' => $k, 'title' => $title, 'body' => self::DEFAULTS[$k],
                    'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $rows = DB::table('hr_letter_templates')->where('agency_id', $agencyId)->get()->keyBy('kind');

        return array_values(array_filter(array_map(fn ($k) => $rows[$k] ?? null, array_keys(self::KINDS))));
    }

    /** People who may sign for the agency: its own directors and admins (never the platform super admin). */
    public static function signatories(int $agencyId): array
    {
        return array_values(array_filter(AgencySignatories::ownStaff($agencyId),
            fn ($p) => (bool) array_intersect($p['roles'], ['Agency admin', 'Director'])));
    }

    public static function fullName(?object $u): string
    {
        return trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
    }

    /** Merge values for one person (+ send-time fields). Formal documents: full names. */
    public static function values(int $agencyId, int $userId, int $signatoryId, array $typed = []): array
    {
        $u = DB::table('users')->where('id', $userId)->first(['first_name', 'last_name', 'pay_rate', 'pay_type', 'hire_date']);
        $f = DB::table('hr_employee_files')->where('agency_id', $agencyId)->where('user_id', $userId)->first(['job_title', 'employment_type', 'primary_centre_id']);
        $s = DB::table('users')->where('id', $signatoryId)->first(['first_name', 'last_name']);
        $sf = DB::table('hr_employee_files')->where('agency_id', $agencyId)->where('user_id', $signatoryId)->value('job_title');
        $sigRoles = collect(self::signatories($agencyId))->firstWhere('id', $signatoryId)['roles'] ?? [];
        $cid = (int) ($f->primary_centre_id ?? 0) ?: (int) DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)->whereNotNull('centre_id')
            ->whereIn('centre_id', Live::centreIds($agencyId) ?: [0])->value('centre_id');
        $c = $cid ? DB::table('centres')->where('id', $cid)->first(['name', 'address_line1', 'city']) : null;
        $fmt = fn ($d) => $d ? Carbon::parse($d)->format('F j, Y') : '';
        $pay = '';
        if ($u && $u->pay_rate !== null) {
            $amt = '$' . number_format((float) $u->pay_rate, 2);
            $pay = $u->pay_type === 'salary' ? $amt . ' a year' : ($u->pay_type === 'per_visit' ? $amt . ' a visit' : $amt . ' an hour');
        }
        $types = ['full_time' => 'full-time', 'part_time' => 'part-time', 'casual' => 'casual', 'contract' => 'contract', 'supply' => 'supply'];

        return ['full_name' => self::fullName($u), 'first_name' => (string) ($u->first_name ?? ''), 'last_name' => (string) ($u->last_name ?? ''),
            'job_title' => (string) ($f->job_title ?? ''), 'employment_type' => $types[$f->employment_type ?? ''] ?? str_replace('_', '-', (string) ($f->employment_type ?? '')),
            'start_date' => $fmt($u->hire_date ?? null), 'pay' => $pay, 'centre' => (string) ($c->name ?? ''),
            'centre_address' => trim(implode(', ', array_filter([$c->address_line1 ?? null, $c->city ?? null]))),
            'agency' => (string) DB::table('agencies')->where('id', $agencyId)->value('name'), 'today' => Carbon::now(AgencyTime::tz($agencyId))->format('F j, Y'),
            'signatory_name' => self::fullName($s), 'signatory_title' => (string) ($sf ?: ($sigRoles[0] ?? '')),
            'effective_date' => $fmt($typed['effective_date'] ?? null), 'details' => trim((string) ($typed['details'] ?? '')),
            '_centre_id' => $cid ?: null];
    }

    /** The letter body as safe HTML: text escaped, {{ fields }} filled (escaped), blank lines = paragraphs. Empty paragraphs drop out. */
    public static function render(string $body, array $values): array
    {
        $missing = [];
        $blocking = [];
        $out = [];
        foreach (preg_split('/\n\s*\n/', str_replace("\r\n", "\n", trim($body))) as $para) {
            // A field alone in its paragraph drops out cleanly when empty; one inside a sentence would leave a gap
            // ("paid at ."), so the letter cannot be sent until it is filled in.
            $alone = (bool) preg_match('/^\s*\{\{\s*[a-z_]+\s*\}\}\s*$/', $para);
            $before = count($missing);
            $html = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($values, &$missing) {
                $v = $values[$m[1]] ?? null;
                if ($v === null || $v === '') { $missing[$m[1]] = true; return ''; }
                return "\x01" . base64_encode((string) $v) . "\x02";
            }, $para);
            $html = nl2br(e($html), false);
            $html = preg_replace_callback("/\x01([A-Za-z0-9+\/=]*)\x02/", fn ($m) => nl2br(e(base64_decode($m[1])), false), $html);
            if (! $alone) {
                preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $para, $mm);
                foreach ($mm[1] as $k) if (isset($missing[$k])) $blocking[$k] = true;
            }
            if (trim(strip_tags($html)) !== '') $out[] = '<p>' . $html . '</p>';
        }

        return ['html' => implode("\n", $out), 'missing' => array_keys($missing), 'blocking' => array_keys($blocking)];
    }
}
