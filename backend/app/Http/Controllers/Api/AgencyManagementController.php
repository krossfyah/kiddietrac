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

/**
 * v12-big: Manage agencies (provision new resellers / branches / brands).
 *
 * Only "platform admins" — currently agency_admins of agency_id=1 (Kiddietrac itself) —
 * can create new agencies. Each new agency gets:
 *   - An agencies row with a trial billing status
 *   - One initial centre
 *   - One agency_admin user with a status='invited' + password reset token emailed
 *   - Optional subdomain (must end in .kiddietrac.com) reserved up front
 *
 * Agency-admins of OTHER agencies can manage their own agency only — branding,
 * centres, users — but not create new top-level agencies.
 */
final class AgencyManagementController extends Controller
{
    /**
     * GET /api/v1/admin/agencies
     * Platform admins (Kiddietrac itself) see all agencies.
     * Other agency_admins see only their own.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $isPlatformAdmin = $this->isPlatformAdmin($user->id);

        $q = DB::table('agencies')->whereNull('deleted_at');
        if (! $isPlatformAdmin) {
            $myAgencyIds = DB::table('role_assignments')
                ->where('user_id', $user->id)
                ->where('role', 'agency_admin')
                ->where('active', true)
                ->pluck('agency_id')
                ->all();
            if (empty($myAgencyIds)) {
                return response()->json(['agencies' => []]);
            }
            $q->whereIn('id', $myAgencyIds);
        }

        $agencies = $q->orderBy('name')->get()->map(function ($a) {
            $centreCount = DB::table('centres')->where('agency_id', $a->id)->whereNull('deleted_at')->count();
            $userCount   = DB::table('role_assignments')->where('agency_id', $a->id)->where('active', true)->distinct('user_id')->count('user_id');
            $settings    = json_decode($a->settings ?? '{}', true) ?: [];
            return [
                'id' => $a->id,
                'name' => $a->name,
                'slug' => $a->slug,
                'subdomain' => $a->subdomain ?? null,
                'custom_domain' => $a->custom_domain ?? null,
                'billing_status' => $a->billing_status,
                'trial_ends_at' => $a->trial_ends_at,
                'plan' => $settings['plan'] ?? 'centre',
                'centre_count' => $centreCount,
                'user_count' => $userCount,
                'contact_email' => $a->contact_email,
                'logo_url' => $a->logo_url,
                'created_at' => $a->created_at,
            ];
        });

        return response()->json(['agencies' => $agencies, 'is_platform_admin' => $isPlatformAdmin]);
    }

    /**
     * POST /api/v1/admin/agencies
     * Platform-admin only. Create a new agency + first centre + first admin user.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->isPlatformAdmin($user->id)) {
            return response()->json(['message' => 'Only platform admins can create agencies.'], 403);
        }

        $data = $request->validate([
            'agency_name' => ['required', 'string', 'max:180'],
            'subdomain' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/'],
            'custom_domain' => ['nullable', 'string', 'max:200'],
            'plan' => ['required', 'in:starter,centre,agency'],
            'trial_days' => ['nullable', 'integer', 'min:1', 'max:90'],

            'centre_name' => ['required', 'string', 'max:180'],
            'centre_city' => ['required', 'string', 'max:80'],
            'centre_capacity' => ['required', 'integer', 'min:1', 'max:500'],

            'admin_email' => ['required', 'email', 'max:180', 'unique:users,email'],
            'admin_first_name' => ['required', 'string', 'max:80'],
            'admin_last_name' => ['required', 'string', 'max:80'],
            'admin_phone' => ['nullable', 'string', 'max:40'],

            'primary_color' => ['nullable', 'string', 'max:9'],
            'accent_color' => ['nullable', 'string', 'max:9'],
        ]);

        // Reserve names
        $slug = $this->uniqueSlug('agencies', 'slug', Str::slug($data['agency_name']));
        $centreSlug = Str::slug($data['centre_name']);

        // Verify subdomain isn't already taken (if provided)
        if (!empty($data['subdomain'])) {
            $taken = DB::table('agencies')->where('subdomain', $data['subdomain'])->exists();
            if ($taken) {
                return response()->json(['message' => 'Subdomain already taken'], 422);
            }
        }

        $plans = [
            'starter' => ['monthly_cents' => 4900, 'child_cap' => 12, 'label' => 'Starter'],
            'centre' => ['monthly_cents' => 14900, 'child_cap' => 80, 'label' => 'Centre'],
            'agency' => ['monthly_cents' => 34900, 'child_cap' => null, 'label' => 'Agency'],
        ];
        $plan = $plans[$data['plan']];
        $trialDays = $data['trial_days'] ?? 14;

        try {
            $result = DB::transaction(function () use ($data, $plan, $slug, $centreSlug, $trialDays) {
                $settings = [
                    'plan' => $data['plan'],
                    'plan_label' => $plan['label'],
                    'monthly_cents' => $plan['monthly_cents'],
                    'child_cap' => $plan['child_cap'],
                    'signup_source' => 'platform_admin',
                    'branding' => [
                        'primary_color' => $data['primary_color'] ?? '#1F6080',
                        'accent_color' => $data['accent_color'] ?? '#8EC73C',
                    ],
                ];

                $agencyId = DB::table('agencies')->insertGetId([
                    'name' => $data['agency_name'],
                    'slug' => $slug,
                    'subdomain' => $data['subdomain'] ?? null,
                    'custom_domain' => $data['custom_domain'] ?? null,
                    'contact_email' => $data['admin_email'],
                    'contact_phone' => $data['admin_phone'] ?? null,
                    'timezone' => 'America/Toronto',
                    'locale' => 'en-CA',
                    'billing_status' => 'trial',
                    'trial_ends_at' => Carbon::now()->addDays($trialDays),
                    'settings' => json_encode($settings),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $centreId = DB::table('centres')->insertGetId([
                    'agency_id' => $agencyId,
                    'name' => $data['centre_name'],
                    'slug' => $centreSlug,
                    'license_capacity' => $data['centre_capacity'],
                    'city' => $data['centre_city'],
                    'province' => 'ON',
                    'country' => 'CA',
                    'email' => $data['admin_email'],
                    'phone' => $data['admin_phone'] ?? null,
                    'status' => 'onboarding',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $tempPw = Str::random(32);
                $userId = DB::table('users')->insertGetId([
                    'email' => $data['admin_email'],
                    'password' => Hash::make($tempPw),
                    'first_name' => $data['admin_first_name'],
                    'last_name' => $data['admin_last_name'],
                    'phone' => $data['admin_phone'] ?? null,
                    'status' => 'invited',
                    'locale' => 'en-CA',
                    'timezone' => 'America/Toronto',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('role_assignments')->insert([
                    'user_id' => $userId,
                    'role' => 'agency_admin',
                    'agency_id' => $agencyId,
                    'centre_id' => null,
                    'active' => 1,
                    'created_at' => now(),
                ]);

                $resetToken = bin2hex(random_bytes(32));
                DB::table('password_resets')->insert([
                    'email' => $data['admin_email'],
                    'token' => $resetToken,
                    'expires_at' => Carbon::now()->addDays(7),
                    'created_at' => now(),
                ]);

                DB::table('audit_logs')->insert([
                    'user_id' => $userId,
                    'action' => 'agency.provisioned',
                    'entity_type' => 'agency',
                    'entity_id' => $agencyId,
                    'payload' => json_encode([
                        'agency_name' => $data['agency_name'],
                        'plan' => $data['plan'],
                        'centre_name' => $data['centre_name'],
                    ]),
                    'created_at' => now(),
                ]);

                return [
                    'agency_id' => $agencyId,
                    'centre_id' => $centreId,
                    'user_id' => $userId,
                    'reset_token' => $resetToken,
                ];
            });

            $this->sendInvite($data, $result['reset_token']);

            return response()->json([
                'success' => true,
                'agency_id' => $result['agency_id'],
                'centre_id' => $result['centre_id'],
                'admin_user_id' => $result['user_id'],
                'trial_ends' => Carbon::now()->addDays($trialDays)->toDateString(),
            ], 201);

        } catch (\Throwable $e) {
            Log::error('Agency provisioning failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Could not create agency.',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PATCH /api/v1/admin/agencies/{id}
     * Edit agency: rename, change subdomain, custom domain, plan, branding.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $this->canManageAgency($user->id, $id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:180'],
            'subdomain' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/'],
            'custom_domain' => ['nullable', 'string', 'max:200'],
            'contact_email' => ['nullable', 'email'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'billing_status' => ['nullable', 'in:trial,active,past_due,suspended'],
            'trial_ends_at' => ['nullable', 'date'],
            'plan' => ['nullable', 'in:starter,centre,agency'],
            'notifications_enabled' => ['sometimes', 'boolean'],
        ]);

        $update = [];
        foreach (['name', 'subdomain', 'custom_domain', 'contact_email', 'contact_phone', 'billing_status', 'trial_ends_at'] as $k) {
            if (array_key_exists($k, $data)) $update[$k] = $data[$k];
        }

        if (isset($data['plan']) || array_key_exists('notifications_enabled', $data)) {
            $agency = DB::table('agencies')->where('id', $id)->first();
            $settings = json_decode($agency->settings ?? '{}', true) ?: [];
            if (isset($data['plan'])) $settings['plan'] = $data['plan'];
            if (array_key_exists('notifications_enabled', $data)) $settings['notifications_enabled'] = (bool) $data['notifications_enabled'];
            $update['settings'] = json_encode($settings);
            \Illuminate\Support\Facades\Cache::forget('kt.agency_notifications:' . $id);
        }

        if (!empty($update)) {
            $update['updated_at'] = now();
            DB::table('agencies')->where('id', $id)->update($update);
            DB::table('audit_logs')->insert([
                'user_id' => $user->id,
                'action' => 'agency.updated',
                'entity_type' => 'agency',
                'entity_id' => $id,
                'payload' => json_encode(array_keys($update)),
                'created_at' => now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * DELETE /api/v1/admin/agencies/{id}
     * Soft-delete (set deleted_at). Only platform admin. Cannot delete agency 1 (Kiddietrac).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $this->isPlatformAdmin($user->id)) {
            return response()->json(['message' => 'Only platform admins can delete agencies.'], 403);
        }
        if ($id === 1) {
            return response()->json(['message' => 'Cannot delete the root Kiddietrac agency.'], 422);
        }

        DB::table('agencies')->where('id', $id)->update([
            'deleted_at' => now(),
            'billing_status' => 'suspended',
        ]);

        DB::table('audit_logs')->insert([
            'user_id' => $user->id,
            'action' => 'agency.deleted',
            'entity_type' => 'agency',
            'entity_id' => $id,
            'payload' => null,
            'created_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /* ─── HELPERS ────────────────────────────────────────────────── */

