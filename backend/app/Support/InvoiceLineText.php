<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WHAT A PAYMENT-SCHEDULE INVOICE LINE SAYS (2026-10-31, Anthony: "invoices that were produced with payment schedule
 * should include the child's name and service under description and not show 'payment schedule 1 of x' in the
 * invoices and should be editable when creating the payment schedule").
 *
 * A schedule's `description` is a TEMPLATE. Placeholders, filled per invoice:
 *   {child}   the family's enrolled children, "Steven Saganek" / "Haven & Malachi Flynn"
 *   {service} what they attend, "Full-time child care (Mon–Fri)" / "Part-time child care (Tue, Thu)"
 *   {month}   the billed month of that invoice, "November 2026"
 *   {period}  that invoice's billing period, "Nov 1 – Nov 30, 2026"
 * Blank means the default, "{child} — {service}". Plain wording with no placeholder ("child care") still names the
 * child: "Steven Saganek — child care", unless it already mentions one of the children.
 * The instalment number ("3 of 7") is never printed; the schedule ↔ invoice link lives in payment_plan_installments.
 */
final class InvoiceLineText
{
    public const DEFAULT = '{child} — {service}';
    /** The old wording, recognised so drafts and reports can be corrected. */
    public const LEGACY_LINE = '/^Payment schedule instalment \d+ of \d+$/i';
    public const LEGACY_NOTE = '/^Payment schedule #\d+ — instalment \d+ of \d+$/u';

    /** The family's children this bill is for: enrolled first, anyone not withdrawn as a fallback. */
    public static function children(int $familyId): array
    {
        $q = fn () => DB::table('children')->where('family_id', $familyId)->whereNull('deleted_at')->orderBy('date_of_birth')->orderBy('first_name');
        $rows = $q()->where('enrollment_status', 'enrolled')->get(['id', 'first_name', 'last_name', 'preferred_name']);
        if ($rows->isEmpty()) {
            $rows = $q()->whereNotIn('enrollment_status', ['withdrawn', 'graduated'])->get(['id', 'first_name', 'last_name', 'preferred_name']);
        }

        return $rows->map(fn ($c) => ['id' => (int) $c->id, 'first' => trim((string) $c->first_name), 'last' => self::tidy((string) $c->last_name)])->all();
    }

    /** "Steven Saganek", "Haven & Malachi Flynn", "Alessia Marcotullio-Loots & Adriana Marcotullio-Gajadhar". */
    public static function childText(int $familyId): string
    {
        $kids = self::children($familyId);
        if (! $kids) {
            return '';
        }
        $lasts = array_unique(array_map(fn ($k) => mb_strtolower($k['last']), $kids));
        if (count($kids) > 1 && count($lasts) === 1 && $kids[0]['last'] !== '') {
            $firsts = array_map(fn ($k) => $k['first'], $kids);
            $tail = array_pop($firsts);

            return trim(($firsts ? implode(', ', $firsts) . ' & ' : '') . $tail . ' ' . $kids[0]['last']);
        }
        $names = array_map(fn ($k) => trim($k['first'] . ' ' . $k['last']), $kids);
        $tail = array_pop($names);

        return ($names ? implode(', ', $names) . ' & ' : '') . $tail;
    }

    /** "Full-time child care (Mon–Fri)" from the children's current enrolments; "Child care" when unknown. */
    public static function serviceText(int $familyId): string
    {
        $ids = array_column(self::children($familyId), 'id');
        if (! $ids) {
            return 'Child care';
        }
        $today = Carbon::today()->toDateString();
        $schedules = [];
        foreach ($ids as $cid) {
            $e = DB::table('enrollments')->where('child_id', $cid)
                ->where(fn ($w) => $w->whereNull('end_date')->orWhere('end_date', '>=', $today))
                ->orderByDesc('start_date')->first(['schedule']);
            $days = $e ? json_decode((string) $e->schedule, true) : null;
            if (is_array($days) && $days) {
                $schedules[] = array_values(array_intersect(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], array_map('strtolower', $days)));
            }
        }
        if (! $schedules) {
            return 'Child care';
        }
        $full = fn ($d) => count(array_intersect($d, ['mon', 'tue', 'wed', 'thu', 'fri'])) === 5;
        $first = $schedules[0];
        $same = count(array_unique(array_map(fn ($d) => implode(',', $d), $schedules))) === 1;
        if (! $same) {
            $kinds = array_unique(array_map(fn ($d) => $full($d) ? 'Full-time' : 'Part-time', $schedules));

            return count($kinds) === 1 ? reset($kinds) . ' child care' : 'Full-time & part-time child care';
        }
        $label = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];
        if ($full($first)) {
            return 'Full-time child care (Mon–Fri)';
        }

        return 'Part-time child care (' . implode(', ', array_map(fn ($d) => $label[$d], $first)) . ')';
    }

    /**
     * The printed line for one invoice. $template is the schedule's description (blank = DEFAULT).
     * $billedMonth / $periodStart / $periodEnd are Y-m-d strings for that invoice.
     */
    public static function resolve(?string $template, int $familyId, ?string $billedMonth = null, ?string $periodStart = null, ?string $periodEnd = null): string
    {
        $t = trim((string) $template);
        if ($t === '' || preg_match(self::LEGACY_LINE, $t)) {
            $t = self::DEFAULT;
        }
        $child = self::childText($familyId);
        $hasPlaceholder = (bool) preg_match('/\{(child|service|month|period)\}/i', $t);
        if (! $hasPlaceholder && $child !== '' && ! self::mentionsAChild($t, $familyId)) {
            $t = '{child} — ' . $t;
        }
        $month = $billedMonth ? Carbon::parse(substr($billedMonth, 0, 10))->format('F Y') : '';
        $period = '';
        if ($periodStart && $periodEnd) {
            $a = Carbon::parse(substr($periodStart, 0, 10));
            $b = Carbon::parse(substr($periodEnd, 0, 10));
            $period = $a->format('M j') . ' – ' . $b->format($a->year === $b->year ? 'M j, Y' : 'M j, Y');
        }
        $out = preg_replace_callback('/\{(child|service|month|period)\}/i', function ($m) use ($child, $familyId, $month, $period) {
            switch (strtolower($m[1])) {
                case 'child': return $child;
                case 'service': return self::serviceText($familyId);
                case 'month': return $month;
                default: return $period;
            }
        }, $t);
        // Tidy what an empty placeholder leaves behind: " — " at either end, doubled dashes/spaces.
        $out = preg_replace('/\s{2,}/u', ' ', (string) $out);
        $out = preg_replace('/^\s*[—–\-,]\s*|\s*[—–\-,]\s*$/u', '', $out);
        $out = trim((string) $out);

        return mb_substr($out !== '' ? $out : 'Child care', 0, 255);
    }

    private static function mentionsAChild(string $text, int $familyId): bool
    {
        $t = mb_strtolower($text);
        foreach (self::children($familyId) as $k) {
            if ($k['first'] !== '' && mb_strpos($t, mb_strtolower($k['first'])) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function tidy(string $s): string
    {
        // "Marcotullio- Gajadhar" (a stray space after a hyphen in the source data) → "Marcotullio-Gajadhar".
        return trim((string) preg_replace('/\s*-\s*/u', '-', $s));
    }
}
