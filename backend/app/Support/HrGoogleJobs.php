<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GOOGLE JOBS FOR AN AGENCY'S PUBLIC JOBS PAGE (2026-11-27, seo1127). Anthony: list an agency's open posts in Google
 * Jobs ONLY when the agency switches it on (Human Resources > HR settings, agency admins; off by default).
 *
 *  - Stored at agencies.settings.hr_google_jobs = {enabled: bool, changed_at}. Kept OUT of settings.hr_talent on
 *    purpose: HrTalent::saveSettings rewrites that block with its own keys only.
 *  - forPublic() feeds GET hr/public/jobs/{slug}: null when off (jobs.html then marks itself noindex), else one
 *    schema.org JobPosting per OPEN post (closed/filled posts are not open, so they drop out by themselves).
 *  - Only fields Google documents: title, description, datePosted, employmentType, hiringOrganization, jobLocation,
 *    baseSalary (only when the post has pay), directApply. No validThrough: posts have no closing date.
 *  - jobLocation is the town, province/state and country ONLY. Home child care "centres" are often a provider's own
 *    home, so a street address or postal code is never published.
 *  - hiringOrganization is the agency's own name, logo and website (white-label agencies show only their brand).
 */
final class HrGoogleJobs
{
    private const TYPES = ['full_time' => 'FULL_TIME', 'part_time' => 'PART_TIME', 'casual' => 'PER_DIEM', 'student' => 'PART_TIME', 'contract' => 'CONTRACTOR'];

    public static function enabled(int $agencyId): bool
    {
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];

        return (bool) (($s['hr_google_jobs']['enabled'] ?? false) === true);
    }

    public static function save(int $agencyId, bool $on): bool
    {
        DB::transaction(function () use ($agencyId, $on) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['settings']);
            $all = json_decode((string) ($row->settings ?? ''), true) ?: [];
            $all['hr_google_jobs'] = ['enabled' => $on, 'changed_at' => now()->toIso8601String()];
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        });

        return self::enabled($agencyId);
    }

    /** The open posts the public page shows (same filter as HrHiringController::publicJobs). */
    public static function openPosts(int $agencyId)
    {
        $live = Live::centreIds($agencyId);

        return DB::table('hr_job_posts')->where('agency_id', $agencyId)->where('status', 'open')->orderByDesc('created_at')->get()
            ->filter(fn ($p) => ! $p->centre_id || in_array((int) $p->centre_id, $live, true))->values();
    }

    /** @return array<int,array>|null JobPosting objects keyed by post id, or null when the agency has not switched Google Jobs on. */
    public static function forPublic(int $agencyId, array $org): ?array
    {
        if (! self::enabled($agencyId)) {
            return null;
        }
        $a = DB::table('agencies')->where('id', $agencyId)->first(['city', 'province', 'country', 'currency', 'website']);
        $posts = self::openPosts($agencyId);
        $centres = DB::table('centres')->whereIn('id', $posts->pluck('centre_id')->filter()->all() ?: [0])->get(['id', 'city', 'province', 'country'])->keyBy('id');
        $tz = AgencyTime::tz($agencyId);
        $hiring = ['@type' => 'Organization', 'name' => $org['name']];
        if (! empty($org['logo'])) $hiring['logo'] = $org['logo'];
        $site = trim((string) ($a->website ?? ''));
        if ($site !== '') $hiring['sameAs'] = preg_match('~^https?://~i', $site) ? $site : 'https://' . $site;

        $out = [];
        foreach ($posts as $p) {
            $c = $p->centre_id ? ($centres[$p->centre_id] ?? null) : null;
            $addr = array_filter(['@type' => 'PostalAddress', 'addressLocality' => $c->city ?? $a->city, 'addressRegion' => $c->province ?? $a->province,
                'addressCountry' => self::country($c->country ?? $a->country, $a->currency)]);
            $job = ['@context' => 'https://schema.org/', '@type' => 'JobPosting', 'title' => (string) $p->title,
                'description' => self::html((string) ($p->description ?: $p->title)),
                'datePosted' => Carbon::parse($p->created_at, 'UTC')->tz($tz)->toDateString(),
                'hiringOrganization' => $hiring, 'jobLocation' => ['@type' => 'Place', 'address' => $addr], 'directApply' => true];
            if (isset(self::TYPES[$p->employment_type ?? ''])) $job['employmentType'] = self::TYPES[$p->employment_type];
            if ($p->pay_min !== null || $p->pay_max !== null) {
                $v = ['@type' => 'QuantitativeValue', 'unitText' => $p->pay_unit === 'year' ? 'YEAR' : 'HOUR'];
                if ($p->pay_min !== null && $p->pay_max !== null && (float) $p->pay_min !== (float) $p->pay_max) {
                    $v['minValue'] = (float) $p->pay_min;
                    $v['maxValue'] = (float) $p->pay_max;
                } else {
                    $v['value'] = (float) ($p->pay_min ?? $p->pay_max);
                }
                $job['baseSalary'] = ['@type' => 'MonetaryAmount', 'currency' => strtoupper((string) ($a->currency ?: 'CAD')), 'value' => $v];
            }
            $out[(int) $p->id] = $job;   // keyed by post id: jobs.html adds the one for the job page it shows
        }

        return $out;
    }

    private static function country(?string $c, ?string $currency): ?string
    {
        $c = strtoupper(trim((string) $c));
        if (in_array($c, ['CA', 'CANADA'], true)) return 'CA';
        if (in_array($c, ['US', 'USA', 'UNITED STATES', 'UNITED STATES OF AMERICA'], true)) return 'US';
        if (strlen($c) === 2) return $c;

        return strtoupper((string) $currency) === 'USD' ? 'US' : 'CA';
    }

    /** Plain text from the post editor -> the simple HTML Google expects (escaped, paragraphs and line breaks kept). */
    private static function html(string $t): string
    {
        $paras = preg_split("/\R{2,}/", trim($t)) ?: [];

        return implode('', array_map(fn ($x) => '<p>' . nl2br(htmlspecialchars(trim($x), ENT_QUOTES, 'UTF-8'), false) . '</p>', $paras));
    }
}
