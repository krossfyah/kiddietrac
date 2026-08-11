<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * v22p22 — Cross-agency platform-admin tooling. Every method assumes the
 * caller is platform_admin (gated by route middleware role:platform_admin
 * + EnsureRole's superset bypass shipped in v22p21).
 */
final class PlatformController extends Controller
{
    /**
     * GET /api/v1/platform/overview
     * Returns total counts across every active agency in the system.
     */
    public function overview(Request $request): JsonResponse
    {
        $agencies = DB::table('agencies')->whereNull('deleted_at')->count();
        $centres  = DB::table('centres')->whereNull('deleted_at')->count();
        $rooms    = DB::table('rooms')->count();
        $families = DB::table('families')->whereNull('deleted_at')->count();
        $children = DB::table('children')->whereNull('deleted_at')->count();
        $staff    = DB::table('role_assignments')
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator'])
            ->where('active', true)
            ->distinct()
            ->count('user_id');
        $guardians = DB::table('guardians')->distinct()->count('user_id');

        // Plan MRR — sum each active agency's plan_amount_cents (in cents).
        $mrrCents = (int) DB::table('agencies')
            ->whereNull('deleted_at')
            ->where('billing_status', 'active')
            ->sum('plan_amount_cents');

        // Recent agency signups + churn — last 30 days
        $thirtyDaysAgo = now()->subDays(30);
        $newAgencies30d = DB::table('agencies')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->whereNull('deleted_at')
            ->count();
        $cancelledAgencies30d = DB::table('agencies')
            ->where('cancelled_at', '>=', $thirtyDaysAgo)
            ->count();

        // v22p32: widget data ------------------------------------------------

        // Agency growth — last 6 calendar months
        $agencyGrowth = [];
        for ($i = 5; $i >= 0; $i--) {
            $start = now()->startOfMonth()->subMonths($i);
            $end   = (clone $start)->endOfMonth();
            $signups = DB::table('agencies')
                ->whereBetween('created_at', [$start, $end])
                ->whereNull('deleted_at')
                ->count();
            $cancelled = DB::table('agencies')
                ->whereBetween('cancelled_at', [$start, $end])
                ->count();
            $agencyGrowth[] = [
                'label' => $start->format('M'),
                'period' => $start->format('Y-m'),
                'signups' => $signups,
                'cancelled' => $cancelled,
            ];
        }

        // MRR trend — snapshot active agencies at the END of each of the last 6 months
        $mrrTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthEnd = now()->startOfMonth()->subMonths($i)->endOfMonth();
            $rows = DB::table('agencies')
                ->where('created_at', '<=', $monthEnd)
                ->where(function ($q) use ($monthEnd) {
                    $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>', $monthEnd);
                })
                ->whereNull('deleted_at')
                ->where('billing_status', 'active')
                ->sum('plan_amount_cents');
            $mrrTrend[] = [
                'label' => $monthEnd->format('M'),
                'period' => $monthEnd->format('Y-m'),
                'mrr_cents' => (int) $rows,
            ];
        }

        // Top agencies by child enrolment. children link to a family (no direct
        // agency_id), so walk children -> families -> centres -> agencies.
        $topAgencies = DB::table('agencies as a')
            ->leftJoin('centres as ce', function ($j) {
                $j->on('ce.agency_id', '=', 'a.id')->whereNull('ce.deleted_at');
            })
            ->leftJoin('families as f', function ($j) {
                $j->on('f.centre_id', '=', 'ce.id')->whereNull('f.deleted_at');
            })
            ->leftJoin('children as c', function ($j) {
                $j->on('c.family_id', '=', 'f.id')->whereNull('c.deleted_at');
            })
            ->whereNull('a.deleted_at')
            ->groupBy('a.id', 'a.name', 'a.brand_primary_color', 'a.billing_status')
            ->orderByRaw('COUNT(DISTINCT c.id) DESC')
            ->limit(5)
            ->get(['a.id', 'a.name', 'a.brand_primary_color as accent', 'a.billing_status as status', DB::raw('COUNT(DISTINCT c.id) as children')]);

        // Recent platform events from audit_logs — enriched with a human name for
        // the affected entity (e.g. WHICH user was deleted) so the feed isn't generic.
        $recentEvents = DB::table('audit_logs as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
            ->orderByDesc('al.created_at')
            ->limit(12)
            ->get([
                'al.id', 'al.action', 'al.entity_type', 'al.entity_id', 'al.created_at', 'al.payload',
                DB::raw("COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'system') as actor"),
            ]);
        $recentEvents = $recentEvents->map(function ($ev) {
            $ev->detail = $this->describeAuditEntity($ev->entity_type, $ev->entity_id ? (int) $ev->entity_id : null, $ev->payload);
            unset($ev->payload);
            return $ev;
        });

        // Active sessions in the last 24 hours — distinct users with a recently-used token
        $sessionsToday = DB::table('personal_access_tokens')
            ->where('last_used_at', '>=', now()->subDay())
            ->distinct()
            ->count('tokenable_id');

        return response()->json([
            'totals' => [
                'agencies' => $agencies,
                'centres'  => $centres,
                'rooms'    => $rooms,
                'families' => $families,
                'children' => $children,
                'staff'    => $staff,
                'guardians' => $guardians,
                'mrr_cents' => $mrrCents,
                'mrr_dollars' => $mrrCents / 100,
                'active_sessions_24h' => $sessionsToday,
            ],
            'recent_30d' => [
                'new_agencies' => $newAgencies30d,
                'cancelled_agencies' => $cancelledAgencies30d,
                'net' => $newAgencies30d - $cancelledAgencies30d,
            ],
            'agency_growth' => $agencyGrowth,
            'mrr_trend' => $mrrTrend,
            'top_agencies' => $topAgencies,
            'recent_events' => $recentEvents,
            'business_metrics' => $this->businessMetrics($mrrCents, $mrrTrend, $children, $agencies, $thirtyDaysAgo),
            'platform_performance' => $this->platformPerformance($thirtyDaysAgo),
        ]);
    }

    /**
     * Operational health + usage/engagement for the platform overview.
     * All cheap aggregates over email_logs / audit_logs / check_events.
     */
    private function platformPerformance($thirtyDaysAgo): array
    {
        $now = now();
        $sevenDaysAgo = $now->copy()->subDays(7);
        $today = $now->copy()->startOfDay();
        $dayAgo = $now->copy()->subDay();

        // ── Email deliverability (last 7 days) ──
        $emailTotal = DB::table('email_logs')->where('created_at', '>=', $sevenDaysAgo)->count();
        $emailFailed = DB::table('email_logs')->where('created_at', '>=', $sevenDaysAgo)->whereIn('status', ['failed', 'error'])->count();
        $emailSuppressed = DB::table('email_logs')->where('created_at', '>=', $sevenDaysAgo)->where('status', 'suppressed')->count();
        $emailOpened = DB::table('email_logs')->where('created_at', '>=', $sevenDaysAgo)->whereNotNull('opened_at')->count();
        $emailDelivered = max(0, $emailTotal - $emailFailed - $emailSuppressed);
        $openRate = $emailDelivered > 0 ? (int) round(($emailOpened / $emailDelivered) * 100) : 0;
        $failRate = $emailTotal > 0 ? round(($emailFailed / $emailTotal) * 100, 1) : 0.0;

        // ── Usage & engagement ──
        $loginsToday = DB::table('audit_logs')->where('action', 'login')->where('created_at', '>=', $today)->count();
        $logins7d = DB::table('audit_logs')->where('action', 'login')->where('created_at', '>=', $sevenDaysAgo)->count();
        $activeUsers24h = DB::table('audit_logs')->where('created_at', '>=', $dayAgo)->whereNotNull('user_id')->distinct()->count('user_id');

        // Active agencies = those with a child check-in in the last 30 days.
        $activeAgencies = DB::table('check_events as ce')
            ->join('rooms as r', 'r.id', '=', 'ce.room_id')
            ->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('ce.occurred_at', '>=', $thirtyDaysAgo)
            ->whereNull('c.deleted_at')
            ->distinct()->count('c.agency_id');
        $totalAgencies = DB::table('agencies')->whereNull('deleted_at')->count();
        $dormantAgencies = max(0, $totalAgencies - $activeAgencies);

        // ── System ──
        $dbMb = 0;
        try {
            $r = DB::selectOne('SELECT ROUND(SUM(data_length + index_length) / 1048576) AS mb FROM information_schema.tables WHERE table_schema = DATABASE()');
            $dbMb = (int) ($r->mb ?? 0);
        } catch (\Throwable $e) {
        }

        return [
            'email' => [
                'window_days' => 7,
                'total' => $emailTotal,
                'delivered' => $emailDelivered,
                'opened' => $emailOpened,
                'failed' => $emailFailed,
                'suppressed' => $emailSuppressed,
                'open_rate_pct' => $openRate,
                'fail_rate_pct' => $failRate,
            ],
            'usage' => [
                'logins_today' => $loginsToday,
                'logins_7d' => $logins7d,
                'active_users_24h' => $activeUsers24h,
                'active_agencies_30d' => $activeAgencies,
                'dormant_agencies' => $dormantAgencies,
                'total_agencies' => $totalAgencies,
            ],
            'system' => [
                'db_size_mb' => $dbMb,
                'api_ok' => true,
                'generated_at' => $now->toIso8601String(),
            ],
        ];
    }

    /**
     * v22p34: SaaS business metrics derived from the trend + totals already
     * loaded by overview(). All numbers are cheap aggregates — no new SQL
     * round-trips, the inputs were already computed for the trend charts.
     */
    private function businessMetrics(int $mrrCents, array $mrrTrend, int $children, int $agencies, $thirtyDaysAgo): array
    {
        $mrrDollars = $mrrCents / 100;
        $arrDollars = $mrrDollars * 12;

        // ARPA — average revenue per active agency
        $arpa = $agencies > 0 ? $mrrDollars / $agencies : 0.0;

        // ARPU — per enrolled child (operator's per-seat cost equivalent)
        $arpu = $children > 0 ? $mrrDollars / $children : 0.0;

        // MoM MRR growth — last vs prior month from the trend snapshot
        $lastMrr = end($mrrTrend)['mrr_cents'] ?? 0;
        $prevMrr = $mrrTrend[count($mrrTrend) - 2]['mrr_cents'] ?? 0;
        $mrrGrowthPct = $prevMrr > 0 ? round((($lastMrr - $prevMrr) / $prevMrr) * 100, 1) : 0.0;
        $mrrGrowthAbs = ($lastMrr - $prevMrr) / 100;

        // Churn rate (last 30 days) — cancelled / starting count
        $startingAgencies = DB::table('agencies')
            ->where('created_at', '<', $thirtyDaysAgo)
            ->where(function ($q) use ($thirtyDaysAgo) {
                $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>=', $thirtyDaysAgo);
            })
            ->whereNull('deleted_at')
            ->count();
        $cancelledLast30 = DB::table('agencies')
            ->where('cancelled_at', '>=', $thirtyDaysAgo)
            ->count();
        $churnPct = $startingAgencies > 0 ? round(($cancelledLast30 / $startingAgencies) * 100, 1) : 0.0;

        // LTV estimate — ARPA / monthly churn rate. Defaults to 24 months
        // when churn is 0 (insufficient data). Capped at 60 months to keep
        // the number visually sensible.
        $monthlyChurnRate = $churnPct / 100;
        $ltvMonths = $monthlyChurnRate > 0 ? min(60, round(1 / $monthlyChurnRate, 1)) : 24;
        $ltvDollars = $arpa * $ltvMonths;

        // Capacity utilisation — total enrolled / total licensed capacity
        $licensedCapacity = (int) DB::table('centres')->whereNull('deleted_at')->sum('license_capacity');
        $enrolledCount = DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->where('c.enrollment_status', 'enrolled')
            ->whereNull('c.deleted_at')
            ->count();
        $capacityPct = $licensedCapacity > 0 ? round(($enrolledCount / $licensedCapacity) * 100, 1) : 0.0;

        // Net Revenue Retention (loose proxy) — current MRR over MRR six
        // months ago. Above 100% = expansion; below = net contraction.
        $sixMoMrr = $mrrTrend[0]['mrr_cents'] ?? 0;
        $nrrPct = $sixMoMrr > 0 ? round(($lastMrr / $sixMoMrr) * 100, 1) : 0.0;

        return [
            'mrr_dollars' => $mrrDollars,
            'arr_dollars' => $arrDollars,
            'arpa_dollars' => round($arpa, 2),
            'arpu_dollars' => round($arpu, 2),
            'mrr_growth_pct' => $mrrGrowthPct,
            'mrr_growth_abs_dollars' => round($mrrGrowthAbs, 2),
            'churn_pct_30d' => $churnPct,
            'ltv_months' => $ltvMonths,
            'ltv_dollars' => round($ltvDollars, 2),
            'capacity_pct' => $capacityPct,
            'capacity_filled' => $enrolledCount,
            'capacity_licensed' => $licensedCapacity,
            'nrr_pct_6m' => $nrrPct,
        ];
    }

    /**
     * GET /api/v1/platform/agencies
     * List every agency with per-tenant stats.
     */
    public function listAgencies(Request $request): JsonResponse
    {
        $agencies = DB::table('agencies')->whereNull('deleted_at')->orderBy('name')->get();
        $agencyIds = $agencies->pluck('id')->all();

        $centresPerAgency = DB::table('centres')
            ->whereIn('agency_id', $agencyIds)
            ->whereNull('deleted_at')
            ->select('agency_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('agency_id')
            ->pluck('cnt', 'agency_id');

        $centreIdsPerAgency = DB::table('centres')
            ->whereIn('agency_id', $agencyIds)
            ->whereNull('deleted_at')
            ->select('agency_id', 'id')
            ->get()
            ->groupBy('agency_id')
            ->map(fn ($g) => $g->pluck('id')->all());

        $result = $agencies->map(function ($a) use ($centresPerAgency, $centreIdsPerAgency) {
            $cids = $centreIdsPerAgency[$a->id] ?? [];
            $familyCount = empty($cids) ? 0 : DB::table('families')->whereIn('centre_id', $cids)->whereNull('deleted_at')->count();
            $childCount  = empty($cids) ? 0 : DB::table('children')
                ->join('families', 'families.id', '=', 'children.family_id')
                ->whereIn('families.centre_id', $cids)
                ->whereNull('children.deleted_at')
                ->count();
            $set = json_decode($a->settings ?? '{}', true) ?: [];
            return [
                'id' => $a->id,
                'name' => $a->name,
                'slug' => $a->slug,
                'contact_email' => $a->contact_email,
                'contact_phone' => $a->contact_phone,
                'billing_status' => $a->billing_status,
                'plan_code' => $a->plan_code,
                'plan_amount_cents' => $a->plan_amount_cents,
                'plan_currency' => $a->plan_currency ?? 'CAD',
                'locale' => $a->locale ?? null,
                // v22p92: address + residence country + default language (Edit-all-fields)
                'brand_address' => $set['brand_address'] ?? null,
                'country' => $set['country'] ?? null,
                'default_locale' => $set['default_locale'] ?? ($a->locale ?? null),
                // Explicit agency owner (super-admin editable) for the Edit modal.
                'owner_name' => $set['owner']['name'] ?? null,
                'owner_email' => $set['owner']['email'] ?? null,
                'centre_count' => (int) ($centresPerAgency[$a->id] ?? 0),
                'family_count' => $familyCount,
                'child_count' => $childCount,
                // v22p24: white-label branding fields for the Edit modal
                'brand_logo_url' => $a->brand_logo_url,
                'brand_primary_color' => $a->brand_primary_color,
                'brand_support_email' => $a->brand_support_email,
                'powered_by_visible' => (int) $a->powered_by_visible,
                // v22p36: per-agency SMTP settings — password is masked in the
                // response so a leaked /platform/agencies dump doesn't leak the
                // mailbox password. The Edit modal sends an empty value to mean
                // 'keep existing' and a non-empty value to replace.
                'email_smtp_host' => $a->email_smtp_host ?? null,
                'email_smtp_port' => $a->email_smtp_port ?? null,
                'email_smtp_user' => $a->email_smtp_user ?? null,
                'email_smtp_pass_set' => !empty($a->email_smtp_pass),
                'email_smtp_encryption' => $a->email_smtp_encryption ?? 'tls',
                'email_from_address' => $a->email_from_address ?? null,
                'email_from_name' => $a->email_from_name ?? null,
                'created_at' => $a->created_at,
                'cancelled_at' => $a->cancelled_at,
            ];
        });

        return response()->json(['agencies' => $result->values()->all()]);
    }

    /**
     * POST /api/v1/platform/agencies
     * Create a brand-new customer agency. The first agency_admin can be
     * invited separately via /admin/users once the agency exists.
     */
    public function createAgency(Request $request): JsonResponse
    {
        // v22p91 — wizard-style provisioning. All caller-facing fields are
        // mandatory; the wizard collects plan + logo, agency address + contact,
        // and the first agency admin, then we provision agency + default centre +
        // admin (invited) in one transaction. White-label is granted by the plan
        // (growth / enterprise) — the admin configures it on first login.
        $data = $request->validate([
            'name'                => ['required', 'string', 'max:180'],
            'slug'                => ['nullable', 'string', 'max:80'],
            'contact_email'       => ['required', 'email', 'max:180'],
            'contact_phone'       => ['required', 'string', 'max:40'],
            'timezone'            => ['nullable', 'string', 'max:60'],
            'plan_code'           => ['required', 'string', 'in:starter,growth,enterprise'],
            'plan_amount_cents'   => ['nullable', 'integer', 'min:0'],
            // Agency address (mandatory)
            'address_line1'       => ['required', 'string', 'max:191'],
            'address_line2'       => ['nullable', 'string', 'max:191'],
            'city'                => ['required', 'string', 'max:120'],
            'province'            => ['required', 'string', 'max:120'],
            'postal_code'         => ['required', 'string', 'max:20'],
            'country'             => ['required', 'string', 'size:2'],   // residence country (sets currency/compliance)
            'default_locale'      => ['required', 'string', 'max:8'],    // default language
            // Branding
            'brand_logo_url'      => ['nullable', 'string', 'max:500'],
            'brand_primary_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'brand_support_email' => ['nullable', 'email', 'max:160'],
            // First agency admin (mandatory)
            'admin_first_name'    => ['required', 'string', 'max:80'],
            'admin_last_name'     => ['required', 'string', 'max:80'],
            'admin_email'         => ['required', 'email', 'max:180', 'unique:users,email'],
            'admin_phone'         => ['required', 'string', 'max:40'],
        ]);

        $planAmounts = ['starter' => 4900, 'growth' => 14900, 'enterprise' => 34900];
        $amount = $data['plan_amount_cents'] ?? ($planAmounts[$data['plan_code']] ?? 0);
        // White-label is included on growth + enterprise (FeatureFlag plan_min=growth).
        $whiteLabel = in_array($data['plan_code'], ['growth', 'enterprise'], true);

        // Residence country → currency + display name (also drives compliance).
        $countryMap = [
            'CA' => ['cur' => 'CAD', 'name' => 'Canada'],
            'US' => ['cur' => 'USD', 'name' => 'United States'],
            'GB' => ['cur' => 'GBP', 'name' => 'United Kingdom'],
            'AU' => ['cur' => 'AUD', 'name' => 'Australia'],
            'NZ' => ['cur' => 'NZD', 'name' => 'New Zealand'],
            'IE' => ['cur' => 'EUR', 'name' => 'Ireland'],
        ];
        $cm = $countryMap[$data['country']] ?? ['cur' => 'CAD', 'name' => $data['country']];
        $currency = $cm['cur'];
        $countryName = $cm['name'];
        $locale = $data['default_locale'];

        $slug = $data['slug'] ?? Str::slug($data['name']);
        if (! $slug) $slug = 'agency_'.uniqid();
        $base = $slug;
        $i = 1;
        while (DB::table('agencies')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        $address = trim($data['address_line1']
            .(! empty($data['address_line2']) ? "\n".$data['address_line2'] : '')
            ."\n".$data['city'].', '.$data['province'].'  '.$data['postal_code']
            ."\n".$countryName);

        $result = DB::transaction(function () use ($data, $slug, $amount, $whiteLabel, $address, $currency, $countryName, $locale) {
            $agencyId = DB::table('agencies')->insertGetId([
                'name'                => $data['name'],
                'slug'                => $slug,
                'contact_email'       => $data['contact_email'],
                'contact_phone'       => $data['contact_phone'],
                'timezone'            => $data['timezone'] ?? 'America/Toronto',
                'locale'              => $locale,
                'billing_status'      => 'trial',
                'plan_code'           => $data['plan_code'],
                'plan_amount_cents'   => $amount,
                'plan_currency'       => $currency,
                'trial_ends_at'       => now()->addDays(30),
                'brand_logo_url'      => $data['brand_logo_url'] ?? null,
                'brand_primary_color' => $data['brand_primary_color'] ?? null,
                'brand_support_email' => $data['brand_support_email'] ?? $data['contact_email'],
                'powered_by_visible'  => $whiteLabel ? 0 : 1,
                'settings'            => json_encode(['brand_address' => $address, 'country' => $data['country'], 'default_locale' => $locale]),
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            // Default first centre from the agency address — keeps rooms/families/
            // children functional. The admin can rename / add more under Centres.
            $centreId = DB::table('centres')->insertGetId([
                'agency_id'     => $agencyId,
                'name'          => $data['name'],
                'slug'          => $slug.'-main',
                'address_line1' => $data['address_line1'],
                'address_line2' => $data['address_line2'] ?? null,
                'city'          => $data['city'],
                'province'      => $data['province'],
                'postal_code'   => $data['postal_code'],
                'country'       => $countryName,
                'phone'         => $data['contact_phone'],
                'email'         => $data['contact_email'],
                'status'        => 'onboarding',
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            // First agency admin — created 'invited', activates via the emailed link.
            $userId = DB::table('users')->insertGetId([
                'email'      => $data['admin_email'],
                'password'   => Hash::make(Str::random(32)),
                'first_name' => $data['admin_first_name'],
                'last_name'  => $data['admin_last_name'],
                'phone'      => $data['admin_phone'],
                'status'     => 'invited',
                'locale'     => 'en-CA',
                'timezone'   => 'America/Toronto',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('role_assignments')->insert([
                'user_id'    => $userId,
                'role'       => 'agency_admin',
                'agency_id'  => $agencyId,
                'centre_id'  => null,
                'active'     => 1,
                'created_at' => now(),
            ]);

            $token = bin2hex(random_bytes(32));
            DB::table('password_resets')->insert([
                'email'      => $data['admin_email'],
                'token'      => hash('sha256', $token), // store hashed; link carries plaintext
                'expires_at' => now()->addDays(7),
                'created_at' => now(),
            ]);

            return ['agency_id' => $agencyId, 'centre_id' => $centreId, 'user_id' => $userId, 'token' => $token];
        });

        $link = 'https://app.kiddietrac.com/set-password.html?token='.$result['token'];
        $this->sendAdminInvite($data, $link);

        return response()->json([
            'agency'              => DB::table('agencies')->where('id', $result['agency_id'])->first(),
            'admin_user_id'       => $result['user_id'],
            'centre_id'           => $result['centre_id'],
            'white_label_enabled' => $whiteLabel,
            'invite_link'         => $link,
            'message'             => 'Agency provisioned. The admin has been emailed an invite to set their password.',
        ], 201);
    }

    /**
     * Email the first agency admin a set-password link. Sends branded HTML
     * (better deliverability than plain text) with proper Reply-To +
     * List-Unsubscribe headers and an open-tracking pixel. Logs the send
     * directly (with the tracking token) — the global MessageSent listener
     * skips this one via the X-KT-Logged header to avoid a duplicate row.
     */
    /**
     * A human-readable name for the entity an audit event touched — resolved from
     * the audit payload first (survives deletion) then a live lookup, so the
     * activity feed reads "deleted — Jane Doe" instead of a generic "user #123".
     */
    private function describeAuditEntity(?string $type, ?int $id, ?string $payload): ?string
    {
        $data = $payload ? (json_decode($payload, true) ?: []) : [];
        $input = is_array($data['input'] ?? null) ? $data['input'] : [];
        $fromPayload = $data['name'] ?? $data['email'] ?? $data['to'] ?? ($input['name'] ?? $input['email'] ?? null);
        if (is_string($fromPayload) && trim($fromPayload) !== '') {
            return $fromPayload;
        }
        if (! $type || ! $id) {
            return null;
        }
        $map = ['users' => 'user', 'user' => 'user', 'agencies' => 'agency', 'agency' => 'agency', 'centres' => 'centre', 'centre' => 'centre', 'children' => 'child', 'child' => 'child'];
        $t = $map[strtolower($type)] ?? strtolower($type);
        try {
            switch ($t) {
                case 'user':
                    $r = DB::table('users')->where('id', $id)->first(['first_name', 'last_name', 'email']);
                    if (! $r) return null;
                    $n = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
                    return $n !== '' ? $n : $r->email;
                case 'agency':
                    return DB::table('agencies')->where('id', $id)->value('name') ?: null;
                case 'centre':
                    return DB::table('centres')->where('id', $id)->value('name') ?: null;
                case 'child':
                    $r = DB::table('children')->where('id', $id)->first(['first_name', 'last_name']);
                    return $r ? (trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: null) : null;
            }
        } catch (\Throwable $e) {
            // best-effort — never break the overview
        }
        return null;
    }

    private function sendAdminInvite(array $data, string $link): void
    {
        $trackToken = bin2hex(random_bytes(16));
        $apiBase = preg_replace('#/api/v1/?$#', '', rtrim((string) config('app.url', 'https://api.kiddietrac.com'), '/'));
        $pixel = '<img src="'.$apiBase.'/api/v1/e/o/'.$trackToken.'" width="1" height="1" alt="" style="display:none;border:0;">';
        $name = htmlspecialchars($data['name']);
        $first = htmlspecialchars($data['admin_first_name']);
        $safeLink = htmlspecialchars($link);

        $body = '<p style="margin:0 0 14px;">Hi '.$first.',</p>'
            .'<p style="margin:0 0 16px;">Your agency account for <strong>'.$name.'</strong> on Kiddietrac is ready. '
            .'Set your password below and you\'ll be taken straight into your dashboard.</p>'
            .\App\Services\EmailTemplate::button('Set my password →', $link)
            .'<p style="margin:16px 0 0;font-size:12px;color:#64748B;">Or paste this into your browser:<br>'
            .'<a href="'.$safeLink.'" style="color:#1F6080;">'.$safeLink.'</a></p>'
            .'<p style="margin:14px 0 0;font-size:12px;color:#94A3B8;">This link expires in 7 days. If you weren\'t expecting this, you can ignore this email.</p>'
            .$pixel;

        $html = \App\Services\EmailTemplate::wrap(null, $body, [
            'eyebrow'   => 'WELCOME TO KIDDIETRAC',
            'title'     => $data['name'],
            'subtitle'  => 'Set your password to get started',
            'preheader' => 'Set your password and sign in to '.$data['name'].' on Kiddietrac.',
        ]);

        try {
            Mail::html($html, function ($m) use ($data) {
                $m->to($data['admin_email'], $data['admin_first_name'].' '.$data['admin_last_name'])
                  ->from('noreply@kiddietrac.com', 'KiddieTrac')
                  ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
                  ->subject('Welcome to Kiddietrac — set your password');
                $m->getHeaders()->addTextHeader('X-KT-Logged', '1');
                // Exempt from the not-onboarded gate — an invite must reach a user
                // who hasn't accepted yet.
                $m->getHeaders()->addTextHeader('X-KT-Invite', '1');
                $m->getHeaders()->addTextHeader('List-Unsubscribe', '<mailto:support@kiddietrac.com>');
            });
            if (Schema::hasTable('email_logs')) {
                DB::table('email_logs')->insert([
                    'to_email' => $data['admin_email'], 'to_name' => $data['admin_first_name'].' '.$data['admin_last_name'],
                    'from_email' => 'noreply@kiddietrac.com', 'subject' => 'Welcome to Kiddietrac — set your password',
                    'mailer' => config('mail.default'), 'status' => 'sent', 'tracking_token' => $trackToken,
                    'opens' => 0, 'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Agency admin invite failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * GET /api/v1/e/o/{token} — 1x1 open-tracking pixel. Public; records the
     * first open + a running open count on the matching email_logs row.
     */
    public function trackOpen(Request $request, string $token)
    {
        try {
            if (Schema::hasTable('email_logs')) {
                $row = DB::table('email_logs')->where('tracking_token', $token)->first();
                if ($row) {
                    DB::table('email_logs')->where('id', $row->id)->update([
                        'opens'     => (int) ($row->opens ?? 0) + 1,
                        'opened_at' => $row->opened_at ?? now(),
                    ]);
                }
            }
        } catch (\Throwable $e) { /* tracking is best-effort */ }
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
        return response($gif, 200)
            ->header('Content-Type', 'image/gif')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * POST /api/v1/platform/agencies/{agency}/resend-invite
     * Re-mint a set-password token for the agency's first admin and resend the
     * invite email. Always returns the link so the admin can share it manually
     * if delivery is flaky (sendmail/SPF).
     */
    public function resendInvite(Request $request, int $agencyId): JsonResponse
    {
        $agency = DB::table('agencies')->where('id', $agencyId)->whereNull('deleted_at')->first();
        if (! $agency) return response()->json(['message' => 'Not found'], 404);

        $userId = DB::table('role_assignments')
            ->where('agency_id', $agencyId)->where('role', 'agency_admin')->where('active', 1)
            ->orderBy('id')->value('user_id');
        if (! $userId) return response()->json(['message' => 'This agency has no agency admin to invite.'], 422);
        $user = DB::table('users')->where('id', $userId)->first();
        if (! $user) return response()->json(['message' => 'Admin user not found.'], 422);

        $token = bin2hex(random_bytes(32));
        DB::table('password_resets')->insert([
            'email' => $user->email, 'token' => hash('sha256', $token), // hashed at rest
            'expires_at' => now()->addDays(7), 'created_at' => now(),
        ]);
        $link = 'https://app.kiddietrac.com/set-password.html?token='.$token;
        $this->sendAdminInvite([
            'admin_first_name' => $user->first_name, 'admin_last_name' => $user->last_name,
            'admin_email' => $user->email, 'name' => $agency->name,
        ], $link);

        try {
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id ?? null, 'action' => 'agency.invite_resent',
                'entity_type' => 'agency', 'entity_id' => $agencyId,
                'payload' => json_encode(['to' => $user->email]), 'ip_address' => $request->ip(), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* best-effort */ }

        return response()->json([
            'ok' => true, 'email' => $user->email, 'invite_link' => $link,
            'message' => 'Invite re-sent to '.$user->email,
        ]);
    }

    /**
     * GET /api/v1/platform/email-logs — every outbound email (audit).
     */
    public function emailLogs(Request $request): JsonResponse
    {
        if (! Schema::hasTable('email_logs')) return response()->json(['logs' => []]);
        // Never ship the (potentially huge) stored HTML body in the LIST — expose a
        // has_body flag; the actual preview is fetched per-row via emailPreview().
        $cols = ['id', 'to_email', 'to_name', 'from_email', 'subject', 'mailer', 'status', 'error', 'created_at', 'opened_at', 'opens'];
        // STRICT per-tenant isolation: when the platform admin has switched INTO a
        // specific agency (X-Active-Agency-Id is a numeric id), show ONLY that
        // agency's emails. 'all'/unset = the platform-wide super-admin view. Previously
        // this returned every agency's emails regardless of the active agency (leak).
        $q = DB::table('email_logs')->orderByDesc('id');
        $active = $request->header('X-Active-Agency-Id');
        if (Schema::hasColumn('email_logs', 'agency_id') && $active !== null && $active !== '' && strtolower((string) $active) !== 'all' && ctype_digit((string) $active)) {
            $q->where('agency_id', (int) $active);
        }
        $logs = $q->limit(300)->get($cols);
        if (Schema::hasColumn('email_logs', 'body_html') && $logs->isNotEmpty()) {
            $withBody = DB::table('email_logs')->whereIn('id', $logs->pluck('id')->all())
                ->whereNotNull('body_html')->where('body_html', '!=', '')->pluck('id')->flip();
            $logs = $logs->map(function ($r) use ($withBody) { $r->has_body = isset($withBody[$r->id]); return $r; });
        } else {
            $logs = $logs->map(function ($r) { $r->has_body = false; return $r; });
        }
        return response()->json(['logs' => $logs->values()]);
    }

    /** GET /platform/email-logs/{id}/preview — the actual sent HTML for one email. */
    public function emailPreview(Request $request, int $id): JsonResponse
    {
        if (! Schema::hasColumn('email_logs', 'body_html')) {
            return response()->json(['message' => 'Email previews are not available.'], 404);
        }
        $row = DB::table('email_logs')->where('id', $id)
            ->first(['id', 'subject', 'to_email', 'to_name', 'from_email', 'status', 'created_at', 'body_html']);
        if (! $row) return response()->json(['message' => 'Not found'], 404);
        return response()->json([
            'id'         => $row->id,
            'subject'    => $row->subject,
            'to_email'   => $row->to_email,
            'to_name'    => $row->to_name,
            'from_email' => $row->from_email,
            'status'     => $row->status,
            'created_at' => $row->created_at,
            'html'       => $row->body_html ?: null,
        ]);
    }

    /**
     * Resend a previously-sent email (from the email log) to its recipient.
     * Uses the stored HTML verbatim; bypasses the per-agency suppression gate since
     * a platform admin is deliberately re-sending an operational message.
     */
    public function emailResend(Request $request, int $id): JsonResponse
    {
        if (! Schema::hasColumn('email_logs', 'body_html')) {
            return response()->json(['message' => 'Resend is not available for this system.'], 400);
        }
        $row = DB::table('email_logs')->where('id', $id)->first();
        if (! $row) return response()->json(['message' => 'Not found'], 404);
        if (empty($row->body_html)) {
            return response()->json(['message' => 'No stored content to resend for this message.'], 422);
        }
        $to = trim((string) ($request->input('to_email') ?: $row->to_email));
        if ($to === '') return response()->json(['message' => 'No recipient email on file.'], 422);

        try {
            Mail::html($row->body_html, function ($m) use ($to, $row) {
                $m->to($to, $row->to_name ?: null)->subject((string) ($row->subject ?: 'Message from KiddieTrac'));
                if (! empty($row->from_email)) { try { $m->from($row->from_email); } catch (\Throwable $e) {} }
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not resend: ' . $e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'message' => 'Resent to ' . $to]);
    }

    /**
     * GET /platform/crash-reports — recent SERVER errors (HTTP 5xx) across every
     * agency: who hit it, in which agency, what endpoint, and the request input.
     * Sourced from the write-audit middleware, which stamps failing requests
     * "<method>:<path> [fail]" with a payload {method,path,status,input}.
     */
    public function crashReports(Request $request): JsonResponse
    {
        if (! Schema::hasTable('audit_logs')) return response()->json(['reports' => [], 'count_24h' => 0]);
        $base = DB::table('audit_logs')->where('action', 'like', '%[fail]%')->where('payload', 'like', '%"status":5%');
        $rows = (clone $base)
            ->leftJoin('users as u', 'u.id', '=', 'audit_logs.user_id')
            ->leftJoin('agencies as ag', 'ag.id', '=', 'audit_logs.agency_id')
            ->orderByDesc('audit_logs.id')->limit(100)
            ->get([
                'audit_logs.action', 'audit_logs.payload', 'audit_logs.created_at', 'audit_logs.ip_address',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as who"),
                'u.email as who_email', 'ag.name as agency',
            ]);
        $reports = $rows->map(function ($r) {
            $p = json_decode((string) $r->payload, true) ?: [];
            return [
                'at'     => $r->created_at,
                'who'    => trim((string) $r->who) ?: ($r->who_email ?: 'Unknown'),
                'agency' => $r->agency,
                'method' => $p['method'] ?? null,
                'path'   => $p['path'] ?? preg_replace('/\s*\[fail\]$/', '', (string) $r->action),
                'status' => $p['status'] ?? null,
                'input'  => isset($p['input']) ? mb_substr(json_encode($p['input']), 0, 400) : null,
                'ip'     => $r->ip_address,
            ];
        })->values();
        $count24 = (clone $base)->where('audit_logs.created_at', '>=', now()->subDay())->count();
        return response()->json(['reports' => $reports, 'count_24h' => $count24]);
    }

    /**
     * v22p24 — PATCH /api/v1/platform/agencies/{agency}
     * Update tenant settings including white-label branding.
     */
    public function updateAgency(Request $request, int $agencyId): JsonResponse
    {
        $exists = DB::table('agencies')->where('id', $agencyId)->whereNull('deleted_at')->exists();
        if (! $exists) return response()->json(['message' => 'Not found'], 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:180'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:180'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            // Explicit agency owner (super-admin editable) — corrects the derived
            // "earliest admin" owner when it's wrong. Stored in the settings JSON.
            'owner_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'owner_email' => ['sometimes', 'nullable', 'email', 'max:180'],
            // The agency's timezone drives every displayed time and every daily
            // bucket (care logs, reports, the end-of-day parent summary). It was
            // settable at creation but NOT here, so editing it silently did nothing.
            'timezone' => ['sometimes', 'nullable', 'timezone'],
            // Master switch: when false, NOTHING goes out to this agency — no
            // email, no SMS, no push, no in-app notification. Stored in the
            // settings JSON and read by App\Support\Suppression.
            'notifications_enabled' => ['sometimes', 'boolean'],
            'plan_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'plan_amount_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'white_label_enabled' => ['sometimes', 'boolean'],
            'brand_logo_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'brand_primary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'brand_support_email' => ['sometimes', 'nullable', 'email', 'max:160'],
            'brand_bank_info' => ['sometimes', 'nullable', 'string'],
            // v22p92: agency address + residence country + default language
            // (so Edit shows/saves every field the create wizard collected).
            'brand_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'default_locale' => ['sometimes', 'nullable', 'string', 'max:8'],
            // v22p36: per-agency email settings
            'email_smtp_host' => ['sometimes', 'nullable', 'string', 'max:160'],
            'email_smtp_port' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],
            'email_smtp_user' => ['sometimes', 'nullable', 'string', 'max:160'],
            'email_smtp_pass' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email_smtp_encryption' => ['sometimes', 'nullable', 'in:tls,ssl,none'],
            'email_from_address' => ['sometimes', 'nullable', 'email', 'max:160'],
            'email_from_name' => ['sometimes', 'nullable', 'string', 'max:160'],
        ]);

        // The master switch lives in the settings JSON (no schema change), and the
        // suppression decision is cached — clear it so the toggle takes effect at
        // once rather than up to two minutes later.
        if (array_key_exists('notifications_enabled', $data)) {
            $row = DB::table('agencies')->where('id', $agencyId)->first(['settings']);
            $settings = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
            $settings['notifications_enabled'] = (bool) $data['notifications_enabled'];
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($settings)]);
            \Illuminate\Support\Facades\Cache::forget('kt.agency_notifications:' . $agencyId);
            unset($data['notifications_enabled']);
        }

        // Explicit owner → settings.owner (name/email). Empty clears it (falls back
        // to the derived owner, which now excludes super admins).
        if (array_key_exists('owner_name', $data) || array_key_exists('owner_email', $data)) {
            $row = DB::table('agencies')->where('id', $agencyId)->first(['settings']);
            $settings = ($row && $row->settings) ? (json_decode($row->settings, true) ?: []) : [];
            $owner = is_array($settings['owner'] ?? null) ? $settings['owner'] : [];
            if (array_key_exists('owner_name', $data))  $owner['name']  = trim((string) $data['owner_name']) ?: null;
            if (array_key_exists('owner_email', $data)) $owner['email'] = trim((string) $data['owner_email']) ?: null;
            $settings['owner'] = $owner;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($settings)]);
            unset($data['owner_name'], $data['owner_email']);
        }


        // white_label_enabled maps to powered_by_visible (inverse).
        if (array_key_exists('white_label_enabled', $data)) {
            $data['powered_by_visible'] = $data['white_label_enabled'] ? 0 : 1;
            unset($data['white_label_enabled']);
        }
        // Treat an empty/missing email_smtp_pass as 'keep existing' so the admin
        // doesn't have to retype the mailbox password every time they edit.
        if (array_key_exists('email_smtp_pass', $data) && ($data['email_smtp_pass'] === null || $data['email_smtp_pass'] === '')) {
            unset($data['email_smtp_pass']);
        }

        // v22p92: brand_address (no column) + country/locale → settings JSON +
        // locale/currency columns. Extract so the raw column update stays valid.
        if (array_key_exists('brand_address', $data) || array_key_exists('country', $data) || array_key_exists('default_locale', $data)) {
            $countryMap = [
                'CA' => 'CAD', 'US' => 'USD', 'GB' => 'GBP', 'AU' => 'AUD', 'NZ' => 'NZD', 'IE' => 'EUR',
            ];
            $row = DB::table('agencies')->where('id', $agencyId)->first();
            $settings = json_decode($row->settings ?? '{}', true) ?: [];
            if (array_key_exists('brand_address', $data)) {
                $settings['brand_address'] = $data['brand_address'] !== '' ? $data['brand_address'] : null;
            }
            if (array_key_exists('country', $data) && $data['country']) {
                $settings['country'] = $data['country'];
                if (isset($countryMap[$data['country']])) $data['plan_currency'] = $countryMap[$data['country']];
            }
            if (array_key_exists('default_locale', $data) && $data['default_locale']) {
                $settings['default_locale'] = $data['default_locale'];
                $data['locale'] = $data['default_locale'];
            }
            $data['settings'] = json_encode($settings);
            unset($data['brand_address'], $data['country'], $data['default_locale']);
        }

        $data['updated_at'] = now();
        DB::table('agencies')->where('id', $agencyId)->update($data);

        // Audit the change (best-effort).
        try {
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id ?? null, 'action' => 'agency.updated',
                'entity_type' => 'agency', 'entity_id' => $agencyId,
                'payload' => json_encode(['changes' => array_keys($data)]),
                'ip_address' => $request->ip(), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* audit best-effort */ }

        return response()->json([
            'agency' => DB::table('agencies')->where('id', $agencyId)->first(),
            'message' => 'Agency updated.',
        ]);
    }

    /**
     * POST /api/v1/platform/agencies/{agency}/suspend
     */
    public function suspendAgency(Request $request, int $agencyId): JsonResponse
    {
        $exists = DB::table('agencies')->where('id', $agencyId)->whereNull('deleted_at')->exists();
        if (! $exists) return response()->json(['message' => 'Not found'], 404);
        DB::table('agencies')->where('id', $agencyId)->update([
            'billing_status' => 'suspended',
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Agency suspended']);
    }

    /**
     * POST /api/v1/platform/agencies/{agency}/resume
     */
    public function resumeAgency(Request $request, int $agencyId): JsonResponse
    {
        $exists = DB::table('agencies')->where('id', $agencyId)->whereNull('deleted_at')->exists();
        if (! $exists) return response()->json(['message' => 'Not found'], 404);
        DB::table('agencies')->where('id', $agencyId)->update([
            'billing_status' => 'active',
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Agency resumed']);
    }
}
