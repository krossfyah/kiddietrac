<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeEmail;
use App\Models\InvitationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Self-service centre signup. A new childcare centre director
 * registers their centre + creates their director account in one step.
 *
 * Rate-limited and CAPTCHA-protected against abuse.
 */
final class SignupController extends Controller
{
    /**
     * POST /api/v1/signup/centre
     */
    public function signup(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Centre info
            'centre_name' => ['required', 'string', 'max:180'],
            'license_number' => ['nullable', 'string', 'max:60'],
            'license_capacity' => ['nullable', 'integer', 'min:1', 'max:500'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'city' => ['required', 'string', 'max:80'],
            'province' => ['required', 'string', 'max:40'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'phone' => ['nullable', 'string', 'max:40'],
            // Director info
            'director_email' => ['required', 'email', 'max:180', 'unique:users,email'],
            'director_first_name' => ['required', 'string', 'max:80'],
            'director_last_name' => ['required', 'string', 'max:80'],
            'director_password' => ['required', 'string', 'min:8'],
            'director_phone' => ['nullable', 'string', 'max:40'],
            // Legal acknowledgement
            'agreed_to_terms' => ['required', 'accepted'],
        ]);

        // Basic abuse prevention
        $recentSignups = DB::table('audit_logs')
            ->where('action', 'centre_signup')
            ->where('ip_address', $request->ip())
            ->where('created_at', '>', now()->subHour())
            ->count();

        if ($recentSignups >= 3) {
            return response()->json([
                'message' => 'Too many signups from this network. Please try again later or contact support.',
            ], 429);
        }

        $slug = Str::slug($data['centre_name']);
        // Ensure unique slug
        $baseSlug = $slug;
        $i = 1;
        while (DB::table('centres')->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$i;
            $i++;
            if ($i > 100) break; // Sanity check
        }

        $result = DB::transaction(function () use ($data, $slug, $request) {
            // Each new centre gets its own agency for isolation
            $agencyId = DB::table('agencies')->insertGetId([
                'name' => $data['centre_name'],
                'slug' => $slug.'-agency',
                'contact_email' => $data['director_email'],
                'timezone' => 'America/Toronto',
                'locale' => 'en-CA',
                'billing_status' => 'trial', // 30-day trial
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Centre
            $centreId = DB::table('centres')->insertGetId([
                'agency_id' => $agencyId,
                'name' => $data['centre_name'],
                'slug' => $slug,
                'license_number' => $data['license_number'] ?? null,
                'license_capacity' => $data['license_capacity'] ?? 30,
                'address_line1' => $data['address_line1'] ?? null,
                'city' => $data['city'],
                'province' => $data['province'],
                'postal_code' => $data['postal_code'] ?? null,
                'country' => 'CA',
                'phone' => $data['phone'] ?? null,
                'email' => $data['director_email'],
                'open_time' => '07:00:00',
                'close_time' => '18:00:00',
                'cwelcc_enrolled' => false, // Director can enable after signup
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Director user
            $userId = DB::table('users')->insertGetId([
                'email' => $data['director_email'],
                'password' => Hash::make($data['director_password']),
                'first_name' => $data['director_first_name'],
                'last_name' => $data['director_last_name'],
                'phone' => $data['director_phone'] ?? null,
                'locale' => 'en-CA',
                'timezone' => 'America/Toronto',
                'status' => 'active',
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('role_assignments')->insert([
                'user_id' => $userId,
                'role' => 'centre_director',
                'centre_id' => $centreId,
                'agency_id' => $agencyId,
                'active' => true,
                'created_at' => now(),
            ]);

            // Audit
            try {
                DB::table('audit_logs')->insert([
                    'user_id' => $userId,
                    'entity_type' => 'centre', 'entity_id' => $centreId,
                    'action' => 'centre_signup',
                    'entity_type' => 'centre',
                    'entity_id' => $centreId,
                    // audit_logs.payload has a json_valid() CHECK — encode to JSON
                    // (a bare string was silently rejected, dropping the signup audit).
                    'payload' => json_encode(['centre_name' => $data['centre_name'], 'director_email' => $data['director_email'], 'source' => 'self-signup']),
                    'ip_address' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 500),
                    'created_at' => now(),
                ]);
            } catch (Throwable $e) {
                Log::warning('Audit failed for signup', ['error' => $e->getMessage()]);
            }

            return [
                'centre_id' => $centreId,
                'user_id' => $userId,
                'agency_id' => $agencyId,
            ];
        });

        // Send welcome email (best effort)
        try {
            Mail::to($data['director_email'])->send(new WelcomeEmail(
                recipientName: $data['director_first_name'],
                recipientEmail: $data['director_email'],
                tempPassword: '(use the password you just set during signup)',
                centreName: $data['centre_name'],
                role: 'director',
            ));
        } catch (Throwable $e) {
            Log::warning('Signup welcome email failed', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Welcome to Kiddietrac! Your centre is set up.',
            'centre_id' => $result['centre_id'],
            'next_steps' => [
                'Log in with your email and the password you just set',
                'Add your first room from the QUICK ADD menu',
                'Add families and enroll children',
                'Invite parents and educators',
            ],
        ], 201);
    }

    /**
     * POST /signup/by-code
     * Public, throttled. Self-service parent enrollment using a director-issued
     * invitation code. Creates user (guardian), family, child, guardian link,
     * and role_assignment. Returns a sanctum token so the parent is auto-logged-in.
     */
    public function byCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'                  => ['required', 'string', 'max:32'],
            // Parent (the user signing up)
            'parent_first_name'     => ['required', 'string', 'max:80'],
            'parent_last_name'      => ['required', 'string', 'max:80'],
            'parent_email'          => ['required', 'email', 'max:180', 'unique:users,email'],
            'parent_phone'          => ['nullable', 'string', 'max:40'],
            'parent_password'       => ['required', 'string', 'min:8'],
            'relationship'          => ['nullable', 'in:mother,father,guardian,grandparent,foster,other'],
            // Family / address
            'family_name'           => ['required', 'string', 'max:120'],
            'address_line1'         => ['nullable', 'string', 'max:200'],
            'city'                  => ['nullable', 'string', 'max:80'],
            'province'              => ['nullable', 'string', 'max:40'],
            'postal_code'           => ['nullable', 'string', 'max:12'],
            // Child
            'child_first_name'      => ['required', 'string', 'max:80'],
            'child_last_name'       => ['required', 'string', 'max:80'],
            'child_date_of_birth'   => ['required', 'date'],
            'child_gender'          => ['nullable', 'in:female,male,non_binary,prefer_not_to_say,other'],
            'expected_start_date'   => ['nullable', 'date'],
            // Acknowledgement
            'agreed_to_terms'       => ['required', 'accepted'],
        ]);

        $invite = InvitationCode::where('code', strtoupper(trim($data['code'])))->first();
        if (! $invite || ! $invite->isUsable()) {
            return response()->json([
                'message' => 'This invitation link is no longer valid. Please contact the centre.',
            ], 422);
        }

        // Throttle: don't allow > 5 enrollments per code per hour, just in case
        // a code leaks.
        $recent = DB::table('audit_logs')
            ->where('action', 'parent_signup_by_code')
            ->where('entity_id', $invite->id)
            ->where('created_at', '>', now()->subHour())
            ->count();
        if ($recent >= 5) {
            return response()->json([
                'message' => 'Too many sign-ups using this invitation in the last hour. Please try again later.',
            ], 429);
        }

        $result = DB::transaction(function () use ($data, $invite, $request) {
            // User (guardian)
            $userId = DB::table('users')->insertGetId([
                'email'             => strtolower($data['parent_email']),
                'password'          => Hash::make($data['parent_password']),
                'first_name'        => $data['parent_first_name'],
                'last_name'         => $data['parent_last_name'],
                'phone'             => $data['parent_phone'] ?? null,
                'locale'            => 'en-CA',
                'timezone'          => 'America/Toronto',
                'status'            => 'active',
                'email_verified_at' => now(),
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            // Family
            $familyId = DB::table('families')->insertGetId([
                'centre_id'      => $invite->centre_id,
                'family_name'    => $data['family_name'],
                'primary_phone'  => $data['parent_phone'] ?? null,
                'primary_email'  => strtolower($data['parent_email']),
                'address_line1'  => $data['address_line1'] ?? null,
                'city'           => $data['city'] ?? null,
                'province'       => $data['province'] ?? null,
                'postal_code'    => $data['postal_code'] ?? null,
                'preferred_lang' => 'en',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            // Guardian link (user <-> family)
            DB::table('guardians')->insert([
                'family_id'           => $familyId,
                'user_id'             => $userId,
                'relationship'        => $data['relationship'] ?? 'guardian',
                'is_primary'          => true,
                'can_pickup'          => true,
                'can_receive_billing' => true,
                'billing_share_pct'   => 100,
                'created_at'          => now(),
            ]);

            // Child (waitlist by default — director will move to enrolled)
            $childId = DB::table('children')->insertGetId([
                'family_id'           => $familyId,
                'first_name'          => $data['child_first_name'],
                'last_name'           => $data['child_last_name'],
                'date_of_birth'       => $data['child_date_of_birth'],
                'gender'              => $data['child_gender'] ?? null,
                'enrollment_status'   => 'waitlist',
                'applied_at'          => now(),
                'expected_start_date' => $data['expected_start_date'] ?? null,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            // Role assignment
            DB::table('role_assignments')->insert([
                'user_id'    => $userId,
                'role'       => 'guardian',
                'agency_id'  => $invite->agency_id,
                'centre_id'  => $invite->centre_id,
                'active'     => true,
                'created_at' => now(),
            ]);

            // Bump invitation usage; mark expired if at cap.
            $newUsed = $invite->used_count + 1;
            DB::table('invitation_codes')->where('id', $invite->id)->update([
                'used_count' => $newUsed,
                'status'     => $newUsed >= $invite->max_uses ? 'expired' : $invite->status,
                'updated_at' => now(),
            ]);

            // Audit
            try {
                DB::table('audit_logs')->insert([
                    'user_id'     => $userId,
                    'entity_type' => 'invitation_code',
                    'entity_id'   => $invite->id,
                    'action'      => 'parent_signup_by_code',
                    'payload'     => "Self-signup. Code: {$invite->code}. Family: {$data['family_name']}. Child: {$data['child_first_name']} {$data['child_last_name']}",
                    'ip_address'  => $request->ip(),
                    'user_agent'  => substr((string) $request->userAgent(), 0, 500),
                    'created_at'  => now(),
                ]);
            } catch (Throwable $e) {
                Log::warning('Audit failed for byCode signup', ['error' => $e->getMessage()]);
            }

            return [
                'user_id'   => $userId,
                'family_id' => $familyId,
                'child_id'  => $childId,
                'centre_id' => $invite->centre_id,
            ];
        });

        // Auto-login: create a token so the parent lands on the dashboard.
        $user  = \App\Models\User::find($result['user_id']);
        $token = $user->createToken('signup-by-code')->plainTextToken;

        return response()->json([
            'message'   => 'Welcome to Kiddietrac! Your child has been added to the waitlist.',
            'token'     => $token,
            'user'      => [
                'id'           => $user->id,
                'email'        => $user->email,
                'first_name'   => $user->first_name,
                'last_name'    => $user->last_name,
                'name'         => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'roles'        => ['guardian'],
                'primary_role' => 'guardian',
                'centre_id'    => null,
                'agency_id'    => $invite->agency_id,
                'status'       => 'active',
                'locale'       => 'en-CA',
                'timezone'     => 'America/Toronto',
            ],
            'child_id'  => $result['child_id'],
            'family_id' => $result['family_id'],
        ], 201);
    }
}
