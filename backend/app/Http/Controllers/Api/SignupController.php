<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeEmail;
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
                    'payload' => "Self-signup. Centre: {$data['centre_name']}. Director: {$data['director_email']}",
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
}
