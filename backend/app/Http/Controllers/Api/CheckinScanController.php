<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * QR check-in / check-out for parents (2026-07-09).
 *
 * The centre displays a QR = a per-centre code that rotates daily
 * ("KTCHK.<centreId>.<YYYYMMDD>.<hmac>"). A guardian scans it in the app;
 * every one of their enrolled children at that centre is toggled in/out.
 * The daily HMAC (over app.key) stops a screenshot being reused another day
 * or from home.
 */
final class CheckinScanController extends Controller
{
    private function sig(int $centreId, string $ymd): string
    {
        return substr(hash_hmac('sha256', $centreId . '.' . $ymd, (string) config('app.key')), 0, 16);
    }

    /**
     * GET /api/v1/checkin/centre-code/{centreId}
     * The current day's code for a centre to display as a QR. Staff/admins only.
     */
    public function centreCode(Request $request, int $centreId): JsonResponse
    {
        $uid = $request->user()->id;
        $ok = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->where(function ($q) use ($centreId) {
                $q->where('centre_id', $centreId)->orWhereIn('role', ['agency_admin', 'platform_admin']);
            })->exists();
        if (!$ok) abort(403);

        $centre = DB::table('centres')->where('id', $centreId)->first();
        if (!$centre) abort(404);

        $ymd = now()->format('Ymd');
        return response()->json([
            'code' => 'KTCHK.' . $centreId . '.' . $ymd . '.' . $this->sig($centreId, $ymd),
            'centre_name' => $centre->name,
            'valid_for' => now()->toDateString(),
        ]);
    }

    /**
     * POST /api/v1/parent/checkin/scan   { code }
     * A guardian scanned the centre QR — toggle each of their enrolled children.
     */
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:120']]);
        $parts = explode('.', $data['code']);
        if (count($parts) !== 4 || $parts[0] !== 'KTCHK') {
            return response()->json(['message' => 'That’s not a KiddieTrac check-in code.'], 422);
        }
        $centreId = (int) $parts[1];
        $ymd = $parts[2];
        $sig = $parts[3];

        if ($ymd !== now()->format('Ymd')) {
            return response()->json(['message' => 'This code has expired — please scan today’s code at the centre.'], 422);
        }
        if (!hash_equals($this->sig($centreId, $ymd), $sig)) {
            return response()->json(['message' => 'Invalid check-in code.'], 422);
        }

        $user = $request->user();
        $children = DB::table('guardians')
            ->join('families', 'families.id', '=', 'guardians.family_id')
            ->join('children', 'children.family_id', '=', 'families.id')
            ->join('enrollments', 'enrollments.child_id', '=', 'children.id')
            ->where('guardians.user_id', $user->id)
            ->where('families.centre_id', $centreId)
            ->whereNull('enrollments.end_date')
            ->where('children.enrollment_status', 'enrolled')
            ->select('children.id', 'children.first_name', 'children.preferred_name', 'enrollments.room_id')
            ->distinct()
            ->get();

        if ($children->isEmpty()) {
            return response()->json(['message' => 'No enrolled children found for you at this centre.'], 422);
        }

        $results = [];
        foreach ($children as $c) {
            $last = DB::table('check_events')
                ->where('child_id', $c->id)
                ->whereDate('occurred_at', now()->toDateString())
                ->orderByDesc('occurred_at')->first();
            $isIn = $last && $last->event_type === 'check_in';
            $newType = $isIn ? 'check_out' : 'check_in';

            DB::table('check_events')->insert([
                'child_id' => $c->id,
                'room_id' => $c->room_id,
                'event_type' => $newType,
                'occurred_at' => now(),
                'by_user_id' => $user->id,
                'recorded_by_id' => $user->id,
                'notes' => 'Parent QR check-in',
                'created_at' => now(),
            ]);

            $results[] = [
                'child_id' => $c->id,
                'name' => $c->preferred_name ?: $c->first_name,
                'event' => $newType,
                'label' => $newType === 'check_in' ? 'checked in' : 'checked out',
                'time' => now()->format('g:i A'),
            ];
        }

        return response()->json(['results' => $results], 201);
    }
}
