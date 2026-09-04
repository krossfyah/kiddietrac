<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform-admin "view as" — lets a super admin see the app exactly as any
 * parent / educator / director / admin sees it. Route group is already gated
 * to role:platform_admin. `directory` powers the name picker; `start` mints a
 * short-lived token for the target so the API returns THEIR data (true
 * impersonation, not a client-side role flip). Every start is audit-logged.
 */
class ImpersonationController extends Controller
{
    /** Matches AuthController::pickPrimaryRole so the impersonated view equals
     *  what the target sees when they log in as themselves. */
    private function primaryRole(array $roles): ?string
    {
        return match (true) {
            in_array('agency_admin', $roles, true)    => 'agency_admin',
            in_array('platform_admin', $roles, true)  => 'agency_admin',
            in_array('centre_director', $roles, true) => 'centre_director',
            in_array('educator', $roles, true)        => 'educator',
            in_array('guardian', $roles, true)         => 'guardian',
            in_array('auditor', $roles, true)          => 'auditor',
            in_array('sales_rep', $roles, true)        => 'sales_rep',
            default                                    => $roles[0] ?? null,
        };
    }

    /** GET /platform/directory?search=&role= — everyone with a role, for the picker. */
    public function directory(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $role   = trim((string) $request->query('role', ''));

        $q = DB::table('users')
            ->join('role_assignments as ra', function ($j) {
                $j->on('ra.user_id', '=', 'users.id')->where('ra.active', true);
            })
            ->leftJoin('agencies as a', 'a.id', '=', 'ra.agency_id')
            ->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
            ->selectRaw("users.id, users.first_name, users.last_name, users.email, users.photo_url,
                GROUP_CONCAT(DISTINCT ra.role) as roles,
                MAX(a.name) as agency_name, MAX(c.name) as centre_name")
            ->groupBy('users.id', 'users.first_name', 'users.last_name', 'users.email', 'users.photo_url');

        if ($search !== '') {
            $like = '%' . $search . '%';
            $q->where(function ($w) use ($like) {
                $w->where('users.first_name', 'like', $like)
                  ->orWhere('users.last_name', 'like', $like)
                  ->orWhere('users.email', 'like', $like)
                  ->orWhereRaw("CONCAT(COALESCE(users.first_name,''),' ',COALESCE(users.last_name,'')) like ?", [$like]);
            });
        }
        if ($role !== '') {
            $q->whereExists(function ($sub) use ($role) {
                $sub->selectRaw('1')->from('role_assignments as r2')
                    ->whereColumn('r2.user_id', 'users.id')
                    ->where('r2.active', true)->where('r2.role', $role);
            });
        }

        $rows = $q->orderBy('users.first_name')->orderBy('users.last_name')->limit(1000)->get();

        $labels = ['guardian' => 'Parent', 'educator' => 'Educator', 'centre_director' => 'Director', 'agency_admin' => 'Admin', 'platform_admin' => 'Super Admin', 'auditor' => 'Auditor'];

        $users = $rows->map(function ($r) use ($labels) {
            $roles = array_values(array_filter(explode(',', (string) $r->roles)));
            $primary = $this->primaryRole($roles);
            return [
                'id'         => (int) $r->id,
                'name'       => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: $r->email,
                'email'      => $r->email,
                'photo_url'  => $r->photo_url,
                'roles'      => $roles,
                'role'       => $primary,
                'role_label' => $primary ? ($labels[$primary] ?? ucfirst($primary)) : '—',
                'agency'     => $r->agency_name,
                'centre'     => $r->centre_name,
            ];
        })->filter(fn ($u) => $u['role'] !== null)->values();

        return response()->json(['users' => $users]);
    }

    /** POST /platform/impersonate/{user} — mint a short-lived token for the target. */
    public function start(Request $request, int $userId): JsonResponse
    {
        $admin  = $request->user();
        $target = User::findOrFail($userId);

        // A 2-hour token, distinctly named so it's easy to spot/revoke.
        $token = $target->createToken('impersonation:by-' . $admin->id, ['*'], now()->addHours(2))->plainTextToken;

        // Audit (best-effort — never block the action on a logging hiccup).
        try {
            \App\Support\Audit::write([
                'user_id'     => $admin->id,
                'action'      => 'impersonate.start',
                'entity_type' => 'user',
                'entity_id'   => $target->id,
                'payload'     => json_encode(['target_email' => $target->email, 'target_name' => trim(($target->first_name ?? '') . ' ' . ($target->last_name ?? ''))]),
                'ip_address'  => $request->ip(),
                'user_agent'  => mb_substr((string) $request->userAgent(), 0, 255),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
        }

        $roles = DB::table('role_assignments')->where('user_id', $target->id)->where('active', true)
            ->pluck('role')->unique()->values()->all();
        $agencyId = DB::table('role_assignments')->where('user_id', $target->id)->where('active', true)
            ->whereNotNull('agency_id')->value('agency_id');

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'           => $target->id,
                'email'        => $target->email,
                'first_name'   => $target->first_name,
                'last_name'    => $target->last_name,
                'name'         => trim(($target->first_name ?? '') . ' ' . ($target->last_name ?? '')) ?: $target->email,
                'photo_url'    => $target->photo_url ?? null,
                'roles'        => $roles,
                'primary_role' => $this->primaryRole($roles),
                'agency_id'    => $agencyId,
            ],
        ]);
    }
}
