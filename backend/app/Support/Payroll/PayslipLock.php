<?php

namespace App\Support\Payroll;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PAYSLIP PASSWORDS (2026-11-27, Anthony: "all payslips should follow iLearn's pass-protect rule for any agency going forward").
 *
 * iLearn's rule, as iLearn's own PayslipPdf applies it (B133/B137): every payslip PDF it makes is encrypted, the download
 * and the emailed copy alike; the password is the first 3 letters of the last name in CAPITALS followed by the date of
 * birth as MMDDYYYY; with no date of birth on file the last day of the pay period stands in, so the file is ALWAYS locked;
 * staff are told the FORMAT, never the password. There is no setting to turn it off, and there is none here.
 *
 * The same rule for every agency, on every payslip PDF a person can download or be emailed (engine, manual, Generate
 * payroll, gross-only, weekly statements) and on T4 / T4A / RL-1 copies (there the stand-in date is December 31 of the tax
 * year). Two gaps iLearn leaves are closed, so a file is never unlocked: no last name -> the first name's letters; no name
 * at all -> the word PAY. Accented letters count as their plain letter (Émond -> EMO) instead of vanishing.
 * Not locked: the office view (admins and directors reading a payslip in the portal) and the agency-wide year-end summary.
 */
final class PayslipLock
{
    private const STAFF_ROLES = ['educator', 'home_visitor', 'centre_director', 'agency_admin', 'cook', 'staff'];

    /**
     * The password for one person's document, and how they are told to work it out.
     * $coversEnd = the last day the document covers (pay period end; December 31 for a tax slip). $what = 'period' | 'year'.
     * @return array{password: string, name_basis: string, date_basis: string, hint: string}
     */
    public static function forPerson(int $userId, ?string $fallbackName, ?string $coversEnd, string $what = 'period'): array
    {
        $u = $userId > 0 ? DB::table('users')->where('id', $userId)->first(['first_name', 'last_name', 'date_of_birth']) : null;
        $letters = fn (?string $s) => mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', Str::ascii((string) $s)), 0, 3));

        $words = preg_split('/\s+/', trim((string) $fallbackName)) ?: [];
        [$stem, $nameBasis] = ($s = $letters($u->last_name ?? null)) !== '' ? [$s, 'last']
            : ((count($words) > 1 && ($s = $letters(end($words))) !== '') ? [$s, 'last']
            : ((($s = $letters($u->first_name ?? null)) !== '' || ($s = $letters($words[0] ?? null)) !== '') ? [$s, 'first'] : ['PAY', 'none']));

        $date = null;
        $dateBasis = 'dob';
        try {
            $dob = (string) ($u->date_of_birth ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dob) && $dob >= '1900-01-01') {
                $date = Carbon::parse(substr($dob, 0, 10));
            }
        } catch (\Throwable $e) {
            $date = null;
        }
        if (! $date) {
            $dateBasis = 'end';
            try {
                $date = $coversEnd ? Carbon::parse(substr($coversEnd, 0, 10)) : null;
            } catch (\Throwable $e) {
                $date = null;
            }
            $date ??= Carbon::create(2000, 1, 1);
        }

        return ['password' => $stem . $date->format('mdY'), 'name_basis' => $nameBasis, 'date_basis' => $dateBasis,
            'hint' => self::hint($nameBasis, $dateBasis, $what)];
    }

    /** The password for a payroll_documents row (payslip or statement). */
    public static function forDoc(object $doc): array
    {
        return self::forPerson((int) ($doc->user_id ?? 0), $doc->payee_name ?? null,
            ($doc->period_end ?? null) ?: (($doc->period_start ?? null) ?: ($doc->issued_at ?? null)), 'period');
    }

    /** What the person is told: the FORMAT, never the password. Matches iLearn's wording for the usual case. */
    public static function hint(string $nameBasis, string $dateBasis, string $what = 'period'): string
    {
        $name = match ($nameBasis) {
            'last' => 'First 3 letters of your last name in CAPITALS',
            'first' => 'First 3 letters of your first name in CAPITALS (there is no last name on your file)',
            default => 'The word PAY in CAPITALS (there is no name on your file)',
        };
        $date = $dateBasis === 'dob' ? 'then your date of birth as MMDDYYYY'
            : ($what === 'year' ? 'then December 31 of the tax year as MMDDYYYY (there is no date of birth on your file)'
                : 'then the last day of the pay period as MMDDYYYY (there is no date of birth on your file)');

        return $name . ', ' . $date . '.';
    }

    /** One person's hint for a list of their documents (the usual case: their date of birth is on file). */
    public static function hintForUser(int $userId, ?string $fallbackName = null, string $what = 'period'): string
    {
        $l = self::forPerson($userId, $fallbackName, null, $what);

        return $l['hint'];
    }

    /**
     * Staff whose payslips cannot use the usual password: no date of birth (the pay period end stands in) or no last name.
     * Everyone paid by this agency in the last 13 months plus everyone holding an active staff role; platform admins never.
     * @return array<int, array{user_id: int, name: string, ref: string, missing: string[]}>
     */
    public static function gaps(int $agencyId): array
    {
        $ids = DB::table('role_assignments')->where('agency_id', $agencyId)->where('active', 1)->whereIn('role', self::STAFF_ROLES)->pluck('user_id')
            ->merge(DB::table('payroll_documents')->where('agency_id', $agencyId)->where('kind', '!=', 'invoice')->where('user_id', '>', 0)
                ->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'void'))
                ->where('created_at', '>=', now()->subMonths(13))->pluck('user_id'))
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
        if (class_exists(\App\Support\AgencyOutsiders::class)) {
            $ids = array_values(array_diff($ids, array_map('intval', \App\Support\AgencyOutsiders::ids())));
        }
        if (! $ids) {
            return [];
        }
        $refs = \App\Support\RefCode::users($ids, $agencyId, 'S');
        $out = [];
        foreach (DB::table('users')->whereIn('id', $ids)->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'date_of_birth']) as $u) {
            $missing = [];
            if (! preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $u->date_of_birth) || (string) $u->date_of_birth < '1900-01-01') {
                $missing[] = 'date of birth';
            }
            if (trim(preg_replace('/[^A-Za-z]/', '', Str::ascii((string) $u->last_name))) === '') {
                $missing[] = 'last name';
            }
            if ($missing) {
                $out[] = ['user_id' => (int) $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name) ?: 'Unnamed staff member',
                    'ref' => (string) ($refs[(int) $u->id] ?? ''), 'missing' => $missing];
            }
        }

        return $out;
    }
}
