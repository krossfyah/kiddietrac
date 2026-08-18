<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Services\AnthropicService;
use App\Support\AgencyTime;
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
            'event_type' => ['required', 'in:meal,snack,nap_start,nap_end,diaper,bathroom,activity,mood,note,milestone,bottle,medication_given,outdoor'],
            'payload' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        // SECURITY (v22p94): only the child's centre staff (or guardian) may log.
        abort_unless($this->canAccessChildId($request->user(), (int) $data['child_id']), 403);

        // The app sends ISO-8601 ("2026-08-10T15:50:00.000Z"); inserting that raw
        // string into a MySQL datetime column 500s (invalid format). Carbon
        // normalises it to 'Y-m-d H:i:s'. (Same trap already fixed in CareController.)
        $occurredAt = ! empty($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();

        try {
            $eventId = DB::table('daily_events')->insertGetId([
                'child_id' => $data['child_id'],
                'room_id' => $data['room_id'],
                'event_type' => $data['event_type'],
                'occurred_at' => $occurredAt,
                'payload' => json_encode($data['payload'] ?? [], JSON_THROW_ON_ERROR),
                'notes' => $data['notes'] ?? null,
                'recorded_by_id' => $request->user()->id,
                'voice_logged' => false,
                'synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Never leak a raw 500 to the educator mid-log — surface a clear message.
            \Log::error('daily_events store failed', ['err' => $e->getMessage(), 'input' => $data]);
            return response()->json(['message' => 'Could not save this entry. Please try again.'], 422);
        }

        // Invalidate today's digest since new info is available (best-effort).
        try {
            DB::table('ai_daily_digests')
                ->where('child_id', $data['child_id'])
                ->where('digest_date', now()->toDateString())
                ->delete();
        } catch (\Throwable $e) { /* non-fatal */ }

        return response()->json([
            'event_id' => $eventId,
            'occurred_at' => $occurredAt->toIso8601String(),
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
            'event_type' => ['in:meal,snack,nap_start,nap_end,diaper,bathroom,activity,mood,note,milestone,bottle,medication_given,outdoor'],
            'payload' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (array_key_exists('payload', $data)) {
            $data['payload'] = json_encode($data['payload'] ?? [], JSON_THROW_ON_ERROR);
        }
        // Normalise an ISO occurred_at to MySQL datetime (see store()).
        if (! empty($data['occurred_at'])) {
            $data['occurred_at'] = Carbon::parse($data['occurred_at']);
        }

        try {
            DB::table('daily_events')->where('id', $eventId)->update([
                ...$data,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::error('daily_events update failed', ['err' => $e->getMessage(), 'id' => $eventId]);
            return response()->json(['message' => 'Could not update this entry. Please try again.'], 422);
        }

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
        $tz = $this->tzForChild($childId);

        // v22p98: a "day" is the centre-LOCAL day, not the UTC day. whereDate()
        // compared the raw UTC date, so an evening-Eastern entry (stamped after
        // midnight UTC) filed under the next day and vanished from the correct
        // day's timeline. Build a UTC window from the centre's local midnight.
        $dayStart = Carbon::parse($date, $tz)->startOfDay();
        $startUtc = $dayStart->copy()->utc()->format('Y-m-d H:i:s');
        $endUtc   = $dayStart->copy()->addDay()->utc()->format('Y-m-d H:i:s');

        $events = DB::table('daily_events')
            ->where('child_id', $childId)
            ->whereNull('deleted_at')
            ->where('occurred_at', '>=', $startUtc)
            ->where('occurred_at', '<', $endUtc)
            ->orderBy('occurred_at')
            ->get();

        // v22p98: "Log a moment" writes daily_care_logs — a SEPARATE table from
        // daily_events. The parent timeline only ever read daily_events, so every
        // nappy/meal/nap/bottle an educator logged from "Log a moment" was
        // invisible to parents. Merge both, shaped like a daily_event, so the
        // parent sees the child's full day.
        $careEvents = DB::table('daily_care_logs')
            ->where('child_id', $childId)
            ->where('occurred_at', '>=', $startUtc)
            ->where('occurred_at', '<', $endUtc)
            ->orderBy('occurred_at')
            ->get()
            ->map(function ($r) {
                $lt = (string) $r->log_type;
                if (in_array($lt, ['meal', 'snack'], true)) {
                    $payload = ['meal' => ucfirst($lt), 'amount' => (string) ($r->details ?? '')];
                } elseif ($lt === 'diaper') {
                    $payload = ['type' => (string) ($r->details ?: 'changed')];
                } else {
                    $payload = $r->details ? ['detail' => (string) $r->details] : [];
                }
                $extra = [];
                if (!empty($r->amount_ml)) { $extra[] = $r->amount_ml . ' ml'; }
                if (!empty($r->amount_oz)) { $extra[] = $r->amount_oz . ' oz'; }
                $note = trim((string) ($r->notes ?? ''));
                if ($extra) { $note = trim($note . ($note !== '' ? ' · ' : '') . implode(' · ', $extra)); }
                return (object) [
                    'id'          => 'care-' . $r->id,
                    'event_type'  => $lt,
                    'occurred_at' => $r->occurred_at,
                    'payload'     => json_encode($payload),
                    'notes'       => $note !== '' ? $note : null,
                ];
            });

        $events = $events->concat($careEvents)->sortBy('occurred_at')->values();

        $checks = DB::table('check_events')
            ->where('child_id', $childId)
            ->where('occurred_at', '>=', $startUtc)
            ->where('occurred_at', '<', $endUtc)
            ->orderBy('occurred_at')
            ->get();

        return response()->json([
            'date' => $date,
            'events' => $events->map(fn ($e) => $this->formatEvent($e, $tz))->all(),
            'checks' => $checks->map(fn ($c) => [
                'type' => $c->event_type,
                'occurred_at' => $c->occurred_at,
                'time_display' => AgencyTime::fmt($c->occurred_at, $tz),
                'by' => $c->notes,
                'mood' => $c->mood_at_event,
            ])->all(),
        ]);
    }

    /** Display timezone (Eastern for Ontario) resolved from a child → family → centre → agency. */
    private function tzForChild(int $childId): string
    {
        $centreId = DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->where('children.id', $childId)
            ->value('families.centre_id');
        return AgencyTime::tzForCentre($centreId ? (int) $centreId : null);
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

        // 1b. If the child was reported absent for this date, there's no day to
        //     summarise — write a warm little note about their day off instead.
        $absence = DB::table('child_absences')
            ->where('child_id', $childId)
            ->whereDate('absent_on', $date)
            ->first();
        if ($absence) {
            $c = DB::table('children')->where('id', $childId)->first();
            $name = $c ? (($c->preferred_name ?: $c->first_name) ?: 'Your child') : 'Your child';
            $reasonLine = [
                'sick'        => "was home unwell",
                'appointment' => "was away for an appointment",
                'holiday'     => "was away on holiday",
                'family'      => "had a family day",
                'other'       => "was away",
            ][$absence->reason] ?? "was away";
            $note = trim((string) ($absence->note ?? ''));
            $body = "{$name} {$reasonLine} today, so there isn't a daily story to share. "
                . ($note ? "You let us know: \"{$note}\". " : "")
                . "We hope {$name} is feeling good, and we can't wait to see them back at the centre! \u{1F49B}";
            return response()->json([
                'body' => $body,
                'generated_at' => now()->toIso8601String(),
                'absent' => true,
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

        $tz = $this->tzForChild($childId);
        $formattedEvents = $events->map(function ($e) use ($tz) {
            $formatted = $this->formatEvent($e, $tz);
            return [
                'time' => $formatted['time_display'],
                'type' => $formatted['display']['title'],
                'detail' => $formatted['display']['detail'] ?: '(no detail)',
            ];
        })->all();

        $formattedChecks = $checks->map(fn ($c) => [
            'time' => AgencyTime::fmt($c->occurred_at, $tz),
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
    /** Pull a human name/title out of a daily_events payload. */
    private function eventName($e): string
    {
        $p = is_string($e->payload ?? null) ? json_decode($e->payload, true) : ($e->payload ?? []);
        if (!is_array($p)) $p = [];
        return trim((string) ($p['name'] ?? $p['title'] ?? $p['description'] ?? ''));
    }

    private function buildFallbackDigest(int $childId, string $date): string
    {
        $child = DB::table('children')->where('id', $childId)->first();
        $name = $child->preferred_name ?: $child->first_name;

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

        // Deterministic per-day variety: the SAME day always reads the same, but
        // consecutive days pull different wording — so the story never feels
        // copy-pasted. Seeded by date+child, advanced with a small LCG.
        $seed = crc32($date.'#'.$childId);
        $pick = function (array $arr) use (&$seed) {
            $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
            return $arr[$seed % count($arr)];
        };

        $tz = $this->tzForChild($childId);
        $checkIn  = $checks->firstWhere('event_type', 'check_in');
        $checkOut = $checks->where('event_type', 'check_out')->last();
        $inT  = $checkIn  ? AgencyTime::fmt($checkIn->occurred_at, $tz)  : null;
        $outT = $checkOut ? AgencyTime::fmt($checkOut->occurred_at, $tz) : null;

        if ($events->isEmpty() && !$checkIn) {
            return $pick([
                "{$name}'s day is still being written — check back a little later for the full story. \u{1F4D6}",
                "We're still capturing {$name}'s day. Pop back this afternoon for the full recap! \u{2728}",
                "{$name}'s story for today is still coming together — check back soon. \u{1F31F}",
            ]);
        }

        $meals      = $events->whereIn('event_type', ['meal', 'snack']);
        $napsCount  = $events->where('event_type', 'nap_end')->count();
        $activities = $events->where('event_type', 'activity');
        $diapers    = $events->whereIn('event_type', ['diaper', 'bathroom'])->count();
        $moodEvt    = $events->where('event_type', 'mood')->last();

        // Total sleep by pairing nap_start -> nap_end.
        $sleepMin = 0; $openStart = null;
        foreach ($events as $e) {
            if ($e->event_type === 'nap_start') {
                $openStart = Carbon::parse($e->occurred_at);
            } elseif ($e->event_type === 'nap_end' && $openStart) {
                $sleepMin += (int) $openStart->diffInMinutes(Carbon::parse($e->occurred_at));
                $openStart = null;
            }
        }

        $parts = [];
        $parts[] = $pick([
            "Here's how {$name}'s day unfolded:",
            "A little window into {$name}'s day:",
            "Here's what {$name} got up to today:",
            "{$name} had a full day \u{2014} here's the recap:",
            "Here's the story of {$name}'s day:",
        ]);

        if ($inT && $outT) {
            $parts[] = $pick([
                "They arrived at {$inT} and headed home at {$outT}.",
                "Signed in at {$inT} and signed out at {$outT}.",
                "Their day with us ran from {$inT} to {$outT}.",
            ]);
        } elseif ($inT) {
            $parts[] = $pick([
                "They arrived bright and ready at {$inT}.",
                "Check-in was at {$inT}.",
                "{$name} joined us at {$inT}.",
            ]);
        }

        if ($meals->count() > 0) {
            $mealNames = $meals->map(fn ($m) => $this->eventName($m))->filter()->take(3)->implode(', ');
            $mc = $meals->count();
            $parts[] = $mealNames
                ? $pick([
                    "At the table, {$name} enjoyed {$mealNames}.",
                    "Mealtimes included {$mealNames}.",
                    "On the menu for {$name}: {$mealNames}.",
                ])
                : $pick([
                    "{$name} had {$mc} meal".($mc === 1 ? '' : 's')." and snacks.",
                    "There ".($mc === 1 ? 'was' : 'were')." {$mc} meal or snack break".($mc === 1 ? '' : 's')." today.",
                ]);
        }

        if ($sleepMin > 0) {
            $h = intdiv($sleepMin, 60); $m = $sleepMin % 60;
            $dur = $h > 0 ? ($h.'h'.($m ? ' '.$m.'m' : '')) : ($m.' minutes');
            $parts[] = $pick([
                "{$name} rested for about {$dur}.",
                "Nap time added up to around {$dur} of sleep.",
                "They recharged with roughly {$dur} of rest.",
            ]);
        } elseif ($napsCount > 0) {
            $parts[] = $pick([
                "Nap time happened {$napsCount} time".($napsCount === 1 ? '' : 's').".",
                "{$name} settled down to rest {$napsCount} time".($napsCount === 1 ? '' : 's').".",
            ]);
        }

        if ($activities->isNotEmpty()) {
            $an = $activities->map(fn ($a) => $this->eventName($a))->filter()->take(3)->implode(', ');
            if ($an) {
                $parts[] = $pick([
                    "Fun moments included {$an}.",
                    "They dove into {$an}.",
                    "Highlights of the day: {$an}.",
                    "{$name} explored {$an}.",
                ]);
            }
        }

        if ($moodEvt) {
            $mood = $this->eventName($moodEvt);
            if ($mood !== '') {
                $parts[] = $pick([
                    "Overall mood: {$mood}.",
                    "{$name} seemed {$mood} today.",
                ]);
            }
        }

        if ($diapers > 0) {
            $parts[] = $pick([
                "We took care of {$diapers} diaper or bathroom break".($diapers === 1 ? '' : 's').".",
                "There ".($diapers === 1 ? 'was' : 'were')." {$diapers} diaper or bathroom check".($diapers === 1 ? '' : 's')." along the way.",
            ]);
        }

        $parts[] = $pick([
            "Ask {$name} about the best part of their day! \u{1F49B}",
            "Give {$name} a big hug from all of us! \u{1F917}",
            "We can't wait to see {$name} again tomorrow. \u{2728}",
            "Ask {$name} what made them smile today! \u{1F60A}",
            "See you next time \u{2014} give {$name} a squeeze from us! \u{1F31F}",
        ]);

        return implode(' ', $parts);
    }

    private function formatEvent(object $event, string $tz = 'America/Toronto'): array
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
            // A media note carries kind=media from PhotoFeedController — give it a
            // warm, specific title instead of the bare word "Note".
            'note' => (($payload['kind'] ?? null) === 'media')
                ? [
                    'title' => (($payload['media_type'] ?? 'photo') === 'video')
                        ? 'A new video was shared'
                        : 'A new photo was shared',
                    'detail' => $payload['note'] ?? '',
                ]
                : ['title' => 'Note', 'detail' => $payload['note'] ?? ''],
            default => ['title' => str_replace('_', ' ', ucfirst($event->event_type)), 'detail' => ''],
        };

        return [
            'id' => $event->id,
            'type' => $event->event_type,
            'occurred_at' => $event->occurred_at,
            'time_display' => AgencyTime::fmt($event->occurred_at, $tz),
            'payload' => $payload,
            'notes' => $event->notes,
            'display' => $display,
        ];
    }
}
