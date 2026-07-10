<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Services\AnthropicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DailyEventController extends Controller
{
    use ResolvesCentreContext;

    public function __construct(
        private readonly AnthropicService $ai = new AnthropicService()
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'room_id' => ['required', 'integer'],
            'event_type' => ['required', 'in:meal,snack,nap_start,nap_end,diaper,bathroom,activity,mood,note,milestone,bottle,medication_given'],
            'payload' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        // SECURITY (v22p94): only the child's centre staff (or guardian) may log.
        abort_unless($this->canAccessChildId($request->user(), (int) $data['child_id']), 403);

        $eventId = DB::table('daily_events')->insertGetId([
            'child_id' => $data['child_id'],
            'room_id' => $data['room_id'],
            'event_type' => $data['event_type'],
            'occurred_at' => $data['occurred_at'] ?? now(),
            'payload' => json_encode($data['payload'] ?? [], JSON_THROW_ON_ERROR),
            'notes' => $data['notes'] ?? null,
            'recorded_by_id' => $request->user()->id,
            'voice_logged' => false,
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Invalidate today's digest since new info is available
        $today = now()->toDateString();
        DB::table('ai_daily_digests')
            ->where('child_id', $data['child_id'])
            ->where('digest_date', $today)
            ->delete();

        return response()->json([
            'event_id' => $eventId,
            'occurred_at' => $data['occurred_at'] ?? now()->toIso8601String(),
        ], 201);
    }

    public function update(Request $request, int $eventId): JsonResponse
    {
        $event = DB::table('daily_events')->where('id', $eventId)->first();
        if (!$event) {
            return response()->json(['message' => 'Not found'], 404);
        }
        abort_unless($this->canAccessChildId($request->user(), (int) $event->child_id), 403);

        $data = $request->validate([
            'event_type' => ['in:meal,snack,nap_start,nap_end,diaper,bathroom,activity,mood,note,milestone,bottle,medication_given'],
            'payload' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('payload', $data)) {
            $data['payload'] = json_encode($data['payload'] ?? [], JSON_THROW_ON_ERROR);
        }

        DB::table('daily_events')->where('id', $eventId)->update([
            ...$data,
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Updated']);
    }

    public function destroy(Request $request, int $eventId): JsonResponse
    {
        $event = DB::table('daily_events')->where('id', $eventId)->first();
        if (! $event) {
            return response()->json(['message' => 'Not found'], 404);
        }
        abort_unless($this->canAccessChildId($request->user(), (int) $event->child_id), 403);
        DB::table('daily_events')->where('id', $eventId)->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function timeline(Request $request, int $childId): JsonResponse
    {
        if (!DB::table('children')->where('id', $childId)->exists()) {
            return response()->json(['message' => 'Not found'], 404);
        }
        // SECURITY (v22p94): only the child's guardians/centre staff.
        abort_unless($this->canAccessChildId($request->user(), $childId), 403);

        $date = $request->input('date', now()->toDateString());

        $events = DB::table('daily_events')
            ->where('child_id', $childId)
            ->whereDate('occurred_at', $date)
            ->orderBy('occurred_at')
            ->get();

        $checks = DB::table('check_events')
            ->where('child_id', $childId)
            ->whereDate('occurred_at', $date)
            ->orderBy('occurred_at')
            ->get();

        return response()->json([
            'date' => $date,
            'events' => $events->map(fn ($e) => $this->formatEvent($e))->all(),
            'checks' => $checks->map(fn ($c) => [
                'type' => $c->event_type,
                'occurred_at' => $c->occurred_at,
                'time_display' => Carbon::parse($c->occurred_at)->format('g:i A'),
                'by' => $c->notes,
                'mood' => $c->mood_at_event,
            ])->all(),
        ]);
    }

    /**
     * GET /api/v1/parent/children/{child}/digest/{date}
     * Returns cached digest if available, else generates fresh.
     */
    public function digest(Request $request, int $childId, string $date): JsonResponse
    {
        // SECURITY (v22p94): only the child's guardians/centre staff.
        abort_unless($this->canAccessChildId($request->user(), $childId), 403);
        // 1. Check for existing cached digest
        $existing = DB::table('ai_daily_digests')
            ->where('child_id', $childId)
            ->where('digest_date', $date)
            ->first();

        if ($existing && !empty($existing->body)) {
            return response()->json([
                'body' => $existing->body,
                'generated_at' => $existing->generated_at,
                'cached' => true,
            ]);
        }

        // 2. Decide whether to generate. Only auto-generate if it's:
        //    - Today AND it's past 4 PM (most of the day has happened)
        //    - OR a past date (anything in the past should be available on demand)
        $isPastDate = Carbon::parse($date)->lt(now()->startOfDay());
        $isLateToday = Carbon::parse($date)->isToday() && now()->hour >= 16;

        if (!$isPastDate && !$isLateToday) {
            return response()->json([
                'body' => null,
                'generated_at' => null,
                'message' => 'Digest will be ready after 4 PM today.',
            ]);
        }

        // 3. Generate digest if we have an Anthropic key
        if (!$this->ai->isConfigured()) {
            return response()->json([
                'body' => $this->buildFallbackDigest($childId, $date),
                'generated_at' => now()->toIso8601String(),
                'fallback' => true,
            ]);
        }

        try {
            $context = $this->buildDigestContext($childId, $date);
            $body = $this->ai->generateDailyDigest($context);

            // Cache the result
            DB::table('ai_daily_digests')->insert([
                'child_id' => $childId,
                'digest_date' => $date,
                'body' => $body,
                'generated_at' => now(),
                'model' => config('services.anthropic.model'),
                'created_at' => now(),
            ]);

            return response()->json([
                'body' => $body,
                'generated_at' => now()->toIso8601String(),
                'cached' => false,
            ]);
        } catch (Throwable $e) {
            Log::error('Digest generation failed', [
                'child_id' => $childId,
                'date' => $date,
                'error' => $e->getMessage(),
            ]);

            // Graceful degradation — return a templated fallback
            return response()->json([
                'body' => $this->buildFallbackDigest($childId, $date),
                'generated_at' => now()->toIso8601String(),
                'fallback' => true,
            ]);
        }
    }

    /**
     * Build the structured context the AI uses to write the digest.
     */
    private function buildDigestContext(int $childId, string $date): array
    {
        $child = DB::table('children')->where('id', $childId)->first();
        $enrollment = DB::table('enrollments')
            ->where('child_id', $childId)
            ->whereNull('end_date')
            ->first();
        $room = $enrollment
            ? DB::table('rooms')->where('id', $enrollment->room_id)->first()
            : null;

        $events = DB::table('daily_events')
            ->where('child_id', $childId)
            ->whereDate('occurred_at', $date)
            ->orderBy('occurred_at')
            ->get();

        $checks = DB::table('check_events')
            ->where('child_id', $childId)
            ->whereDate('occurred_at', $date)
            ->orderBy('occurred_at')
            ->get();

        $formattedEvents = $events->map(function ($e) {
            $formatted = $this->formatEvent($e);
            return [
                'time' => $formatted['time_display'],
                'type' => $formatted['display']['title'],
                'detail' => $formatted['display']['detail'] ?: '(no detail)',
            ];
        })->all();

        $formattedChecks = $checks->map(fn ($c) => [
            'time' => Carbon::parse($c->occurred_at)->format('g:i A'),
            'type' => $c->event_type,
        ])->all();

        $age = Carbon::parse($child->date_of_birth);
        $months = (int) $age->diffInMonths(now());
        $years = intdiv($months, 12);
        $m = $months % 12;
        $ageHuman = $years > 0 ? "{$years} years {$m} months" : "{$months} months";

        return [
            'child_name' => $child->preferred_name ?: $child->first_name,
            'age' => $ageHuman,
            'room' => $room?->name ?? 'their room',
            'events' => $formattedEvents,
            'checks' => $formattedChecks,
        ];
    }

    /**
     * Template-based fallback when AI isn't available.
     */
    private function buildFallbackDigest(int $childId, string $date): string
    {
        $child = DB::table('children')->where('id', $childId)->first();
        $name = $child->preferred_name ?: $child->first_name;

        $events = DB::table('daily_events')
            ->where('child_id', $childId)
            ->whereDate('occurred_at', $date)
            ->orderBy('occurred_at')
            ->get();

        if ($events->isEmpty()) {
            return "{$name}'s day is still being captured. Check back later for a full summary.";
        }

        $meals = $events->whereIn('event_type', ['meal', 'snack'])->count();
        $naps = $events->where('event_type', 'nap_end')->count();
        $activities = $events->where('event_type', 'activity');
        $diapers = $events->whereIn('event_type', ['diaper', 'bathroom'])->count();

        $parts = ["Here's a quick look at {$name}'s day:"];

        if ($meals > 0) {
            $parts[] = "{$name} had {$meals} meal".($meals === 1 ? '' : 's')." and snacks.";
        }
        if ($naps > 0) {
            $parts[] = "Nap time happened {$naps} time".($naps === 1 ? '' : 's').".";
        }
        if ($diapers > 0) {
            $parts[] = "We took care of {$diapers} diaper or bathroom break".($diapers === 1 ? '' : 's').".";
        }
        if ($activities->isNotEmpty()) {
            $activityNames = $activities->map(function ($a) {
                $p = is_string($a->payload) ? json_decode($a->payload, true) : [];
                return $p['name'] ?? 'an activity';
            })->take(3)->implode(', ');
            $parts[] = "Activities included {$activityNames}.";
        }

        $parts[] = "Ask {$name} about their day when you pick up!";

        return implode(' ', $parts);
    }

    private function formatEvent(object $event): array
    {
        $payload = is_string($event->payload)
            ? (json_decode($event->payload, true) ?? [])
            : ((array) ($event->payload ?? []));

        $display = match ($event->event_type) {
            'meal', 'snack' => [
                'title' => ucfirst($payload['meal'] ?? $event->event_type),
                'detail' => ($payload['amount'] ?? '').(!empty($payload['items']) ? ' · '.implode(', ', $payload['items']) : ''),
            ],
            'nap_start' => ['title' => 'Started nap', 'detail' => ''],
            'nap_end' => ['title' => 'Woke from nap', 'detail' => ''],
            'diaper' => ['title' => 'Diaper change', 'detail' => $payload['type'] ?? 'changed'],
            'activity' => [
                'title' => $payload['name'] ?? 'Activity',
                'detail' => ($payload['domain'] ?? '').(!empty($payload['duration_min']) ? ' · '.$payload['duration_min'].' min' : ''),
            ],
            'mood' => ['title' => 'Mood: '.($payload['score'] ?? '—'), 'detail' => ''],
            'note' => ['title' => 'Note', 'detail' => $payload['note'] ?? ''],
            default => ['title' => str_replace('_', ' ', ucfirst($event->event_type)), 'detail' => ''],
        };

        return [
            'id' => $event->id,
            'type' => $event->event_type,
            'occurred_at' => $event->occurred_at,
            'time_display' => Carbon::parse($event->occurred_at)->format('g:i A'),
            'payload' => $payload,
            'notes' => $event->notes,
            'display' => $display,
        ];
    }
}
