<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Models\InvitationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * InvitationController v22p2.1
 *
 * Director-managed invite codes for self-service parent (guardian) enrollment.
 * A parent who has a code can self-serve at /enroll.html?code=XXXX
 * (handled by SignupController::byCode).
 *
 *   GET    /director/invitation-codes
 *   POST   /director/invitation-codes
 *   POST   /director/invitation-codes/{id}/revoke
 *   DELETE /director/invitation-codes/{id}
 *
 * Plus one public probe endpoint for the public form:
 *   GET    /signup/invitation/{code}    show centre name + label without leaking PII
 */
class InvitationController extends Controller
{
    use ResolvesCentreContext;

    public function index(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (! $centreId) {
            return response()->json(['invitation_codes' => []]);
        }

        $rows = InvitationCode::query()
            ->where('centre_id', $centreId)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $creators = DB::table('users')
            ->whereIn('id', $rows->pluck('created_by_id')->unique())
            ->pluck(DB::raw("CONCAT(first_name, ' ', last_name)"), 'id');

        $out = $rows->map(function ($r) use ($creators) {
            $arr = $r->toArray();
            $arr['created_by_name'] = $creators[$r->created_by_id] ?? null;
            $arr['is_usable']       = $r->isUsable();
            return $arr;
        });

        return response()->json(['invitation_codes' => $out]);
    }

    public function store(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (! $centreId) {
            return response()->json(['message' => 'No centre access'], 403);
        }

        $data = $request->validate([
            'label'      => ['nullable', 'string', 'max:200'],
            'role'       => ['nullable', 'in:guardian,educator'],
            'max_uses'   => ['nullable', 'integer', 'min:1', 'max:200'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        // Derive agency_id from the centre.
        $centre = DB::table('centres')->where('id', $centreId)->first();
        $agencyId = $centre ? (int) $centre->agency_id : 0;
        if (! $agencyId) {
            return response()->json(['message' => 'Centre has no agency'], 422);
        }

        // 10-char code (Crockford-ish alphabet — no 0/O/1/I) so directors can read it aloud.
        $code = $this->generateUniqueCode();

        $row = InvitationCode::create([
            'code'          => $code,
            'agency_id'     => $agencyId,
            'centre_id'     => $centreId,
            'created_by_id' => $request->user()->id,
            'label'         => $data['label']     ?? null,
            'role'          => $data['role']      ?? 'guardian',
            'max_uses'      => $data['max_uses']  ?? 1,
            'expires_at'    => $data['expires_at'] ?? null,
            'status'        => 'active',
        ]);

        return response()->json(['invitation_code' => $row], 201);
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        $row = InvitationCode::where('centre_id', $centreId)->find($id);
        if (! $row) return response()->json(['message' => 'Not found'], 404);
        $row->update(['status' => 'revoked']);
        return response()->json(['invitation_code' => $row->fresh()]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        $row = InvitationCode::where('centre_id', $centreId)->find($id);
        if (! $row) return response()->json(['message' => 'Not found'], 404);
        // Only allow hard-delete of UNUSED codes; used codes stay as audit trail.
        if ($row->used_count > 0) {
            return response()->json(['message' => 'Cannot delete a code that has been used. Revoke instead.'], 422);
        }
        $row->delete();
        return response()->json(['ok' => true]);
    }

    /**
     * Public probe — given a code, return the centre name + label so the
     * parent can verify they are signing up at the right place. Returns 404
     * for invalid or unusable codes (don't leak which case).
     */
    public function probe(Request $request, string $code): JsonResponse
    {
        $row = InvitationCode::where('code', strtoupper(trim($code)))->first();
        if (! $row || ! $row->isUsable()) {
            return response()->json(['message' => 'This invitation link is no longer valid. Please contact the centre.'], 404);
        }
        $centre = DB::table('centres')->where('id', $row->centre_id)->first();
        return response()->json([
            'centre_name'  => $centre->name ?? '',
            'centre_city'  => $centre->city ?? '',
            'role'         => $row->role,
            'label'        => $row->label,
            'remaining'    => $row->max_uses - $row->used_count,
            'expires_at'   => $row->expires_at,
        ]);
    }

    /**
     * 10-char code from an unambiguous alphabet, with collision retry.
     */
    private function generateUniqueCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            if (! DB::table('invitation_codes')->where('code', $code)->exists()) {
                return $code;
            }
        }
        // Fallback — append a timestamp suffix
        return $code . dechex(time() % 65536);
    }
}
