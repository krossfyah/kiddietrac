<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\Licences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Licences (2026-10-24). The rules live in App\Support\Licences. */
class LicenceController extends Controller
{
    use ResolvesCentreContext;

    private function audit(Request $r, string $action, ?int $id, string $summary, array $extra = []): void
    {
        \App\Support\Audit::write(['user_id' => $r->user()->id, 'action' => $action, 'entity_type' => 'agency_licence', 'entity_id' => $id,
            'payload' => json_encode(array_merge(['summary' => $summary], $extra)), 'created_at' => now()]);
    }

    /** GET /platform/licences?agency_id=&status= — every licence, plus a sales summary by part number. */
    public function index(Request $r): JsonResponse
    {
        $q = DB::table('agency_licences as l')->leftJoin('agencies as a', 'a.id', '=', 'l.agency_id');
        if ($aid = (int) $r->query('agency_id')) $q->where('l.agency_id', $aid);
        if ($st = $r->query('status')) $q->where('l.status', $st);
        $rows = $q->orderByRaw("FIELD(l.status,'grace','active','expired','cancelled')")->orderBy('l.expires_at')->limit(1000)->get(['l.*', 'a.name as agency_name']);

        // Sales by part number: what is licensed now, and what has been invoiced (all time and this year).
        $year = now()->startOfYear()->toDateString();
        $summary = [];
        foreach (DB::table('agency_licences')->whereIn('status', Licences::LIVE)->get() as $l) {
            $k = $l->part_number ?: '—';
            $summary[$k] = $summary[$k] ?? ['part_number' => $k, 'name' => $l->name, 'kind' => $l->kind, 'live' => 0, 'mrr_cents' => 0, 'invoiced_cents' => 0, 'invoiced_ytd_cents' => 0];
            $summary[$k]['live']++;
            $summary[$k]['mrr_cents'] += \App\Support\PlatformBilling::monthlyCents((int) round((float) $l->qty * (int) $l->unit_price_cents), $l->interval);
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('platform_invoice_lines')) {
            $lines = DB::table('platform_invoice_lines as pl')->join('platform_invoices as pi', 'pi.id', '=', 'pl.invoice_id')
                ->where('pi.status', '!=', 'void')->get(['pl.part_number', 'pl.description', 'pl.amount_cents', 'pi.created_at']);
            foreach ($lines as $ln) {
                $k = $ln->part_number ?: '—';
                $summary[$k] = $summary[$k] ?? ['part_number' => $k, 'name' => $ln->description, 'kind' => null, 'live' => 0, 'mrr_cents' => 0, 'invoiced_cents' => 0, 'invoiced_ytd_cents' => 0];
                $summary[$k]['invoiced_cents'] += (int) $ln->amount_cents;
                if ((string) $ln->created_at >= $year) $summary[$k]['invoiced_ytd_cents'] += (int) $ln->amount_cents;
            }
        }
        ksort($summary);
        return response()->json([
            'licences' => $rows->map(fn ($l) => Licences::shape($l, $l->agency_name))->values(),
            'summary' => array_values($summary),
            'products' => DB::table('sales_products')->whereIn('kind', ['plan', 'addon'])->where('active', 1)->orderBy('kind')->orderBy('sort')->orderBy('id')
                ->get(['id', 'name', 'kind', 'part_number', 'price', 'unit', 'plan_code', 'feature_keys']),
            'agencies' => DB::table('agencies')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            // Centres open vs the licence's cap, per agency on this page (App\Support\CentreCap, 2026-11-26).
            'centre_caps' => (object) $rows->pluck('agency_id')->unique()->mapWithKeys(fn ($a) => [(int) $a => \App\Support\CentreCap::status((int) $a)])->all(),
        ]);
    }

    /** PUT /platform/licences/centre-cap/{agencyId} {included: int|null} - this agency's included centres (null = the product's). */
    public function centreCap(Request $r, int $agencyId): JsonResponse
    {
        abort_unless(DB::table('agencies')->where('id', $agencyId)->exists(), 404, 'Agency not found');
        $d = $r->validate(['included' => 'nullable|integer|min:0|max:100000']);
        $before = \App\Support\CentreCap::override($agencyId);
        \App\Support\CentreCap::setOverride($agencyId, isset($d['included']) ? (int) $d['included'] : null);
        $this->audit($r, 'licence.centre_cap', $agencyId, 'Set agency #' . $agencyId . '\'s included centres to '
            . (isset($d['included']) ? (int) $d['included'] : 'the product\'s number'), ['agency_id' => $agencyId, 'before' => $before]);

        return response()->json(\App\Support\CentreCap::status($agencyId));
    }

