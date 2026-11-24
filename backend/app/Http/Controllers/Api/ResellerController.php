<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * RESELLERS (2026-10-21). A reseller owns several agencies and sets one brand for all of
 * them. Two audiences:
 *
 *   platform admin  /platform/resellers…   create resellers, assign agencies, add the
 *                                           people who manage each one
 *   reseller admin  /reseller/…            its OWN brand, and the NAMES of its own agencies
 *
 * DATA-LEAKAGE RULE: a reseller admin is not a member of any agency (role_assignments
 * agency_id and centre_id are NULL), so every agency-scoped endpoint already refuses
 * them. These endpoints give them their reseller row, its brand and its agencies' names
 * - nothing about families, children, staff or money.
 */
final class ResellerController extends Controller
{
    private function isPlatform(Request $request): bool
    {
        return DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
    }

    /** The reseller this caller manages: their own, or (platform admin) the one named. */
    private function resellerFor(Request $request): ?object
    {
        if ($this->isPlatform($request)) {
            $id = (int) $request->input('reseller_id', 0);
            return $id ? DB::table('resellers')->where('id', $id)->first() : null;
        }
        $id = (int) DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('role', 'reseller_admin')->where('active', true)->value('reseller_id');
        return $id ? DB::table('resellers')->where('id', $id)->first() : null;
    }

    private function present(object $r, bool $withAdmins = false): array
    {
        $brand = json_decode((string) $r->brand, true) ?: [];
        $out = [
            'id' => (int) $r->id, 'name' => $r->name, 'slug' => $r->slug, 'status' => $r->status,
            'hide_kiddietrac' => (bool) $r->hide_kiddietrac,
            'allow_agency_override' => (bool) $r->allow_agency_override,
            'commission_pct' => (float) ($r->commission_pct ?? 0),
            'commission_flat_cents' => (int) ($r->commission_flat_cents ?? 0),
            'brand' => (object) $brand,
            'resolved' => Brand::forReseller((int) $r->id),
            'agencies' => DB::table('agencies')->where('reseller_id', $r->id)->whereNull('deleted_at')
                ->orderBy('name')->get(['id', 'name', 'slug']),
        ];
        if ($withAdmins) {
            $out['admins'] = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.role', 'reseller_admin')->where('ra.reseller_id', $r->id)->where('ra.active', true)
                ->whereNull('u.deleted_at')
                ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.status']);
        }
        return $out;
    }

