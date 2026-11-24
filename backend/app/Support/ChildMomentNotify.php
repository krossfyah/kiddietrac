<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * INSTANT MOMENTS FOR FAMILIES (2026-10-31, Anthony: "daily moments, observation logged etc should all be instant
 * notifications in the app for the parent").
 *
 * Every child log a staff member writes - a meal, nap, diaper, mood, activity, sunscreen, outdoor play, bottle, a
 * medication dose, a milestone, a learning observation shared with the family - tells that child's guardians at once:
 * an in-app notification (bell + the parent's timeline) and a phone push in the "Photos & daily moments" sound.
 * Photos keep their own notification (PhotoFeedController); the end-of-day summary still goes as before.
 *
 * Rules:
 *  - Runs AFTER the response is sent (app()->terminating), so logging stays as fast as it was.
 *  - Only the logged child's own guardians, never anyone else's (a room-wide log tells each family about its own child).
 *  - Only enrolled children; guardians who switched the category off in Settings → Notifications are skipped
 *    (notification_prefs: daily_moments / learning_observations; default on). Accounts that cannot sign in are dropped
 *    by Notify::write as everywhere.
 *  - Agency switch: agencies.settings.instant_moments (default ON; Settings → Agency notifications → Instant moments),
 *    with per-kind switches (moments, observations, medication, milestones).
 *  - Bursts are grouped: a second log for the same child within 2 minutes updates the unread notification
 *    ("3 new moments for Evelyn") instead of ringing again.
 */
final class ChildMomentNotify
{
    public const WINDOW_SECONDS = 120;
    public const PREF_MOMENTS = 'daily_moments';
    public const PREF_OBSERVATIONS = 'learning_observations';
    public const PREF_PHOTOS = 'photos_videos';

    public const DEFAULT_SETTINGS = ['enabled' => true, 'moments' => true, 'observations' => true, 'medication' => true, 'milestones' => true];

    private const EVENTS = [
        'meal' => ['🍽️', 'Meal'], 'snack' => ['🍎', 'Snack'], 'bottle' => ['🍼', 'Bottle'],
        'nap_start' => ['😴', 'Nap started'], 'nap_end' => ['⏰', 'Woke up from nap'], 'nap' => ['😴', 'Nap'],
        'diaper' => ['🧷', 'Diaper change'], 'bathroom' => ['🚽', 'Bathroom'],
        'activity' => ['🎨', 'Activity'], 'mood' => ['😊', 'Mood'], 'note' => ['📝', 'Note'],
        'outdoor' => ['🌳', 'Outdoor play'], 'sunscreen' => ['🧴', 'Sunscreen'],
        'medicine' => ['💊', 'Medicine'], 'clothing' => ['👕', 'Change of clothes'],
        'milestone' => ['🌟', 'Milestone'], 'medication_given' => ['💊', 'Medication given'],
    ];

    /** Queue items to deliver once the response has gone. Each item: child_id, kind, emoji, label, text, link. */
    public static function after(array $items): void
    {
        $items = array_values(array_filter($items, fn ($i) => ! empty($i['child_id'])));
        if (! $items) return;
        try {
            app()->terminating(function () use ($items) { self::deliver($items); });
        } catch (\Throwable $e) {
            self::deliver($items);
        }
    }

    /** A daily_events / care-log entry → item (null when it is not something a family is told about this way). */
    public static function momentItem(int $childId, string $type, ?string $notes, $payload = null, $occurredAt = null, $endedAt = null, ?string $details = null): ?array
    {
        $p = is_array($payload) ? $payload : (json_decode((string) $payload, true) ?: []);
        if (($p['kind'] ?? '') === 'media') return null;           // photos have their own notification
        if (! isset(self::EVENTS[$type])) return null;              // walks etc. are told elsewhere
        [$emoji, $label] = self::EVENTS[$type];
        if ($type === 'diaper' && ! empty($p['type'])) {
            $map = ['wet' => 'wet', 'bm' => 'BM', 'dry' => 'dry', 'both' => 'wet + BM'];
            $label .= ' (' . ($map[$p['type']] ?? (string) $p['type']) . ')';
        }
        $text = trim((string) ($notes ?: $details ?: ''));
        if ($type === 'nap' && $occurredAt && $endedAt) {
            $text = trim('slept ' . self::clock($childId, $occurredAt) . '–' . self::clock($childId, $endedAt) . ($text !== '' ? ' · ' . $text : ''));
        }
        return ['child_id' => $childId, 'kind' => 'moments', 'emoji' => $emoji, 'label' => $label, 'text' => $text, 'link' => '#today'];
    }

    public static function deliver(array $items): void
    {
        try {
            $byChild = [];
            foreach ($items as $it) { $byChild[(int) $it['child_id']][] = $it; }
            foreach ($byChild as $childId => $list) {
                try { self::deliverChild($childId, $list); } catch (\Throwable $e) {
                    Log::warning('instant moment notify failed', ['child' => $childId, 'err' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('instant moment notify failed', ['err' => $e->getMessage()]);
        }
    }

    private static function deliverChild(int $childId, array $list): void
    {
        $child = DB::table('children as c')->leftJoin('families as f', 'f.id', '=', 'c.family_id')
            ->leftJoin('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->where('c.id', $childId)->whereNull('c.deleted_at')
            ->first(['c.id', 'c.first_name', 'c.family_id', 'c.enrollment_status', 'ce.agency_id']);
        if (! $child || ! $child->family_id || ($child->enrollment_status && $child->enrollment_status !== 'enrolled')) return;

        $settings = self::settings((int) $child->agency_id);
        if (! $settings['enabled']) return;
        $list = array_values(array_filter($list, fn ($it) => ! empty($settings[$it['kind']] ?? true)));
        if (! $list) return;

        $guardians = DB::table('guardians')->where('family_id', $child->family_id)->whereNotNull('user_id')
            ->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        if (! $guardians) return;

        $first = trim((string) $child->first_name) ?: 'your child';
        foreach ($guardians as $uid) {
            $mine = array_values(array_filter($list, fn ($it) => self::wants($uid, $it['kind'] === 'observations' ? self::PREF_OBSERVATIONS : self::PREF_MOMENTS)));
            if (! $mine) continue;
            self::notifyGuardian($uid, $childId, $first, $mine);
        }
    }

    private static function notifyGuardian(int $uid, int $childId, string $first, array $mine): void
    {
        $last = end($mine);
        $obs = array_values(array_filter($mine, fn ($it) => $it['kind'] === 'observations'));

        // A recent unread moment notification for this child → fold this one into it, no second ring.
        $recent = DB::table('notifications')->where('user_id', $uid)->where('type', 'moment')->whereNull('read_at')
            ->where('created_at', '>=', now()->subSeconds(self::WINDOW_SECONDS))
            ->where('data', 'like', '%"child_id":' . $childId . ',%')
            ->orderByDesc('id')->first(['id', 'data']);
        if ($recent && ! $obs) {
            $d = json_decode((string) $recent->data, true) ?: [];
            $n = (int) ($d['count'] ?? 1) + count($mine);
            $d['count'] = $n;
            DB::table('notifications')->where('id', $recent->id)->update([
                'title' => '🌈 ' . $n . ' new moments for ' . $first,
                'body' => self::line($last, $first),
                'data' => json_encode($d),
            ]);
            return;
        }

        if (count($mine) === 1) {
            $title = $last['emoji'] . ' ' . $last['label'] . ' — ' . $first;
            $body = $last['text'] !== '' ? $last['text'] : self::line($last, $first);
        } else {
            $title = '🌈 ' . count($mine) . ' new moments for ' . $first;
            $body = self::line($last, $first);
        }
        $body = mb_substr($body, 0, 160);
        $type = $obs && count($mine) === count($obs) ? 'observation' : 'moment';
        Notify::write([
            'user_id' => $uid, 'type' => $type, 'title' => $title, 'body' => $body,
            'data' => json_encode(['child_id' => $childId, 'count' => count($mine), 'link' => $last['link'] ?? '#today', 'kind' => $last['kind']]),
            'created_at' => now(),
        ]);
        try {
            app(\App\Services\FcmService::class)->sendToUser($uid, $title, $body, (string) ($last['link'] ?? '#today'), false, false, true, ['kt_category' => 'photos']);
        } catch (\Throwable $e) { /* the in-app notification is already there */ }
    }

    private static function line(array $it, string $first): string
    {
        $t = $it['text'] !== '' ? ': ' . $it['text'] : '';
        return mb_substr($it['emoji'] . ' ' . $it['label'] . $t, 0, 160);
    }

    /** The guardian's own switch for this category (Settings → Notifications). Default on. */
    public static function wants(int $userId, string $key): bool
    {
        try {
            $v = DB::table('notification_prefs')->where('user_id', $userId)->where('event_key', $key)->value('push');
            return $v === null ? true : (bool) $v;
        } catch (\Throwable $e) { return true; }
    }

    public static function settings(int $agencyId): array
    {
        $s = [];
        try {
            $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
            $all = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
            $s = is_array($all['instant_moments'] ?? null) ? $all['instant_moments'] : [];
        } catch (\Throwable $e) {}
        $out = self::DEFAULT_SETTINGS;
        foreach ($out as $k => $v) { if (array_key_exists($k, $s)) $out[$k] = (bool) $s[$k]; }
        return $out;
    }

    private static function clock(int $childId, $when): string
    {
        try {
            $agencyId = (int) DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
                ->join('centres as ce', 'ce.id', '=', 'f.centre_id')->where('c.id', $childId)->value('ce.agency_id');
            $tz = AgencyTime::tz($agencyId ?: null);
        } catch (\Throwable $e) { $tz = 'America/Toronto'; }
        try { return Carbon::parse($when, 'UTC')->setTimezone($tz)->format('g:i'); } catch (\Throwable $e) { return ''; }
    }
}
