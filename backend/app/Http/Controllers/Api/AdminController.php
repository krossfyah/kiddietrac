<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin controller: agency-admin level CRUD for centres, users, families, children.
 * All endpoints require role:agency_admin middleware.
 * All queries scope to the agency_admin's own agency_id.
 */
final class AdminController extends Controller
{
    // ════════════════════════════════════════════════════════════════
    //   Helpers
    // ════════════════════════════════════════════════════════════════

    private function getAgencyId(Request $request): ?int
    {
        // v22p20: honor X-Active-Agency-Id header for multi-agency admins.
        // v22p21: platform_admin trumps tenant scope — they can target ANY agency.
        $isPlatformAdmin = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')
            ->where('active', true)
            ->exists();
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($isPlatformAdmin) {
            // SECURITY (v22p98): a platform_admin must EXPLICITLY select an agency.
            // The old "default to the first agency" silently returned iLearn's
            // centres/families/contacts to a super-admin whenever the active-agency
            // header was missing (fresh tab, race, or a call that bypasses app.js)
            // — e.g. the announcement composer's centre picker leaked iLearn. Return
            // null (→ "No agency access") instead of defaulting to a real tenant.
            return ($activeId && DB::table('agencies')->where('id', $activeId)->whereNull('deleted_at')->exists())
                ? $activeId : null;
        }
        if ($activeId && DB::table('role_assignments')
                ->where('user_id', $request->user()->id)
                ->where('role', 'agency_admin')
                ->where('agency_id', $activeId)
                ->where('active', true)
                ->exists()) {
            return $activeId;
        }
        return DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'agency_admin')
            ->where('active', true)
            ->value('agency_id');
    }

    private function getCentreIds(int $agencyId): array
    {
        return DB::table('centres')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();
    }

    /**
     * Persist a centre's geocoded map coordinates (called by the provider map
     * once it geocodes an address) so the map plots instantly next time — for
     * everyone, not just the browser that did the geocoding. Agency-scoped.
     */
    public function saveCentreCoords(Request $request, int $centre): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency access'], 403);
        $data = $request->validate([
            'latitude'  => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $updated = DB::table('centres')->where('id', $centre)->where('agency_id', $agencyId)
            ->update(['latitude' => $data['latitude'], 'longitude' => $data['longitude'], 'updated_at' => now()]);
        if (! $updated) return response()->json(['message' => 'Centre not in your agency'], 403);

        return response()->json(['ok' => true]);
    }

    private function audit(int $userId, string $action, ?string $entityType, ?int $entityId, array $payload = []): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $userId,
            'agency_id' => \App\Support\AuditScope::resolve($userId),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => json_encode($payload),
            'created_at' => now(),
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //   WHITE-LABEL EMAIL (per-agency own M365 / Google)
    // ════════════════════════════════════════════════════════════════

    /**
     * A white-label agency can send its own email from its OWN Microsoft 365
     * (Graph) or Google/Gmail (SMTP) instead of KiddieTrac's default sender.
     * Scoped by getAgencyId(), so an agency_admin manages only their own agency
     * and a platform_admin manages whichever agency they've selected.
     */
    /**
     * The agency whose mail config to manage. A platform_admin may target ANY
     * agency explicitly (agency_id — the agency Edit screen), while an
     * agency_admin is always locked to their own agency via getAgencyId().
     */
    private function mailTargetAgency(Request $request): ?int
    {
        $isPlatformAdmin = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatformAdmin) {
            $req = (int) $request->input('agency_id');
            if ($req && DB::table('agencies')->where('id', $req)->whereNull('deleted_at')->exists()) {
                return $req;
            }
        }
        return $this->getAgencyId($request);
    }

    public function agencyMailConfig(Request $request): JsonResponse
    {
        $agencyId = $this->mailTargetAgency($request);
        if (! $agencyId) return response()->json(['message' => 'No agency access'], 403);

        $agency = DB::table('agencies')->where('id', $agencyId)->first(['id', 'name']);

        return response()->json([
            'agency_id'   => $agencyId,
            'agency_name' => $agency->name ?? null,
            'config'      => \App\Support\AgencyMail::publicConfig($agencyId),
            'active'      => \App\Support\AgencyMail::configFor($agencyId) !== null,
        ]);
    }

    public function saveAgencyMailConfig(Request $request): JsonResponse
    {
        $agencyId = $this->mailTargetAgency($request);
        if (! $agencyId) return response()->json(['message' => 'No agency access'], 403);

        $data = $request->validate([
            'agency_id'           => 'nullable|integer',
            'provider'            => 'required|in:default,graph,google',
            'from'                => 'nullable|email|max:160',
            'from_name'           => 'nullable|string|max:120',
            'graph_tenant'        => 'nullable|string|max:120',
            'graph_client_id'     => 'nullable|string|max:120',
            'graph_client_secret' => 'nullable|string|max:400',   // only when (re)setting
            'google_username'     => 'nullable|email|max:160',
            'google_password'     => 'nullable|string|max:300',    // only when (re)setting
            'google_host'         => 'nullable|string|max:120',
            'google_port'         => 'nullable|integer|min:1|max:65535',
        ]);

        // Don't half-enable a provider: require its pieces (secret may already be set).
        $existing = \App\Support\AgencyMail::publicConfig($agencyId);
        if ($data['provider'] === 'graph') {
            $haveSecret = ! empty($data['graph_client_secret']) || ($existing['graph']['secret_set'] ?? false);
            if (empty($data['from']) || empty($data['graph_tenant']) || empty($data['graph_client_id']) || ! $haveSecret) {
                return response()->json(['message' => 'Microsoft 365 needs a from address, tenant, client ID and client secret.'], 422);
            }
        } elseif ($data['provider'] === 'google') {
            $havePass = ! empty($data['google_password']) || ($existing['google']['password_set'] ?? false);
            if (empty($data['from']) || empty($data['google_username']) || ! $havePass) {
                return response()->json(['message' => 'Google needs a from address, SMTP username and an app password.'], 422);
            }
        }

        \App\Support\AgencyMail::save($agencyId, $data);
        $this->audit($request->user()->id, 'agency.mail_config.updated', 'agency', $agencyId, ['provider' => $data['provider']]);

        $agency = DB::table('agencies')->where('id', $agencyId)->value('name');
        \App\Support\CriticalNotifier::send('White-label email settings updated', array_values(array_filter([
            'Agency: ' . ($agency ?: ('#' . $agencyId)),
            'Email provider set to: ' . ($data['provider'] === 'graph' ? 'their own Microsoft 365' : ($data['provider'] === 'google' ? 'their own Google/Gmail' : 'KiddieTrac default')),
            ! empty($data['from']) ? 'Sends from: ' . $data['from'] : null,
            ! empty($data['graph_client_secret']) ? 'The Microsoft 365 client secret was changed.' : null,
            ! empty($data['google_password']) ? 'The Google app password was changed.' : null,
        ])));

        return response()->json([
            'ok'     => true,
            'config' => \App\Support\AgencyMail::publicConfig($agencyId),
            'active' => \App\Support\AgencyMail::configFor($agencyId) !== null,
        ]);
    }

    /** Send a real test through the agency's OWN configured transport. */
    public function testAgencyMail(Request $request): JsonResponse
    {
        $agencyId = $this->mailTargetAgency($request);
        if (! $agencyId) return response()->json(['message' => 'No agency access'], 403);

        $data = $request->validate(['to' => 'required|email', 'agency_id' => 'nullable|integer']);
        $cfg = \App\Support\AgencyMail::configFor($agencyId);
        if (! $cfg) {
            return response()->json(['ok' => false, 'message' => 'This agency is on the KiddieTrac default email — pick and save Microsoft 365 or Google first, then test.'], 422);
        }

        $email = (new \Symfony\Component\Mime\Email())
            ->from(new \Symfony\Component\Mime\Address($cfg['from'], (string) ($cfg['from_name'] ?? '')))
            ->to($data['to'])
            ->subject('KiddieTrac white-label email test')
            ->html('<p>This is a KiddieTrac test email sent ' . now()->format('M j, g:i A')
                . ' through your own <strong>' . ($cfg['provider'] === 'graph' ? 'Microsoft 365' : 'Google') . '</strong> email, from <strong>'
                . e($cfg['from']) . '</strong>. If you received this, your white-label email is working.</p>');

        try {
            if ($cfg['provider'] === 'graph') {
                (new \App\Mail\GraphTransport($cfg['graph']))->send($email);
            } else {
                $g = $cfg['google'];
                $t = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport($g['host'], (int) $g['port'], (int) $g['port'] === 465);
                $t->setUsername($g['username']);
                $t->setPassword($g['password']);
                $t->send($email);
            }
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'via' => $cfg['provider'], 'message' => substr($e->getMessage(), 0, 300)]);
        }

        return response()->json(['ok' => true, 'via' => $cfg['provider'], 'from' => $cfg['from']]);
    }

    // ════════════════════════════════════════════════════════════════
    //   CENTRES
    // ════════════════════════════════════════════════════════════════

    public function listCentres(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $centres = DB::table('centres')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        // Active rooms per centre, grouped once (avoids an N+1 in the map). Lets
        // admins/directors pick ANY provider's room in screens that span the whole
        // agency (educator "log a moment", lesson plans) — previously this list had
        // no rooms, so those screens fell back to a single centre.
        $roomsByCentre = DB::table('rooms')
            ->whereIn('centre_id', $centres->pluck('id')->all() ?: [0])
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'centre_id', 'name', 'age_group', 'capacity'])
            ->groupBy('centre_id');

        // Provider photo fallback: a home-daycare provider IS a centre, linked to the
        // person by a matching email. Surface that user's photo so Providers & rooms
        // cards show the provider's face even when the centre has no logo.
        $__cids = $centres->pluck('id')->all() ?: [0];
        $provPhotoByCentre = DB::table('centres as pc')
            ->join('users as pu', DB::raw('LOWER(pu.email)'), '=', DB::raw('LOWER(pc.email)'))
            ->whereIn('pc.id', $__cids)->whereNotNull('pc.email')->whereNotNull('pu.photo_url')
            ->pluck('pu.photo_url', 'pc.id')->all();

        $result = $centres->map(function ($c) use ($roomsByCentre, $provPhotoByCentre) {
            // Enrolled children are counted through the AUTHORITATIVE link —
            // the child's family centre — not room enrolments: many agencies
            // don't place children in rooms (or haven't created rooms yet), and
            // the old rooms-join silently returned 0 for them.
            $childrenCount = DB::table('children as c')
                ->join('families as f', 'f.id', '=', 'c.family_id')
                ->where('f.centre_id', $c->id)
                ->where('c.enrollment_status', 'enrolled')
                ->whereNull('c.deleted_at')
                ->count();

            $familyCount = DB::table('families')
                ->where('centre_id', $c->id)
                ->whereNull('deleted_at')
                ->count();

            $staffCount = DB::table('role_assignments')
                ->where('centre_id', $c->id)
                ->whereIn('role', ['centre_director', 'educator'])
                ->where('active', true)
                ->distinct()
                ->count('user_id');

            return [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'license_number' => $c->license_number,
                'license_capacity' => $c->license_capacity,
                'address_line1' => $c->address_line1,
                'address_line2' => $c->address_line2,
                'city' => $c->city,
                'province' => $c->province,
                'postal_code' => $c->postal_code,
                // Persisted map coordinates — lets the provider map plot instantly
                // instead of geocoding every address on each load.
                'latitude'  => isset($c->latitude) ? (float) $c->latitude : null,
                'longitude' => isset($c->longitude) ? (float) $c->longitude : null,
                'date_of_birth' => $c->date_of_birth ?? null,
                'phone' => $c->phone,
                'email' => $c->email,
                'status' => $c->status,
                'cwelcc_enrolled' => (bool) $c->cwelcc_enrolled,
                'open_time'  => $c->open_time ?? null,
                'close_time' => $c->close_time ?? null,
                // v22p3.4: per-centre branding
                'logo_url'     => $c->logo_url ?? null,
                'provider_photo_url' => $provPhotoByCentre[$c->id] ?? null,
                'brand_color'  => $c->brand_color ?? null,
                'accent_color' => $c->accent_color ?? null,
                'tagline'      => $c->tagline ?? null,
                'provider_bio' => $c->provider_bio ?? null,
                // v22p5.1: kiosk state surface in admin centres list
                'kiosk_enabled' => (bool) ($c->kiosk_enabled ?? false),
                'kiosk_token'   => $c->kiosk_token ?? null,
                'enrolled_count' => $childrenCount,
                'family_count' => $familyCount,
                'staff_count' => $staffCount,
                'capacity_pct' => $c->license_capacity > 0 ? round(($childrenCount / $c->license_capacity) * 100) : 0,
                // Email delivery switch (centres.settings.email_enabled) so the
                // list can badge each location on/off. Absent → on (default).
                'email_enabled' => (function ($s) {
                    $d = $s ? (json_decode($s, true) ?: []) : [];
                    return ($d['email_enabled'] ?? true) !== false;
                })($c->settings ?? null),
                // Days the centre is open (1=Mon..7=Sun); default Mon–Fri. Drives
                // which day columns the weekly-menu shows.
                'open_days' => self::openDaysFromSettings($c->settings ?? null),
                // Active rooms for this centre — so agency-wide screens (educator
                // roster picker, lesson plans) can target any provider's room.
                'rooms' => array_values(($roomsByCentre[$c->id] ?? collect())->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'centre_id' => $r->centre_id,
                    'age_group' => $r->age_group ?? null,
                    'capacity' => $r->capacity ?? null,
                ])->all()),
                'created_at' => $c->created_at,
            ];
        });

        return response()->json(['centres' => $result->all()]);
    }

    /** Normalise an incoming open_days array to sorted unique ints in 1..7. */
    private static function normaliseOpenDays($raw): array
    {
        if (!is_array($raw)) return [];
        $days = array_values(array_unique(array_filter(array_map('intval', $raw), fn ($d) => $d >= 1 && $d <= 7)));
        sort($days);
        return $days;
    }

    /** Read open_days from a centre's settings JSON; default Mon–Fri when unset. */
    private static function openDaysFromSettings($settings): array
    {
        $arr = $settings ? json_decode($settings, true) : null;
        if (is_array($arr) && !empty($arr['open_days']) && is_array($arr['open_days'])) {
            $d = self::normaliseOpenDays($arr['open_days']);
            if ($d) return $d;
        }
        return [1, 2, 3, 4, 5];
    }

    public function createCentre(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'supervisor_first_name' => ['nullable', 'string', 'max:80'],
            'supervisor_last_name' => ['nullable', 'string', 'max:80'],
            'license_number' => ['nullable', 'string', 'max:60'],
            'license_capacity' => ['nullable', 'integer', 'min:0', 'max:500'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', 'string', 'max:40'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:180'],
            'cwelcc_enrolled' => ['nullable', 'boolean'],
            'open_time'  => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'close_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'open_days' => ['nullable', 'array'],
            'open_days.*' => ['integer', 'between:1,7'],
        ]);

        $slug = Str::slug($data['name']);
        // Ensure unique slug within agency
        $baseSlug = $slug;
        $i = 1;
        while (DB::table('centres')->where('agency_id', $agencyId)->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . ++$i;
        }

        $centreId = DB::table('centres')->insertGetId([
            'agency_id' => $agencyId,
            'name' => $data['name'],
            'supervisor_first_name' => $data['supervisor_first_name'] ?? null,
            'supervisor_last_name' => $data['supervisor_last_name'] ?? null,
            'slug' => $slug,
            'license_number' => $data['license_number'] ?? null,
            'license_capacity' => $data['license_capacity'] ?? 0,
            'address_line1' => $data['address_line1'] ?? null,
            'city' => $data['city'] ?? null,
            'province' => $data['province'] ?? 'ON',
            'postal_code' => $data['postal_code'] ?? null,
            'country' => 'CA',
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'cwelcc_enrolled' => !empty($data['cwelcc_enrolled']) ? 1 : 0,
            'open_time' => $data['open_time'] ?? null,
            'close_time' => $data['close_time'] ?? null,
            'settings' => !empty($data['open_days']) ? json_encode(['open_days' => self::normaliseOpenDays($data['open_days'])]) : null,
            'status' => 'onboarding',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($request->user()->id, 'centre.created', 'centre', $centreId, ['name' => $data['name']]);

        return response()->json(['id' => $centreId, 'message' => 'Centre created'], 201);
    }

    public function updateCentre(Request $request, int $centreId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $centre = DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$centre) return response()->json(['message' => 'Centre not found'], 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:180'],
            'license_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'license_capacity' => ['sometimes', 'integer', 'min:0', 'max:500'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:200'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'province' => ['sometimes', 'nullable', 'string', 'max:40'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:12'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:180'],
            'cwelcc_enrolled' => ['sometimes', 'boolean'],
            // Centre operating hours (HH:MM, seconds optional).
            'open_time'  => ['sometimes', 'nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'close_time' => ['sometimes', 'nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'status' => ['sometimes', 'in:onboarding,active,paused,closed'],
            // v22p3.4: branding fields
            'brand_color'  => ['sometimes', 'nullable', 'string', 'max:20'],
            'accent_color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'tagline'      => ['sometimes', 'nullable', 'string', 'max:200'],
            'provider_bio' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'open_days'    => ['sometimes', 'array'],
            'open_days.*'  => ['integer', 'between:1,7'],
        ]);

        // open_days lives inside the settings JSON, not a column — merge it in so
        // other settings keys survive, and don't pass it to the column update.
        if (array_key_exists('open_days', $data)) {
            $settings = json_decode($centre->settings ?? '{}', true) ?: [];
            $settings['open_days'] = self::normaliseOpenDays($data['open_days']);
            unset($data['open_days']);
            $data['settings'] = json_encode($settings);
        }

        $data['updated_at'] = now();
        DB::table('centres')->where('id', $centreId)->update($data);

        $this->audit($request->user()->id, 'centre.updated', 'centre', $centreId, $data);

        return response()->json(['message' => 'Centre updated']);
    }

    /** Can this user archive/restore/delete the given centre? platform_admin →
     *  any; agency_admin → centres in their agency; centre_director → their centre. */
    private function canManageCentre(Request $request, $centre): bool
    {
        $uid = $request->user()->id;
        if (DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', true)->exists()) {
            return true;
        }
        if (DB::table('role_assignments')->where('user_id', $uid)->where('role', 'agency_admin')->where('agency_id', $centre->agency_id)->where('active', true)->exists()) {
            return true;
        }
        if (DB::table('role_assignments')->where('user_id', $uid)->where('role', 'centre_director')->where('centre_id', $centre->id)->where('active', true)->exists()) {
            return true;
        }
        return false;
    }

    public function archiveCentre(Request $request, int $centreId): JsonResponse
    {
        $centre = DB::table('centres')->where('id', $centreId)->whereNull('deleted_at')->first();
        if (!$centre || !$this->canManageCentre($request, $centre)) {
            return response()->json(['message' => 'Centre not found'], 404);
        }
        DB::table('centres')->where('id', $centreId)->update(['deleted_at' => now(), 'status' => 'closed']);
        $this->audit($request->user()->id, 'centre.archived', 'centre', $centreId, []);
        return response()->json(['message' => 'Centre archived']);
    }

    public function restoreCentre(Request $request, int $centreId): JsonResponse
    {
        $centre = DB::table('centres')->where('id', $centreId)->whereNotNull('deleted_at')->first();
        if (!$centre || !$this->canManageCentre($request, $centre)) {
            return response()->json(['message' => 'Archived centre not found'], 404);
        }
        DB::table('centres')->where('id', $centreId)->update(['deleted_at' => null, 'status' => 'active']);
        $this->audit($request->user()->id, 'centre.restored', 'centre', $centreId, []);
        return response()->json(['message' => 'Centre restored']);
    }

    public function permanentDeleteCentre(Request $request, int $centreId): JsonResponse
    {
        $centre = DB::table('centres')->where('id', $centreId)->first(); // includes archived
        if (!$centre || !$this->canManageCentre($request, $centre)) {
            return response()->json(['message' => 'Centre not found'], 404);
        }
        // Guard: never permanently destroy a centre that still has children.
        $hasKids = DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->where('families.centre_id', $centreId)
            ->whereNull('children.deleted_at')
            ->exists();
        if ($hasKids) {
            return response()->json(['message' => 'This centre still has children — archive it instead, or remove the children first.'], 422);
        }
        DB::table('centres')->where('id', $centreId)->delete();
        $this->audit($request->user()->id, 'centre.deleted', 'centre', $centreId, ['name' => $centre->name ?? null]);
        return response()->json(['message' => 'Centre permanently deleted']);
    }

    // ════════════════════════════════════════════════════════════════
    //   AUDIT LOG VIEWER — v22p39
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/audit-logs
     *
     * Filterable, paginated audit-log viewer for agency admins. Each row
     * is enriched with the actor's name / email so the UI doesn't need
     * separate lookups.
     *
     * Scope: by default returns logs where the actor's role_assignment OR
     * the entity itself is within the caller's agency. platform_admin sees
     * every row across the platform.
     *
     * Query params:
     *   entity_type — filter to one type (user, centre, agency, invoice, ...)
     *   action      — filter to a single action (user.created, ...)
     *   user_id     — filter to events by this actor
     *   since       — ISO datetime, lower bound on created_at
     *   until       — ISO datetime, upper bound on created_at
     *   q           — free-text match on action or payload
     *   limit       — page size (default 50, max 200)
     *   offset      — pagination offset
     */
    /** "post:api/v1/admin/centres" → "Created · admin › centres"; "user.deleted" → "User deleted". */
    private function humanizeAuditAction(?string $action): string
    {
        $action = (string) $action;
        $failed = str_contains($action, '[fail]');
        $action = trim(str_replace('[fail]', '', $action));
        if (preg_match('#^(post|put|patch|delete):(.+)$#', $action, $m)) {
            $verb = ['post' => 'Created', 'put' => 'Updated', 'patch' => 'Updated', 'delete' => 'Deleted'][$m[1]] ?? ucfirst($m[1]);
            $path = preg_replace('#^api/v1/#', '', $m[2]);

            // Drop the record ids from the path. They read as noise ("managed-forms
            // › 9 › sign") and the record they point at is already named in the
            // "What" column, resolved to a real name.
            $segments = array_values(array_filter(explode('/', $path), function ($s) {
                return $s !== '' && ! ctype_digit($s);
            }));

            // A trailing verb segment IS the action — "…/9/sign" is a signature, not
            // the creation of something called "sign".
            static $tailVerbs = [
                'sign' => 'Signed', 'draft' => 'Saved a draft of', 'resend' => 'Resent',
                'send' => 'Sent', 'promote' => 'Promoted', 'decline' => 'Declined',
                'remind' => 'Sent a reminder for', 'approve' => 'Approved', 'reject' => 'Rejected',
                'restore' => 'Restored', 'archive' => 'Archived', 'suspend' => 'Suspended',
                'resume' => 'Resumed', 'cancel' => 'Cancelled', 'submit' => 'Submitted',
                'clock-in' => 'Clocked in', 'clock-out' => 'Clocked out', 'react' => 'Reacted to',
                'mark-read' => 'Marked read', 'typing' => 'Typing in', 'nudge' => 'Nudged',
            ];
            // Friendly names for the resources themselves.
            static $resources = [
                'managed-forms' => 'a form', 'photos' => 'a photo', 'care' => 'a care record',
                'logs' => 'a daily-care log', 'children' => 'a child record',
                'families' => 'a family', 'users' => 'a user', 'centres' => 'a centre',
                'rooms' => 'a room', 'invoices' => 'an invoice', 'payments' => 'a payment',
                'announcements' => 'an announcement', 'observations' => 'an observation',
                'incidents' => 'an incident report', 'chats' => 'a chat message',
                'team-threads' => 'a staff message', 'awards' => 'an award',
                'lesson-plans' => 'a lesson plan', 'documents' => 'a document',
                'withdrawals' => 'a withdrawal request', 'tasks' => 'a task',
                'inspection-forms' => 'an inspection form', 'agreements' => 'an agreement',
                'attendance' => 'attendance', 'time-punches' => 'a time punch',
            ];

            $tail = end($segments);
            if ($tail !== false && isset($tailVerbs[$tail])) {
                array_pop($segments);
                $what = null;
                foreach (array_reverse($segments) as $seg) {
                    if (isset($resources[$seg])) { $what = $resources[$seg]; break; }
                }
                $label = $tailVerbs[$tail] . ($what ? ' ' . $what : '');
                return $label . ($failed ? ' (failed)' : '');
            }

            // Otherwise: verb + the most specific resource we recognise.
            $what = null;
            foreach (array_reverse($segments) as $seg) {
                if (isset($resources[$seg])) { $what = $resources[$seg]; break; }
            }
            if ($what !== null) return $verb . ' ' . $what . ($failed ? ' (failed)' : '');

            $pretty = trim(str_replace(['/', '-'], [' › ', ' '], implode('/', $segments)));
            return $verb . ' · ' . $pretty . ($failed ? '  (failed)' : '');
        }
        return ucfirst(str_replace(['.', '_'], ' ', $action)) . ($failed ? ' (failed)' : '');
    }

    /** Human name for the entity an audit row touched (payload first, then a live lookup). */
    private function describeAuditEntity(?string $type, ?int $id, ?string $payload): ?string
    {
        $data = $payload ? (json_decode($payload, true) ?: []) : [];
        $input = is_array($data['input'] ?? null) ? $data['input'] : [];
        $fromPayload = $data['name'] ?? $data['email'] ?? $data['to'] ?? ($input['name'] ?? $input['email'] ?? $input['family_name'] ?? null);
        if (is_string($fromPayload) && trim($fromPayload) !== '') {
            return $fromPayload;
        }
        // The middleware derives entity_type from the URL, which frequently yields
        // junk like "id" (from ".../managed-forms/9/sign") — so the record was never
        // looked up and the "What" column sat empty. When the type is unusable, take
        // the resource segment that PRECEDES the id in the recorded path instead.
        if ($id && (! $type || in_array(strtolower((string) $type), ['id', 'ids', ''], true))) {
            $path = (string) ($data['path'] ?? '');
            if ($path !== '') {
                $segs = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
                foreach ($segs as $i => $seg) {
                    if (ctype_digit($seg) && (int) $seg === $id && $i > 0) { $type = $segs[$i - 1]; break; }
                }
            }
        }
        if (! $type || ! $id) {
            return null;
        }
        $map = ['users' => 'user', 'user' => 'user', 'agencies' => 'agency', 'agency' => 'agency', 'centres' => 'centre', 'centre' => 'centre', 'children' => 'child', 'child' => 'child', 'families' => 'family', 'family' => 'family', 'rooms' => 'room', 'room' => 'room',
            'managed-forms' => 'managed_form', 'managed_forms' => 'managed_form'];
        $t = $map[strtolower($type)] ?? strtolower($type);
        try {
            switch ($t) {
                case 'user':
                    $r = DB::table('users')->where('id', $id)->first(['first_name', 'last_name', 'email']);
                    if (! $r) return null;
                    $n = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
                    return $n !== '' ? $n : $r->email;
                case 'agency': return DB::table('agencies')->where('id', $id)->value('name') ?: null;
                case 'centre': return DB::table('centres')->where('id', $id)->value('name') ?: null;
                case 'family': return DB::table('families')->where('id', $id)->value('family_name') ?: null;
                case 'child':
                    $r = DB::table('children')->where('id', $id)->first(['first_name', 'last_name']);
                    return $r ? (trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: null) : null;
                case 'room': return DB::table('rooms')->where('id', $id)->value('name') ?: null;
                case 'managed_form':
                    return \Illuminate\Support\Facades\Schema::hasTable('managed_forms')
                        ? (DB::table('managed_forms')->where('id', $id)->value('title') ?: null)
                        : null;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    /** Short human summary of what changed, drawn from the audit payload. */
    private function summarizeAuditPayload(?string $payload, ?string $tz = null): ?string
    {
        $data = $payload ? (json_decode($payload, true) ?: []) : [];
        if (! is_array($data)) return null;
        // Any controller can supply a ready-made, human-readable summary in its audit
        // payload — prefer it verbatim (e.g. "Anthony Hosein clocked IN at Sunny
        // Meadows · shift 6h 12m"). This is how per-event detail is surfaced cleanly.
        if (isset($data['summary']) && is_string($data['summary']) && $data['summary'] !== '') {
            return $data['summary'];
        }
        $bits = [];
        if (isset($data['status']) && ((int) $data['status'] < 200 || (int) $data['status'] >= 300)) {
            $bits[] = 'HTTP ' . $data['status'];
        }
        $input = is_array($data['input'] ?? null) ? $data['input'] : (isset($data['method']) ? [] : $data);
        $skip = ['method', 'path', 'status', 'input', 'active_agency_id', 'fields', 'user_agent', 'ip_address'];
        // Uploaded blobs and data: URLs (signatures, files, logos). Their VALUE is
        // meaningless to a reader and megabytes long — say that one was included.
        $blobKeys = ['signature', 'file', 'filled_file', 'photo', 'video', 'attachment', 'brand_logo_url', 'avatar', 'image'];
        $fields = [];
        foreach ($input as $k => $v) {
            if (in_array($k, $skip, true) || $v === '' || $v === null || $v === []) continue;

            // Resolve id references to the actual record's NAME so an auditor reads
            // "child: Aria Hosein" instead of "child_id: 6" — singly or as a list.
            $ref = $this->resolveAuditRef((string) $k, $v);
            if ($ref !== null) { $fields[] = $ref; if (count($fields) >= 6) break; continue; }

            $label = $this->auditFieldLabel((string) $k);

            if (in_array(strtolower((string) $k), $blobKeys, true)
                || (is_string($v) && str_starts_with($v, 'data:'))) {
                $fields[] = $label . ': (attached)';
                if (count($fields) >= 6) break;
                continue;
            }

            if (! is_scalar($v)) {
                // Nested structures (e.g. a form's field_values): say how much, not what.
                if (is_array($v)) { $fields[] = $label . ': ' . count($v) . ' ' . (count($v) === 1 ? 'entry' : 'entries'); if (count($fields) >= 6) break; }
                continue;
            }

            // Timestamps in the AGENCY's timezone, matching the "When" column.
            $ts = $this->formatAuditTs($v, $tz);
            if ($ts !== null) { $fields[] = $label . ': ' . $ts; if (count($fields) >= 6) break; continue; }

            $val = is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v;
            if (mb_strlen($val) > 60) $val = mb_substr($val, 0, 60) . '…';
            $fields[] = $label . ': ' . $val;
            if (count($fields) >= 6) break;
        }
        if (isset($data['fields']) && is_array($data['fields'])) {
            $fields = array_merge($fields, array_map('strval', array_slice($data['fields'], 0, 6)));
        }
        if ($fields) $bits[] = implode(', ', $fields);
        return $bits ? implode(' · ', $bits) : null;
    }

    /** Per-request cache of resolved id→name lookups so the audit list isn't N+1 heavy. */
    private array $auditRefCache = [];

    /**
     * If a payload key is a reference to another record (child_id, family_id,
     * user_id, centre_id, agency_id, assigned_to, …), return a readable
     * "label: Name" string instead of "key: 6". Returns null for non-reference
     * fields so the caller prints them normally.
     */
    private function resolveAuditRef(string $key, $value): ?string
    {
        $k = strtolower($key);
        $spec = $this->auditRefSpec($k);
        if ($spec === null) return null;

        // PLURAL / LIST forms: child_ids arrives as a real array, a JSON string
        // ("[59]" — that is how the photo upload posts it) or a comma list. These
        // used to fall straight through to the generic branch, which printed the
        // literal "child_ids: [59]" an auditor cannot read. Resolve every id.
        $ids = $this->auditRefIds($value);
        if (count($ids) > 1 || (count($ids) === 1 && ! is_scalar($value)) || (is_string($value) && preg_match('/^\s*[\[,]/', $value))) {
            $names = [];
            foreach (array_slice($ids, 0, 6) as $one) {
                $n = $this->auditRefName($spec[1], $one);
                if ($n !== null) $names[] = $n;
            }
            if (! $names) return null;
            $label = count($ids) > 1 ? $this->auditRefPlural($spec[0]) : $spec[0];
            $more = count($ids) > count($names) ? ' +' . (count($ids) - count($names)) . ' more' : '';
            return $label . ': ' . implode(', ', $names) . $more;
        }

        if (! is_scalar($value)) return null;
        $id = (int) $value;
        if ((string) $id !== (string) $value || $id <= 0) return null;   // only plain positive ids
        $name = $this->auditRefName($spec[1], $id);
        return $name !== null ? ($spec[0] . ': ' . $name) : null;
    }

    /** key → [display label, lookup table], singular or plural (child_id / child_ids). */
    private function auditRefSpec(string $k): ?array
    {
        $k = preg_replace('/_ids$/', '_id', $k);   // child_ids → child_id
        static $userKeys = ['user_id', 'assigned_to', 'assigned_by', 'guardian_id', 'educator_id',
            'home_visitor_id', 'staff_id', 'target_user_id', 'impersonated_user_id', 'created_by',
            'updated_by', 'reviewer_id', 'signer_id', 'recipient_id', 'actor_id'];
        if (in_array($k, $userKeys, true)) return [$this->auditRefLabel($k), 'users'];
        if (in_array($k, ['child_id', 'children_id'], true)) return ['child', 'children'];
        if ($k === 'family_id') return ['family', 'families'];
        if ($k === 'centre_id') return ['centre', 'centres'];
        if ($k === 'agency_id') return ['agency', 'agencies'];
        if ($k === 'room_id')   return ['room', 'rooms'];
        return null;
    }

    /** "children" from "child", "families" from "family" — for id LISTS. */
    private function auditRefPlural(string $label): string
    {
        static $map = ['child' => 'children', 'family' => 'families', 'centre' => 'centres',
            'agency' => 'agencies', 'room' => 'rooms', 'user' => 'users', 'parent' => 'parents',
            'educator' => 'educators', 'staff' => 'staff'];
        return $map[$label] ?? $label . 's';
    }

    /** Every positive id inside a scalar, JSON-encoded array, real array or CSV. */
    private function auditRefIds($value): array
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $v) if (is_scalar($v) && (int) $v > 0) $out[] = (int) $v;
            return array_values(array_unique($out));
        }
        if (! is_scalar($value)) return [];
        $s = trim((string) $value);
        if ($s === '') return [];
        if ($s[0] === '[' || $s[0] === '{') {
            $decoded = json_decode($s, true);
            if (is_array($decoded)) return $this->auditRefIds($decoded);
        }
        if (str_contains($s, ',')) {
            $out = [];
            foreach (explode(',', $s) as $part) {
                $part = trim($part);
                if ($part !== '' && ctype_digit($part) && (int) $part > 0) $out[] = (int) $part;
            }
            return array_values(array_unique($out));
        }
        return ctype_digit($s) && (int) $s > 0 ? [(int) $s] : [];
    }

    /**
     * Replace embedded blobs in the payload we RETURN (never in the stored row) with
     * a short marker. A single signature is ~40 KB of base64 that pushes the real
     * fields off the screen and makes the detail view unreadable — and 50 of them in
     * one response is megabytes over the wire for data nobody can interpret.
     */
    private function trimAuditPayloadBlobs(?string $payload): ?string
    {
        if ($payload === null || $payload === '') return $payload;
        if (strlen($payload) < 400 && ! str_contains($payload, 'data:')) return $payload;
        $data = json_decode($payload, true);
        if (! is_array($data)) {
            return strlen($payload) > 4000 ? substr($payload, 0, 4000).'… (truncated)' : $payload;
        }
        $walk = function ($node) use (&$walk) {
            if (is_array($node)) {
                $out = [];
                foreach ($node as $k => $v) $out[$k] = $walk($v);
                return $out;
            }
            if (is_string($node)) {
                if (str_starts_with($node, 'data:')) {
                    $kind = explode(';', substr($node, 5))[0] ?: 'file';
                    return '('.$kind.', '.number_format(strlen($node) / 1024, 0).' KB — not shown)';
                }
                if (strlen($node) > 600) return substr($node, 0, 600).'… (truncated)';
            }
            return $node;
        };
        return json_encode($walk($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Render a timestamp found inside an audit payload in the AGENCY's timezone.
     *
     * Clients post these as UTC ISO strings ("2026-08-12T11:50:00.000Z"), and the
     * log printed them verbatim — so a care log entered at 7:50 a.m. Eastern read
     * as 11:50, disagreeing with the very same event's "When" column right beside
     * it. Every displayed time in the portal is agency-local; this is no exception.
     */
    private function formatAuditTs($value, ?string $tz): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) return null;
        $s = trim((string) $value);
        if ($s === '') return null;
        // ISO-8601 (with or without a zone) or a MySQL datetime; a bare date too.
        if (! preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/', $s)) return null;
        try {
            $dateOnly = ! str_contains($s, ':');
            // No zone marker on a datetime means UTC here (that is how the server stores).
            $hasZone = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $s);
            $c = \Carbon\Carbon::parse($s, $hasZone || $dateOnly ? null : 'UTC');
            if ($dateOnly) return $c->format('M j, Y');
            return $c->setTimezone($tz ?: 'UTC')->format('M j, Y, g:i a');
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Readable label for a payload field. Raw request keys are developer names —
     * "log_type", "occurred_at", "week_starting" — and an audit trail is read by
     * administrators and auditors, not by the people who chose the column names.
     */
    private function auditFieldLabel(string $key): string
    {
        static $map = [
            'log_type' => 'type', 'occurred_at' => 'occurred', 'ended_at' => 'ended',
            'started_at' => 'started', 'week_starting' => 'week of', 'field_values' => 'fields filled',
            'details' => 'details', 'notes' => 'notes', 'body' => 'message', 'title' => 'title',
            'full_name' => 'name', 'first_name' => 'first name', 'last_name' => 'last name',
            'agreed' => 'agreed', 'enabled' => 'enabled', 'status' => 'status',
            'audiences' => 'audiences', 'brand_primary_color' => 'brand colour',
            'brand_support_email' => 'support email', 'effective_date' => 'effective',
            'due_date' => 'due', 'amount' => 'amount', 'reason' => 'reason',
        ];
        if (isset($map[$key])) return $map[$key];
        return str_replace('_', ' ', preg_replace('/_at$/', '', $key));
    }

    private function auditRefLabel(string $key): string
    {
        $map = ['user_id' => 'user', 'assigned_to' => 'assigned to', 'assigned_by' => 'assigned by',
            'guardian_id' => 'parent', 'educator_id' => 'educator', 'home_visitor_id' => 'home visitor',
            'staff_id' => 'staff', 'target_user_id' => 'user', 'impersonated_user_id' => 'viewing as',
            'created_by' => 'created by', 'updated_by' => 'updated by', 'reviewer_id' => 'reviewer'];
        return $map[$key] ?? str_replace('_', ' ', preg_replace('/_id$/', '', $key));
    }

    /** Cached id→display-name lookup for the audit reference tables. */
    private function auditRefName(string $table, int $id): ?string
    {
        $cacheKey = $table . ':' . $id;
        if (array_key_exists($cacheKey, $this->auditRefCache)) return $this->auditRefCache[$cacheKey];
        $name = null;
        try {
            switch ($table) {
                case 'users':
                    $r = DB::table('users')->where('id', $id)->first(['first_name', 'last_name', 'email']);
                    if ($r) { $n = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')); $name = $n !== '' ? $n : $r->email; }
                    break;
                case 'children':
                    $r = DB::table('children')->where('id', $id)->first(['first_name', 'last_name']);
                    if ($r) $name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: null;
                    break;
                case 'families':
                    $name = DB::table('families')->where('id', $id)->value('family_name') ?: null;
                    break;
                case 'centres':
                    $name = DB::table('centres')->where('id', $id)->value('name') ?: null;
                    break;
                case 'agencies':
                    $name = DB::table('agencies')->where('id', $id)->value('name') ?: null;
                    break;
                case 'rooms':
                    $name = DB::table('rooms')->where('id', $id)->value('name') ?: null;
                    break;
            }
        } catch (\Throwable $e) { $name = null; }
        return $this->auditRefCache[$cacheKey] = $name;
    }

    public function auditLogs(Request $request)
    {
        // v22p47: viewable by agency_admin AND centre_director. Agency admins see
        // the whole agency; directors see only the centre(s) they direct.
        $caller = $request->user();
        $callerRoles = DB::table('role_assignments')
            ->where('user_id', $caller->id)->where('active', true)
            ->pluck('role')->all();
        $callerIsPlatformAdmin = in_array('platform_admin', $callerRoles, true);
        $callerIsAgencyAdmin   = in_array('agency_admin', $callerRoles, true);
        $callerIsDirector      = in_array('centre_director', $callerRoles, true);
        if (!$callerIsPlatformAdmin && !$callerIsAgencyAdmin && !$callerIsDirector) {
            return response()->json(['logs' => [], 'total' => 0]);
        }

        $entityType = $request->input('entity_type');
        $action     = $request->input('action');
        $userId     = (int) $request->input('user_id');
        $since      = $request->input('since');
        $until      = $request->input('until');
        $q          = trim((string) $request->input('q', ''));
        $limit      = min(200, max(1, (int) $request->input('limit', 50)));
        $offset     = max(0, (int) $request->input('offset', 0));

        // Build agency-scoped user id list (actors who belong to this agency).
        // SECURITY (v22p96): ALWAYS scope to the active agency — a platform_admin
        // is treated as an agency_admin of whichever agency they've switched into
        // (getAgencyId resolves the X-Active-Agency-Id header), so audit logs are
        // never shown across tenants. The prior `$scopedUserIds = null` bypass
        // surfaced every agency's audit trail to platform_admins — removed.
        $scopedUserIds = null;
        $activeAgencyId = null;   // the agency this log view is scoped to
        if ($callerIsPlatformAdmin || $callerIsAgencyAdmin) {
            // Whole active agency: actors scoped by agency_id or any agency centre.
            $agencyId = $this->getAgencyId($request);
            if (!$agencyId) return response()->json(['logs' => [], 'total' => 0]);
            $activeAgencyId = (int) $agencyId;
            $centreIds = $this->getCentreIds($agencyId);
            $scopedUserIds = DB::table('role_assignments')
                ->where('active', true)
                ->where(function ($x) use ($agencyId, $centreIds) {
                    $x->where('agency_id', $agencyId);
                    if (!empty($centreIds)) $x->orWhereIn('centre_id', $centreIds);
                })
                ->pluck('user_id')->unique()->values()->all();
        } else {
            // centre_director: limit to the centre(s) they actually direct.
            $centreIds = DB::table('role_assignments')
                ->where('user_id', $caller->id)->where('role', 'centre_director')
                ->where('active', true)->whereNotNull('centre_id')
                ->pluck('centre_id')->unique()->values()->all();
            $activeAgencyId = (int) (DB::table('centres')->whereIn('id', $centreIds ?: [0])->value('agency_id') ?: 0) ?: null;
            $scopedUserIds = DB::table('role_assignments')
                ->where('active', true)->whereIn('centre_id', $centreIds ?: [0])
                ->pluck('user_id')->unique()->values()->all();
        }
        // Include guardian users tied to the in-scope centres + the viewer.
        $guardianIds = DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->whereIn('f.centre_id', $centreIds ?: [0])
            ->pluck('g.user_id')->all();
        $scopedUserIds = array_values(array_unique(array_merge($scopedUserIds, $guardianIds, [(int) $caller->id])));

        $base = DB::table('audit_logs as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
            ->orderByDesc('al.created_at');

        if ($scopedUserIds !== null) {
            // Primary, LEAK-PROOF filter: rows stamped with THIS agency's id only.
            // (agency_id is stamped at write time — see App\Support\AuditScope.)
            //
            // Legacy rows written before agency stamping have agency_id = NULL; for
            // those we fall back to the older actor/email scoping so historical
            // entries still appear, scoped to this agency's users. A backfill tags
            // most of them, shrinking this fallback to near-nothing.
            // STRICT tenant isolation (2026-08-11): a row is shown ONLY when it is
            // stamped with THIS agency's id (al.agency_id). The previous fallback
            // ALSO matched untagged (agency_id NULL) rows by actor user_id/email —
            // which LEAKED cross-agency entries: a platform-admin's actions in one
            // agency (or any unstamped legacy row) surfaced under every agency that
            // shared that actor. Measured: iLearn's view was 1026 rows, 764 of them
            // NOT iLearn's. Untagged legacy rows are now excluded from EVERY agency
            // view (a backfill re-tags the ones whose owning agency is knowable).
            if (! $activeAgencyId) {
                return response()->json(['logs' => [], 'total' => 0]);
            }
            $base->where('al.agency_id', $activeAgencyId);

            // DEFENCE IN DEPTH (2026-08-14). The stamp alone is not enough. A row
            // carrying the wrong agency_id — mis-stamped by an older code path, or
            // by a request whose header was trusted before that was hardened —
            // sails straight through a filter that only checks the stamp. Measured
            // on live data: of 902 rows stamped for iLearn, 56 were written by
            // someone with no role in iLearn at all.
            //
            // So a row must ALSO have been written by someone who genuinely belongs
            // to this agency (or by a platform admin, who legitimately acts inside
            // it, or by the system with no actor). Two independent conditions have
            // to agree before one tenant sees a row, which means a single bad stamp
            // can no longer leak anything.
            $platformActorIds = DB::table('role_assignments')
                ->where('role', 'platform_admin')->where('active', true)
                ->pluck('user_id')->all();
            $permittedActors = array_values(array_unique(array_merge(
                $scopedUserIds ?: [], $platformActorIds, [(int) $caller->id]
            )));
            $base->where(function ($q) use ($permittedActors) {
                $q->whereIn('al.user_id', $permittedActors ?: [0])
                  ->orWhereNull('al.user_id');          // system-generated, no actor
            });

            // Centre directors (not agency/platform admins) additionally see only
            // their own centre's actors.
            if ($callerIsDirector && ! $callerIsAgencyAdmin && ! $callerIsPlatformAdmin) {
                $base->whereIn('al.user_id', $scopedUserIds ?: [0]);
            }
        }
        if ($entityType) $base->where('al.entity_type', $entityType);
        if ($action)     $base->where('al.action', $action);
        if ($userId)     $base->where('al.user_id', $userId);
        if ($since)      $base->where('al.created_at', '>=', $since);
        if ($until)      $base->where('al.created_at', '<=', $until);
        if ($q !== '')   $base->where(function ($x) use ($q) {
            $x->where('al.action', 'like', "%{$q}%")
              ->orWhere('al.payload', 'like', "%{$q}%")
              ->orWhere('u.first_name', 'like', "%{$q}%")
              ->orWhere('u.last_name', 'like', "%{$q}%")
              ->orWhere('u.username', 'like', "%{$q}%")
              ->orWhere('u.email', 'like', "%{$q}%")
              ->orWhereRaw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) like ?", ["%{$q}%"]);
        });

        $total = (clone $base)->count('al.id');

        $rows = $base->limit($limit)->offset($offset)->get([
            'al.id', 'al.action', 'al.entity_type', 'al.entity_id',
            'al.payload', 'al.ip_address', 'al.created_at',
            'al.user_id',
            DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.email, 'system') as actor_name"),
            'u.email as actor_email',
        ]);

        // Enrich each row: readable action, the affected entity's NAME (from
        // payload first so it survives deletes, else a live lookup), and a short
        // human summary of what changed.
        // One timezone for the whole view: the agency being audited. Times inside
        // payloads are rendered in it so they agree with the "When" column.
        $auditTz = 'UTC';
        try { $auditTz = \App\Support\AgencyTime::tz($activeAgencyId ?: null) ?: 'UTC'; } catch (\Throwable $e) {}

        $rows = $rows->map(function ($r) use ($auditTz) {
            $r->action_label = $this->humanizeAuditAction($r->action);
            $r->entity_name  = $this->describeAuditEntity($r->entity_type, $r->entity_id ? (int) $r->entity_id : null, $r->payload);
            $r->summary      = $this->summarizeAuditPayload($r->payload, $auditTz);
            // An IP address tells an auditor nothing. Where it came from does.
            $r->location     = \App\Support\GeoIp::locate($r->ip_address);
            $r->payload      = $this->trimAuditPayloadBlobs($r->payload);
            return $r;
        });

        // Distinct values for the UI filter dropdowns.
        //
        // These MUST come from the agency-scoped set, NOT from $base — $base has
        // the user's filters applied, so picking an action collapsed the dropdown
        // to that single action, and a filter with no results emptied it entirely.
        // That is the "sometimes it has options, sometimes it doesn't".
        $optionsBase = DB::table('audit_logs as al');
        if ($scopedUserIds !== null) {
            $optionsBase->where(function ($w) use ($scopedUserIds) {
                $w->whereIn('al.user_id', $scopedUserIds ?: [0])->orWhereNull('al.user_id');
            });
        }
        $distinctActions = (clone $optionsBase)->select('al.action')->distinct()
            ->orderBy('al.action')->limit(80)->pluck('al.action')->filter()->values();
        $distinctEntities = (clone $optionsBase)->select('al.entity_type')->distinct()
            ->orderBy('al.entity_type')->limit(40)->pluck('al.entity_type')->filter()->values();

        // v22p46: CSV export — pulls up to 5000 rows (above the 200 default)
        // so a date-range export can produce a full history slice in one shot.
        if (strtolower((string) $request->query('format', '')) === 'csv') {
            $csvRows = (clone $base)->limit(5000)->offset(0)->get([
                'al.id', 'al.action', 'al.entity_type', 'al.entity_id',
                'al.payload', 'al.ip_address', 'al.created_at', 'al.user_id',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.email, 'system') as actor_name"),
                'u.email as actor_email',
            ]);
            return $this->streamCsv('audit-log', [
                'When', 'Action', 'Entity type', 'Entity ID',
                'Actor', 'Email', 'IP', 'Payload',
            ], $csvRows->map(fn ($r) => [
                $r->created_at, $r->action, $r->entity_type, $r->entity_id,
                $r->actor_name, $r->actor_email, $r->ip_address, $r->payload,
            ])->all());
        }

        return response()->json([
            'logs' => $rows,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'filters' => [
                'actions' => $distinctActions,
                'entity_types' => $distinctEntities,
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //   PRESENCE — v22p42
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/presence
     *
     * Returns the list of currently-online user_ids in the agency, derived
     * from personal_access_tokens.last_used_at within the last 5 minutes.
     * Cheap proxy for 'who is in the portal right now' without needing a
     * websocket or a dedicated presence table.
     *
     * platform_admin sees everyone; agency_admin sees only their agency.
     */
    public function presence(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['online' => [], 'window_minutes' => 5]);

        $cutoff = now()->subMinutes(5);
        $base = DB::table('personal_access_tokens as t')
            ->where('t.tokenable_type', \App\Models\User::class)
            ->whereNotNull('t.last_used_at')
            ->where('t.last_used_at', '>=', $cutoff)
            ->groupBy('t.tokenable_id');

        // SECURITY (v22p96): presence is ALWAYS scoped to the active agency,
        // platform_admin included (getAgencyId resolves the switched-into agency).
        // The prior platform bypass showed every online user across all tenants.
        $centreIds = $this->getCentreIds($agencyId);
        $scoped = DB::table('role_assignments')
            ->where('active', true)
            ->where(function ($x) use ($agencyId, $centreIds) {
                $x->where('agency_id', $agencyId);
                if (!empty($centreIds)) $x->orWhereIn('centre_id', $centreIds);
            })
            ->pluck('user_id')->unique()->values()->all();
        $guardianIds = DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->whereIn('f.centre_id', $centreIds ?: [0])
            ->pluck('g.user_id')->all();
        $scoped = array_values(array_unique(array_merge($scoped, $guardianIds)));
        $base->whereIn('t.tokenable_id', $scoped ?: [0]);

        $rows = $base->get(['t.tokenable_id', DB::raw('MAX(t.last_used_at) as last_seen')]);

        return response()->json([
            'online' => $rows->map(fn ($r) => [
                'user_id' => (int) $r->tokenable_id,
                'last_seen_at' => $r->last_seen,
            ])->values(),
            'window_minutes' => 5,
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //   COMPLIANCE — v22p47
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/compliance
     *
     * Aggregates three of the most-asked-for compliance signals into one
     * payload so the admin dashboard can render a single 'what needs
     * attention' surface without firing N requests.
     *
     *   expired_certs[]      — staff_certifications.expires_at past or
     *                          within 30 days, joined to user + cert_type
     *   mfa_laggards[]       — agency_admin + centre_director users in this
     *                          agency without two_factor_secret set
     *   centre_ratios[]      — for every centre: licensed capacity vs
     *                          enrolled headcount and a status pill
     *
     * Scoped to the caller's agency. platform_admin sees every agency-
     * filtered slice for whichever X-Active-Agency-Id is set (same
     * pattern as the rest of AdminController).
     */
    public function compliance(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $centreIds = $this->getCentreIds($agencyId);
        $now = now();
        $soon = $now->copy()->addDays(30);

        // Staff users whose role is pinned to this agency or one of its centres
        $staffUserIds = DB::table('role_assignments')
            ->where('active', true)
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator'])
            ->where(function ($q) use ($agencyId, $centreIds) {
                $q->where('agency_id', $agencyId);
                if (!empty($centreIds)) $q->orWhereIn('centre_id', $centreIds);
            })
            ->pluck('user_id')->unique()->all();

        // Expired or expiring certs
        $expiredCerts = empty($staffUserIds) ? collect() : DB::table('staff_certifications as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->whereIn('c.user_id', $staffUserIds)
            ->where('c.active', true)
            ->whereNotNull('c.expires_at')
            ->where('c.expires_at', '<=', $soon->toDateString())
            ->orderBy('c.expires_at')
            ->limit(200)
            ->get([
                'c.id', 'c.cert_type', 'c.certifier', 'c.issued_at', 'c.expires_at',
                'c.user_id',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.email) as user_name"),
                'u.email as user_email',
                DB::raw("CASE WHEN c.expires_at < '{$now->toDateString()}' THEN 'expired' ELSE 'expiring_soon' END as status"),
            ]);

        // MFA laggards — directors + agency admins without two_factor_secret
        $mfaLaggards = empty($staffUserIds) ? collect() : DB::table('users as u')
            ->whereIn('u.id', function ($q) use ($agencyId, $centreIds) {
                $q->select('user_id')->from('role_assignments')
                    ->whereIn('role', ['agency_admin', 'centre_director'])
                    ->where('active', true)
                    ->where(function ($x) use ($agencyId, $centreIds) {
                        $x->where('agency_id', $agencyId);
                        if (!empty($centreIds)) $x->orWhereIn('centre_id', $centreIds);
                    });
            })
            ->whereNull('u.two_factor_secret')
            ->whereNull('u.deleted_at')
            ->orderBy('u.first_name')
            ->limit(200)
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.last_login_at']);

        // Centre capacity vs enrolled
        $centreRatios = empty($centreIds) ? collect() : collect($centreIds)->map(function ($cid) {
            $centre = DB::table('centres')->where('id', $cid)->first();
            if (!$centre) return null;
            $enrolled = DB::table('children as c')
                ->join('families as f', 'f.id', '=', 'c.family_id')
                ->where('f.centre_id', $cid)
                ->where('c.enrollment_status', 'enrolled')
                ->whereNull('c.deleted_at')
                ->count();
            $cap = (int) ($centre->license_capacity ?? 0);
            $pct = $cap > 0 ? round(($enrolled / $cap) * 100, 1) : 0;
            $status = $pct >= 100 ? 'over_capacity' : ($pct >= 95 ? 'tight' : 'ok');
            return [
                'centre_id' => $centre->id,
                'centre_name' => $centre->name,
                'enrolled' => $enrolled,
                'license_capacity' => $cap,
                'pct' => $pct,
                'status' => $status,
            ];
        })->filter()->values();

        return response()->json([
            'expired_certs' => $expiredCerts,
            'mfa_laggards' => $mfaLaggards,
            'centre_ratios' => $centreRatios,
            'summary' => [
                'expired_certs' => $expiredCerts->where('status', 'expired')->count(),
                'expiring_soon' => $expiredCerts->where('status', 'expiring_soon')->count(),
                'mfa_laggards' => $mfaLaggards->count(),
                'over_capacity' => $centreRatios->where('status', 'over_capacity')->count(),
                'tight' => $centreRatios->where('status', 'tight')->count(),
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //   AGENCY-WIDE CHILDREN — v22p47
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/children
     *
     * Cross-centre list of every child in the agency. Same enrolled /
     * waitlist / withdrawn filter as the per-centre director endpoint,
     * but agency-scoped so admins don't have to drill into a centre to
     * see (or export) the full roster. Supports ?format=csv.
     */
    public function listAgencyChildren(Request $request)
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['children' => []]);
        $centreIds = $this->getCentreIds($agencyId);
        if (empty($centreIds)) return response()->json(['children' => []]);

        $q = DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->whereIn('f.centre_id', $centreIds)
            ->whereNull('c.deleted_at')
            ->orderBy('c.last_name')->orderBy('c.first_name');

        if ($status = $request->input('status')) {
            $q->where('c.enrollment_status', $status);
        }

        $rows = $q->limit(2000)->get([
            'c.id', 'c.first_name', 'c.last_name', 'c.preferred_name',
            'c.date_of_birth', 'c.enrollment_status', 'c.enrolled_at',
            'c.allergies', 'c.health_alerts',
            'f.id as family_id', 'f.family_name',
            'ce.id as centre_id', 'ce.name as centre_name',
        ])->all();

        if (strtolower((string) $request->query('format', '')) === 'csv') {
            return $this->streamCsv('children', [
                'ID', 'First name', 'Last name', 'Preferred', 'Date of birth',
                'Status', 'Enrolled', 'Family', 'Centre',
                'Allergies', 'Health alerts',
            ], array_map(fn ($r) => [
                $r->id, $r->first_name, $r->last_name, $r->preferred_name,
                $r->date_of_birth, $r->enrollment_status, $r->enrolled_at,
                $r->family_name, $r->centre_name,
                $r->allergies, $r->health_alerts,
            ], $rows));
        }

        return response()->json(['children' => $rows]);
    }

    // ════════════════════════════════════════════════════════════════
    //   USERS
    // ════════════════════════════════════════════════════════════════

    public function listUsers(Request $request)
    {
        // Platform admins can pick the aggregate "All agencies" scope — the agency
        // switcher sends X-Active-Agency-Id: all. In that mode the Users list spans
        // every agency; otherwise it stays strictly scoped to the active agency.
        $isPlatform = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        $allMode = $isPlatform && strtolower(trim((string) $request->header('X-Active-Agency-Id'))) === 'all';

        $agencyId = $allMode ? null : $this->getAgencyId($request);
        if (!$allMode && !$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $centreIds = $allMode ? [] : $this->getCentreIds($agencyId);
        $roleFilter = $request->input('role');
        $searchQuery = $request->input('q');

        // SECURITY (v22p96): the Users view is ALWAYS scoped to the active agency
        // — including for platform_admins. getAgencyId() already resolves the
        // X-Active-Agency-Id header to the agency the super-admin has switched
        // INTO, so a platform_admin sees one agency at a time (the one selected),
        // never every tenant's users mixed together. To manage another agency's
        // users they switch their active agency to it. The prior v22p27 bypass
        // (drop the filter entirely for platform_admins) leaked every user on the
        // platform into whatever agency context was active — removed.
        // ?deactivated=1 flips the whole query to show SOFT-DELETED users (so an admin
        // can find and reactivate them). Deleting a user sets role_assignments.active=false
        // AND users.deleted_at, so both filters below invert together.
        $deactivated = $request->boolean('deactivated');

        $userIdsQuery = DB::table('role_assignments')
            ->when(! $deactivated, function ($q) { $q->where('active', true); })
            ->when(! $allMode, function ($q) use ($agencyId, $centreIds) {
                $q->where(function ($qq) use ($agencyId, $centreIds) {
                    $qq->where('agency_id', $agencyId);
                    if (!empty($centreIds)) {
                        $qq->orWhereIn('centre_id', $centreIds);
                    }
                });
            });

        if ($roleFilter) {
            $userIdsQuery->where('role', $roleFilter);
        }

        $userIds = $userIdsQuery->pluck('user_id')->unique()->all();

        // Also include guardians of families at this agency's centres
        // (v22p96: always scoped to the active agency, platform_admin included).
        if ($roleFilter === null || $roleFilter === 'guardian') {
            if ($allMode) {
                $guardianUserIds = DB::table('guardians')->whereNotNull('user_id')->pluck('user_id')->all();
            } else {
                $guardianUserIds = DB::table('guardians')
                    ->join('families', 'families.id', '=', 'guardians.family_id')
                    ->whereIn('families.centre_id', $centreIds ?: [0])
                    ->pluck('guardians.user_id')->all();
            }
            $userIds = array_unique(array_merge($userIds, $guardianUserIds));
        }

        if (empty($userIds)) {
            return response()->json(['users' => []]);
        }

        $usersQuery = DB::table('users')
            ->whereIn('id', $userIds)
            ->when($deactivated,
                function ($q) { $q->whereNotNull('deleted_at'); },
                function ($q) { $q->whereNull('deleted_at'); });

        if ($searchQuery) {
            $usersQuery->where(function ($q) use ($searchQuery) {
                $q->where('email', 'like', "%{$searchQuery}%")
                  ->orWhere('first_name', 'like', "%{$searchQuery}%")
                  ->orWhere('last_name', 'like', "%{$searchQuery}%");
            });
        }

        $users = $usersQuery->orderBy('first_name')->limit(200)->get();

        // Get all roles for these users
        $allAssignments = DB::table('role_assignments')
            ->whereIn('user_id', $users->pluck('id'))
            ->when(! $deactivated, function ($q) { $q->where('active', true); })
            ->get()
            ->groupBy('user_id');

        $allGuardianLinks = DB::table('guardians')
            ->join('families', 'families.id', '=', 'guardians.family_id')
            ->whereIn('guardians.user_id', $users->pluck('id'))
            ->select('guardians.user_id', 'families.centre_id', 'families.family_name')
            ->get()
            ->groupBy('user_id');

        $result = $users->map(function ($u) use ($allAssignments, $allGuardianLinks) {
            $roles = ($allAssignments[$u->id] ?? collect())->pluck('role')->unique()->values()->all();
            $guardianLinks = $allGuardianLinks[$u->id] ?? collect();
            if ($guardianLinks->isNotEmpty() && !in_array('guardian', $roles)) {
                $roles[] = 'guardian';
            }

            // v22p3.4: surface profile_extras (incl. role_extras) so the
            // Manage modal can render educator credentials, etc.
            $extras = null;
            if (! empty($u->profile_extras)) {
                $extras = is_string($u->profile_extras)
                    ? (json_decode($u->profile_extras, true) ?: null)
                    : $u->profile_extras;
            }
            return [
                'id' => $u->id,
                'email' => $u->email,
                'username' => $u->username ?? null,
                'first_name' => $u->first_name,
                'last_name' => $u->last_name,
                'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                'phone' => $u->phone,
                'photo_url' => $u->photo_url,
                'status' => $u->status,
                'deactivated' => ! empty($u->deleted_at),
                'deactivated_at' => $u->deleted_at ?? null,
                'last_login_at' => $u->last_login_at,
                'onboarded_at' => $u->onboarded_at ?? null,
                'profile_extras' => $extras,
                'roles' => $roles,
                'created_at' => $u->created_at,
            ];
        });

        $rows = $result->all();

        // v22p45: CSV export — ?format=csv streams an Excel-friendly file
        if (strtolower((string) $request->query('format', '')) === 'csv') {
            return $this->streamCsv('users', [
                'ID', 'Name', 'Email', 'Phone', 'Status', 'Roles', 'Last login', 'Created',
            ], array_map(fn ($u) => [
                $u['id'], $u['name'], $u['email'], $u['phone'] ?? '',
                $u['status'], implode(' / ', $u['roles']),
                $u['last_login_at'] ?? '', $u['created_at'],
            ], $rows));
        }

        return response()->json(['users' => $rows]);
    }

    /**
     * v22p45 — shared CSV streamer. Used by listUsers + listFamilies and
     * any future list endpoint that wants a download. UTF-8 BOM so Excel
     * opens special characters cleanly.
     */
    private function streamCsv(string $label, array $header, array $rows): StreamedResponse
    {
        $filename = $label . '-' . now()->format('Y-m-d') . '.csv';
        return new StreamedResponse(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $r) fputcsv($out, $r);
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * GET /admin/username-available?username=X[&exclude_id=N]
     * Live check so the admin knows a username is free before submitting.
     * Case-insensitive, ignores soft-deleted rows; matches the create/update rules.
     */
    public function usernameAvailable(Request $request): JsonResponse
    {
        $u = trim((string) $request->query('username', ''));
        $exclude = (int) $request->query('exclude_id', 0);
        if ($u === '') {
            return response()->json(['available' => false, 'reason' => 'empty']);
        }
        if (! preg_match('/^[A-Za-z0-9._-]{3,50}$/', $u)) {
            return response()->json(['available' => false, 'reason' => 'invalid']);
        }
        $taken = DB::table('users')
            ->whereRaw('LOWER(username) = ?', [mb_strtolower($u)])
            ->whereNull('deleted_at')
            ->when($exclude > 0, fn ($q) => $q->where('id', '!=', $exclude))
            ->exists();
        return response()->json(['available' => ! $taken]);
    }

    public function createUser(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $data = $request->validate([
            // v23: email no longer has to be unique — one person can hold several
            // accounts under one email, told apart by an optional username at login.
            'email' => ['required', 'email', 'max:180'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', 'in:agency_admin,centre_director,educator,auditor,platform_admin,home_visitor,sales_rep'],
            'centre_id' => ['nullable', 'integer'],
            'send_invite' => ['nullable', 'boolean'],
            // Optional per-account username (unique). Needed when a person has more
            // than one account on the same email so they can sign in to the right one.
            'username' => ['nullable', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->whereNull('deleted_at')],
        ]);

        // v22p23: only platform_admin can mint another platform_admin.
        if ($data['role'] === 'platform_admin') {
            $callerIsPlatform = DB::table('role_assignments')
                ->where('user_id', $request->user()->id)
                ->where('role', 'platform_admin')
                ->where('active', true)
                ->exists();
            if (! $callerIsPlatform) {
                return response()->json([
                    'message' => 'Only platform admins can create platform admins.',
                    'errors' => ['role' => ['Insufficient privilege for that role.']],
                ], 403);
            }
        }

        // v22p18: non-admin roles MUST be tied to a specific centre — otherwise
        // the role_assignment row has no scope and the user becomes invisible
        // in listUsers (its WHERE clause requires agency_id OR centre_id).
        if (! in_array($data['role'], ['agency_admin', 'platform_admin', 'sales_rep'], true) && empty($data['centre_id'])) {
            return response()->json([
                'message' => 'Centre is required for this role.',
                'errors' => ['centre_id' => ['Please pick a centre for centre directors, educators, and auditors.']],
            ], 422);
        }

        // Validate centre belongs to this agency if provided
        if (!empty($data['centre_id'])) {
            $centre = DB::table('centres')->where('id', $data['centre_id'])->where('agency_id', $agencyId)->first();
            if (!$centre) return response()->json(['message' => 'Invalid centre'], 422);
        }

        // Random temporary password (user resets via invite email or forgot-password)
        $tempPassword = Str::random(24);

        // v22p31: if a soft-deleted user with this email already exists, revive
        // it rather than creating a duplicate row. Re-create-after-delete is the
        // most natural admin workflow for fixing a misadded user, and persisting
        // a stable user.id keeps audit logs, payments, and message history
        // attached to the same person on re-add.
        // v23: only revive a soft-deleted row by email when there is NO active
        // account already using that email. If an active account exists, this is a
        // deliberate SECOND account for the same person/email → create a new row.
        $activeSameEmail = DB::table('users')->where('email', $data['email'])->whereNull('deleted_at')->exists();
        $existing = $activeSameEmail ? null : DB::table('users')->where('email', $data['email'])->whereNotNull('deleted_at')->first();
        $isRevive = (bool) $existing;
        if ($isRevive) {
            $userId = (int) $existing->id;
            DB::table('users')->where('id', $userId)->update(array_filter([
                'deleted_at' => null,
                'password' => Hash::make($tempPassword),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'] ?? null,
                'username' => $data['username'] ?? null,
                'status' => 'invited',
                'updated_at' => now(),
            ], fn ($v) => $v !== null) + ['deleted_at' => null]);
            // Drop the old (deactivated) role_assignment rows for this user; a
            // fresh one is inserted below. This avoids a recreated educator
            // accidentally inheriting a stale platform_admin row.
            // Never strip roles from the protected super-admin account.
            if (!$this->isProtectedEmail($data['email'] ?? null)) {
                DB::table('role_assignments')->where('user_id', $userId)->delete();
            }
        } else {
            $userId = DB::table('users')->insertGetId([
                'email' => $data['email'],
                'username' => $data['username'] ?? null,
                'password' => Hash::make($tempPassword),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'] ?? null,
                'status' => 'invited',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // v22p18 + v22p23: stamp agency_id so the user is discoverable in
        // listUsers (filter requires agency_id OR centre_id). platform_admin
        // is exempt and gets both NULL since it spans the entire platform.
        $assignmentAgencyId = $data['role'] === 'platform_admin' ? null : $agencyId;
        $assignmentCentreId = (! in_array($data['role'], ['agency_admin', 'platform_admin', 'sales_rep'], true))
            ? ($data['centre_id'] ?? null)
            : null;
        DB::table('role_assignments')->insert([
            'user_id' => $userId,
            'role' => $data['role'],
            'agency_id' => $assignmentAgencyId,
            'centre_id' => $assignmentCentreId,
            'active' => 1,
            'created_at' => now(),
        ]);

        $this->audit($request->user()->id, $isRevive ? 'user.revived' : 'user.created', 'user', $userId, [
            'email' => $data['email'],
            'role' => $data['role'],
        ]);

        // Email the user a set-password invite. This was previously an
        // unimplemented TODO — invited users never received anything, which is
        // why enrollment invites silently "sent" but never arrived. Mirrors
        // PlatformController's agency-admin invite (password_resets token +
        // branded HTML + email_logs row).
        $inviteSent = false;
        if (!empty($data['send_invite'])) {
            try {
                $token = bin2hex(random_bytes(32));
                DB::table('password_resets')->insert([
                    'email'      => $data['email'],
                    'user_id'    => $userId,
                    'token'      => hash('sha256', $token),
                    'expires_at' => now()->addDays(7),
                    'created_at' => now(),
                ]);
                $agencyName = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?? 'Kiddietrac');
                $this->sendUserInvite($data['email'], $data['first_name'], $data['last_name'], $agencyName,
                    'https://app.kiddietrac.com/set-password.html?token=' . $token, (int) $agencyId);
                $inviteSent = true;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('User invite email failed', ['email' => $data['email'], 'error' => $e->getMessage()]);
            }
        }

        // Let the agency's admins + directors SEE that a user was added / invited,
        // in the portal (bell + notifications inbox). Best-effort — never blocks.
        try {
            $this->notifyStaffOfNewUser(
                (int) $agencyId,
                trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
                (string) $data['role'],
                $inviteSent,
                (int) $request->user()->id
            );
        } catch (\Throwable $e) { /* notification is best-effort */ }

        return response()->json([
            'id' => $userId,
            'message' => $isRevive
                ? 'User restored — they previously existed and have been reinstated with the role you selected.'
                : 'User created',
            'revived' => $isRevive,
            'invite_sent' => $inviteSent,
            'note' => $inviteSent
                ? 'An invite email with a set-password link has been sent.'
                : 'Have the user click "Forgot password" on the login page to set their password.',
        ], 201);
    }

    /**
     * Email a newly-created user a branded set-password link. Synchronous send
     * (sendmail) + logs to email_logs with a tracking token; the X-KT-Logged
     * header stops the global MessageSent listener writing a duplicate row.
     */
    private function sendUserInvite(string $email, string $firstName, string $lastName, string $agencyName, string $link, ?int $agencyId = null): void
    {
        $trackToken = bin2hex(random_bytes(16));
        $apiBase = preg_replace('#/api/v1/?$#', '', rtrim((string) config('app.url', 'https://api.kiddietrac.com'), '/'));
        $pixel = '<img src="' . $apiBase . '/api/v1/e/o/' . $trackToken . '" width="1" height="1" alt="" style="display:none;border:0;">';
        $first = htmlspecialchars($firstName);
        $safeLink = htmlspecialchars($link);
        $safeAgency = htmlspecialchars($agencyName);

        // Wording comes from the agency-editable "invite" template (#77) when set;
        // falls back to the built-in copy. Guarded so a registry error never blocks
        // an invite from going out.
        $intro = ''; $instructions = ''; $signoff = '';
        if ($agencyId) {
            try {
                $d = ['name' => $firstName ?: 'there', 'agency_name' => $agencyName, 'portal_url' => 'https://app.kiddietrac.com'];
                $intro        = \App\Support\EmailTemplates::block($agencyId, 'invite', 'intro', $d);
                $instructions = \App\Support\EmailTemplates::block($agencyId, 'invite', 'instructions', $d);
                $signoff      = \App\Support\EmailTemplates::block($agencyId, 'invite', 'signoff', $d);
            } catch (\Throwable $e) { /* fall back to built-in copy */ }
        }
        if (trim(strip_tags($intro)) === '')        $intro = 'Hi ' . $first . ', you\'ve been invited to <strong>' . $safeAgency . '</strong> on Kiddietrac.';
        if (trim(strip_tags($instructions)) === '') $instructions = 'Set your password below and you\'ll be taken straight into your account.';

        $body = '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">' . $intro . '</p>'
            . '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;">' . $instructions . '</p>'
            . \App\Services\EmailTemplate::button('Set my password →', $link)
            . (trim(strip_tags($signoff)) !== '' ? '<p style="margin:16px 0 0;font-size:15px;line-height:1.6;">' . $signoff . '</p>' : '')
            . '<p style="margin:16px 0 0;font-size:12px;color:#64748B;">Or paste this into your browser:<br>'
            . '<a href="' . $safeLink . '" style="color:#1F6080;">' . $safeLink . '</a></p>'
            . '<p style="margin:14px 0 0;font-size:12px;color:#94A3B8;">This link expires in 7 days. If you weren\'t expecting this, you can ignore this email.</p>'
            . $pixel;

        $html = \App\Services\EmailTemplate::wrap(null, $body, [
            'eyebrow'   => 'YOU\'RE INVITED',
            'title'     => $agencyName,
            'subtitle'  => 'Set your password to get started',
            'preheader' => 'Set your password and sign in to ' . $agencyName . ' on Kiddietrac.',
        ]);

        // Send in the background so the invite request returns immediately
        // instead of blocking ~1.3–5.5s on sendmail. Capture only scalars.
        $recipientName = trim($firstName . ' ' . $lastName);
        dispatch(function () use ($email, $recipientName, $html) {
            \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($email, $recipientName) {
                $m->to($email, $recipientName)
                  ->from('noreply@kiddietrac.com', 'KiddieTrac')
                  ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
                  ->subject('You\'re invited to Kiddietrac — set your password');
                $m->getHeaders()->addTextHeader('X-KT-Logged', '1');
                // Exempt from the not-onboarded gate: the invite MUST reach a user
                // who hasn't accepted yet — that's the whole point of it.
                $m->getHeaders()->addTextHeader('X-KT-Invite', '1');
                $m->getHeaders()->addTextHeader('List-Unsubscribe', '<mailto:support@kiddietrac.com>');
            });
        })->onQueue('mail');
        if (\Illuminate\Support\Facades\Schema::hasTable('email_logs')) {
            DB::table('email_logs')->insert([
                'to_email' => $email, 'to_name' => trim($firstName . ' ' . $lastName),
                'from_email' => 'noreply@kiddietrac.com', 'subject' => 'You\'re invited to Kiddietrac — set your password',
                'mailer' => config('mail.default'), 'status' => 'sent', 'tracking_token' => $trackToken,
                'opens' => 0, 'created_at' => now(),
            ]);
        }
    }

    public function updateUser(Request $request, int $userId): JsonResponse
    {
        // A user may ALWAYS edit their own account, regardless of agency scope — a
        // platform-level superadmin isn't a member of any single agency, so the
        // tenancy check below would otherwise 403 them out of their own record.
        $self = ($userId === (int) $request->user()->id);
        if (! $self) {
            $agencyId = $this->getAgencyId($request);
            if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

            // v22p30: route the tenancy check through the shared helper so platform_admin
            // gets the same cross-agency bypass as destroyUser/resetUserPassword/etc.
            // Helper also covers the guardian path (families.centre_id), which the prior
            // inline check missed — agency_admins can now also update guardians via this
            // endpoint, matching the rest of the surface.
            if (!$this->userBelongsToAgency($userId, $agencyId)) {
                return response()->json(['message' => 'User not in your agency'], 403);
            }
        }

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'string', 'max:80'],
            // Email is intentionally NOT globally unique (multi-account-per-email); validate
            // format only. Admins/directors/super-admin can correct a user's login email.
            'email' => ['sometimes', 'email', 'max:190'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'status' => ['sometimes', 'in:active,invited,not_invited,suspended,deactivated'],
        ]);

        $data['updated_at'] = now();
        DB::table('users')->where('id', $userId)->update($data);

        $this->audit($request->user()->id, 'user.updated', 'user', $userId, $data);
        return response()->json(['message' => 'User updated']);
    }

    /** platform_admin / agency_admin — profile extras + notes for a user. */
    public function userProfile(Request $request, int $userId): JsonResponse
    {
        // A user may always view their own full record (self-service account view).
        if ($userId !== (int) $request->user()->id) {
            $agencyId = $this->getAgencyId($request);
            if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
            if (!$this->userBelongsToAgency($userId, $agencyId)) return response()->json(['message' => 'User not in your agency'], 403);
        }
        $p = DB::table('user_profiles')->where('user_id', $userId)->first();
        $notes = DB::table('user_notes')->where('user_id', $userId)->orderByDesc('created_at')->limit(200)->get();
        $u = DB::table('users')->where('id', $userId)->first();

        // #11 — the COMPLETE record for the detail view: core account fields +
        // user_profiles + everything captured at onboarding (users.profile_extras
        // JSON, including address parts, emergency contacts and role-specific
        // extras). Returned as an ordered [label => value] map so the UI can show
        // every field without hard-coding which keys exist.
        $record = [];
        $put = function ($label, $value) use (&$record) {
            // A list answer (multi-select at onboarding) is worth showing, not
            // dropping - flatten it to a readable string. Nested structures are
            // the only thing skipped, and those have their own handling below.
            if (is_array($value)) {
                $flat = array_filter($value, static fn ($v) => is_scalar($v) && $v !== '');
                $value = count($flat) === count($value) ? implode(', ', $flat) : null;
            }
            $value = is_string($value) ? trim($value) : $value;
            if ($value !== null && $value !== '' && $value !== []) {
                $record[$label] = $value;
            }
        };
        // Onboarding keys are snake_case, so a plain ucwords() prints "Rece Number"
        // and "Cpr Expiry". Restore the acronyms the childcare sector actually uses.
        $labelise = function ($k) {
            $label = ucwords(str_replace('_', ' ', (string) $k));
            return preg_replace_callback('/(rece|cpr|dob|id|sin|ece)/i',
                static fn ($m) => strtoupper($m[1]), $label);
        };
        $extras = [];
        if ($u && $u->profile_extras) {
            $decoded = json_decode($u->profile_extras, true);
            if (is_array($decoded)) $extras = $decoded;
        }
        if ($u) {
            $put('First name', $u->first_name);
            $put('Last name', $u->last_name);
            $put('Preferred name', $u->preferred_name);
            $put('Email', $u->email);
            $put('Username', $u->username);
            $put('Phone', $u->phone);
            $put('Date of birth', ($p->date_of_birth ?? null) ?: ($u->date_of_birth ?? null));
            $put('Status', $u->status);
            $put('Language', $u->locale);
            $put('Timezone', $u->timezone);
            $put('Onboarded', $u->onboarded_at);
            $put('Last login', $u->last_login_at);
            $put('Sex', $u->sex ?? null);
            if (($u->pay_rate ?? null) !== null && $u->pay_rate !== '') {
                $put('Pay rate', '$' . number_format((float) $u->pay_rate, 2)
                    . ($u->pay_type ? ' / ' . $u->pay_type : ''));
            }
        }

        // BIO. A home-daycare provider writes this during onboarding and it is
        // stored against their CENTRE (centres.provider_bio), matched to them by
        // email - never on the user row. That is why it was missing from a record
        // otherwise assembled from users + user_profiles + profile_extras.
        // PATCH /auth/me/provider-bio edits the same column, so this stays current.
        $bio = null;
        if ($u && !empty($u->email)) {
            $bio = DB::table('centres')
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $u->email))])
                ->whereNull('deleted_at')
                ->value('provider_bio');
        }
        // Any other role that gains a bio later writes it into profile_extras;
        // read both so this does not need revisiting.
        if (!$bio) $bio = $extras['bio'] ?? ($extras['role_extras']['bio'] ?? null);
        $put('Bio', $bio);
        // Address — prefer the structured onboarding parts, fall back to the flat field.
        $addr = implode(', ', array_filter([
            $extras['address_line1'] ?? null,
            $extras['address_line2'] ?? null,
            $extras['city'] ?? null,
            $extras['province'] ?? null,
            $extras['postal_code'] ?? null,
        ]));
        $put('Address', $addr ?: ($p->address ?? null));
        // Emergency contact — from profile row or onboarding extras.
        $ecN = ($p->emergency_contact_name ?? null) ?: ($extras['emergency_contact_name'] ?? null);
        $ecP = ($p->emergency_contact_phone ?? null) ?: ($extras['emergency_contact_phone'] ?? null);
        $ecR = $p->emergency_contact_relation ?? null;
        if ($ecN) $put('Emergency contact', $ecN . ($ecR ? ' (' . $ecR . ')' : '') . ($ecP ? ' · ' . $ecP : ''));
        // Additional contacts captured at onboarding.
        foreach (($extras['extra_contacts'] ?? []) as $c) {
            if (!empty($c['name'])) $put('Contact: ' . $c['name'], ($c['relation'] ?? '') . (!empty($c['phone']) ? ' · ' . $c['phone'] : ''));
        }
        // Role-specific onboarding answers (dynamic keys).
        foreach (($extras['role_extras'] ?? []) as $k => $v) {
            if ($v === null || $v === '') continue;
            $put($labelise($k), is_bool($v) ? ($v ? 'Yes' : 'No') : $v);
        }
        // Any other top-level onboarding extras we didn't explicitly place.
        $known = ['address_line1', 'address_line2', 'city', 'province', 'postal_code', 'emergency_contact_name', 'emergency_contact_phone', 'extra_contacts', 'role_extras'];
        foreach ($extras as $k => $v) {
            if (in_array($k, $known, true) || $v === null || $v === '') continue;
            $put($labelise($k), is_bool($v) ? ($v ? 'Yes' : 'No') : $v);
        }

        return response()->json([
            'profile' => $p ? [
                'address' => $p->address,
                'date_of_birth' => $p->date_of_birth,
                'emergency_contact_name' => $p->emergency_contact_name,
                'emergency_contact_phone' => $p->emergency_contact_phone,
                'emergency_contact_relation' => $p->emergency_contact_relation,
            ] : null,
            'record' => $record,
            // Raw structured fields for the editable form (so edits write back to
            // the SAME place the Full record reads from — profile_extras + users).
            'editable' => [
                'phone'          => $u->phone ?? null,
                'direct_phone'   => $extras['direct_phone'] ?? null,
                'home_phone'     => $extras['home_phone'] ?? null,
                'address_line1'  => $extras['address_line1'] ?? null,
                'address_line2'  => $extras['address_line2'] ?? null,
                'city'           => $extras['city'] ?? null,
                'province'       => $extras['province'] ?? null,
                'postal_code'    => $extras['postal_code'] ?? null,
                'date_of_birth'  => ($p->date_of_birth ?? null) ?: ($u->date_of_birth ?? null),
                'emergency_contact_name'     => ($p->emergency_contact_name ?? null) ?: ($extras['emergency_contact_name'] ?? null),
                'emergency_contact_phone'    => ($p->emergency_contact_phone ?? null) ?: ($extras['emergency_contact_phone'] ?? null),
                'emergency_contact_relation' => $p->emergency_contact_relation ?? null,
            ],
            'notes' => $notes,
        ]);
    }

    /** Upsert a user's profile extras. */
    public function updateUserProfile(Request $request, int $userId): JsonResponse
    {
        // Self-edit is always allowed (see updateUser).
        if ($userId !== (int) $request->user()->id) {
            $agencyId = $this->getAgencyId($request);
            if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
            if (!$this->userBelongsToAgency($userId, $agencyId)) return response()->json(['message' => 'User not in your agency'], 403);
        }
        $data = $request->validate([
            // Core phone lives on the user row.
            'phone'         => ['sometimes', 'nullable', 'string', 'max:40'],
            'direct_phone'  => ['sometimes', 'nullable', 'string', 'max:40'],
            'home_phone'    => ['sometimes', 'nullable', 'string', 'max:40'],
            // Structured address (the Full-record source).
            'address'       => ['sometimes', 'nullable', 'string', 'max:300'],   // legacy single field
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:200'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'city'          => ['sometimes', 'nullable', 'string', 'max:80'],
            'province'      => ['sometimes', 'nullable', 'string', 'max:60'],
            'postal_code'   => ['sometimes', 'nullable', 'string', 'max:20'],
            'date_of_birth' => ['sometimes', 'nullable', 'date'],
            'emergency_contact_name'     => ['sometimes', 'nullable', 'string', 'max:160'],
            'emergency_contact_phone'    => ['sometimes', 'nullable', 'string', 'max:60'],
            'emergency_contact_relation' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        // 1) Core phone on the user row.
        if (array_key_exists('phone', $data)) {
            DB::table('users')->where('id', $userId)->update(['phone' => $data['phone'], 'updated_at' => now()]);
        }

        // 2) profile_extras — the SAME place the Full record reads from, so edits
        //    actually show up there (this was the disconnect).
        $u = DB::table('users')->where('id', $userId)->first();
        $extras = [];
        if ($u && $u->profile_extras) {
            $decoded = json_decode((string) $u->profile_extras, true);
            if (is_array($decoded)) $extras = $decoded;
        }
        foreach (['direct_phone', 'home_phone', 'address_line1', 'address_line2', 'city', 'province', 'postal_code', 'emergency_contact_name', 'emergency_contact_phone'] as $k) {
            if (array_key_exists($k, $data)) {
                $extras[$k] = ($data[$k] === '' ? null : $data[$k]);
            }
        }
        DB::table('users')->where('id', $userId)->update(['profile_extras' => json_encode($extras), 'updated_at' => now()]);

        // 3) user_profiles — keep in sync for anything still reading it. Compose the
        //    flat address from the structured parts when those were supplied.
        $addr = $data['address'] ?? null;
        $parts = array_filter([$data['address_line1'] ?? null, $data['address_line2'] ?? null, $data['city'] ?? null, $data['province'] ?? null, $data['postal_code'] ?? null]);
        if ($parts) $addr = implode(', ', $parts);
        $up = ['user_id' => $userId, 'updated_at' => now()];
        if ($addr !== null) $up['address'] = $addr;
        foreach (['date_of_birth', 'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation'] as $k) {
            if (array_key_exists($k, $data)) $up[$k] = $data[$k];
        }
        if (DB::table('user_profiles')->where('user_id', $userId)->exists()) {
            DB::table('user_profiles')->where('user_id', $userId)->update($up);
        } else {
            $up['created_at'] = now();
            DB::table('user_profiles')->insert($up);
        }

        $this->audit($request->user()->id, 'user.profile_updated', 'user', $userId, []);
        return response()->json(['message' => 'Profile saved']);
    }

    /** Add a timestamped note to a user. */
    public function addUserNote(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (!$this->userBelongsToAgency($userId, $agencyId)) return response()->json(['message' => 'User not in your agency'], 403);
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $actor = $request->user();
        $name = trim((string) (($actor->first_name ?? '') . ' ' . ($actor->last_name ?? ''))) ?: ($actor->name ?? 'Admin');
        $id = DB::table('user_notes')->insertGetId([
            'user_id' => $userId,
            'note' => $data['note'],
            'created_by' => $actor->id,
            'created_by_name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($actor->id, 'user.note_added', 'user', $userId, []);
        return response()->json(['message' => 'Note added', 'id' => $id]);
    }

    public function setUserRole(Request $request, int $userId): JsonResponse
    {
        // Editing your own roles doesn't require an active agency (a platform-level
        // superadmin has none). The "never strip the protected super-admin" guard
        // below still applies, so you can't accidentally lock yourself out.
        $agencyId = $this->getAgencyId($request);
        if ($userId !== (int) $request->user()->id && !$agencyId) {
            return response()->json(['message' => 'No agency access'], 403);
        }

        $data = $request->validate([
            'role' => ['required', 'in:agency_admin,centre_director,educator,auditor,platform_admin,home_visitor,sales_rep'],
            'centre_id' => ['nullable', 'integer'],
            'active' => ['nullable', 'boolean'],
        ]);

        // v22p23: platform_admin role can only be granted by a platform_admin.
        if ($data['role'] === 'platform_admin') {
            $callerIsPlatform = DB::table('role_assignments')
                ->where('user_id', $request->user()->id)
                ->where('role', 'platform_admin')
                ->where('active', true)
                ->exists();
            if (! $callerIsPlatform) {
                return response()->json([
                    'message' => 'Only platform admins can grant the platform_admin role.',
                    'errors' => ['role' => ['Insufficient privilege.']],
                ], 403);
            }
        }

        if (!empty($data['centre_id'])) {
            $centre = DB::table('centres')->where('id', $data['centre_id'])->where('agency_id', $agencyId)->first();
            if (!$centre) return response()->json(['message' => 'Invalid centre'], 422);
        }

        // v22p23: scope handling mirrors createUser — platform_admin spans all
        // (both NULL); agency_admin scopes by agency only; others by centre too.
        $assignmentAgencyId = $data['role'] === 'platform_admin' ? null : $agencyId;
        $assignmentCentreId = (! in_array($data['role'], ['agency_admin', 'platform_admin', 'sales_rep'], true))
            ? ($data['centre_id'] ?? null)
            : null;

        DB::table('role_assignments')->updateOrInsert(
            [
                'user_id' => $userId,
                'role' => $data['role'],
                'agency_id' => $assignmentAgencyId,
                'centre_id' => $assignmentCentreId,
            ],
            ['active' => $data['active'] ?? true]
        );

        $this->audit($request->user()->id, 'user.role_set', 'user', $userId, $data);
        return response()->json(['message' => 'Role updated']);
    }

    // ════════════════════════════════════════════════════════════════
    //   FAMILIES
    // ════════════════════════════════════════════════════════════════

    public function listFamilies(Request $request)
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $centreIds = $this->getCentreIds($agencyId);
        $centreFilter = $request->input('centre_id');

        $query = DB::table('families')
            ->leftJoin('centres', 'centres.id', '=', 'families.centre_id')
            ->whereIn('families.centre_id', $centreIds ?: [0])
            ->whereNull('families.deleted_at')
            ->select('families.*', 'centres.name as centre_name');

        if ($centreFilter) {
            $query->where('families.centre_id', $centreFilter);
        }

        $families = $query->orderBy('families.family_name')->limit(200)->get();

        $result = $families->map(function ($f) {
            $childCount = DB::table('children')
                ->where('family_id', $f->id)
                ->whereNull('deleted_at')
                ->count();
            $guardianCount = DB::table('guardians')->where('family_id', $f->id)->count();
            // Suspended = at least one guardian account is blocked from logging in.
            $suspendedGuardians = DB::table('guardians')
                ->join('users', 'users.id', '=', 'guardians.user_id')
                ->where('guardians.family_id', $f->id)
                ->where('users.status', 'suspended')
                ->count();
            $balance = DB::table('invoices')
                ->where('family_id', $f->id)
                ->whereIn('status', ['sent', 'partial', 'overdue'])
                ->sum('balance_due');

            return [
                'id' => $f->id,
                'family_name' => $f->family_name,
                'centre_id' => $f->centre_id,
                'centre_name' => $f->centre_name,
                'primary_phone' => $f->primary_phone,
                'primary_email' => $f->primary_email,
                'city' => $f->city,
                'child_count' => $childCount,
                'guardian_count' => $guardianCount,
                'suspended' => $suspendedGuardians > 0,
                'outstanding_balance' => (float) $balance,
                'created_at' => $f->created_at,
            ];
        });

        $rows = $result->all();

        // v22p45: CSV export — ?format=csv
        if (strtolower((string) $request->query('format', '')) === 'csv') {
            return $this->streamCsv('families', [
                'ID', 'Family name', 'Centre', 'Primary email', 'Primary phone',
                'Address', 'City', 'Children', 'Guardians', 'Outstanding balance',
            ], array_map(fn ($f) => [
                $f['id'] ?? '',
                $f['family_name'] ?? '',
                $f['centre_name'] ?? '',
                $f['primary_email'] ?? '',
                $f['primary_phone'] ?? '',
                $f['address_line1'] ?? '',
                $f['city'] ?? '',
                $f['child_count'] ?? 0,
                $f['guardian_count'] ?? 0,
                $f['outstanding_balance'] ?? 0,
            ], $rows));
        }

        return response()->json(['families' => $rows]);
    }

    public function showFamily(Request $request, int $familyId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $centreIds = $this->getCentreIds($agencyId);

        $family = DB::table('families')
            ->whereIn('centre_id', $centreIds ?: [0])
            ->where('id', $familyId)
            ->whereNull('deleted_at')
            ->first();

        if (!$family) return response()->json(['message' => 'Not found'], 404);

        $children = DB::table('children')
            ->where('family_id', $familyId)
            ->whereNull('deleted_at')
            ->get();

        $guardians = DB::table('guardians')
            ->join('users', 'users.id', '=', 'guardians.user_id')
            ->where('guardians.family_id', $familyId)
            ->select('guardians.*', 'users.email', 'users.first_name', 'users.last_name', 'users.phone', 'users.status', 'users.photo_url')
            ->get();

        $emergency = DB::table('emergency_contacts')
            ->where('family_id', $familyId)
            ->get();

        return response()->json([
            'family' => $family,
            'children' => $children,
            'guardians' => $guardians,
            'emergency_contacts' => $emergency,
        ]);
    }

    /** Family (via centre) belongs to the caller's agency? Returns the family row or null. */
    private function familyInAgency(Request $request, int $familyId)
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) return null;
        $centreIds = $this->getCentreIds($agencyId);
        return DB::table('families')->where('id', $familyId)->whereIn('centre_id', $centreIds ?: [0])->whereNull('deleted_at')->first();
    }

    /** Edit a guardian's user (name/phone) + guardian row (relationship/pickup). */
    public function updateGuardian(Request $request, int $guardian): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency access'], 403);
        $centreIds = $this->getCentreIds($agencyId);
        $g = DB::table('guardians as gu')->join('families as f', 'f.id', '=', 'gu.family_id')
            ->where('gu.id', $guardian)->whereIn('f.centre_id', $centreIds ?: [0])->whereNull('f.deleted_at')
            ->select('gu.id', 'gu.user_id')->first();
        if (! $g) return response()->json(['message' => 'Guardian not in your agency'], 403);

        $data = $request->validate([
            'first_name'   => ['sometimes', 'string', 'max:80'],
            'last_name'    => ['sometimes', 'nullable', 'string', 'max:80'],
            'phone'        => ['sometimes', 'nullable', 'string', 'max:40'],
            'relationship' => ['sometimes', 'string', 'max:40'],
            'can_pickup'   => ['sometimes', 'boolean'],
            'is_primary'   => ['sometimes', 'boolean'],
        ]);
        $userUpd = [];
        foreach (['first_name', 'last_name', 'phone'] as $k) { if (array_key_exists($k, $data)) $userUpd[$k] = $data[$k]; }
        if ($userUpd) { $userUpd['updated_at'] = now(); DB::table('users')->where('id', $g->user_id)->update($userUpd); }
        $gUpd = [];
        foreach (['relationship', 'can_pickup', 'is_primary'] as $k) { if (array_key_exists($k, $data)) $gUpd[$k] = $data[$k]; }
        if ($gUpd) DB::table('guardians')->where('id', $guardian)->update($gUpd);
        return response()->json(['ok' => true]);
    }

    private function emergencyRules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:120'],
            'relationship' => ['nullable', 'string', 'max:60'],
            'phone'        => ['nullable', 'string', 'max:40'],
            'alt_phone'    => ['nullable', 'string', 'max:40'],
            'can_pickup'   => ['nullable', 'boolean'],
        ];
    }

    public function addEmergencyContact(Request $request, int $family): JsonResponse
    {
        if (! $this->familyInAgency($request, $family)) return response()->json(['message' => 'Family not in your agency'], 403);
        $data = $request->validate($this->emergencyRules());
        $id = DB::table('emergency_contacts')->insertGetId([
            'family_id' => $family, 'name' => $data['name'], 'relationship' => $data['relationship'] ?? null,
            'phone' => $data['phone'] ?? null, 'alt_phone' => $data['alt_phone'] ?? null,
            'can_pickup' => ! empty($data['can_pickup']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function updateEmergencyContact(Request $request, int $id): JsonResponse
    {
        $ec = DB::table('emergency_contacts')->where('id', $id)->first();
        if (! $ec || ! $this->familyInAgency($request, (int) $ec->family_id)) return response()->json(['message' => 'Not in your agency'], 403);
        $data = $request->validate($this->emergencyRules());
        DB::table('emergency_contacts')->where('id', $id)->update([
            'name' => $data['name'], 'relationship' => $data['relationship'] ?? null,
            'phone' => $data['phone'] ?? null, 'alt_phone' => $data['alt_phone'] ?? null,
            'can_pickup' => ! empty($data['can_pickup']), 'updated_at' => now(),
        ]);
        return response()->json(['ok' => true]);
    }

    public function deleteEmergencyContact(Request $request, int $id): JsonResponse
    {
        $ec = DB::table('emergency_contacts')->where('id', $id)->first();
        if (! $ec || ! $this->familyInAgency($request, (int) $ec->family_id)) return response()->json(['message' => 'Not in your agency'], 403);
        DB::table('emergency_contacts')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * v22p11 — agency_admin can create a family at any centre in their agency.
     */
    public function createFamily(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency context'], 403);

        $data = $request->validate([
            'family_name' => ['required', 'string', 'max:120'],
            'centre_id' => ['required', 'integer'],
            'primary_email' => ['nullable', 'email', 'max:180'],
            'primary_phone' => ['nullable', 'string', 'max:40'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', 'string', 'max:40'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'preferred_lang' => ['nullable', 'string', 'max:10'],
            'billing_split' => ['nullable', 'in:single,split_50_50,custom'],
            'notes' => ['nullable', 'string'],
            // v22p36: optional nested guardians + children for the multi-step wizard.
            // Omitting them keeps the legacy single-step behaviour (family only).
            'guardians' => ['nullable', 'array'],
            'guardians.*.email' => ['required_with:guardians', 'email', 'max:180'],
            'guardians.*.first_name' => ['required_with:guardians', 'string', 'max:80'],
            'guardians.*.last_name' => ['nullable', 'string', 'max:80'],
            'guardians.*.phone' => ['nullable', 'string', 'max:40'],
            'guardians.*.relationship' => ['nullable', 'in:mother,father,guardian,grandparent,foster,other'],
            'guardians.*.is_primary' => ['nullable', 'boolean'],
            'guardians.*.can_pickup' => ['nullable', 'boolean'],
            'children' => ['nullable', 'array'],
            'children.*.first_name' => ['required_with:children', 'string', 'max:80'],
            'children.*.last_name' => ['required_with:children', 'string', 'max:80'],
            'children.*.preferred_name' => ['nullable', 'string', 'max:80'],
            'children.*.date_of_birth' => ['required_with:children', 'date'],
            'children.*.gender' => ['nullable', 'in:female,male,non_binary,prefer_not_to_say,other'],
            'children.*.enrollment_status' => ['nullable', 'in:waitlist,enrolled,withdrawn,graduated'],
            // v22p… onboarding wizard — rich child + emergency-contact data.
            'children.*.allergies' => ['nullable', 'string', 'max:1000'],
            'children.*.dietary_restrictions' => ['nullable', 'string', 'max:1000'],
            'children.*.medical_notes' => ['nullable', 'string', 'max:2000'],
            'children.*.doctor_name' => ['nullable', 'string', 'max:120'],
            'children.*.doctor_phone' => ['nullable', 'string', 'max:40'],
            'children.*.school' => ['nullable', 'string', 'max:160'],
            'emergency_contacts' => ['nullable', 'array'],
            'emergency_contacts.*.name' => ['required_with:emergency_contacts', 'string', 'max:120'],
            'emergency_contacts.*.relationship' => ['nullable', 'string', 'max:60'],
            'emergency_contacts.*.phone' => ['nullable', 'string', 'max:40'],
            'emergency_contacts.*.alt_phone' => ['nullable', 'string', 'max:40'],
            'emergency_contacts.*.can_pickup' => ['nullable', 'boolean'],
        ]);

        // Centre must belong to this agency.
        $centreOwned = DB::table('centres')
            ->where('id', $data['centre_id'])
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->exists();
        if (! $centreOwned) {
            return response()->json([
                'message' => 'Centre not in your agency.',
                'errors' => ['centre_id' => ['You do not have access to that centre.']],
            ], 422);
        }

        $guardians = $data['guardians'] ?? [];
        $children = $data['children'] ?? [];
        $emergency = $data['emergency_contacts'] ?? [];
        unset($data['guardians'], $data['children'], $data['emergency_contacts']);

        // Reject duplicate guardian emails within the same submission so we do
        // not create two guardian rows that collapse onto one user.
        $seenEmails = [];
        foreach ($guardians as $g) {
            $em = strtolower(trim($g['email']));
            if (isset($seenEmails[$em])) {
                return response()->json([
                    'message' => 'Duplicate guardian email in submission: '.$g['email'],
                    'errors' => ['guardians' => ['Each guardian must have a unique email address.']],
                ], 422);
            }
            $seenEmails[$em] = true;
        }

        $newGuardians = [];   // freshly-created guardians to invite by email
        try {
            $result = DB::transaction(function () use ($data, $guardians, $children, $emergency, $agencyId, &$newGuardians) {
                $famId = DB::table('families')->insertGetId(array_merge($data, [
                    'preferred_lang' => $data['preferred_lang'] ?? 'en-CA',
                    'billing_split' => $data['billing_split'] ?? 'single',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));

                $guardianIds = [];
                foreach (array_values($guardians) as $i => $g) {
                    $email = strtolower(trim($g['email']));
                    // Reuse an existing (non-deleted) user with this email so we
                    // never duplicate accounts or clobber an existing login.
                    $existing = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->whereNull('deleted_at')->first();
                    if ($existing) {
                        $uid = (int) $existing->id;
                        DB::table('users')->where('id', $uid)->update(array_filter([
                            'first_name' => $g['first_name'] ?? null,
                            'last_name' => $g['last_name'] ?? null,
                            'phone' => $g['phone'] ?? null,
                            'updated_at' => now(),
                        ], fn ($v) => $v !== null));
                    } else {
                        $temp = Str::random(12);
                        $uid = (int) DB::table('users')->insertGetId([
                            'email' => $g['email'],
                            'password' => Hash::make($temp),
                            'first_name' => $g['first_name'],
                            'last_name' => $g['last_name'] ?? '',
                            'phone' => $g['phone'] ?? null,
                            'locale' => 'en-CA',
                            'timezone' => 'America/Toronto',
                            'status' => 'invited',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $newGuardians[] = ['uid' => $uid, 'email' => $g['email'], 'first_name' => $g['first_name'], 'temp' => $temp];
                    }

                    DB::table('role_assignments')->updateOrInsert(
                        ['user_id' => $uid, 'role' => 'guardian', 'agency_id' => $agencyId, 'centre_id' => null],
                        ['active' => true, 'created_at' => now()]
                    );

                    $isPrimary = ! empty($g['is_primary']) || $i === 0;
                    DB::table('guardians')->updateOrInsert(
                        ['family_id' => $famId, 'user_id' => $uid],
                        [
                            'relationship' => $g['relationship'] ?? 'guardian',
                            'is_primary' => $isPrimary,
                            'can_pickup' => array_key_exists('can_pickup', $g) ? (bool) $g['can_pickup'] : true,
                            'can_receive_billing' => true,
                            'billing_share_pct' => $isPrimary ? 100.00 : 0.00,
                            'created_at' => now(),
                        ]
                    );
                    $guardianIds[] = $uid;
                }

                $childIds = [];
                foreach ($children as $c) {
                    $status = $c['enrollment_status'] ?? 'enrolled';
                    $childIds[] = (int) DB::table('children')->insertGetId([
                        'family_id' => $famId,
                        'first_name' => $c['first_name'],
                        'last_name' => $c['last_name'],
                        'preferred_name' => $c['preferred_name'] ?? $c['first_name'],
                        'date_of_birth' => $c['date_of_birth'],
                        'gender' => $c['gender'] ?? 'prefer_not_to_say',
                        'photo_url' => $c['photo_url'] ?? null,
                        // allergies + dietary_restrictions are JSON (json_valid CHECK) — store as arrays.
                        'allergies' => ! empty($c['allergies']) ? json_encode(array_values(array_filter(array_map('trim', explode(',', (string) $c['allergies']))))) : null,
                        'dietary_restrictions' => ! empty($c['dietary_restrictions']) ? json_encode(array_values(array_filter(array_map('trim', explode(',', (string) $c['dietary_restrictions']))))) : null,
                        'medical_notes' => $c['medical_notes'] ?? null,
                        'doctor_name' => $c['doctor_name'] ?? null,
                        'doctor_phone' => $c['doctor_phone'] ?? null,
                        'school' => $c['school'] ?? null,
                        'preferred_lang' => 'en-CA',
                        'enrollment_status' => $status,
                        'enrolled_at' => $status === 'enrolled' ? now()->toDateString() : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $emergencyIds = [];
                if (\Illuminate\Support\Facades\Schema::hasTable('emergency_contacts')) {
                    foreach ($emergency as $ec) {
                        $emergencyIds[] = (int) DB::table('emergency_contacts')->insertGetId([
                            'family_id' => $famId,
                            'name' => $ec['name'],
                            'relationship' => $ec['relationship'] ?? null,
                            'phone' => $ec['phone'] ?? null,
                            'alt_phone' => $ec['alt_phone'] ?? null,
                            'can_pickup' => ! empty($ec['can_pickup']),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                return ['family_id' => $famId, 'guardian_ids' => $guardianIds, 'child_ids' => $childIds];
            });
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Could not create family: '.$e->getMessage(),
            ], 500);
        }

        $this->audit($request->user()->id, 'create_family', 'family', $result['family_id'], [
            'centre_id' => $data['centre_id'],
            'guardians' => count($result['guardian_ids']),
            'children' => count($result['child_ids']),
        ]);

        // Invite each NEW guardian by email — login + a nudge to complete their
        // family profile (onboarding). Uses AccountNotice (carries X-KT-Invite so
        // it reaches a not-yet-onboarded user); agency suppression still applies.
        $invited = 0;
        foreach ($newGuardians as $g) {
            $ok = $this->sendAccountEmail(
                $g['email'],
                $g['first_name'] ?: 'there',
                'Welcome to Kiddietrac — set up your family account',
                "You've been added to Kiddietrac by your childcare provider.\n\n"
                . "Sign in at https://app.kiddietrac.com to activate your account:\n\n"
                . "Email: {$g['email']}\n"
                . "Temporary password: {$g['temp']}\n\n"
                . "On your first sign-in you'll be guided through a short setup to review your family "
                . "details, add anything missing, and upload a profile photo. We recommend changing your "
                . "password afterwards via 'Forgot password'."
            );
            if ($ok) $invited++;
        }

        // Provider welcome email — a warm intro to the child's provider (bio,
        // photo, contacts + escalation, what-to-expect). Best-effort; normal
        // agency suppression applies (so a suppressed agency stays quiet until
        // it goes live). Sent to ALL guardians on the family, new or existing.
        try {
            $allGuardianEmails = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                ->where('g.family_id', $result['family_id'])->whereNotNull('u.email')
                ->get(['u.email', 'u.first_name'])
                ->map(fn ($r) => ['email' => $r->email, 'first_name' => $r->first_name])->all();
            $this->sendProviderWelcomeToFamily(
                (int) $data['centre_id'], $agencyId, $allGuardianEmails,
                DB::table('children')->whereIn('id', $result['child_ids'])->pluck('first_name')->all()
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Provider welcome email failed: ' . $e->getMessage());
        }

        return response()->json([
            'family' => DB::table('families')->where('id', $result['family_id'])->first(),
            'guardians' => count($result['guardian_ids']),
            'children' => count($result['child_ids']),
            'invited' => $invited,
            'message' => 'Family created',
        ], 201);
    }

    /**
     * v22p11 — agency_admin can edit any family in their agency.
     */
    public function updateFamily(Request $request, int $familyId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $centreIds = $this->getCentreIds($agencyId);

        $family = DB::table('families')
            ->whereIn('centre_id', $centreIds ?: [0])
            ->where('id', $familyId)
            ->whereNull('deleted_at')
            ->first();
        if (! $family) return response()->json(['message' => 'Not found'], 404);

        $data = $request->validate([
            'family_name' => ['sometimes', 'string', 'max:120'],
            'centre_id' => ['sometimes', 'integer'],
            'primary_email' => ['sometimes', 'nullable', 'email', 'max:180'],
            'primary_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:200'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'province' => ['sometimes', 'nullable', 'string', 'max:40'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:12'],
            'preferred_lang' => ['sometimes', 'nullable', 'string', 'max:10'],
            'billing_split' => ['sometimes', 'in:single,split_50_50,custom'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        // If centre is being moved, verify the new centre is in this agency too.
        if (isset($data['centre_id']) && ! in_array((int) $data['centre_id'], $centreIds, true)) {
            return response()->json([
                'message' => 'Cannot move family to a centre outside your agency.',
                'errors' => ['centre_id' => ['Centre not in your agency.']],
            ], 422);
        }

        $data['updated_at'] = now();
        DB::table('families')->where('id', $familyId)->update($data);

        $this->audit($request->user()->id, 'update_family', 'family', $familyId, array_keys($data));

        return response()->json([
            'family' => DB::table('families')->where('id', $familyId)->first(),
            'message' => 'Family updated',
        ]);
    }

    /**
     * v22p46 — DELETE /api/v1/admin/families/{family}
     * Soft-deletes the family row. Children + guardian links stay intact so
     * historical reports and audit logs keep working. Caller must own the
     * family's centre via the existing agency_admin / platform_admin gates.
     */
    public function destroyFamily(Request $request, int $familyId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $callerIsPlatformAdmin = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();

        $family = DB::table('families')->where('id', $familyId)->whereNull('deleted_at')->first();
        if (!$family) return response()->json(['message' => 'Not found'], 404);

        if (!$callerIsPlatformAdmin) {
            $centreIds = $this->getCentreIds($agencyId);
            if (!in_array($family->centre_id, $centreIds, true)) {
                return response()->json(['message' => 'Family not in your agency'], 403);
            }
        }

        DB::table('families')->where('id', $familyId)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($request->user()->id, 'family.deleted', 'family', $familyId, [
            'family_name' => $family->family_name,
        ]);

        return response()->json(['message' => 'Family deleted', 'id' => $familyId]);
    }

    /**
     * Temporarily SUSPEND a family: block its guardians from logging in by setting
     * their user.status to 'suspended' (the login flow already rejects that). The
     * family + enrollment stay intact. Reversible via reactivateFamily.
     */
    public function suspendFamily(Request $request, int $familyId): JsonResponse
    {
        $family = $this->familyForWrite($request, $familyId);
        if ($family instanceof JsonResponse) return $family;

        $userIds = DB::table('guardians')->where('family_id', $familyId)->whereNotNull('user_id')->pluck('user_id')->all();
        if ($userIds) {
            DB::table('users')->whereIn('id', $userIds)->update(['status' => 'suspended']);
        }
        $this->audit($request->user()->id, 'family.suspended', 'family', $familyId, [
            'family_name' => $family->family_name, 'accounts' => count($userIds),
        ]);
        return response()->json(['ok' => true, 'suspended_accounts' => count($userIds)]);
    }

    /** Undo a suspension: flip suspended guardians back to active. */
    public function reactivateFamily(Request $request, int $familyId): JsonResponse
    {
        $family = $this->familyForWrite($request, $familyId);
        if ($family instanceof JsonResponse) return $family;

        $userIds = DB::table('guardians')->where('family_id', $familyId)->whereNotNull('user_id')->pluck('user_id')->all();
        if ($userIds) {
            DB::table('users')->whereIn('id', $userIds)->where('status', 'suspended')->update(['status' => 'active']);
        }
        $this->audit($request->user()->id, 'family.reactivated', 'family', $familyId, [
            'family_name' => $family->family_name, 'accounts' => count($userIds),
        ]);
        return response()->json(['ok' => true, 'restored_accounts' => count($userIds)]);
    }

    /** Shared scope guard for family write actions (mirrors destroyFamily). */
    private function familyForWrite(Request $request, int $familyId)
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        $callerIsPlatformAdmin = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        $family = DB::table('families')->where('id', $familyId)->whereNull('deleted_at')->first();
        if (!$family) return response()->json(['message' => 'Not found'], 404);
        if (!$callerIsPlatformAdmin) {
            $centreIds = $this->getCentreIds($agencyId);
            if (!in_array($family->centre_id, $centreIds, true)) {
                return response()->json(['message' => 'Family not in your agency'], 403);
            }
        }
        return $family;
    }

    // ════════════════════════════════════════════════════════════════
    //   AGENCY ANALYTICS (richer than dashboard)
    // ════════════════════════════════════════════════════════════════

    public function analytics(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $centreIds = $this->getCentreIds($agencyId);

        // 30 days of new enrollments
        $thirtyDaysAgo = Carbon::now()->subDays(30);
        $newEnrollments = DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->whereIn('families.centre_id', $centreIds ?: [0])
            ->where('children.enrolled_at', '>=', $thirtyDaysAgo)
            ->whereNull('children.deleted_at')
            ->count();

        // 30 days of withdrawals
        $withdrawals = DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->whereIn('families.centre_id', $centreIds ?: [0])
            ->where('children.withdrawn_at', '>=', $thirtyDaysAgo)
            ->count();

        // Revenue this month
        $monthStart = Carbon::now()->startOfMonth();
        $revenueThisMonth = DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->whereIn('invoices.centre_id', $centreIds ?: [0])
            ->where('payments.status', 'succeeded')
            ->where('payments.paid_at', '>=', $monthStart)
            ->sum('payments.amount');

        // Outstanding receivables
        $outstanding = DB::table('invoices')
            ->whereIn('centre_id', $centreIds ?: [0])
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->sum('balance_due');

        // Total active users by role
        $byRole = DB::table('role_assignments')
            ->where('active', true)
            ->where(function ($q) use ($agencyId, $centreIds) {
                $q->where('agency_id', $agencyId);
                if (!empty($centreIds)) $q->orWhereIn('centre_id', $centreIds);
            })
            ->select('role', DB::raw('count(distinct user_id) as cnt'))
            ->groupBy('role')
            ->pluck('cnt', 'role')
            ->all();

        return response()->json([
            'last_30_days' => [
                'new_enrollments' => $newEnrollments,
                'withdrawals' => $withdrawals,
                'net' => $newEnrollments - $withdrawals,
            ],
            'this_month' => [
                'revenue' => (float) $revenueThisMonth,
                'outstanding' => (float) $outstanding,
            ],
            'users_by_role' => $byRole,
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //   v22p1.2 — user lifecycle: delete, reset password, resend welcome
    // ════════════════════════════════════════════════════════════════

    /**
     * Verify a target user is within the admin's agency (staff via role_assignments,
     * or guardian via families.centre_id).
     *
     * SECURITY (v22p96): membership is checked against the ACTIVE agency for
     * everyone, platform_admins included. The prior v22p30 short-circuit
     * (`if platform_admin return true`) let a super-admin read (userProfile) and
     * mutate (delete/reset-password/resend/reopen/avatar) any user in any tenant
     * regardless of which agency they were viewing — a cross-tenant leak. Because
     * $agencyId comes from getAgencyId() (which resolves the X-Active-Agency-Id a
     * platform_admin has switched into), a super-admin still manages any agency —
     * one at a time, by selecting it. To act on another agency's user, switch to
     * that agency first.
     */
    private function userBelongsToAgency(int $userId, int $agencyId): bool
    {
        $centreIds = $this->getCentreIds($agencyId);

        $hasStaffRole = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where(function ($q) use ($agencyId, $centreIds) {
                $q->where('agency_id', $agencyId);
                if (!empty($centreIds)) $q->orWhereIn('centre_id', $centreIds);
            })
            ->exists();
        if ($hasStaffRole) return true;

        if (empty($centreIds)) return false;
        return DB::table('guardians')
            ->join('families', 'families.id', '=', 'guardians.family_id')
            ->where('guardians.user_id', $userId)
            ->whereIn('families.centre_id', $centreIds)
            ->exists();
    }

    /**
     * DELETE /admin/users/{user}
     * Soft-delete the user, deactivate role assignments, revoke tokens.
     * Does not cascade to families or children — those keep their guardian link
     * intact for audit (but the user can no longer log in).
     */
    /**
     * Emails that are permanently protected: always super-admin, never deletable.
     * Guarded across every delete path so the platform always retains an owner.
     */
    private const PROTECTED_SUPER_ADMINS = ['mr.anthonyhosein@gmail.com'];

    private function isProtectedEmail(?string $email): bool
    {
        return $email !== null && in_array(strtolower(trim($email)), self::PROTECTED_SUPER_ADMINS, true);
    }

    public function destroyUser(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        if ($userId === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }
        if (!$this->userBelongsToAgency($userId, $agencyId)) {
            return response()->json(['message' => 'User not in your agency'], 403);
        }

        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) return response()->json(['message' => 'User not found'], 404);

        // Protected super-admin account — can never be deleted or deactivated.
        if ($this->isProtectedEmail($user->email ?? null)) {
            return response()->json(['message' => 'This is the protected super-admin account and cannot be deleted.'], 422);
        }

        DB::transaction(function () use ($userId) {
            DB::table('role_assignments')->where('user_id', $userId)->update([
                'active' => false,
            ]);
            // Revoke any sanctum tokens so the user is logged out instantly.
            DB::table('personal_access_tokens')->where('tokenable_id', $userId)->delete();
            DB::table('users')->where('id', $userId)->update([
                'status' => 'deactivated',
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
        });

        // Tell the person. Their access has just been withdrawn; finding out by
        // failing to sign in is both discourteous and, for a privacy request, no
        // record at all. States what was withdrawn, when (in the agency's own
        // timezone), what happens to their information, and who to ask about it.
        $emailSent = false;
        if (!empty($user->email)) {
            try {
                $agency = DB::table('agencies')->where('id', $agencyId)->first();
                $agencyName = $agency->name ?? 'KiddieTrac';
                $contact = $agency->contact_email ?? null;
                $phone = $agency->contact_phone ?? null;
                $tz = \App\Support\AgencyTime::tz($agencyId);
                $when = now()->setTimezone($tz);
                $first = trim((string) ($user->first_name ?? '')) ?: 'Hello';

                // Retention is a per-agency policy setting; quote theirs when it is
                // set rather than inventing a number in an email about their rights.
                $retention = null;
                try {
                    $settings = json_decode((string) ($agency->settings ?? ''), true);
                    $months = $settings['retention']['staff_records_months']
                        ?? ($settings['retention_months'] ?? null);
                    if ($months) $retention = (int) $months;
                } catch (\Throwable $e) {}

                // Who is this? A parent's question is about their CHILD's records; an
                // educator's is about employment. Answering with the other one is
                // the same as not answering.
                $roles = DB::table('role_assignments')->where('user_id', $userId)
                    ->pluck('role')->map(fn ($r) => (string) $r)->all();
                $isGuardian = in_array('guardian', $roles, true);
                $isStaff = (bool) array_intersect($roles,
                    ['educator', 'centre_director', 'agency_admin', 'home_visitor', 'auditor', 'sales_rep', 'platform_admin']);

                $records = [];
                if ($isGuardian) {
                    $records[] = '<strong>Your child\'s records</strong> — enrolment, attendance, daily care notes, '
                        . 'medication and allergy information, and any incident reports — are part of the agency\'s '
                        . 'licensed child care records. The agency is required to keep these, and cannot delete them '
                        . 'on request while that requirement applies.';
                    $records[] = '<strong>Your own contact and billing records</strong> — invoices, payments and receipts — '
                        . 'are kept for as long as tax and financial record-keeping rules require.';
                    $records[] = '<strong>Photos and videos of your child</strong> shared with you through the app are '
                        . 'removed from your access immediately. Copies you have already downloaded remain yours.';
                }
                if ($isStaff) {
                    $records[] = '<strong>Employment and payroll records</strong> — hours worked, pay, and related '
                        . 'documents — are kept for the period employment and tax rules require.';
                    $records[] = '<strong>Qualification and screening records</strong> — certifications, first aid and CPR, '
                        . 'and police record checks — form part of the agency\'s licensing records and are kept for as '
                        . 'long as those obligations apply.';
                    $records[] = '<strong>Work you recorded</strong> — attendance you took, care you logged, reports you '
                        . 'filed — stays on the children\'s records, because it is part of those children\'s history and '
                        . 'not personal information about you that can be withdrawn.';
                }
                if (!$records) {
                    $records[] = 'Records the agency is required by law to keep are retained for as long as that '
                        . 'requirement applies; everything else is removed.';
                }
                $recordsHtml = '<ul style="margin:8px 0 0;padding-left:18px;font-size:13.5px;color:#334155;">'
                    . implode('', array_map(fn ($r) => '<li style="margin-bottom:7px;line-height:1.55;">' . $r . '</li>', $records))
                    . '</ul>';

                $body = '<div style="margin:0;padding:0;background:#F1F5F9;">'
                    . '<div style="max-width:620px;margin:0 auto;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">'
                    . '<div style="background:linear-gradient(168deg,#0a1f44 0%,#0c2857 46%,#0a1f44 100%);padding:22px 24px;border-radius:14px 14px 0 0;text-align:center;">'
                    .   '<img src="https://app.kiddietrac.com/login-wordmark.png" alt="KiddieTrac" width="170" style="max-width:170px;height:auto;display:block;margin:0 auto 6px;">'
                    // The tagline as TEXT, not the login-tagline.png asset: that image is
                    // dark-on-light and would vanish against this navy header. Text also
                    // survives a client that blocks remote images, which is most of them.
                    .   '<div style="color:rgba(255,255,255,.82);font-size:11.5px;font-weight:600;letter-spacing:.4px;margin-bottom:12px;">Smart Childcare Management Platform</div>'
                    .   '<div style="color:#fff;font-size:17px;font-weight:800;">Your access has been removed</div>'
                    . '</div>'
                    . '<div style="background:#fff;padding:22px 24px;border:1px solid #E2E8F0;border-top:0;font-size:14.5px;color:#1E293B;line-height:1.6;">'
                    .   '<p style="margin:0 0 12px;">' . e($first) . ',</p>'
                    .   '<p style="margin:0 0 12px;">Your KiddieTrac account with <strong>' . e($agencyName) . '</strong> was deactivated on '
                    .     e($when->format('D, M j, Y')) . ' at ' . e($when->format('g:i A')) . ' (' . e($when->format('T')) . ').</p>'
                    .   '<p style="margin:0 0 12px;">You can no longer sign in, and you have been signed out of any device where you were still signed in. '
                    .     'No further emails or notifications will be sent to you from this agency.</p>'
                    .   '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:14px 16px;margin:16px 0;">'
                    .     '<div style="font-size:11px;font-weight:800;letter-spacing:1px;color:#64748B;text-transform:uppercase;margin-bottom:6px;">What happens to your information</div>'
                    .     '<p style="margin:0;font-size:13.5px;color:#334155;">Some records must be kept by law even after access ends. Those are retained for as long as the law requires'
                    .       ($retention ? ', in line with this agency\'s retention policy of ' . (int) $retention . ' months' : '')
                    .       ', held securely, used for no other purpose, and then destroyed. Anything not covered by such an obligation is removed.</p>'
                    .     $recordsHtml
                    .     '<p style="margin:10px 0 0;font-size:13.5px;color:#334155;">You may ask for a copy of the personal information held about you, ask for a correction, or ask what is kept and for how long. Write to the contact below and the agency will respond.</p>'
                    .   '</div>'
                    .   '<p style="margin:0 0 6px;">If this was not expected, please contact '
                    .     ($contact ? '<a href="mailto:' . e($contact) . '" style="color:#1F6FB2;">' . e($contact) . '</a>' : 'your agency administrator')
                    .     ' — it can be reversed.</p>'
                    . '</div>'
                    . '<div style="padding:16px 24px 26px;color:#94A3B8;font-size:11.5px;line-height:1.65;text-align:center;">'
                    .   '<div style="font-weight:700;color:#64748B;">' . e($agencyName) . '</div>'
                    .   ($contact ? '<div><a href="mailto:' . e($contact) . '" style="color:#94A3B8;">' . e($contact) . '</a>'
                        . ($phone ? ' &middot; ' . e($phone) : '') . '</div>' : ($phone ? '<div>' . e($phone) . '</div>' : ''))
                    .   '<div style="margin-top:8px;">This is an automated message about your account. It was sent because your '
                    .   'access was removed, and it is not marketing — there is nothing to unsubscribe from.</div>'
                    .   '<div style="margin-top:6px;">For questions about your information, or to request a copy, reply to this '
                    .   'email or contact the agency directly.</div>'
                    .   '<div style="margin-top:10px;color:#CBD5E1;">Sent ' . e($when->format('D, M j, Y g:i A T')) . ' &middot; powered by KiddieTrac</div>'
                    . '</div></div></div>';

                // BCC the people accountable for the decision: the agency's own contact
                // address (the owner) and its admins and directors. BCC rather than CC
                // deliberately — the person being deactivated should not be handed a
                // list of who was told, and the recipients do not need to see each
                // other. It doubles as the agency's own copy of the notice, which is
                // the record that matters if the removal is ever questioned.
                $oversight = DB::table('users as u')
                    ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
                    ->where('ra.agency_id', $agencyId)
                    ->where('ra.active', true)
                    ->whereIn('ra.role', ['agency_admin', 'centre_director'])
                    ->whereNull('u.deleted_at')
                    ->whereNotNull('u.email')
                    ->distinct()
                    ->pluck('u.email')
                    ->all();
                if (!empty($agency->contact_email)) $oversight[] = $agency->contact_email;

                // Never copy the person being removed on the notice about their own
                // removal, and keep the list sane if an agency has many directors.
                $bcc = array_values(array_unique(array_filter($oversight, function ($e) use ($user) {
                    return $e && strcasecmp(trim($e), trim((string) $user->email)) !== 0;
                })));
                $bcc = array_slice($bcc, 0, 15);

                \App\Services\AgencyMailer::forAgency($agencyId)->mailer()->html($body, function ($m) use ($user, $agencyName, $bcc) {
                    $m->to($user->email)->subject('Your ' . $agencyName . ' account has been deactivated');
                    if ($bcc) $m->bcc($bcc);
                });
                $emailSent = true;
            } catch (\Throwable $e) {
                // Never block the deactivation itself — but do not lose the failure,
                // because "was the person told?" is exactly what gets asked later.
                \Illuminate\Support\Facades\Log::error('Deactivation notice could not be sent', [
                    'user_id' => $userId, 'error' => $e->getMessage(),
                ]);
            }
        }

        $this->audit($request->user()->id, 'user.deleted', 'user', $userId, [
            'email' => $user->email,
            'notice_emailed' => $emailSent,
        ]);

        return response()->json(['message' => 'User deleted', 'id' => $userId, 'notice_emailed' => $emailSent]);
    }

    /**
     * POST /admin/users/{user}/reset-password
     * Generate a fresh temporary password, set it on the user, and email them
     * a notice with the temp password and a "use Forgot password" link.
     * Body: { send_email?: bool, set_status_invited?: bool }
     */
    public function resetUserPassword(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (!$this->userBelongsToAgency($userId, $agencyId)) {
            return response()->json(['message' => 'User not in your agency'], 403);
        }

        $data = $request->validate([
            'send_email'           => ['nullable', 'boolean'],
            'set_status_invited'   => ['nullable', 'boolean'],
        ]);
        $sendEmail = $data['send_email'] ?? true;

        $user = DB::table('users')->where('id', $userId)->whereNull('deleted_at')->first();
        if (!$user) return response()->json(['message' => 'User not found'], 404);

        // 12-char, mixed-case + digits. The user is encouraged to change it via Forgot password.
        $tempPassword = Str::random(12);

        DB::transaction(function () use ($userId, $tempPassword, $data) {
            $upd = ['password' => Hash::make($tempPassword), 'updated_at' => now()];
            if (!empty($data['set_status_invited'])) {
                $upd['status'] = 'invited';
            }
            DB::table('users')->where('id', $userId)->update($upd);
            // Revoke existing sessions so the old password really stops working.
            DB::table('personal_access_tokens')->where('tokenable_id', $userId)->delete();
        });

        $emailed = false;
        if ($sendEmail) {
            $emailed = $this->sendAccountEmail(
                $user->email,
                trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'Your Kiddietrac password has been reset',
                "Your administrator has reset your Kiddietrac password.\n\n" .
                "Temporary password: {$tempPassword}\n\n" .
                "Sign in at https://app.kiddietrac.com and use the 'Forgot password' link to choose a new one."
            );
        }

        $this->audit($request->user()->id, 'user.password_reset', 'user', $userId, [
            'email_sent' => $emailed,
        ]);

        return response()->json([
            'message'       => $emailed ? 'Password reset; email sent.' : 'Password reset.',
            'temp_password' => $tempPassword,
            'email_sent'    => $emailed,
        ]);
    }

    /**
     * POST /admin/users/{user}/resend-welcome
     * Re-send the welcome invite email. Always generates a fresh temp password
     * since the previous one was effectively lost.
     */
    public function resendWelcome(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (!$this->userBelongsToAgency($userId, $agencyId)) {
            return response()->json(['message' => 'User not in your agency'], 403);
        }

        $user = DB::table('users')->where('id', $userId)->whereNull('deleted_at')->first();
        if (!$user) return response()->json(['message' => 'User not found'], 404);

        $tempPassword = Str::random(12);

        DB::transaction(function () use ($userId, $tempPassword) {
            DB::table('users')->where('id', $userId)->update([
                'password'   => Hash::make($tempPassword),
                'status'     => 'invited',
                'updated_at' => now(),
            ]);
            DB::table('personal_access_tokens')->where('tokenable_id', $userId)->delete();
        });

        $emailed = $this->sendAccountEmail(
            $user->email,
            trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
            'Welcome to Kiddietrac',
            "Welcome to Kiddietrac!\n\n" .
            "Your account is ready at https://app.kiddietrac.com\n\n" .
            "Temporary password: {$tempPassword}\n\n" .
            "We recommend signing in then using 'Forgot password' to set your own."
        );

        $this->audit($request->user()->id, 'user.welcome_resent', 'user', $userId, [
            'email_sent' => $emailed,
        ]);

        return response()->json([
            'message'       => $emailed ? 'Welcome email sent.' : 'Welcome email failed to send (saved temp password — share manually).',
            'temp_password' => $tempPassword,
            'email_sent'    => $emailed,
        ]);
    }

    /**
     * POST /admin/users/{user}/reopen-onboarding
     * Clear onboarded_at so the user is presented with the wizard again on
     * next dashboard load. Used when the admin wants the user to top up
     * their profile (eg, an educator's expired First Aid date).
     * v22p3.5.
     */
    /**
     * POST /admin/users/{user}/reactivate
     * Restore a soft-deleted (deactivated) user: clear deleted_at, set status active, and
     * re-activate their role assignments for THIS agency. The reverse of destroyUser.
     */
    public function reactivateUser(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (!$this->userBelongsToAgency($userId, $agencyId)) {
            return response()->json(['message' => 'User not in your agency'], 403);
        }

        $user = DB::table('users')->where('id', $userId)->whereNotNull('deleted_at')->first();
        if (!$user) return response()->json(['message' => 'Deactivated user not found'], 404);

        $centreIds = $this->getCentreIds($agencyId);
        DB::transaction(function () use ($userId, $agencyId, $centreIds) {
            // Re-activate the assignments scoped to THIS agency only — never touch a
            // separate agency's assignment for the same person.
            DB::table('role_assignments')->where('user_id', $userId)
                ->where(function ($q) use ($agencyId, $centreIds) {
                    $q->where('agency_id', $agencyId);
                    if (!empty($centreIds)) $q->orWhereIn('centre_id', $centreIds);
                })
                ->update(['active' => true]);
            DB::table('users')->where('id', $userId)->update([
                'status'     => 'active',
                'deleted_at' => null,
                'updated_at' => now(),
            ]);
        });

        $this->audit($request->user()->id, 'user.reactivated', 'user', $userId, ['email' => $user->email]);

        return response()->json(['message' => 'User reactivated', 'id' => $userId]);
    }

        public function reopenOnboarding(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (!$this->userBelongsToAgency($userId, $agencyId)) {
            return response()->json(['message' => 'User not in your agency'], 403);
        }
        DB::table('users')->where('id', $userId)->update([
            'onboarded_at' => null,
            'updated_at'   => now(),
        ]);
        $this->audit($request->user()->id, 'user.onboarding_reopened', 'user', $userId);
        return response()->json(['message' => 'Onboarding reopened — the user will see the wizard on their next sign-in.']);
    }

    /**
     * POST /admin/centres/{centre}/logo
     * Upload a centre logo (jpg/png/webp/svg, max 2 MB). v22p3.4.
     */
    public function uploadCentreLogo(Request $request, int $centreId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        $centre = DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$centre) return response()->json(['message' => 'Centre not found'], 404);

        $request->validate([
            'logo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
        ]);

        $file = $request->file('logo');
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $name = (string) Str::uuid() . '.' . $ext;
        $file->storeAs('centre-logos', $name, 'public');

        $publicPath = '/storage/centre-logos/' . $name;
        DB::table('centres')->where('id', $centreId)->update([
            'logo_url'   => $publicPath,
            'updated_at' => now(),
        ]);

        $this->audit($request->user()->id, 'centre.logo_updated', 'centre', $centreId, ['path' => $publicPath]);

        return response()->json([
            'logo_url' => $publicPath,
            'message'  => 'Centre logo updated',
        ]);
    }

    /**
     * POST /admin/users/{user}/avatar
     * Upload an avatar image (jpg/png/webp, max 2 MB) and persist photo_url.
     * v22p3.2.
     */
    public function uploadAvatar(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (!$this->userBelongsToAgency($userId, $agencyId)) {
            return response()->json(['message' => 'User not in your agency'], 403);
        }

        $request->validate([
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $file = $request->file('avatar');
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $name = (string) Str::uuid() . '.' . $ext;
        $file->storeAs('avatars', $name, 'public');

        $publicPath = '/storage/avatars/' . $name;
        DB::table('users')->where('id', $userId)->update([
            'photo_url'  => $publicPath,
            'updated_at' => now(),
        ]);

        $this->audit($request->user()->id, 'user.avatar_updated', 'user', $userId, ['path' => $publicPath]);

        return response()->json([
            'photo_url' => $publicPath,
            'message'   => 'Avatar updated',
        ]);
    }

    /**
     * Upload a child photo DURING family setup — before the child row exists —
     * and return its public URL. The add-family wizard attaches the URL to the
     * child on createFamily. Child photos are mandatory (educator safety +
     * identification); same image validation as avatars.
     */
    public function uploadChildPhoto(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) {
            return response()->json(['message' => 'No agency access'], 403);
        }
        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
        $file = $request->file('photo');
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $name = (string) Str::uuid() . '.' . $ext;
        $file->storeAs('child-photos', $name, 'public');

        return response()->json(['photo_url' => '/storage/child-photos/' . $name, 'message' => 'Photo uploaded']);
    }

    /**
     * Send the warm provider welcome email (emails/provider-welcome.blade) to a
     * family's guardians. Carries X-KT-Invite so it reaches not-yet-onboarded
     * parents; normal agency suppression still applies. Best-effort per recipient.
     */
    private function sendProviderWelcomeToFamily(int $centreId, ?int $agencyId, array $guardians, array $childFirstNames): void
    {
        if (empty($guardians)) {
            return;
        }
        $centre = DB::table('centres')->where('id', $centreId)->first();
        if (! $centre) {
            return;
        }
        $agency = $agencyId ? DB::table('agencies')->where('id', $agencyId)->first() : null;
        $s = ($agency && $agency->settings) ? (json_decode($agency->settings, true) ?: []) : [];
        $brand = $s['branding'] ?? [];
        $abs = fn ($u) => $u ? (preg_match('#^https?://#', (string) $u) ? $u : ('https://api.kiddietrac.com' . $u)) : null;
        $childName = $childFirstNames[0] ?? '';
        $providerAddress = trim(($centre->address_line1 ? $centre->address_line1 . "\n" : '')
            . trim(($centre->city ?? '') . ' ' . ($centre->province ?? '') . ' ' . ($centre->postal_code ?? ''))) ?: null;

        // CC the agency admin, this centre's director(s) and educator(s) so the
        // whole care team sees the welcome that went to the family.
        $ccEmails = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.active', 1)->whereNotNull('u.email')
            ->where(function ($q) use ($agencyId, $centreId) {
                $q->where(function ($x) use ($agencyId) { $x->where('ra.role', 'agency_admin')->where('ra.agency_id', $agencyId); })
                  ->orWhere(function ($x) use ($centreId) { $x->whereIn('ra.role', ['centre_director', 'educator'])->where('ra.centre_id', $centreId); });
            })->distinct()->pluck('u.email')->filter()->values()->all();

        foreach ($guardians as $g) {
            if (empty($g['email'])) {
                continue;
            }
            $view = [
                'agencyName'      => $s['name'] ?? ($agency->name ?? 'Your childcare agency'),
                'agencyLogoUrl'   => $abs($brand['logo_url'] ?? null),
                'agencyPhone'     => $s['phone'] ?? null,
                'agencyEmail'     => $s['data_contact_email'] ?? ($agency->email ?? null),
                'providerName'    => $centre->name,
                'providerPhotoUrl'=> $abs($centre->logo_url ?? null),
                'providerBio'     => $centre->provider_bio ?: 'Your provider will share a little about themselves here soon.',
                'providerPhone'   => $centre->phone ?? null,
                'providerEmail'   => $centre->email ?? null,
                'parentFirstName' => $g['first_name'] ?: 'there',
                'childName'       => $childName,
                'portalUrl'       => 'https://app.kiddietrac.com',
                'primaryColor'    => $brand['primary_color'] ?? '#081C41',
                'accentColor'     => $brand['accent_color'] ?? '#2EA9AC',
                'privacyUrl'      => $s['brand_privacy_url'] ?? null,
                'termsUrl'        => $s['brand_terms_url'] ?? null,
                'agencyAddress'   => $s['brand_address'] ?? null,
                'agencyOwnerName' => $s['owner']['name'] ?? null,
                'providerAddress' => $providerAddress,
                'websiteUrl'      => $s['brand_website_url'] ?? ($s['website'] ?? null),
            ];
            // Inject the agency-editable narrative blocks (subject + intro/care/
            // expect/closing), merge-tags filled from $view.
            $view = \App\Support\ProviderWelcomeTemplate::viewData($view, $s);
            try {
                $html = view('emails.provider-welcome', $view)->render();
                Mail::html($html, function ($m) use ($g, $centre, $ccEmails) {
                    $m->to($g['email'])->subject('Welcome to ' . $centre->name . " \u{2014} meet your child's provider");
                    if (! empty($ccEmails)) {
                        $m->cc($ccEmails);
                    }
                    $m->getHeaders()->addTextHeader('X-KT-Invite', '1');
                });
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Provider welcome send failed for ' . $g['email'] . ': ' . $e->getMessage());
            }
        }
    }

    /** POST /admin/families/{family}/provider-welcome — manually (re)send the provider welcome. */
    public function resendProviderWelcome(Request $request, int $familyId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $family = DB::table('families')->where('id', $familyId)->whereNull('deleted_at')->first();
        if (! $family) {
            return response()->json(['message' => 'Family not found'], 404);
        }
        $centre = DB::table('centres')->where('id', $family->centre_id)->first();
        if (! $centre || ($agencyId && (int) $centre->agency_id !== (int) $agencyId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $guardians = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $familyId)->whereNotNull('u.email')
            ->get(['u.email', 'u.first_name'])
            ->map(fn ($r) => ['email' => $r->email, 'first_name' => $r->first_name])->all();
        if (empty($guardians)) {
            return response()->json(['message' => 'No guardian email addresses on this family.'], 422);
        }
        $childNames = DB::table('children')->where('family_id', $familyId)->whereNull('deleted_at')->pluck('first_name')->all();
        $this->sendProviderWelcomeToFamily((int) $family->centre_id, (int) $centre->agency_id, $guardians, $childNames);
        $this->audit($request->user()->id, 'family.provider_welcome_resent', 'family', $familyId, ['guardians' => count($guardians)]);

        return response()->json(['message' => 'Provider welcome email sent.', 'recipients' => count($guardians)]);
    }

    /** GET /admin/email-template/provider-welcome — the agency's editable blocks. */
    public function getProviderWelcomeTemplate(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) {
            return response()->json(['message' => 'No agency access'], 403);
        }
        $ag = DB::table('agencies')->where('id', $agencyId)->first();
        $s = ($ag && $ag->settings) ? (json_decode($ag->settings, true) ?: []) : [];
        return response()->json([
            'key'        => 'provider-welcome',
            'label'      => 'Provider welcome',
            'fields'     => [
                ['k' => 'subject',      'label' => 'Subject',       'rich' => false, 'minH' => 0],
                ['k' => 'intro',        'label' => 'Introduction',  'rich' => true,  'minH' => 120],
                ['k' => 'care_message', 'label' => 'Our care',      'rich' => true,  'minH' => 160],
                ['k' => 'expect_intro', 'label' => 'What to expect intro', 'rich' => true, 'minH' => 80],
                ['k' => 'closing',      'label' => 'Closing',       'rich' => true,  'minH' => 70],
            ],
            'blocks'     => \App\Support\ProviderWelcomeTemplate::blocks($s),
            'defaults'   => \App\Support\ProviderWelcomeTemplate::defaults(),
            'merge_tags' => \App\Support\ProviderWelcomeTemplate::mergeTags(),
        ]);
    }

    /** PUT /admin/email-template/provider-welcome — save the agency's custom blocks. */
    public function saveProviderWelcomeTemplate(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) {
            return response()->json(['message' => 'No agency access'], 403);
        }
        $data = $request->validate([
            'subject'      => ['nullable', 'string', 'max:200'],
            'intro'        => ['nullable', 'string', 'max:4000'],
            'care_message' => ['nullable', 'string', 'max:6000'],
            'expect_intro' => ['nullable', 'string', 'max:2000'],
            'closing'      => ['nullable', 'string', 'max:2000'],
        ]);
        $ag = DB::table('agencies')->where('id', $agencyId)->first();
        $s = ($ag && $ag->settings) ? (json_decode($ag->settings, true) ?: []) : [];
        $clean = [];
        foreach (array_filter($data, fn ($v) => $v !== null && $v !== '') as $k => $v) {
            // Subject stays plain text; the body blocks may be rich HTML from the
            // editor — sanitise them (strip scripts / event handlers / js: urls).
            $clean[$k] = $k === 'subject' ? trim(strip_tags((string) $v)) : $this->sanitizeEmailHtml((string) $v);
        }
        $s['provider_welcome'] = $clean;
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        $this->audit($request->user()->id, 'agency.provider_welcome_template_updated', 'agency', $agencyId, []);

        return response()->json(['message' => 'Template saved.']);
    }

    /** Strip dangerous markup from admin-authored email HTML. */
    private function sanitizeEmailHtml(string $html): string
    {
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|form)[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? $html;
        $html = preg_replace('#<\s*(script|style|iframe|object|embed|form)[^>]*/?>#is', '', $html) ?? $html;
        $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;
        $html = preg_replace('#(href|src)\s*=\s*(["\']?)\s*javascript:[^"\'>]*\2#i', '$1="#"', $html) ?? $html;
        return $html;
    }

    /** POST /admin/email-template/provider-welcome/preview — render the draft to HTML. */
    public function previewProviderWelcomeTemplate(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) {
            return response()->json(['message' => 'No agency access'], 403);
        }
        $ag = DB::table('agencies')->where('id', $agencyId)->first();
        $s = ($ag && $ag->settings) ? (json_decode($ag->settings, true) ?: []) : [];
        $brand = $s['branding'] ?? [];
        $abs = fn ($u) => $u ? (preg_match('#^https?://#', (string) $u) ? $u : ('https://api.kiddietrac.com' . $u)) : null;
        $centre = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        $view = [
            'agencyName'      => $s['name'] ?? ($ag->name ?? 'Your agency'),
            'agencyLogoUrl'   => $abs($brand['logo_url'] ?? null),
            'agencyPhone'     => $s['phone'] ?? null,
            'agencyEmail'     => $s['data_contact_email'] ?? ($ag->email ?? null),
            'providerName'    => $centre->name ?? 'Your provider',
            'providerPhotoUrl'=> $abs($centre->logo_url ?? null),
            'providerBio'     => ($centre->provider_bio ?? null) ?: "Hi! I'm your provider — I can't wait to care for your little one.",
            'providerPhone'   => $centre->phone ?? null,
            'providerEmail'   => $centre->email ?? null,
            'providerAddress' => trim(($centre->address_line1 ?? '') . ' ' . ($centre->city ?? '')) ?: null,
            'parentFirstName' => $request->user()->first_name ?: 'Alex',
            'childName'       => 'Ava',
            'portalUrl'       => 'https://app.kiddietrac.com',
            'primaryColor'    => $brand['primary_color'] ?? '#081C41',
            'accentColor'     => $brand['accent_color'] ?? '#2EA9AC',
            'privacyUrl'      => $s['brand_privacy_url'] ?? null,
            'termsUrl'        => $s['brand_terms_url'] ?? null,
            'agencyAddress'   => $s['brand_address'] ?? null,
            'agencyOwnerName' => $s['owner']['name'] ?? null,
            'websiteUrl'      => $s['brand_website_url'] ?? ($s['website'] ?? null),
        ];
        if ($request->filled('blocks') && is_array($request->input('blocks'))) {
            $blocks = [];
            foreach ($request->input('blocks') as $k => $v) {
                $blocks[$k] = $k === 'subject' ? strip_tags((string) $v) : $this->sanitizeEmailHtml((string) $v);
            }
            $s['provider_welcome'] = $blocks;
        }
        $view = \App\Support\ProviderWelcomeTemplate::viewData($view, $s);
        return response()->json(['html' => view('emails.provider-welcome', $view)->render()]);
    }

    /** POST /admin/email-template/provider-welcome/test — send a live preview to the caller. */
    public function testProviderWelcomeTemplate(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) {
            return response()->json(['message' => 'No agency access'], 403);
        }
        $to = $request->input('to') ?: $request->user()->email;
        if (! $to) {
            return response()->json(['message' => 'No email address to send to.'], 422);
        }
        $ag = DB::table('agencies')->where('id', $agencyId)->first();
        $s = ($ag && $ag->settings) ? (json_decode($ag->settings, true) ?: []) : [];
        $brand = $s['branding'] ?? [];
        $abs = fn ($u) => $u ? (preg_match('#^https?://#', (string) $u) ? $u : ('https://api.kiddietrac.com' . $u)) : null;
        $centre = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        $view = [
            'agencyName'      => $s['name'] ?? ($ag->name ?? 'Your agency'),
            'agencyLogoUrl'   => $abs($brand['logo_url'] ?? null),
            'agencyPhone'     => $s['phone'] ?? null,
            'agencyEmail'     => $s['data_contact_email'] ?? ($ag->email ?? null),
            'providerName'    => $centre->name ?? 'Your provider',
            'providerPhotoUrl'=> $abs($centre->logo_url ?? null),
            'providerBio'     => ($centre->provider_bio ?? null) ?: "Hi! I'm your provider — I can't wait to care for your little one.",
            'providerPhone'   => $centre->phone ?? null,
            'providerEmail'   => $centre->email ?? null,
            'providerAddress' => null,
            'parentFirstName' => $request->user()->first_name ?: 'there',
            'childName'       => 'Ava',
            'portalUrl'       => 'https://app.kiddietrac.com',
            'primaryColor'    => $brand['primary_color'] ?? '#081C41',
            'accentColor'     => $brand['accent_color'] ?? '#2EA9AC',
            'privacyUrl'      => $s['brand_privacy_url'] ?? null,
            'termsUrl'        => $s['brand_terms_url'] ?? null,
            'agencyAddress'   => $s['brand_address'] ?? null,
            'agencyOwnerName' => $s['owner']['name'] ?? null,
            'websiteUrl'      => $s['brand_website_url'] ?? ($s['website'] ?? null),
        ];
        // Use the DRAFT blocks from the request (live, unsaved) if provided.
        if ($request->filled('blocks') && is_array($request->input('blocks'))) {
            $s['provider_welcome'] = array_map(fn ($v) => (string) $v, $request->input('blocks'));
        }
        $view = \App\Support\ProviderWelcomeTemplate::viewData($view, $s);
        try {
            $html = view('emails.provider-welcome', $view)->render();
            \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($to, $view) {
                $m->to($to)->subject('[Test] ' . $view['subject']);
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            });
            return response()->json(['message' => 'Test sent to ' . $to]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Send failed: ' . $e->getMessage()], 500);
        }
    }

    // ── Generic multi-template editor (#77) ─────────────────────────────
    // Covers the templates in App\Support\EmailTemplates (parent-daily-summary,
    // onboarding-welcome, invite, announcement). provider-welcome keeps its own
    // endpoints above; the picker lists it too but routes it there.

    /** GET /admin/email-templates — the picker list (provider-welcome + registry). */
    public function emailTemplateList(Request $request): JsonResponse
    {
        if (! $this->getAgencyId($request)) return response()->json(['message' => 'No agency access'], 403);
        return response()->json(['templates' => \App\Support\EmailTemplates::list()]);
    }

    /** GET /admin/email-template/{key} — a registry template's editable blocks + fields. */
    public function getEmailTemplate(Request $request, string $key): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (! \App\Support\EmailTemplates::exists($key)) return response()->json(['message' => 'Unknown template'], 404);
        $def = \App\Support\EmailTemplates::registry()[$key];
        return response()->json([
            'key'        => $key,
            'label'      => $def['label'],
            'fields'     => $def['fields'],
            'blocks'     => \App\Support\EmailTemplates::blocks((int) $agencyId, $key),
            'defaults'   => $def['defaults'],
            'merge_tags' => $def['merge_tags'],
        ]);
    }

    /** PUT /admin/email-template/{key} — save a registry template's custom blocks. */
    public function saveEmailTemplate(Request $request, string $key): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (! \App\Support\EmailTemplates::exists($key)) return response()->json(['message' => 'Unknown template'], 404);
        $def = \App\Support\EmailTemplates::registry()[$key];
        $in = (array) $request->input('blocks', []);
        $clean = [];
        foreach ($def['fields'] as $f) {
            $v = $in[$f['k']] ?? null;
            if ($v === null || trim((string) $v) === '') continue;
            $v = mb_substr((string) $v, 0, 8000);
            // Rich blocks may carry editor HTML → sanitise; plain fields → strip tags.
            $clean[$f['k']] = ! empty($f['rich']) ? $this->sanitizeEmailHtml($v) : trim(strip_tags($v));
        }
        $ag = DB::table('agencies')->where('id', $agencyId)->first();
        $s = ($ag && $ag->settings) ? (json_decode($ag->settings, true) ?: []) : [];
        if (! isset($s['email_templates']) || ! is_array($s['email_templates'])) $s['email_templates'] = [];
        $s['email_templates'][$key] = $clean;
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        $this->audit($request->user()->id, 'agency.email_template_updated', 'agency', (int) $agencyId, ['template' => $key]);
        return response()->json(['message' => 'Template saved.']);
    }

    /** POST /admin/email-template/{key}/preview — render draft blocks to HTML. */
    public function previewEmailTemplate(Request $request, string $key): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (! \App\Support\EmailTemplates::exists($key)) return response()->json(['message' => 'Unknown template'], 404);
        $blocks = $this->draftOrStoredBlocks($request, (int) $agencyId, $key);
        $sample = \App\Support\EmailTemplates::sample((int) $agencyId, $request->user()->first_name);
        return response()->json(['html' => \App\Support\EmailTemplates::render((int) $agencyId, $key, $blocks, $sample)]);
    }

    /** POST /admin/email-template/{key}/test — send the rendered draft to the admin. */
    public function testEmailTemplate(Request $request, string $key): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (! \App\Support\EmailTemplates::exists($key)) return response()->json(['message' => 'Unknown template'], 404);
        $to = $request->input('to') ?: $request->user()->email;
        if (! $to) return response()->json(['message' => 'No email address to send to.'], 422);
        $blocks = $this->draftOrStoredBlocks($request, (int) $agencyId, $key);
        $sample = \App\Support\EmailTemplates::sample((int) $agencyId, $request->user()->first_name);
        $html = \App\Support\EmailTemplates::render((int) $agencyId, $key, $blocks, $sample);
        $label = \App\Support\EmailTemplates::registry()[$key]['label'];
        try {
            \App\Support\PlatformSettings::applyMail();
            \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($to, $label) {
                $m->to($to)->subject('[Test] ' . $label);
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            });
            return response()->json(['message' => 'Test sent to ' . $to]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Send failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Notify an agency's admins + directors that a user was added / invited, so it
     * shows in the portal (top-bar bell + notifications inbox). Skips the actor.
     * Directors are resolved via their centre → agency; admins via agency_id direct.
     */
    private function notifyStaffOfNewUser(int $agencyId, string $name, string $role, bool $inviteSent, int $byUserId): void
    {
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->pluck('id')->all();
        $recipients = DB::table('role_assignments')
            ->where('active', true)
            ->whereIn('role', ['agency_admin', 'centre_director'])
            ->where(function ($q) use ($agencyId, $centreIds) {
                $q->where('agency_id', $agencyId)->orWhereIn('centre_id', $centreIds ?: [0]);
            })
            ->pluck('user_id')->unique()->filter()->all();

        $roleLabel = [
            'agency_admin' => 'agency admin', 'centre_director' => 'director', 'educator' => 'educator',
            'home_visitor' => 'home visitor', 'guardian' => 'parent', 'sales_rep' => 'sales rep',
            'auditor' => 'auditor', 'platform_admin' => 'platform admin',
        ][$role] ?? str_replace('_', ' ', $role);

        $who = $name !== '' ? $name : 'A new user';
        $an = preg_match('/^[aeiou]/i', $roleLabel) ? 'an' : 'a';
        $title = $inviteSent ? '📨 Invitation sent' : '👤 New user added';
        $body = $inviteSent
            ? "{$who} was invited as {$an} {$roleLabel} — a set-password email has been sent."
            : "{$who} was added as {$an} {$roleLabel}.";
        $now = now();

        foreach ($recipients as $uid) {
            if ((int) $uid === $byUserId) continue;   // don't notify the person who did it
            DB::table('notifications')->insert([
                'user_id'    => (int) $uid,
                'type'       => 'user_invited',
                'title'      => $title,
                'body'       => mb_substr($body, 0, 500),
                'data'       => json_encode(['role' => $role, 'invite_sent' => $inviteSent]),
                'created_at' => $now,
            ]);
        }
    }

    /**
     * GET /admin/duplicate-users — agency-scoped report of likely duplicate people:
     * accounts sharing one email, or the same full name across different emails.
     * Read-only, so admins can spot messes like one person holding several logins.
     */
    public function duplicateUsers(Request $request): JsonResponse
    {
        $isPlatform = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        $allMode = $isPlatform && strtolower(trim((string) $request->header('X-Active-Agency-Id'))) === 'all';
        $agencyId = $allMode ? null : $this->getAgencyId($request);
        if (! $allMode && ! $agencyId) return response()->json(['message' => 'No agency access'], 403);
        $centreIds = $allMode ? [] : $this->getCentreIds($agencyId);

        $userIds = DB::table('role_assignments')->where('active', true)
            ->when(! $allMode, function ($q) use ($agencyId, $centreIds) {
                $q->where(function ($qq) use ($agencyId, $centreIds) {
                    $qq->where('agency_id', $agencyId);
                    if (! empty($centreIds)) $qq->orWhereIn('centre_id', $centreIds);
                });
            })->pluck('user_id')->unique();
        if ($allMode) {
            $gids = DB::table('guardians')->whereNotNull('user_id')->pluck('user_id');
        } else {
            $gids = DB::table('guardians')->join('families', 'families.id', '=', 'guardians.family_id')
                ->whereIn('families.centre_id', $centreIds ?: [0])->pluck('guardians.user_id');
        }
        $ids = $userIds->merge($gids)->unique()->values();
        if ($ids->isEmpty()) return response()->json(['groups' => [], 'count' => 0]);

        $users = DB::table('users')->whereIn('id', $ids)->whereNull('deleted_at')
            ->get(['id', 'email', 'username', 'first_name', 'last_name', 'photo_url', 'last_login_at']);
        $rolesByUser = DB::table('role_assignments')->whereIn('user_id', $users->pluck('id'))
            ->where('active', true)->get()->groupBy('user_id');
        $fmt = function ($u) use ($rolesByUser) {
            return [
                'id' => $u->id,
                'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                'email' => $u->email,
                'username' => $u->username,
                'photo_url' => $u->photo_url,
                'last_login_at' => $u->last_login_at,
                'roles' => ($rolesByUser[$u->id] ?? collect())->pluck('role')->unique()->values()->all(),
            ];
        };

        $groups = [];
        foreach ($users->filter(fn ($u) => filled($u->email))
            ->groupBy(fn ($u) => mb_strtolower(trim((string) $u->email))) as $email => $grp) {
            if ($grp->count() < 2) continue;
            $groups[] = ['type' => 'email', 'key' => $email, 'members' => $grp->map($fmt)->values()->all()];
        }
        foreach ($users->filter(fn ($u) => filled(trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''))))
            ->groupBy(fn ($u) => mb_strtolower(trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')))) as $nm => $grp) {
            if ($grp->count() < 2) continue;
            $distinctEmails = $grp->map(fn ($u) => mb_strtolower(trim((string) $u->email)))->unique();
            if ($distinctEmails->count() < 2) continue; // pure email-dupes already listed above
            $first = $grp->first();
            $groups[] = ['type' => 'name', 'key' => trim(($first->first_name ?? '') . ' ' . ($first->last_name ?? '')), 'members' => $grp->map($fmt)->values()->all()];
        }
        return response()->json(['groups' => $groups, 'count' => count($groups)]);
    }

    /**
     * GET /admin/duplicate-check?type=user|child|family&name=&email= — likely-existing
     * records so the entry UI can warn before creating a duplicate. Agency-scoped.
     */
    public function duplicateCheck(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) return response()->json(['matches' => []]);
        $type  = (string) $request->query('type', 'user');
        $name  = trim((string) $request->query('name', ''));
        $email = trim((string) $request->query('email', ''));
        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $matches = [];

        if ($type === 'user') {
            // DUP-DEACT: include deactivated accounts so re-inviting a deactivated
            // person prompts a reactivate instead of creating a duplicate.
            $q = DB::table('users');
            if ($email !== '') $q->whereRaw('LOWER(email) = ?', [mb_strtolower($email)]);
            elseif (mb_strlen($name) >= 3) $q->whereRaw("LOWER(CONCAT(first_name,' ',last_name)) LIKE ?", ['%' . mb_strtolower($name) . '%']);
            else return response()->json(['matches' => []]);
            foreach ($q->limit(6)->get(['id', 'first_name', 'last_name', 'email', 'deleted_at']) as $u) {
                $matches[] = ['id' => (int) $u->id, 'label' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: 'User', 'detail' => $u->email, 'deactivated' => ! empty($u->deleted_at)];
            }
        } elseif ($type === 'child' && mb_strlen($name) >= 3) {
            foreach (DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
                ->whereIn('f.centre_id', $centreIds ?: [0])->whereNull('c.deleted_at')
                ->whereRaw("LOWER(CONCAT(c.first_name,' ',COALESCE(c.last_name,''))) LIKE ?", ['%' . mb_strtolower($name) . '%'])
                ->limit(6)->get(['c.id', 'c.first_name', 'c.last_name', 'f.family_name']) as $c) {
                $matches[] = ['id' => (int) $c->id, 'label' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) ?: 'Child', 'detail' => 'Family: ' . ($c->family_name ?: '—')];
            }
        } elseif ($type === 'family' && mb_strlen($name) >= 3) {
            foreach (DB::table('families')->whereIn('centre_id', $centreIds ?: [0])->whereNull('deleted_at')
                ->whereRaw('LOWER(family_name) LIKE ?', ['%' . mb_strtolower($name) . '%'])
                ->limit(6)->get(['id', 'family_name']) as $f) {
                $matches[] = ['id' => (int) $f->id, 'label' => $f->family_name ?: 'Family', 'detail' => 'Existing family'];
            }
        }
        return response()->json(['matches' => $matches]);
    }

    /** Use unsaved draft blocks from the request when present, else the stored ones. */
    private function draftOrStoredBlocks(Request $request, int $agencyId, string $key): array
    {
        $stored = \App\Support\EmailTemplates::blocks($agencyId, $key);
        if ($request->filled('blocks') && is_array($request->input('blocks'))) {
            foreach ((array) $request->input('blocks') as $k => $v) {
                if ($v !== null && trim((string) $v) !== '') $stored[$k] = (string) $v;
            }
        }
        return $stored;
    }

    /**
     * Send a transactional account email via the branded layout
     * (app/Mail/AccountNotice + emails/account-notice.blade.php). Returns
     * true on dispatch, false if the mailer threw. Errors are logged.
     *
     * v22p3.3: was Mail::raw() — now uses the branded HTML layout with
     * logo, primary colour, and footer (privacy + terms + contact).
     */
    private function sendAccountEmail(string $to, string $name, string $subject, string $body): bool
    {
        try {
            $mailable = new \App\Mail\AccountNotice(
                recipientName: $name ?: 'there',
                subjectLine:   $subject,
                bodyText:      $body,
                ctaLabel:      'Sign in to Kiddietrac',
                ctaUrl:        config('app.url', 'https://app.kiddietrac.com'),
            );
            $mailable->onQueue('mail');
            Mail::to($to, $name ?: null)->queue($mailable); // background — returns immediately
            return true;
        } catch (\Throwable $e) {
            Log::warning('sendAccountEmail failed', [
                'to' => $to, 'subject' => $subject, 'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ── Per-user files / documents (v23, 2026-07-20) ─────────────────────
    // Files filed against a user's record live in the polymorphic `documents`
    // table (scope_type='user'). The signed NDA is auto-filed there by
    // AgreementController (category='agreement'), so it shows up here without
    // any extra work; admins can also attach their own files (contracts,
    // certificates, ID, etc.).

    /** Can the caller manage this user's record? Platform admins: any user; others: same-agency only. */
    private function canManageUser(Request $request, int $userId): bool
    {
        $isPlatform = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) return true;
        $agencyId = $this->getAgencyId($request);
        return $agencyId && $this->userBelongsToAgency($userId, $agencyId);
    }

    /** List every document filed against a user's record. */
    public function userDocuments(Request $request, int $userId): JsonResponse
    {
        if (!$this->canManageUser($request, $userId)) return response()->json(['message' => 'No access to this user'], 403);

        $docs = DB::table('documents')
            ->where('scope_type', 'user')->where('scope_id', $userId)
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'category', 'file_url', 'file_type', 'file_size', 'signed_at', 'signature_url', 'expires_at', 'created_at']);

        return response()->json(['documents' => $docs]);
    }

    /** Attach a file to a user's record. */
    public function uploadUserDocument(Request $request, int $userId): JsonResponse
    {
        if (!$this->canManageUser($request, $userId)) return response()->json(['message' => 'No access to this user'], 403);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx', 'max:10240'],
            'title' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:60'],
        ]);

        $file = $request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $name = (string) Str::uuid() . '.' . $ext;
        // Public disk so the file lands under the /storage symlink (Laravel 11).
        $file->storeAs('user-documents/' . $userId, $name, 'public');
        $publicPath = '/storage/user-documents/' . $userId . '/' . $name;

        $title = trim((string) ($data['title'] ?? '')) ?: ($file->getClientOriginalName() ?: 'Document');
        $id = DB::table('documents')->insertGetId([
            'scope_type'     => 'user',
            'scope_id'       => $userId,
            'category'       => $data['category'] ?? 'file',
            'title'          => mb_substr($title, 0, 200),
            'file_url'       => $publicPath,
            'file_type'      => $file->getClientMimeType() ?: 'application/octet-stream',
            'file_size'      => $file->getSize(),
            'uploaded_by_id' => $request->user()->id,
            'created_at'     => now(),
        ]);

        $this->audit($request->user()->id, 'user.document_uploaded', 'user', $userId, ['document_id' => $id, 'title' => $title]);

        return response()->json(['id' => $id, 'file_url' => $publicPath, 'message' => 'File attached']);
    }

    /** Remove an attached file. Signed agreements (the NDA) are a legal record and can't be deleted. */
    public function deleteUserDocument(Request $request, int $userId, int $docId): JsonResponse
    {
        if (!$this->canManageUser($request, $userId)) return response()->json(['message' => 'No access to this user'], 403);

        $doc = DB::table('documents')->where('id', $docId)
            ->where('scope_type', 'user')->where('scope_id', $userId)->first();
        if (!$doc) return response()->json(['message' => 'Not found'], 404);
        if (($doc->category ?? '') === 'agreement') {
            return response()->json(['message' => 'Signed agreements are a legal record and cannot be deleted.'], 422);
        }

        try {
            $rel = ltrim(str_replace('/storage/', '', (string) $doc->file_url), '/');
            if ($rel !== '') \Illuminate\Support\Facades\Storage::disk('public')->delete($rel);
        } catch (\Throwable $e) {
            // File may already be gone; the DB row is what matters.
        }
        DB::table('documents')->where('id', $docId)->delete();

        $this->audit($request->user()->id, 'user.document_deleted', 'user', $userId, ['document_id' => $docId]);

        return response()->json(['message' => 'File removed']);
    }

    /** Stream a user's document through the API (so the mobile WebView can open it). */
    public function downloadUserDocument(Request $request, int $userId, int $docId)
    {
        if (!$this->canManageUser($request, $userId)) return response()->json(['message' => 'No access to this user'], 403);

        $doc = DB::table('documents')->where('id', $docId)
            ->where('scope_type', 'user')->where('scope_id', $userId)->first();
        if (!$doc) abort(404);
        $rel = ltrim(str_replace('/storage/', '', (string) $doc->file_url), '/');
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        if ($rel === '' || !$disk->exists($rel)) abort(404);
        return response()->file($disk->path($rel));
    }
}
