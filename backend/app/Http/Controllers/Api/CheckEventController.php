<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CheckEventController extends Controller
{
    public function checkIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'room_id' => ['required', 'integer'],
            'mood' => ['nullable', 'in:happy,calm,tired,upset,sick'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->doCheckIn(
            $data['child_id'],
            $data['room_id'],
            $request->user()->id,
            $data['mood'] ?? null,
            $data['notes'] ?? null,
        );

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json($result, 201);
    }

    public function checkOut(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'room_id' => ['required', 'integer'],
            'pickup_person' => ['nullable', 'string', 'max:160'],
            'mood' => ['nullable', 'in:happy,calm,tired,upset,sick'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $today = now()->toDateString();
        $existing = DB::table('check_events')
            ->where('child_id', $data['child_id'])
            ->whereDate('occurred_at', $today)
            ->orderByDesc('occurred_at')
            ->first();

        if (!$existing || $existing->event_type !== 'check_in') {
            return response()->json(['message' => 'Child is not currently checked in.'], 422);
        }

        $notes = trim(($data['pickup_person'] ?? '').' '.($data['notes'] ?? '')) ?: null;

        $eventId = DB::table('check_events')->insertGetId([
            'child_id' => $data['child_id'],
            'room_id' => $data['room_id'],
            'event_type' => 'check_out',
            'occurred_at' => now(),
            'by_user_id' => $request->user()->id,
            'recorded_by_id' => $request->user()->id,
            'mood_at_event' => $data['mood'] ?? null,
            'notes' => $notes,
            'created_at' => now(),
        ]);

        // Tell the parents — who signed the child out, and when. Wrapped so a
        // failed notification can never undo a check-out that already happened.
        try {
            app(\App\Services\CheckEventNotifier::class)
                ->notify((int) $data['child_id'], 'check_out', (int) $request->user()->id);
        } catch (\Throwable $e) {}

        return response()->json([
            'event_id' => $eventId,
            'child_id' => $data['child_id'],
            'event_type' => 'check_out',
            'occurred_at' => now()->toIso8601String(),
            'time_display' => now()->format('g:i A'),
        ], 201);
    }

    /**
     * POST /api/v1/provider/check-in-batch
     * Check in multiple children at once (morning arrival rush).
     */
    public function checkInBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer'],
            'child_ids' => ['required', 'array', 'min:1'],
            'child_ids.*' => ['integer'],
            'mood' => ['nullable', 'in:happy,calm,tired,upset,sick'],
        ]);

        $results = [
            'checked_in' => [],
            'skipped' => [],
            'errors' => [],
        ];

        foreach ($data['child_ids'] as $childId) {
            $result = $this->doCheckIn(
                (int) $childId,
                $data['room_id'],
                $request->user()->id,
                $data['mood'] ?? null,
                null,
            );

            if (isset($result['error'])) {
                if (str_contains($result['error'], 'Already')) {
                    $results['skipped'][] = ['child_id' => $childId, 'reason' => $result['error']];
                } else {
                    $results['errors'][] = ['child_id' => $childId, 'reason' => $result['error']];
                }
            } else {
                $results['checked_in'][] = $childId;
            }
        }

        return response()->json([
            'checked_in_count' => count($results['checked_in']),
            'skipped_count' => count($results['skipped']),
            'error_count' => count($results['errors']),
            'detail' => $results,
        ], 201);
    }

    private function doCheckIn(int $childId, int $roomId, int $userId, ?string $mood, ?string $notes): array
    {
        $child = DB::table('children')->where('id', $childId)->first();
        if (!$child) {
            return ['error' => 'Child not found'];
        }

        $today = now()->toDateString();
        $existing = DB::table('check_events')
            ->where('child_id', $childId)
            ->whereDate('occurred_at', $today)
            ->orderByDesc('occurred_at')
            ->first();

        if ($existing && $existing->event_type === 'check_in') {
            return ['error' => 'Already checked in at '.Carbon::parse($existing->occurred_at)->format('g:i A')];
        }

        $eventId = DB::table('check_events')->insertGetId([
            'child_id' => $childId,
            'room_id' => $roomId,
            'event_type' => 'check_in',
            'occurred_at' => now(),
            'by_user_id' => $userId,
            'recorded_by_id' => $userId,
            'mood_at_event' => $mood,
            'notes' => $notes,
            'created_at' => now(),
        ]);

        // Tell the parents — including WHO signed the child in. This helper backs
        // both the single check-in and the batch, so hooking it here covers both.
        try {
            app(\App\Services\CheckEventNotifier::class)->notify((int) $childId, 'check_in', (int) $userId);
        } catch (\Throwable $e) {}

        return [
            'event_id' => $eventId,
            'child_id' => $childId,
            'event_type' => 'check_in',
            'occurred_at' => now()->toIso8601String(),
            'time_display' => now()->format('g:i A'),
        ];
    }
}
