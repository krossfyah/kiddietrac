<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * LICENSING (2026-10-24, Anthony: "the plans and auditing the features based on the plans when i
 * sell the service needs to have a licensing system with expiry/renewal and tied back to the
 * billing as well ... including addon plans. Also add part#s to each plan and addon").
 *
 * Anthony's choices (question prompt): part numbers KT-PLN / KT-ADD / KT-SVC / KT-DSC-0001;
 * a licence's term is its billing period and it renews automatically; an unrenewed licence gets
 * 14 days' grace, then the paid features switch off (records stay readable, nothing deleted);
 * agency admins see their own licence read-only.
 *
 * How it fits together
 *  - The catalogue (sales_products) gives every plan / add-on / service / discount a part number.
 *    A plan carries a level (starter/growth/enterprise); an add-on the features it switches on.
 *  - agency_licences: one row per agency per plan or add-on: price, term, expiry, auto-renew,
 *    status active → grace → expired (or cancelled).
 *  - An agency with any licence is "licence-managed": FeatureFlagController::effectiveFor then
 *    follows the licensed plan level (each feature's plan_min) plus its add-ons, with the
 *    superadmin's explicit per-agency On/Off still winning. Agencies with no licence keep the
 *    old behaviour exactly (everything on unless switched off).
 *  - Billing: with recurring billing switched on (billing_status active + a next invoice date),
 *    PlatformInvoiceRaiser bills a licence-managed agency from its licences coming due — one
 *    itemised draft invoice with part numbers — and renews each licence it bills. With billing
 *    off (set up, not billed yet), an auto-renew licence simply rolls on, so nothing locks.
 *  - kt:licences (daily): rolls unbilled renewals, starts grace / expiry, reminders, an unpaid
 *    renewal invoice past due starts grace, payment restores it; then re-syncs the agency.
 */
class Licences
{
    /* 'addon' is a plan_min no plan reaches: the feature comes only with its add-on (SMS & voice,
       white-label — 2026-10-24, Anthony: "always an add-on"). */
    public const ORDER = ['free' => 0, 'starter' => 1, 'growth' => 2, 'enterprise' => 3, 'addon' => 99];
    public const PREFIX = ['plan' => 'KT-PLN', 'addon' => 'KT-ADD', 'service' => 'KT-SVC', 'discount' => 'KT-DSC'];
    public const LIVE = ['active', 'grace'];
    public const GRACE_DAYS = 14;

    /** The next free part number for a kind, e.g. KT-ADD-0004. */
    public static function nextPart(string $kind): string
    {
        $prefix = self::PREFIX[$kind] ?? 'KT-PLN';
        $nums = DB::table('sales_products')->where('part_number', 'like', $prefix . '-%')->pluck('part_number')
            ->map(fn ($p) => (int) substr((string) $p, strlen($prefix) + 1))->all();
        return $prefix . '-' . str_pad((string) ((($nums ? max($nums) : 0)) + 1), 4, '0', STR_PAD_LEFT);
    }

    public static function ready(): bool
    {
        static $ok = null;
        return $ok ??= Schema::hasTable('agency_licences');
    }

    public static function managed(?int $agencyId): bool
    {
        return $agencyId && self::ready() && DB::table('agency_licences')->where('agency_id', $agencyId)->exists();
    }

    public static function live(int $agencyId)
    {
        return DB::table('agency_licences')->where('agency_id', $agencyId)->whereIn('status', self::LIVE)->get();
    }

    /**
     * What each plan allows (2026-10-25, Anthony: "starter should be 15 and so on - match whats on
     * our marketing material"). Children from the website's plan cards (15 / 60 / unlimited);
     * centres from the plan descriptions (single centre / up to 3 / multi-centre). null = unlimited.
     */
    public const LIMITS = [
        'free' => ['centres' => 1, 'children' => 15],
        'starter' => ['centres' => 1, 'children' => 15],
        'growth' => ['centres' => 3, 'children' => 60],
        'enterprise' => ['centres' => null, 'children' => null],
    ];

    /**
     * No licence to operate (2026-10-25: "no agency should have no license to operate with
     * kiddietrac"): a licence-managed agency with no live plan licence (its grace is over). Its
     * people see only the renewal screen; a superadmin can always get in.
     */
    public static function lapsed(?int $agencyId): bool
    {
        if (! $agencyId || ! self::managed($agencyId)) return false;
        return ! DB::table('agency_licences')->where('agency_id', $agencyId)->where('kind', 'plan')->whereIn('status', self::LIVE)->exists();
    }

    /**
     * Where a licence's next term ends: its contract term in months when it has one (a 24- or
     * 36-month contract), else one billing interval (2026-10-25).
     */
    public static function nextTerm(object $l, string $from): string
    {
        /* CALENDAR MONTHS (2026-11-04, Anthony: "make all invoices raise at the beginning of the month and due at the
           end of the month - (standard) add option to change to annual, semi-annual or extend terms if required for
           individual accounts but all by end of its a month"). Every term starts on the 1st: a term that starts
           mid-month runs to the end of that month (its first invoice is prorated, invoiceLines) and the next one
           starts on the 1st. From the 1st, a term is its interval or its own number of months, so it always ends
           on a month end. */
        $f = Carbon::parse($from)->startOfDay();
        if ($f->day !== 1) return $f->copy()->addMonthNoOverflow()->startOfMonth()->toDateString();
        $m = (int) ($l->term_months ?? 0);
        return $m > 0 ? Carbon::parse($from)->startOfDay()->addMonthsNoOverflow($m)->toDateString() : PlatformBilling::advance($from, (string) $l->interval);
    }

    /** What people call each plan level (the Agency plan is 'enterprise' in code). */
    public const PLAN_NAMES = ['free' => 'Free', 'starter' => 'Starter', 'growth' => 'Growth', 'enterprise' => 'Agency'];

    /**
     * The plan's name for the sidebar foot (2026-10-25): the licensed level when the agency is
     * licence-managed, else its billing plan code, else null (nothing to show).
     */
    public static function isTrial(?int $agencyId): bool
    {
        if (! $agencyId) return false;
        $p = self::planLine($agencyId);
        return $p && (string) ($p->source ?? '') === 'trial';
    }

    public static function planLabel(?int $agencyId): ?string
    {
        if (! $agencyId) return null;
        if (self::managed($agencyId)) return (self::PLAN_NAMES[self::planLevel($agencyId)] ?? 'Free') . ' plan' . (self::isTrial($agencyId) ? ' (trial)' : '');
        $code = (string) DB::table('agencies')->where('id', $agencyId)->value('plan_code');
        if ($code === '') return null;
        return (self::PLAN_NAMES[$code] ?? ucfirst(str_replace('_', ' ', $code))) . ' plan';
    }

    /** The licensed plan level ('free' when no live plan licence). */
    public static function planLevel(int $agencyId): string
    {
        $best = 'free';
        foreach (self::live($agencyId) as $l) {
            if ($l->kind === 'plan' && isset(self::ORDER[$l->plan_code]) && self::ORDER[$l->plan_code] > self::ORDER[$best]) $best = $l->plan_code;
        }
        return $best;
    }

    /** Courtesy enhancement requests a year on the Agency plan (2026-10-24, Anthony's choice). */
    public const ENHANCEMENTS_PER_YEAR = 3;

    /**
     * The agency's enhancement-request allowance for the current calendar year (agency day):
     * eligible only with a live Agency (enterprise) plan licence; every request raised this year counts.
     */
    public static function enhancements(int $agencyId): array
    {
        $year = (int) \App\Support\AgencyTime::todayDate($agencyId ?: null)->year;
        [$from, $to] = \App\Support\AgencyTime::spanRange($agencyId ?: null, $year . '-01-01', $year . '-12-31');
        $eligible = $agencyId > 0 && self::planLevel($agencyId) === 'enterprise';
        $used = $agencyId > 0 ? (int) DB::table('support_tickets')->where('agency_id', $agencyId)->where('request_type', 'enhancement')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)->count() : 0;
        return ['eligible' => $eligible, 'allowance' => self::ENHANCEMENTS_PER_YEAR, 'used' => $used, 'left' => $eligible ? max(0, self::ENHANCEMENTS_PER_YEAR - $used) : 0, 'year' => $year];
    }

    /** Features the live add-ons switch on. */
    public static function addonFeatures(int $agencyId): array
    {
        $keys = [];
        foreach (self::live($agencyId) as $l) {
            if ($l->kind !== 'addon') continue;
            foreach ((array) (json_decode((string) $l->feature_keys, true) ?: []) as $k) $keys[$k] = true;
        }
        return array_keys($keys);
    }

    /**
     * Feature map for a licence-managed agency: the plan level decides each feature, add-ons
     * switch theirs on, and an explicit per-agency On/Off from the superadmin wins.
     */
    public static function effectiveFlags(int $agencyId, array $features, array $stored): array
    {
        $level = self::ORDER[self::planLevel($agencyId)];
        $addons = array_flip(self::addonFeatures($agencyId));
        $out = [];
        foreach ($features as $key => $meta) {
            if (array_key_exists($key, $stored)) { $out[$key] = (bool) $stored[$key]; continue; }
            $min = self::ORDER[$meta['plan_min'] ?? 'free'] ?? 0;
            $on = $level >= $min || isset($addons[$key]);
            if (! $on && in_array($key, self::PAID_THROUGH, true)) {
                $on = self::paidThrough($agencyId, $key, (string) ($meta['plan_min'] ?? 'free')) !== null;
            }
            $out[$key] = $on;
        }
        return $out;
    }

    /* BILLING RUNS TO THE END OF THE PERIOD ALREADY PAID (2026-11-26, Anthony: "billing jobs run to the end of the
       period already paid, then stop (not that night)"). When a plan is replaced by a smaller one, or the licence is
       cancelled, invoicing and autopay stay on - screens, API and the nightly jobs alike, one answer - until the
       licence that brought them reaches its end date. Every other feature still stops straight away. An explicit
       Off from a platform admin is a decision, not a downgrade, so it still wins at once (effectiveFlags above). */
    public const PAID_THROUGH = ['billing', 'autopay'];

    /** The last day (Y-m-d) a no-longer-live licence still pays for this feature, or null when none does today. */
    public static function paidThrough(int $agencyId, string $key, string $planMin): ?string
    {
        $need = self::ORDER[$planMin] ?? 0;
        $best = null;
        foreach (DB::table('agency_licences')->where('agency_id', $agencyId)->whereNotIn('status', self::LIVE)
            ->whereNotNull('expires_at')->get(['kind', 'plan_code', 'feature_keys', 'expires_at']) as $l) {
            $gave = $l->kind === 'plan'
                ? (self::ORDER[(string) $l->plan_code] ?? -1) >= $need
                : in_array($key, (array) (json_decode((string) $l->feature_keys, true) ?: []), true);
            $end = substr((string) $l->expires_at, 0, 10);
            if ($gave && ($best === null || $end > $best)) $best = $end;
        }
        if ($best === null) return null;

        return \App\Support\AgencyTime::todayDate($agencyId)->toDateString() <= $best ? $best : null;
    }

    /**
     * Bring the agency's plan / white-label / texting switches and price into line with its
     * live licences. Only for licence-managed agencies; never touches anyone else.
     */
    public static function sync(int $agencyId): void
    {
        // The bundle first: add-ons always carry the plan's term (2026-10-25).
        try { self::alignBundle($agencyId); } catch (Throwable $e) {}
        if (! self::managed($agencyId)) return;
        try {
            $a = DB::table('agencies')->where('id', $agencyId)->first();
            if (! $a) return;
            $flags = \App\Http\Controllers\Api\FeatureFlagController::effectiveFor($agencyId);
            $live = self::live($agencyId);
            $interval = PlatformBilling::normaliseInterval($a->billing_interval ?? null);
            $monthly = 0;
            foreach ($live as $l) $monthly += PlatformBilling::monthlyCents((int) round((float) $l->qty * (int) $l->unit_price_cents), $l->interval);
            $per = ['weekly' => 12 / 52, 'biweekly' => 12 / 26, 'monthly' => 1, 'quarterly' => 3, 'annual' => 12][$interval] ?? 1;
            $nextDue = DB::table('agency_licences')->where('agency_id', $agencyId)->whereIn('status', self::LIVE)->where('auto_renew', 1)->min('expires_at');
            $upd = [
                'plan_code' => self::planLevel($agencyId),
                /* Licensed is not switched on (2026-10-25, Anthony: "dont turn on white label but keep white
                   label package for ilearn"). No white-label licence forces the KiddieTrac branding back;
                   holding one only allows it - Platform -> Features turns it on. */
                'powered_by_visible' => empty($flags['white_label']) ? 1 : (int) ($a->powered_by_visible ?? 1),
                'sms_enabled' => ! empty($flags['sms']) ? 1 : 0,
                'plan_amount_cents' => (int) round($monthly * $per),
                'updated_at' => now(),
            ];
            // The billing status follows the licence too (derivedStatus, 2026-11-26).
            $st = self::derivedStatus($agencyId, $a);
            if ($st !== null && $st !== (string) $a->billing_status) $upd['billing_status'] = $st;
            // On recurring billing the next invoice date follows the next licence renewal.
            if ($a->next_invoice_at && $nextDue) $upd['next_invoice_at'] = $nextDue;
            DB::table('agencies')->where('id', $agencyId)->update($upd);
        } catch (Throwable $e) {
            Log::warning('Licence sync failed', ['agency' => $agencyId, 'error' => $e->getMessage()]);
        }
    }

    /* BILLING STATUS FOLLOWS THE LICENCE (2026-11-26, Anthony). For a licence-managed agency agencies.billing_status
       is worked out here and written by sync(), never typed in (Edit agency, Features and Billing plans show it
       read-only and their endpoints drop it):
         live plan, paid or complimentary  -> active
         trial plan                        -> trial
         plan in grace                     -> active (renewal invoices keep being raised, billingOn needs 'active')
         no live plan (lapsed / cancelled) -> suspended
       A SUSPEND PRESSED BY HAND WINS: Platform > Suspend sets settings.billing_suspended_manually and it stays
       suspended through every sync until Resume clears the flag and hands the status back to the licence.
       null = not licence-managed (or deleted): the stored value is left alone. */
    public const MANUAL_SUSPEND = 'billing_suspended_manually';

    public static function manuallySuspended(object $a): bool
    {
        $s = json_decode((string) ($a->settings ?? ''), true);
        return is_array($s) && ! empty($s[self::MANUAL_SUSPEND]);
    }

    public static function derivedStatus(int $agencyId, ?object $a = null): ?string
    {
        if (! self::managed($agencyId)) return null;
        $a = $a ?: DB::table('agencies')->where('id', $agencyId)->first();
        if (! $a || ! empty($a->deleted_at)) return null;
        if (self::manuallySuspended($a)) return 'suspended';
        $p = DB::table('agency_licences')->where('agency_id', $agencyId)->where('kind', 'plan')->whereIn('status', self::LIVE)
            ->orderByRaw("FIELD(status,'active','grace')")->first();
        if (! $p) return 'suspended';
        if ((string) ($p->source ?? '') === 'trial') return 'trial';
        return 'active';
    }

    public static function billingOn(object $a): bool
    {
        return (string) $a->billing_status === 'active' && ! empty($a->next_invoice_at)
            && PlatformBilling::recurring()['enabled'] && PlatformBilling::recurringSkip($a, AgencyTime::todayDate()->toDateString()) === null;
    }

    /* ── ONE LICENCE PER AGENCY (2026-10-25) ──────────────────────────────────────────
       Anthony: "you cannot just renew one feature and not the rest - it needs to be all or remove/add per
       agency". The plan line carries the agency's term (end date, billing interval, contract term,
       auto-renew, status); every add-on follows it. Add-ons are added or removed one at a time; renewing,
       changing the term or cancelling is always the whole licence. */

    /** The agency's plan line (live first), which carries the licence term. */
    public static function planLine(int $agencyId): ?object
    {
        return DB::table('agency_licences')->where('agency_id', $agencyId)->where('kind', 'plan')->where('status', '!=', 'cancelled')
            ->orderByRaw("FIELD(status,'active','grace','expired')")->orderByDesc('expires_at')->first();
    }

    /** Every add-on onto the plan's term. Returns how many lines changed. Runs on every sync and nightly. */
    public static function alignBundle(int $agencyId): int
    {
        $plan = self::planLine($agencyId);
        if (! $plan) return 0;
        $n = 0;
        foreach (DB::table('agency_licences')->where('agency_id', $agencyId)->where('kind', 'addon')->where('status', '!=', 'cancelled')->get() as $x) {
            $want = ['expires_at' => $plan->expires_at, 'interval' => $plan->interval, 'term_months' => $plan->term_months, 'auto_renew' => (int) $plan->auto_renew,
                'status' => $plan->status, 'grace_until' => $plan->grace_until];
            $diff = array_filter($want, fn ($v, $k) => (string) ($x->$k ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH);
            if ($diff) {
                DB::table('agency_licences')->where('id', $x->id)->update($diff + ['updated_at' => now()]);
                $n++;
            }
        }
        return $n;
    }

    /** Renew the whole licence one term: every live line to the same new end date. Returns that date. */
    public static function renewBundle(int $agencyId): ?string
    {
        $plan = self::planLine($agencyId);
        abort_unless($plan, 422, 'This agency has no plan to renew. Add a plan first.');
        $today = AgencyTime::todayDate()->toDateString();
        $base = $plan->expires_at && (string) $plan->expires_at > $today ? (string) $plan->expires_at : $today;
        $next = self::nextTerm($plan, $base);
        DB::table('agency_licences')->where('agency_id', $agencyId)->where('status', '!=', 'cancelled')
            ->update(['expires_at' => $next, 'status' => 'active', 'grace_until' => null, 'last_renewed_at' => now(), 'updated_at' => now()]);
        self::sync($agencyId);
        return $next;
    }

    /** Create a licence from a catalogue product. */
    public static function grant(int $agencyId, int $productId, array $o = []): int
    {
        $p = DB::table('sales_products')->where('id', $productId)->first();
        abort_unless($p && in_array($p->kind, ['plan', 'addon'], true), 422, 'Only a plan or an add-on can be licensed.');
        $interval = $o['interval'] ?? ($p->unit === 'year' ? 'annual' : 'monthly');
        $start = Carbon::parse($o['starts_at'] ?? AgencyTime::todayDate()->toDateString())->toDateString();
        /* THE BUNDLE: an add-on joins the plan's term; a new plan replaces the current one and keeps its term. */
        $current = self::planLine($agencyId);
        if ($p->kind === 'addon') {
            abort_unless($current, 422, 'Add a plan first: an add-on joins the agency\'s plan and renews with it.');
            abort_if(DB::table('agency_licences')->where('agency_id', $agencyId)->where('product_id', $p->id)->where('status', '!=', 'cancelled')->exists(), 422, $p->name . ' is already on this agency\'s licence.');
            $interval = $current->interval;
            $o['term_months'] = $current->term_months;
            $o['expires_at'] = $current->expires_at;
            $o['auto_renew'] = (bool) $current->auto_renew;
        } elseif ($current) {
            DB::table('agency_licences')->where('id', $current->id)->update(['status' => 'cancelled', 'notes' => trim((string) $current->notes . ' [replaced by ' . $p->name . ' ' . AgencyTime::todayDate()->toDateString() . ']'), 'updated_at' => now()]);
            $interval = $o['interval'] ?? $current->interval;
            $o['term_months'] = $o['term_months'] ?? $current->term_months;
            $o['expires_at'] = $o['expires_at'] ?? $current->expires_at;
            if (! array_key_exists('auto_renew', $o)) $o['auto_renew'] = (bool) $current->auto_renew;
        }
        $id = DB::table('agency_licences')->insertGetId([
            'agency_id' => $agencyId, 'product_id' => $p->id, 'part_number' => $p->part_number, 'name' => $p->name,
            'kind' => $p->kind, 'plan_code' => $p->kind === 'plan' ? $p->plan_code : null, 'feature_keys' => $p->kind === 'addon' ? $p->feature_keys : null,
            'qty' => $o['qty'] ?? 1,
            'unit_price_cents' => (int) round(((float) ($o['unit_price'] ?? $p->price)) * 100),
            'currency' => $o['currency'] ?? (DB::table('agencies')->where('id', $agencyId)->value('plan_currency') ?: 'CAD'),
            'interval' => $interval, 'term_months' => isset($o['term_months']) ? (int) $o['term_months'] : null, 'starts_at' => $start,
            'expires_at' => $o['expires_at'] ?? self::nextTerm((object) ['term_months' => $o['term_months'] ?? null, 'interval' => $interval], $start),
            'auto_renew' => array_key_exists('auto_renew', $o) ? (bool) $o['auto_renew'] : true,
            'status' => 'active', 'source' => $o['source'] ?? 'manual', 'quote_id' => $o['quote_id'] ?? null,
            'notes' => $o['notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        self::sync($agencyId);
        return (int) $id;
    }

    /* ── billing: what the Raiser needs ───────────────────────────────── */

    /** Live auto-renew licences whose next term has started (the 1st of the month), for one agency. */
    public static function dueForInvoice(int $agencyId, string $todayStr): array
    {
        $soon = $todayStr;   // raised the day the term starts (2026-11-04: invoices on the 1st, due at month end)
        return DB::table('agency_licences')->where('agency_id', $agencyId)->whereIn('status', self::LIVE)
            ->where('auto_renew', 1)->whereNotNull('expires_at')->where('expires_at', '<=', $soon)->orderBy('expires_at')->get()->all();
    }

    /* ── COMPLIMENTARY LICENCES (2026-10-25) ──────────────────────────────────────────
       Anthony: "bill the amounts but add a new line item with new part# as complimentary to zero it all out but
       recognize the potential revenue". A complimentary line (source 'comp', or a $0 price on a priced product) is
       invoiced at its catalogue price for the term, and one KT-CMP-0001 line takes the same amount off. */
    public const COMP_PART = 'KT-CMP-0001';

    public static function isComp(object $l): bool
    {
        if ((string) ($l->source ?? '') === 'trial') return false;   // a trial is not billed at all
        return (string) ($l->source ?? '') === 'comp' || ((int) $l->unit_price_cents === 0 && self::listCents($l) > 0);
    }

    /** The end date of a trial that starts on $start and lasts $length $unit (days | weeks | months | years). */
    public static function trialEnd(string $start, int $length, string $unit): string
    {
        $d = Carbon::parse($start);
        $length = max(1, $length);
        $d = match ($unit) { 'days' => $d->addDays($length), 'weeks' => $d->addWeeks($length), 'years' => $d->addYearsNoOverflow($length), default => $d->addMonthsNoOverflow($length) };
        return $d->toDateString();
    }

    /** How many months one term of a licence is: its own term, else its interval. */
    public static function termMonths(object $l): int
    {
        $m = (int) ($l->term_months ?? 0);
        return $m > 0 ? $m : (['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12][$l->interval] ?? 1);
    }

    /** The catalogue price of one unit of a licence for ONE term of its billing interval. */
    public static function listCents(object $l, ?int $months = null): int
    {
        $p = $l->product_id ? DB::table('sales_products')->where('id', $l->product_id)->first(['price', 'unit']) : null;
        if (! $p || (float) $p->price <= 0) return 0;
        $months = $months ?? self::termMonths($l);
        $perMonth = $p->unit === 'year' ? (float) $p->price / 12 : (float) $p->price;
        return (int) round($perMonth * $months * 100);
    }

    /**
     * Complimentary lines at list price plus the KT-CMP-0001 line that zeroes them. $lines are invoice lines that
     * carry 'licence_id'; returns the lines with the comp line appended (or unchanged when nothing is comp).
     */
    public static function withComp(array $lines, string $currency = 'CAD', ?int $months = null): array
    {
        $comp = 0; $names = [];
        foreach ($lines as $i => $ln) {
            if (empty($ln['licence_id'])) continue;
            $l = DB::table('agency_licences')->find($ln['licence_id']);
            if (! $l || ! self::isComp($l)) continue;
            $unit = self::listCents($l, $months);
            if ($unit <= 0) continue;
            $qty = $months !== null ? 1 : (float) $ln['qty'];
            $amt = (int) round((float) $l->qty * $unit * (float) ($ln['part'] ?? 1));   // a prorated first term: its share
            $lines[$i]['unit_cents'] = (int) round($amt / max(0.01, (float) $ln['qty']));
            $lines[$i]['amount_cents'] = $amt;
            $lines[$i]['description'] = $ln['description'] . ' (list price)';
            $comp += $amt; $names[] = $l->name;
        }
        if ($comp > 0) {
            $first = $lines[0];
            $lines[] = ['licence_id' => null, 'part_number' => self::COMP_PART, 'kind' => 'comp',
                'description' => 'Complimentary licence (100%): ' . implode(', ', $names) . ' · value ' . PlatformBilling::money($comp, $currency) . ' not charged',
                'qty' => 1, 'unit_cents' => -$comp, 'amount_cents' => -$comp, 'period_start' => $first['period_start'] ?? null, 'period_end' => $first['period_end'] ?? null];
        }
        return $lines;
    }

    /** Invoice lines for those licences, and the period they cover. */
    public static function invoiceLines(array $licences): array
    {
        $lines = []; $start = null; $end = null; $sub = 0;
        foreach ($licences as $l) {
            $from = (string) $l->expires_at;
            $to = Carbon::parse(self::nextTerm($l, $from))->subDay()->toDateString();
            /* PRORATED FIRST TERM (2026-11-04): a term from mid-month runs to the month end (nextTerm) and is billed for
               those days: the term's price / its months / the days in that month, times the days left. */
            $f = Carbon::parse($from); $part = 1.0; $note = '';
            if ($f->day !== 1) {
                $days = $f->daysInMonth - $f->day + 1;
                $part = $days / ($f->daysInMonth * self::termMonths($l));
                $note = ' · prorated ' . $days . ' of ' . $f->daysInMonth . ' days';
            }
            $unit = (int) round((int) $l->unit_price_cents * $part);
            $amount = (int) round((float) $l->qty * $unit);
            $lines[] = ['licence_id' => (int) $l->id, 'part_number' => $l->part_number, 'description' => $l->name . ' · ' . ($part < 1 ? 'to ' . Carbon::parse($to)->format('M j') . $note : PlatformBilling::intervalLabel($l->interval) . ((int) ($l->term_months ?? 0) > 0 ? ' · ' . (int) $l->term_months . '-month term' : '')),
                'qty' => (float) $l->qty, 'unit_cents' => $unit, 'amount_cents' => $amount, 'period_start' => $from, 'period_end' => $to, 'part' => $part];
            $start = $start === null || $from < $start ? $from : $start;
            $end = $end === null || $to > $end ? $to : $end;
        }
        // Complimentary: list price on each line, one KT-CMP-0001 line taking it off. The subtotal is the net.
        $lines = self::withComp($lines, (string) ($licences[0]->currency ?? 'CAD'));
        foreach ($lines as $ln) $sub += (int) $ln['amount_cents'];
        return ['lines' => $lines, 'subtotal_cents' => $sub, 'period_start' => $start, 'period_end' => $end];
    }

    /** After the invoice is written: each billed licence renews for one more term. */
    public static function renewBilled(int $invoiceId, array $lines): void
    {
        foreach ($lines as $ln) {
            DB::table('platform_invoice_lines')->insert(array_merge(array_intersect_key($ln, array_flip(['licence_id', 'part_number', 'kind', 'description', 'qty', 'unit_cents', 'amount_cents', 'period_start', 'period_end'])),
                ['invoice_id' => $invoiceId, 'created_at' => now()]));
            if (empty($ln['licence_id'])) continue;   // the complimentary line belongs to no licence
            $l = DB::table('agency_licences')->where('id', $ln['licence_id'])->first();
            if (! $l) continue;
            DB::table('agency_licences')->where('id', $l->id)->update([
                'expires_at' => self::nextTerm($l, (string) $l->expires_at),
                'last_invoice_id' => $invoiceId, 'last_renewed_at' => now(), 'status' => 'active', 'grace_until' => null, 'updated_at' => now(),
            ]);
        }
    }

    /* ── the daily run ────────────────────────────────────────────────── */

    public static function daily(bool $dry = false): array
    {
        if (! self::ready()) return [];
        $out = [];
        $today = AgencyTime::todayDate()->toDateString();
        $touched = [];

        /* A $0 INVOICE PAYS ITSELF ON ITS DUE DATE (2026-10-25, complimentary licences billed at $0):
           issued and settled with "No charge", so it is never unpaid and never starts a grace period. */
        foreach (DB::table('platform_invoices')->where('amount_cents', 0)->whereIn('status', ['draft', 'issued', 'sent'])->whereNotNull('due_at')->where('due_at', '<=', $today)->get() as $zi) {
            $out[] = "invoice {$zi->number}: \$0 due {$zi->due_at} — settled (no charge)";
            if (! $dry) {
                DB::table('platform_invoices')->where('id', $zi->id)->update(['status' => 'paid', 'issued_at' => $zi->issued_at ?: now(), 'paid_at' => now(),
                    'payment_reference' => 'No charge (complimentary)', 'updated_at' => now()]);
                \App\Support\Audit::write(['user_id' => null, 'agency_id' => $zi->agency_id, 'action' => 'platform_invoice.zero_settled', 'entity_type' => 'platform_invoice', 'entity_id' => $zi->id,
                    'payload' => json_encode(['summary' => 'Invoice ' . $zi->number . ' for $0 settled on its due date (no charge).']), 'created_at' => now()]);
            }
        }

        // GUARD: any add-on that drifted off its plan's term is put back before anything is decided (2026-10-25).
        foreach (DB::table('agency_licences')->where('kind', 'plan')->where('status', '!=', 'cancelled')->distinct()->pluck('agency_id') as $aid) {
            $fixed = $dry ? 0 : self::alignBundle((int) $aid);
            if ($fixed) $out[] = "agency {$aid}: {$fixed} add-on(s) realigned to the plan's term";
        }

        foreach (DB::table('agency_licences')->whereIn('status', self::LIVE)->orderBy('agency_id')->get() as $l) {
            $a = DB::table('agencies')->where('id', $l->agency_id)->first();
            if (! $a) continue;
            $exp = $l->expires_at ? (string) $l->expires_at : null;

            // 1) Billing on: draft renewal invoices for what is coming due (never emailed here).
            if ($l->auto_renew && self::billingOn($a) && $exp && $exp <= $today && ! isset($touched['inv' . $a->id])) {
                $touched['inv' . $a->id] = 1; $touched[(int) $a->id] = 1;
                /* 2026-11-04: this used to draft the renewal 10 minutes before platform:billing-run, which then found it
                   "already billed" and never emailed it. With automatic raising on, the billing run raises AND sends it. */
                if (in_array(strtolower((string) DB::table('platform_settings')->where('key', 'billing.auto_raise')->value('value')), ['1', 'true', 'yes', 'on'], true)) {
                    $out[] = "agency {$a->id}: licences renew today - raised and emailed by platform:billing-run";
                    continue;
                }
                $out[] = "agency {$a->id}: renewal invoice for licences due";
                if (! $dry) {
                    // Free lines are invoiced too now, at $0 (2026-10-25): one itemised record per term.
                    try {
                        $r = app(\App\Services\PlatformInvoiceRaiser::class);
                        $r->commit($r->billable($r->plan($today, (int) $a->id)));
                    } catch (Throwable $e) { Log::warning('Licence renewal invoice failed', ['agency' => $a->id, 'error' => $e->getMessage()]); }
                }
                continue;
            }
            // 2) Billing off + auto-renew: roll on (set up, not billed yet must never lock).
            if ($l->auto_renew && ! self::billingOn($a) && $exp && $exp <= $today) {
                $out[] = "licence {$l->id} ({$l->part_number}) agency {$a->id}: rolled on, billing off";
                if (! $dry) {
                    $next = $exp; while ($next <= $today) $next = self::nextTerm($l, $next);
                    DB::table('agency_licences')->where('id', $l->id)->update(['expires_at' => $next, 'last_renewed_at' => now(), 'updated_at' => now()]);
                    $touched[(int) $a->id] = 1;
                }
                continue;
            }
            // 3) An issued renewal invoice past due and unpaid: grace; paid again: active.
            if ($l->last_invoice_id) {
                $inv = DB::table('platform_invoices')->where('id', $l->last_invoice_id)->first();
                if ($inv && $inv->status === 'paid' && $l->status === 'grace') {
                    $out[] = "licence {$l->id}: renewal paid, active again";
                    if (! $dry) { DB::table('agency_licences')->where('id', $l->id)->update(['status' => 'active', 'grace_until' => null, 'updated_at' => now()]); $touched[$a->id] = 1; }
                    continue;
                }
                if ($inv && in_array($inv->status, ['issued', 'sent', 'overdue'], true) && $inv->due_at && (string) $inv->due_at < $today && $l->status === 'active') {
                    $out[] = "licence {$l->id}: renewal invoice {$inv->number} unpaid — grace";
                    if (! $dry) { self::startGrace($l, $a, 'its renewal invoice ' . $inv->number . ' is unpaid'); $touched[$a->id] = 1; }
                    continue;
                }
            }
            // 4) Not renewing and past expiry: grace.
            if (! $l->auto_renew && $exp && $exp < $today && $l->status === 'active') {
                $out[] = "licence {$l->id} ({$l->part_number}) agency {$a->id}: expired — grace";
                if (! $dry) { self::startGrace($l, $a, 'it expired on ' . $exp . ' and is not set to renew'); $touched[$a->id] = 1; }
                continue;
            }
            // 5) Grace over: expired, the features go.
            if ($l->status === 'grace' && $l->grace_until && (string) $l->grace_until < $today) {
                $out[] = "licence {$l->id} ({$l->part_number}) agency {$a->id}: grace over — expired";
                if (! $dry) {
                    DB::table('agency_licences')->where('id', $l->id)->update(['status' => 'expired', 'updated_at' => now()]);
                    self::tell($a, '⛔ ' . $l->name . ' has ended', 'The licence for ' . $l->name . ' (' . $l->part_number . ') has ended, so its features are switched off. Records are kept. Contact KiddieTrac to renew.', true);
                    $touched[$a->id] = 1;
                }
                continue;
            }
            // 6) Reminders before a non-renewing licence ends: 14 and 3 days out.
            if (! $l->auto_renew && $exp) {
                $days = Carbon::parse($today)->diffInDays(Carbon::parse($exp), false);
                if (in_array((int) $days, [14, 3], true) && (string) $l->reminded_for !== $exp . ':' . (int) $days) {
                    $out[] = "licence {$l->id}: reminder, ends in {$days} days";
                    if (! $dry) {
                        DB::table('agency_licences')->where('id', $l->id)->update(['reminded_for' => $exp . ':' . (int) $days, 'updated_at' => now()]);
                        self::tell($a, '⏳ ' . $l->name . ' ends in ' . (int) $days . ' days', 'The licence for ' . $l->name . ' (' . $l->part_number . ') ends on ' . Carbon::parse($exp)->format('M j, Y') . ' and is not set to renew.', false);
                    }
                }
            }
        }
        if (! $dry) foreach (array_keys($touched) as $k) if (is_int($k)) self::sync($k);
        return $out;
    }

    private static function startGrace(object $l, object $a, string $why): void
    {
        $until = AgencyTime::todayDate()->addDays(self::GRACE_DAYS)->toDateString();
        DB::table('agency_licences')->where('id', $l->id)->update(['status' => 'grace', 'grace_until' => $until, 'updated_at' => now()]);
        self::tell($a, '⚠️ ' . $l->name . ': ' . self::GRACE_DAYS . ' days to renew', 'The licence for ' . $l->name . ' (' . $l->part_number . ') needs attention because ' . $why
            . '. Its features stay on until ' . Carbon::parse($until)->format('M j, Y') . ', then switch off.', true);
    }

    /** Bell for the agency's admins and the platform admins; email to the agency admins when it matters. */
    private static function tell(object $a, string $title, string $body, bool $email): void
    {
        try {
            $agencyAdmins = DB::table('role_assignments')->where('agency_id', $a->id)->where('role', 'agency_admin')->where('active', 1)->pluck('user_id')->unique()->all();
            $platform = DB::table('role_assignments')->where('role', 'platform_admin')->where('active', 1)->pluck('user_id')->unique()->all();
            $rows = [];
            foreach (array_unique(array_merge($agencyAdmins, $platform)) as $uid) {
                $rows[] = ['user_id' => (int) $uid, 'type' => 'licence', 'title' => mb_substr($title . ' · ' . $a->name, 0, 190), 'body' => $body,
                    'data' => json_encode(['link' => in_array($uid, $platform) ? '#licences' : '#licence']), 'created_at' => now()];
            }
            if ($rows) Notify::write($rows);
            if ($email && $agencyAdmins) {
                $emails = DB::table('users')->whereIn('id', $agencyAdmins)->whereNull('deleted_at')->whereNotNull('email')->pluck('email')->all();
                $html = \App\Services\EmailTemplate::wrap(null, '<p style="margin:0 0 12px;">' . e($body) . '</p>', ['eyebrow' => 'YOUR KIDDIETRAC LICENCE', 'title' => $title, 'preheader' => $body]);
                foreach ($emails as $em) {
                    \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($em, $title) {
                        \App\Support\MailScope::platform($m);
                        $m->to($em)->from('noreply@kiddietrac.com', 'KiddieTrac')->subject(strip_tags($title));
                    });
                }
            }
        } catch (Throwable $e) {
            Log::warning('Licence notice failed', ['agency' => $a->id, 'error' => $e->getMessage()]);
        }
    }

    /** One licence as the screens use it. */
    public static function shape(object $l, ?string $agencyName = null): array
    {
        return [
            'id' => (int) $l->id, 'agency_id' => (int) $l->agency_id, 'agency_name' => $agencyName, 'product_id' => $l->product_id ? (int) $l->product_id : null,
            'part_number' => $l->part_number, 'name' => $l->name, 'kind' => $l->kind, 'plan_code' => $l->plan_code,
            'feature_keys' => json_decode((string) $l->feature_keys, true) ?: [], 'qty' => (float) $l->qty,
            'unit_price' => round((int) $l->unit_price_cents / 100, 2), 'currency' => $l->currency, 'interval' => $l->interval, 'term_months' => isset($l->term_months) ? (int) $l->term_months : null,
            'starts_at' => $l->starts_at, 'expires_at' => $l->expires_at, 'auto_renew' => (bool) $l->auto_renew,
            'status' => $l->status, 'grace_until' => $l->grace_until, 'source' => $l->source, 'quote_id' => $l->quote_id ? (int) $l->quote_id : null,
            'last_invoice_id' => $l->last_invoice_id ? (int) $l->last_invoice_id : null, 'notes' => $l->notes,
        ];
    }
}
