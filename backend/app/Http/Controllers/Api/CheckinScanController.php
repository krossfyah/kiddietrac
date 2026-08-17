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

    /** A unique, unambiguous 6-char code (no 0/O/1/I/L) for manual check-in entry. */
    private function makeShortCode(): string
    {
        $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        for ($try = 0; $try < 12; $try++) {
            $c = '';
            for ($i = 0; $i < 6; $i++) $c .= $alpha[random_int(0, strlen($alpha) - 1)];
            if (!DB::table('checkin_short_codes')->where('code', $c)->exists()) return $c;
        }
        return substr(strtoupper(bin2hex(random_bytes(3))), 0, 6);
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
        // Mint a fresh short code each time the QR is opened (unique → traceable).
        // The QR image keeps encoding the day's KTCHK code; the short code is the
        // typeable fallback shown beneath it.
        $short = $this->makeShortCode();
        DB::table('checkin_short_codes')->insert([
            'code'       => $short,
            'centre_id'  => $centreId,
            'created_by' => $uid,
            'used_count' => 0,
            'expires_at' => now()->endOfDay(),
            'created_at' => now(),
        ]);
        return response()->json([
            'code' => 'KTCHK.' . $centreId . '.' . $ymd . '.' . $this->sig($centreId, $ymd),
            'short_code' => $short,
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
        $data = $request->validate([
            'code' => ['required', 'string', 'max:120'],
            'child_ids' => ['sometimes', 'array'],
            'child_ids.*' => ['integer'],
        ]);
        $raw = trim($data['code']);
        $centreId = null;

        // Short manual code (6 chars, no dots) — resolve the centre from the DB.
        if (preg_match('/^[A-Za-z0-9]{6}$/', $raw)) {
            $sc = DB::table('checkin_short_codes')->where('code', strtoupper($raw))->first();
            if (!$sc) {
                return response()->json(['message' => 'That code isn’t valid — check it and try again.'], 422);
            }
            if ($sc->expires_at && strtotime((string) $sc->expires_at) < time()) {
                return response()->json(['message' => 'This code has expired — ask the centre for today’s code.'], 422);
            }
            $centreId = (int) $sc->centre_id;
            DB::table('checkin_short_codes')->where('id', $sc->id)->increment('used_count');
        } else {
            // The scannable KTCHK.<centre>.<ymd>.<hmac> code.
            $parts = explode('.', $raw);
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

        // More than one child on this account: don't silently toggle them all.
        // The first scan (no child_ids) returns a preview so the app can ask WHICH
        // children to sign in/out; the app then re-posts with the chosen ids.
        $selected = $request->input('child_ids');
        if (empty($selected) && $children->count() > 1) {
            $preview = $children->map(function ($c) {
                $last = DB::table('check_events')
                    ->where('child_id', $c->id)
                    ->whereDate('occurred_at', now()->toDateString())
                    ->orderByDesc('occurred_at')->first();
                $isIn = $last && $last->event_type === 'check_in';
                return [
                    'child_id' => $c->id,
                    'name' => $c->preferred_name ?: $c->first_name,
                    'is_in' => $isIn,
                    'action' => $isIn ? 'check_out' : 'check_in',
                    'action_label' => $isIn ? 'Check out' : 'Check in',
                ];
            })->values();
            return response()->json(['needs_selection' => true, 'children' => $preview], 200);
        }
        if (! empty($selected)) {
            $sel = array_map('intval', (array) $selected);
            $children = $children->filter(fn ($c) => in_array((int) $c->id, $sel, true))->values();
            if ($children->isEmpty()) {
                return response()->json(['message' => 'No matching children were selected.'], 422);
            }
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

            // The QR scan is a check-in/out like any other — the other guardians
            // on the family should hear about it too.
            try {
                app(\App\Services\CheckEventNotifier::class)->notify((int) $c->id, $newType, (int) $user->id);
            } catch (\Throwable $e) {}

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
