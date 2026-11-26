<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\NotificationSounds;
use App\Support\NotifyAudience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A person's own notification tones and vibration, per category (2026-10-21).
 * See App\Support\NotificationSounds. Always the caller's own settings.
 */
class NotificationSoundsController extends Controller
{
    /** GET /me/notification-sounds */
    public function index(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $devices = DB::table('device_tokens')->where('user_id', $uid)->whereIn('platform', ['android', 'ios'])
            ->orderByDesc('last_active_at')->get(['platform', 'app_build', 'last_active_at']);

        /* Only the kinds this person can receive, each marked with who it is for (2026-11-06, NotifyAudience):
           a parent no longer sees staff reminders, headcounts or the staff mailbox. */
        $sides = NotifyAudience::sides($request);
        $cat = NotificationSounds::catalogue();
        $cat['categories'] = array_values(array_filter(array_map(function ($c) use ($sides) {
            $c['for'] = NotifyAudience::SOUND_CATEGORIES[$c['key']] ?? 'all';
            $c['roles'] = NotifyAudience::SOUND_ROLES[$c['key']] ?? null;   // staff roles it is for (null = all of them)
            $c['as'] = NotifyAudience::tag($c['for'], $sides, $c['roles']);   // staff | parent | both | null (2026-11-26)

            return $c;
        }, $cat['categories'] ?? []), fn ($c) => $c['as'] !== null));

        return response()->json($cat + [
            'sides' => $sides,
            'choices' => NotificationSounds::forUser($uid),
            'min_build' => NotificationSounds::MIN_BUILD,
            // So the screen can say "update the app" to the right people.
            'devices' => $devices->map(fn ($d) => [
                'platform' => $d->platform,
                'app_build' => $d->app_build ? (int) $d->app_build : null,
                'supports_tones' => $d->platform === 'ios' || (int) $d->app_build >= NotificationSounds::MIN_BUILD,
            ])->values(),
        ]);
    }

    /** PUT /me/notification-sounds  {category, tone, vibration} or {reset: true} */
    public function update(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        if ($request->boolean('reset')) {
            $n = DB::table('notification_sounds')->where('user_id', $uid)->delete();
            NotificationSounds::forget($uid);
            \App\Support\Audit::write([
                'user_id' => $uid, 'action' => 'notification_sounds.reset', 'entity_type' => 'user', 'entity_id' => $uid,
                'payload' => json_encode(['summary' => 'Reset every notification tone and vibration to the defaults (' . $n . ' changed).']),
                'created_at' => now(),
            ]);

            return response()->json(['ok' => true, 'choices' => NotificationSounds::forUser($uid)]);
        }

        $data = $request->validate([
            'category' => ['required', 'string', 'in:' . implode(',', array_keys(NotificationSounds::CATEGORIES))],
            'tone' => ['required', 'string', 'regex:/^[a-z0-9_]{1,40}$/', function ($attr, $value, $fail) {
                // Only tones in the hosted catalogue (parent-portal/sounds/catalog.json).
                if (! NotificationSounds::isTone((string) $value)) {
                    $fail('That tone is not in the sound library.');
                }
            }],
            'vibration' => ['required', 'string', 'in:' . implode(',', array_keys(NotificationSounds::VIBRATIONS))],
        ]);
        abort_unless(NotifyAudience::shows(NotifyAudience::SOUND_CATEGORIES[$data['category']] ?? 'all', NotifyAudience::sides($request), NotifyAudience::SOUND_ROLES[$data['category']] ?? null),
            422, 'That kind of notification is not sent to you.');
        if ($data['category'] === 'emergency') {
            $data['vibration'] = 'insistent';
        }
        DB::table('notification_sounds')->updateOrInsert(
            ['user_id' => $uid, 'category' => $data['category']],
            ['tone' => $data['tone'], 'vibration' => $data['vibration'], 'updated_at' => now()]
        );
        NotificationSounds::forget($uid);

        $label = NotificationSounds::CATEGORIES[$data['category']][0];
        \App\Support\Audit::write([
            'user_id' => $uid, 'action' => 'notification_sounds.updated', 'entity_type' => 'user', 'entity_id' => $uid,
            'payload' => json_encode($data + ['summary' => $label . ': ' . (NotificationSounds::library()[$data['tone']]['name'] ?? $data['tone'])
                . ' · ' . NotificationSounds::VIBRATIONS[$data['vibration']]['label']]),
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true, 'choices' => NotificationSounds::forUser($uid)]);
    }
}