    private function isPlatformAdmin(int $userId): bool
    {
        return DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('role', 'agency_admin')
            ->where('agency_id', 1)
            ->where('active', true)
            ->exists();
    }

    private function canManageAgency(int $userId, int $agencyId): bool
    {
        // Platform admin can manage any agency
        if ($this->isPlatformAdmin($userId)) return true;
        // Agency admin can manage their own
        return DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('role', 'agency_admin')
            ->where('agency_id', $agencyId)
            ->where('active', true)
            ->exists();
    }

    private function uniqueSlug(string $table, string $column, string $base): string
    {
        $slug = $base ?: 'tenant';
        $i = 1;
        while (DB::table($table)->where($column, $slug)->exists()) {
            $i++;
            $slug = $base . '-' . $i;
        }
        return $slug;
    }

    private function sendInvite(array $data, string $token): void
    {
        $link = 'https://app.kiddietrac.com/set-password.html?token=' . $token;
        if (!empty($data['subdomain'])) {
            $link = 'https://' . $data['subdomain'] . '.kiddietrac.com/set-password.html?token=' . $token;
        }

        $body = "Hi " . $data['admin_first_name'] . ",\n\n";
        $body .= "Your agency account for " . $data['agency_name'] . " on Kiddietrac is ready.\n\n";
        $body .= "Click here to set your password and sign in:\n" . $link . "\n\n";
        $body .= "This link expires in 7 days.\n\n";
        $body .= "What's been set up for you:\n";
        $body .= "• Agency: " . $data['agency_name'] . " (plan: " . $data['plan'] . ")\n";
        $body .= "• First centre: " . $data['centre_name'] . " in " . $data['centre_city'] . "\n";
        $body .= "• " . ($data['trial_days'] ?? 14) . "-day free trial — no card required\n\n";
        $body .= "— The Kiddietrac team";

        try {
            Mail::raw($body, function ($m) use ($data) {
                $m->to($data['admin_email'], $data['admin_first_name'] . ' ' . $data['admin_last_name'])
                  ->from('noreply@kiddietrac.com', 'KiddieTrac')
                  ->subject('Welcome to Kiddietrac — set your password');
            });
        } catch (\Throwable $e) {
            Log::warning('Agency invite email failed', ['error' => $e->getMessage()]);
        }
    }
}