    /** POST /platform/licences {agency_id, product_id, qty?, unit_price?, interval?, starts_at?, expires_at?, auto_renew?, notes?} */
    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'agency_id' => ['required', 'integer', 'exists:agencies,id'], 'product_id' => ['required', 'integer', 'exists:sales_products,id'],
            'qty' => ['nullable', 'numeric', 'min:0.01', 'max:100000'], 'unit_price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'interval' => ['nullable', 'in:monthly,quarterly,semiannual,annual'], 'starts_at' => ['nullable', 'date'], 'expires_at' => ['nullable', 'date'],
            'auto_renew' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:500'], 'term_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            // Paid, complimentary or a TRIAL of any length (2026-10-25).
            'licence_type' => ['nullable', 'in:paid,comp,trial'], 'trial_length' => ['nullable', 'integer', 'min:1', 'max:999'], 'trial_unit' => ['nullable', 'in:days,weeks,months,years'],
        ]);
        $type = $d['licence_type'] ?? 'paid';
        unset($d['licence_type']);
        if ($type === 'comp') { $d['unit_price'] = 0; $d['source'] = 'comp'; }
        if ($type === 'trial') {
            $len = (int) ($d['trial_length'] ?? 30); $unit = $d['trial_unit'] ?? 'days';
            $d['unit_price'] = 0; $d['source'] = 'trial'; $d['auto_renew'] = false; $d['term_months'] = null;
            $d['expires_at'] = Licences::trialEnd($d['starts_at'] ?? \App\Support\AgencyTime::todayDate()->toDateString(), $len, $unit);
            $d['notes'] = trim(($d['notes'] ?? '') . ' Trial: ' . $len . ' ' . ($len === 1 ? rtrim($unit, 's') : $unit) . '.');
        }
        unset($d['trial_length'], $d['trial_unit']);
        $id = Licences::grant((int) $d['agency_id'], (int) $d['product_id'], array_filter($d, fn ($v) => $v !== null));
        $l = DB::table('agency_licences')->find($id);
        $this->audit($r, 'licence.granted', $id, 'Licensed ' . $l->name . ' (' . $l->part_number . ') to agency #' . $l->agency_id, ['agency_id' => $l->agency_id]);
        return response()->json(['licence' => Licences::shape($l)], 201);
    }

    /** PATCH /platform/licences/{id} {expires_at?, auto_renew?, qty?, unit_price?, interval?, notes?, status: cancelled|active?} */
    public function update(Request $r, int $id): JsonResponse
    {
        $l = DB::table('agency_licences')->find($id);
        abort_unless($l, 404);
        $d = $r->validate([
            'expires_at' => ['nullable', 'date'], 'auto_renew' => ['nullable', 'boolean'], 'qty' => ['nullable', 'numeric', 'min:0.01'],
            'unit_price' => ['nullable', 'numeric', 'min:0'], 'interval' => ['nullable', 'in:monthly,quarterly,semiannual,annual'],
            'notes' => ['nullable', 'string', 'max:500'], 'status' => ['nullable', 'in:active,cancelled'], 'term_months' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);
        /* THE BUNDLE (2026-10-25): price, quantity and note are per line; the term (end date, billing interval,
           contract term, auto-renew) is the agency's and goes onto every line. Cancelling the plan cancels the
           whole licence; cancelling an add-on removes just that add-on. */
        $upd = ['updated_at' => now()];
        foreach (['qty', 'notes'] as $k) if (array_key_exists($k, $d)) $upd[$k] = $d[$k];
        if (array_key_exists('unit_price', $d)) $upd['unit_price_cents'] = (int) round((float) $d['unit_price'] * 100);
        $term = [];
        if ($r->has('term_months')) $term['term_months'] = $d['term_months'] ?? null;
        foreach (['expires_at', 'interval'] as $k) if (array_key_exists($k, $d) && $d[$k] !== null) $term[$k] = $d[$k];
        if (array_key_exists('auto_renew', $d) && $d['auto_renew'] !== null) $term['auto_renew'] = (bool) $d['auto_renew'];
        // A new end date in the future re-opens a licence in grace or one that had ended - all of it.
        if (! empty($term['expires_at']) && $term['expires_at'] >= \App\Support\AgencyTime::todayDate()->toDateString() && in_array($l->status, ['grace', 'expired'], true) && empty($d['status'])) {
            $term['status'] = 'active'; $term['grace_until'] = null;
        }
        if (! empty($d['status'])) {
            if ($d['status'] === 'cancelled' && $l->kind === 'plan') {
                DB::table('agency_licences')->where('agency_id', $l->agency_id)->where('status', '!=', 'cancelled')->update(['status' => 'cancelled', 'updated_at' => now()]);
            } elseif ($d['status'] === 'active' && $l->kind === 'addon') {
                abort_unless(Licences::planLine((int) $l->agency_id), 422, 'Add a plan first: an add-on joins the agency\'s plan.');
                $upd['status'] = 'active';
            } else {
                $upd['status'] = $d['status']; if ($d['status'] === 'active') $upd['grace_until'] = null;
            }
        }
        DB::table('agency_licences')->where('id', $id)->update($upd);
        if ($term) {
            DB::table('agency_licences')->where('agency_id', $l->agency_id)->where('status', '!=', 'cancelled')->update($term + ['updated_at' => now()]);
        }
        Licences::sync((int) $l->agency_id);
        $this->audit($r, 'licence.updated', $id, 'Changed the licence ' . $l->name . ' (' . $l->part_number . ') for agency #' . $l->agency_id, ['changes' => $d]);
        /* Removed or cancelled: the open invoices that bill it, so the screen can offer to reissue them (2026-10-25).
           Cancelling the plan cancels every line, so every line's invoices are collected. */
        $open = [];
        if (($d['status'] ?? null) === 'cancelled') {
            $ids = $l->kind === 'plan' ? DB::table('agency_licences')->where('agency_id', $l->agency_id)->pluck('id')->all() : [$id];
            foreach ($ids as $lid) foreach (\App\Support\PlatformAccounts::openInvoicesFor((int) $lid) as $o) $open[$o->id] = $o;
        }
        return response()->json(['licence' => Licences::shape(DB::table('agency_licences')->find($id)), 'open_invoices' => array_values($open)]);
    }

    /** POST /platform/licences/{id}/convert {to: paid|trial, trial_length?, trial_unit?} — the whole licence changes type. */
    public function convert(Request $r, int $id): JsonResponse
    {
        $l = DB::table('agency_licences')->find($id);
        abort_unless($l, 404);
        $d = $r->validate(['to' => ['required', 'in:paid,trial'], 'trial_length' => ['nullable', 'integer', 'min:1', 'max:999'], 'trial_unit' => ['nullable', 'in:days,weeks,months,years']]);
        $q = DB::table('agency_licences')->where('agency_id', $l->agency_id)->where('status', '!=', 'cancelled');
        if ($d['to'] === 'paid') {
            // Each line takes its catalogue price unless it already has one; it renews from now on.
            foreach ((clone $q)->get() as $x) {
                $price = (int) $x->unit_price_cents ?: Licences::listCents($x);
                DB::table('agency_licences')->where('id', $x->id)->update(['source' => 'manual', 'unit_price_cents' => $price, 'auto_renew' => 1, 'updated_at' => now()]);
            }
        } else {
            $end = Licences::trialEnd(\App\Support\AgencyTime::todayDate()->toDateString(), (int) ($d['trial_length'] ?? 30), $d['trial_unit'] ?? 'days');
            (clone $q)->update(['source' => 'trial', 'unit_price_cents' => 0, 'auto_renew' => 0, 'term_months' => null, 'expires_at' => $end, 'status' => 'active', 'grace_until' => null, 'updated_at' => now()]);
        }
        Licences::sync((int) $l->agency_id);
        $this->audit($r, 'licence.converted', $id, 'Licence for agency #' . $l->agency_id . ' converted to ' . $d['to']);
        return response()->json(['ok' => true]);
    }

    /** POST /platform/licences/{id}/renew — the WHOLE licence one more term (plan and every add-on, one end date). */
    public function renew(Request $r, int $id): JsonResponse
    {
        $l = DB::table('agency_licences')->find($id);
        abort_unless($l, 404);
        $next = Licences::renewBundle((int) $l->agency_id);
        $n = DB::table('agency_licences')->where('agency_id', $l->agency_id)->where('status', '!=', 'cancelled')->count();
        $this->audit($r, 'licence.renewed', $id, 'Renewed the whole licence (' . $n . ' line' . ($n === 1 ? '' : 's') . ') to ' . $next . ' for agency #' . $l->agency_id);
        return response()->json(['expires_at' => $next, 'lines' => $n]);
    }

    /** GET /admin/licence — the agency's own licence, read-only (agency admins). */
    public function mine(Request $r): JsonResponse
    {
        $aid = (int) ($this->resolveAgencyId($r) ?: 0);
        abort_unless($aid, 403);
        $rows = DB::table('agency_licences')->where('agency_id', $aid)->where('status', '!=', 'cancelled')->orderByRaw("FIELD(kind,'plan','addon')")->orderBy('expires_at')->get();
        $a = DB::table('agencies')->where('id', $aid)->first(['name', 'plan_code', 'billing_status', 'next_invoice_at']);
        return response()->json([
            'agency' => $a ? $a->name : null, 'managed' => $rows->count() > 0, 'plan' => Licences::managed($aid) ? Licences::planLevel($aid) : ($a->plan_code ?? null),
            'licences' => $rows->map(fn ($l) => array_diff_key(Licences::shape($l), array_flip(['notes', 'last_invoice_id', 'quote_id', 'source'])))->values(),
            'next_invoice_at' => $a->next_invoice_at ?? null,
            'enhancements' => Licences::enhancements($aid),
            'summary' => self::summary($aid),
        ]);
    }

    /**
     * The whole instance at a glance (2026-10-25): the plan and its dates, who and what is on the
     * account, which features the plan includes, and recent use. Live records only (open
     * centres, enrolled children, families that have not left, active people).
     */
    private static function summary(int $aid): array
    {
        $a = DB::table('agencies')->where('id', $aid)->first();
        $cids = \App\Support\Live::centreIds($aid);
        $kids = fn () => DB::table('children')->join('families', 'families.id', '=', 'children.family_id')->whereIn('families.centre_id', $cids ?: [0]);
        $people = function (array $roles) use ($aid) {
            return (int) DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.agency_id', $aid)->where('ra.active', 1)->whereIn('ra.role', $roles)->whereNull('u.deleted_at')
                ->distinct()->count('ra.user_id');
        };
        $monthStart = \App\Support\AgencyTime::boundaryUtc($aid, 'month');

        $plan = DB::table('agency_licences')->where('agency_id', $aid)->where('kind', 'plan')->where('status', '!=', 'cancelled')
            ->orderByDesc('expires_at')->first();
        $firstPlan = DB::table('agency_licences')->where('agency_id', $aid)->where('kind', 'plan')->min('starts_at');
        $level = Licences::managed($aid) ? Licences::planLevel($aid) : (string) ($a->plan_code ?? '');

        $flags = \App\Http\Controllers\Api\FeatureFlagController::effectiveFor($aid);
        $features = [];
        foreach (\App\Http\Controllers\Api\FeatureFlagController::FEATURES as $key => $f) {
            $features[] = ['key' => $key, 'label' => $f['label'], 'group' => $f['group'] ?? 'Other', 'on' => (bool) ($flags[$key] ?? true),
                'plan_min' => $f['plan_min'] ?? 'free', 'plan_min_name' => ($f['plan_min'] ?? '') === 'addon' ? 'Add-on' : (Licences::PLAN_NAMES[$f['plan_min'] ?? 'free'] ?? ucfirst((string) ($f['plan_min'] ?? '')))];
        }

        $q = fn ($fn) => rescue($fn, 0, false);

        // Who runs it: the owner (the admin whose email is the agency's contact, else the first
        // admin), every admin, every director with their centres.
        $name = fn ($u) => trim($u->first_name . ' ' . $u->last_name) ?: $u->email;
        $adminRows = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')->where('ra.agency_id', $aid)->where('ra.role', 'agency_admin')
            ->where('ra.active', 1)->whereNull('u.deleted_at')->orderBy('ra.id')->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.phone', 'u.photo_url'])->unique('id')->values();
        $owner = $adminRows->first(fn ($u) => $a->contact_email && strcasecmp((string) $u->email, (string) $a->contact_email) === 0) ?: $adminRows->first();
        $dirRows = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.role', 'centre_director')->where('ra.active', 1)->whereNull('u.deleted_at')
            ->where(fn ($w) => $w->where('ra.agency_id', $aid)->orWhereIn('ra.centre_id', $cids ?: [0]))
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.photo_url', 'c.name as centre'])->groupBy('id');
        $team = [
            'owner' => $owner ? ['name' => $name($owner), 'email' => $owner->email, 'phone' => $owner->phone, 'photo_url' => $owner->photo_url] : null,
            'admins' => $adminRows->map(fn ($u) => ['name' => $name($u), 'email' => $u->email, 'photo_url' => $u->photo_url, 'owner' => $owner && $u->id === $owner->id])->all(),
            'directors' => $dirRows->map(fn ($g) => ['name' => $name($g[0]), 'email' => $g[0]->email, 'photo_url' => $g[0]->photo_url,
                'centres' => $g->pluck('centre')->filter()->unique()->values()->all()])->values()->all(),
        ];

        // What the plan allows, and how much room is left.
        $lim = Licences::LIMITS[$level ?: 'free'] ?? Licences::LIMITS['free'];
        $nCentres = count($cids);
        $nKids = (int) $q(fn () => \App\Support\Live::enrolledChildren($kids())->distinct()->count('children.id'));
        $room = fn ($used, $max) => ['used' => $used, 'max' => $max, 'left' => $max === null ? null : max(0, $max - $used), 'over' => $max !== null && $used > $max];
        $addons = DB::table('agency_licences')->where('agency_id', $aid)->where('kind', 'addon')->whereIn('status', Licences::LIVE)->pluck('name')->all();
        $planDesc = DB::table('sales_products')->where('kind', 'plan')->where('plan_code', $level)->value('description');

        return [
            'people' => $team,
            'limits' => ['centres' => $room($nCentres, $lim['centres']), 'children' => $room($nKids, $lim['children'])],
            'addons' => $addons,
            'plan_description' => $planDesc,
            'plan_name' => Licences::planLabel($aid),
            'plan_level' => $level ?: null,
            'licence_managed' => Licences::managed($aid),
            'status' => $plan->status ?? ($a->billing_status ?? null),
            'started_at' => $firstPlan ?: ($a->billing_starts_at ?? null),
            'current_term_start' => $plan->starts_at ?? null,
            'ends_at' => $plan->expires_at ?? null,
            'auto_renew' => $plan ? (bool) $plan->auto_renew : null,
            'grace_until' => $plan->grace_until ?? null,
            'interval' => $plan->interval ?? ($a->billing_interval ?? null),
            'customer_since' => $a->created_at ?? null,
            'trial_ends_at' => $a->trial_ends_at ?? null,
            'billing_status' => $a->billing_status ?? null,
            'next_invoice_at' => $a->next_invoice_at ?? null,
            'white_label' => isset($a->powered_by_visible) ? ! (bool) $a->powered_by_visible : false,
            'counts' => [
                'centres' => count($cids),
                'licensed_capacity' => (int) $q(fn () => DB::table('centres')->whereIn('id', $cids ?: [0])->sum('license_capacity')),
                'rooms' => (int) $q(fn () => DB::table('rooms')->whereIn('centre_id', $cids ?: [0])->where('active', 1)->count()),
                'children' => (int) $q(fn () => \App\Support\Live::enrolledChildren($kids())->distinct()->count('children.id')),
                'waitlist' => (int) $q(fn () => $kids()->whereNull('children.deleted_at')->where('children.enrollment_status', 'waitlist')->distinct()->count('children.id')),
                'families' => (int) $q(fn () => \App\Support\Live::families(DB::table('families')->whereIn('centre_id', $cids ?: [0]))->count()),
                'parents' => $people(['guardian']),
                'educators' => $people(['educator']),
                'directors' => $people(['centre_director']),
                'admins' => $people(['agency_admin']),
                'home_visitors' => $people(['home_visitor']),
                'auditors' => $people(['auditor']),
            ],
            'usage_this_month' => [
                'photos' => (int) $q(fn () => DB::table('photos')->whereIn('centre_id', $cids ?: [0])->where('created_at', '>=', $monthStart)->count()),
                'texts' => (int) $q(fn () => DB::table('sms_messages')->where('agency_id', $aid)->where('direction', 'out')->where('created_at', '>=', $monthStart)->count()),
                // Voice: automated voice alerts plus calls placed from the portal's dial pad (2026-10-25).
                'calls' => (int) $q(fn () => DB::table('voice_calls')->where('agency_id', $aid)->where('created_at', '>=', $monthStart)->count())
                    + (int) $q(fn () => DB::table('outbound_calls')->where('agency_id', $aid)->where('created_at', '>=', $monthStart)->count()),
                'messages' => (int) $q(fn () => DB::table('messages as m')->join('conversations as c', 'c.id', '=', 'm.conversation_id')->whereIn('c.centre_id', $cids ?: [0])->where('m.created_at', '>=', $monthStart)->count()),
            ],
            'features' => $features,
            // The platform's region and what every licence includes (2026-10-25).
            'region' => \App\Support\PlatformRegion::forAgency($aid),
            'included' => \App\Support\PlatformRegion::included($flags),
        ];
    }
}