    private function audit(Request $request, string $action, ?int $resellerId, array $payload): void
    {
        try {
            \App\Support\Audit::write([
                'user_id' => $request->user()->id, 'agency_id' => null, 'action' => $action,
                'entity_type' => 'reseller', 'entity_id' => $resellerId,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    // ── Platform admin ─────────────────────────────────────────────────────────

    /** GET /platform/resellers */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->isPlatform($request), 403);
        $rows = DB::table('resellers')->orderBy('name')->get()->map(fn ($r) => $this->present($r, true));
        return response()->json([
            'resellers' => $rows,
            // For the "assign agencies" picker: every live agency and which reseller has it.
            'all_agencies' => DB::table('agencies')->whereNull('deleted_at')->orderBy('name')
                ->get(['id', 'name', 'slug', 'reseller_id', 'powered_by_visible']),
        ]);
    }

    /** POST /platform/resellers {name, slug?} */
    public function store(Request $request): JsonResponse
    {
        abort_unless($this->isPlatform($request), 403);
        $data = $request->validate(['name' => 'required|string|max:160', 'slug' => 'nullable|string|max:80|regex:/^[a-z0-9-]+$/']);
        $slug = $data['slug'] ?? Str::slug($data['name']);
        if (DB::table('resellers')->where('slug', $slug)->exists() || DB::table('agencies')->where('slug', $slug)->exists()) {
            return response()->json(['message' => 'That short name is already used by a reseller or an agency.'], 422);
        }
        $id = DB::table('resellers')->insertGetId([
            'name' => $data['name'], 'slug' => $slug, 'brand' => json_encode(['app_name' => $data['name']]),
            'hide_kiddietrac' => true, 'allow_agency_override' => false, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit($request, 'reseller.created', $id, ['summary' => 'Created reseller ' . $data['name']]);
        return response()->json(['reseller' => $this->present(DB::table('resellers')->find($id), true)], 201);
    }

    /** PATCH /platform/resellers/{id} {name?, slug?, status?} */
    public function update(Request $request, int $id): JsonResponse
    {
        abort_unless($this->isPlatform($request), 403);
        $r = DB::table('resellers')->find($id);
        abort_unless($r, 404);
        $data = $request->validate(['name' => 'nullable|string|max:160', 'slug' => 'nullable|string|max:80|regex:/^[a-z0-9-]+$/',
            'status' => 'nullable|in:active,suspended',
            // Commission (2026-10-21): % of the pre-tax plan money collected, plus a flat
            // amount per agency that paid that month.
            'commission_pct' => 'nullable|numeric|min:0|max:100',
            'commission_flat_cents' => 'nullable|integer|min:0|max:100000000']);
        if (! empty($data['slug']) && $data['slug'] !== $r->slug
            && (DB::table('resellers')->where('slug', $data['slug'])->exists() || DB::table('agencies')->where('slug', $data['slug'])->exists())) {
            return response()->json(['message' => 'That short name is already used.'], 422);
        }
        DB::table('resellers')->where('id', $id)->update(array_filter($data, fn ($v) => $v !== null) + ['updated_at' => now()]);
        $this->audit($request, 'reseller.updated', $id, ['summary' => 'Updated reseller ' . $r->name, 'changes' => $data]);
        return response()->json(['reseller' => $this->present(DB::table('resellers')->find($id), true)]);
    }

    /** PUT /platform/resellers/{id}/agencies {agency_ids[]} — exactly these agencies. */
    public function setAgencies(Request $request, int $id): JsonResponse
    {
        abort_unless($this->isPlatform($request), 403);
        $r = DB::table('resellers')->find($id);
        abort_unless($r, 404);
        $ids = array_values(array_unique(array_map('intval', (array) $request->input('agency_ids', []))));
        $before = DB::table('agencies')->where('reseller_id', $id)->pluck('id')->map(fn ($v) => (int) $v)->all();
        DB::transaction(function () use ($id, $ids) {
            DB::table('agencies')->where('reseller_id', $id)->whereNotIn('id', $ids ?: [0])->update(['reseller_id' => null, 'updated_at' => now()]);
            if ($ids) {
                DB::table('agencies')->whereIn('id', $ids)->update(['reseller_id' => $id, 'updated_at' => now()]);
            }
        });
        Brand::forget();
        \App\Support\ResellerAccess::syncReseller($id);
        $names = fn ($list) => DB::table('agencies')->whereIn('id', $list ?: [0])->pluck('name')->all();
        $this->audit($request, 'reseller.agencies_set', $id, [
            'summary' => $r->name . ' now has ' . count($ids) . ' agenc' . (count($ids) === 1 ? 'y' : 'ies'),
            'added' => $names(array_diff($ids, $before)), 'removed' => $names(array_diff($before, $ids)),
        ]);
        return response()->json(['reseller' => $this->present(DB::table('resellers')->find($id), true)]);
    }

    /** POST /platform/resellers/{id}/admins {email} — an EXISTING account becomes its admin. */
    public function addAdmin(Request $request, int $id): JsonResponse
    {
        abort_unless($this->isPlatform($request), 403);
        $r = DB::table('resellers')->find($id);
        abort_unless($r, 404);
        $email = mb_strtolower(trim((string) $request->validate(['email' => 'required|email|max:190'])['email']));
        $users = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->whereNull('deleted_at')->get(['id', 'first_name', 'last_name']);
        if ($users->count() !== 1) {
            return response()->json(['message' => $users->isEmpty()
                ? 'No account uses that email. Create the person first (Admin → Users), then add them here.'
                : 'Several accounts share that email; ask them for the one to use and give its username.'], 422);
        }
        $u = $users->first();
        $exists = DB::table('role_assignments')->where('user_id', $u->id)->where('role', 'reseller_admin')
            ->where('reseller_id', $id)->first();
        if ($exists) {
            DB::table('role_assignments')->where('id', $exists->id)->update(['active' => 1]);
        } else {
            DB::table('role_assignments')->insert(['user_id' => $u->id, 'role' => 'reseller_admin', 'reseller_id' => $id,
                'agency_id' => null, 'centre_id' => null, 'active' => 1, 'created_at' => now()]);
        }
        \App\Support\ResellerAccess::sync((int) $u->id);
        $this->audit($request, 'reseller.admin_added', $id, ['summary' => trim($u->first_name . ' ' . $u->last_name)
            . ' can now manage ' . $r->name, 'user_id' => (int) $u->id]);
        return response()->json(['reseller' => $this->present(DB::table('resellers')->find($id), true)]);
    }

    /** DELETE /platform/resellers/{id}/admins/{userId} */
    public function removeAdmin(Request $request, int $id, int $userId): JsonResponse
    {
        abort_unless($this->isPlatform($request), 403);
        $r = DB::table('resellers')->find($id);
        abort_unless($r, 404);
        DB::table('role_assignments')->where('user_id', $userId)->where('role', 'reseller_admin')
            ->where('reseller_id', $id)->update(['active' => 0]);
        \App\Support\ResellerAccess::sync($userId);   // withdraws the agency grants with it
        $name = DB::table('users')->where('id', $userId)->value(DB::raw("TRIM(CONCAT(first_name,' ',last_name))"));
        $this->audit($request, 'reseller.admin_removed', $id, ['summary' => $name . ' can no longer manage ' . $r->name, 'user_id' => $userId]);
        return response()->json(['reseller' => $this->present(DB::table('resellers')->find($id), true)]);
    }

    // ── The reseller's own app (white-label phase 5) ──────────────────────────
    /* A web server cannot compile an Android app, so "Build branded app" produces a BUILD
       KIT: the few facts the Android project's generator needs (flavor, package id, name,
       ?tenant= code, icon, colours). tools/build-from-kit.mjs in kiddietrac-android turns it
       into a signed APK in one command (or Codemagic does, from the same file). The
       checklist is the part no script can do: a Firebase app for the package (push), the
       Play Console listing, and the passkey assetlinks entry. */

    private function appOf(object $r): array
    {
        $a = json_decode((string) ($r->app_build ?? ''), true) ?: [];
        return $a + ['application_id' => null, 'version_code' => null, 'version_name' => null,
            'firebase_ready' => false, 'play_listing_url' => null, 'status' => 'draft', 'kit_at' => null, 'built_at' => null];
    }

    private function kitFor(object $r, array $app): array
    {
        $t = Brand::forReseller((int) $r->id);
        $flavor = preg_replace('/[^a-z0-9]/', '', strtolower((string) $r->slug)) ?: 'brand' . $r->id;
        if (! preg_match('/^[a-z]/', $flavor)) { $flavor = 'b' . $flavor; }
        return [
            'kit_version' => 1,
            'flavor' => $flavor,
            'app_id' => $app['application_id'],
            'name' => $t['name'],
            'tenant' => $r->slug,
            'icon_url' => $t['logo_square_url'],
            'splash_color' => $t['splash_bg'] ?: '#FFFFFF',
            'icon_bg' => '#FFFFFF',
            'primary_color' => $t['primary_color'],
            'version_code' => $app['version_code'],
            'version_name' => $app['version_name'],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** GET /platform/resellers/{id}/app */
    public function app(Request $request, int $id): JsonResponse
    {
        abort_unless($this->isPlatform($request), 403);
        $r = DB::table('resellers')->find($id);
        abort_unless($r, 404);
        $app = $this->appOf($r);
        $t = Brand::forReseller((int) $r->id);
        $kit = $this->kitFor($r, $app);
        $checks = [
            ['done' => (bool) $app['application_id'], 'text' => 'Choose the package id (permanent once the app is published), e.g. com.' . preg_replace('/[^a-z0-9]/', '', strtolower($r->slug)) . '.app'],
            ['done' => ! str_contains((string) $t['logo_square_url'], 'app.kiddietrac.com/icon-512'), 'text' => 'Upload a square logo on the Brand tab (it becomes the app icon)'],
            ['done' => (bool) $app['firebase_ready'], 'text' => 'Firebase: add an Android app for the package and place google-services.json in android/app/src/' . $kit['flavor'] . '/ (needed for notifications)'],
            ['done' => in_array($app['status'], ['built', 'published'], true), 'text' => 'Build: node tools/build-from-kit.mjs reseller-' . $r->slug . '.json (signs with the KiddieTrac release key)'],
            ['done' => (bool) $app['play_listing_url'], 'text' => 'Google Play: create the listing under the reseller\'s or KiddieTrac\'s developer account, upload the AAB, add the store text and screenshots'],
            ['done' => false, 'text' => 'Passkeys: add the package and signing fingerprint to /.well-known/assetlinks.json (ask KiddieTrac support)'],
        ];
        return response()->json(['app' => $app, 'kit' => $kit, 'checklist' => $checks]);
    }

    /** PUT /platform/resellers/{id}/app */
    public function saveApp(Request $request, int $id): JsonResponse
    {
        abort_unless($this->isPlatform($request), 403);
        $r = DB::table('resellers')->find($id);
        abort_unless($r, 404);
        $data = $request->validate([
            'application_id' => ['nullable', 'string', 'max:120', 'regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/'],
            'version_code' => 'nullable|integer|min:1|max:2100000000',
            'version_name' => 'nullable|string|max:20|regex:/^[0-9A-Za-z.\-]+$/',
            'firebase_ready' => 'nullable|boolean',
            'play_listing_url' => 'nullable|url|max:300',
            'status' => 'nullable|in:draft,kit_downloaded,built,published',
        ]);
        if (! empty($data['application_id']) && $data['application_id'] === 'com.kiddietrac.app') {
            return response()->json(['message' => 'That is the KiddieTrac app\'s own package id; a reseller app needs its own.'], 422);
        }
        $app = $this->appOf($r);
        $was = $app;
        foreach ($data as $k => $v) {
            if ($v !== null || in_array($k, ['play_listing_url'], true)) { $app[$k] = $v; }
        }
        if (($data['status'] ?? null) === 'built') { $app['built_at'] = now()->toIso8601String(); }
        DB::table('resellers')->where('id', $id)->update(['app_build' => json_encode($app, JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
        $this->audit($request, 'reseller.app_saved', $id, ['summary' => 'Changed the ' . $r->name . ' app build settings', 'was' => $was, 'now' => $app]);
        return $this->app($request, $id);
    }

    /** POST /platform/resellers/{id}/app/kit — the build kit as a file, and the status moves on. */
    public function kit(Request $request, int $id)
    {
        abort_unless($this->isPlatform($request), 403);
        $r = DB::table('resellers')->find($id);
        abort_unless($r, 404);
        $app = $this->appOf($r);
        if (empty($app['application_id'])) {
            return response()->json(['message' => 'Choose the package id first.'], 422);
        }
        $kit = $this->kitFor($r, $app);
        $app['kit_at'] = now()->toIso8601String();
        if ($app['status'] === 'draft') { $app['status'] = 'kit_downloaded'; }
        DB::table('resellers')->where('id', $id)->update(['app_build' => json_encode($app, JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
        $this->audit($request, 'reseller.app_kit', $id, ['summary' => 'Downloaded the ' . $r->name . ' app build kit (' . $kit['app_id'] . ')']);
        return response()->json($kit, 200, ['Content-Disposition' => 'attachment; filename="reseller-' . $r->slug . '.json"']);
    }

    // ── Reseller overview (2026-10-21, phase B) ────────────────────────────────
    /* Every agency and centre the reseller brought to the platform, with the numbers that
       say whether each is healthy: enrolment against capacity, who is here today, staff on
       the floor, families, open tickets, the last time anyone did anything, and what
       KiddieTrac bills it. Scoped to agencies.reseller_id - nothing else is ever read. */

    /** The reseller this caller looks at: their own, or (platform admin) ?reseller_id / the first. */
    private function overviewReseller(Request $request): ?object
    {
        if ($this->isPlatform($request)) {
            $id = (int) $request->input('reseller_id', 0);
            return $id ? DB::table('resellers')->find($id) : DB::table('resellers')->orderBy('id')->first();
        }
        return $this->resellerFor($request);
    }

    /** GET /reseller/overview */
    public function overview(Request $request): JsonResponse
    {
        $r = $this->overviewReseller($request);
        abort_unless($r, 403, 'You do not manage a reseller.');
        $agencies = DB::table('agencies')->where('reseller_id', $r->id)->whereNull('deleted_at')->orderBy('name')
            ->get(['id', 'name', 'slug', 'cancelled_at', 'plan_code', 'plan_amount_cents', 'plan_currency', 'billing_interval', 'billing_status', 'contact_email', 'created_at']);
        $aids = $agencies->pluck('id')->map(fn ($v) => (int) $v)->all();
        $centres = $aids ? \App\Support\Live::centres(DB::table('centres'))->whereIn('agency_id', $aids)
            ->orderBy('name')->get(['id', 'agency_id', 'name', 'city', 'license_capacity']) : collect();
        $cids = $centres->pluck('id')->map(fn ($v) => (int) $v)->all() ?: [0];

        $enrolled = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->whereIn('f.centre_id', $cids)->where('ch.enrollment_status', 'enrolled')->whereNull('ch.deleted_at')
            ->groupBy('f.centre_id')->selectRaw('f.centre_id as cid, COUNT(*) as n')->pluck('n', 'cid');
        $families = DB::table('families')->whereIn('centre_id', $cids)->whereNull('deleted_at')
            ->groupBy('centre_id')->selectRaw('centre_id as cid, COUNT(*) as n')->pluck('n', 'cid');

        // Here now: each child's last sign-in/out today (the agency's own day).
        $present = [];
        foreach ($agencies as $a) {
            $tz = \App\Support\AgencyTime::tz((int) $a->id);
            $from = \Carbon\Carbon::now($tz)->startOfDay()->utc();
            $to = \Carbon\Carbon::now($tz)->endOfDay()->utc();
            $acids = $centres->where('agency_id', $a->id)->pluck('id')->all() ?: [0];
            $last = [];
            foreach (DB::table('check_events as ce')->join('rooms as rm', 'rm.id', '=', 'ce.room_id')
                ->whereIn('rm.centre_id', $acids)->whereIn('ce.event_type', ['check_in', 'check_out'])
                ->whereBetween('ce.occurred_at', [$from, $to])->orderBy('ce.occurred_at')
                ->get(['rm.centre_id', 'ce.child_id', 'ce.event_type']) as $e) {
                $last[$e->centre_id][$e->child_id] = $e->event_type;
            }
            foreach ($last as $cid => $kids) {
                $present[$cid] = count(array_filter($kids, fn ($t) => $t === 'check_in'));
            }
        }
        // The agency dashboard's own definition (AgencyController::dashboard): an OPEN clock-in
        // in time_entries, or in time_punches within 20h - one person counted once per centre.
        $onFloor = [];
        foreach (DB::table('time_entries')->whereIn('centre_id', $cids)->whereNull('clocked_out_at')->get(['centre_id', 'user_id']) as $s) {
            $onFloor[$s->centre_id][$s->user_id] = true;
        }
        foreach (DB::table('time_punches')->whereIn('centre_id', $cids)->whereNull('punched_out_at')
            ->where('punched_in_at', '>=', now()->subHours(20))->get(['centre_id', 'user_id']) as $s) {
            $onFloor[$s->centre_id][$s->user_id] = true;
        }
        $staff = array_map('count', $onFloor);
        $tickets = $aids ? DB::table('support_tickets')->whereIn('agency_id', $aids)->whereIn('status', ['open', 'awaiting_user'])
            ->groupBy('agency_id')->selectRaw('agency_id as aid, COUNT(*) as n')->pluck('n', 'aid') : collect();
        $lastAct = $aids ? DB::table('audit_logs')->whereIn('agency_id', $aids)->where('created_at', '>=', now()->subDays(60))
            ->groupBy('agency_id')->selectRaw('agency_id as aid, MAX(created_at) as at')->pluck('at', 'aid') : collect();
        $overdue = $aids ? DB::table('platform_invoices')->whereIn('agency_id', $aids)->whereNotIn('status', ['paid', 'void', 'draft'])
            ->where('due_at', '<', now())->groupBy('agency_id')->selectRaw('agency_id as aid, COUNT(*) as n, SUM(amount_cents - COALESCE(amount_paid_cents,0)) as owed')
            ->get()->keyBy('aid') : collect();
        // Agencies with no admin of their own (a reseller's grant does not count).
        $withAdmin = $aids ? DB::table('role_assignments')->whereIn('agency_id', $aids)->where('role', 'agency_admin')
            ->where('active', 1)->whereNull('granted_by_reseller_id')->distinct()->pluck('agency_id')->map(fn ($v) => (int) $v)->all() : [];

        $tot = ['agencies' => 0, 'centres' => 0, 'enrolled' => 0, 'capacity' => 0, 'present' => 0, 'staff' => 0, 'families' => 0, 'open_tickets' => 0, 'mrr_cents' => 0, 'flags' => 0];
        $out = [];
        foreach ($agencies as $a) {
            $rows = [];
            $ag = ['enrolled' => 0, 'capacity' => 0, 'present' => 0, 'staff' => 0, 'families' => 0];
            foreach ($centres->where('agency_id', $a->id) as $c) {
                $row = [
                    'id' => (int) $c->id, 'name' => $c->name, 'city' => $c->city,
                    'enrolled' => (int) ($enrolled[$c->id] ?? 0), 'capacity' => (int) ($c->license_capacity ?? 0),
                    'present' => (int) ($present[$c->id] ?? 0), 'staff' => (int) ($staff[$c->id] ?? 0),
                    'families' => (int) ($families[$c->id] ?? 0),
                ];
                $row['attendance_pct'] = $row['enrolled'] ? (int) round($row['present'] / $row['enrolled'] * 100) : null;
                foreach ($ag as $k => $v) { $ag[$k] += $row[$k]; }
                $rows[] = $row;
            }
            $flags = [];
            $la = $lastAct[$a->id] ?? null;
            if (! $la || \Carbon\Carbon::parse($la)->lt(now()->subDays(7))) $flags[] = ['level' => 'warn', 'text' => 'No activity for 7+ days'];
            if (isset($overdue[$a->id])) $flags[] = ['level' => 'bad', 'text' => $overdue[$a->id]->n . ' overdue plan invoice' . ($overdue[$a->id]->n == 1 ? '' : 's')];
            if (! in_array((int) $a->id, $withAdmin, true)) $flags[] = ['level' => 'warn', 'text' => 'No agency admin of its own'];
            if (! $rows) $flags[] = ['level' => 'warn', 'text' => 'No centres yet'];
            elseif ($ag['enrolled'] === 0) $flags[] = ['level' => 'warn', 'text' => 'No children enrolled'];
            $presentPeople = $ag['present'];
            if ($presentPeople > 0 && $ag['staff'] === 0) $flags[] = ['level' => 'bad', 'text' => 'Children present with no staff clocked in'];
            if ($ag['capacity'] > 0 && $ag['enrolled'] >= $ag['capacity'] * 0.95) $flags[] = ['level' => 'info', 'text' => 'At or near capacity'];
            $mrr = ($a->billing_interval ?? 'month') === 'year' ? (int) round(($a->plan_amount_cents ?? 0) / 12) : (int) ($a->plan_amount_cents ?? 0);
            $out[] = [
                'id' => (int) $a->id, 'name' => $a->name, 'slug' => $a->slug, 'status' => $a->cancelled_at ? 'cancelled' : 'active',
                'joined' => $a->created_at, 'contact_email' => $a->contact_email,
                'centres' => $rows, 'centre_count' => count($rows),
                'enrolled' => $ag['enrolled'], 'capacity' => $ag['capacity'], 'present' => $ag['present'], 'staff' => $ag['staff'],
                'families' => $ag['families'],
                'attendance_pct' => $ag['enrolled'] ? (int) round($ag['present'] / $ag['enrolled'] * 100) : null,
                'open_tickets' => (int) ($tickets[$a->id] ?? 0),
                'last_activity' => $la,
                'plan' => ['code' => $a->plan_code, 'amount_cents' => (int) ($a->plan_amount_cents ?? 0),
                    'currency' => $a->plan_currency ?: 'CAD', 'interval' => $a->billing_interval ?: 'month',
                    'billing_status' => $a->billing_status, 'overdue_cents' => (int) ($overdue[$a->id]->owed ?? 0)],
                'flags' => $flags,
            ];
            $tot['agencies']++; $tot['centres'] += count($rows);
            foreach (['enrolled', 'capacity', 'present', 'staff', 'families'] as $k) { $tot[$k] += $ag[$k]; }
            $tot['open_tickets'] += (int) ($tickets[$a->id] ?? 0);
            $tot['mrr_cents'] += $mrr;
            $tot['flags'] += count(array_filter($flags, fn ($f) => $f['level'] !== 'info'));
        }
        $tot['attendance_pct'] = $tot['enrolled'] ? (int) round($tot['present'] / $tot['enrolled'] * 100) : null;

        return response()->json([
            'reseller' => ['id' => (int) $r->id, 'name' => $r->name, 'slug' => $r->slug],
            'totals' => $tot,
            'agencies' => $out,
            'resellers' => $this->isPlatform($request) ? DB::table('resellers')->orderBy('name')->get(['id', 'name']) : null,
        ]);
    }

    // ── Reseller phase C (2026-10-21): onboard agencies, manage their admins ─────
    /* A reseller adds agencies under itself and looks after each one's own admins.
       Everything is checked against agencies.reseller_id here, whatever header is sent. */

    /** An agency this caller may manage: one of its reseller's, or (platform) any reseller's. */
    private function ownedAgency(Request $request, int $agencyId): object
    {
        $q = DB::table('agencies')->where('id', $agencyId)->whereNull('deleted_at');
        if ($this->isPlatform($request)) {
            $q->whereNotNull('reseller_id');
        } else {
            $r = $this->resellerFor($request);
            abort_unless($r, 403, 'You do not manage a reseller.');
            $q->where('reseller_id', $r->id);
        }
        $a = $q->first();
        abort_unless($a, 403, 'That agency is not one of yours.');
        return $a;
    }

    private function agencyAudit(Request $request, int $agencyId, string $action, ?int $userId, array $payload): void
    {
        try {
            \App\Support\Audit::write([
                'user_id' => $request->user()->id, 'agency_id' => $agencyId, 'action' => $action,
                'entity_type' => 'user', 'entity_id' => $userId,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip_address' => $request->ip(), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    /** A fresh 7-day set-password link, emailed through the platform's own invite. */
    private function inviteAdmin(object $agency, object $user): void
    {
        $token = bin2hex(random_bytes(32));
        DB::table('password_resets')->insert([
            'email' => $user->email, 'user_id' => $user->id, 'token' => hash('sha256', $token),
            'expires_at' => now()->addDays(7), 'created_at' => now(),
        ]);
        app(PlatformController::class)->sendAdminInvite([
            'admin_first_name' => $user->first_name, 'admin_last_name' => $user->last_name,
            'admin_email' => $user->email, 'name' => $agency->name,
        ], 'https://app.kiddietrac.com/set-password.html?token=' . $token);
    }

    /** POST /reseller/agencies - the platform's own provisioning, under this reseller.
        The plan's price is the platform's list price; a reseller cannot set it. */
    public function createAgency(Request $request): JsonResponse
    {
        $r = $this->resellerFor($request);
        abort_unless($r, 403, $this->isPlatform($request) ? 'Choose the reseller first.' : 'You do not manage a reseller.');
        $request->merge(['plan_amount_cents' => null, 'slug' => null]);
        $request->attributes->set('kt_reseller_id', (int) $r->id);
        $res = app(PlatformController::class)->createAgency($request);
        if ($res->getStatusCode() !== 201) {
            return $res;
        }
        $out = $res->getData(true);
        $aid = (int) ($out['agency']['id'] ?? 0);
        Brand::forget();
        \App\Support\ResellerAccess::syncReseller((int) $r->id);
        $this->audit($request, 'reseller.agency_created', (int) $r->id, ['agency_id' => $aid, 'summary' => $out['agency']['name'] ?? '']);
        $this->agencyAudit($request, $aid, 'reseller.agency_created', (int) ($out['admin_user_id'] ?? 0) ?: null,
            ['summary' => ($out['agency']['name'] ?? '') . ' added by ' . $r->name, 'reseller_id' => (int) $r->id]);
        // The admin's set-password link is theirs alone - only the platform sees it.
        if (! $this->isPlatform($request)) {
            unset($out['invite_link']);
        }
        $out['message'] = 'Agency added. ' . ($request->input('admin_first_name') ?: 'The admin') . ' has been emailed an invite to set a password.';
        return response()->json($out, 201);
    }

    /** GET /reseller/agencies/{agencyId}/admins - the agency's own admins (a reseller's grant is not listed). */
    public function agencyAdmins(Request $request, int $agencyId): JsonResponse
    {
        $a = $this->ownedAgency($request, $agencyId);
        $rows = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.agency_id', $a->id)->where('ra.role', 'agency_admin')->whereNull('ra.granted_by_reseller_id')
            ->whereNull('u.deleted_at')
            ->groupBy('u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.phone', 'u.status', 'u.last_login_at', 'u.two_factor_enabled')
            ->selectRaw('u.id, u.first_name, u.last_name, u.email, u.phone, u.status, u.last_login_at, u.two_factor_enabled, MAX(ra.active) as active')
            ->orderByDesc('active')->orderBy('u.first_name')->get()
            ->map(fn ($u) => [
                'id' => (int) $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name), 'email' => $u->email, 'phone' => $u->phone,
                'status' => $u->status, 'active' => (bool) $u->active, 'last_login_at' => $u->last_login_at,
                'two_factor' => (bool) $u->two_factor_enabled,
            ]);
        return response()->json(['agency' => ['id' => (int) $a->id, 'name' => $a->name], 'admins' => $rows]);
    }

    /** POST /reseller/agencies/{agencyId}/admins - invite another admin for the agency. */
    public function inviteAgencyAdmin(Request $request, int $agencyId): JsonResponse
    {
        $a = $this->ownedAgency($request, $agencyId);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:180', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
        ], ['email.unique' => 'Someone already has an account with that email.']);
        $uid = DB::transaction(function () use ($a, $data) {
            $uid = DB::table('users')->insertGetId([
                'email' => $data['email'], 'password' => \Illuminate\Support\Facades\Hash::make(Str::random(32)),
                'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'phone' => $data['phone'] ?? null,
                'status' => 'invited', 'locale' => 'en-CA', 'timezone' => $a->timezone ?: 'America/Toronto',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('role_assignments')->insert(['user_id' => $uid, 'role' => 'agency_admin', 'agency_id' => $a->id,
                'centre_id' => null, 'active' => 1, 'created_at' => now()]);
            return $uid;
        });
        $u = DB::table('users')->where('id', $uid)->first();
        $this->inviteAdmin($a, $u);
        $this->agencyAudit($request, (int) $a->id, 'reseller.agency_admin_invited', $uid, ['summary' => trim($u->first_name . ' ' . $u->last_name), 'to' => $u->email]);
        return response()->json(['ok' => true, 'id' => $uid, 'message' => 'Invite sent to ' . $u->email . '.'], 201);
    }

    /** POST /reseller/agencies/{agencyId}/admins/{userId}/{action} - deactivate | reactivate | reset */
    public function agencyAdminAction(Request $request, int $agencyId, int $userId, string $action): JsonResponse
    {
        $a = $this->ownedAgency($request, $agencyId);
        $rows = DB::table('role_assignments')->where('user_id', $userId)->where('agency_id', $a->id)
            ->where('role', 'agency_admin')->whereNull('granted_by_reseller_id');
        abort_unless((clone $rows)->exists(), 404, 'That person is not an admin of this agency.');
        abort_if($userId === (int) $request->user()->id, 422, 'You cannot change your own access here.');
        $u = DB::table('users')->where('id', $userId)->whereNull('deleted_at')->first();
        abort_unless($u, 404, 'That person is not an admin of this agency.');
        $name = trim($u->first_name . ' ' . $u->last_name);

        if ($action === 'deactivate') {
            (clone $rows)->update(['active' => 0]);
            // Nothing left anywhere: their open sessions end now.
            if (! DB::table('role_assignments')->where('user_id', $userId)->where('active', true)->exists()) {
                DB::table('personal_access_tokens')->where('tokenable_type', \App\Models\User::class)->where('tokenable_id', $userId)->delete();
            }
            $msg = $name . ' can no longer manage ' . $a->name . '.';
        } elseif ($action === 'reactivate') {
            (clone $rows)->update(['active' => 1]);
            $msg = $name . ' can manage ' . $a->name . ' again.';
        } elseif ($action === 'reset') {
            abort_unless((clone $rows)->where('active', true)->exists(), 422, 'Reactivate them first.');
            $this->inviteAdmin($a, $u);
            $msg = 'A new set-password link was emailed to ' . $u->email . '.';
        } else {
            abort(404);
        }
        $this->agencyAudit($request, (int) $a->id, 'reseller.agency_admin_' . $action, $userId, ['summary' => $name]);
        return response()->json(['ok' => true, 'message' => $msg]);
    }

    // ── Reseller phase D (2026-10-21): billing & commissions ────────────────────
    /* What the platform bills each of the reseller's agencies in a month, what was
       collected, what is still owed, and the reseller's commission on it:
         commission = commission_pct % of the PRE-TAX part of money collected that month
                      + commission_flat_cents for each agency that paid that month.
       Months are calendar months in Eastern time - the platform's billing clock. */

    /** GET /reseller/statement?month=YYYY-MM[&format=csv] */
    public function statement(Request $request)
    {
        $r = $this->overviewReseller($request);
        abort_unless($r, 403, 'You do not manage a reseller.');
        $tz = 'America/Toronto';
        $month = (string) $request->input('month', '');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = \Carbon\Carbon::now($tz)->format('Y-m');
        }
        $from = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $month . '-01 00:00:00', $tz);
        $to = (clone $from)->addMonth();
        [$fromUtc, $toUtc] = [(clone $from)->utc(), (clone $to)->utc()];
        $pct = (float) ($r->commission_pct ?? 0);
        $flat = (int) ($r->commission_flat_cents ?? 0);

        $agencies = DB::table('agencies')->where('reseller_id', $r->id)->whereNull('deleted_at')->orderBy('name')
            ->get(['id', 'name', 'plan_code', 'plan_amount_cents', 'plan_currency', 'billing_interval', 'billing_status']);
        $aids = $agencies->pluck('id')->all() ?: [0];
        $inv = DB::table('platform_invoices')->whereIn('agency_id', $aids)->whereNotIn('status', ['void', 'draft'])
            ->where(function ($q) use ($fromUtc, $toUtc) {
                $q->whereBetween('issued_at', [$fromUtc, $toUtc])->orWhereBetween('paid_at', [$fromUtc, $toUtc])
                  ->orWhereNotIn('status', ['paid']);
            })
            ->orderBy('issued_at')
            ->get(['id', 'agency_id', 'number', 'period_start', 'period_end', 'plan_code', 'amount_cents', 'subtotal_cents', 'tax_cents',
                'amount_paid_cents', 'currency', 'status', 'issued_at', 'due_at', 'paid_at']);
        $in = fn ($at) => $at && \Carbon\Carbon::parse($at, 'UTC')->gte($fromUtc) && \Carbon\Carbon::parse($at, 'UTC')->lt($toUtc);

        $tot = ['billed_cents' => 0, 'billed_pretax_cents' => 0, 'collected_cents' => 0, 'collected_pretax_cents' => 0,
            'outstanding_cents' => 0, 'commission_cents' => 0, 'flat_cents' => 0, 'agencies_paid' => 0];
        $rows = [];
        foreach ($agencies as $a) {
            $mine = $inv->where('agency_id', $a->id);
            $row = ['billed_cents' => 0, 'billed_pretax_cents' => 0, 'collected_cents' => 0, 'collected_pretax_cents' => 0, 'outstanding_cents' => 0];
            $lines = [];
            foreach ($mine as $i) {
                $amount = (int) $i->amount_cents;
                $sub = $i->subtotal_cents !== null ? (int) $i->subtotal_cents : $amount;
                $paid = (int) ($i->amount_paid_cents ?? 0);
                $issuedIn = $in($i->issued_at);
                $paidIn = $i->status === 'paid' && $in($i->paid_at);
                $open = ! in_array($i->status, ['paid', 'void', 'draft'], true);
                if (! $issuedIn && ! $paidIn && ! $open) continue;
                if ($issuedIn) { $row['billed_cents'] += $amount; $row['billed_pretax_cents'] += $sub; }
                if ($paidIn) {
                    $row['collected_cents'] += $paid;
                    $row['collected_pretax_cents'] += $amount > 0 ? (int) round($paid * $sub / $amount) : 0;
                }
                if ($open) $row['outstanding_cents'] += max(0, $amount - $paid);
                $lines[] = ['number' => $i->number, 'period' => trim(($i->period_start ?? '') . ' – ' . ($i->period_end ?? ''), ' –'),
                    'issued_at' => $i->issued_at, 'due_at' => $i->due_at, 'paid_at' => $i->paid_at, 'status' => $i->status,
                    'amount_cents' => $amount, 'subtotal_cents' => $sub, 'tax_cents' => (int) ($i->tax_cents ?? 0), 'paid_cents' => $paid,
                    'currency' => $i->currency ?: ($a->plan_currency ?: 'CAD'),
                    'counts' => $paidIn ? 'collected' : ($issuedIn ? 'billed' : 'outstanding')];
            }
            $paidThisMonth = $row['collected_cents'] > 0;
            $row['commission_cents'] = (int) round($row['collected_pretax_cents'] * $pct / 100) + ($paidThisMonth ? $flat : 0);
            $rows[] = ['id' => (int) $a->id, 'name' => $a->name, 'currency' => $a->plan_currency ?: 'CAD',
                'plan' => ['code' => $a->plan_code, 'amount_cents' => (int) ($a->plan_amount_cents ?? 0), 'interval' => $a->billing_interval ?: 'month', 'billing_status' => $a->billing_status],
                'invoices' => $lines] + $row + ['flat_cents' => $paidThisMonth ? $flat : 0];
            foreach (['billed_cents', 'billed_pretax_cents', 'collected_cents', 'collected_pretax_cents', 'outstanding_cents', 'commission_cents'] as $k) {
                $tot[$k] += $row[$k];
            }
            if ($paidThisMonth) { $tot['agencies_paid']++; $tot['flat_cents'] += $flat; }
        }

        if ($request->input('format') === 'csv') {
            $this->audit($request, 'reseller.statement_exported', (int) $r->id, ['summary' => 'Downloaded the ' . $month . ' statement for ' . $r->name]);
            $m = fn ($c) => number_format($c / 100, 2, '.', '');
            $out = fopen('php://temp', 'w+');
            fputcsv($out, ['Statement', $r->name, $month, 'Commission ' . rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . '% of pre-tax money collected' . ($flat ? ' + ' . $m($flat) . ' per agency that paid' : '')]);
            fputcsv($out, []);
            fputcsv($out, ['Agency', 'Plan', 'Currency', 'Billed', 'Billed before tax', 'Collected', 'Collected before tax', 'Outstanding', 'Commission']);
            foreach ($rows as $x) {
                fputcsv($out, [$x['name'], $x['plan']['code'], $x['currency'], $m($x['billed_cents']), $m($x['billed_pretax_cents']), $m($x['collected_cents']),
                    $m($x['collected_pretax_cents']), $m($x['outstanding_cents']), $m($x['commission_cents'])]);
            }
            fputcsv($out, ['Total', '', '', $m($tot['billed_cents']), $m($tot['billed_pretax_cents']), $m($tot['collected_cents']),
                $m($tot['collected_pretax_cents']), $m($tot['outstanding_cents']), $m($tot['commission_cents'])]);
            fputcsv($out, []);
            fputcsv($out, ['Agency', 'Invoice', 'Period', 'Issued', 'Due', 'Paid', 'Status', 'Before tax', 'Tax', 'Total', 'Paid amount', 'Counts as']);
            foreach ($rows as $x) {
                foreach ($x['invoices'] as $l) {
                    fputcsv($out, [$x['name'], $l['number'], $l['period'], substr((string) $l['issued_at'], 0, 10), substr((string) $l['due_at'], 0, 10),
                        substr((string) $l['paid_at'], 0, 10), $l['status'], $m($l['subtotal_cents']), $m($l['tax_cents']), $m($l['amount_cents']), $m($l['paid_cents']), $l['counts']]);
                }
            }
            rewind($out);
            $csv = stream_get_contents($out);
            fclose($out);
            return response("\xEF\xBB\xBF" . $csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="statement-' . $r->slug . '-' . $month . '.csv"',
            ]);
        }

        $months = [];
        $c = \Carbon\Carbon::now($tz)->startOfMonth();
        for ($k = 0; $k < 12; $k++) { $months[] = $c->format('Y-m'); $c->subMonth(); }
        return response()->json([
            'reseller' => ['id' => (int) $r->id, 'name' => $r->name, 'commission_pct' => $pct, 'commission_flat_cents' => $flat],
            'month' => $month, 'months' => $months, 'totals' => $tot, 'agencies' => $rows,
            'resellers' => $this->isPlatform($request) ? DB::table('resellers')->orderBy('name')->get(['id', 'name']) : null,
        ]);
    }

    // ── Reseller phase E (2026-10-21): support desk ─────────────────────────────
    /** GET /reseller/tickets[?status=open|closed|all] - every ticket of the reseller's agencies.
        Platform-team tickets show their status only (SupportTicketController enforces it too). */
    public function tickets(Request $request): JsonResponse
    {
        $r = $this->overviewReseller($request);
        abort_unless($r, 403, 'You do not manage a reseller.');
        $agencies = DB::table('agencies')->where('reseller_id', $r->id)->whereNull('deleted_at')->pluck('name', 'id');
        $q = DB::table('support_tickets as t')->leftJoin('users as u', 'u.id', '=', 't.raised_by_user_id')
            ->whereIn('t.agency_id', $agencies->keys()->all() ?: [0])->orderByDesc('t.updated_at');
        $st = (string) $request->input('status', 'open');
        if ($st === 'open') { $q->whereIn('t.status', ['open', 'awaiting_user']); }
        elseif ($st === 'closed') { $q->whereIn('t.status', ['resolved', 'closed']); }
        $rows = $q->limit(300)->get(['t.id', 't.agency_id', 't.category', 't.priority', 't.subject', 't.status', 't.created_at', 't.updated_at',
            DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as raised_by")]);
        $escalated = $rows->isEmpty() ? [] : array_flip(DB::table('support_ticket_events')->whereIn('ticket_id', $rows->pluck('id')->all())
            ->where('type', 'escalated')->distinct()->pluck('ticket_id')->map(fn ($v) => (int) $v)->all());
        $hq = \App\Http\Controllers\Api\SupportTicketController::HQ_CATEGORIES;
        return response()->json([
            'reseller' => ['id' => (int) $r->id, 'name' => $r->name],
            'agencies' => $agencies->map(fn ($n, $id) => ['id' => (int) $id, 'name' => $n])->values(),
            'tickets' => $rows->map(fn ($t) => [
                'id' => (int) $t->id, 'agency_id' => (int) $t->agency_id, 'agency' => $agencies[$t->agency_id] ?? '', 'subject' => $t->subject,
                'category' => $t->category, 'priority' => $t->priority, 'status' => $t->status, 'raised_by' => $t->raised_by,
                'created_at' => $t->created_at, 'updated_at' => $t->updated_at,
                'with_platform' => in_array((string) $t->category, $hq, true), 'escalated' => isset($escalated[(int) $t->id]),
            ])->values(),
            'resellers' => $this->isPlatform($request) ? DB::table('resellers')->orderBy('name')->get(['id', 'name']) : null,
        ]);
    }

    // ── Reseller panel (reseller admin, or a platform admin naming reseller_id) ──

    /** GET /reseller/brand */
    public function brand(Request $request): JsonResponse
    {
        $r = $this->resellerFor($request);
        abort_unless($r, 403, 'You do not manage a reseller.');
        return response()->json(['reseller' => $this->present($r)]);
    }

    /** PUT /reseller/brand {brand{...}, hide_kiddietrac?, allow_agency_override?} */
    public function saveBrand(Request $request): JsonResponse
    {
        $r = $this->resellerFor($request);
        abort_unless($r, 403, 'You do not manage a reseller.');
        $hex = 'nullable|regex:/^#[0-9A-Fa-f]{6}$/';
        $url = 'nullable|string|max:500';
        $data = $request->validate([
            'brand' => 'required|array',
            'brand.app_name' => 'nullable|string|max:60',
            'brand.support_email' => 'nullable|email|max:160',
            'brand.primary_color' => $hex, 'brand.accent_color' => $hex, 'brand.splash_bg' => $hex,
            'brand.logo_square_url' => $url, 'brand.logo_wordmark_url' => $url,
            'brand.splash_logo_url' => $url, 'brand.favicon_url' => $url,
            'brand.hero_from' => $hex, 'brand.hero_via' => $hex, 'brand.hero_to' => $hex,
            'brand.hero_angle' => 'nullable|integer|min:0|max:360',
            'hide_kiddietrac' => 'nullable|boolean',
            'allow_agency_override' => 'nullable|boolean',
        ]);
        $was = json_decode((string) $r->brand, true) ?: [];
        $brand = [];
        foreach (Brand::FIELDS as $k) {
            $v = trim((string) ($data['brand'][$k] ?? ''));
            if ($v !== '') { $brand[$k] = $v; }
        }
        $upd = ['brand' => json_encode($brand, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updated_at' => now()];
        // Licence-level switches belong to whoever sold the licence: platform admins only.
        if ($this->isPlatform($request)) {
            foreach (['hide_kiddietrac', 'allow_agency_override'] as $k) {
                if (array_key_exists($k, $data) && $data[$k] !== null) { $upd[$k] = (bool) $data[$k]; }
            }
        } elseif (array_key_exists('allow_agency_override', $data) && $data['allow_agency_override'] !== null) {
            // A reseller decides whether its own agencies may brand themselves.
            $upd['allow_agency_override'] = (bool) $data['allow_agency_override'];
        }
        DB::table('resellers')->where('id', $r->id)->update($upd);
        Brand::forget();
        $changed = [];
        foreach (array_unique(array_merge(array_keys($was), array_keys($brand))) as $k) {
            if (($was[$k] ?? null) !== ($brand[$k] ?? null)) { $changed[] = $k; }
        }
        $this->audit($request, 'reseller.brand_saved', (int) $r->id, ['summary' => 'Changed the ' . $r->name . ' brand'
            . ($changed ? ': ' . implode(', ', str_replace('_', ' ', $changed)) : ''), 'was' => $was, 'now' => $brand]);
        return response()->json(['reseller' => $this->present(DB::table('resellers')->find($r->id))]);
    }

    /** POST /reseller/brand/upload {kind, file} — kind: logo_square|logo_wordmark|splash_logo|favicon */
    public function upload(Request $request): JsonResponse
    {
        $r = $this->resellerFor($request);
        abort_unless($r, 403, 'You do not manage a reseller.');
        $data = $request->validate([
            'kind' => 'required|in:logo_square,logo_wordmark,splash_logo,favicon',
            'file' => 'required|file|max:4096|mimes:png,jpg,jpeg,webp,svg,ico',
        ]);
        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        $name = 'reseller-' . $r->id . '-' . $data['kind'] . '-' . Str::random(8) . '.' . $ext;
        Storage::disk('public')->putFileAs('branding', $file, $name);
        $url = '/storage/branding/' . $name;
        $brand = json_decode((string) $r->brand, true) ?: [];
        $brand[$data['kind'] . '_url'] = $url;
        DB::table('resellers')->where('id', $r->id)->update(['brand' => json_encode($brand, JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
        Brand::forget();
        $this->audit($request, 'reseller.logo_uploaded', (int) $r->id, ['summary' => 'Uploaded a new ' . str_replace('_', ' ', $data['kind'])
            . ' for ' . $r->name, 'url' => $url]);
        return response()->json(['url' => $url, 'reseller' => $this->present(DB::table('resellers')->find($r->id))]);
    }
}
